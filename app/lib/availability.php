<?php
declare(strict_types=1);

final class Availability
{
    public const LANES = [1, 2, 3, 4, 5, 6];

    /** Staze koje imaju bilo koji zauzet blok u [start, end). */
    public static function busyLanes(string $start, string $end, ?int $excludeRes = null, ?int $excludeEvent = null): array
    {
        $sql = 'SELECT DISTINCT lane_id FROM lane_slots WHERE slot_start >= ? AND slot_start < ?';
        $p = [$start, $end];
        if ($excludeRes) { $sql .= ' AND (reservation_id IS NULL OR reservation_id <> ?)'; $p[] = $excludeRes; }
        if ($excludeEvent) { $sql .= ' AND (event_id IS NULL OR event_id <> ?)'; $p[] = $excludeEvent; }
        return array_map('intval', array_column(Db::all($sql, $p), 'lane_id'));
    }
    public static function freeLanes(string $start, string $end, ?int $excludeRes = null): array
    {
        return array_values(array_diff(self::LANES, self::busyLanes($start, $end, $excludeRes)));
    }

    /** Zauzeti broj staza po bloku (za provjeru virtualnih pojava stalnih termina). */
    private static function occupiedBySlot(string $start, string $end, ?int $excludeRes): array
    {
        $sql = 'SELECT slot_start, COUNT(*) n FROM lane_slots WHERE slot_start >= ? AND slot_start < ?' . ($excludeRes ? ' AND (reservation_id IS NULL OR reservation_id <> ' . (int) $excludeRes . ')' : '') . ' GROUP BY slot_start';
        $out = [];
        foreach (Db::all($sql, [$start, $end]) as $r) $out[$r['slot_start']] = (int) $r['n'];
        return $out;
    }

    /**
     * Virtualne (još neupisane) pojave odobrenih stalnih termina za dan: potražnja staza po bloku.
     * Ovo omogućava pravilo ponavljanja bez beskonačnog broja redova u bazi.
     */
    public static function seriesDemand(string $start, string $end, ?int $excludeSeries = null): array
    {
        $date = T::day($start);
        $wd = T::weekday($date);
        $rows = Db::all("SELECT s.* FROM recurring_series s WHERE s.status = 'approved' AND s.weekday = ? AND s.valid_from <= ? AND (s.valid_to IS NULL OR s.valid_to >= ?)
            AND NOT EXISTS (SELECT 1 FROM series_exceptions e WHERE e.series_id = s.id AND e.date = ?)
            AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.series_id = s.id AND r.occurrence_date = ?)", [$wd, $date, $date, $date, $date]);
        $demand = [];
        foreach ($rows as $s) {
            if ($excludeSeries && (int) $s['id'] === $excludeSeries) continue;
            $ss = $date . ' ' . $s['start_time'] . ':00';
            $se = T::addMin($ss, (int) $s['minutes']);
            if ($se <= $start || $ss >= $end) continue;
            foreach (T::slots($ss, $se) as $sl) if ($sl >= $start && $sl < $end) $demand[$sl] = ($demand[$sl] ?? 0) + (int) $s['lane_count'];
        }
        return $demand;
    }

    /**
     * Bira staze za [start, end). $want = lista konkretnih staza ili broj staza (sistem bira najniže slobodne).
     * Baca 409 ako nema mjesta. Ne upisuje ništa.
     */
    public static function pick(string $start, string $end, array|int $want, ?int $excludeRes = null, bool $respectSeries = true, ?int $excludeSeries = null): array
    {
        $free = self::freeLanes($start, $end, $excludeRes);
        if (is_array($want)) {
            $want = array_values(array_unique(array_map('intval', $want)));
            sort($want);
            if (!$want) throw new AppError('Izaberite barem jednu stazu.');
            foreach ($want as $l) if (!in_array($l, self::LANES, true)) throw new AppError('Nepostojeća staza.');
            $taken = array_diff($want, $free);
            if ($taken) throw new AppError('Zauzeto: staza ' . implode(', ', $taken) . '. Izaberite drugi termin ili druge staze.', 409, ['free' => $free]);
            $lanes = $want;
        } else {
            if ($want < 1 || $want > 6) throw new AppError('Broj staza mora biti 1–6.');
            if (count($free) < $want) throw new AppError(count($free) === 0 ? 'U tom terminu nema slobodnih staza.' : 'U tom terminu je slobodno samo ' . count($free) . ' staza.', 409, ['free' => $free]);
            $lanes = array_slice($free, 0, $want);
        }
        if ($respectSeries) {
            $demand = self::seriesDemand($start, $end, $excludeSeries);
            if ($demand) {
                $occ = self::occupiedBySlot($start, $end, $excludeRes);
                foreach ($demand as $sl => $d) {
                    if (($occ[$sl] ?? 0) + count($lanes) + $d > 6) throw new AppError('Termin je rezervisan za stalni klupski trening.', 409);
                }
            }
        }
        return $lanes;
    }

    public static function occupy(array $lanes, string $start, string $end, ?int $resId, ?int $eventId = null): void
    {
        $slots = T::slots($start, $end);
        if (!$slots) return;
        $vals = []; $p = [];
        foreach ($lanes as $l) foreach ($slots as $s) { $vals[] = '(?,?,?,?)'; array_push($p, $l, $s, $resId, $eventId); }
        try {
            Db::q('INSERT INTO lane_slots (lane_id, slot_start, reservation_id, event_id) VALUES ' . implode(',', $vals), $p);
        } catch (PDOException $e) {
            if (Db::isDuplicate($e)) throw new AppError('Termin je upravo zauzet. Osvježite kalendar i izaberite drugi termin.', 409);
            throw $e;
        }
    }
    public static function freeReservation(int $resId, ?string $from = null): void
    {
        if ($from) Db::q('DELETE FROM lane_slots WHERE reservation_id = ? AND slot_start >= ?', [$resId, $from]);
        else Db::q('DELETE FROM lane_slots WHERE reservation_id = ?', [$resId]);
    }

    /** Svi konflikti u intervalu (za turnire/kvalifikacije/odobrenje serija). */
    public static function conflicts(string $start, string $end, array $lanes, ?int $excludeEvent = null, ?int $excludeSeries = null): array
    {
        $in = implode(',', array_map('intval', $lanes));
        $out = [];
        $rows = Db::all("SELECT DISTINCT r.id, r.category, r.start_at, r.end_at, r.user_id, r.club_id FROM lane_slots s JOIN reservations r ON r.id = s.reservation_id
            WHERE s.slot_start >= ? AND s.slot_start < ? AND s.lane_id IN ($in) ORDER BY r.start_at", [$start, $end]);
        foreach ($rows as $r) $out[] = ['type' => 'reservation', 'id' => (int) $r['id'], 'category' => $r['category'], 'label' => Cat::label($r['category']), 'start_at' => $r['start_at'], 'end_at' => $r['end_at']];
        $ev = Db::all("SELECT DISTINCT e.id, e.kind, e.title, e.start_at, e.end_at FROM lane_slots s JOIN events e ON e.id = s.event_id
            WHERE s.slot_start >= ? AND s.slot_start < ? AND s.lane_id IN ($in)" . ($excludeEvent ? ' AND e.id <> ' . (int) $excludeEvent : ''), [$start, $end]);
        foreach ($ev as $e) $out[] = ['type' => 'event', 'id' => (int) $e['id'], 'category' => $e['kind'], 'label' => $e['title'], 'start_at' => $e['start_at'], 'end_at' => $e['end_at']];
        // virtualne pojave stalnih termina (po danima u intervalu)
        for ($d = T::day($start); $d <= T::day(T::addMin($end, -1)); $d = T::addDays($d, 1)) {
            $ds = max($start, $d . ' 00:00:00'); $de = min($end, T::addDays($d, 1) . ' 00:00:00');
            foreach (Db::all("SELECT s.*, c.name club FROM recurring_series s JOIN clubs c ON c.id = s.club_id WHERE s.status = 'approved' AND s.weekday = ? AND s.valid_from <= ? AND (s.valid_to IS NULL OR s.valid_to >= ?)
                AND NOT EXISTS (SELECT 1 FROM series_exceptions e WHERE e.series_id = s.id AND e.date = ?)
                AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.series_id = s.id AND r.occurrence_date = ?)", [T::weekday($d), $d, $d, $d, $d]) as $s) {
                if ($excludeSeries && (int) $s['id'] === $excludeSeries) continue;
                $ss = $d . ' ' . $s['start_time'] . ':00'; $se = T::addMin($ss, (int) $s['minutes']);
                if ($se <= $ds || $ss >= $de) continue;
                $out[] = ['type' => 'series', 'id' => (int) $s['id'], 'date' => $d, 'category' => 'training', 'label' => 'Stalni termin ' . $s['club'], 'start_at' => $ss, 'end_at' => $se];
            }
        }
        return $out;
    }
}
