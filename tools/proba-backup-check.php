<?php
declare(strict_types=1);
/**
 * Provjera backupa probnog okruženja (pravi backup na serveru, preuzima ga i VRAĆA u odvojeno lokalno okruženje).
 *   php tools/proba-backup-check.php            — napravi novi backup na serveru, preuzmi ga i provjeri
 *   php tools/proba-backup-check.php --samo-provjera <datoteka.sqlite.gz>
 * Prijava: vlasnički probni nalog iz PROBA-NALOZI.txt (lozinka se ne ispisuje).
 * Rezultat: storage/proba-backups/<backup>.sqlite.gz + izvještaj; produkcija/proba se NE mijenjaju.
 */
$root = dirname(__DIR__);
$base = 'https://proba.brckoworldcup.com/rezervacije/api/';
$dir = "$root/storage/proba-backups";
@mkdir($dir, 0770, true);

if (($argv[1] ?? '') === '--samo-provjera') {
    $file = $argv[2] ?? '';
} else {
    $t = (string) @file_get_contents("$root/PROBA-NALOZI.txt");
    if (!preg_match('/owner\s+prijava: (\S+)\s+lozinka: (\S+)/', $t, $m)) exit("Nema vlasničkog probnog naloga u PROBA-NALOZI.txt\n");
    $cookie = '';
    $req = function (string $method, string $path, ?array $body = null, bool $raw = false) use ($base, &$cookie) {
        $h = "X-RS: 1\r\nContent-Type: application/json\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '');
        $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60, 'header' => $h, 'content' => $body ? json_encode($body) : '']]);
        $r = file_get_contents($base . $path, false, $ctx);
        foreach (http_get_last_response_headers() ?? [] as $hh) if (preg_match('/^Set-Cookie: (rs_sess=[^;]+)/i', $hh, $mm)) $cookie = $mm[1];
        return $raw ? $r : json_decode((string) $r, true);
    };
    $l = $req('POST', 'auth/login', ['login' => $m[1], 'password' => $m[2]]);
    if (empty($l['ok'])) exit('Prijava nije uspjela: ' . ($l['error'] ?? '?') . "\n");
    $run = $req('POST', 'backup/run', []);
    if (empty($run['ok'])) exit('Backup na serveru nije uspio: ' . ($run['error'] ?? '?') . "\n");
    echo "Backup na serveru: {$run['data']['file']} ({$run['data']['bytes']} B)\n";
    $list = $req('GET', 'backup/list');
    echo "Backupi na serveru (" . count($list['data']) . "): " . implode(', ', array_slice(array_column($list['data'], 'file'), 0, 5)) . "\n";
    $bin = $req('GET', 'backup/download?file=' . urlencode($run['data']['file']), null, true);
    $file = "$dir/" . $run['data']['file'];
    file_put_contents($file, $bin);
    echo "Preuzeto: storage/proba-backups/" . basename($file) . ' (' . filesize($file) . " B)\n";
}

if (!is_file($file)) exit("Datoteka ne postoji: $file\n");
// 1) raspakivanje u ODVOJENO okruženje (nova datoteka, ne dira ništa postojeće)
$restored = "$dir/vraceno-" . date('Ymd-His') . '.sqlite';
$data = @gzdecode((string) file_get_contents($file));
if ($data === false || !str_starts_with($data, 'SQLite format 3')) exit("GREŠKA: backup nije ispravna SQLite gzip datoteka\n");
file_put_contents($restored, $data);
$pdo = new PDO('sqlite:' . $restored, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ic = $pdo->query('PRAGMA integrity_check')->fetchColumn();
echo "integrity_check: $ic\n";
$mig = $pdo->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
echo 'Migracije: ' . implode(', ', $mig) . "\n";
$counts = [];
foreach (['users', 'reservations', 'lane_slots', 'payments', 'monthly_passes', 'club_statements', 'events', 'outbox', 'audit_log'] as $t) $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
echo 'Redova: ' . json_encode($counts) . "\n";
$pdo = null;
// 2) aplikacija radi nad vraćenom bazom (zaseban proces, zasebna konfiguracija)
$probe = <<<'PHP'
require $argv[1] . '/app/bootstrap.php';
Config::load(['env' => 'dev', 'db' => ['dsn' => 'sqlite:' . $argv[2], 'user' => null, 'pass' => null], 'data_dir' => sys_get_temp_dir() . '/arena-restore-probe', 'channels' => []]);
$d = Calendar::day(Clock::today(), 'staff');
$t = DayClose::totals(Clock::today());
echo 'Aplikacija nad vraćenom bazom: kalendar ' . count($d['blocks']) . ' blokova danas, dnevni promet ' . Money::fmt($t['total']) . ', korisnika ' . Db::val('SELECT COUNT(*) FROM users') . "\n";
PHP;
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($restored) . ' 2>&1');
echo trim((string) $out) . "\n";
$ok = $ic === 'ok' && str_contains((string) $out, 'Aplikacija nad vraćenom bazom');
echo $ok ? "OPORAVAK PROVJEREN — vraćena kopija: storage/proba-backups/" . basename($restored) . "\n" : "OPORAVAK NIJE PROVJEREN\n";
exit($ok ? 0 : 1);
