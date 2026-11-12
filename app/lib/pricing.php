<?php
declare(strict_types=1);

final class Pricing
{
    public static function current(): array
    {
        $r = Db::one('SELECT * FROM price_versions ORDER BY id DESC LIMIT 1');
        if (!$r) throw new RuntimeException('Nema cjenovnika.');
        return $r;
    }
    public static function version(int $id): array { return Db::one('SELECT * FROM price_versions WHERE id = ?', [$id]) ?? self::current(); }

    /** Rekreacija/rođendan po stazi: 7,50 KM po započetih 30 min, najmanje 15,00 KM. */
    public static function laneCash(int $minutes, array $pv): int
    {
        if ($minutes <= 0) return 0;
        $units = intdiv($minutes + 29, 30);
        return max($units * (int) $pv['recreation_30_cents'], (int) $pv['recreation_min_cents']);
    }
    /** Ostatak na stazi djelimično pokrivenoj mjesečnom/bonusom: po započetih 30 min, bez minimuma (otvorena odluka 3). */
    public static function remainderCash(int $minutes, array $pv): int
    {
        if ($minutes <= 0) return 0;
        $per30 = (int) ($pv['monthly_remainder_30_cents'] ?? $pv['recreation_30_cents']);
        return max(intdiv($minutes + 29, 30) * $per30, (int) ($pv['monthly_remainder_min_cents'] ?? 0));
    }
    public static function training(int $lanes, int $minutes, array $pv): int
    {
        return intdiv($lanes * $minutes * (int) $pv['training_lane_hour_cents'] + 30, 60);
    }
    public static function match(array $club, int $lanes, int $minutes): int
    {
        if ($club['match_price_mode'] === 'fixed') return (int) $club['match_fixed_cents'];
        return intdiv($lanes * $minutes * (int) $club['match_lane_hour_cents'] + 30, 60);
    }
}

/** Mjesečne, VIP i bonus sati. Sve u minutama. */
final class Coverage
{
    public const COUNTED = "('planned','used','forfeited','compensated')";

    public static function passOn(int $userId, string $date): ?array
    {
        return Db::one('SELECT * FROM monthly_passes WHERE user_id = ? AND start_date <= ? AND end_date > ? ORDER BY id DESC LIMIT 1', [$userId, $date, $date]);
    }
    public static function currentPass(int $userId): ?array { return self::passOn($userId, Clock::today()); }
    public static function latestPass(int $userId): ?array { return Db::one('SELECT * FROM monthly_passes WHERE user_id = ? ORDER BY end_date DESC, id DESC LIMIT 1', [$userId]); }

    public static function regularUsed(int $passId, string $weekStart, ?int $excludeRes = null): int
    {
        return (int) Db::val('SELECT COALESCE(SUM(minutes),0) FROM hour_allocations WHERE pass_id = ? AND week_start = ? AND source = \'regular\' AND state IN ' . self::COUNTED . ($excludeRes ? ' AND reservation_id <> ' . (int) $excludeRes : ''), [$passId, $weekStart]);
    }
    public static function bonusBalance(int $userId, ?int $excludeRes = null): int
    {
        $g = (int) Db::val('SELECT COALESCE(SUM(minutes),0) FROM bonus_grants WHERE user_id = ?', [$userId]);
        $u = (int) Db::val('SELECT COALESCE(SUM(minutes),0) FROM hour_allocations WHERE user_id = ? AND source = \'bonus\' AND state IN ' . self::COUNTED . ($excludeRes ? ' AND reservation_id <> ' . (int) $excludeRes : ''), [$userId]);
        return $g - $u;
    }
    public static function bonusPlanned(int $userId): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(minutes),0) FROM hour_allocations WHERE user_id = ? AND source = 'bonus' AND state = 'planned'", [$userId]);
    }

    /**
     * Izračun pokrića i cijene (bez upisa). Redoslijed: redovni sati → bonus → gotovina.
     * Mjesečna pokriva jednu stazu; ostale staze se plaćaju kao rekreacija.
     */
    public static function compute(?int $userId, string $category, string $start, string $end, int $laneCount, array $pv, ?int $excludeRes = null, ?int $usedMinutes = null): array
    {
        $minutes = T::minutes($start, $end);
        $billMin = $usedMinutes ?? $minutes;
        $out = ['minutes' => $minutes, 'bill_minutes' => $billMin, 'pass' => null, 'regular' => 0, 'bonus' => 0, 'week_start' => null, 'lanes' => [], 'cash_total' => 0, 'category' => $category, 'regular_left' => null, 'bonus_left' => null];
        $coverable = $userId && in_array($category, ['monthly', 'recreation'], true);
        if ($coverable) {
            $date = T::day($start);
            $pass = self::passOn($userId, $date);
            $out['category'] = $pass ? 'monthly' : 'recreation';
            $need = $minutes; // planira se rezervisano trajanje (sati se drže unaprijed)
            if ($pass) {
                $ws = T::weekStart($date);
                $avail = max(0, (int) $pass['weekly_minutes'] - self::regularUsed((int) $pass['id'], $ws, $excludeRes));
                $out['pass'] = $pass; $out['week_start'] = $ws;
                $out['regular'] = min($avail, $need); $need -= $out['regular'];
                $out['regular_left'] = $avail - $out['regular'];
            }
            $bal = max(0, self::bonusBalance($userId, $excludeRes));
            if ($need > 0 && $bal > 0) { $out['bonus'] = min($bal, $need); $need -= $out['bonus']; }
            $out['bonus_left'] = $bal - $out['bonus'];
        }
        $covered = $out['regular'] + $out['bonus'];
        for ($i = 0; $i < $laneCount; $i++) {
            if ($i === 0 && $covered > 0) {
                $rest = max(0, $billMin - $covered);
                $cash = Pricing::remainderCash($rest, $pv);
                $out['lanes'][] = ['covered' => $covered, 'cash_minutes' => $rest, 'cash' => $cash];
            } else {
                $cash = Pricing::laneCash($billMin, $pv);
                $out['lanes'][] = ['covered' => 0, 'cash_minutes' => $billMin, 'cash' => $cash];
            }
            $out['cash_total'] += $cash;
        }
        return $out;
    }

    /** Ponovo planira pokriće jedne rezervacije (briše stari plan, upisuje novi). */
    public static function plan(int $resId): array
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ?', [$resId]);
        Db::q("DELETE FROM hour_allocations WHERE reservation_id = ? AND state = 'planned'", [$resId]);
        if (!$r || $r['status'] !== 'active' || !$r['user_id'] || !in_array($r['category'], ['monthly', 'recreation'], true) || $r['processed_utc']) return [];
        $lanes = (int) Db::val('SELECT COUNT(*) FROM reservation_lanes WHERE reservation_id = ?', [$resId]);
        $c = self::compute((int) $r['user_id'], $r['category'], $r['start_at'], $r['end_at'], $lanes, Pricing::version((int) $r['price_version_id']), $resId);
        if ($c['regular'] > 0) Db::insert('hour_allocations', ['reservation_id' => $resId, 'user_id' => $r['user_id'], 'source' => 'regular', 'pass_id' => $c['pass']['id'], 'week_start' => $c['week_start'], 'minutes' => $c['regular'], 'state' => 'planned', 'created_utc' => Clock::nowUtc()]);
        if ($c['bonus'] > 0) Db::insert('hour_allocations', ['reservation_id' => $resId, 'user_id' => $r['user_id'], 'source' => 'bonus', 'minutes' => $c['bonus'], 'state' => 'planned', 'created_utc' => Clock::nowUtc()]);
        if ($c['category'] !== $r['category']) Db::update('reservations', ['category' => $c['category']], 'id = ?', [$resId]);
        return $c;
    }

    /** Preračun budućih neobrađenih rezervacija korisnika (hronološki — raniji termin ima prednost na satima). */
    public static function replanUser(int $userId): void
    {
        $ids = array_column(Db::all("SELECT id FROM reservations WHERE user_id = ? AND status = 'active' AND processed_utc IS NULL AND attendance = 'pending' AND category IN ('monthly','recreation') AND end_at > ? ORDER BY start_at, id", [$userId, Clock::nowLocal()]), 'id');
        if (!$ids) return;
        Db::q("DELETE FROM hour_allocations WHERE state = 'planned' AND reservation_id IN (" . implode(',', array_map('intval', $ids)) . ')');
        foreach ($ids as $id) self::plan((int) $id);
    }

    public static function allocations(int $resId): array { return Db::all('SELECT * FROM hour_allocations WHERE reservation_id = ?', [$resId]); }
    public static function release(int $resId): void { Db::q("DELETE FROM hour_allocations WHERE reservation_id = ? AND state = 'planned'", [$resId]); }
    public static function markUsed(int $resId): void { Db::q("UPDATE hour_allocations SET state = 'used' WHERE reservation_id = ? AND state = 'planned'", [$resId]); }
    /** Nedolazak korisnika mjesečne: redovni sati propadaju, bonus se vraća. */
    public static function forfeit(int $resId): void
    {
        Db::q("UPDATE hour_allocations SET state = 'forfeited' WHERE reservation_id = ? AND state = 'planned' AND source = 'regular'", [$resId]);
        Db::q("DELETE FROM hour_allocations WHERE reservation_id = ? AND state = 'planned' AND source = 'bonus'", [$resId]);
    }

    /**
     * Otkaz od strane Arene: pokriveni sati se NE vraćaju; umjesto toga 2× bonus.
     * Jedinstveni source_reservation_id sprečava ponovnu dodjelu (nema trostrukog odobravanja).
     */
    public static function compensateArena(int $resId, string $reason): int
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ?', [$resId]);
        if (!$r || !$r['user_id']) return 0;
        $covered = (int) Db::val("SELECT COALESCE(SUM(minutes),0) FROM hour_allocations WHERE reservation_id = ? AND state = 'planned'", [$resId]);
        if ($covered <= 0) return 0;
        if (Db::val('SELECT id FROM bonus_grants WHERE source_reservation_id = ?', [$resId])) return 0;
        Db::q("UPDATE hour_allocations SET state = 'compensated' WHERE reservation_id = ? AND state = 'planned'", [$resId]);
        $grant = $covered * 2;
        Db::insert('bonus_grants', ['user_id' => $r['user_id'], 'minutes' => $grant, 'kind' => 'arena_cancel', 'reason' => $reason, 'source_reservation_id' => $resId, 'created_by' => Audit::$actor['id'] ?? null, 'created_utc' => Clock::nowUtc()]);
        Audit::log('bonus.granted', 'user', $r['user_id'], ['minutes' => $grant, 'reservation_id' => $resId], $reason);
        return $grant;
    }

    public static function manualBonus(int $userId, int $minutes, string $reason): void
    {
        if ($minutes === 0 || $minutes % 15 !== 0) throw new AppError('Bonus se unosi u koracima od 15 minuta.');
        if ($minutes < 0 && self::bonusBalance($userId) + $minutes < 0) throw new AppError('Ne može se oduzeti više bonus sati nego što korisnik ima.');
        Db::insert('bonus_grants', ['user_id' => $userId, 'minutes' => $minutes, 'kind' => 'manual', 'reason' => $reason, 'created_by' => Audit::$actor['id'] ?? null, 'created_utc' => Clock::nowUtc()]);
        Audit::log('bonus.manual', 'user', $userId, ['minutes' => $minutes], $reason);
    }

    /** Aktivacija/produženje nakon primljene gotovine. Period počinje danas (bez retroaktivnog početka). */
    /** Šta će se desiti pri aktivaciji danas (prikazuje se prije potvrde). */
    public static function activationPreview(int $userId): array
    {
        $today = Clock::today();
        $cur = self::passOn($userId, $today);
        $end = T::addMonth($today);
        return ['start' => $today, 'last_day' => T::addDays($end, -1), 'amount_cents' => (int) Pricing::current()['monthly_cents'],
            'current' => $cur ? ['kind' => $cur['kind'], 'last_day' => T::addDays($cur['end_date'], -1), 'lost_days' => (int) (T::minutes($today . ' 12:00:00', $cur['end_date'] . ' 12:00:00') / 1440)] : null];
    }

    public static function activate(int $userId, string $kind, ?int $weeklyMinutes, array $by, bool $confirmEarly = false): array
    {
        $pre = self::activationPreview($userId);
        if ($pre['current'] && !$confirmEarly) {
            throw new AppError('Korisnik ima aktivnu mjesečnu do ' . T::humanDate($pre['current']['last_day']) . ' Novi period bi trajao od ' . T::humanDate($pre['start']) . ' do ' . T::humanDate($pre['last_day'])
                . ' — preostalih ' . $pre['current']['lost_days'] . ' dana prethodnog perioda NE prelazi u novi. Potvrdite produženje.', 409, ['preview' => $pre, 'needs_confirm' => true]);
        }
        return Db::tx(function () use ($userId, $kind, $weeklyMinutes, $by) {
            $u = Db::one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$u) throw new AppError('Korisnik ne postoji.', 404);
            $today = Clock::today();            // početak perioda = stvarni dan uplate
            $payDay = DayClose::paymentDay();   // dan dnevnog obračuna (sljedeći ako je današnji zaključen)
            $pv = Pricing::current();
            if ($kind === 'vip') {
                if (!Perms::has($by, 'monthly.vip')) throw new AppError('VIP mjesečnu može odobriti samo vlasnik ili ovlašteni menadžer.', 403);
                if (!$weeklyMinutes || $weeklyMinutes < 15 || $weeklyMinutes % 15 !== 0) throw new AppError('Unesite sedmični broj sati za VIP (u koracima od 15 min).');
            } else { $kind = 'standard'; $weeklyMinutes = (int) Settings::get('standard_weekly_minutes', '120'); }
            $cur = self::passOn($userId, $today);
            if ($cur) Db::update('monthly_passes', ['end_date' => $today, 'status' => 'ended', 'ended_reason' => 'Novi period od ' . T::humanDate($today)], 'id = ?', [$cur['id']]);
            $payId = Db::insert('payments', ['business_day' => $payDay, 'user_id' => $userId, 'kind' => 'monthly', 'category' => 'monthly', 'amount_cents' => (int) $pv['monthly_cents'], 'created_by' => $by['id'], 'created_utc' => Clock::nowUtc()]);
            $passId = Db::insert('monthly_passes', ['user_id' => $userId, 'kind' => $kind, 'weekly_minutes' => $weeklyMinutes, 'start_date' => $today, 'end_date' => T::addMonth($today), 'payment_id' => $payId, 'created_by' => $by['id'], 'created_utc' => Clock::nowUtc()]);
            Db::update('payments', ['pass_id' => $passId], 'id = ?', [$payId]);
            Db::q("UPDATE monthly_requests SET status = 'done', handled_by = ?, handled_utc = ? WHERE user_id = ? AND status = 'pending'", [$by['id'], Clock::nowUtc(), $userId]);
            if ($cur) {
                // Planirani sati starog perioda za datume od danas prelaze na novi period.
                $ids = array_column(Db::all("SELECT DISTINCT reservation_id FROM hour_allocations WHERE pass_id = ? AND state = 'planned'", [$cur['id']]), 'reservation_id');
                if ($ids) Db::q("DELETE FROM hour_allocations WHERE state = 'planned' AND reservation_id IN (" . implode(',', array_map('intval', $ids)) . ')');
            }
            self::replanUser($userId);
            Audit::log('monthly.activated', 'user', $userId, ['pass_id' => $passId, 'kind' => $kind, 'weekly_minutes' => $weeklyMinutes, 'start' => $today, 'end' => T::addMonth($today), 'amount' => (int) $pv['monthly_cents']]);
            Notify::event('monthly_activated', ['user_id' => $userId, 'pass_id' => $passId]);
            return Db::one('SELECT * FROM monthly_passes WHERE id = ?', [$passId]);
        });
    }

    public static function request(int $userId, string $kind): void
    {
        if (Db::val("SELECT id FROM monthly_requests WHERE user_id = ? AND status = 'pending'", [$userId])) throw new AppError('Zahtjev je već poslan i čeka osoblje.');
        $id = Db::insert('monthly_requests', ['user_id' => $userId, 'kind' => $kind === 'renew' ? 'renew' : 'new', 'created_utc' => Clock::nowUtc()]);
        Audit::log('monthly.requested', 'user', $userId, ['request_id' => $id]);
        Notify::event('monthly_request', ['user_id' => $userId, 'request_id' => $id]);
    }

    /** Sažetak za korisnika (mjesečna, preostali sati ove sedmice, bonus). */
    public static function summary(int $userId): array
    {
        $today = Clock::today();
        $pass = self::passOn($userId, $today);
        $latest = self::latestPass($userId);
        $ws = T::weekStart($today);
        $regLeft = null;
        if ($pass) $regLeft = max(0, (int) $pass['weekly_minutes'] - self::regularUsed((int) $pass['id'], $ws));
        $grants = Db::all('SELECT minutes, kind, reason, source_reservation_id, created_utc FROM bonus_grants WHERE user_id = ? ORDER BY id DESC', [$userId]);
        $usage = Db::all("SELECT a.minutes, a.state, r.start_at, r.id reservation_id FROM hour_allocations a JOIN reservations r ON r.id = a.reservation_id WHERE a.user_id = ? AND a.source = 'bonus' ORDER BY r.start_at DESC", [$userId]);
        return [
            'pass' => $pass, 'latest_pass' => $latest,
            'week_start' => $ws, 'regular_left' => $regLeft,
            'bonus_balance' => self::bonusBalance($userId), 'bonus_planned' => self::bonusPlanned($userId),
            'bonus_grants' => $grants, 'bonus_usage' => $usage,
            'pending_request' => Db::one("SELECT * FROM monthly_requests WHERE user_id = ? AND status = 'pending'", [$userId]),
        ];
    }
}
