<?php
// Za hosting bez SSH: lokalno pravi SQL za nalog vlasnika (lozinka se upisuje skriveno, u SQL ide samo hash).
// php tools/owner-sql.php  → kopirati ispis u phpMyAdmin (SQL kartica) produkcione baze.
require __DIR__ . '/../app/lib/core.php';
$ask = function (string $q, bool $hidden = false): string { echo $q; if ($hidden) system('stty -echo'); $v = trim((string) fgets(STDIN)); if ($hidden) { system('stty echo'); echo "\n"; } return $v; };
$first = $ask('Ime: '); $last = $ask('Prezime: '); $phone = Phone::require($ask('Telefon: ')); $user = mb_strtolower($ask('Korisničko ime: '));
$p1 = $ask('Lozinka (min. 8): ', true); $p2 = $ask('Ponovi: ', true);
if ($p1 !== $p2 || mb_strlen($p1) < 8) exit("Lozinke se ne poklapaju ili su prekratke.\n");
$q = fn($s) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
echo "\nINSERT INTO users (first_name, last_name, phone, username, password_hash, phone_verified_utc, role, created_utc) VALUES ("
    . implode(', ', [$q($first), $q($last), $q($phone), $q($user), $q(password_hash($p1, PASSWORD_DEFAULT)), 'UTC_TIMESTAMP()', "'owner'", 'UTC_TIMESTAMP()']) . ");\n";
