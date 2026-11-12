# Sistem rezervacija Arene Bakarni — projektni dokument

Verzija 2 · 12.11.2026 (revizija) · probno okruženje https://proba.brckoworldcup.com (ništa nije objavljeno na arenabrcko.com)

Sadržaj: 0. Tehnologija i hosting · 1. Stranice i tokovi · 2. Poslovna pravila (kako su implementirana) · 3. Matrica ovlaštenja · 4. Model baze · 5. Faze i kriteriji prihvatanja · 6. Otvorene odluke

---

## 0. Tehnologija, hosting i domena

**Postojeće stanje (provjereno 12.11.2026):**
- arenabrcko.com je parkirani domen na Hostinger sajtu `brckoworldcup.com` (nalog u270359984), paket **Single Web Hosting**.
- Postojeći sajt je statički (HTML/CSS/JS koje generiše `arena-bakarni-site/tools/build.py`), objavljuje se kao zip u `public_html`, Apache `.htaccess`.
- Single Web Hosting podržava **PHP 8 + MySQL**, cron i HTTPS. **Ne podržava Node.js** (to je tek na Business planu).

**Odluka:** aplikacija je napisana u **PHP 8 (bez okvira i bez Composer zavisnosti) + MySQL/MariaDB (InnoDB)**, front-end u čistom JavaScriptu bez build koraka.
Razlozi: radi na postojećem hostingu i domeni bez doplate i bez selidbe; MySQL daje prave transakcije i jedinstvene indekse (zaštita od dvostrukog rezervisanja); nema npm/Composer lanca koji se mora održavati na dijeljenom hostingu.

**Raspored na serveru (prijedlog za objavu, tek nakon odobrenja):**
```
/home/u270359984/domains/brckoworldcup.com/
├── arena-rezervacije/          ← kod aplikacije, config.php sa lozinkom baze, backup/  (IZVAN public_html)
└── public_html/
    ├── (postojeći sajt — ne dira se)
    ├── _rs/index.php           ← tanki ulaz koji učitava ../arena-rezervacije
    ├── _rs/assets/…            ← CSS/JS aplikacije
    └── .htaccess               ← + 3 pravila: /rezervacije, /nalog, /administracija → _rs/index.php
```
Adrese: `arenabrcko.com/rezervacije` (javni kalendar), `/nalog` (prijava, registracija, moj nalog), `/administracija` (osoblje), API pod `/rezervacije/api/…`.
Objava postojećeg sajta (zip) mijenja `public_html`; zato `tools/package.py` postojećeg sajta treba dobiti `_rs/` i tri `.htaccess` pravila (izmjena se radi tek pri objavi, uz odobrenje). Dugme „Rezerviši stazu“ u zaglavlju i dalje vodi na Sportin dok vlasnik ne odobri prelazak.

**Probno okruženje:** lokalno (`php -S` + MariaDB, baza `arena_rs_dev`; testovi na zasebnoj bazi `arena_rs_test`). Kasnije opcionalno poddomena `proba.arenabrcko.com` sa zasebnom bazom — tek uz odobrenje (Single plan ima ograničen broj sajtova; poddomena je dozvoljena).

**Rutinske tehničke odluke:**
| Tema | Rješenje |
|---|---|
| Novac | cijeli brojevi u feninzima (`INT`, 15,00 KM = 1500). Nema float-a. |
| Vrijeme | poslovna vremena (početak/kraj termina) kao lokalno vrijeme Europe/Sarajevo (`DATETIME`); sistemski tragovi (`created_at`, audit) u UTC. Rad je 08–23 h, pa prelazak na ljetno/zimsko (02–03 h noću) ne dira termine. |
| Granulacija | zauzetost se vodi u blokovima od 15 min (`lane_slots`, jedinstveni indeks staza+blok). 60/90/120/150/75 min su višekratnici 15. |
| Zaštita od duplog rezervisanja | (1) zaključavanje dana `SELECT … FOR UPDATE` na `day_locks`, (2) jedinstveni indeks `lane_slots(lane_id, slot_start)`, (3) sve u jednoj transakciji (više staza = sve ili ništa). |
| Lozinke | `password_hash` (bcrypt/argon2 koliko PHP podržava), kodovi se čuvaju kao hash, ograničenja pokušaja u `rate_limits`. |
| Sesije | nasumičan token u HttpOnly + Secure + SameSite=Lax kolačiću, u bazi samo hash; CSRF zaštita zaglavljem `X-RS` na svim izmjenama. |
| Telefoni | normalizacija u E.164 (`066 213-121`, `+387 66 213 121`, `0038766213121` → `+38766213121`). |
| PDF | vlastiti mali PDF generator (Helvetica sa kodiranjem za č, ć, đ, š, ž) — bez vanjskih biblioteka. |
| Poruke | red `outbox` sa ključem idempotentnosti, ponovni pokušaji s rastućim razmakom; kanali kao zamjenjivi adapteri (SMS/Viber/WhatsApp/Email). Dok kanal nije povezan, status je „Nije poslano — kanal nije povezan“. |
| Backup | `cron.php backup` pravi dnevni SQL dump (gzip) izvan `public_html`, čuva 30 dana; `tools/restore-check.php` vraća dump u probnu bazu i provjerava brojeve redova. |
| Pozadinski poslovi | jedan cron svakih 5 min: `php cron.php all` (slanje poruka, podsjetnici, neobrađeni termini, generisanje serija, backup jednom dnevno). |

---

## 1. Stranice i tokovi

### 1.1 Javno (bez prijave) — `/rezervacije`
- Dnevni kalendar: vrijeme vertikalno 08:00–23:00, **šest staza u kolonama, bez horizontalnog pomjeranja** (telefon: oznake S1–S6, sažeti blokovi, detalj na klik).
- Kontrole: ‹ prethodni dan, sljedeći dan ›, izbor datuma, „Danas“, prekidač Dan/Sedmica. Početno: današnji dan. Pregled bez ograničenja unaprijed.
- Sedmični pregled: 7 dana × sati, u svakoj ćeliji 6 malih traka (staze); klik na dan otvara dnevni.
- Blok prikazuje kategoriju (boja + naziv). Utakmica: domaćin – gost, liga. Kvalifikacije: ime i prezime igrača po stazi i terminu, slobodna mjesta, „Telefon za prijave“. Turnir: naziv i javni raspored igrača.
- Nikad javno: telefoni, email, stanje plaćanja, dugovi, klupski obračuni, cijene, imena gostiju rekreacije.
- Klik na slobodan termin → ako nije prijavljen: „Prijavite se za rezervaciju“.

### 1.2 Nalog — `/nalog`
- **Registracija:** ime, prezime, telefon, email, lozinka → SMS kod → potvrda. Ako profil s tim telefonom već postoji (unijelo ga osoblje, bez lozinke) → povezuje se s njim (bez duplikata). Ako postoji aktivan nalog → „Broj je već registrovan — prijavite se ili oporavite lozinku“.
- **Prijava:** telefon + lozinka (osoblje može i korisničkim imenom, npr. `sank`).
- **Oporavak lozinke:** telefon → kod na potvrđeni telefon → nova lozinka.
- **Moj nalog:** buduće i prethodne rezervacije (izmijeni / otkaži / produži), mjesečna i datum isteka, preostali redovni sati ove sedmice i bonus sati (s historijom), lični dug i upozorenja, dugmad „Želim mjesečnu“ / „Želim produžiti“, za člana kluba klupski treninzi (bez cijena i obračuna), zahtjev za stalni klupski termin.
- **Tok rezervacije:** klik na slobodan termin → izbor trajanja (60/90/120), broja staza ili konkretnih staza → za člana kluba prekidač „Trening (na račun kluba)“ / „Nije trening — plaćam lično“ → prikaz pokrića i cijene (npr. „1 h iz mjesečne + 30 min 7,50 KM“) → Potvrdi. Rezervacija je odmah potvrđena.
- Blokiran nalog: sve vidi, ne može praviti nove rezervacije.
- Upozorenje o dugu: „Imate evidentiran dug — provjerite s osobljem pri dolasku.“ (ne blokira).

### 1.3 Administracija — `/administracija`
Početni ekran: kalendar (isti kao javni, s punim podacima) + sažetak: današnji gotovinski promet, neobrađeni/nenaplaćeni termini, zahtjevi na čekanju.
**Bočni panel rezervacije:** gost (ime, telefon, upozorenja: dug, prethodni nedolasci, blokada), događaj, staze, vrijeme, pokriće i cijena, statusi (rezervacija / dolazak / pokriće / naplata / klupski obračun), dugmad prema ovlaštenju i stanju: **Došao, Nije došao, Naplati, Izmijeni, Otkaži (gost / Arena), Produži, Oslobodi stazu (nedolazak nakon 10 min)**.
**Naplata:** obavezno pitanje „Jesu li korištene patike?“ (Da/Ne, broj pari); prikaz zasebno: Kuglanje, Patike, Prethodni dug (opciono, odvojene stavke) → Ukupno gotovina → Zaključi termin.

Stranice: Kalendar · Dnevni obračun · Zahtjevi (mjesečne, stalni termini) · Korisnici (profil, blokiranje, klub, mjesečna, bonus, dug) · Mjesečne · Klubovi (profil, email, način cijene utakmice, članovi) · Stalni termini (serije) · Obračuni klubova (neobračunato, napravi obračun, izdvoji utakmicu, arhiva, PDF) · Događaji (utakmica, turnir, kvalifikacije, rođendan) · Statistika · Cijene · Ovlaštenja · Obavijesti (primaoci, red poruka) · Historija promjena.

### 1.4 Ključni tokovi (sažeto)
1. **Rezervacija gosta online:** provjere (prijavljen, nije blokiran, početak na puni sat, 10:00 ≤ početak, kraj ≤ 23:00, početak u budućnosti, 60/90/120 min) → u transakciji: zaključaj dan → provjeri slobodne staze (rezervacije, blokade događaja, stalni klupski termini) → upiši rezervaciju, staze, blokove → isplaniraj pokriće (mjesečna/bonus) → obavijest.
2. **Unos osoblja za gosta bez naloga:** telefon → pretraga postojećih → izbor ili novi profil (ime + telefon) → isto kao 1, uz dozvolu 08:00 i slobodnije trajanje.
3. **Dolazak → naplata → zaključen termin** (vidi 2.6).
4. **Nedolazak:** nakon 10 min kašnjenja „Nije došao“ (+ opciono „Oslobodi stazu“) → zapis ostaje, blokovi se brišu → pravila duga/upozorenja/propadanja sati.
5. **Zaključivanje dana:** lista prepreka s linkovima → kad je prazna, „Zaključi dan“ → snimak dnevnog obračuna.
6. **Obračun kluba:** „Napravi obračun“ → sve održano i neobračunato do tog trenutka → stavke vezane za obračun → PDF → email u red slanja.

---

## 2. Poslovna pravila — kako su implementirana

### 2.1 Kalendar i dostupnost
- Prikaz 08:00–23:00. Gost: početak na puni sat, 10:00–23:00. Osoblje: od 08:00, u koracima od 15 min (događaji), standardne rezervacije na puni sat.
- Nema obavezne pauze. Prva potvrđena ima prednost: ko prvi upiše blok, drugi dobija „Termin je upravo zauzet“.
- Preklapanje nije dozvoljeno ni između kategorija. Rezervacija se nikad ne prepisuje.
- Izmjena/produženje: stari blokovi se oslobađaju i novi zauzimaju u **istoj transakciji**; ako nema mjesta, ostaje stara rezervacija.
- Dodjela staza: gost bira konkretne staze ili „bilo koje N“ → sistem uzima najniže slobodne brojeve.

### 2.2 Kategorije i boje
Mjesečne `#2e7d32` · Treninzi `#1565c0` · KK Rad `#c62828` · ŽKK Olimp `#6a1b9a` · KK Zrinski `#00838f` · Utakmice ostale `#5d4037` · Rekreacija `#ef6c00` · Rođendan `#d81b60` · Turnir `#283593` · Kvalifikacije `#827717`.
Stanje naplate je zasebna oznaka (ikonica/obrub): ⏳ čeka, ✓ zaključeno, ✕ nedolazak, ⊘ otkazano.

### 2.3 Cijene (verzionisane)
Tabela `price_versions`: svaka promjena pravi novu verziju; rezervacija pamti `price_version_id` i snimak iznosa.
- Rekreacija i rođendan: 7,50 KM po započetih 30 min po stazi, **najmanje 15,00 KM po stazi** → 30 min 15,00 · 60 min 15,00 · 90 min 22,50 · 120 min 30,00. Ne zavisi od broja igrača.
- Patike 3,00 KM po paru.
- Trening 10,00 KM po stazi i satu (proporcionalno: 90 min = 15,00).
- Mjesečna i VIP mjesečna 50,00 KM.
- Utakmica: po profilu kluba — fiksni iznos ili cijena po stazi i satu (6 staza × 2,5 h = 15 staza-sati).
- Kraće korištenje: osoblje pri naplati može upisati stvarno korištene minute; vrijedi minimum 15,00 KM po stazi.

### 2.4 Mjesečne, VIP i bonus
- Period: od dana uplate do istog datuma narednog mjeseca (isključivo). Aktivira osoblje nakon gotovine (upis uplate 50 KM + period u istoj transakciji).
- Limit: standardno 120 min sedmično, VIP: broj sati određuje vlasnik/menadžer. Sedmica pon–ned, bez prenosa. Prva (djelimična) sedmica perioda ima puni limit.
- Pokriva **jednu stazu** po rezervaciji; ostale staze iste rezervacije plaćaju se kao rekreacija.
- Pokriće se **planira pri rezervaciji** (`hour_allocations`, stanje `planned`): 1) redovni sati te sedmice tog perioda, 2) bonus, 3) gotovina za ostatak. Planirani sati smanjuju raspoloživo stanje odmah — isti sat ne može se obećati dvaput. Pri izmjeni, otkazu i zaključenju plan se preračunava; pri aktivaciji nove mjesečne preračunavaju se buduće rezervacije korisnika.
- Rezervacija u periodu bez aktivne mjesečne dozvoljena je; pokriće se računa pri dolasku (produženje, bonus ili gotovina).
- Nedolazak: planirani redovni sati propadaju (`forfeited`), bonus se vraća, **nema duga**.
- Otkaz od strane Arene: pokriveni sati (redovni + bonus) se **ne vraćaju**, nego se dodjeljuje 2× toliko bonus sati (1 h → 2 h, 2 h → 4 h). Jedinstveni indeks na `bonus_grants(source_reservation_id)` sprečava ponovnu dodjelu. Time nema trostrukog odobravanja (vraćen sat + 2 bonus).
- Otkaz gosta: plan se oslobađa (sati se vraćaju u limit).
- Bonus: bez isteka, važi i bez aktivne mjesečne, troši se tek kad nema redovnih sati, puna evidencija (dodjela, razlog, ko, potrošnja po rezervaciji). Ručnu dodjelu/oduzimanje radi ovlaštena osoba uz obavezan razlog.
- Podsjetnici: 3 dana prije isteka i na dan isteka (jednom, ključ idempotentnosti).
- Prije potvrde aktivacije ekran prikazuje novi period (od–do). Ako korisnik ima aktivnu mjesečnu (produženje prije isteka), prikazuje se upozorenje koliko dana prethodnog perioda propada i traži se izričita potvrda; server odbija aktivaciju bez nje.
- **Nepokriveni dio termina uz mjesečnu/bonus** (otvorena odluka 3): iznos po započetih 30 min i opcioni minimum su **podesivi** u Cijenama (verzionisano). Trenutno: 7,50 KM / 30 min, bez minimuma. Primjeri: 2 h termin, 1,5 h iz mjesečne → 7,50 KM; 1,5 h termin, 30 min iz mjesečne → 15,00 KM; druga staza iste rezervacije ili staza bez ikakvog pokrića → obična rekreacija (najmanje 15 KM).

### 2.5 Klubovi, treninzi, serije
- Korisnik je u najviše jednom klubu (`users.club_id`), uređuje vlasnik/menadžer.
- Rezervacija člana je podrazumijevano trening (na račun kluba, 10 KM/staza/sat); „Nije trening — plaćam lično“ → rekreacija/mjesečna. Bez sedmičnog limita.
- Trening koji nije otkazan, po isteku vremena je **održan** i automatski **obrađen** (evidentiran za klub) — ne traži naplatu ni obračun za zaključenje dana.
- Stalni termin (`recurring_series`): pravilo (dan u sedmici, početak, trajanje, broj staza, željene staze, od datuma, do datuma ili otvoreno). Zahtjev igrača čeka odobrenje i ne čuva termin. Pri odobrenju sistem provjerava sve postojeće buduće rezervacije i blokade (konačan skup) i druga odobrena pravila; nudi listu konfliktnih datuma i opciju „Prihvati uz preskakanje ovih datuma“ (izuzeci).
- Bez beskonačnih redova: pojave se upisuju kao stvarne rezervacije samo za prozor (danas + 8 sedmica, i na zahtjev kalendara za gledani datum). Za datume izvan prozora provjera dostupnosti uzima u obzir **virtualne pojave** pravila: nova rezervacija ne smije ostaviti manje slobodnih staza od broja staza koje pravilo traži. Pri upisu pojave, ako je željena staza zauzeta, dodjeljuje se druga slobodna.
- Ukidanje: postavlja se krajnji datum; buduće pojave se otkazuju (zapis ostaje).
- Pregled prije odobrenja prikazuje: datume s konfliktom (nijedna staza slobodna — mogu se preskočiti) i datume na kojima će sistem dodijeliti **zamjensku stazu**. „Pregled“ odobrene serije prikazuje upisane termine (zamjenska staza označena), preskočene datume s razlogom i do kada su termini upisani.

### 2.6 Dolazak, pokriće, naplata, dug
Statusi su odvojeni: `status` (aktivna/otkazana), `attendance` (čeka/došao/nije došao), pokriće (`hour_allocations` + `charges`), gotovina (`payments`), klupski obračun (`club_statement_items`), obrada (`processed_at`).
- Dolazak se može označiti prije kraja. Termin je **obrađen** kad: došao + pokriće potpuno (gotovina naplaćena / mjesečna / bonus / klub) ili nije došao (pravila nedolaska primijenjena) ili je klupski i održan.
- Nema statusa „Došao, ali nije platio“: dugme „Zaključi“ traži da je iznos naplaćen.
- Nedolazak nakon 10 min: „Nije došao“ + „Oslobodi stazu“ (briše blokove od tog trenutka, zapis ostaje).
- Pravovremeno otkazivanje ≥ 2 h prije početka; kasnije = `late_cancel` (samo evidencija; posljedice odlučuje vlasnik/menadžer).
- Rekreacija, nedolazak: prvi → upozorenje (bez duga); drugi i svaki naredni → dug po cijeni propuštene rezervacije. Oprost: vlasnik/menadžer, uz trag i obavijest.
- Dug ne blokira rezervisanje; upozorenje vide gost i osoblje. Konobar može naplatiti termin i stari dug zajedno, kao odvojene stavke.
- Ispravke naplate: samo storno + nova stavka (nikad brisanje/izmjena), autor, razlog, obavijest.

### 2.7 Utakmice, turniri, kvalifikacije, rođendani
- Utakmica: 150 min, svih 6 staza, domaćin, gost, liga, početak, napomena, klub koji se obračunava (KK Rad / ŽKK Olimp / KK Zrinski / ostali klub iz liste).
- Turnir: blokada svih 6 staza za period ili cijeli dan (može od 08:00). Prije potvrde moraju biti riješeni konflikti (sistem ih izlista; osoblje premješta/otkazuje). Raspored igrača (staza, vrijeme, ime) je javan. Naplata turnira je izvan programa.
- Kvalifikacije: blokada (podrazumijevano 6 staza), nastupi od početka svakih 75 min; javno: ime i prezime, vrijeme, staza, slobodna mjesta; „Telefon za prijave“. Prijave unosi osoblje.
- **Blokada objekta (`events` + `lane_slots`) je odvojena od nastupa (`event_entries`)** — nastupi ne zauzimaju blokove, pa nema lažnih konflikata sa sopstvenom blokadom.
- Rođendan: unosi osoblje, broj staza, ≥ 60 min, cijena kao rekreacija, bez avansa, naplata gotovinom nakon.

### 2.8 Dnevni obračun
Jedan obračun po danu (nema smjena po konobaru). Gotovina dana = sve uplate upisane tog dana: Kuglanje (rekreacija, rođendani, ostaci uz mjesečnu), Patike, Mjesečne, Naplaćeni dugovi, Ispravke. Klupske vrijednosti prikazane odvojeno (nisu gotovina).
Zaključenje je blokirano dok postoje **završeni** lični termini (rekreacija, mjesečna, rođendan) bez evidentiranog ishoda ili pokrića (bez dolaska, ili došao bez naplate). Lista prepreka sa direktnim otvaranjem.
**Ne blokiraju:** budući i tekući termini (prikazani informativno) i klupski treninzi/utakmice (po završetku se sami evidentiraju klubu).
Zaključeni dnevni obračun se nikad ne mijenja: uplata ili ispravka nakon zaključenja upisuje se u **prvi sljedeći nezaključeni dan** (ekran to navodi). Rezervacije se mogu unositi i mijenjati i na zaključeni dan — dan zaključava samo novac.

### 2.9 Obavijesti
Primaoci (vlasnik unosi više brojeva i bira tipove), šabloni po tipu, red slanja, adapteri. Tipovi: nova rezervacija, otkaz (gost/osoblje), otkaz Arene (gostu obavezno), podsjetnik/istek mjesečne, zahtjevi, admin radnje, ispravka naplate i oprost duga (s autorom), neobrađen termin 1 h nakon kraja, verifikacioni kodovi, email obračuna kluba s PDF-om.

### 2.10 Statistika (podrazumijevano samo vlasnik)
Prihod (gotovina) po periodu i kategoriji · popunjenost u staza-satima i % (kapacitet 6 × 13 h = 78 staza-sati dnevno u radnom vremenu) · mjesečne i VIP (aktivne, nove, produžene) · klubovi: treninzi/utakmice i obračunate vrijednosti (odvojeno od gotovine) · patike · nedolasci · poređenje s prethodnim jednakim periodom.

---

## 3. Matrica ovlaštenja

Server provjerava svako ovlaštenje u svakom API pozivu. Vlasnik ima sve i jedini dodjeljuje ovlaštenja. Za osoblje se podrazumijevana ovlaštenja upisuju pri kreiranju naloga; vlasnik ih mijenja kvačicama.

| Ključ | Opis | Vlasnik | Menadžer | Šank Arena | Gost/član |
|---|---|:-:|:-:|:-:|:-:|
| `res.read` | Admin kalendar s punim podacima | ✓ | ✓ | ✓ | — (samo svoje) |
| `res.write` | Pravljenje, izmjena, otkaz rezervacija za druge | ✓ | ✓ | ✓ | — (svoje: da) |
| `res.attendance` | Došao / nije došao / oslobodi stazu | ✓ | ✓ | ✓ | — |
| `cash.collect` | Naplata rekreacije, patika, duga | ✓ | ✓ | ✓ | — |
| `cash.correct` | Storno/ispravka evidentirane naplate | ✓ | ✓ | — | — |
| `monthly.manage` | Aktivacija/produženje mjesečne nakon gotovine | ✓ | ✓ | ✓ | — (zahtjev: da) |
| `monthly.vip` | VIP mjesečna i sedmični sati | ✓ | ✓ | — | — |
| `bonus.manage` | Ručna dodjela/oduzimanje bonus sati | ✓ | ✓ | — | — |
| `users.manage` | Ručno pravljenje i uređivanje profila | ✓ | ✓ | — | — |
| `users.block` | Blokiranje naloga | ✓ | ✓ | — | — |
| `debt.forgive` | Oprost duga | ✓ | ✓ | — | — |
| `clubs.manage` | Klubovi, članstvo, stalni termini (odobrenje) | ✓ | ✓ | — | — (zahtjev: da) |
| `clubs.billing` | Obračuni klubova, PDF, slanje | ✓ | ✓ | — | — |
| `events.manage` | Utakmice, turniri, kvalifikacije | ✓ | ✓ | — | — |
| `events.birthday` | Rođendani | ✓ | ✓ | ✓ | — |
| `events.entries` | Unos igrača u kvalifikacije/turnir | ✓ | ✓ | ✓ | — |
| `day.view` | Pregled dnevnog prometa | ✓ | ✓ | ✓ | — |
| `day.close` | Zaključivanje dana | ✓ | ✓ | ✓ | — |
| `stats.view` | Statistika | ✓ | — | — | — |
| `prices.manage` | Cijene | ✓ | — | — | — |
| `settings.manage` | Glavne postavke, obavijesti, primaoci | ✓ | — | — | — |
| `audit.view` | Historija promjena | ✓ | ✓ | — | — |
| `perms.manage` | Dodjela ovlaštenja | ✓ (samo vlasnik, ne može se dodijeliti) | — | — | — |

Historija bilježi nalog koji je radnju napravio; za zajednički nalog piše „Šank Arena“ (ne izmišlja se konobar).

---

## 4. Model baze (MySQL/InnoDB, sažetak; puni DDL u `migrations/001_init.sql`)

| Tabela | Ključna polja | Napomena |
|---|---|---|
| `users` | id, first_name, last_name, phone (E.164, UNIQUE), phone_verified_at, email, password_hash, username (osoblje, UNIQUE), role (`owner/manager/staff/guest`), club_id, blocked_at/by/reason, created_by | profil bez lozinke = unijelo osoblje |
| `user_permissions` | user_id, perm | PK (user_id, perm) |
| `sessions` | token_hash, user_id, expires_at, ip, ua | |
| `verification_codes` | phone, purpose, code_hash, expires_at, attempts, used_at | |
| `rate_limits` | bucket, hits, window_start | prijava, kodovi |
| `lanes` | id 1–6, name, short | |
| `clubs` | name, email, short, category_code, match_price_mode (`fixed/lane_hour`), match_fixed_cents, match_lane_hour_cents, active | |
| `price_versions` | id, valid_from, recreation_30_cents, recreation_min_cents, shoes_cents, training_lane_hour_cents, monthly_cents, created_by | nikad se ne mijenja, samo nova verzija |
| `reservations` | id, user_id (odgovorni rezervant), category, club_id, start_at, end_at, status (`active/cancelled`), cancel_kind (`guest/guest_late/arena/staff/series_end`), cancelled_at/by, attendance (`pending/arrived/no_show`), attendance_at/by, released_at, processed_at, source (`online/staff/series`), series_id + occurrence_date (UNIQUE), event_id, match_home/guest/league, notes, price_version_id, quoted_cents, used_minutes, created_by, updated_at | jedna rezervacija može imati više staza |
| `reservation_lanes` | reservation_id, lane_id | |
| `events` | id, kind (`tournament/qualification`), title, start_at, end_at, signup_phone, public_note, status, created_by | blokada objekta |
| `event_lanes` | event_id, lane_id | |
| `event_entries` | event_id, lane_id, start_at, end_at, player_name, note | nastupi — ne zauzimaju blokove |
| `lane_slots` | lane_id, slot_start, reservation_id \| event_id | **UNIQUE(lane_id, slot_start)** — fizička garancija |
| `day_locks` | day (PK) | serijalizacija upisa po danu |
| `monthly_passes` | id, user_id, kind (`standard/vip`), weekly_minutes, start_date, end_date (isključivo), payment_id, status (`active/ended`), created_by | |
| `monthly_requests` | user_id, kind (`new/renew`), status (`pending/done/rejected`), handled_by | zahtjev ≠ uplata |
| `hour_allocations` | reservation_id, user_id, source (`regular/bonus`), pass_id, week_start, minutes, state (`planned/used/forfeited/compensated`) | puštene stavke se brišu iz plana (trag u auditu) |
| `bonus_grants` | user_id, minutes (+/−), kind (`arena_cancel/manual`), reason, source_reservation_id (UNIQUE), created_by | |
| `charges` | reservation_id, kind (`lane/shoes`), lane_id, minutes, qty, amount_cents | obračun termina |
| `payments` | id, business_day, user_id, reservation_id, debt_id, pass_id, kind (`lane/shoes/monthly/debt`), amount_cents (može biti negativan za storno), method=`cash`, reverses_payment_id, reason, created_by | **samo dodavanje** |
| `debts` | user_id, reservation_id (UNIQUE), amount_cents, status (`open/paid/forgiven`), forgiven_by/at/reason | |
| `warnings` | user_id, reservation_id, kind (`no_show_first`) | |
| `recurring_series` | club_id, requested_by, weekday, start_time, minutes, lane_count, preferred_lanes, valid_from, valid_to, status (`pending/approved/rejected/ended`), approved_by, materialized_to | |
| `series_exceptions` | series_id, date (UNIQUE par), reason | |
| `club_statements` | id, club_id, number, created_at, cutoff_at, total_cents, kind (`full/single_match`), pdf_path, created_by | |
| `club_statement_items` | statement_id, reservation_id (**UNIQUE**), description, lane_count, minutes, unit, amount_cents | sprečava dvostruki obračun |
| `day_closes` | day (PK), totals_json, closed_by, closed_at | |
| `notification_recipients` | name, phone, email, channel, types (JSON), active | |
| `outbox` | id, idem_key (UNIQUE), channel, to_addr, type, subject, body, attachment_path, status (`pending/sent/failed/not_configured`), attempts, next_attempt_at, last_error, sent_at | |
| `settings` | k, v | |
| `audit_log` | at, user_id, actor_label, action, entity, entity_id, before_json, after_json, reason | |

---

## 5. Faze i kriteriji prihvatanja

| Faza | Sadržaj | Kriteriji prihvatanja |
|---|---|---|
| 1 | Nalozi, ovlaštenja, šest staza, kalendar | registracija s kodom (kod vidljiv u razvojnom prikazu poruka), prijava telefonom, oporavak, ograničenje pokušaja, povezivanje s ručnim profilom; matrica radi na serveru (403 bez ovlaštenja); kalendar 6 staza bez horizontalnog pomjeranja na 360 px |
| 2 | Rezervacije i javni pregled | gost rezerviše 1–6 staza, auto-dodjela, izmjena, otkaz, produženje; konflikti odbijeni i pod istovremenim upisom; javni kalendar bez privatnih podataka |
| 3 | Dolasci, gotovina, patike, dnevni obračun | pitanje o patikama obavezno; cijene 15/22,50/30 i minimum; nedolazak 1 = upozorenje, 2 = dug; oprost s tragom; dan se ne zaključuje uz prepreke |
| 4 | Mjesečne, VIP, bonus | limit sedmice pon–ned, buduće rezervacije troše limit, kombinovano pokriće, otkaz Arene = 2× bonus jednom, nedolazak = propadanje bez duga |
| 5 | Klubovi, serije, utakmice, obračuni | trening na račun kluba; serija bez beskonačnih redova, konflikti s preskakanjem; obračun samo završenih stavki; izdvojena utakmica; nemoguć dvostruki obračun; PDF + email u redu |
| 6 | Turniri, kvalifikacije, javni nastupi | blokada zaustavlja online rezervacije; konflikti prije potvrde; nastupi 75 min bez lažnih konflikata; javni raspored i telefon za prijave |
| 7 | Obavijesti, statistika, završne provjere | red s ponovnim pokušajima i bez dupliranja; „nije poslano“ dok kanal nije povezan; statistika s poređenjem; backup + provjera oporavka; svi testovi prolaze |

---

## 6. Stvarno otvorene odluke (s predloženim podrazumijevanim rješenjem — implementirano je podrazumijevano)

1. **SMS servis za kodove i obavijesti.** Bez njega registracija i oporavak lozinke ne mogu raditi u produkciji. *Podrazumijevano:* razvojni prikaz poruka; prije objave odabrati dobavljača (npr. Infobip/Twilio za SMS; Viber/WhatsApp za obavijesti) — adapter je spreman.
2. **Email za obračune klubova.** *Podrazumijevano:* SMTP sanduče na arenabrcko.com (npr. `obracuni@arenabrcko.com`, Hostinger mail); dok nije podešeno, email s PDF-om čeka u redu sa statusom „nije poslano“.
3. **Nepokriveni dio termina uz mjesečnu** (npr. 2 h rezervacija, 1,5 h pokriveno): *implementirani prijedlog, NIJE potvrđena odluka vlasnika* — 7,50 KM po započetih 30 min, **bez** minimuma od 15 KM (minimum važi samo za stazu bez ikakvog pokrića). Podesivo u Cijenama (iznos i minimum); primjeri u 2.4.
4. **Produženje mjesečne prije isteka.** Po specifikaciji: novi period počinje danom potvrđene uplate; preostali dani starog perioda ne prelaze u novi. Prije potvrde se prikazuje novi istek i upozorenje (obavezna potvrda). Pravilo nije mijenjano; alternativa ostaje odluka vlasnika.
5. **Nedolazak na rođendan / utakmicu:** specifikacija propisuje dug samo za rekreaciju. *Podrazumijevano:* rođendan bez automatskog duga (osoblje može evidentirati ručno), utakmica/trening nemaju nedolazak.
6. **Trajanje online rezervacije duže od 120 min:** *podrazumijevano* gost bira 60/90/120 (i produžava ako ima mjesta); osoblje može unijeti bilo koje trajanje u koracima od 30 min.
7. **Objava:** proba radi na `proba.brckoworldcup.com` (arenabrcko.com je parkiran domen). Konačna objava na arenabrcko.com i prelazak dugmeta sa Sportina — tek uz odobrenje vlasnika.
8. **Odvojena lokacija probe** (da objava glavnog sajta ne briše fajlove probe): prijedlog u `docs/OBJAVA.md` (zaseban sajt na postojećem Premium paketu ili nadogradnja).
9. **Baza na hostingu:** MySQL (preporuka za produkciju) ili SQLite (trenutno za probu).
