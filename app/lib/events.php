<?php
declare(strict_types=1);

/**
 * Turniri i kvalifikacije. Blokada objekta (events + lane_slots) je odvojena od nastupa (event_entries):
 * nastupi ne zauzimaju blokove, pa ne prave lažne konflikte sa sopstvenom blokadom.
 */
final class Events
{
    public const QUAL_SLOT = 75;

    private static function parse(array $in): array
    {
        $kind = ($in['kind'] ?? '') === 'qualification' ? 'qualification' : 'tournament';
        $start = T::parseLocal((string) ($in['start'] ?? ''));
        $end = T::parseLocal((string) ($in['end'] ?? ''));
        if (T::day($start) !== T::day($end) && T::hm($end) !== '00:00') throw new AppError('Događaj mora biti u okviru jednog dana (za više dana unesite svaki dan posebno).');
        if ($end <= $start) throw new AppError('Kraj mora biti poslije početka.');
        if (T::hm($start) < '08:00' || T::hm($end) > '23:00') throw new AppError('Događaj mora biti između 08:00 i 23:00.');
        if (!preg_match('/:(00|15|30|45):00$/', $start) || !preg_match('/:(00|15|30|45):00$/', $end)) throw new AppError('Vrijeme u koracima od 15 minuta.');
        $lanes = $kind === 'tournament' ? Availability::LANES : (isset($in['lanes']) && is_array($in['lanes']) && $in['lanes'] ? array_values(array_intersect(Availability::LANES, array_map('intval', $in['lanes']))) : Availability::LANES);
        if (!$lanes) throw new AppError('Izaberite staze.');
        return ['kind' => $kind, 'title' => str_req($in, 'title', 160), 'start_at' => $start, 'end_at' => $end, 'lanes' => $lanes,
            'slot_minutes' => $kind === 'qualification' ? self::QUAL_SLOT : null, 'signup_phone' => str_opt($in, 'signup_phone', 40), 'public_note' => str_opt($in, 'public_note', 2000)];
    }

    /** Konflikti prije potvrde (postojeće rezervacije, druge blokade, stalni klupski termini). */
    public static function preview(array $actor, array $in): array
    {
        Perms::require($actor, 'events.manage');
        $e = self::parse($in);
        return ['conflicts' => Availability::conflicts($e['start_at'], $e['end_at'], $e['lanes'], isset($in['id']) ? (int) $in['id'] : null)];
    }

    public static function create(array $actor, array $in): array
    {
        Perms::require($actor, 'events.manage');
        $e = self::parse($in);
        $id = Db::tx(function () use ($actor, $e) {
            Db::lockDay(T::day($e['start_at']));
            $c = Availability::conflicts($e['start_at'], $e['end_at'], $e['lanes']);
            if ($c) throw new AppError('Prije potvrde riješite postojeće konflikte (premjestite ili otkažite termine, ili preskočite stalne termine).', 409, ['conflicts' => $c]);
            $lanes = $e['lanes']; unset($e['lanes']);
            $id = Db::insert('events', $e + ['created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()]);
            foreach ($lanes as $l) Db::insert('event_lanes', ['event_id' => $id, 'lane_id' => $l]);
            Availability::occupy($lanes, $e['start_at'], $e['end_at'], null, $id);
            Audit::log('event.created', 'event', $id, $e + ['lanes' => $lanes]);
            return $id;
        });
        return self::get($id, true);
    }

    public static function cancel(array $actor, int $id, string $reason): void
    {
        Perms::require($actor, 'events.manage');
        Db::tx(function () use ($id, $reason) {
            $e = Db::one("SELECT * FROM events WHERE id = ? AND status = 'active' FOR UPDATE", [$id]);
            if (!$e) throw new AppError('Događaj nije aktivan.');
            Db::lockDay(T::day($e['start_at']));
            Db::q('DELETE FROM lane_slots WHERE event_id = ?', [$id]);
            Db::update('events', ['status' => 'cancelled'], 'id = ?', [$id]);
            Audit::log('event.cancelled', 'event', $id, [], $reason);
        });
    }

    public static function get(int $id, bool $staff): array
    {
        $e = Db::one('SELECT * FROM events WHERE id = ?', [$id]);
        if (!$e) throw new AppError('Događaj ne postoji.', 404);
        $e['lanes'] = array_map('intval', array_column(Db::all('SELECT lane_id FROM event_lanes WHERE event_id = ? ORDER BY lane_id', [$id]), 'lane_id'));
        $e['entries'] = Db::all('SELECT id, lane_id, start_at, end_at, player_name' . ($staff ? ', note' : '') . ' FROM event_entries WHERE event_id = ? ORDER BY start_at, lane_id', [$id]);
        if ($e['kind'] === 'qualification') $e['positions'] = self::positions($e);
        unset($e['created_by']);
        return $e;
    }

    /** Kvalifikacije: termini od početka svakih 75 min × staze; slobodna mjesta. */
    public static function positions(array $e): array
    {
        $out = [];
        $taken = [];
        foreach ($e['entries'] as $en) $taken[$en['lane_id'] . '|' . $en['start_at']] = $en['player_name'];
        for ($t = $e['start_at']; T::addMin($t, self::QUAL_SLOT) <= $e['end_at']; $t = T::addMin($t, self::QUAL_SLOT)) {
            foreach ($e['lanes'] as $l) $out[] = ['lane_id' => $l, 'start_at' => $t, 'end_at' => T::addMin($t, self::QUAL_SLOT), 'player_name' => $taken[$l . '|' . $t] ?? null];
        }
        return $out;
    }

    /** Unos igrača (prijave idu telefonom; unosi osoblje). */
    public static function addEntry(array $actor, int $eventId, array $in): array
    {
        Perms::require($actor, 'events.entries');
        return Db::tx(function () use ($eventId, $in) {
            $e = Db::one("SELECT * FROM events WHERE id = ? AND status = 'active' FOR UPDATE", [$eventId]);
            if (!$e) throw new AppError('Događaj nije aktivan.');
            $e['lanes'] = array_map('intval', array_column(Db::all('SELECT lane_id FROM event_lanes WHERE event_id = ?', [$eventId]), 'lane_id'));
            $lane = int_req($in, 'lane_id');
            if (!in_array($lane, $e['lanes'], true)) throw new AppError('Staza nije dio događaja.');
            $start = T::parseLocal((string) ($in['start'] ?? ''));
            if ($e['kind'] === 'qualification') {
                if (T::minutes($e['start_at'], $start) % self::QUAL_SLOT !== 0) throw new AppError('Nastup mora početi u terminu kvalifikacija (svakih 75 min).');
                $end = T::addMin($start, self::QUAL_SLOT);
            } else {
                $end = isset($in['end']) && $in['end'] ? T::parseLocal((string) $in['end']) : T::addMin($start, 60);
            }
            if ($start < $e['start_at'] || $end > $e['end_at']) throw new AppError('Nastup mora biti unutar trajanja događaja.');
            // samo nastupi istog događaja se međusobno porede; blokada događaja se NE smatra konfliktom
            if (Db::val('SELECT id FROM event_entries WHERE event_id = ? AND lane_id = ? AND start_at < ? AND end_at > ?', [$eventId, $lane, $end, $start])) throw new AppError('Na toj stazi u tom terminu već je upisan igrač.', 409);
            $id = Db::insert('event_entries', ['event_id' => $eventId, 'lane_id' => $lane, 'start_at' => $start, 'end_at' => $end, 'player_name' => str_req($in, 'player_name', 160), 'note' => str_opt($in, 'note', 255), 'created_by' => Audit::$actor['id'] ?? null, 'created_utc' => Clock::nowUtc()]);
            Audit::log('event.entry_added', 'event', $eventId, ['entry_id' => $id, 'lane' => $lane, 'start' => $start]);
            return ['id' => $id];
        });
    }

    public static function removeEntry(array $actor, int $entryId): void
    {
        Perms::require($actor, 'events.entries');
        $en = Db::one('SELECT * FROM event_entries WHERE id = ?', [$entryId]);
        if (!$en) throw new AppError('Nastup ne postoji.', 404);
        Db::q('DELETE FROM event_entries WHERE id = ?', [$entryId]);
        Audit::log('event.entry_removed', 'event', $en['event_id'], $en);
    }

    public static function list(string $from, string $to): array
    {
        return Db::all("SELECT id, kind, title, start_at, end_at, status, signup_phone FROM events WHERE start_at >= ? AND start_at < ? ORDER BY start_at", [$from . ' 00:00:00', T::addDays($to, 1) . ' 00:00:00']);
    }
}
