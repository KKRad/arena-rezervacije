<?php
declare(strict_types=1);

/**
 * Samo za probno okruženje bez SSH-a (config 'auto_setup' => true): primjenjuje migracije
 * i jednom upisuje probne naloge iz storage/proba-seed.json (u datoteci su samo hash-evi lozinki).
 */
final class Setup
{
    public static function ensure(): void
    {
        if (Config::get('env') === 'prod') return;
        $pdo = Db::pdo();
        $root = dirname(__DIR__, 2);
        $has = Db::tableExists('schema_migrations');
        $done = $has ? array_column(Db::all('SELECT name FROM schema_migrations'), 'name') : [];
        $files = glob(Db::migrationsDir($root) . '/*.sql'); sort($files);
        foreach ($files as $f) {
            if (in_array(basename($f), $done, true)) continue;
            $lock = fopen(Config::dataDir() . '/setup.lock', 'c');
            if (!flock($lock, LOCK_EX)) return;
            $done = Db::tableExists('schema_migrations') ? array_column(Db::all('SELECT name FROM schema_migrations'), 'name') : [];
            if (!in_array(basename($f), $done, true)) {
                $pdo->exec((string) file_get_contents($f));
                Db::insert('schema_migrations', ['name' => basename($f), 'applied_utc' => Clock::nowUtc()]);
            }
            flock($lock, LOCK_UN);
        }
        $seed = $root . '/storage/proba-seed.json';
        if (is_file($seed) && Settings::get('proba_seeded') === null) {
            $s = json_decode((string) file_get_contents($seed), true) ?: [];
            foreach ($s['users'] ?? [] as $u) {
                if (($u['username'] ?? null) && Db::val('SELECT id FROM users WHERE username = ?', [$u['username']])) continue;
                if (($u['phone'] ?? null) && Db::val('SELECT id FROM users WHERE phone = ?', [$u['phone']])) continue;
                $club = isset($u['club']) ? Db::val('SELECT id FROM clubs WHERE short = ?', [$u['club']]) : null;
                $id = Db::insert('users', ['first_name' => $u['first_name'], 'last_name' => $u['last_name'] ?? '', 'username' => $u['username'] ?? null, 'phone' => $u['phone'] ?? null,
                    'email' => $u['email'] ?? null, 'password_hash' => $u['hash'], 'phone_verified_utc' => Clock::nowUtc(), 'role' => $u['role'], 'club_id' => $club, 'created_utc' => Clock::nowUtc()]);
                if (isset(Perms::DEFAULTS[$u['role']])) Perms::setFor($id, Perms::DEFAULTS[$u['role']]);
            }
            Settings::set('proba_seeded', Clock::nowUtc());
            @unlink($seed);
        }
    }
}
