<?php
declare(strict_types=1);

final class Clubs
{
    public static function all(bool $full): array
    {
        $rows = Db::all('SELECT * FROM clubs WHERE active = 1 ORDER BY id');
        if (!$full) return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'short' => $c['short']], $rows);
        foreach ($rows as &$c) $c['members'] = (int) Db::val('SELECT COUNT(*) FROM users WHERE club_id = ?', [$c['id']]);
        return $rows;
    }
    public static function save(array $actor, array $in): array
    {
        Perms::require($actor, 'clubs.manage');
        $mode = ($in['match_price_mode'] ?? 'lane_hour') === 'fixed' ? 'fixed' : 'lane_hour';
        $email = str_opt($in, 'email', 190);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Neispravan email kluba.');
        $row = ['name' => str_req($in, 'name', 120), 'short' => str_req($in, 'short', 20), 'email' => $email, 'match_price_mode' => $mode,
            'match_fixed_cents' => Money::parse((string) ($in['match_fixed'] ?? '0')), 'match_lane_hour_cents' => Money::parse((string) ($in['match_lane_hour'] ?? '10'))];
        if (!empty($in['id'])) {
            Db::update('clubs', $row, 'id = ?', [(int) $in['id']]);
            Audit::log('club.updated', 'club', (int) $in['id'], $row);
            return Db::one('SELECT * FROM clubs WHERE id = ?', [(int) $in['id']]);
        }
        $row['match_category'] = 'match_other';
        $row['created_utc'] = Clock::nowUtc();
        $id = Db::insert('clubs', $row);
        Audit::log('club.created', 'club', $id, $row);
        return Db::one('SELECT * FROM clubs WHERE id = ?', [$id]);
    }
    public static function setMember(array $actor, int $userId, ?int $clubId): void
    {
        Perms::require($actor, 'clubs.manage');
        if ($clubId && !Db::val('SELECT id FROM clubs WHERE id = ?', [$clubId])) throw new AppError('Klub ne postoji.');
        $old = Db::val('SELECT club_id FROM users WHERE id = ?', [$userId]);
        Db::update('users', ['club_id' => $clubId, 'updated_utc' => Clock::nowUtc()], 'id = ?', [$userId]);
        Audit::log('user.club', 'user', $userId, ['from' => $old, 'to' => $clubId]);
    }
}

final class Series
{
    private static function validate(array $in): array
    {
        $wd = int_req($in, 'weekday');
        if ($wd < 1 || $wd > 7) throw new AppError('Neispravan dan u sedmici.');
        $st = (string) ($in['start_time'] ?? '');
        if (!preg_match('/^(0[8-9]|1\d|2[0-2]):00$/', $st)) throw new AppError('Početak stalnog termina mora biti na puni sat (08:00–22:00).');
        $min = int_req($in, 'minutes');
        if ($min < 30 || $min % 30 !== 0) throw new AppError('Trajanje u koracima od 30 minuta.');
        if (T::hm(T::addMin('2000-01-03 ' . $st . ':00', $min)) > '23:00' || $min > 15 * 60) throw new AppError('Stalni termin mora završiti do 23:00.');
        $lc = int_req($in, 'lane_count');
        if ($lc < 1 || $lc > 6) throw new AppError('Broj staza 1–6.');
        $from = (string) ($in['valid_from'] ?? Clock::today());
        if (!T::validDate($from) || $from < Clock::today()) throw new AppError('Početni datum ne može biti u prošlosti.');
        $to = !empty($in['valid_to']) ? (string) $in['valid_to'] : null;
        if ($to !== null && (!T::validDate($to) || $to < $from)) throw new AppError('Neispravan krajnji datum.');
        $pref = null;
        if (!empty($in['preferred_lanes'])) {
            $pl = array_values(array_unique(array_filter(array_map('intval', (array) $in['preferred_lanes']), fn($x) => $x >= 1 && $x <= 6)));
            sort($pl); $pref = implode(',', $pl) ?: null;
        }
        return ['weekday' => $wd, 'start_time' => $st, 'minutes' => $min, 'lane_count' => $lc, 'valid_from' => $from, 'valid_to' => $to, 'preferred_lanes' => $pref, 'note' => str_opt($in, 'note', 255)];
    }

    /** Zahtjev igrača (čeka odobrenje, ne čuva termin). */
    public static function request(array $actor, array $in): array
    {
        if (!$actor['club_id']) throw new AppError('Stalne termine imaju samo klubovi.');
        if ($actor['blocked_utc']) throw new AppError('Nalog je blokiran.', 403);
        $row = self::validate($in) + ['club_id' => (int) $actor['club_id'], 'requested_by' => (int) $actor['id'], 'status' => 'pending', 'created_utc' => Clock::nowUtc()];
        $id = Db::insert('recurring_series', $row);
        Audit::log('series.requested', 'series', $id, $row);
        Notify::event('series_request', ['series_id' => $id]);
        return self::get($id);
    }

    /** Osoblje postavlja stalni termin (odmah ide na provjeru i odobrenje). */
    public static function createByStaff(array $actor, array $in): array
    {
        Perms::require($actor, 'clubs.manage');
        $club = (int) ($in['club_id'] ?? 0);
        if (!Db::val('SELECT id FROM clubs WHERE id = ?', [$club])) throw new AppError('Izaberite klub.');
        $row = self::validate($in) + ['club_id' => $club, 'requested_by' => null, 'status' => 'pending', 'created_utc' => Clock::nowUtc()];
        $id = Db::insert('recurring_series', $row);
        Audit::log('series.created', 'series', $id, $row);
        return self::get($id);
    }

    public static function get(int $id): array
    {
        $s = Db::one('SELECT s.*, c.name club_name FROM recurring_series s JOIN clubs c ON c.id = s.club_id WHERE s.id = ?', [$id]);
        if (!$s) throw new AppError('Stalni termin ne postoji.', 404);
        $s['exceptions'] = Db::all('SELECT date, reason FROM series_exceptions WHERE series_id = ? ORDER BY date', [$id]);
        return $s;
    }

    /**
     * Konflikti za buduće datume. Provjeravaju se samo postojeće (konačne) buduće rezervacije/blokade
     * i druga odobrena pravila — bez generisanja beskonačnog niza datuma.
     */
    public static function conflicts(array $s): array
    {
        $out = ['dates' => [], 'rule' => [], 'reassigned' => []];
        $pref = !empty($s['preferred_lanes']) ? array_map('intval', explode(',', $s['preferred_lanes'])) : [];
        foreach (Db::all("SELECT o.*, c.name club FROM recurring_series o JOIN clubs c ON c.id = o.club_id WHERE o.status = 'approved' AND o.weekday = ? AND o.id <> ?", [$s['weekday'], $s['id'] ?? 0]) as $o) {
            $overlapTime = $o['start_time'] < T::hm(T::addMin('2000-01-03 ' . $s['start_time'] . ':00', (int) $s['minutes'])) && $s['start_time'] < T::hm(T::addMin('2000-01-03 ' . $o['start_time'] . ':00', (int) $o['minutes']));
            $overlapDates = ($o['valid_to'] === null || $o['valid_to'] >= $s['valid_from']) && ($s['valid_to'] === null || $s['valid_to'] >= $o['valid_from']);
            if ($overlapTime && $overlapDates && (int) $o['lane_count'] + (int) $s['lane_count'] > 6) $out['rule'][] = ['series_id' => (int) $o['id'], 'text' => 'Preklapa se sa stalnim terminom ' . $o['club'] . ' (' . $o['start_time'] . ', ' . $o['lane_count'] . ' staza)'];
        }
        $from = max($s['valid_from'], Clock::today());
        $dates = array_column(Db::all('SELECT DISTINCT DATE(slot_start) d FROM lane_slots WHERE slot_start >= ? ' . ($s['valid_to'] ? 'AND slot_start < ?' : '') . ' ORDER BY d',
            $s['valid_to'] ? [$from . ' 00:00:00', T::addDays($s['valid_to'], 1) . ' 00:00:00'] : [$from . ' 00:00:00']), 'd');
        $exc = array_column(Db::all('SELECT date FROM series_exceptions WHERE series_id = ?', [$s['id'] ?? 0]), 'date');
        foreach ($dates as $d) {
            if (T::weekday($d) !== (int) $s['weekday'] || in_array($d, $exc, true)) continue;
            if (!empty($s['id']) && Db::val('SELECT id FROM reservations WHERE series_id = ? AND occurrence_date = ?', [$s['id'], $d])) continue;
            $ss = $d . ' ' . $s['start_time'] . ':00'; $se = T::addMin($ss, (int) $s['minutes']);
            $free = Availability::freeLanes($ss, $se);
            if (count($free) >= (int) $s['lane_count'] && $pref && array_diff(array_slice($pref, 0, (int) $s['lane_count']), $free)) {
                $order = array_values(array_unique(array_merge(array_intersect($pref, $free), $free)));
                $as = array_slice($order, 0, (int) $s['lane_count']); sort($as);
                $out['reassigned'][] = ['date' => $d, 'preferred' => array_slice($pref, 0, (int) $s['lane_count']), 'assigned' => $as];
            }
            if (count($free) < (int) $s['lane_count']) {
                $what = array_map(fn($c) => $c['label'] . ' ' . T::hm($c['start_at']) . '–' . T::hm($c['end_at']), array_filter(Availability::conflicts($ss, $se, Availability::LANES, null, (int) ($s['id'] ?? 0)), fn($c) => $c['type'] !== 'series'));
                $out['dates'][] = ['date' => $d, 'free_lanes' => $free, 'conflicts' => array_values(array_unique($what))];
            }
        }
        return $out;
    }

    public static function check(array $actor, int $id): array
    {
        Perms::require($actor, 'clubs.manage');
        $s = self::get($id);
        return ['series' => $s, 'conflicts' => self::conflicts($s)];
    }

    /** Odobrenje: ponovna provjera konflikata; konfliktni datumi moraju biti eksplicitno preskočeni. */
    public static function approve(array $actor, int $id, array $skipDates): array
    {
        Perms::require($actor, 'clubs.manage');
        Db::tx(function () use ($actor, $id, $skipDates) {
            $s = Db::one('SELECT * FROM recurring_series WHERE id = ? FOR UPDATE', [$id]);
            if (!$s || $s['status'] !== 'pending') throw new AppError('Zahtjev nije na čekanju.');
            if ($s['valid_from'] < Clock::today()) Db::update('recurring_series', ['valid_from' => Clock::today()], 'id = ?', [$id]);
            $s = self::get($id);
            $c = self::conflicts($s);
            if ($c['rule']) throw new AppError('Stalni termin se trajno preklapa s drugim stalnim terminom.', 409, ['conflicts' => $c]);
            $missing = array_values(array_diff(array_column($c['dates'], 'date'), $skipDates));
            if ($missing) throw new AppError('Postoje konflikti na pojedinim datumima. Prihvatite seriju uz preskakanje tih datuma ili riješite konflikte.', 409, ['conflicts' => $c]);
            foreach (array_unique($skipDates) as $d) {
                if (!T::validDate((string) $d)) continue;
                Db::q('INSERT IGNORE INTO series_exceptions (series_id, date, reason, created_by) VALUES (?, ?, ?, ?)', [$id, $d, 'Preskočeno pri odobrenju (konflikt)', $actor['id']]);
            }
            Db::update('recurring_series', ['status' => 'approved', 'decided_by' => $actor['id'], 'decided_utc' => Clock::nowUtc()], 'id = ?', [$id]);
            Audit::log('series.approved', 'series', $id, ['skipped' => $skipDates]);
        });
        self::materialize(T::addDays(Clock::today(), (int) Settings::get('series_window_days', '56')), $id);
        Notify::event('series_decided', ['series_id' => $id, 'approved' => true]);
        return self::get($id);
    }

    public static function reject(array $actor, int $id, ?string $reason): void
    {
        Perms::require($actor, 'clubs.manage');
        $n = Db::q("UPDATE recurring_series SET status = 'rejected', decided_by = ?, decided_utc = ?, note = COALESCE(?, note) WHERE id = ? AND status = 'pending'", [$actor['id'], Clock::nowUtc(), $reason, $id])->rowCount();
        if (!$n) throw new AppError('Zahtjev nije na čekanju.');
        Audit::log('series.rejected', 'series', $id, [], $reason);
        Notify::event('series_decided', ['series_id' => $id, 'approved' => false]);
    }

    /** Ukidanje od datuma (uključivo): buduće pojave se otkazuju, zapisi ostaju. */
    public static function end(array $actor, int $id, string $fromDate): void
    {
        Perms::require($actor, 'clubs.manage');
        if (!T::validDate($fromDate)) throw new AppError('Neispravan datum.');
        Db::tx(function () use ($actor, $id, $fromDate) {
            $s = Db::one("SELECT * FROM recurring_series WHERE id = ? AND status = 'approved' FOR UPDATE", [$id]);
            if (!$s) throw new AppError('Stalni termin nije aktivan.');
            Db::update('recurring_series', ['valid_to' => T::addDays($fromDate, -1), 'status' => $fromDate <= Clock::today() ? 'ended' : 'approved'], 'id = ?', [$id]);
            foreach (Db::all("SELECT id, start_at FROM reservations WHERE series_id = ? AND occurrence_date >= ? AND status = 'active' AND processed_utc IS NULL", [$id, $fromDate]) as $r) {
                Db::lockDay(T::day($r['start_at']));
                Availability::freeReservation((int) $r['id']);
                Db::update('reservations', ['status' => 'cancelled', 'cancel_kind' => 'series_end', 'cancelled_utc' => Clock::nowUtc(), 'cancelled_by' => $actor['id']], 'id = ?', [$r['id']]);
            }
            Audit::log('series.ended', 'series', $id, ['from' => $fromDate]);
        });
    }

    public static function skipDate(array $actor, int $id, string $date, ?string $reason): void
    {
        Perms::require($actor, 'clubs.manage');
        if (!T::validDate($date)) throw new AppError('Neispravan datum.');
        Db::tx(function () use ($actor, $id, $date, $reason) {
            Db::q('INSERT IGNORE INTO series_exceptions (series_id, date, reason, created_by) VALUES (?, ?, ?, ?)', [$id, $date, $reason ?? 'Preskočeno', $actor['id']]);
            $r = Db::one("SELECT id FROM reservations WHERE series_id = ? AND occurrence_date = ? AND status = 'active' AND processed_utc IS NULL", [$id, $date]);
            if ($r) {
                Db::lockDay($date);
                Availability::freeReservation((int) $r['id']);
                Db::update('reservations', ['status' => 'cancelled', 'cancel_kind' => 'staff', 'cancel_reason' => $reason, 'cancelled_utc' => Clock::nowUtc(), 'cancelled_by' => $actor['id']], 'id = ?', [$r['id']]);
            }
            Audit::log('series.skip', 'series', $id, ['date' => $date], $reason);
        });
    }

    /**
     * Upis pojava kao stvarnih rezervacija do $until (klizni prozor + traženi datum u kalendaru).
     * Željena staza zauzeta → druga slobodna. Nema mjesta → izuzetak s razlogom + obavijest.
     */
    public static function materialize(string $until, ?int $onlyId = null): int
    {
        $n = 0;
        $list = Db::all("SELECT * FROM recurring_series WHERE status IN ('approved','ended')" . ($onlyId ? ' AND id = ' . (int) $onlyId : '') . ' AND (materialized_to IS NULL OR materialized_to < ?)', [$until]);
        foreach ($list as $s) {
            $from = $s['materialized_to'] ? T::addDays($s['materialized_to'], 1) : $s['valid_from'];
            $to = $s['valid_to'] && $s['valid_to'] < $until ? $s['valid_to'] : $until;
            $d = $from;
            $shift = ((int) $s['weekday'] - T::weekday($d) + 7) % 7;
            $d = T::addDays($d, $shift);
            for (; $d <= $to; $d = T::addDays($d, 7)) {
                $n += Db::tx(function () use ($s, $d) {
                    Db::lockDay($d);
                    if (Db::val('SELECT 1 FROM series_exceptions WHERE series_id = ? AND date = ?', [$s['id'], $d])) return 0;
                    if (Db::val('SELECT id FROM reservations WHERE series_id = ? AND occurrence_date = ?', [$s['id'], $d])) return 0;
                    $ss = $d . ' ' . $s['start_time'] . ':00'; $se = T::addMin($ss, (int) $s['minutes']);
                    $free = Availability::freeLanes($ss, $se);
                    $pref = $s['preferred_lanes'] ? array_map('intval', explode(',', $s['preferred_lanes'])) : [];
                    $order = array_values(array_unique(array_merge(array_intersect($pref, $free), $free)));
                    if (count($order) < (int) $s['lane_count']) {
                        Db::q('INSERT IGNORE INTO series_exceptions (series_id, date, reason) VALUES (?, ?, ?)', [$s['id'], $d, 'Konflikt pri upisu — nema dovoljno slobodnih staza']);
                        Audit::log('series.conflict', 'series', $s['id'], ['date' => $d]);
                        Notify::event('series_conflict', ['series_id' => (int) $s['id'], 'date' => $d]);
                        return 0;
                    }
                    $lanes = array_slice($order, 0, (int) $s['lane_count']); sort($lanes);
                    $pv = Pricing::current();
                    $id = Db::insert('reservations', ['user_id' => $s['requested_by'], 'category' => 'training', 'club_id' => $s['club_id'], 'start_at' => $ss, 'end_at' => $se, 'source' => 'series', 'series_id' => $s['id'], 'occurrence_date' => $d,
                        'price_version_id' => $pv['id'], 'club_amount_cents' => Pricing::training(count($lanes), (int) $s['minutes'], $pv), 'created_utc' => Clock::nowUtc()]);
                    foreach ($lanes as $l) Db::insert('reservation_lanes', ['reservation_id' => $id, 'lane_id' => $l]);
                    Availability::occupy($lanes, $ss, $se, $id);
                    return 1;
                });
            }
            Db::update('recurring_series', ['materialized_to' => $to], 'id = ?', [$s['id']]);
        }
        return $n;
    }

    /** Pregled serije: upisane pojave sa stazama (oznaka kad nije željena staza) i preskočeni datumi s razlogom. */
    public static function detail(array $actor, int $id): array
    {
        Perms::require($actor, 'clubs.manage');
        $s = self::get($id);
        $pref = $s['preferred_lanes'] ? array_map('intval', explode(',', $s['preferred_lanes'])) : [];
        $want = array_slice($pref, 0, (int) $s['lane_count']); sort($want);
        $occ = [];
        foreach (Db::all('SELECT r.id, r.occurrence_date, r.status, r.cancel_kind, r.start_at, r.end_at FROM reservations r WHERE r.series_id = ? ORDER BY r.occurrence_date', [$id]) as $r) {
            $lanes = array_map('intval', array_column(Db::all('SELECT lane_id FROM reservation_lanes WHERE reservation_id = ? ORDER BY lane_id', [$r['id']]), 'lane_id'));
            $occ[] = ['reservation_id' => (int) $r['id'], 'date' => $r['occurrence_date'], 'lanes' => $lanes, 'status' => $r['status'], 'cancel_kind' => $r['cancel_kind'],
                'changed' => $want && $lanes !== $want];
        }
        return ['series' => $s, 'preferred' => $want, 'occurrences' => $occ, 'exceptions' => $s['exceptions'], 'materialized_to' => $s['materialized_to'],
            'note' => 'Pojave se upisuju unaprijed za ' . (int) Settings::get('series_window_days', '56') . ' dana; dalji datumi se upisuju automatski (pravilo traje dok se ne ukine).'];
    }

    public static function list(array $actor, ?string $status = null): array
    {
        Perms::require($actor, 'clubs.manage');
        $rows = Db::all('SELECT s.*, c.name club_name, TRIM(CONCAT(u.first_name, \' \', u.last_name)) requester FROM recurring_series s JOIN clubs c ON c.id = s.club_id LEFT JOIN users u ON u.id = s.requested_by' . ($status ? ' WHERE s.status = ?' : '') . ' ORDER BY FIELD(s.status, \'pending\', \'approved\', \'ended\', \'rejected\'), s.weekday, s.start_time', $status ? [$status] : []);
        return $rows;
    }
}

final class Statements
{
    /** Održano i neobračunato do trenutka $cutoff (trening 11–13 nije uključen u 12:00). */
    public static function unbilled(int $clubId, ?string $cutoff = null, ?int $onlyRes = null): array
    {
        $cutoff ??= Clock::nowLocal();
        $sql = "SELECT r.id, r.category, r.start_at, r.end_at, r.club_amount_cents, r.match_home, r.match_guest, r.match_league, (SELECT COUNT(*) FROM reservation_lanes l WHERE l.reservation_id = r.id) lanes
            FROM reservations r WHERE r.club_id = ? AND r.status = 'active' AND r.end_at <= ? AND (r.category = 'training' OR r.category LIKE 'match\\_%')
            AND NOT EXISTS (SELECT 1 FROM club_statement_items i WHERE i.reservation_id = r.id)" . ($onlyRes ? ' AND r.id = ' . (int) $onlyRes : '') . ' ORDER BY r.start_at';
        $rows = Db::all($sql, [$clubId, $cutoff]);
        foreach ($rows as &$r) {
            $min = T::minutes($r['start_at'], $r['end_at']);
            $r['minutes'] = $min;
            $r['lanes'] = (int) $r['lanes'];
            $r['amount_cents'] = (int) $r['club_amount_cents'];
            $r['description'] = $r['category'] === 'training' ? 'Trening' : ('Utakmica: ' . $r['match_home'] . ' – ' . $r['match_guest'] . ($r['match_league'] ? ' (' . $r['match_league'] . ')' : ''));
            $r['state'] = 'Neobračunato';
        }
        return $rows;
    }

    public static function create(array $actor, int $clubId, ?int $singleMatchId = null): array
    {
        Perms::require($actor, 'clubs.billing');
        $id = Db::tx(function () use ($actor, $clubId, $singleMatchId) {
            $club = Db::one('SELECT * FROM clubs WHERE id = ? FOR UPDATE', [$clubId]);
            if (!$club) throw new AppError('Klub ne postoji.', 404);
            $cutoff = Clock::nowLocal();
            $items = self::unbilled($clubId, $cutoff, $singleMatchId);
            if ($singleMatchId) {
                if (!$items || !Cat::isMatch($items[0]['category'])) throw new AppError('Utakmica nije pronađena među neobračunatim (možda još nije završena ili je već obračunata).');
            }
            if (!$items) throw new AppError('Nema neobračunatih održanih stavki.');
            $year = substr($cutoff, 0, 4);
            $seq = (int) Db::val('SELECT COUNT(*) FROM club_statements WHERE number LIKE ?', ['OB-' . $year . '-%']) + 1;
            $total = array_sum(array_column($items, 'amount_cents'));
            $sid = Db::insert('club_statements', ['club_id' => $clubId, 'number' => sprintf('OB-%s-%04d', $year, $seq), 'kind' => $singleMatchId ? 'single_match' : 'full', 'cutoff_at' => $cutoff,
                'period_from' => T::day($items[0]['start_at']), 'period_to' => T::day(end($items)['start_at']), 'total_cents' => $total, 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()]);
            foreach ($items as $it) {
                $unit = $it['category'] === 'training' ? $it['lanes'] . ' staza × ' . self::hours($it['minutes']) : ($club['match_price_mode'] === 'fixed' ? 'fiksno' : $it['lanes'] . ' staza × ' . self::hours($it['minutes']));
                // UNIQUE(reservation_id) garantuje da stavka ne može ući u dva obračuna
                Db::insert('club_statement_items', ['statement_id' => $sid, 'reservation_id' => $it['id'], 'item_date' => T::day($it['start_at']), 'description' => $it['description'] . ' ' . T::hm($it['start_at']) . '–' . T::hm($it['end_at']),
                    'lane_count' => $it['lanes'], 'minutes' => $it['minutes'], 'unit_label' => $unit, 'amount_cents' => $it['amount_cents']]);
            }
            Audit::log('statement.created', 'statement', $sid, ['club_id' => $clubId, 'items' => count($items), 'total' => $total, 'single' => $singleMatchId]);
            return $sid;
        });
        $st = self::get($id);
        $path = Pdf::statement($st);
        Db::update('club_statements', ['pdf_path' => $path], 'id = ?', [$id]);
        $st['pdf_path'] = $path;
        if ($st['club_email']) {
            Notify::direct('club_statement', 'email', $st['club_email'], "Poštovani,\n\nu prilogu je obračun {$st['number']} za {$st['club_name']} (ukupno " . Money::fmt((int) $st['total_cents']) . ").\n\nArena Bakarni", 'statement:' . $id, 'Obračun ' . $st['number'] . ' — Arena Bakarni', $path);
        }
        return $st;
    }

    public static function hours(int $min): string { return $min % 60 === 0 ? ($min / 60) . ' h' : number_format($min / 60, 2, ',', '') . ' h'; }

    public static function get(int $id): array
    {
        $s = Db::one('SELECT s.*, c.name club_name, c.email club_email FROM club_statements s JOIN clubs c ON c.id = s.club_id WHERE s.id = ?', [$id]);
        if (!$s) throw new AppError('Obračun ne postoji.', 404);
        $s['items'] = Db::all('SELECT * FROM club_statement_items WHERE statement_id = ? ORDER BY item_date, id', [$id]);
        $s['email_status'] = Db::one('SELECT status, attempts, last_error, sent_utc FROM outbox WHERE idem_key = ?', ['statement:' . $id . ':' . ($s['club_email'] ?? '')]);
        return $s;
    }
    public static function list(?int $clubId): array
    {
        return Db::all('SELECT s.id, s.number, s.kind, s.cutoff_at, s.period_from, s.period_to, s.total_cents, s.created_utc, c.name club_name FROM club_statements s JOIN clubs c ON c.id = s.club_id' . ($clubId ? ' WHERE s.club_id = ' . (int) $clubId : '') . ' ORDER BY s.id DESC');
    }
}
