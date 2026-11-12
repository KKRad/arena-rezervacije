<?php
// Provjerljiv oporavak: vraća najnoviji (ili zadani) backup u PROBNU bazu i poredi broj redova.
// php tools/restore-check.php [putanja.sql.gz]   — ciljna baza: config 'db_restore' (nikad produkciona)
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/backup-lib.php';
$file = $argv[1] ?? (function () { $f = array_merge(glob(__DIR__ . '/../storage/backup/arena-rs-*.sql.gz'), glob(__DIR__ . '/../storage/backup/arena-rs-*.sqlite.gz')); sort($f); return end($f); })();
if (!$file) exit("Nema backupa.\n");
if (str_ends_with($file, '.sqlite.gz')) {
    $res = Backup::restoreCheckSqlite($file);
    foreach ($res as $t => $r) printf("%-28s %6d / %6d %s\n", $t, $r['restored'], $r['expected'], $r['ok'] ? 'OK' : 'GREŠKA');
    $bad = array_filter($res, fn($r) => !$r['ok']);
    echo $bad ? "OPORAVAK NEUSPJEŠAN\n" : "Oporavak provjeren (SQLite): " . basename($file) . "\n";
    exit($bad ? 1 : 0);
}
$c = Config::get('db_restore') ?? Config::get('db_test');
if (!$c || $c['dsn'] === Config::get('db.dsn')) exit("Podesite 'db_restore' (zasebna probna baza).\n");
$pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$res = Backup::restoreCheck($file, $pdo);
$bad = array_filter($res, fn($r) => !$r['ok']);
foreach ($res as $t => $r) printf("%-28s %6d / %6d %s\n", $t, $r['restored'], $r['expected'], $r['ok'] ? 'OK' : 'GREŠKA');
echo $bad ? "OPORAVAK NEUSPJEŠAN\n" : "Oporavak provjeren: " . basename($file) . "\n";
exit($bad ? 1 : 0);
