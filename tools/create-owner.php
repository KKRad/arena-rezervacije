<?php
// Pravi nalog vlasnika. Lozinku upisuje vlasnik sam u terminalu (ne prikazuje se).
// php tools/create-owner.php
require __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
$ask = function (string $q, bool $hidden = false): string {
    echo $q;
    if ($hidden) system('stty -echo');
    $v = trim((string) fgets(STDIN));
    if ($hidden) { system('stty echo'); echo "\n"; }
    return $v;
};
$first = $ask('Ime: '); $last = $ask('Prezime: ');
$phone = Phone::require($ask('Telefon: '));
$user = mb_strtolower($ask('Korisničko ime (npr. vlasnik): '));
$p1 = $ask('Lozinka (min. 8): ', true); $p2 = $ask('Ponovi lozinku: ', true);
if ($p1 !== $p2) exit("Lozinke se ne poklapaju.\n");
Auth::validatePassword($p1);
$ex = Auth::userByPhone($phone);
if ($ex) { Db::update('users', ['role' => 'owner', 'username' => $user, 'password_hash' => password_hash($p1, PASSWORD_DEFAULT), 'phone_verified_utc' => Clock::nowUtc()], 'id = ?', [$ex['id']]); $id = (int) $ex['id']; }
else $id = Db::insert('users', ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'username' => $user, 'password_hash' => password_hash($p1, PASSWORD_DEFAULT), 'phone_verified_utc' => Clock::nowUtc(), 'role' => 'owner', 'created_utc' => Clock::nowUtc()]);
Audit::log('owner.created', 'user', $id);
echo "Vlasnik je spreman (id $id). Prijava: $user ili telefon.\n";
