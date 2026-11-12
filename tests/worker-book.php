<?php
declare(strict_types=1);
// Pomoćni proces za test istovremenog rezervisanja: php tests/worker-book.php <user_id> <start> <lane> <go_at_microtime>
// Ispisuje OK ili ERR:<poruka>. Baza: ista kao u tests/run.php (DB=sqlite → SQLite datoteka).
require __DIR__ . '/../app/bootstrap.php';
$sqlite = getenv('DB') === 'sqlite';
Config::load((function () use ($sqlite) { $c = require __DIR__ . '/../config.local.php'; $c['db'] = $sqlite ? ['dsn' => 'sqlite:' . (getenv('ARENA_SQLITE') ?: sys_get_temp_dir() . '/arena-rs-test.sqlite'), 'user' => null, 'pass' => null] : $c['db_test']; $c['env'] = 'test'; $c['channels'] = []; return $c; })());
Db::connect(Config::get('db'));
[$_, $uid, $start, $lane, $goAt] = $argv;
$u = Auth::userById((int) $uid);
Audit::$actor = Auth::actorFor($u);
while (microtime(true) < (float) $goAt) usleep(200); // svi procesi kreću u istom trenutku
try {
    Reservations::create($u, ['start' => $start, 'minutes' => 60, 'lanes' => [(int) $lane]]);
    echo 'OK';
} catch (AppError $e) {
    echo 'ERR:' . $e->getMessage();
} catch (Throwable $e) {
    echo 'EXC:' . get_class($e) . ' ' . $e->getMessage();
}
