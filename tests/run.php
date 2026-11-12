<?php
declare(strict_types=1);
// Pokretanje: php tests/run.php   (koristi bazu db_test iz config.local.php — briše je i pravi iznova)

require __DIR__ . '/../app/bootstrap.php';
Config::load((function () { $c = require __DIR__ . '/../config.local.php'; $c['db'] = $c['db_test']; $c['env'] = 'test'; $c['channels'] = []; return $c; })());
Db::connect(Config::get('db'));

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = [];
function ok(bool $c, string $msg): void { if ($c) $GLOBALS['pass']++; else { $GLOBALS['fail'][] = $msg; echo "  ✗ $msg\n"; } }
function eq(mixed $a, mixed $b, string $msg): void { ok($a === $b, $msg . ' (dobijeno: ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ', očekivano: ' . json_encode($b, JSON_UNESCAPED_UNICODE) . ')'); }
function throws(callable $f, string $msg, ?string $contains = null): ?AppError
{
    try { $f(); ok(false, $msg . ' — očekivana greška'); return null; }
    catch (AppError $e) { ok($contains === null || str_contains($e->getMessage(), $contains), $msg . ' — poruka: ' . $e->getMessage()); return $e; }
}
function reset_db(): void
{
    $pdo = Db::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/001_init.sql'));
}
function mkuser(string $first, string $phone, string $role = 'guest', ?int $club = null): array
{
    $id = Db::insert('users', ['first_name' => $first, 'last_name' => 'Test', 'phone' => Phone::normalize($phone), 'phone_verified_utc' => Clock::nowUtc(), 'password_hash' => password_hash('lozinka123', PASSWORD_DEFAULT), 'role' => $role, 'club_id' => $club, 'created_utc' => Clock::nowUtc()]);
    if (isset(Perms::DEFAULTS[$role])) Perms::setFor($id, Perms::DEFAULTS[$role]);
    return Auth::userById($id);
}
function as_(array $u): array { Audit::$actor = Auth::actorFor($u); return Auth::userById((int) $u['id']); }
function book(array $u, string $start, int $min = 60, array|int $lanes = 1, array $extra = []): array
{
    as_($u);
    return Reservations::create(Auth::userById((int) $u['id']), ['start' => $start, 'minutes' => $min] + (is_array($lanes) ? ['lanes' => $lanes] : ['lane_count' => $lanes]) + $extra);
}
function staffbook(array $s, array $in): array { as_($s); return Reservations::create(Auth::userById((int) $s['id']), $in + ['as_staff' => true]); }
function test(string $name, callable $f): void
{
    echo "• $name\n";
    reset_db();
    Clock::set('2026-11-16 09:00:00'); // ponedjeljak
    try { $f(); } catch (Throwable $e) { ok(false, "$name: neočekivana greška " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
}

// ---------------------------------------------------------------------------------------------
test('Telefoni i novac', function () {
    eq(Phone::normalize('066 213 121'), '+38766213121', '066 → +387');
    eq(Phone::normalize('+387 66 213-121'), '+38766213121', '+387 s razmacima');
    eq(Phone::normalize('0038766213121'), '+38766213121', '00387');
    eq(Phone::normalize('38766213121'), '+38766213121', '387 bez plusa');
    eq(Phone::normalize('abc'), null, 'neispravan');
    eq(Money::parse('22,50'), 2250, 'parse 22,50');
    eq(Money::fmt(2250), '22,50 KM', 'fmt');
    eq(T::addMonth('2026-01-31'), '2026-02-28', 'mjesec od 31.1.');
    eq(T::weekStart('2026-11-22'), '2026-11-16', 'sedmica pon–ned (nedjelja)');
});

test('Cijene rekreacije i patika', function () {
    $pv = Pricing::current();
    eq(Pricing::laneCash(30, $pv), 1500, '30 min = 15 KM');
    eq(Pricing::laneCash(60, $pv), 1500, '60 min = 15 KM');
    eq(Pricing::laneCash(90, $pv), 2250, '90 min = 22,50 KM');
    eq(Pricing::laneCash(120, $pv), 3000, '120 min = 30 KM');
    eq(Pricing::training(2, 90, $pv), 3000, 'trening 2 staze × 1,5 h = 30 KM');
    $g = mkuser('Gost', '061111111');
    $r = book($g, '2026-11-16 18:00', 90, 2);
    $s = mkuser('Sank', '060000000', 'staff');
    Clock::set('2026-11-16 19:30:00');
    as_($s);
    throws(fn() => Reservations::settle($s, $r['id'], []), 'naplata bez odgovora o patikama', 'patike');
    $rc = Reservations::settle($s, $r['id'], ['shoes_used' => true, 'shoe_pairs' => 3]);
    eq($rc['lane_cents'], 4500, 'dvije staze × 22,50 = 45 KM');
    eq($rc['shoes_cents'], 900, '3 para × 3 KM');
    eq($rc['total_cents'], 5400, 'ukupno 54 KM');
    throws(fn() => Reservations::settle($s, $r['id'], ['shoes_used' => false]), 'dvostruka naplata', 'zaključen');
    // kraće korištenje: 90 rezervisano, 30 korišteno → minimum 15 KM
    Clock::set('2026-11-16 09:00:00');
    $r2 = book($g, '2026-11-16 20:00', 90, 1);
    Clock::set('2026-11-16 20:40:00');
    eq(Reservations::settle($s, $r2['id'], ['shoes_used' => false, 'used_minutes' => 30])['lane_cents'], 1500, 'kraće korištenje 30 min → 15 KM minimum');
});

test('Pravila online rezervacije i konflikti', function () {
    $a = mkuser('Ana', '061000001'); $b = mkuser('Bojan', '061000002');
    throws(fn() => book($a, '2026-11-16 09:00'), 'prije 10:00', '10:00');
    throws(fn() => book($a, '2026-11-16 10:30'), 'nije puni sat', 'puni sat');
    throws(fn() => book($a, '2026-11-16 22:30', 60), 'poslije 23:00');
    throws(fn() => book($a, '2026-11-16 22:00', 45), 'trajanje 45');
    throws(fn() => book($a, '2026-11-16 08:00'), 'u prošlosti / prije 10');
    $r = book($a, '2026-11-16 18:00', 60, [3]);
    eq($r['lanes'], [3], 'konkretna staza');
    throws(fn() => book($b, '2026-11-16 18:00', 60, [3]), 'preklapanje iste staze', 'Zauzeto');
    throws(fn() => book($b, '2026-11-16 17:00', 120, [3]), 'djelimično preklapanje');
    $r2 = book($b, '2026-11-16 18:00', 60, 2);
    eq($r2['lanes'], [1, 2], 'auto-dodjela najnižih slobodnih');
    // više staza — sve ili ništa
    $before = (int) Db::val('SELECT COUNT(*) FROM lane_slots');
    throws(fn() => book($b, '2026-11-16 18:00', 60, [4, 3]), 'više staza, jedna zauzeta');
    eq((int) Db::val('SELECT COUNT(*) FROM lane_slots'), $before, 'nijedan blok nije upisan (transakcija)');
    throws(fn() => book($b, '2026-11-16 18:00', 60, 4), 'samo 3 slobodne', 'samo 3');
    // bez pauze
    $r3 = book($b, '2026-11-16 19:00', 60, [3]);
    ok($r3['id'] > 0, 'bez obavezne pauze između rezervacija');
    // fizička zaštita: jedinstveni indeks
    throws(fn() => Db::tx(fn() => Availability::occupy([3], '2026-11-16 18:30:00', '2026-11-16 18:45:00', 9999)), 'jedinstveni indeks lane_slots', 'zauzet');
    // blokiran nalog
    Db::update('users', ['blocked_utc' => Clock::nowUtc()], 'id = ?', [$a['id']]);
    throws(fn() => book($a, '2026-11-17 18:00'), 'blokiran nalog', 'blokiran');
    eq(Db::val('SELECT status FROM reservations WHERE id = ?', [$r['id']]), 'active', 'postojeća rezervacija blokiranog nije otkazana');
});

test('Istovremeni upis: zaključavanje dana (druga konekcija čeka)', function () {
    $c = Config::get('db');
    $other = new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    Db::pdo()->beginTransaction();
    Db::lockDay('2026-11-20');
    $blocked = false;
    try { $other->beginTransaction(); $other->exec("INSERT IGNORE INTO day_locks (day) VALUES ('2026-11-20')"); $other->query("SELECT day FROM day_locks WHERE day = '2026-11-20' FOR UPDATE")->fetchAll(); }
    catch (PDOException $e) { $blocked = str_contains($e->getMessage(), 'Lock wait timeout'); }
    if ($other->inTransaction()) $other->rollBack();
    Db::pdo()->rollBack();
    ok($blocked, 'druga transakcija ne može upisati isti dan dok prva traje');
});

test('Izmjena, produženje, otkazivanje', function () {
    $a = mkuser('Ana', '061000001'); $b = mkuser('Bojan', '061000002');
    $r = book($a, '2026-11-16 18:00', 60, [1]);
    book($b, '2026-11-16 20:00', 60, [1]);
    as_($a);
    $r = Reservations::change($a, $r['id'], ['start' => '2026-11-16 19:00', 'minutes' => 60]);
    eq($r['start_at'], '2026-11-16 19:00:00', 'pomjeranje');
    throws(fn() => Reservations::change($a, $r['id'], [], true) ?: null, 'produženje bez minuta');
    throws(fn() => Reservations::change($a, $r['id'], ['add_minutes' => 60], true), 'produženje u zauzeto', 'Zauzeto');
    $b2 = mkuser('Ceca', '061000003');
    $x = book($b2, '2026-11-16 15:00', 60, [2]);
    Clock::set('2026-11-16 15:30:00');
    as_($b2);
    $x = Reservations::change($b2, $x['id'], ['add_minutes' => 30], true);
    eq($x['end_at'], '2026-11-16 16:30:00', 'produženje tekućeg termina');
    throws(fn() => Reservations::change($b2, $x['id'], ['start' => '2026-11-16 17:00']), 'izmjena nakon početka (gost)', 'počeo');
    // otkazivanje: pravovremeno ≥ 2 h, kasno < 2 h
    Clock::set('2026-11-16 16:00:00');
    as_($a);
    eq(Reservations::cancel($a, $r['id'], 'guest')['cancel_kind'], 'guest', 'otkaz 3 h prije = pravovremeno');
    $y = book($a, '2026-11-16 17:00', 60, [3]);
    Clock::set('2026-11-16 15:30:00');
    eq(Reservations::cancel($a, $y['id'], 'guest')['cancel_kind'], 'guest_late', 'otkaz 1,5 h prije = kasno (samo evidencija)');
    eq((int) Db::val('SELECT COUNT(*) FROM lane_slots WHERE reservation_id = ?', [$y['id']]), 0, 'otkaz oslobađa staze');
    ok((bool) Db::one('SELECT id FROM reservations WHERE id = ?', [$y['id']]), 'zapis otkazane rezervacije ostaje');
    throws(fn() => Reservations::cancel($b, $x['id'], 'guest'), 'tuđa rezervacija', 'ovlaštenje');
});

test('Mjesečna: sedmični limit, buduće rezervacije, kombinovano pokriće', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $m = mkuser('Mirko', '062000001');
    Clock::set('2026-11-20 12:00:00'); // petak
    as_($s);
    $p = Coverage::activate((int) $m['id'], 'standard', null, $s);
    eq([$p['start_date'], $p['end_date'], (int) $p['weekly_minutes']], ['2026-11-20', '2026-12-20', 120], 'period od dana uplate, 2 h sedmično');
    eq((int) Db::val("SELECT amount_cents FROM payments WHERE kind = 'monthly'"), 5000, 'gotovinska uplata 50 KM');
    $r1 = book($m, '2026-11-21 18:00', 60, 1);
    eq($r1['category'], 'monthly', 'kategorija Mjesečne');
    $q = Reservations::quoteSettle($r1['id']);
    eq([$q['regular'], $q['cash_total']], [60, 0], '1 h iz mjesečne');
    $r2 = book($m, '2026-11-22 18:00', 90, 1);
    $q2 = Reservations::quoteSettle($r2['id']);
    eq([$q2['regular'], $q2['bonus'], $q2['cash_total']], [60, 0, 750], 'ostatak sedmice 1 h + 30 min gotovina 7,50');
    $r3 = book($m, '2026-11-22 20:00', 60, 1);
    eq(Reservations::quoteSettle($r3['id'])['cash_total'], 1500, 'limit iskorišten budućim rezervacijama → 15 KM');
    // nova sedmica = novi limit, bez prenosa
    $r4 = book($m, '2026-11-23 18:00', 120, 1);
    eq(Reservations::quoteSettle($r4['id'])['regular'], 120, 'ponedjeljak: novi puni limit');
    // mjesečna pokriva jednu stazu
    $r5 = book($m, '2026-11-25 18:00', 60, 2);
    $q5 = Reservations::quoteSettle($r5['id']);
    eq([$q5['regular'], $q5['cash_total']], [0, 3000], 'limit sedmice potrošen (r4) → obje staze gotovina');
    as_($m); Reservations::cancel($m, $r4['id'], 'guest');
    $q5 = Reservations::quoteSettle($r5['id']);
    eq([$q5['regular'], $q5['cash_total']], [60, 1500], 'otkaz vraća sate; mjesečna pokriva samo jednu od dvije staze');
    // rezervacija poslije isteka: pokriće pri dolasku
    $r6 = book($m, '2026-12-22 18:00', 60, 1);
    eq([$r6['category'], Reservations::quoteSettle($r6['id'])['cash_total']], ['recreation', 1500], 'poslije isteka: rekreacija dok se ne produži');
    Clock::set('2026-12-21 12:00:00');
    as_($s); Coverage::activate((int) $m['id'], 'standard', null, $s);
    eq(Db::val('SELECT category FROM reservations WHERE id = ?', [$r6['id']]), 'monthly', 'produženje pokriva buduću rezervaciju');
    eq(Db::val('SELECT start_date FROM monthly_passes ORDER BY id DESC LIMIT 1'), '2026-12-21', 'novi period od stvarnog dana uplate (bez retroaktivnosti)');
});

test('VIP mjesečna i ovlaštenja', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $mgr = mkuser('Menadzer', '060000001', 'manager');
    $v = mkuser('Vesna', '062000002');
    as_($s);
    throws(fn() => Coverage::activate((int) $v['id'], 'vip', 300, $s), 'šank ne može VIP', 'VIP');
    as_($mgr);
    $p = Coverage::activate((int) $v['id'], 'vip', 300, $mgr);
    eq((int) $p['weekly_minutes'], 300, 'VIP 5 h sedmično');
    $r = book($v, '2026-11-17 18:00', 120, 1); $r2 = book($v, '2026-11-18 18:00', 120, 1); $r3 = book($v, '2026-11-19 18:00', 120, 1);
    eq(Reservations::quoteSettle($r3['id'])['regular'], 60, 'VIP: treći termin dobija preostalih 60 min');
    // matrica ovlaštenja na serveru
    $api = new Api(Auth::createSession((int) $s['id']));
    eq($api->handle('GET', 'stats', [], [])[0], 403, 'šank nema statistiku');
    eq($api->handle('POST', 'prices', [], ['recreation_30' => '8', 'recreation_min' => '16', 'shoes' => '3', 'training_lane_hour' => '10', 'monthly' => '50'])[0], 403, 'šank ne mijenja cijene');
    eq((new Api(null))->handle('GET', 'dashboard', [], [])[0], 401, 'bez prijave nema admin podataka');
    $own = mkuser('Vlasnik', '060000009', 'owner');
    eq((new Api(Auth::createSession((int) $own['id'])))->handle('GET', 'stats', [], [])[0], 200, 'vlasnik vidi statistiku');
});

test('Bonus sati: otkaz Arene 2×, bez trostrukog odobravanja; nedolazak mjesečne', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $m = mkuser('Mirko', '062000001');
    as_($s); Coverage::activate((int) $m['id'], 'standard', null, $s);
    $r1 = book($m, '2026-11-17 18:00', 60, 1);
    as_($s);
    throws(fn() => Reservations::cancel($s, $r1['id'], 'arena'), 'otkaz Arene bez razloga', 'razlog');
    Reservations::cancel($s, $r1['id'], 'arena', 'Kvar staze');
    eq(Coverage::bonusBalance((int) $m['id']), 120, '1 otkazani sat → 2 bonus sata');
    eq(Coverage::regularUsed((int) Coverage::currentPass((int) $m['id'])['id'], '2026-11-16'), 60, 'otkazani redovni sat se NE vraća (nema trostrukog)');
    eq((int) Db::val('SELECT COUNT(*) FROM outbox WHERE type = ? AND to_addr = ?', ['reservation_cancelled_arena', $m['phone']]), 1, 'obavijest gostu o otkazu Arene (u redu)');
    // bonus se troši tek kad nema redovnih
    $r2 = book($m, '2026-11-18 18:00', 120, 1);
    $q = Reservations::quoteSettle($r2['id']);
    eq([$q['regular'], $q['bonus'], $q['cash_total']], [60, 60, 0], 'redovni 1 h pa bonus 1 h');
    eq(Coverage::bonusBalance((int) $m['id']), 60, 'planirani bonus smanjuje raspoloživo');
    // nedolazak: redovni propadaju, bonus se vraća, nema duga
    Clock::set('2026-11-18 18:15:00');
    Reservations::markNoShow($s, $r2['id'], true);
    eq(Coverage::bonusBalance((int) $m['id']), 120, 'bonus vraćen nakon nedolaska');
    eq(Coverage::regularUsed((int) Coverage::currentPass((int) $m['id'])['id'], '2026-11-16'), 120, 'redovni sati propali');
    eq((int) Db::val('SELECT COUNT(*) FROM debts WHERE user_id = ?', [$m['id']]), 0, 'mjesečna: nema duga za nedolazak');
    // 2 h otkazano → 4 h bonusa
    Clock::set('2026-11-23 09:00:00');
    $r3 = book($m, '2026-11-24 18:00', 120, 1);
    Reservations::cancel($s, $r3['id'], 'arena', 'Turnir');
    eq(Coverage::bonusBalance((int) $m['id']), 360, '2 otkazana sata → +4 h bonusa');
    eq(Coverage::compensateArena($r3['id'], 'ponovo'), 0, 'ponovna kompenzacija iste rezervacije nije moguća');
    // bonus važi i nakon isteka mjesečne
    Clock::set('2027-01-10 09:00:00');
    $r4 = book($m, '2027-01-11 18:00', 60, 1);
    eq([Reservations::quoteSettle($r4['id'])['bonus'], Reservations::quoteSettle($r4['id'])['cash_total']], [60, 0], 'bonus važi nakon isteka mjesečne');
});

test('Nedolazak rekreacije: upozorenje, dug, oprost', function () {
    $s = mkuser('Sank', '060000000', 'staff'); $mgr = mkuser('Menadzer', '060000001', 'manager');
    $g = mkuser('Goran', '063000001');
    $r1 = book($g, '2026-11-16 18:00', 60, 2);
    Clock::set('2026-11-16 18:05:00');
    as_($s);
    throws(fn() => Reservations::markNoShow($s, $r1['id'], true), 'nedolazak prije 10 min', '10 minuta');
    Clock::set('2026-11-16 18:11:00');
    $x = Reservations::markNoShow($s, $r1['id'], true);
    ok($x['no_show_result']['warning'] && !$x['no_show_result']['debt'], 'prvi nedolazak = upozorenje, bez duga');
    eq(Db::val('SELECT MIN(slot_start) FROM lane_slots WHERE reservation_id = ?', [$r1['id']]), null, 'staze oslobođene');
    eq(Db::val('SELECT attendance FROM reservations WHERE id = ?', [$r1['id']]), 'no_show', 'originalni zapis sačuvan');
    $n = mkuser('Novi', '063000002');
    $walk = book($n, '2026-11-17 18:00', 60, 1); // druga
    as_($s);
    $w = staffbook($s, ['start' => '2026-11-16 18:00', 'minutes' => 60, 'lanes' => [1], 'guest' => ['phone' => '065 999 999', 'first_name' => 'Šetač']]);
    ok($w['id'] > 0, 'oslobođena staza ponovo rezervisana (osoblje, gost bez naloga)');
    Clock::set('2026-11-16 19:00:00');
    $r2 = book($g, '2026-11-16 20:00', 90, 1);
    Clock::set('2026-11-16 20:15:00');
    as_($s);
    $x2 = Reservations::markNoShow($s, $r2['id'], false);
    eq($x2['no_show_result']['debt']['amount_cents'], 2250, 'drugi nedolazak = dug 22,50 KM');
    // dug ne blokira, upozorenje vide i gost i osoblje
    $r3 = book($g, '2026-11-18 18:00', 60, 1);
    ok($r3['id'] > 0, 'dug ne blokira novu rezervaciju');
    as_($s);
    $det = Reservations::detail($s, $r3['id']);
    ok((bool) array_filter($det['warnings'], fn($w) => $w['kind'] === 'debt'), 'osoblje vidi upozorenje o dugu');
    $q = (new Api(Auth::createSession((int) $g['id'])))->handle('POST', 'reservations/quote', [], ['start' => '2026-11-19 18:00', 'minutes' => 60, 'lane_count' => 1]);
    eq($q[1]['data']['debt_cents'], 2250, 'gost vidi dug prije potvrde');
    throws(fn() => Cash::forgiveDebt($s, (int) $x2['no_show_result']['debt']['id'], 'x'), 'šank ne oprašta dug', 'ovlaštenje');
    as_($mgr);
    Cash::forgiveDebt($mgr, (int) $x2['no_show_result']['debt']['id'], 'Stalni gost');
    eq(Db::val('SELECT status FROM debts WHERE id = ?', [$x2['no_show_result']['debt']['id']]), 'forgiven', 'oprošten dug');
    ok((bool) Db::val("SELECT id FROM audit_log WHERE action = 'debt.forgiven' AND actor_label LIKE 'Menadzer%'"), 'trag ko je oprostio');
    // naplata termina + stari dug kao odvojene stavke
    $d = Db::insert('debts', ['user_id' => $g['id'], 'amount_cents' => 1500, 'reason' => 'test', 'created_utc' => Clock::nowUtc()]);
    Clock::set('2026-11-18 19:00:00');
    as_($s);
    $rc = Reservations::settle($s, $r3['id'], ['shoes_used' => false, 'debt_ids' => [$d]]);
    eq([$rc['lane_cents'], $rc['debt_cents'], $rc['total_cents']], [1500, 1500, 3000], 'termin i dug odvojeno');
});

test('Ispravka naplate (storno) — historija se ne briše', function () {
    $s = mkuser('Sank', '060000000', 'staff'); $o = mkuser('Vlasnik', '060000009', 'owner');
    $g = mkuser('Goran', '063000001');
    $r = book($g, '2026-11-16 18:00', 60, 1);
    Clock::set('2026-11-16 19:00:00');
    as_($s); $rc = Reservations::settle($s, $r['id'], ['shoes_used' => false]);
    throws(fn() => Cash::correct($s, $rc['payment_ids'][0], 1000, 'greška'), 'šank ne ispravlja', 'ovlaštenje');
    as_($o); Cash::correct($o, $rc['payment_ids'][0], 1000, 'Pogrešan iznos');
    eq((int) Db::val('SELECT COUNT(*) FROM payments'), 3, 'original + storno + nova stavka');
    eq(DayClose::totals('2026-11-16')['total'], 1000, 'dnevni zbir nakon ispravke');
    throws(fn() => Cash::correct($o, $rc['payment_ids'][0], null, 'opet'), 'dupli storno', 'već');
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'payment_corrected'"), 0, 'bez primalaca nema poruke');
});

test('Klubovi: trening na račun kluba, utakmica, obračun', function () {
    $o = mkuser('Vlasnik', '060000009', 'owner');
    $rad = (int) Db::val("SELECT id FROM clubs WHERE short = 'Rad'");
    Db::update('clubs', ['email' => 'rad@example.test', 'match_price_mode' => 'fixed', 'match_fixed_cents' => 12000], 'id = ?', [$rad]);
    $p = mkuser('Igrač', '064000001', 'guest', $rad);
    $t = book($p, '2026-11-16 11:00', 120, 2);
    eq([$t['category'], (int) $t['club_amount_cents']], ['training', 4000], 'trening 2 staze × 2 h = 40 KM na račun kluba');
    $priv = book($p, '2026-11-17 18:00', 60, 1, ['personal' => true]);
    eq($priv['category'], 'recreation', '„Nije trening — plaćam lično“');
    $det = Reservations::detail(Auth::userById((int) $p['id']), $t['id']);
    ok(!isset($det['club_amount_cents']) && !isset($det['billing']), 'član ne vidi cijenu treninga ni obračun');
    $nm = mkuser('Nečlan', '064000002');
    throws(fn() => staffbook($o, ['start' => '2026-11-16 15:00', 'minutes' => 60, 'category' => 'training', 'user_id' => $nm['id']]), 'trening samo za članove', 'članove');
    $match = staffbook($o, ['start' => '2026-11-16 15:00', 'minutes' => 150, 'category' => 'match_rad', 'club_id' => $rad, 'match_home' => 'KK Rad', 'match_guest' => 'KK Gost', 'match_league' => 'Liga']);
    eq([$match['lanes'], (int) $match['club_amount_cents'], $match['end_at']], [[1, 2, 3, 4, 5, 6], 12000, '2026-11-16 17:30:00'], 'utakmica: 6 staza, 2,5 h, fiksno 120 KM');
    as_($o);
    Clock::set('2026-11-16 12:00:00');
    throws(fn() => Statements::create($o, $rad), 'u 12:00 trening 11–13 nije održan', 'Nema');
    Clock::set('2026-11-16 18:00:00');
    $single = Statements::create($o, $rad, $match['id']);
    eq([$single['kind'], (int) $single['total_cents'], count($single['items'])], ['single_match', 12000, 1], 'izdvojen obračun samo utakmice');
    ok(is_file($single['pdf_path']) && str_starts_with((string) file_get_contents($single['pdf_path']), '%PDF'), 'PDF napravljen');
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'club_statement' AND to_addr = 'rad@example.test' AND attachment_path IS NOT NULL"), 1, 'email s PDF prilogom u redu');
    $full = Statements::create($o, $rad);
    eq([count($full['items']), (int) $full['total_cents']], [1, 4000], 'puni obračun: samo trening (utakmica već obračunata)');
    throws(fn() => Statements::create($o, $rad), 'novi zbir počinje od nule', 'Nema');
    $dup = false;
    try { Db::insert('club_statement_items', ['statement_id' => $full['id'], 'reservation_id' => $t['id'], 'item_date' => '2026-11-16', 'description' => 'x', 'lane_count' => 1, 'minutes' => 60, 'unit_label' => 'x', 'amount_cents' => 1]); }
    catch (PDOException $e) { $dup = (int) $e->errorInfo[1] === 1062; }
    ok($dup, 'baza odbija dvostruki obračun iste stavke (UNIQUE)');
    eq(count(Statements::list($rad)), 2, 'arhiva obračuna');
    eq((bool) Db::val('SELECT processed_utc FROM reservations WHERE id = ?', [$t['id']]), false, 'trening ne treba ručnu obradu');
    Reservations::autoProcessClub();
    ok((bool) Db::val('SELECT processed_utc FROM reservations WHERE id = ?', [$t['id']]), 'održan trening automatski evidentiran');
});

test('Stalni klupski termini: pravilo, izuzeci, bez beskonačnih redova', function () {
    $o = mkuser('Vlasnik', '060000009', 'owner');
    $rad = (int) Db::val("SELECT id FROM clubs WHERE short = 'Rad'");
    $p = mkuser('Igrač', '064000001', 'guest', $rad);
    $g = mkuser('Gost', '061000001');
    as_($p);
    $s = Series::request(Auth::userById((int) $p['id']), ['weekday' => 2, 'start_time' => '19:00', 'minutes' => 120, 'lane_count' => 2, 'preferred_lanes' => [1, 2]]);
    eq($s['status'], 'pending', 'zahtjev čeka odobrenje');
    $early = book($g, '2026-11-17 19:00', 60, 6); // utorak — sve staze zauzete
    ok($early['id'] > 0, 'zahtjev ne čuva termin');
    $later = book($g, '2026-12-01 19:00', 60, [1]); // utorak 1.12. — samo staza 1
    as_($o);
    $e = throws(fn() => Series::approve($o, (int) $s['id'], []), 'odobrenje s konfliktom', 'konflikti');
    eq(array_column($e?->data['conflicts']['dates'] ?? [], 'date'), ['2026-11-17'], 'konflikt samo 17.11. (1.12. ima drugu slobodnu stazu)');
    Series::approve($o, (int) $s['id'], ['2026-11-17']);
    $n = (int) Db::val('SELECT COUNT(*) FROM reservations WHERE series_id = ?', [$s['id']]);
    ok($n >= 7 && $n <= 9, "upisano samo za prozor od 8 sedmica ($n), ne beskonačno");
    eq(array_map('intval', array_column(Db::all("SELECT l.lane_id FROM reservations r JOIN reservation_lanes l ON l.reservation_id = r.id WHERE r.series_id = ? AND r.occurrence_date = '2026-12-01' ORDER BY l.lane_id", [$s['id']]), 'lane_id')), [2, 3], 'zauzeta željena staza → druga slobodna');
    eq((int) Db::val('SELECT COUNT(*) FROM reservations WHERE id = ? AND status = \'active\'', [$early['id']]), 1, 'postojeća rezervacija nije prepisana');
    // daleka budućnost: virtualna pojava blokira
    Db::q('UPDATE reservations SET status = \'active\' WHERE 1 = 0');
    $far = '2027-06-01 19:00'; // utorak
    book($g, $far, 60, 4);
    throws(fn() => book($g, $far, 60, 1), 'virtualni stalni termin daleko unaprijed', 'stalni');
    ok(!Db::val("SELECT id FROM reservations WHERE series_id = ? AND occurrence_date = '2027-06-01'", [$s['id']]), 'daleka pojava nije upisana kao red');
    // ukidanje
    Series::end($o, (int) $s['id'], '2026-12-08');
    eq((int) Db::val("SELECT COUNT(*) FROM reservations WHERE series_id = ? AND occurrence_date >= '2026-12-08' AND status = 'active'", [$s['id']]), 0, 'ukinut: buduće pojave otkazane');
    $ok = book($g, $far, 60, 2);
    ok($ok['id'] > 0, 'nakon ukidanja termin je slobodan');
    // pravilo vs pravilo
    $s2 = Series::createByStaff($o, ['club_id' => $rad, 'weekday' => 3, 'start_time' => '18:00', 'minutes' => 60, 'lane_count' => 4]);
    Series::approve($o, (int) $s2['id'], []);
    $s3 = Series::createByStaff($o, ['club_id' => $rad, 'weekday' => 3, 'start_time' => '18:00', 'minutes' => 60, 'lane_count' => 3]);
    throws(fn() => Series::approve($o, (int) $s3['id'], []), 'dva pravila > 6 staza', 'trajno');
});

test('Turniri i kvalifikacije', function () {
    $o = mkuser('Vlasnik', '060000009', 'owner');
    $g = mkuser('Gost', '061000001');
    $r = book($g, '2026-11-21 12:00', 60, 1);
    as_($o);
    $e = throws(fn() => Events::create($o, ['kind' => 'tournament', 'title' => 'Kup', 'start' => '2026-11-21 08:00', 'end' => '2026-11-21 14:30']), 'turnir s konfliktom', 'konflikte');
    eq(count($e?->data['conflicts'] ?? []), 1, 'prikazan konflikt');
    Reservations::cancel($o, $r['id'], 'arena', 'Turnir');
    $t = Events::create($o, ['kind' => 'tournament', 'title' => 'Kup', 'start' => '2026-11-21 08:00', 'end' => '2026-11-21 14:30']);
    ok($t['id'] > 0, 'turnir od 08:00 nakon rješavanja konflikta');
    throws(fn() => book($g, '2026-11-21 14:00', 60, 1), 'online rezervacija tokom blokade');
    $after = book($g, '2026-11-21 15:00', 60, 1);
    ok($after['id'] > 0, 'nakon turnira: prvi slobodan puni sat');
    Events::addEntry($o, (int) $t['id'], ['lane_id' => 1, 'start' => '2026-11-21 08:00', 'end' => '2026-11-21 09:00', 'player_name' => 'Marko Marković']);
    eq(count(Events::get((int) $t['id'], false)['entries']), 1, 'javni raspored turnira');
    // kvalifikacije
    $q = Events::create($o, ['kind' => 'qualification', 'title' => 'Kvalifikacije', 'start' => '2026-11-22 10:00', 'end' => '2026-11-22 15:00', 'signup_phone' => '066 213 121']);
    eq(count($q['positions']), 4 * 6, '4 termina po 75 min × 6 staza');
    Events::addEntry($o, (int) $q['id'], ['lane_id' => 2, 'start' => '2026-11-22 11:15', 'player_name' => 'Ivana Ivić']);
    ok(true, 'nastup unutar sopstvene blokade nema lažni konflikt');
    throws(fn() => Events::addEntry($o, (int) $q['id'], ['lane_id' => 2, 'start' => '2026-11-22 11:15', 'player_name' => 'Drugi']), 'dupli nastup', 'već');
    throws(fn() => Events::addEntry($o, (int) $q['id'], ['lane_id' => 3, 'start' => '2026-11-22 11:00', 'player_name' => 'X']), 'nastup van rasporeda 75 min', '75');
    $pub = Calendar::day('2026-11-22', 'public');
    $blk = $pub['blocks'][0];
    eq([$blk['signup_phone'], count(array_filter($blk['positions'], fn($p) => $p['player_name'] === null))], ['066 213 121', 23], 'javno: telefon za prijave i slobodna mjesta');
    throws(fn() => book($g, '2026-11-22 12:00', 60, 1), 'slobodno mjesto kvalifikacija se ne rezerviše online');
});

test('Rođendan', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    throws(fn() => staffbook($s, ['start' => '2026-11-16 16:00', 'minutes' => 30, 'category' => 'birthday', 'lane_count' => 2, 'guest' => ['phone' => '061 555 555', 'first_name' => 'Mama']]), 'rođendan < 60 min', '60');
    $b = staffbook($s, ['start' => '2026-11-16 16:00', 'minutes' => 120, 'category' => 'birthday', 'lane_count' => 3, 'guest' => ['phone' => '061 555 555', 'first_name' => 'Mama']]);
    Clock::set('2026-11-16 18:00:00');
    as_($s);
    eq(Reservations::settle($s, $b['id'], ['shoes_used' => true, 'shoe_pairs' => 10])['total_cents'], 3 * 3000 + 1000 * 3, 'rođendan 3 staze × 2 h = 90 KM + 10 pari = 30 KM');
});

test('Dnevni obračun i zabrana zaključivanja neriješenog dana', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $rad = (int) Db::val("SELECT id FROM clubs WHERE short = 'Rad'");
    $p = mkuser('Igrač', '064000001', 'guest', $rad);
    $g = mkuser('Gost', '061000001');
    $r = book($g, '2026-11-16 18:00', 60, 1);
    $t = book($p, '2026-11-16 19:00', 60, 1);
    Clock::set('2026-11-16 22:30:00');
    as_($s);
    $e = throws(fn() => DayClose::close($s, '2026-11-16'), 'zaključenje s neobrađenim terminom', 'neobrađeni');
    eq(array_column($e?->data['blockers'] ?? [], 'reservation_id'), [$r['id']], 'prepreka: samo nenaplaćena rekreacija (trening je obrađen)');
    Reservations::settle($s, $r['id'], ['shoes_used' => true, 'shoe_pairs' => 2]);
    $tot = DayClose::close($s, '2026-11-16');
    eq([$tot['lane'], $tot['shoes'], $tot['total']], [1500, 600, 2100], 'dnevni promet: kuglanje i patike odvojeno');
    eq($tot['club_value'][0]['value_cents'] ?? null, 1000, 'klupska vrijednost odvojeno od gotovine');
    throws(fn() => book($g, '2026-11-16 22:00', 60, 1), 'zaključen dan');
    throws(fn() => DayClose::close($s, '2026-11-16'), 'dvostruko zaključenje', 'već');
});

test('Nalozi: registracija, povezivanje profila, prijava, oporavak, ograničenja', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    as_($s);
    $prof = Auth::findOrCreateProfile(['phone' => '065 123 456', 'first_name' => 'Petar'], $s);
    $again = Auth::findOrCreateProfile(['phone' => '+387 65 123-456', 'first_name' => 'Drugi'], $s);
    eq((int) $again['id'], (int) $prof['id'], 'isti broj, drugi zapis → isti profil');
    $api = new Api(null, '1.2.3.4');
    [$st, $res] = $api->handle('POST', 'auth/register', [], ['first_name' => 'Petar', 'last_name' => 'Perić', 'phone' => '0038765123456', 'email' => 'p@example.test', 'password' => 'tajna12345']);
    eq($st, 200, 'registracija start');
    $code = $res['data']['dev_code'];
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'verify_code' AND status = 'pending'"), 1, 'kod u redu poruka');
    eq($api->handle('POST', 'auth/confirm', [], ['phone' => '065123456', 'code' => '000000' === $code ? '111111' : '000000'])[0], 400, 'pogrešan kod');
    [$st] = $api->handle('POST', 'auth/confirm', [], ['phone' => '065123456', 'code' => $code]);
    eq($st, 200, 'potvrda koda');
    eq((int) Db::val('SELECT COUNT(*) FROM users WHERE phone = ?', ['+38765123456']), 1, 'nema duplikata — povezan s profilom osoblja');
    eq($api->handle('POST', 'auth/login', [], ['login' => '065/123-456', 'password' => 'tajna12345'])[0], 200, 'prijava telefonom (drugi zapis)');
    for ($i = 0; $i < 5; $i++) $api->handle('POST', 'auth/login', [], ['login' => '061777777', 'password' => 'x']);
    eq($api->handle('POST', 'auth/login', [], ['login' => '061777777', 'password' => 'x'])[0], 429, 'ograničenje pokušaja prijave');
    [$st, $rr] = $api->handle('POST', 'auth/reset', [], ['phone' => '065123456']);
    eq($api->handle('POST', 'auth/reset-confirm', [], ['phone' => '065123456', 'code' => $rr['data']['dev_code'], 'password' => 'novalozinka1'])[0], 200, 'oporavak lozinke kodom');
    eq($api->handle('POST', 'auth/login', [], ['login' => '065123456', 'password' => 'novalozinka1'])[0], 200, 'prijava novom lozinkom');
    ok(!str_contains((string) Db::val('SELECT password_hash FROM users WHERE phone = ?', ['+38765123456']), 'novalozinka1'), 'lozinka nije u čistom tekstu');
});

test('Javni kalendar ne otkriva privatne podatke; verzija cijene', function () {
    $g = mkuser('Tajni', '061000009');
    $r = book($g, '2026-11-16 18:00', 60, 1);
    $pub = json_encode(Calendar::day('2026-11-16', 'public'), JSON_UNESCAPED_UNICODE);
    ok(!str_contains($pub, 'Tajni') && !str_contains($pub, '61000009') && !str_contains($pub, 'cents'), 'javno: bez imena, telefona i iznosa');
    $o = mkuser('Vlasnik', '060000009', 'owner');
    $api = new Api(Auth::createSession((int) $o['id']));
    eq($api->handle('POST', 'prices', [], ['recreation_30' => '10', 'recreation_min' => '20', 'shoes' => '4', 'training_lane_hour' => '12', 'monthly' => '60'])[0], 200, 'nova verzija cjenovnika');
    eq(Reservations::quoteSettle($r['id'])['cash_total'], 1500, 'stara rezervacija zadržava staru cijenu');
    $r2 = book($g, '2026-11-17 18:00', 60, 1);
    eq(Reservations::quoteSettle($r2['id'])['cash_total'], 2000, 'nova rezervacija po novoj cijeni');
});

test('Obavijesti: red, idempotentnost, „nije poslano“ bez kanala, ponovni pokušaji', function () {
    $g = mkuser('Gost', '061000001');
    Db::insert('notification_recipients', ['name' => 'Vlasnik', 'phone' => '+38766000000', 'channel' => 'sms', 'types' => json_encode(['reservation_created', 'unprocessed'])]);
    $r = book($g, '2026-11-16 18:00', 60, 1);
    Notify::event('reservation_created', ['reservation_id' => $r['id']]);
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'reservation_created'"), 2, 'gost + primalac, ponovljeni događaj ne duplira');
    $st = Notify::work();
    eq($st['not_configured'], 2, 'bez kanala: status nije poslano');
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE status = 'sent'"), 0, 'ništa nije označeno kao poslano');
    // lažni kanal koji pada pa uspije
    Config::load(array_merge(Config::all(), ['channels' => ['sms' => ['adapter' => 'http', 'url' => 'http://127.0.0.1:9/nema']]]));
    foreach (Db::all('SELECT id FROM outbox') as $m) Notify::requeue((int) $m['id']);
    $st = Notify::work();
    eq($st['failed'], 2, 'neuspjeh → ponovni pokušaj zakazan');
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE status = 'pending' AND attempts = 1 AND next_attempt_utc > ?", [Clock::nowUtc()]), 2, 'čeka sljedeći pokušaj');
    Config::load(array_merge(Config::all(), ['channels' => []]));
    Clock::set('2026-11-16 20:05:00');
    Jobs::unprocessedReminders(); Jobs::unprocessedReminders();
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'unprocessed'"), 1, 'neobrađen termin 1 h nakon kraja — jednom');
});

test('Podsjetnici mjesečne', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $m = mkuser('Mirko', '062000001');
    as_($s); Coverage::activate((int) $m['id'], 'standard', null, $s);
    Clock::set('2026-12-13 08:00:00');
    Jobs::monthlyReminders(); Jobs::monthlyReminders();
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'monthly_expiring'"), 1, '3 dana prije isteka, jednom');
    Clock::set('2026-12-16 08:00:00');
    Jobs::monthlyReminders();
    eq((int) Db::val("SELECT COUNT(*) FROM outbox WHERE type = 'monthly_expired'"), 1, 'po isteku');
    $api = new Api(Auth::createSession((int) $m['id']));
    eq($api->handle('POST', 'me/monthly-request', [], ['kind' => 'renew'])[0], 200, 'zahtjev za produženje');
    eq($api->handle('POST', 'me/monthly-request', [], ['kind' => 'renew'])[0], 400, 'drugi zahtjev dok prvi čeka');
});

test('Statistika', function () {
    $s = mkuser('Sank', '060000000', 'staff');
    $g = mkuser('Gost', '061000001');
    $o = mkuser('Vlasnik', '060000009', 'owner');
    $rad = (int) Db::val("SELECT id FROM clubs WHERE short = 'Rad'");
    $r = book($g, '2026-11-16 18:00', 60, 2);
    staffbook($o, ['start' => '2026-11-16 15:00', 'category' => 'match_rad', 'club_id' => $rad, 'match_home' => 'Rad', 'match_guest' => 'Gost']);
    Clock::set('2026-11-16 19:30:00');
    as_($s); Reservations::settle($s, $r['id'], ['shoes_used' => true, 'shoe_pairs' => 1]);
    $st = Stats::compare('2026-11-16', '2026-11-16');
    eq((float) $st['current']['lane_hours_total'], 17.0, 'staza-sati: utakmica 15 + 2');
    eq($st['current']['cash']['total'], 3300, 'gotovina 30 + 3');
    ok(isset($st['previous']), 'poređenje s prethodnim periodom');
    eq((int) ($st['current']['clubs'][0]['value_cents'] ?? 0), 15 * 1000, 'klupska vrijednost odvojeno');
});

echo "\n" . $GLOBALS['pass'] . ' provjera prošlo, ' . count($GLOBALS['fail']) . " palo.\n";
exit($GLOBALS['fail'] ? 1 : 0);
