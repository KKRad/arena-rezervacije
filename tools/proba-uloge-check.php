<?php
declare(strict_types=1);
/**
 * Provjera uloga na PROBNOM sajtu preko pravog HTTP API-ja (isti pozivi kao ekran).
 * php tools/proba-uloge-check.php [datum GGGG-MM-DD]
 * Prijave iz PROBA-NALOZI.txt (lozinke se ne ispisuju). Pravi probne termine na zadani datum (podrazumijevano za 8 dana).
 */
$root = dirname(__DIR__);
$base = rtrim(getenv('PROBA_URL') ?: 'https://aliceblue-eagle-979980.hostingersite.com', '/') . '/rezervacije/api/';
$day = $argv[1] ?? date('Y-m-d', strtotime('+8 days'));
$t = (string) file_get_contents("$root/PROBA-NALOZI.txt");
preg_match_all('/^(\w+)\s+prijava: (\S+)\s+lozinka: (\S+)/m', $t, $mm, PREG_SET_ORDER);
$acc = []; foreach ($mm as $m) $acc[$m[1] . ($m[1] === 'guest' && isset($acc['guest']) ? '2' : '')] = [$m[2], $m[3]];
$pass = 0; $fail = [];
function check(bool $c, string $msg): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $msg\n"; } else { $fail[] = $msg; echo "  ✗ $msg\n"; } }
function client(string $base): object {
    return new class($base) {
        public string $cookie = '';
        public function __construct(private string $base) {}
        public function req(string $m, string $p, ?array $b = null): array {
            $h = "X-RS: 1\r\nContent-Type: application/json\r\n" . ($this->cookie ? "Cookie: {$this->cookie}\r\n" : '');
            $r = file_get_contents($this->base . $p, false, stream_context_create(['http' => ['method' => $m, 'ignore_errors' => true, 'timeout' => 30, 'header' => $h, 'content' => $b !== null ? json_encode($b) : '']]));
            $hdr = http_get_last_response_headers() ?? [];
            foreach ($hdr as $x) if (preg_match('/^Set-Cookie: (rs_sess=[^;]+)/i', $x, $q)) $this->cookie = $q[1];
            preg_match('/\s(\d{3})\s/', $hdr[0] ?? '', $c);
            return [(int) ($c[1] ?? 0), json_decode((string) $r, true)];
        }
        public function login(array $a): bool { return ($this->req('POST', 'auth/login', ['login' => $a[0], 'password' => $a[1]])[1]['ok'] ?? false) === true; }
    };
}
$c = []; foreach (['owner', 'manager', 'staff', 'guest', 'guest2'] as $r) { $c[$r] = client($base); check($c[$r]->login($acc[$r]), "prijava: $r"); }

echo "• Priprema probnih termina ($day)\n";
[$st, $g1] = $c['guest']->req('POST', 'reservations', ['start' => "$day 12:00", 'minutes' => 60, 'lanes' => [1]]);
check($st === 200 && in_array($g1['data']['category'] ?? '', ['recreation', 'monthly'], true), 'gost rezerviše (rekreacija ili mjesečna ako je gost u međuvremenu dobio mjesečnu kartu)');
[$st, $cl] = $c['guest2']->req('POST', 'reservations', ['start' => "$day 13:00", 'minutes' => 120, 'lanes' => [2, 3]]);
check($st === 200 && ($cl['data']['category'] ?? '') === 'training', 'član kluba: trening na račun kluba');
check(!array_key_exists('club_amount_cents', $cl['data'] ?? []) && !array_key_exists('billing', $cl['data'] ?? []), 'član ne vidi cijenu ni obračun treninga');
$clubs = $c['owner']->req('GET', 'clubs')[1]['data'];
$rad = array_values(array_filter($clubs, fn($x) => $x['short'] === 'Rad'))[0];
[$st, $mt] = $c['owner']->req('POST', 'reservations', ['as_staff' => true, 'start' => "$day 16:00", 'category' => $rad['match_category'], 'club_id' => $rad['id'], 'match_home' => 'KK Rad', 'match_guest' => 'KK Gost Proba', 'match_league' => 'Probna liga']);
check($st === 200 && count($mt['data']['lanes'] ?? []) === 6, 'vlasnik: utakmica na 6 staza');
[$st, $ev] = $c['owner']->req('POST', 'events', ['kind' => 'qualification', 'title' => 'Probne kvalifikacije', 'start' => "$day 19:00", 'end' => "$day 21:30", 'signup_phone' => '066 213 121']);
check($st === 200, 'vlasnik: kvalifikacije');
[$st] = $c['staff']->req('POST', 'events/' . ($ev['data']['id'] ?? 0) . '/entries', ['lane_id' => 1, 'start' => "$day 19:00", 'player_name' => 'Marko Probni']);
check($st === 200, 'Šank Arena: upis igrača u kvalifikacije');

echo "• Javni prikaz (bez prijave)\n";
$anon = client($base);
[$st, $pub] = $anon->req('GET', 'calendar/day?date=' . $day);
$js = json_encode($pub, JSON_UNESCAPED_UNICODE);
$cats = array_column($pub['data']['blocks'], 'category');
check((in_array('recreation', $cats, true) || in_array('monthly', $cats, true)) && in_array('training', $cats, true) && in_array($rad['match_category'], $cats, true) && in_array('qualification', $cats, true), 'javno: kategorije svih termina');
check(str_contains($js, 'KK Rad – KK Gost Proba') && str_contains($js, 'Probna liga'), 'javno: klubovi i liga utakmice');
$q = array_values(array_filter($pub['data']['blocks'], fn($b) => $b['type'] === 'event'))[0] ?? [];
$free = count(array_filter($q['positions'] ?? [], fn($p) => $p['player_name'] === null));
check(str_contains($js, 'Marko Probni') && $free === 11 && ($q['signup_phone'] ?? '') === '066 213 121', "javno: igrač, staza, vrijeme, $free slobodnih mjesta, telefon za prijave");
check(!preg_match('/\+387|61100100|example\.test|cents|amount|debt|who"/', $js), 'javno: bez telefona gostiju, emaila, iznosa, dugova i imena gostiju');
[$st] = $anon->req('POST', 'reservations', ['start' => "$day 20:00", 'minutes' => 60, 'lanes' => [5]]);
check($st === 401, 'javno: bez prijave nema rezervacije');
[$st, $r] = $c['guest']->req('POST', 'reservations', ['start' => "$day 20:00", 'minutes' => 60, 'lanes' => [6]]);
check($st === 409, 'gost: slobodno mjesto kvalifikacija se ne može rezervisati online');
$far = date('Y-m-d', strtotime($day . ' +40 days'));
check(($anon->req('GET', 'calendar/day?date=' . $far)[0]) === 200, "javno: pregled više od mjesec unaprijed ($far)");

echo "• Uloge i server-side ovlaštenja\n";
check($c['guest']->req('GET', 'reservations/' . ($cl['data']['id'] ?? 0))[0] === 403, 'gost ne vidi tuđu rezervaciju');
check($c['guest']->req('GET', 'dashboard')[0] === 403, 'gost: nema administracije');
check($c['staff']->req('GET', 'dashboard')[0] === 200, 'Šank Arena: administracija');
check($c['staff']->req('GET', 'stats')[0] === 403 && $c['staff']->req('POST', 'prices', ['recreation_30' => '1'])[0] === 403, 'Šank Arena: bez statistike i cijena');
check($c['staff']->req('POST', 'debts/1/forgive', ['reason' => 'x'])[0] === 403 && $c['staff']->req('POST', 'payments/1/correct', ['reason' => 'x'])[0] === 403, 'Šank Arena: bez oprosta duga i ispravki naplate');
check($c['manager']->req('GET', 'statements')[0] === 200 && $c['manager']->req('GET', 'stats')[0] === 403 && $c['manager']->req('GET', 'staff-accounts')[0] === 403, 'menadžer: obračuni da; statistika i ovlaštenja ne');
check($c['owner']->req('GET', 'stats')[0] === 200 && $c['owner']->req('GET', 'staff-accounts')[0] === 200, 'vlasnik: statistika i ovlaštenja');
[$st, $day1] = $c['staff']->req('GET', 'day?date=' . $day);
check($st === 200 && is_array($day1['data']['open_later'] ?? null), 'Šank Arena: dnevni obračun');
[$st, $un] = $c['manager']->req('GET', 'statements/unbilled?club=' . $rad['id']);
check($st === 200 && !str_contains(json_encode($un), 'laćeno'), 'klubovi: „Obračunato/Neobračunato“, bez uplata');
[$st, $me] = $anon->req('GET', 'me');
check(($me['data']['env'] ?? '') === 'proba' && ($me['data']['registration'] ?? false) === true, 'proba: registracija uključena, označeno kao proba');

echo "\n$pass provjera prošlo, " . count($fail) . " palo.\n";
exit($fail ? 1 : 0);
