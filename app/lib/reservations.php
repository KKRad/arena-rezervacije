<?php
declare(strict_types=1);

final class Reservations
{
    public const OPEN = '10:00';
    public const CLOSE = '23:00';
    public const CAL_START = '08:00';
    public const LATE_NO_SHOW_MIN = 10;
    public const TIMELY_CANCEL_MIN = 120;

    public static function get(int $id): array
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ?', [$id]);
        if (!$r) throw new AppError('Rezervacija ne postoji.', 404);
        $r['lanes'] = array_map('intval', array_column(Db::all('SELECT lane_id FROM reservation_lanes WHERE reservation_id = ? ORDER BY lane_id', [$id]), 'lane_id'));
        return $r;
    }
    private static function lockRow(int $id): array
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$id]);
        if (!$r) throw new AppError('Rezervacija ne postoji.', 404);
        $r['lanes'] = array_map('intval', array_column(Db::all('SELECT lane_id FROM reservation_lanes WHERE reservation_id = ? ORDER BY lane_id', [$id]), 'lane_id'));
        return $r;
    }

    /** Provjera vremenskih pravila. $staff = unos osoblja (dozvoljeno od 08:00, unos u toku dana). */
    public static function validateTime(string $start, int $minutes, string $category, bool $staff, bool $extending = false): string
    {
        $end = T::addMin($start, $minutes);
        $day = T::day($start);
        if (T::day(T::addMin($end, -1)) !== $day) throw new AppError('Termin mora završiti istog dana.');
        $hm = T::hm($start);
        if (!preg_match('/:(00|15|30|45)$/', $hm)) throw new AppError('Početak mora biti na 15 minuta.');
        $fullHour = !Cat::isMatch($category);
        if ($fullHour && !$extending && substr($hm, 3) !== '00') throw new AppError('Standardne rezervacije počinju na puni sat.');
        if ($minutes <= 0 || $minutes % 15 !== 0) throw new AppError('Neispravno trajanje.');
        $min = $staff ? self::CAL_START : self::OPEN;
        if ($hm < $min) throw new AppError($staff ? 'Kalendar počinje u 08:00.' : 'Online rezervacije su moguće od 10:00.');
        if (T::hm($end) > self::CLOSE || T::hm($end) === '00:00') throw new AppError('Radno vrijeme je do 23:00.');
        if (!$extending) {
            if ($staff) {
                if ($day < Clock::today()) throw new AppError('Ne može se unositi rezervacija za prošle dane.');
            } elseif ($start <= Clock::nowLocal()) throw new AppError('Termin je već počeo — rezervisati se može do početka termina.');
        }
        if (!$staff && !$extending && !in_array($minutes, [60, 90, 120], true)) throw new AppError('Trajanje online rezervacije: 60, 90 ili 120 minuta.');
        if ($category === 'birthday' && $minutes < 60) throw new AppError('Rođendan traje najmanje 60 minuta.');
        if (in_array($category, ['recreation', 'monthly', 'training', 'birthday'], true) && $minutes % 30 !== 0) throw new AppError('Trajanje mora biti u koracima od 30 minuta.');
        return $end;
    }

    /**
     * Nova rezervacija.
     * $in: start, minutes, lanes[] ili lane_count, (gost) personal=true za "Nije trening — plaćam lično",
     *      (osoblje) user_id ili guest{phone, first_name, last_name}, category, club_id, match_home/guest/league, notes.
     */
    public static function create(array $actor, array $in): array
    {
        $staffMode = Perms::isStaff($actor) && !empty($in['as_staff']);
        $start = T::parseLocal((string) ($in['start'] ?? ''));
        $minutes = isset($in['minutes']) && $in['minutes'] !== '' ? int_req($in, 'minutes') : 0;
        $want = isset($in['lanes']) && is_array($in['lanes']) && $in['lanes'] ? $in['lanes'] : (int) ($in['lane_count'] ?? 1);
        $clubId = null; $user = null; $category = 'recreation';
        $match = ['match_home' => null, 'match_guest' => null, 'match_league' => null];

        if ($staffMode) {
            Perms::require($actor, 'res.write');
            $category = (string) ($in['category'] ?? 'recreation');
            if (!isset(Cat::ALL[$category]) || in_array($category, ['tournament', 'qualification', 'monthly'], true)) {
                if ($category !== 'monthly') throw new AppError('Neispravna kategorija.');
                $category = 'recreation';
            }
            if (Cat::isMatch($category)) {
                Perms::require($actor, 'events.manage');
                $club = Db::one('SELECT * FROM clubs WHERE id = ? AND active = 1', [(int) ($in['club_id'] ?? 0)]);
                if (!$club) throw new AppError('Izaberite klub koji se obračunava.');
                $clubId = (int) $club['id'];
                $category = $club['match_category'];
                $match = ['match_home' => str_req($in, 'match_home', 120), 'match_guest' => str_req($in, 'match_guest', 120), 'match_league' => str_opt($in, 'match_league', 160)];
                $minutes = (int) ($in['minutes'] ?? 150) ?: 150;
                $want = Availability::LANES;
            } elseif ($category === 'birthday') {
                Perms::require($actor, 'events.birthday');
            }
            if (!Cat::isMatch($category)) {
                if (!empty($in['user_id'])) $user = Auth::userById((int) $in['user_id']);
                elseif (!empty($in['guest'])) $user = Auth::findOrCreateProfile((array) $in['guest'], $actor);
                if (!$user) throw new AppError('Unesite gosta (ime i telefon).');
                if ($category === 'training') {
                    if (!$user['club_id']) throw new AppError('Treninzi su samo za članove klubova.');
                    $clubId = (int) $user['club_id'];
                }
            }
        } else {
            if ($actor['blocked_utc']) throw new AppError('Vaš nalog je blokiran za nove rezervacije. Obratite se osoblju.', 403);
            $user = $actor;
            if ($user['club_id'] && empty($in['personal'])) { $category = 'training'; $clubId = (int) $user['club_id']; }
        }

        $end = self::validateTime($start, $minutes, $category, $staffMode);
        $pv = Pricing::current();

        $id = Db::tx(function () use ($actor, $user, $category, $clubId, $start, $end, $minutes, $want, $match, $in, $pv, $staffMode) {
            Db::lockDay(T::day($start));
            $lanes = Availability::pick($start, $end, $want);
            $clubAmount = null;
            if ($category === 'training') $clubAmount = Pricing::training(count($lanes), $minutes, $pv);
            if (Cat::isMatch($category)) $clubAmount = Pricing::match(Db::one('SELECT * FROM clubs WHERE id = ?', [$clubId]), count($lanes), $minutes);
            $id = Db::insert('reservations', array_merge([
                'user_id' => $user['id'] ?? null, 'category' => $category, 'club_id' => $clubId, 'start_at' => $start, 'end_at' => $end,
                'source' => $staffMode ? 'staff' : 'online', 'notes' => str_opt($in, 'notes', 1000), 'price_version_id' => $pv['id'],
                'club_amount_cents' => $clubAmount, 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc(),
            ], $match));
            foreach ($lanes as $l) Db::insert('reservation_lanes', ['reservation_id' => $id, 'lane_id' => $l]);
            Availability::occupy($lanes, $start, $end, $id);
            Coverage::plan($id);
            Audit::log('reservation.created', 'reservation', $id, ['start' => $start, 'end' => $end, 'lanes' => $lanes, 'category' => $category, 'user_id' => $user['id'] ?? null]);
            return $id;
        });
        $r = self::get($id);
        Notify::event('reservation_created', ['reservation_id' => $id]);
        return $r;
    }

    private static function canManage(array $actor, array $r): bool
    {
        if (Perms::has($actor, 'res.write')) return true;
        return (int) $r['user_id'] === (int) $actor['id'];
    }

    /** Izmjena termina/staza/trajanja — ponovo provjerava dostupnost u istoj transakciji. */
    public static function change(array $actor, int $id, array $in, bool $extendOnly = false): array
    {
        $r0 = self::get($id);
        if (!self::canManage($actor, $r0)) throw new AppError('Nemate ovlaštenje za ovu rezervaciju.', 403);
        $staff = Perms::has($actor, 'res.write');
        if ($r0['status'] !== 'active') throw new AppError('Rezervacija je otkazana.');
        if ($r0['processed_utc'] || $r0['attendance'] === 'no_show') throw new AppError('Termin je već zaključen.');
        if (!$staff && $actor['blocked_utc'] && !$extendOnly) throw new AppError('Vaš nalog je blokiran za izmjene.', 403);

        if ($extendOnly) {
            $add = int_req($in, 'add_minutes');
            if ($add <= 0 || $add % 30 !== 0) throw new AppError('Produženje u koracima od 30 minuta.');
            if ($r0['end_at'] <= Clock::nowLocal() && !$staff) throw new AppError('Termin je završen.');
            $start = $r0['start_at'];
            $minutes = T::minutes($r0['start_at'], $r0['end_at']) + $add;
            $want = $r0['lanes'];
        } else {
            if (!$staff && $r0['start_at'] <= Clock::nowLocal()) throw new AppError('Termin je već počeo — moguće je samo produženje.');
            $start = isset($in['start']) ? T::parseLocal((string) $in['start']) : $r0['start_at'];
            $minutes = isset($in['minutes']) ? int_req($in, 'minutes') : T::minutes($r0['start_at'], $r0['end_at']);
            $want = isset($in['lanes']) && is_array($in['lanes']) && $in['lanes'] ? $in['lanes'] : (isset($in['lane_count']) ? (int) $in['lane_count'] : $r0['lanes']);
        }
        $end = self::validateTime($start, $minutes, $r0['category'], $staff, $extendOnly);
        if (!$staff && !$extendOnly && !in_array($minutes, [60, 90, 120], true)) throw new AppError('Trajanje online rezervacije: 60, 90 ili 120 minuta.');

        Db::tx(function () use ($actor, $id, $start, $end, $want, $minutes, $staff) {
            $days = array_unique([T::day($start), T::day(self::get($id)['start_at'])]); sort($days);
            foreach ($days as $d) Db::lockDay($d);
            $r = self::lockRow($id);
            $lanes = Availability::pick($start, $end, $want, $id, true, $r['series_id'] ? (int) $r['series_id'] : null);
            Availability::freeReservation($id);
            Db::q('DELETE FROM reservation_lanes WHERE reservation_id = ?', [$id]);
            foreach ($lanes as $l) Db::insert('reservation_lanes', ['reservation_id' => $id, 'lane_id' => $l]);
            Availability::occupy($lanes, $start, $end, $id);
            $late = !$staff && T::minutes(Clock::nowLocal(), $r['start_at']) < self::TIMELY_CANCEL_MIN ? 1 : (int) $r['late_change'];
            $pv = Pricing::version((int) $r['price_version_id']);
            $clubAmount = $r['club_amount_cents'];
            if ($r['category'] === 'training') $clubAmount = Pricing::training(count($lanes), $minutes, $pv);
            if (Cat::isMatch($r['category'])) $clubAmount = Pricing::match(Db::one('SELECT * FROM clubs WHERE id = ?', [$r['club_id']]), count($lanes), $minutes);
            Db::update('reservations', ['start_at' => $start, 'end_at' => $end, 'late_change' => $late, 'club_amount_cents' => $clubAmount, 'updated_utc' => Clock::nowUtc()], 'id = ?', [$id]);
            Coverage::plan($id);
            if ($r['user_id']) Coverage::replanUser((int) $r['user_id']);
            Audit::log('reservation.changed', 'reservation', $id, ['from' => [$r['start_at'], $r['end_at'], $r['lanes']], 'to' => [$start, $end, $lanes]]);
        });
        Notify::event('reservation_changed', ['reservation_id' => $id]);
        return self::get($id);
    }

    /** $kind: 'guest' (gost ili osoblje na zahtjev gosta) ili 'arena' (otkaz na strani Arene). */
    public static function cancel(array $actor, int $id, string $kind, ?string $reason = null): array
    {
        $r0 = self::get($id);
        if (!self::canManage($actor, $r0)) throw new AppError('Nemate ovlaštenje za ovu rezervaciju.', 403);
        $isStaff = Perms::has($actor, 'res.write');
        if ($kind === 'arena' && !$isStaff) throw new AppError('Nemate ovlaštenje.', 403);
        if ($r0['status'] !== 'active') throw new AppError('Rezervacija je već otkazana.');
        if ($r0['processed_utc'] || $r0['attendance'] !== 'pending') throw new AppError('Termin je već evidentiran — otkazivanje nije moguće.');
        if (!$isStaff && $r0['start_at'] <= Clock::nowLocal()) throw new AppError('Termin je već počeo.');
        if ($kind === 'arena' && !$reason) throw new AppError('Unesite razlog otkazivanja od strane Arene.');

        $bonus = Db::tx(function () use ($id, $kind, $reason, $actor) {
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Rezervacija je već otkazana.');
            Db::lockDay(T::day($r['start_at']));
            $ck = $kind === 'arena' ? 'arena' : (T::minutes(Clock::nowLocal(), $r['start_at']) >= self::TIMELY_CANCEL_MIN ? 'guest' : 'guest_late');
            Availability::freeReservation($id);
            $bonus = 0;
            if ($ck === 'arena') $bonus = Coverage::compensateArena($id, 'Otkaz Arene: ' . $reason . ' (' . T::human($r['start_at']) . ')');
            Coverage::release($id);
            Db::update('reservations', ['status' => 'cancelled', 'cancel_kind' => $ck, 'cancel_reason' => $reason, 'cancelled_utc' => Clock::nowUtc(), 'cancelled_by' => $actor['id'], 'updated_utc' => Clock::nowUtc()], 'id = ?', [$id]);
            if ($r['user_id']) Coverage::replanUser((int) $r['user_id']);
            Audit::log('reservation.cancelled', 'reservation', $id, ['kind' => $ck, 'bonus_minutes' => $bonus], $reason);
            return $bonus;
        });
        $r = self::get($id);
        Notify::event($r['cancel_kind'] === 'arena' ? 'reservation_cancelled_arena' : 'reservation_cancelled', ['reservation_id' => $id, 'bonus_minutes' => $bonus]);
        return $r;
    }

    public static function markArrived(array $actor, int $id): array
    {
        Perms::require($actor, 'res.attendance');
        Db::tx(function () use ($id) {
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Rezervacija je otkazana.');
            if ($r['attendance'] === 'no_show') throw new AppError('Označen je nedolazak.');
            if (Cat::isClub($r['category'])) throw new AppError('Klupski termini se ne označavaju — evidentiraju se automatski.');
            if ($r['start_at'] > T::addMin(Clock::nowLocal(), 60)) throw new AppError('Dolazak se može označiti najranije sat prije početka.');
            Db::update('reservations', ['attendance' => 'arrived', 'attendance_utc' => Clock::nowUtc(), 'attendance_by' => Audit::$actor['id'] ?? null], 'id = ?', [$id]);
            Audit::log('reservation.arrived', 'reservation', $id);
        });
        return self::get($id);
    }

    /** Nedolazak (nakon 10 min kašnjenja). $release = osloboditi stazu za novu rezervaciju (zapis ostaje). */
    public static function markNoShow(array $actor, int $id, bool $release): array
    {
        Perms::require($actor, 'res.attendance');
        $result = Db::tx(function () use ($id, $release) {
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Rezervacija je otkazana.');
            if ($r['attendance'] !== 'pending' || $r['processed_utc']) throw new AppError('Dolazak je već evidentiran.');
            if (Cat::isClub($r['category'])) throw new AppError('Za klupske termine se ne vodi nedolazak.');
            if (Clock::nowLocal() < T::addMin($r['start_at'], self::LATE_NO_SHOW_MIN)) throw new AppError('Nedolazak se može označiti tek nakon 10 minuta kašnjenja.');
            Db::lockDay(T::day($r['start_at']));
            $res = ['debt' => null, 'warning' => false];
            $releasedAt = null;
            if ($release && Clock::nowLocal() < $r['end_at']) {
                $now = T::dt(Clock::nowLocal());
                $m = (int) $now->format('i');
                $from = $now->setTime((int) $now->format('H'), $m - $m % 15, 0)->format('Y-m-d H:i:s');
                $from = max($from, $r['start_at']);
                Availability::freeReservation($id, $from);
                $releasedAt = $from;
            }
            if ($r['category'] === 'monthly') {
                Coverage::forfeit($id);
            } elseif ($r['category'] === 'recreation' && $r['user_id']) {
                Coverage::forfeit($id);
                $prior = (int) Db::val("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND id <> ? AND attendance = 'no_show' AND category = 'recreation'", [$r['user_id'], $id]);
                if ($prior === 0) {
                    Db::insert('warnings', ['user_id' => $r['user_id'], 'reservation_id' => $id, 'kind' => 'no_show_first', 'text' => 'Prvi nedolazak (' . T::human($r['start_at']) . ') — upozorenje, bez duga.', 'created_utc' => Clock::nowUtc()]);
                    $res['warning'] = true;
                } else {
                    $pv = Pricing::version((int) $r['price_version_id']);
                    $amount = Pricing::laneCash(T::minutes($r['start_at'], $r['end_at']), $pv) * count($r['lanes']);
                    $debtId = Db::insert('debts', ['user_id' => $r['user_id'], 'reservation_id' => $id, 'amount_cents' => $amount, 'reason' => 'Nedolazak ' . T::human($r['start_at']) . ' (' . count($r['lanes']) . ' staza)', 'created_utc' => Clock::nowUtc()]);
                    $res['debt'] = ['id' => $debtId, 'amount_cents' => $amount];
                }
            } elseif ($r['category'] === 'birthday' && $r['user_id'] && Settings::get('birthday_noshow_debt') === '1') {
                // Otvorena poslovna odluka (isključeno podrazumijevano) — vidi Administracija → Cijene.
                $pv = Pricing::version((int) $r['price_version_id']);
                $amount = Pricing::laneCash(T::minutes($r['start_at'], $r['end_at']), $pv) * count($r['lanes']);
                $debtId = Db::insert('debts', ['user_id' => $r['user_id'], 'reservation_id' => $id, 'amount_cents' => $amount, 'reason' => 'Nedolazak na rođendan ' . T::human($r['start_at']) . ' (' . count($r['lanes']) . ' staza)', 'created_utc' => Clock::nowUtc()]);
                $res['debt'] = ['id' => $debtId, 'amount_cents' => $amount];
            }
            Db::update('reservations', ['attendance' => 'no_show', 'attendance_utc' => Clock::nowUtc(), 'attendance_by' => Audit::$actor['id'] ?? null, 'released_at' => $releasedAt, 'processed_utc' => Clock::nowUtc(), 'processed_by' => Audit::$actor['id'] ?? null], 'id = ?', [$id]);
            Audit::log('reservation.no_show', 'reservation', $id, $res + ['released_from' => $releasedAt]);
            return $res;
        });
        return self::get($id) + ['no_show_result' => $result];
    }

    /**
     * Ispravka: poništava pogrešno evidentiran dolazak/naplatu/nedolazak i vraća termin na "čeka",
     * da ga osoblje može ponovo ispravno obraditi. Samo vlasnik i menadžer (cash.correct).
     * Uplate se STORNIRAJU (ne brišu), dug nastao greškom se oprašta, a redovni sati mjesečne koji su
     * propali zbog pogrešno označenog nedolaska vraćaju se na raspolaganje. Bonus sati koji su u
     * međuvremenu POTROŠENI (ne samo planirani) ovim se ne vraćaju automatski — za to služi ručna
     * dodjela bonus sati (sa svojim razlogom i tragom), da ne bi došlo do neprimijećenog dupliranja.
     */
    public static function reopen(array $actor, int $id, string $reason): array
    {
        Perms::require($actor, 'cash.correct');
        if (trim($reason) === '') throw new AppError('Unesite razlog ispravke.');
        Db::tx(function () use ($actor, $id, $reason) {
            $today = DayClose::paymentDay();
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Otkazana rezervacija se ne ispravlja ovako.');
            if ($r['attendance'] === 'pending' && !$r['processed_utc']) throw new AppError('Termin čeka obradu — nema šta da se poništi.');
            Db::lockDay(T::day($r['start_at']));
            $reversed = 0;
            foreach (Db::all('SELECT * FROM payments WHERE reservation_id = ? AND reverses_payment_id IS NULL', [$id]) as $p) {
                if (Db::val('SELECT id FROM payments WHERE reverses_payment_id = ?', [$p['id']])) continue;
                Db::insert('payments', ['business_day' => $today, 'user_id' => $p['user_id'], 'reservation_id' => $id, 'debt_id' => $p['debt_id'], 'pass_id' => $p['pass_id'],
                    'kind' => $p['kind'], 'category' => $p['category'], 'amount_cents' => -(int) $p['amount_cents'], 'reverses_payment_id' => $p['id'],
                    'reason' => 'Poništeno pri ispravci termina: ' . $reason, 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()]);
                $reversed += (int) $p['amount_cents'];
            }
            $debt = Db::one("SELECT * FROM debts WHERE reservation_id = ? AND status = 'open'", [$id]);
            if ($debt) Db::update('debts', ['status' => 'forgiven', 'closed_by' => $actor['id'], 'closed_utc' => Clock::nowUtc(), 'close_reason' => 'Poništeno pri ispravci termina: ' . $reason], 'id = ?', [$debt['id']]);
            Db::q("DELETE FROM hour_allocations WHERE reservation_id = ? AND source = 'regular' AND state IN ('forfeited','used')", [$id]);
            Db::q('DELETE FROM charges WHERE reservation_id = ?', [$id]);
            Db::update('reservations', ['attendance' => 'pending', 'attendance_utc' => null, 'attendance_by' => null, 'released_at' => null, 'used_minutes' => null, 'processed_utc' => null, 'processed_by' => null, 'updated_utc' => Clock::nowUtc()], 'id = ?', [$id]);
            if ($r['user_id']) Coverage::replanUser((int) $r['user_id']);
            Audit::log('reservation.reopened', 'reservation', $id, ['reversed_cents' => $reversed, 'debt_forgiven' => $debt ? (int) $debt['id'] : null], $reason);
        });
        return self::get($id);
    }

    /**
     * Član kluba (ili osoblje u njegovo ime) preuzima slobodnu stazu u terminu treninga svog kluba.
     * Staza koju niko ne preuzme i dalje je trening (klub se naplaćuje za cijeli termin bez obzira na
     * popunjenost) — preuzimanje samo evidentira ko je na toj stazi igrao, bez posebne naplate igrača.
     */
    public static function claimTrainingLane(array $actor, int $id, int $laneId, ?int $forUserId = null): array
    {
        $staff = Perms::has($actor, 'res.write');
        if ($forUserId !== null && !$staff) throw new AppError('Nemate ovlaštenje da prijavite drugog igrača.', 403);
        Db::tx(function () use ($actor, $id, $laneId, $forUserId, $staff) {
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Rezervacija je otkazana.');
            if ($r['category'] !== 'training') throw new AppError('Ova staza nije u terminu treninga.');
            if (!in_array($laneId, $r['lanes'], true)) throw new AppError('Staza nije u ovom terminu.');
            if ($r['end_at'] <= Clock::nowLocal()) throw new AppError('Termin je već završen.');
            $targetId = $forUserId ?? (int) $actor['id'];
            $target = Auth::userById($targetId);
            if (!$target || (int) $target['club_id'] !== (int) $r['club_id']) throw new AppError('Igrač nije član ovog kluba.');
            $n = Db::q('UPDATE reservation_lanes SET claimed_by_user_id = ?, claimed_utc = ? WHERE reservation_id = ? AND lane_id = ? AND claimed_by_user_id IS NULL', [$targetId, Clock::nowUtc(), $id, $laneId])->rowCount();
            if (!$n) throw new AppError('Staza je već zauzeta.');
            Audit::log('training_lane.claimed', 'reservation', $id, ['lane_id' => $laneId, 'user_id' => $targetId]);
        });
        return self::get($id);
    }

    /** Oslobađa prijavljenu stazu u terminu treninga (sam igrač ili osoblje). */
    public static function releaseTrainingLane(array $actor, int $id, int $laneId): array
    {
        $staff = Perms::has($actor, 'res.write');
        Db::tx(function () use ($actor, $id, $laneId, $staff) {
            $r = self::lockRow($id);
            if ($r['category'] !== 'training') throw new AppError('Ova staza nije u terminu treninga.');
            $claimed = Db::val('SELECT claimed_by_user_id FROM reservation_lanes WHERE reservation_id = ? AND lane_id = ?', [$id, $laneId]);
            if (!$claimed) throw new AppError('Staza nije prijavljena.');
            if (!$staff && (int) $claimed !== (int) $actor['id']) throw new AppError('Nemate ovlaštenje.', 403);
            Db::q('UPDATE reservation_lanes SET claimed_by_user_id = NULL, claimed_utc = NULL WHERE reservation_id = ? AND lane_id = ?', [$id, $laneId]);
            Audit::log('training_lane.released', 'reservation', $id, ['lane_id' => $laneId, 'user_id' => (int) $claimed]);
        });
        return self::get($id);
    }

    /** Prijedlog naplate (bez upisa). */
    public static function quoteSettle(int $id, ?int $usedMinutes = null): array
    {
        $r = self::get($id);
        $pv = Pricing::version((int) $r['price_version_id']);
        if ($r['category'] === 'birthday') {
            $bill = $usedMinutes ?? T::minutes($r['start_at'], $r['end_at']);
            $lanes = []; $tot = 0;
            foreach ($r['lanes'] as $_) { $c = Pricing::laneCash($bill, $pv); $lanes[] = ['covered' => 0, 'cash_minutes' => $bill, 'cash' => $c]; $tot += $c; }
            return ['category' => 'birthday', 'minutes' => T::minutes($r['start_at'], $r['end_at']), 'bill_minutes' => $bill, 'regular' => 0, 'bonus' => 0, 'lanes' => $lanes, 'cash_total' => $tot, 'shoes_cents' => (int) $pv['shoes_cents']];
        }
        $c = Coverage::compute($r['user_id'] ? (int) $r['user_id'] : null, $r['category'], $r['start_at'], $r['end_at'], count($r['lanes']), $pv, $id, $usedMinutes);
        unset($c['pass']);
        return $c + ['shoes_cents' => (int) $pv['shoes_cents']];
    }

    /**
     * Naplata i zaključenje termina. Obavezno: shoes_used (true/false) — „Jesu li korištene patike?“.
     * $in: shoes_used, shoe_pairs, used_minutes (kraće korištenje), debt_ids[] (prethodni dug, odvojene stavke).
     */
    public static function settle(array $actor, int $id, array $in): array
    {
        Perms::require($actor, 'cash.collect');
        if (!array_key_exists('shoes_used', $in) || !is_bool($in['shoes_used'])) throw new AppError('Odgovorite na pitanje: „Jesu li korištene patike?“');
        $pairs = $in['shoes_used'] ? (int) ($in['shoe_pairs'] ?? 0) : 0;
        if ($in['shoes_used'] && ($pairs < 1 || $pairs > 60)) throw new AppError('Unesite broj pari patika.');
        $used = isset($in['used_minutes']) && $in['used_minutes'] !== '' && $in['used_minutes'] !== null ? (int) $in['used_minutes'] : null;
        $debtIds = array_map('intval', (array) ($in['debt_ids'] ?? []));

        return Db::tx(function () use ($actor, $id, $pairs, $used, $debtIds) {
            $today = DayClose::paymentDay();
            $r = self::lockRow($id);
            if ($r['status'] !== 'active') throw new AppError('Rezervacija je otkazana.');
            if ($r['processed_utc']) throw new AppError('Termin je već zaključen.');
            if (!Cat::isPersonal($r['category'])) throw new AppError('Ovaj termin se ne naplaćuje gotovinom.');
            if ($r['attendance'] === 'no_show') throw new AppError('Označen je nedolazak.');
            $full = T::minutes($r['start_at'], $r['end_at']);
            if ($used !== null && ($used <= 0 || $used > $full || $used % 15 !== 0)) throw new AppError('Stvarno korištenje mora biti između 15 i ' . $full . ' minuta (koraci po 15).');
            if ($r['category'] !== 'birthday') Coverage::plan($id);
            $q = self::quoteSettle($id, $used);
            $pv = Pricing::version((int) $r['price_version_id']);
            Db::q('DELETE FROM charges WHERE reservation_id = ?', [$id]);
            foreach ($q['lanes'] as $i => $ln) Db::insert('charges', ['reservation_id' => $id, 'kind' => 'lane', 'lane_id' => $r['lanes'][$i] ?? null, 'minutes' => $ln['cash_minutes'], 'qty' => 1, 'amount_cents' => $ln['cash'], 'note' => $ln['covered'] ? ('pokriveno ' . $ln['covered'] . ' min') : null]);
            $shoes = $pairs * (int) $pv['shoes_cents'];
            if ($pairs) Db::insert('charges', ['reservation_id' => $id, 'kind' => 'shoes', 'qty' => $pairs, 'amount_cents' => $shoes]);
            $base = ['business_day' => $today, 'user_id' => $r['user_id'], 'reservation_id' => $id, 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()];
            $pays = [];
            if ($q['cash_total'] > 0) $pays[] = Db::insert('payments', $base + ['kind' => 'lane', 'category' => $r['category'], 'amount_cents' => $q['cash_total']]);
            if ($shoes > 0) $pays[] = Db::insert('payments', $base + ['kind' => 'shoes', 'category' => $r['category'], 'amount_cents' => $shoes]);
            $debtTotal = 0;
            foreach ($debtIds as $did) {
                $d = Db::one("SELECT * FROM debts WHERE id = ? AND status = 'open' FOR UPDATE", [$did]);
                if (!$d || (int) $d['user_id'] !== (int) $r['user_id']) throw new AppError('Dug nije pronađen ili je već riješen.');
                $pays[] = Db::insert('payments', ['business_day' => $today, 'user_id' => $d['user_id'], 'debt_id' => $did, 'kind' => 'debt', 'category' => 'recreation', 'amount_cents' => $d['amount_cents'], 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()]);
                Db::update('debts', ['status' => 'paid', 'closed_by' => $actor['id'], 'closed_utc' => Clock::nowUtc()], 'id = ?', [$did]);
                $debtTotal += (int) $d['amount_cents'];
            }
            Coverage::markUsed($id);
            Db::update('reservations', ['attendance' => 'arrived', 'attendance_utc' => $r['attendance_utc'] ?? Clock::nowUtc(), 'attendance_by' => $r['attendance_by'] ?? $actor['id'], 'used_minutes' => $used, 'processed_utc' => Clock::nowUtc(), 'processed_by' => $actor['id']], 'id = ?', [$id]);
            $receipt = ['lane_cents' => $q['cash_total'], 'shoe_pairs' => $pairs, 'shoes_cents' => $shoes, 'debt_cents' => $debtTotal, 'total_cents' => $q['cash_total'] + $shoes + $debtTotal, 'regular_minutes' => $q['regular'], 'bonus_minutes' => $q['bonus'], 'payment_ids' => $pays];
            Audit::log('reservation.settled', 'reservation', $id, $receipt);
            return $receipt;
        });
    }

    /** Klupski termini: po isteku vremena smatraju se održanim i evidentiranim za klub. */
    public static function autoProcessClub(): int
    {
        return Db::q("UPDATE reservations SET processed_utc = ? WHERE status = 'active' AND processed_utc IS NULL AND end_at <= ? AND (category = 'training' OR category LIKE 'match\\_%')", [Clock::nowUtc(), Clock::nowLocal()])->rowCount();
    }

    /** Detalj za bočni panel: podaci, pokriće, upozorenja i dozvoljene radnje. */
    public static function detail(array $actor, int $id): array
    {
        $r = self::get($id);
        $staff = Perms::has($actor, 'res.read');
        if (!$staff && (int) $r['user_id'] !== (int) $actor['id']) throw new AppError('Nemate pristup.', 403);
        $u = $r['user_id'] ? Auth::userById((int) $r['user_id']) : null;
        $club = $r['club_id'] ? Db::one('SELECT id, name, short FROM clubs WHERE id = ?', [$r['club_id']]) : null;
        $now = Clock::nowLocal();
        $out = [
            'id' => (int) $r['id'], 'category' => $r['category'], 'category_label' => Cat::label($r['category']), 'color' => Cat::ALL[$r['category']][1] ?? '#666',
            'start_at' => $r['start_at'], 'end_at' => $r['end_at'], 'lanes' => $r['lanes'], 'status' => $r['status'], 'cancel_kind' => $r['cancel_kind'], 'cancel_reason' => $r['cancel_reason'],
            'attendance' => $r['attendance'], 'processed' => (bool) $r['processed_utc'], 'released_at' => $r['released_at'], 'late_change' => (bool) $r['late_change'],
            'source' => $r['source'], 'notes' => $r['notes'], 'club' => $club, 'series_id' => $r['series_id'] ? (int) $r['series_id'] : null,
            'match' => Cat::isMatch($r['category']) ? ['home' => $r['match_home'], 'guest' => $r['match_guest'], 'league' => $r['match_league']] : null,
            'user' => $u ? ['id' => (int) $u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']), 'phone' => Phone::pretty($u['phone']), 'blocked' => (bool) $u['blocked_utc'], 'has_account' => (bool) $u['password_hash']] : null,
        ];
        $isOwnClubMember = !$staff && Cat::isClub($r['category']);
        if (Cat::isPersonal($r['category'])) {
            $out['coverage'] = ($r['processed_utc'] || $r['status'] !== 'active') ? null : self::quoteSettle($id);
            $out['allocations'] = Coverage::allocations($id);
            $out['charges'] = Db::all('SELECT kind, lane_id, minutes, qty, amount_cents, note FROM charges WHERE reservation_id = ?', [$id]);
            $out['paid_cents'] = (int) Db::val('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE reservation_id = ?', [$id]);
        }
        if ($staff && Cat::isClub($r['category'])) {
            $out['club_amount_cents'] = $r['club_amount_cents'] !== null ? (int) $r['club_amount_cents'] : null;
            $st = Db::one('SELECT s.id, s.number FROM club_statement_items i JOIN club_statements s ON s.id = i.statement_id WHERE i.reservation_id = ?', [$id]);
            $out['billing'] = $st ? ['state' => 'Obračunato', 'statement' => $st] : ['state' => 'Neobračunato'];
        }
        if ($isOwnClubMember) unset($out['club_amount_cents']);
        $warnings = [];
        if ($u) {
            $debt = (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM debts WHERE user_id = ? AND status = 'open'", [$u['id']]);
            if ($debt > 0) $warnings[] = ['kind' => 'debt', 'text' => 'Evidentiran dug: ' . Money::fmt($debt) . ' — provjeriti s gostom.', 'amount_cents' => $debt];
            $ns = (int) Db::val("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND attendance = 'no_show'", [$u['id']]);
            if ($ns > 0) $warnings[] = ['kind' => 'no_show', 'text' => 'Nedolazaka do sada: ' . $ns];
            if ($u['blocked_utc']) $warnings[] = ['kind' => 'blocked', 'text' => 'Nalog je blokiran.'];
            if ($staff) $out['open_debts'] = Db::all("SELECT id, amount_cents, reason FROM debts WHERE user_id = ? AND status = 'open'", [$u['id']]);
        }
        if ($r['late_change']) $warnings[] = ['kind' => 'late', 'text' => 'Kasna izmjena (manje od 2 h prije početka).'];
        if ($r['cancel_kind'] === 'guest_late') $warnings[] = ['kind' => 'late', 'text' => 'Kasno otkazivanje — odluku o posljedicama donosi vlasnik/menadžer.'];
        $out['warnings'] = $warnings;

        $a = [];
        $active = $r['status'] === 'active';
        $open = $active && !$r['processed_utc'] && $r['attendance'] !== 'no_show';
        $personal = Cat::isPersonal($r['category']);
        $mine = (int) $r['user_id'] === (int) $actor['id'];
        if ($open && $personal && Perms::has($actor, 'res.attendance') && $r['attendance'] === 'pending' && $r['start_at'] <= T::addMin($now, 60)) $a[] = 'arrived';
        if ($open && $personal && Perms::has($actor, 'res.attendance') && $r['attendance'] === 'pending' && $now >= T::addMin($r['start_at'], self::LATE_NO_SHOW_MIN)) $a[] = 'no_show';
        if ($open && $personal && Perms::has($actor, 'cash.collect') && $r['start_at'] <= T::addMin($now, 60)) $a[] = 'settle';
        if ($open && (Perms::has($actor, 'res.write') || ($mine && $r['start_at'] > $now))) $a[] = 'edit';
        if ($open && (Perms::has($actor, 'res.write') || $mine) && $r['end_at'] > $now) $a[] = 'extend';
        if ($open && $r['attendance'] === 'pending' && (Perms::has($actor, 'res.write') || ($mine && $r['start_at'] > $now))) $a[] = 'cancel';
        if ($open && $r['attendance'] === 'pending' && Perms::has($actor, 'res.write')) $a[] = 'cancel_arena';
        if ($active && $personal && !$open && Perms::has($actor, 'cash.correct')) $a[] = 'reopen';
        $out['actions'] = $a;
        return $out;
    }
}
