<?php
declare(strict_types=1);

/** Ispravke naplate, dugovi, dnevni obračun. Finansijska historija se nikad ne briše. */
final class Cash
{
    /** Storno uplate (+ opciono nova ispravna stavka). Original ostaje. */
    public static function correct(array $actor, int $paymentId, ?int $newAmount, string $reason): array
    {
        Perms::require($actor, 'cash.correct');
        if (trim($reason) === '') throw new AppError('Unesite razlog ispravke.');
        $out = Db::tx(function () use ($actor, $paymentId, $newAmount, $reason) {
            $today = DayClose::paymentDay();
            $p = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p) throw new AppError('Uplata ne postoji.', 404);
            if ($p['reverses_payment_id']) throw new AppError('Storno stavka se ne može ponovo stornirati.');
            if (Db::val('SELECT id FROM payments WHERE reverses_payment_id = ?', [$paymentId])) throw new AppError('Uplata je već stornirana.');
            $common = ['business_day' => $today, 'user_id' => $p['user_id'], 'reservation_id' => $p['reservation_id'], 'debt_id' => $p['debt_id'], 'pass_id' => $p['pass_id'], 'kind' => $p['kind'], 'category' => $p['category'], 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()];
            $rev = Db::insert('payments', $common + ['amount_cents' => -(int) $p['amount_cents'], 'reverses_payment_id' => $paymentId, 'reason' => $reason]);
            $new = null;
            if ($newAmount !== null) {
                if ($newAmount < 0) throw new AppError('Iznos ne može biti negativan.');
                $new = Db::insert('payments', $common + ['amount_cents' => $newAmount, 'reason' => 'Ispravka uplate #' . $paymentId . ': ' . $reason]);
            }
            Audit::log('payment.corrected', 'payment', $paymentId, ['old' => (int) $p['amount_cents'], 'new' => $newAmount, 'reversal_id' => $rev, 'new_id' => $new], $reason);
            return ['reversal_id' => $rev, 'new_id' => $new, 'old_cents' => (int) $p['amount_cents'], 'new_cents' => $newAmount];
        });
        Notify::event('payment_corrected', $out + ['payment_id' => $paymentId, 'reason' => $reason, 'author' => Audit::$actor['label'] ?? '']);
        return $out;
    }

    public static function forgiveDebt(array $actor, int $debtId, string $reason): void
    {
        Perms::require($actor, 'debt.forgive');
        if (trim($reason) === '') throw new AppError('Unesite razlog oprosta.');
        $d = Db::tx(function () use ($actor, $debtId, $reason) {
            $d = Db::one('SELECT * FROM debts WHERE id = ? FOR UPDATE', [$debtId]);
            if (!$d || $d['status'] !== 'open') throw new AppError('Dug nije otvoren.');
            Db::update('debts', ['status' => 'forgiven', 'closed_by' => $actor['id'], 'closed_utc' => Clock::nowUtc(), 'close_reason' => $reason], 'id = ?', [$debtId]);
            Audit::log('debt.forgiven', 'debt', $debtId, ['amount' => (int) $d['amount_cents'], 'user_id' => (int) $d['user_id']], $reason);
            return $d;
        });
        Notify::event('debt_forgiven', ['debt_id' => $debtId, 'user_id' => (int) $d['user_id'], 'amount_cents' => (int) $d['amount_cents'], 'reason' => $reason, 'author' => Audit::$actor['label'] ?? '']);
    }

    /** Samostalna naplata starog duga (bez termina). */
    public static function payDebt(array $actor, int $debtId): int
    {
        Perms::require($actor, 'cash.collect');
        return Db::tx(function () use ($actor, $debtId) {
            $payDay = DayClose::paymentDay();
            $d = Db::one("SELECT * FROM debts WHERE id = ? AND status = 'open' FOR UPDATE", [$debtId]);
            if (!$d) throw new AppError('Dug nije otvoren.');
            $pid = Db::insert('payments', ['business_day' => $payDay, 'user_id' => $d['user_id'], 'debt_id' => $debtId, 'kind' => 'debt', 'category' => 'recreation', 'amount_cents' => $d['amount_cents'], 'created_by' => $actor['id'], 'created_utc' => Clock::nowUtc()]);
            Db::update('debts', ['status' => 'paid', 'closed_by' => $actor['id'], 'closed_utc' => Clock::nowUtc()], 'id = ?', [$debtId]);
            Audit::log('debt.paid', 'debt', $debtId, ['payment_id' => $pid]);
            return $pid;
        });
    }
}

final class DayClose
{
    public static function isClosed(string $day): bool { return (bool) Db::val('SELECT day FROM day_closes WHERE day = ?', [$day]); }
    /**
     * Dan u koji se upisuje nova uplata: danas, a ako je današnji dan već zaključen — prvi sljedeći nezaključeni dan
     * (zaključeni dnevni obračun se nikad ne mijenja).
     */
    public static function paymentDay(): string
    {
        $d = Clock::today();
        while (self::isClosed($d)) $d = T::addDays($d, 1);
        return $d;
    }

    /** Lični termini tog dana koji još nisu završeni — ne blokiraju zaključenje (informativno). */
    public static function openLater(string $day): array
    {
        $rows = Db::all("SELECT r.id, r.start_at, r.end_at, r.category, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) who FROM reservations r LEFT JOIN users u ON u.id = r.user_id
            WHERE r.status = 'active' AND r.processed_utc IS NULL AND r.category IN ('recreation','monthly','birthday') AND r.start_at >= ? AND r.start_at < ? AND r.end_at > ? ORDER BY r.start_at",
            [$day . ' 00:00:00', T::addDays($day, 1) . ' 00:00:00', Clock::nowLocal()]);
        return array_map(fn($r) => ['reservation_id' => (int) $r['id'], 'time' => T::hm($r['start_at']) . '–' . T::hm($r['end_at']), 'who' => $r['who'], 'category' => Cat::label($r['category'])], $rows);
    }

    public static function assertOpen(string $day): void
    {
        if (self::isClosed($day)) throw new AppError('Dan ' . T::humanDate($day) . ' je zaključen — izmjene nisu moguće.', 409);
    }

    /** Prepreke za zaključenje dana (sa linkom na stavku). */
    public static function blockers(string $day): array
    {
        Reservations::autoProcessClub();
        $now = Clock::nowLocal();
        $out = [];
        // Blokiraju samo ZAVRŠENI lični termini bez ishoda/pokrića. Budući i tekući termini ne blokiraju;
        // klupski termini se po završetku sami evidentiraju klubu i nikad ne blokiraju.
        $rows = Db::all("SELECT r.*, u.first_name, u.last_name FROM reservations r LEFT JOIN users u ON u.id = r.user_id WHERE r.status = 'active' AND r.processed_utc IS NULL AND r.category IN ('recreation','monthly','birthday') AND r.start_at >= ? AND r.start_at < ? AND r.end_at <= ? ORDER BY r.start_at", [$day . ' 00:00:00', T::addDays($day, 1) . ' 00:00:00', $now]);
        foreach ($rows as $r) {
            $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: Cat::label($r['category']);
            $why = $r['attendance'] === 'pending' ? 'Nije evidentiran dolazak ni naplata' : 'Došao — nije naplaćeno / nema pokrića';
            $out[] = ['reservation_id' => (int) $r['id'], 'time' => T::hm($r['start_at']) . '–' . T::hm($r['end_at']), 'who' => $who, 'category' => Cat::label($r['category']), 'reason' => $why];
        }
        return $out;
    }

    public static function totals(string $day): array
    {
        $rows = Db::all('SELECT kind, category, SUM(amount_cents) s, COUNT(*) n FROM payments WHERE business_day = ? GROUP BY kind, category', [$day]);
        $t = ['lane' => 0, 'shoes' => 0, 'monthly' => 0, 'debt' => 0, 'by_category' => [], 'total' => 0];
        foreach ($rows as $r) {
            $t[$r['kind']] += (int) $r['s'];
            $t['total'] += (int) $r['s'];
            if ($r['kind'] === 'lane') $t['by_category'][$r['category'] ?? 'recreation'] = ($t['by_category'][$r['category'] ?? 'recreation'] ?? 0) + (int) $r['s'];
        }
        $t['corrections'] = (int) Db::val('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE business_day = ? AND (reverses_payment_id IS NOT NULL OR reason LIKE \'Ispravka%\')', [$day]);
        $t['shoe_pairs'] = (int) Db::val("SELECT COALESCE(SUM(c.qty),0) FROM charges c JOIN payments p ON p.reservation_id = c.reservation_id AND p.kind = 'shoes' AND p.reverses_payment_id IS NULL WHERE p.business_day = ? AND c.kind = 'shoes'", [$day]);
        $club = Db::all("SELECT r.category, c.short, COUNT(*) n, SUM(r.club_amount_cents) s FROM reservations r JOIN clubs c ON c.id = r.club_id WHERE r.status = 'active' AND r.start_at >= ? AND r.start_at < ? AND (r.category = 'training' OR r.category LIKE 'match\\_%') GROUP BY r.category, c.short", [$day . ' 00:00:00', T::addDays($day, 1) . ' 00:00:00']);
        $t['club_value'] = array_map(fn($r) => ['category' => Cat::label($r['category']), 'club' => $r['short'], 'count' => (int) $r['n'], 'value_cents' => (int) $r['s']], $club);
        $t['payments'] = Db::all("SELECT p.id, p.kind, p.category, p.amount_cents, p.reservation_id, p.reverses_payment_id, p.reason, p.created_utc, TRIM(CONCAT(u.first_name,' ',u.last_name)) who, TRIM(CONCAT(a.first_name,' ',a.last_name)) by_name
            FROM payments p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN users a ON a.id = p.created_by WHERE p.business_day = ? ORDER BY p.id", [$day]);
        return $t;
    }

    public static function close(array $actor, string $day): array
    {
        Perms::require($actor, 'day.close');
        if ($day > Clock::today()) throw new AppError('Ne može se zaključiti budući dan.');
        return Db::tx(function () use ($actor, $day) {
            Db::lockDay($day);
            if (self::isClosed($day)) throw new AppError('Dan je već zaključen.');
            $b = self::blockers($day);
            if ($b) throw new AppError('Dan se ne može zaključiti — postoje neobrađeni termini.', 409, ['blockers' => $b]);
            $t = self::totals($day);
            unset($t['payments']);
            Db::insert('day_closes', ['day' => $day, 'totals_json' => json_encode($t, JSON_UNESCAPED_UNICODE), 'closed_by' => $actor['id'], 'closed_utc' => Clock::nowUtc()]);
            Audit::log('day.closed', 'day', $day, $t);
            return $t;
        });
    }
}
