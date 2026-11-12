<?php
// SAMO probno okruženje: test nalozi i primjeri. Odbija rad ako env = prod.
// Test prijave (lokalno): vlasnik / proba-vlasnik-1 · sank / proba-sank-1 · menadzer / proba-menadzer-1
// Gost: 061 100 100 / proba-gost-1 · član KK Rad: 061 200 200 / proba-clan-1
require __DIR__ . '/../app/bootstrap.php';
if (Config::get('env') === 'prod') exit("Ne u produkciji.\n");
$mk = function (array $u, string $pw, ?string $role = null) {
    $ex = $u['username'] ?? null ? Db::one('SELECT id FROM users WHERE username = ?', [$u['username']]) : Auth::userByPhone($u['phone']);
    if ($ex) return (int) $ex['id'];
    $id = Db::insert('users', $u + ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'phone_verified_utc' => Clock::nowUtc(), 'created_utc' => Clock::nowUtc()]);
    if ($role && isset(Perms::DEFAULTS[$role])) Perms::setFor($id, Perms::DEFAULTS[$role]);
    return $id;
};
$o = $mk(['first_name' => 'Vlasnik', 'last_name' => 'Proba', 'username' => 'vlasnik', 'phone' => '+38766900001', 'role' => 'owner'], 'proba-vlasnik-1');
$mk(['first_name' => 'Šank', 'last_name' => 'Arena', 'username' => 'sank', 'role' => 'staff'], 'proba-sank-1', 'staff');
$mk(['first_name' => 'Menadžer', 'last_name' => 'Proba', 'username' => 'menadzer', 'role' => 'manager'], 'proba-menadzer-1', 'manager');
$g = $mk(['first_name' => 'Gost', 'last_name' => 'Probni', 'phone' => '+38761100100', 'email' => 'gost@example.test', 'role' => 'guest'], 'proba-gost-1');
$rad = (int) Db::val("SELECT id FROM clubs WHERE short = 'Rad'");
$c = $mk(['first_name' => 'Član', 'last_name' => 'Rada', 'phone' => '+38761200200', 'email' => 'clan@example.test', 'role' => 'guest', 'club_id' => $rad], 'proba-clan-1');
echo "Seed gotov (vlasnik #$o, gost #$g, član #$c).\n";
