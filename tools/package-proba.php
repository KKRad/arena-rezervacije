<?php
// Pravi build/proba/ za probnu poddomenu (kod u korijenu poddomene, zaštićen .htaccess-om).
// php tools/package-proba.php                     — privremena SQLite baza u ../proba-data/ (IZVAN foldera sajta: objava je ne briše)
// php tools/package-proba.php <db_name> <db_user>   — MySQL; lozinka baze se čita iz storage/proba-db-pass (nije u gitu)
// Lozinke probnih naloga: nasumične, zapisuju se SAMO lokalno u PROBA-NALOZI.txt (nije u gitu); na server idu samo hash-evi.
$root = dirname(__DIR__);
[$_, $dbName, $dbUser] = $argv + [null, null, null];
$sqlite = !$dbName;
$pass = $sqlite ? '' : trim((string) @file_get_contents("$root/storage/proba-db-pass"));
if (!$sqlite && (!$dbUser || $pass === '')) exit("Upotreba: php tools/package-proba.php [<db_name> <db_user>] (MySQL traži storage/proba-db-pass)\n");
$out = "$root/build/proba";
exec('rm -rf ' . escapeshellarg($out));
mkdir("$out/storage/backup", 0775, true); mkdir("$out/storage/pdf", 0775, true);
$copy = function (string $src, string $dst) use (&$copy) {
    if (is_dir($src)) { @mkdir($dst, 0775, true); foreach (scandir($src) as $f) if ($f[0] !== '.') $copy("$src/$f", "$dst/$f"); }
    else copy($src, $dst);
};
foreach (['app', 'migrations'] as $d) $copy("$root/$d", "$out/$d");
@mkdir("$out/migrations/sqlite"); foreach (glob("$root/migrations/sqlite/*.sql") as $f) copy($f, "$out/migrations/sqlite/" . basename($f));
$copy("$root/public/_rs", "$out/_rs");
@mkdir("$out/tools"); foreach (['backup-lib.php', 'restore-check.php', 'migrate.php'] as $f) copy("$root/tools/$f", "$out/tools/$f");
copy("$root/cron.php", "$out/cron.php");
@mkdir("$out/assets/img", 0775, true);
foreach (['logo-light.webp', 'favicon.png'] as $f) copy("$root/../arena-bakarni-site/assets/img/$f", "$out/assets/img/$f");
$dbExport = $sqlite ? "['dsn' => 'sqlite:' . dirname(__DIR__, 2) . '/proba-data/arena-rs.sqlite', 'user' => null, 'pass' => null]"
    : var_export(['dsn' => "mysql:host=127.0.0.1;port=3306;dbname=$dbName;charset=utf8mb4", 'user' => $dbUser, 'pass' => $pass], true);
file_put_contents("$out/config.php", "<?php\n// PROBNO okruženje (ne produkcija).\n// Ako postoji ../../proba-data/config.php (izvan foldera sajta, npr. s lozinkom MySQL baze), koristi se ta datoteka.\nif (is_file(\$ext = dirname(__DIR__, 2) . '/proba-data/config.php')) return require \$ext;\nreturn ['env' => 'dev', 'auto_setup' => true, 'show_dev_codes' => true,\n  'db' => $dbExport,\n  'data_dir' => dirname(__DIR__, 2) . '/proba-data',\n  'base_url' => 'https://proba.brckoworldcup.com', 'cookie_secure' => true, 'channels' => []];\n");
$htDeny = "Require all denied\n";
foreach (['app', 'migrations', 'tools', 'storage'] as $d) file_put_contents("$out/$d/.htaccess", $htDeny);
file_put_contents("$out/.htaccess", <<<HT
Options -Indexes
DirectoryIndex disabled
<FilesMatch "^(config\.php|cron\.php|.*\.md|.*\.sql|.*\.lock|.*\.json|.*\.sqlite.*)$">
  Require all denied
</FilesMatch>
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^(app|migrations|tools|storage)(/|$) - [F,L]
  RewriteRule ^$ /rezervacije/ [R=302,L]
  RewriteRule ^(rezervacije|nalog|administracija)(/.*)?$ _rs/index.php [L,QSA]
</IfModule>
<IfModule mod_headers.c>
  Header always set X-Robots-Tag "noindex, nofollow"
</IfModule>
HT);
file_put_contents("$out/robots.txt", "User-agent: *\nDisallow: /\n");
// probni nalozi
$gen = fn() => substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
$accounts = [
    ['first_name' => 'Vlasnik', 'last_name' => 'Proba', 'username' => 'vlasnik', 'phone' => '+38766900001', 'role' => 'owner'],
    ['first_name' => 'Menadžer', 'last_name' => 'Proba', 'username' => 'menadzer', 'role' => 'manager'],
    ['first_name' => 'Šank', 'last_name' => 'Arena', 'username' => 'sank', 'role' => 'staff'],
    ['first_name' => 'Gost', 'last_name' => 'Probni', 'phone' => '+38761100100', 'email' => 'gost@example.test', 'role' => 'guest'],
    ['first_name' => 'Član', 'last_name' => 'Rada', 'phone' => '+38761200200', 'email' => 'clan@example.test', 'role' => 'guest', 'club' => 'Rad'],
];
// Lozinke se prave samo jednom i čuvaju lokalno (storage/proba-seed.json + PROBA-NALOZI.txt), da nova objava ne promijeni prijave.

// Arhiva ove verzije za vraćanje (rollback): build/archive/proba-<datum>-<git>.zip
function proba_archive(string $root, string $out): void {
    $hash = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')) ?: 'nogit';
    $dirty = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null')) !== '' ? '-izmjene' : '';
    @mkdir("$root/build/archive", 0775, true);
    $zipPath = "$root/build/archive/proba-" . date('Ymd-His') . "-$hash$dirty.zip";
    $z = new ZipArchive(); $z->open($zipPath, ZipArchive::CREATE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) $z->addFile($f->getPathname(), substr($f->getPathname(), strlen($out) + 1));
    $z->close();
    echo "arhiva za vraćanje: build/archive/" . basename($zipPath) . "\n";
}
$keep = "$root/storage/proba-seed.json";
if (is_file($keep) && is_file("$root/PROBA-NALOZI.txt")) {
    copy($keep, "$out/storage/proba-seed.json");
    echo "build/proba spreman (postojeće probne lozinke iz PROBA-NALOZI.txt)\n";
    proba_archive($root, $out);
    exit;
}
$lines = ["PROBNI NALOZI — https://proba.brckoworldcup.com/nalog/  (samo probno okruženje; ne koristiti ove lozinke nigdje drugdje)", ''];
foreach ($accounts as &$a) {
    $pw = $gen();
    $a['hash'] = password_hash($pw, PASSWORD_DEFAULT);
    $lines[] = sprintf('%-10s prijava: %-16s lozinka: %s', $a['role'], $a['username'] ?? ('0' . substr($a['phone'], 4)), $pw);
}
file_put_contents("$out/storage/proba-seed.json", json_encode(['users' => $accounts], JSON_UNESCAPED_UNICODE));
copy("$out/storage/proba-seed.json", $keep);
file_put_contents("$root/PROBA-NALOZI.txt", implode("\n", $lines) . "\n");
echo "build/proba spreman; nalozi u PROBA-NALOZI.txt\n";
proba_archive($root, $out);
