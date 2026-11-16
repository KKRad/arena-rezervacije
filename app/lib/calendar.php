<?php
declare(strict_types=1);

/** Podaci za kalendar. $mode: public (bez privatnih podataka), user (+ svoje rezervacije), staff (sve). */
final class Calendar
{
    public static function day(string $date, string $mode, ?array $viewer = null): array
    {
        if (!T::validDate($date)) throw new AppError('Neispravan datum.');
        Series::materialize(max($date, T::addDays(Clock::today(), 14)));
        $from = $date . ' 00:00:00'; $to = T::addDays($date, 1) . ' 00:00:00';
        $blocks = [];
        $staff = $mode === 'staff';
        $now = Clock::nowLocal();
        $rows = Db::all("SELECT r.*, c.short club_short, c.name club_name, u.first_name, u.last_name FROM reservations r LEFT JOIN clubs c ON c.id = r.club_id LEFT JOIN users u ON u.id = r.user_id
            WHERE r.start_at >= ? AND r.start_at < ? AND (r.status = 'active'" . ($staff ? " OR r.status = 'cancelled'" : '') . ') ORDER BY r.start_at', [$from, $to]);
        $lanesBy = []; $claimBy = [];
        if ($rows) foreach (Db::all('SELECT reservation_id, lane_id, claimed_by_user_id FROM reservation_lanes WHERE reservation_id IN (' . implode(',', array_map(fn($r) => (int) $r['id'], $rows)) . ') ORDER BY lane_id') as $l) {
            $lanesBy[$l['reservation_id']][] = (int) $l['lane_id'];
            if ($l['claimed_by_user_id']) $claimBy[$l['reservation_id']][(int) $l['lane_id']] = (int) $l['claimed_by_user_id'];
        }
        $claimNames = [];
        $claimerIds = [];
        foreach ($claimBy as $byLane) foreach ($byLane as $uid) $claimerIds[$uid] = true;
        if ($claimerIds) foreach (Db::all('SELECT id, first_name, last_name FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($claimerIds))) . ')') as $u) $claimNames[(int) $u['id']] = trim($u['first_name'] . ' ' . $u['last_name']);
        foreach ($rows as $r) {
            $cat = $r['category'];
            $mine = $viewer && (int) $r['user_id'] === (int) $viewer['id'];
            $b = ['type' => 'res', 'id' => (int) $r['id'], 'category' => $cat, 'color' => Cat::ALL[$cat][1] ?? '#666', 'start_at' => $r['start_at'],
                'end_at' => $r['released_at'] && !$staff ? $r['released_at'] : $r['end_at'], 'lanes' => $lanesBy[$r['id']] ?? [], 'label' => Cat::label($cat), 'short' => self::short($cat), 'mine' => $mine];
            if ($r['released_at'] && !$staff && $r['released_at'] <= $r['start_at']) continue;
            if (Cat::isMatch($cat)) { $b['label'] = $r['match_home'] . ' – ' . $r['match_guest']; $b['sub'] = $r['match_league']; $b['short'] = 'UT'; }
            elseif ($cat === 'training' && $r['club_short']) {
                $b['label'] = 'Trening ' . $r['club_short'];
                $b['club_id'] = (int) $r['club_id'];
                $canClaim = $viewer && !empty($viewer['club_id']) && (int) $viewer['club_id'] === (int) $r['club_id'] && $r['status'] === 'active' && $r['end_at'] > $now;
                $b['lane_claims'] = array_map(function ($laneId) use ($claimBy, $r, $viewer, $claimNames, $staff) {
                    $uid = $claimBy[$r['id']][$laneId] ?? null;
                    $own = $uid && $viewer && (int) $uid === (int) $viewer['id'];
                    return ['lane_id' => $laneId, 'claimed' => $uid !== null, 'mine' => $own, 'name' => ($staff || $own) ? ($claimNames[$uid] ?? null) : null];
                }, $b['lanes']);
                $b['claimable'] = $canClaim;
            }
            if ($staff || $mine) {
                $b['who'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $b['state'] = self::state($r);
                $b['status'] = $r['status'];
            }
            if ($staff) { $b['released_at'] = $r['released_at']; $b['cancel_kind'] = $r['cancel_kind']; $b['notes'] = $r['notes']; }
            $blocks[] = $b;
        }
        foreach (Db::all("SELECT * FROM events WHERE status = 'active' AND start_at < ? AND end_at > ? ORDER BY start_at", [$to, $from]) as $e) {
            $ev = Events::get((int) $e['id'], $staff);
            $blocks[] = ['type' => 'event', 'id' => (int) $e['id'], 'category' => $e['kind'], 'color' => Cat::ALL[$e['kind']][1], 'start_at' => $e['start_at'], 'end_at' => $e['end_at'], 'lanes' => $ev['lanes'],
                'label' => $e['title'], 'short' => self::short($e['kind']), 'signup_phone' => $e['signup_phone'], 'public_note' => $e['public_note'],
                'entries' => $ev['entries'], 'positions' => $ev['positions'] ?? null];
        }
        return ['date' => $date, 'today' => Clock::today(), 'now' => Clock::nowLocal(), 'closed' => DayClose::isClosed($date),
            'lanes' => array_map(fn($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'short' => $l['short']], Db::all('SELECT * FROM lanes ORDER BY id')),
            'blocks' => $blocks, 'open' => Reservations::OPEN, 'close' => Reservations::CLOSE, 'cal_start' => Reservations::CAL_START,
            'categories' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v[0], 'color' => $v[1]], array_keys(Cat::ALL), Cat::ALL)];
    }

    /** Sedmica: po danu i satu, kategorija svake staze (za mali pregled). */
    public static function week(string $date, string $mode, ?array $viewer = null): array
    {
        $ws = T::weekStart($date);
        Series::materialize(max(T::addDays($ws, 6), T::addDays(Clock::today(), 14)));
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = T::addDays($ws, $i);
            $grid = [];
            $rows = Db::all("SELECT s.lane_id, s.slot_start, COALESCE(r.category, e.kind) cat, r.user_id FROM lane_slots s LEFT JOIN reservations r ON r.id = s.reservation_id LEFT JOIN events e ON e.id = s.event_id WHERE s.slot_start >= ? AND s.slot_start < ?", [$d . ' 00:00:00', T::addDays($d, 1) . ' 00:00:00']);
            foreach ($rows as $r) {
                $h = substr($r['slot_start'], 11, 2);
                $grid[$h][$r['lane_id']] = ['c' => $r['cat'], 'm' => $viewer && (int) $r['user_id'] === (int) $viewer['id']];
            }
            $days[] = ['date' => $d, 'grid' => $grid, 'closed' => $mode === 'staff' ? DayClose::isClosed($d) : null];
        }
        return ['week_start' => $ws, 'today' => Clock::today(), 'days' => $days, 'categories' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v[0], 'color' => $v[1]], array_keys(Cat::ALL), Cat::ALL)];
    }

    private static function short(string $c): string
    {
        return ['monthly' => 'MJ', 'training' => 'TR', 'recreation' => 'RE', 'birthday' => 'RĐ', 'tournament' => 'TU', 'qualification' => 'KV'][$c] ?? 'UT';
    }
    public static function state(array $r): string
    {
        if ($r['status'] === 'cancelled') return 'cancelled';
        if ($r['attendance'] === 'no_show') return 'no_show';
        if ($r['processed_utc']) return 'done';
        if ($r['attendance'] === 'arrived') return 'arrived';
        return 'pending';
    }
}

final class Stats
{
    public static function period(string $from, string $to): array
    {
        $fromT = $from . ' 00:00:00'; $toT = T::addDays($to, 1) . ' 00:00:00';
        $cash = Db::all('SELECT kind, category, SUM(amount_cents) s FROM payments WHERE business_day BETWEEN ? AND ? GROUP BY kind, category', [$from, $to]);
        $c = ['total' => 0, 'lane' => 0, 'shoes' => 0, 'monthly' => 0, 'debt' => 0, 'by_category' => []];
        foreach ($cash as $r) { $c['total'] += (int) $r['s']; $c[$r['kind']] += (int) $r['s']; $k = $r['kind'] === 'lane' ? ($r['category'] ?? 'recreation') : $r['kind']; $c['by_category'][$k] = ($c['by_category'][$k] ?? 0) + (int) $r['s']; }
        // popunjenost u staza-satima (blokovi od 15 min / 4), samo aktivni termini i blokade
        $occ = Db::all("SELECT COALESCE(r.category, e.kind) cat, COUNT(*) n FROM lane_slots s LEFT JOIN reservations r ON r.id = s.reservation_id LEFT JOIN events e ON e.id = s.event_id WHERE s.slot_start >= ? AND s.slot_start < ? GROUP BY cat", [$fromT, $toT]);
        $lh = []; $tot = 0;
        foreach ($occ as $o) { $lh[$o['cat']] = (int) $o['n'] / 4; $tot += (int) $o['n'] / 4; }
        $days = T::minutes($fromT, $toT) / 1440;
        $capacity = $days * 6 * 13; // radno vrijeme 10–23
        $clubs = Db::all("SELECT c.name, SUM(r.category = 'training') trainings, SUM(r.category LIKE 'match\\_%') matches, SUM(r.club_amount_cents) value_cents,
            SUM(CASE WHEN i.id IS NOT NULL THEN r.club_amount_cents ELSE 0 END) billed_cents
            FROM reservations r JOIN clubs c ON c.id = r.club_id LEFT JOIN club_statement_items i ON i.reservation_id = r.id
            WHERE r.status = 'active' AND r.start_at >= ? AND r.start_at < ? AND r.end_at <= ? AND (r.category = 'training' OR r.category LIKE 'match\\_%') GROUP BY c.id, c.name", [$fromT, $toT, Clock::nowLocal()]);
        $passes = Db::one("SELECT SUM(kind = 'standard') standard, SUM(kind = 'vip') vip, COUNT(*) total FROM monthly_passes WHERE start_date BETWEEN ? AND ?", [$from, $to]);
        $activePasses = (int) Db::val('SELECT COUNT(*) FROM monthly_passes WHERE start_date <= ? AND end_date > ?', [$to, $to]);
        $shoes = (int) Db::val("SELECT COALESCE(SUM(c.qty),0) FROM charges c JOIN reservations r ON r.id = c.reservation_id WHERE c.kind = 'shoes' AND r.start_at >= ? AND r.start_at < ?", [$fromT, $toT]);
        $noShows = Db::one("SELECT COUNT(*) n, SUM(category = 'monthly') monthly, SUM(category = 'recreation') recreation FROM reservations WHERE attendance = 'no_show' AND start_at >= ? AND start_at < ?", [$fromT, $toT]);
        $cancels = Db::one("SELECT SUM(cancel_kind = 'guest') timely, SUM(cancel_kind = 'guest_late') late, SUM(cancel_kind = 'arena') arena FROM reservations WHERE status = 'cancelled' AND start_at >= ? AND start_at < ?", [$fromT, $toT]);
        return ['from' => $from, 'to' => $to, 'cash' => $c, 'lane_hours' => $lh, 'lane_hours_total' => $tot, 'capacity_lane_hours' => $capacity,
            'occupancy_pct' => $capacity ? round($tot * 100 / $capacity, 1) : 0, 'clubs' => $clubs, 'passes' => $passes + ['active_at_end' => $activePasses],
            'shoe_pairs' => $shoes, 'no_shows' => $noShows, 'cancellations' => $cancels];
    }
    public static function compare(string $from, string $to): array
    {
        $len = (int) (T::minutes($from . ' 00:00:00', $to . ' 00:00:00') / 1440) + 1;
        $pTo = T::addDays($from, -1); $pFrom = T::addDays($pTo, -($len - 1));
        return ['current' => self::period($from, $to), 'previous' => self::period($pFrom, $pTo)];
    }
}
