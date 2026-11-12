<?php
declare(strict_types=1);

/** Greška koja se prikazuje korisniku (poruka na lokalnom jeziku). */
final class AppError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400, public array $data = [])
    {
        parent::__construct($message);
    }
}

final class Config
{
    private static ?array $cfg = null;
    public static function load(?array $override = null): void
    {
        if ($override !== null) { self::$cfg = $override; return; }
        $root = dirname(__DIR__, 2);
        foreach ([$root . '/config.php', $root . '/config.local.php'] as $f) {
            if (is_file($f)) { self::$cfg = require $f; return; }
        }
        throw new RuntimeException('Nema config.php');
    }
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cfg === null) self::load();
        $v = self::$cfg;
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }
    public static function all(): array { if (self::$cfg === null) self::load(); return self::$cfg; }
    /** Folder za podatke koji moraju preživjeti ponovnu objavu (baza, backup, PDF, lock). */
    public static function dataDir(): string
    {
        $d = (string) self::get('data_dir', dirname(__DIR__, 2) . '/storage');
        if (!is_dir($d)) @mkdir($d, 0770, true);
        return $d;
    }
}

/**
 * Pristup bazi. Produkcija: MySQL/MariaDB (InnoDB). Privremeno/probno: SQLite (jedna datoteka, bez servera baze).
 * Za SQLite se MySQL-specifični dijelovi upita prevode u q(); upisi se serijalizuju sa BEGIN IMMEDIATE.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;
    private static string $driver = 'mysql';

    public static function connect(array $c): void
    {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        self::$driver = str_starts_with($c['dsn'], 'sqlite:') ? 'sqlite' : 'mysql';
        if (self::$driver === 'sqlite') {
            $file = substr($c['dsn'], 7);
            if ($file !== ':memory:' && !is_dir(dirname($file))) @mkdir(dirname($file), 0770, true);
            $pdo = method_exists(PDO::class, 'connect') ? PDO::connect($c['dsn'], null, null, $opts) : new PDO($c['dsn'], null, null, $opts);
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $fns = [
                'CONCAT' => [fn(...$a) => implode('', array_map(fn($x) => (string) $x, $a)), -1],
                'FIELD' => [function ($v, ...$list) { $i = array_search($v, $list, false); return $i === false ? 0 : $i + 1; }, -1],
                'UTC_TIMESTAMP' => [fn() => gmdate('Y-m-d H:i:s'), 0],
            ];
            foreach ($fns as $name => [$fn, $n]) {
                if (class_exists('Pdo\\Sqlite') && $pdo instanceof \Pdo\Sqlite) $pdo->createFunction($name, $fn, $n);
                else $pdo->sqliteCreateFunction($name, $fn, $n);
            }
        } else {
            $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], $opts + [PDO::ATTR_EMULATE_PREPARES => false]);
            $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $pdo->exec("SET time_zone = '+00:00'");
        }
        self::$pdo = $pdo;
        self::$depth = 0;
    }
    public static function pdo(): PDO
    {
        if (self::$pdo === null) self::connect(Config::get('db'));
        return self::$pdo;
    }
    public static function driver(): string { self::pdo(); return self::$driver; }
    public static function isSqlite(): bool { return self::driver() === 'sqlite'; }

    /** MySQL → SQLite prevod onoga što aplikacija koristi. */
    private static function tr(string $sql): string
    {
        static $cache = [];
        if (isset($cache[$sql])) return $cache[$sql];
        $t = preg_replace('/\bINSERT IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        $t = preg_replace('/\s+FOR UPDATE\b/i', '', $t);
        $t = preg_replace('/GROUP_CONCAT\(([^()]+?)\s+ORDER BY[^()]*\)/i', 'GROUP_CONCAT($1)', $t);
        $t = preg_replace("/LIKE '((?:[^'\\\\]|\\\\.)*\\\\[_%](?:[^'\\\\]|\\\\.)*)'/", "LIKE '$1' ESCAPE '\\\\'", $t);
        return $cache[$sql] = $t;
    }
    public static function q(string $sql, array $p = []): PDOStatement
    {
        if (self::isSqlite()) $sql = self::tr($sql);
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }
    public static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function val(string $sql, array $p = []): mixed { $r = self::q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }
    public static function update(string $table, array $set, string $where, array $p = []): int
    {
        $parts = [];
        foreach ($set as $k => $_) $parts[] = "$k = ?";
        return self::q('UPDATE ' . $table . ' SET ' . implode(',', $parts) . ' WHERE ' . $where, array_merge(array_values($set), $p))->rowCount();
    }
    public static function inTx(): bool { return self::$depth > 0; }
    public static function tableExists(string $t): bool
    {
        if (self::isSqlite()) return (bool) self::val("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
        return (bool) self::pdo()->query('SHOW TABLES LIKE ' . self::pdo()->quote($t))->fetch();
    }
    /** Folder s migracijama za aktivnu bazu. */
    public static function migrationsDir(string $root): string { return $root . '/migrations' . (self::isSqlite() ? '/sqlite' : ''); }

    public static function isDuplicate(Throwable $e): bool
    {
        if (!$e instanceof PDOException) return false;
        $code = (int) ($e->errorInfo[1] ?? 0);
        return $code === 1062 || ($code === 19 && stripos($e->getMessage(), 'UNIQUE') !== false) || ($code === 19 && stripos($e->getMessage(), 'PRIMARY') !== false);
    }
    private static function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) return false;
        $code = (string) ($e->errorInfo[1] ?? '');
        return in_array($code, ['1213', '1205'], true) || (self::$driver === 'sqlite' && in_array($code, ['5', '6'], true));
    }

    /** Transakcija s ponovnim pokušajem kod deadlocka/zauzete baze. Ugniježđeni pozivi rade u istoj transakciji. */
    public static function tx(callable $fn): mixed
    {
        if (self::$depth > 0) return $fn();
        $pdo = self::pdo();
        for ($attempt = 1; ; $attempt++) {
            if (self::$driver === 'sqlite') $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction();
            self::$depth = 1;
            try {
                $r = $fn();
                if (self::$driver === 'sqlite') $pdo->exec('COMMIT'); else $pdo->commit();
                self::$depth = 0;
                return $r;
            } catch (Throwable $e) {
                if (self::$driver === 'sqlite') { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
                elseif ($pdo->inTransaction()) $pdo->rollBack();
                self::$depth = 0;
                if (self::isRetryable($e) && $attempt < 3) { usleep(50000 * $attempt); continue; }
                if (self::isDuplicate($e) && str_contains($e->getMessage(), 'lane_slots')) {
                    throw new AppError('Termin je upravo zauzet. Osvježite kalendar i izaberite drugi termin.', 409);
                }
                throw $e;
            }
        }
    }

    /** Serijalizuje sve upise zauzetosti za jedan dan (zaštita od istovremenog duplog rezervisanja). SQLite: cijela baza je već zaključana (BEGIN IMMEDIATE). */
    public static function lockDay(string $day): void
    {
        self::q('INSERT IGNORE INTO day_locks (day) VALUES (?)', [$day]);
        if (!self::isSqlite()) self::q('SELECT day FROM day_locks WHERE day = ? FOR UPDATE', [$day]);
    }
}

/** Sat sistema. U testovima se može zamrznuti. Lokalno vrijeme = Europe/Sarajevo. */
final class Clock
{
    public static ?DateTimeImmutable $fixed = null;
    public static function tz(): DateTimeZone { static $tz = null; return $tz ??= new DateTimeZone('Europe/Sarajevo'); }
    public static function now(): DateTimeImmutable { return self::$fixed ?? new DateTimeImmutable('now', self::tz()); }
    public static function nowLocal(): string { return self::now()->format('Y-m-d H:i:s'); }
    public static function today(): string { return self::now()->format('Y-m-d'); }
    public static function nowUtc(): string { return self::now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    public static function utcPlus(string $mod): string { return self::now()->setTimezone(new DateTimeZone('UTC'))->modify($mod)->format('Y-m-d H:i:s'); }
    public static function set(string $local): void { self::$fixed = new DateTimeImmutable($local, self::tz()); }
}

final class T
{
    public static function dt(string $s): DateTimeImmutable { return new DateTimeImmutable($s, Clock::tz()); }
    public static function fmt(DateTimeImmutable $d): string { return $d->format('Y-m-d H:i:s'); }
    public static function addMin(string $s, int $m): string { return self::fmt(self::dt($s)->modify(($m >= 0 ? '+' : '') . $m . ' minutes')); }
    public static function minutes(string $a, string $b): int { return intdiv(self::dt($b)->getTimestamp() - self::dt($a)->getTimestamp(), 60); }
    public static function day(string $s): string { return substr($s, 0, 10); }
    public static function hm(string $s): string { return substr($s, 11, 5); }
    public static function weekStart(string $date): string
    {
        $d = self::dt(substr($date, 0, 10) . ' 12:00:00');
        $n = (int) $d->format('N');
        return $d->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
    }
    public static function weekday(string $date): int { return (int) self::dt(substr($date, 0, 10) . ' 12:00:00')->format('N'); }
    public static function addDays(string $date, int $n): string { return self::dt(substr($date, 0, 10) . ' 12:00:00')->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d'); }
    public static function addMonth(string $date): string
    {
        // 31.1. + 1 mjesec = 28/29.2. (bez prelivanja u mart)
        $d = self::dt($date . ' 12:00:00');
        $y = (int) $d->format('Y'); $m = (int) $d->format('n') + 1; $day = (int) $d->format('j');
        if ($m > 12) { $m = 1; $y++; }
        $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
        return sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
    }
    /** Blokovi od 15 min u [start, end). */
    public static function slots(string $start, string $end): array
    {
        $out = []; $t = self::dt($start); $e = self::dt($end);
        while ($t < $e) { $out[] = self::fmt($t); $t = $t->modify('+15 minutes'); }
        return $out;
    }
    public static function validDate(string $d): bool { $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, Clock::tz()); return $x !== false && $x->format('Y-m-d') === $d; }
    public static function parseLocal(string $s): string
    {
        $s = str_replace('T', ' ', trim($s));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s) || !self::validDate(substr($s, 0, 10))) throw new AppError('Neispravan datum ili vrijeme.');
        return $s;
    }
    public static function human(string $s): string { $d = self::dt($s); return $d->format('d.m.Y.') . ' ' . $d->format('H:i'); }
    public static function humanDate(string $s): string { return self::dt(substr($s, 0, 10) . ' 12:00')->format('d.m.Y.'); }
}

final class Money
{
    public static function fmt(int $cents): string
    {
        $neg = $cents < 0; $c = abs($cents);
        return ($neg ? '−' : '') . number_format(intdiv($c, 100), 0, ',', '.') . ',' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT) . ' KM';
    }
    /** "22,50" / "22.50" / "22" → 2250 (bez float-a). */
    public static function parse(string|int $v): int
    {
        if (is_int($v)) return $v * 100;
        $s = str_replace([' ', 'KM', 'km'], '', trim($v));
        if (!preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $s, $m)) throw new AppError('Neispravan iznos.');
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}

final class Phone
{
    /** Normalizacija u E.164; podrazumijevana zemlja BiH (+387). */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) return null;
        $s = trim($raw);
        if ($s === '') return null;
        $plus = str_starts_with($s, '+');
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return null;
        if ($plus) { /* već međunarodni */ }
        elseif (str_starts_with($d, '00')) $d = substr($d, 2);
        elseif (str_starts_with($d, '0')) $d = '387' . substr($d, 1);
        elseif (str_starts_with($d, '387') && strlen($d) >= 11) { /* 38766... */ }
        elseif (strlen($d) >= 8 && strlen($d) <= 9) $d = '387' . $d;
        if (strlen($d) < 8 || strlen($d) > 15) return null;
        return '+' . $d;
    }
    public static function require(?string $raw): string
    {
        $p = self::normalize($raw);
        if ($p === null) throw new AppError('Neispravan broj telefona.');
        return $p;
    }
    public static function pretty(?string $e164): string
    {
        if (!$e164) return '';
        if (preg_match('/^\+387(\d{2})(\d{3})(\d{3,4})$/', $e164, $m)) return "+387 {$m[1]} {$m[2]} {$m[3]}";
        return $e164;
    }
}

final class Settings
{
    public static function get(string $k, ?string $default = null): ?string { $v = Db::val('SELECT v FROM settings WHERE k = ?', [$k]); return $v === null ? $default : (string) $v; }
    public static function set(string $k, string $v): void { Db::q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, $v]); }
}

final class Audit
{
    public static ?array $actor = null; // ['id' => , 'label' => ]
    public static function log(string $action, string $entity, string|int|null $id, array $data = [], ?string $reason = null): void
    {
        $a = self::$actor ?? ['id' => null, 'label' => 'Sistem'];
        Db::insert('audit_log', [
            'at_utc' => Clock::nowUtc(), 'user_id' => $a['id'], 'actor_label' => $a['label'],
            'action' => $action, 'entity' => $entity, 'entity_id' => $id === null ? null : (string) $id,
            'data_json' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'reason' => $reason,
        ]);
    }
}

final class Cat
{
    public const ALL = [
        'monthly' => ['Mjesečne', '#2e7d32'],
        'training' => ['Trening', '#1565c0'],
        'match_rad' => ['Utakmica KK Rad', '#c62828'],
        'match_olimp' => ['Utakmica ŽKK Olimp', '#6a1b9a'],
        'match_zrinski' => ['Utakmica KK Zrinski', '#00838f'],
        'match_other' => ['Utakmica', '#5d4037'],
        'recreation' => ['Rekreacija', '#ef6c00'],
        'birthday' => ['Rođendan', '#d81b60'],
        'tournament' => ['Turnir', '#283593'],
        'qualification' => ['Kvalifikacije', '#827717'],
    ];
    public static function isMatch(string $c): bool { return str_starts_with($c, 'match_'); }
    public static function isClub(string $c): bool { return $c === 'training' || self::isMatch($c); }
    public static function isPersonal(string $c): bool { return in_array($c, ['monthly', 'recreation', 'birthday'], true); }
    public static function label(string $c): string { return self::ALL[$c][0] ?? $c; }
}

function str_req(array $in, string $k, int $max = 160): string
{
    $v = trim((string) ($in[$k] ?? ''));
    if ($v === '') throw new AppError('Polje je obavezno: ' . $k);
    if (mb_strlen($v) > $max) throw new AppError('Predugačak unos: ' . $k);
    return $v;
}
function str_opt(array $in, string $k, int $max = 255): ?string
{
    $v = trim((string) ($in[$k] ?? ''));
    if ($v === '') return null;
    if (mb_strlen($v) > $max) throw new AppError('Predugačak unos: ' . $k);
    return $v;
}
function int_req(array $in, string $k): int
{
    if (!isset($in[$k]) || !preg_match('/^-?\d+$/', (string) $in[$k])) throw new AppError('Neispravan broj: ' . $k);
    return (int) $in[$k];
}
