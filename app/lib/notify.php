<?php
declare(strict_types=1);

/** Zamjenjiv kanal slanja. Poslovna logika ne zna za dobavljača. */
interface Channel
{
    /** Vraća oznaku dobavljača (id poruke) ili baca izuzetak. */
    public function send(string $to, ?string $subject, string $body, ?string $attachment): string;
}

/** Generički HTTP SMS/Viber/WhatsApp adapter (JSON POST). Konkretan dobavljač se podešava u config.php. */
final class HttpChannel implements Channel
{
    public function __construct(private array $c) {}
    public function send(string $to, ?string $subject, string $body, ?string $attachment): string
    {
        $payload = json_encode([$this->c['to_field'] ?? 'to' => $to, $this->c['text_field'] ?? 'text' => $body, 'from' => $this->c['from'] ?? 'Arena'], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAuthorization: " . ($this->c['auth_header'] ?? '') . "\r\n", 'content' => $payload]]);
        $res = @file_get_contents($this->c['url'], false, $ctx);
        $hdr = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        $code = isset($hdr[0]) && preg_match('/\s(\d{3})\s/', $hdr[0], $m) ? (int) $m[1] : 0;
        if ($res === false || $code < 200 || $code >= 300) throw new RuntimeException('HTTP ' . $code . ': ' . substr((string) $res, 0, 200));
        return substr((string) $res, 0, 180);
    }
}

/** Minimalni SMTP klijent (SSL/STARTTLS, AUTH LOGIN, PDF prilog). */
final class SmtpChannel implements Channel
{
    public function __construct(private array $c) {}
    private function cmd($fp, ?string $line, array $ok): string
    {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $resp = '';
        while (($l = fgets($fp, 1024)) !== false) { $resp .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
        if (!in_array((int) substr($resp, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($resp));
        return $resp;
    }
    public function send(string $to, ?string $subject, string $body, ?string $attachment): string
    {
        $host = $this->c['host']; $port = (int) ($this->c['port'] ?? 465);
        $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 20);
        if (!$fp) throw new RuntimeException("SMTP veza: $es");
        stream_set_timeout($fp, 20);
        $this->cmd($fp, null, [220]);
        $this->cmd($fp, 'EHLO arenabrcko.com', [250]);
        if ($port !== 465) { $this->cmd($fp, 'STARTTLS', [220]); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $this->cmd($fp, 'EHLO arenabrcko.com', [250]); }
        $this->cmd($fp, 'AUTH LOGIN', [334]);
        $this->cmd($fp, base64_encode($this->c['user']), [334]);
        $this->cmd($fp, base64_encode($this->c['pass']), [235]);
        $from = $this->c['from'] ?? $this->c['user'];
        $this->cmd($fp, "MAIL FROM:<$from>", [250]);
        $this->cmd($fp, "RCPT TO:<$to>", [250, 251]);
        $this->cmd($fp, 'DATA', [354]);
        $b = 'b' . bin2hex(random_bytes(8));
        $msg = "From: Arena Bakarni <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject ?? '') . "?=\r\nMIME-Version: 1.0\r\nDate: " . date(DATE_RFC2822) . "\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
        $msg .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n";
        if ($attachment && is_file($attachment)) $msg .= "--$b\r\nContent-Type: application/pdf; name=\"" . basename($attachment) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . basename($attachment) . "\"\r\n\r\n" . chunk_split(base64_encode((string) file_get_contents($attachment))) . "\r\n";
        $msg .= "--$b--\r\n";
        $msg = preg_replace('/^\./m', '..', $msg);
        $r = $this->cmd($fp, $msg . "\r\n.", [250]);
        $this->cmd($fp, 'QUIT', [221]);
        fclose($fp);
        return trim(substr($r, 4, 150));
    }
}

final class Notify
{
    public const TYPES = [
        'reservation_created' => 'Nova rezervacija', 'reservation_changed' => 'Izmjena rezervacije', 'reservation_cancelled' => 'Otkazivanje (gost)',
        'reservation_cancelled_arena' => 'Otkazivanje od strane Arene', 'monthly_request' => 'Zahtjev za mjesečnu', 'monthly_activated' => 'Aktivirana mjesečna',
        'monthly_expiring' => 'Mjesečna ističe za 3 dana', 'monthly_expired' => 'Mjesečna istekla', 'series_request' => 'Zahtjev za stalni termin',
        'series_decided' => 'Odluka o stalnom terminu', 'series_conflict' => 'Konflikt stalnog termina', 'payment_corrected' => 'Ispravka naplate',
        'debt_forgiven' => 'Oprost duga', 'unprocessed' => 'Neobrađen termin (1 h nakon kraja)',
    ];
    /** Tipovi koji se uvijek šalju gostu (ako ima telefon). */
    private const TO_GUEST = ['reservation_created', 'reservation_changed', 'reservation_cancelled', 'reservation_cancelled_arena', 'monthly_activated', 'monthly_expiring', 'monthly_expired', 'series_decided'];

    public static function channel(string $name): ?Channel
    {
        $c = Config::get('channels.' . $name);
        if (!$c || empty($c['adapter'])) return null;
        return match ($c['adapter']) { 'smtp' => new SmtpChannel($c), 'http' => new HttpChannel($c), default => null };
    }

    public static function direct(string $type, string $channel, string $to, string $body, string $idemBase, ?string $subject = null, ?string $attachment = null): int
    {
        Db::q('INSERT IGNORE INTO outbox (idem_key, channel, to_addr, type, subject, body, attachment_path, status, next_attempt_utc, created_utc) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$idemBase . ':' . $to, $channel, $to, $type, $subject, $body, $attachment, 'pending', Clock::nowUtc(), Clock::nowUtc()]);
        return (int) Db::val('SELECT id FROM outbox WHERE idem_key = ?', [$idemBase . ':' . $to]);
    }

    /** Odmah pokušaj slanja jedne poruke (verifikacioni kodovi ne mogu čekati cron). Vraća stvarni status. */
    public static function sendNow(int $id): string
    {
        $m = Db::one("SELECT * FROM outbox WHERE id = ? AND status = 'pending'", [$id]);
        if ($m) self::process($m);
        return (string) Db::val('SELECT status FROM outbox WHERE id = ?', [$id]);
    }

    private static function resText(array $r): string
    {
        $lanes = array_column(Db::all('SELECT lane_id FROM reservation_lanes WHERE reservation_id = ? ORDER BY lane_id', [$r['id']]), 'lane_id');
        return T::humanDate($r['start_at']) . ' ' . T::hm($r['start_at']) . '–' . T::hm($r['end_at']) . ', staza ' . implode(', ', $lanes);
    }

    public static function event(string $type, array $d): void
    {
        $guestBody = null; $staffBody = null; $guest = null; $key = $type;
        $r = isset($d['reservation_id']) ? Db::one('SELECT * FROM reservations WHERE id = ?', [$d['reservation_id']]) : null;
        if ($r && $r['user_id']) $guest = Auth::userById((int) $r['user_id']);
        if (isset($d['user_id'])) $guest = Auth::userById((int) $d['user_id']);
        $name = $guest ? trim($guest['first_name'] . ' ' . $guest['last_name']) : '';
        switch ($type) {
            case 'reservation_created':
                $guestBody = 'Arena Bakarni: rezervacija potvrđena — ' . self::resText($r) . '.';
                $staffBody = "Nova rezervacija ($name, " . Cat::label($r['category']) . '): ' . self::resText($r);
                $key .= ':' . $r['id']; break;
            case 'reservation_changed':
                $guestBody = 'Arena Bakarni: rezervacija izmijenjena — ' . self::resText($r) . '.';
                $staffBody = "Izmjena rezervacije ($name): " . self::resText($r);
                $key .= ':' . $r['id'] . ':' . $r['updated_utc']; break;
            case 'reservation_cancelled':
                $guestBody = 'Arena Bakarni: rezervacija otkazana — ' . self::resText($r) . '.';
                $staffBody = "Otkazano ($name" . ($r['cancel_kind'] === 'guest_late' ? ', KASNO' : '') . '): ' . self::resText($r);
                $key .= ':' . $r['id']; break;
            case 'reservation_cancelled_arena':
                $b = (int) ($d['bonus_minutes'] ?? 0);
                $guestBody = 'Arena Bakarni: nažalost moramo otkazati vaš termin ' . self::resText($r) . '. Razlog: ' . $r['cancel_reason'] . '.' . ($b ? ' Dodijeljeno vam je ' . Statements::hours($b) . ' bonus sati.' : '') . ' Izvinjavamo se.';
                $staffBody = "Arena otkazala termin ($name): " . self::resText($r) . ($b ? ', bonus ' . Statements::hours($b) : '');
                $key .= ':' . $r['id']; break;
            case 'monthly_activated':
                $p = Db::one('SELECT * FROM monthly_passes WHERE id = ?', [$d['pass_id']]);
                $guestBody = 'Arena Bakarni: mjesečna aktivirana do ' . T::humanDate(T::addDays($p['end_date'], -1)) . ' (' . Statements::hours((int) $p['weekly_minutes']) . ' sedmično).';
                $key .= ':' . $d['pass_id']; break;
            case 'monthly_expiring':
                $guestBody = 'Arena Bakarni: vaša mjesečna ističe ' . T::humanDate($d['end_date']) . '. Produženje možete zatražiti u nalogu (/nalog).';
                $staffBody = "Mjesečna ističe za 3 dana: $name";
                $key .= ':' . $d['pass_id']; break;
            case 'monthly_expired':
                $guestBody = 'Arena Bakarni: vaša mjesečna je istekla. Produženje zatražite u nalogu (/nalog) ili na šanku.';
                $staffBody = "Mjesečna istekla: $name";
                $key .= ':' . $d['pass_id']; break;
            case 'monthly_request':
                $staffBody = "Zahtjev za mjesečnu: $name (" . Phone::pretty($guest['phone']) . ')';
                $key .= ':' . $d['request_id']; break;
            case 'series_request':
                $s = Series::get((int) $d['series_id']);
                $staffBody = 'Zahtjev za stalni termin: ' . $s['club_name'] . ', ' . ['', 'pon', 'uto', 'sri', 'čet', 'pet', 'sub', 'ned'][$s['weekday']] . ' ' . $s['start_time'];
                $key .= ':' . $d['series_id']; break;
            case 'series_decided':
                $s = Series::get((int) $d['series_id']);
                if ($s['requested_by']) $guest = Auth::userById((int) $s['requested_by']);
                $guestBody = 'Arena Bakarni: zahtjev za stalni termin (' . $s['start_time'] . ') je ' . ($d['approved'] ? 'odobren.' : 'odbijen.');
                $key .= ':' . $d['series_id']; break;
            case 'series_conflict':
                $staffBody = 'Stalni termin #' . $d['series_id'] . ' nije upisan za ' . T::humanDate($d['date']) . ' (nema slobodnih staza).';
                $key .= ':' . $d['series_id'] . ':' . $d['date']; break;
            case 'payment_corrected':
                $staffBody = 'Ispravka naplate #' . $d['payment_id'] . ': ' . Money::fmt((int) $d['old_cents']) . ' → ' . ($d['new_cents'] === null ? 'storno' : Money::fmt((int) $d['new_cents'])) . '. Razlog: ' . $d['reason'] . '. Autor: ' . $d['author'];
                $key .= ':' . $d['payment_id']; break;
            case 'debt_forgiven':
                $staffBody = "Oprošten dug $name: " . Money::fmt((int) $d['amount_cents']) . '. Razlog: ' . $d['reason'] . '. Autor: ' . $d['author'];
                $key .= ':' . $d['debt_id']; break;
            case 'unprocessed':
                $staffBody = 'Neobrađen termin: ' . self::resText($r) . ($name ? " ($name)" : '') . ' — nije evidentiran ishod/pokriće.';
                $key .= ':' . $r['id']; break;
            default: return;
        }
        $guestChannel = Settings::get('guest_channel', 'sms');
        if ($guestBody && $guest && $guest['phone'] && in_array($type, self::TO_GUEST, true)) self::direct($type, $guestChannel, $guest['phone'], $guestBody, $key . ':g');
        if ($staffBody) {
            foreach (Db::all('SELECT * FROM notification_recipients WHERE active = 1') as $rc) {
                $types = json_decode($rc['types'], true) ?: [];
                if (!in_array($type, $types, true)) continue;
                $to = $rc['channel'] === 'email' ? $rc['email'] : $rc['phone'];
                if ($to) self::direct($type, $rc['channel'], $to, $staffBody, $key . ':r' . $rc['id'], 'Arena Bakarni — ' . (self::TYPES[$type] ?? $type));
            }
        }
    }

    /** Obrada reda: ponovni pokušaji s rastućim razmakom; nikad dvostruko slanje (claim + idem_key). */
    public static function work(int $limit = 50): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'not_configured' => 0];
        Db::q("UPDATE outbox SET status = 'failed', last_error = 'Nepoznat ishod (prekid tokom slanja) — provjeriti ručno, ne šalje se ponovo automatski' WHERE status = 'sending' AND next_attempt_utc < ?", [Clock::utcPlus('-15 minutes')]);
        $rows = Db::all("SELECT * FROM outbox WHERE status = 'pending' AND next_attempt_utc <= ? ORDER BY id LIMIT " . (int) $limit, [Clock::nowUtc()]);
        foreach ($rows as $m) { $r = self::process($m); if ($r) $stats[$r]++; }
        return $stats;
    }

    /** Jedna poruka: claim (pending → sending) pa slanje. Vraća sent | failed | not_configured | null (preuzeo drugi proces). */
    private static function process(array $m): ?string
    {
        $ch = self::channel($m['channel']);
        if (!$ch) { Db::update('outbox', ['status' => 'not_configured', 'last_error' => 'Kanal „' . $m['channel'] . '“ nije povezan — poruka NIJE poslana'], 'id = ? AND status = ?', [$m['id'], 'pending']); return 'not_configured'; }
        if (!Db::q("UPDATE outbox SET status = 'sending', next_attempt_utc = ? WHERE id = ? AND status = 'pending'", [Clock::nowUtc(), $m['id']])->rowCount()) return null;
        try {
            $ref = $ch->send($m['to_addr'], $m['subject'], $m['body'], $m['attachment_path']);
            Db::update('outbox', ['status' => 'sent', 'sent_utc' => Clock::nowUtc(), 'provider_ref' => $ref, 'attempts' => (int) $m['attempts'] + 1], 'id = ?', [$m['id']]);
            return 'sent';
        } catch (Throwable $e) {
            $att = (int) $m['attempts'] + 1;
            $delays = [60, 300, 900, 3600, 10800, 21600];
            $final = $att >= count($delays);
            Db::update('outbox', ['status' => $final ? 'failed' : 'pending', 'attempts' => $att, 'last_error' => substr($e->getMessage(), 0, 500), 'next_attempt_utc' => Clock::utcPlus('+' . ($delays[$att - 1] ?? 21600) . ' seconds')], 'id = ?', [$m['id']]);
            return 'failed';
        }
    }

    public static function requeue(int $id): void
    {
        Db::q("UPDATE outbox SET status = 'pending', next_attempt_utc = ?, last_error = NULL WHERE id = ? AND status IN ('not_configured','failed')", [Clock::nowUtc(), $id]);
    }
}

final class Jobs
{
    public static function unprocessedReminders(): int
    {
        $n = 0;
        foreach (Db::all("SELECT id FROM reservations WHERE status = 'active' AND processed_utc IS NULL AND category IN ('recreation','monthly','birthday') AND end_at <= ? AND end_at >= ?", [T::addMin(Clock::nowLocal(), -60), T::fmt(Clock::now()->modify('-3 days'))]) as $r) {
            Notify::event('unprocessed', ['reservation_id' => (int) $r['id']]); $n++;
        }
        return $n;
    }
    public static function monthlyReminders(): int
    {
        $n = 0;
        $today = Clock::today();
        // ističe za 3 dana: posljednji dan važenja = end_date - 1
        foreach (Db::all("SELECT * FROM monthly_passes WHERE status = 'active' AND end_date = ?", [T::addDays($today, 3)]) as $p) {
            if (Db::val('SELECT id FROM monthly_passes WHERE user_id = ? AND start_date > ?', [$p['user_id'], $p['start_date']])) continue;
            Notify::event('monthly_expiring', ['user_id' => (int) $p['user_id'], 'pass_id' => (int) $p['id'], 'end_date' => T::addDays($p['end_date'], -1)]); $n++;
        }
        foreach (Db::all("SELECT * FROM monthly_passes WHERE status = 'active' AND end_date <= ?", [$today]) as $p) {
            Db::update('monthly_passes', ['status' => 'ended', 'ended_reason' => 'Istekla'], 'id = ?', [$p['id']]);
            if (!Db::val('SELECT id FROM monthly_passes WHERE user_id = ? AND start_date >= ? AND id <> ?', [$p['user_id'], $p['end_date'], $p['id']])) {
                Notify::event('monthly_expired', ['user_id' => (int) $p['user_id'], 'pass_id' => (int) $p['id']]); $n++;
            }
        }
        return $n;
    }
    public static function all(): array
    {
        $out = [];
        $out['club_processed'] = Reservations::autoProcessClub();
        $out['series'] = Series::materialize(T::addDays(Clock::today(), (int) Settings::get('series_window_days', '56')));
        $out['unprocessed'] = self::unprocessedReminders();
        $out['monthly'] = self::monthlyReminders();
        $out['outbox'] = Notify::work();
        return $out;
    }

    /**
     * Jedan prolaz pozadinskih poslova (CLI cron ili web-cron preko wget-a). Najviše jednom u 60 s;
     * svi poslovi su idempotentni, pa prerano pokretanje ne pravi štetu. Backup jednom dnevno poslije 03:00.
     */
    public static function run(string $via): array
    {
        $root = dirname(__DIR__, 2);
        $lock = fopen(Config::dataDir() . '/cron.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => 'već radi'];
        $last = Settings::get('last_cron_utc');
        if ($last && strtotime($last . ' UTC') > strtotime(Clock::nowUtc() . ' UTC') - 60) return ['skipped' => 'pokrenuto prije manje od minute'];
        Settings::set('last_cron_utc', Clock::nowUtc());
        Settings::set('last_cron_via', $via);
        try {
            $out = self::all();
            if ((int) Clock::now()->format('G') >= 3 && Settings::get('last_backup_day') !== Clock::today()) {
                require_once $root . '/tools/backup-lib.php';
                $f = Backup::dump(Config::dataDir() . '/backup');
                Settings::set('last_backup_day', Clock::today());
                Backup::prune(Config::dataDir() . '/backup', 30);
                $out['backup'] = basename($f);
            }
            Settings::set('last_cron_error', '');
            return $out;
        } catch (Throwable $e) {
            Settings::set('last_cron_error', Clock::nowUtc() . ' ' . substr($e->getMessage(), 0, 300));
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
        }
    }
}
