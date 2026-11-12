<?php
// Pozadinski poslovi. Cron svakih 5 minuta:  php /putanja/arena-rezervacije/cron.php all
// Backup jednom dnevno (automatski u "all" poslije 03:00):  php cron.php backup
require __DIR__ . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$cmd = $argv[1] ?? 'all';
$lock = fopen(__DIR__ . '/storage/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("Već radi.\n");
if ($cmd === 'all') {
    echo json_encode(Jobs::all(), JSON_UNESCAPED_UNICODE) . "\n";
    if ((int) Clock::now()->format('G') >= 3 && Settings::get('last_backup_day') !== Clock::today()) $cmd = 'backup';
}
if ($cmd === 'backup') {
    require __DIR__ . '/tools/backup-lib.php';
    $f = Backup::dump(__DIR__ . '/storage/backup');
    Settings::set('last_backup_day', Clock::today());
    Backup::prune(__DIR__ . '/storage/backup', 30);
    echo "Backup: $f\n";
}
