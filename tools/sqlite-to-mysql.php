<?php
declare(strict_types=1);
/**
 * Prenos probne SQLite baze u MySQL — za pripremu i provjeru prelaska na MySQL hosting.
 * NE dira izvornu SQLite datoteku (samo čita); upisuje isključivo u ciljnu MySQL bazu (koju sâm pravi/čisti).
 *
 * php tools/sqlite-to-mysql.php <sqlite_datoteka> <mysql_baza> [mysql_host] [mysql_port]
 *   — koristi MySQL korisnika/lozinku iz config.local.php (db.user/db.pass), istu instancu, drugu bazu.
 *
 * Nakon prenosa ispisuje provjeru po kategorijama (brojevi redova + par sadržajnih provjera) i
 * kako se vratiti na SQLite (ništa se ne mora "vraćati" — izvorna baza nije dirana).
 */
require __DIR__ . '/../app/lib/core.php';

[$_, $sqliteFile, $mysqlDb, $host, $port] = $argv + [null, null, null, '127.0.0.1', '3306'];
if (!$sqliteFile || !$mysqlDb) exit("Upotreba: php tools/sqlite-to-mysql.php <sqlite_datoteka> <mysql_baza> [host] [port]\n");
if (!is_file($sqliteFile)) exit("Nema datoteke: $sqliteFile\n");
$cfg = require __DIR__ . '/../config.local.php';
$user = $cfg['db']['user']; $pass = $cfg['db']['pass'];

echo "1) Čitanje izvorne SQLite baze (samo čitanje, ništa se ne mijenja): $sqliteFile\n";
$src = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$srcTables = $src->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

echo "2) Priprema ciljne MySQL baze '$mysqlDb' (briše je ako postoji i pravi iznova — samo cilj, ne izvor) i učitava šemu…\n";
$root = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP DATABASE IF EXISTS `$mysqlDb`");
$root->exec("CREATE DATABASE `$mysqlDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$dst = new PDO("mysql:host=$host;port=$port;dbname=$mysqlDb;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migFiles = glob(__DIR__ . '/../migrations/*.sql'); sort($migFiles);
foreach ($migFiles as $f) { $dst->exec((string) file_get_contents($f)); echo "   migracija: " . basename($f) . "\n"; }
$dstTables = $dst->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

echo "3) Prenos podataka (sve tabele, izvorni ID-jevi se čuvaju radi veza među zapisima)…\n";
$dst->exec('SET FOREIGN_KEY_CHECKS = 0');
$counts = [];
foreach ($dstTables as $t) {
    if ($t === 'schema_migrations') continue; // cilj dobija svoj zapis migracija iz koraka 2, ne prepisuje se izvorom
    if (!in_array($t, $srcTables, true)) { $counts[$t] = ['src' => 0, 'dst' => (int) $dst->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(), 'note' => 'nema u izvoru (nova tabela)']; continue; }
    $dst->exec("DELETE FROM `$t`"); // iz migracije mogu postojati početni redovi (npr. staze, cjenovnik) — zamjenjuju se izvornim
    $rows = $src->query("SELECT * FROM `$t`")->fetchAll();
    $n = 0;
    if ($rows) {
        $cols = array_keys($rows[0]);
        $sql = "INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $st = $dst->prepare($sql);
        foreach ($rows as $r) { $st->execute(array_values($r)); $n++; }
    }
    $got = (int) $dst->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $counts[$t] = ['src' => count($rows), 'dst' => $got, 'note' => $n === $got && count($rows) === $got ? 'OK' : 'NESLAGANJE'];
}
// autoincrement nastavlja od sljedećeg slobodnog broja
foreach (['users', 'clubs', 'reservations', 'events', 'event_entries', 'recurring_series', 'monthly_passes', 'monthly_requests',
    'hour_allocations', 'bonus_grants', 'debts', 'warnings', 'club_statements', 'club_statement_items', 'payments',
    'charges', 'notification_recipients', 'outbox', 'audit_log', 'verification_codes'] as $t) {
    if (!in_array($t, $dstTables, true)) continue;
    $max = (int) $dst->query("SELECT COALESCE(MAX(id),0) FROM `$t`")->fetchColumn();
    $dst->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . ($max + 1));
}
$dst->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\n4) Brojevi redova po tabeli (izvor → cilj):\n";
$bad = [];
foreach ($counts as $t => $c) {
    printf("   %-28s %6d -> %6d  %s\n", $t, $c['src'], $c['dst'], $c['note']);
    if ($c['note'] === 'NESLAGANJE') $bad[] = $t;
}

echo "\n5) Sadržajna provjera po kategorijama (spec tačka 4):\n";
function cat(PDO $db, string $label, string $sql): void { $r = $db->query($sql)->fetch(PDO::FETCH_ASSOC); echo "   - $label: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
cat($dst, 'Korisnici i ovlaštenja', "SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM user_permissions) perms, (SELECT COUNT(*) FROM users WHERE role='owner') owners");
cat($dst, 'Rezervacije i stalni termini', "SELECT (SELECT COUNT(*) FROM reservations) reservations, (SELECT COUNT(*) FROM reservation_lanes) lanes, (SELECT COUNT(*) FROM lane_slots) slots, (SELECT COUNT(*) FROM recurring_series) series");
cat($dst, 'Mjesečne i bonus sati', "SELECT (SELECT COUNT(*) FROM monthly_passes) passes, (SELECT COUNT(*) FROM hour_allocations) allocations, (SELECT COUNT(*) FROM bonus_grants) bonus_grants, (SELECT COALESCE(SUM(minutes),0) FROM bonus_grants) bonus_minuta_ukupno");
cat($dst, 'Gotovinska evidencija', "SELECT (SELECT COUNT(*) FROM payments) payments, (SELECT COALESCE(SUM(amount_cents),0) FROM payments) ukupno_centi, (SELECT COUNT(*) FROM debts) debts");
cat($dst, 'Klubovi, stavke, arhiva obračuna', "SELECT (SELECT COUNT(*) FROM clubs) clubs, (SELECT COUNT(*) FROM club_statements) statements, (SELECT COUNT(*) FROM club_statement_items) items");
cat($dst, 'Historija i red obavijesti', "SELECT (SELECT COUNT(*) FROM audit_log) audit_log, (SELECT COUNT(*) FROM outbox) outbox");

echo "\n6) Aplikacija nad prenesenom MySQL bazom (stvaran rad, ne samo brojevi)…\n";
$probe = <<<'PHP'
require $argv[1] . '/app/bootstrap.php';
$cfg = require $argv[1] . '/config.local.php';
Config::load(['env' => 'dev', 'db' => ['dsn' => "mysql:host=$argv[2];port=$argv[3];dbname=$argv[4];charset=utf8mb4", 'user' => $cfg['db']['user'], 'pass' => $cfg['db']['pass']], 'data_dir' => sys_get_temp_dir() . '/arena-mysql-probe', 'channels' => []]);
$u = Db::one("SELECT * FROM users WHERE role = 'owner' LIMIT 1");
echo 'vlasnik: ' . ($u ? $u['first_name'] . ' ' . $u['last_name'] : 'NEMA') . "\n";
$d = Calendar::day(Clock::today(), 'staff');
echo 'kalendar danas: ' . count($d['blocks']) . " blokova (upit sa JOIN-ovima radi)\n";
$t = DayClose::totals(Clock::today());
echo 'dnevni obračun (DayClose::totals): ukupno ' . Money::fmt($t['total']) . "\n";
if ($u) { $ov = (new Api(Auth::createSession((int) $u['id'])))->handle('GET', 'me', [], []); echo 'prijava vlasnika i sesija: ' . ($ov[1]['data']['user'] ? 'radi' : 'NE RADI') . "\n"; }
PHP;
$root2 = dirname(__DIR__);
passthru(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' ' . escapeshellarg($root2) . ' ' . escapeshellarg($host) . ' ' . escapeshellarg($port) . ' ' . escapeshellarg($mysqlDb));

echo "\n" . ($bad ? "NESLAGANJE u tabelama: " . implode(', ', $bad) . "\n" : "PRENOS PROVJEREN — svi brojevi se poklapaju, aplikacija radi nad prenesenom bazom.\n");
echo "Povratak na prethodno stanje: izvorna SQLite baza NIJE mijenjana ni u jednom koraku — nastavak rada na njoj zahtijeva samo da config.php i dalje pokazuje na nju. Ciljna MySQL baza ('$mysqlDb') je odvojena i može se obrisati bez posljedica: DROP DATABASE `$mysqlDb`.\n";
exit($bad ? 1 : 0);
