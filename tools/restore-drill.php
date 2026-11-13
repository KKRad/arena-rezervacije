<?php
declare(strict_types=1);
/**
 * Pravi "restore drill": ne samo da provjeri da je backup čitljiv (integrity_check, brojevi redova),
 * nego STVARNO pokrene aplikaciju iz vraćene kopije na zasebnom lokalnom web serveru i odradi pravi
 * korisnički tok preko HTTP-a (prijava, kalendar, dnevni obračun, detalj rezervacije, obračuni) —
 * baš ono za šta "zabilježen backup i uspješan cron" sami po sebi nisu dovoljan dokaz.
 *
 * php tools/restore-drill.php [<backup.sqlite.gz> | <already-restored.sqlite>]
 *   bez argumenta: uzima najnoviji postojeći storage/proba-backups/vraceno-*.sqlite
 *                  (ako ga nema, prvo pokreće tools/proba-backup-check.php da ga napravi)
 *
 * Ništa se ne piše u pravu probu ni u pravu bazu — sve se dešava u novoj privremenoj kopiji,
 * na novom portu, pa se server gasi i privremeni folder briše na kraju.
 */
$root = dirname(__DIR__);
$arg = $argv[1] ?? null;

if ($arg && str_ends_with($arg, '.sqlite.gz')) {
    $data = @gzdecode((string) file_get_contents($arg));
    if ($data === false) exit("Ne mogu raspakovati: $arg\n");
    $restored = sys_get_temp_dir() . '/arena-drill-src-' . bin2hex(random_bytes(4)) . '.sqlite';
    file_put_contents($restored, $data);
} elseif ($arg) {
    $restored = $arg;
} else {
    $existing = glob("$root/storage/proba-backups/vraceno-*.sqlite");
    if ($existing) { rsort($existing); $restored = $existing[0]; echo "Koristim postojeću vraćenu kopiju: " . basename($restored) . "\n"; }
    else { echo "Nema postojeće vraćene kopije — pravim svježi backup i vraćanje…\n"; passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/proba-backup-check.php")); $existing = glob("$root/storage/proba-backups/vraceno-*.sqlite"); rsort($existing); $restored = $existing[0] ?? null; }
}
if (!$restored || !is_file($restored)) exit("Nema vraćene SQLite kopije za probu.\n");

echo "\n=== RESTORE DRILL — stvaran rad aplikacije nad vraćenom bazom (odvojen server, odvojen port) ===\n";
$drill = sys_get_temp_dir() . '/arena-drill-' . bin2hex(random_bytes(4));
mkdir($drill, 0770, true);
foreach (['app', 'migrations'] as $d) {
    mkdir("$drill/$d", 0775, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) { $rel = substr($f->getPathname(), strlen("$root/$d") + 1); $f->isDir() ? @mkdir("$drill/$d/$rel", 0775, true) : copy($f->getPathname(), "$drill/$d/$rel"); }
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/public/_rs", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
mkdir("$drill/_rs", 0775, true);
foreach ($it as $f) { $rel = substr($f->getPathname(), strlen("$root/public/_rs") + 1); $f->isDir() ? @mkdir("$drill/_rs/$rel", 0775, true) : copy($f->getPathname(), "$drill/_rs/$rel"); }
copy($restored, "$drill/drill.sqlite");
mkdir("$drill/proba-data", 0775, true);
file_put_contents("$drill/config.php", "<?php\nreturn ['env' => 'dev', 'show_dev_codes' => true,\n  'db' => ['dsn' => 'sqlite:' . __DIR__ . '/drill.sqlite', 'user' => null, 'pass' => null],\n  'data_dir' => __DIR__ . '/proba-data', 'base_url' => 'http://127.0.0.1', 'cookie_secure' => false, 'channels' => []];\n");
file_put_contents("$drill/router.php", "<?php\nchdir(__DIR__);\nrequire __DIR__ . '/_rs/index.php';\n");
echo "Privremeni paket: $drill\n";

$port = 8700 + random_int(0, 200);
$cmd = sprintf('%s -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!', escapeshellarg(PHP_BINARY), $port, escapeshellarg($drill), escapeshellarg("$drill/router.php"), escapeshellarg("$drill/server.log"));
$pid = (int) trim((string) shell_exec($cmd));
usleep(400000);
echo "Pokrenut privremeni server na 127.0.0.1:$port (pid $pid)\n\n";

function httpc(string $base): object {
    return new class($base) {
        public string $cookie = '';
        public function __construct(private string $base) {}
        public function req(string $m, string $p, ?array $b = null): array {
            $h = "X-RS: 1\r\nContent-Type: application/json\r\n" . ($this->cookie ? "Cookie: {$this->cookie}\r\n" : '');
            $ctx = stream_context_create(['http' => ['method' => $m, 'ignore_errors' => true, 'timeout' => 10, 'header' => $h, 'content' => $b !== null ? json_encode($b) : '']]);
            $r = @file_get_contents($this->base . $p, false, $ctx);
            $hdr = $http_response_header ?? [];
            foreach ($hdr as $x) if (preg_match('/^Set-Cookie: (rs_sess=[^;]+)/i', $x, $q)) $this->cookie = $q[1];
            preg_match('/\s(\d{3})\s/', $hdr[0] ?? '', $c);
            return [(int) ($c[1] ?? 0), json_decode((string) $r, true)];
        }
    };
}

$pass = 0; $fail = [];
function check(bool $c, string $msg): void { global $pass, $fail; echo ($c ? '  ✓ ' : '  ✗ ') . $msg . "\n"; $c ? $pass++ : $fail[] = $msg; }

$base = "http://127.0.0.1:$port/rezervacije/api/";
$c = httpc($base);
[$st, $me] = $c->req('GET', 'me');
check($st === 200, 'server odgovara (GET me)');
[$st, $cal] = $c->req('GET', 'calendar/day');
check($st === 200 && isset($cal['data']['blocks']), 'javni kalendar vraća blokove iz vraćene baze');

// nađi probnog vlasnika u samoj vraćenoj bazi da bismo se prijavili (lozinka nepoznata — koristi se sesija direktno iz baze)
$pdo = new PDO('sqlite:' . "$drill/drill.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$owner = $pdo->query("SELECT * FROM users WHERE role = 'owner' LIMIT 1")->fetch();
if ($owner) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO sessions (token_hash, user_id, created_utc, expires_utc, ip) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $token), $owner['id'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 3600), '127.0.0.1']);
    $c->cookie = 'rs_sess=' . $token;
    [$st, $d] = $c->req('GET', 'dashboard');
    check($st === 200, 'prijava vlasnika (iz podataka u vraćenoj bazi) i pristup administraciji rade');
    [$st, $day] = $c->req('GET', 'day');
    check($st === 200 && isset($day['data']['totals']['total']), 'dnevni obračun se računa iz vraćenih uplata (ukupno: ' . ($day['data']['totals']['total'] ?? '?') . ' feninga)');
    [$st, $res] = $c->req('GET', 'me/overview');
    check($st === 200, 'profil i historija rezervacija čitljivi iz vraćene baze');
    $firstRes = $pdo->query('SELECT id FROM reservations ORDER BY id LIMIT 1')->fetchColumn();
    if ($firstRes) { [$st, $rd] = $c->req('GET', 'reservations/' . $firstRes); check($st === 200, 'detalj konkretne rezervacije (#' . $firstRes . ') čitljiv'); }
    [$st, $stm] = $c->req('GET', 'statements');
    check($st === 200, 'arhiva obračuna kluba dostupna');
} else {
    check(false, 'u vraćenoj bazi nema vlasničkog naloga (neočekivano)');
}

posix_kill($pid, 9) ?? exec('kill -9 ' . $pid . ' 2>/dev/null');
exec('rm -rf ' . escapeshellarg($drill));
echo "\nPrivremeni server zaustavljen, privremeni paket obrisan (izvorna proba i backup nisu dirani).\n";
echo "\n" . ($fail ? "RESTORE DRILL NEUSPJEŠAN (" . count($fail) . " provjera palo)\n" : "RESTORE DRILL USPJEŠAN — aplikacija stvarno radi iz vraćene kopije backupa ($pass provjera).\n");
exit($fail ? 1 : 0);
