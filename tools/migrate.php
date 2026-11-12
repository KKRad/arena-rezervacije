<?php
// Primjena migracija: php tools/migrate.php
require __DIR__ . '/../app/bootstrap.php';
$pdo = Db::pdo();
$has = Db::tableExists('schema_migrations');
$done = $has ? array_column(Db::all('SELECT name FROM schema_migrations'), 'name') : [];
foreach (glob(Db::migrationsDir(dirname(__DIR__)) . '/*.sql') as $f) {
    $n = basename($f);
    if (in_array($n, $done, true)) continue;
    echo "Primjenjujem $n … ";
    $pdo->exec(file_get_contents($f));
    Db::insert('schema_migrations', ['name' => $n, 'applied_utc' => Clock::nowUtc()]);
    echo "OK\n";
}
echo "Baza je ažurna.\n";
