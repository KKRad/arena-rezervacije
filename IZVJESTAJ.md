# Sistem rezervacija Arene Bakarni — izvještaj revizije (12.11.2026)

Proba: https://aliceblue-eagle-979980.hostingersite.com (zaseban sajt; stara adresa proba.brckoworldcup.com zamijenjena) · Kod: `SAJTOVI/arena-rezervacije` · Ništa nije objavljeno na arenabrcko.com; dugme „Rezerviši stazu“ i dalje vodi na Sportin.
Raniji izvještaj („sve završeno“) tretiran je kao tvrdnja i provjeren. Nađeni nedostaci su popravljeni (tačka 2).

## 1. Šta je provjereno i kojim dokazom

Dokazi: **T** = automatski test (`php tests/run.php` i `DB=sqlite php tests/run.php`, 224 provjere svaki) · **P** = provjera na probnom sajtu preko pravog API-ja (`php tools/proba-uloge-check.php`, 28 provjera) · **B** = ručno u pregledniku (računar i telefon 375 px) · **S** = provjera servera (`curl`, backup alat).

| Pravilo | Rezultat | Dokaz |
|---|---|---|
| Javno: kategorija svakog termina, klubovi i liga utakmice | ✓ | P, B |
| Javno: ime i prezime igrača, staza, vrijeme nastupa (kvalifikacije/turnir), slobodna mjesta, javni „Telefon za prijave“ | ✓ (11 slobodnih mjesta + upisani igrač) | P, B, T |
| Slobodno mjesto kvalifikacija se ne može rezervisati online | ✓ (409) | P, T |
| Privatno: telefoni gostiju, email, naplata, dugovi, imena gostiju rekreacije | ✓ nisu u javnom odgovoru | P, T |
| 6 staza na telefonu bez horizontalnog pomjeranja; današnji dan; strelice, izbor datuma; > mjesec unaprijed | ✓ (scrollWidth 375 = širina; 40 dana unaprijed) | B, P |
| Serija: zahtjev čeka, ne čuva mjesto; ponovna provjera pri odobrenju; zamjenska staza; konflikt samo kad nema nijedne; prihvatanje bez datuma; ništa se ne prepisuje; bez beskonačnih zapisa | ✓ | T (2 testa), B |
| Pregled serije prikazuje promijenjene staze i preskočene datume | **nedostajalo → dodano** | T, B |
| Mjesečna 50 KM, 2 h sedmično pon–ned; VIP podesiv; uplata u petak = puna 2 h; buduće rezervacije troše pravo bez dvostrukog trošenja; redoslijed redovni → bonus → gotovina | ✓ | T |
| Bonus bez isteka, i bez aktivne mjesečne; otkaz Arene = ukupno 2× (redovni sat se ne vraća, ponovna kompenzacija nemoguća) | ✓ | T |
| Produženje prije isteka: novi istek + upozorenje prije potvrde | **nedostajalo → dodano** (server traži izričitu potvrdu) | T, B |
| Klubovi: nema evidencije uplata; održano, neobračunato, arhiva, obračun jedne utakmice, email s PDF-om; zbir od nule; stavka se ne može ponovo obračunati; „Obračunato/Neobračunato“; klupska vrijednost odvojena od gotovine | ✓ (UNIQUE na stavci obračuna) | T, P |
| PDF obračuna s našim slovima | ✓ (popravljeno ranije danas) | B |
| Gotovina za rekreaciju, patike, mjesečne; obavezno pitanje o patikama; 15 / 22,50 / 30 KM; 30 min = najmanje 15 KM | ✓ | T, B |
| Jedan dnevni obračun (zajednički nalog Šank Arena) | ✓ | T, P |
| Dan se ne zaključuje uz završene neobrađene termine | ✓ | T |
| Budući i tekući termini te klupski treninzi ne blokiraju zaključenje | **pogrešno → popravljeno** | T |
| Ispravke naplate: storno + trag + ovlaštenje | ✓ | T, P |
| Ovlaštenja po nalogu (vlasnik bira), provjera na serveru | ✓ 24 interna poziva odbijena gostu i anonimnom; Šank/menadžer odbijeni za vlasnička prava; dodijeljeno pravo radi | T, P |
| Više primalaca obavijesti, izbor vrsta po primaocu | ✓ | T, B |
| Proba: ne šalje SMS; kod jasno označen kao probni; neisporučeno nikad „poslano“ | ✓ (oznaka „PROBNI KOD“ dodana) | T, B |
| Produkcija: kod se ne prikazuje; registracija isključena dok SMS nije povezan i izričito uključen | **nedostajalo → dodano** | T |
| Istovremene rezervacije iste staze | ✓ 4 procesa × 5 rundi: uvijek tačno jedan uspije (MySQL i SQLite) | T |
| Backup: pravi se, preuzima i **vraća** u odvojeno okruženje | ✓ backup sa servera vraćen lokalno, integrity_check ok, aplikacija radi nad kopijom | S |
| Nova objava probe ne briše podatke | ✓ podaci od 20.11. ostali nakon objava (stara i nova proba) | S |
| Proba na odvojenoj lokaciji (objava glavnog sajta je ne dira) | ✓ zaseban sajt na Premium paketu, podaci izvan web foldera | S |
| Baza, postavke, backup nisu javni | ✓ 403 za config.php, storage/, app/, tools/; baza i backup izvan foldera sajta | S |

## 2. Šta je popravljeno (ova revizija)
1. **Zaključivanje dana:** blokiraju samo završeni lični termini bez ishoda/pokrića. Budući i tekući termini i klupski treninzi ne blokiraju. Uplata nakon zaključenja ide u sljedeći otvoreni dan; zaključeni obračun se nikad ne mijenja.
2. **Serije:** pregled prije odobrenja prikazuje datume sa zamjenskom stazom; novi „Pregled“ serije prikazuje upisane termine (zamjenska staza označena), preskočene datume s razlogom i do kada su termini upisani.
3. **Mjesečna:** prije potvrde prikaz novog perioda; kod produženja prije isteka upozorenje koliko dana propada i obavezna potvrda (i na serveru).
4. **Nepokriveni dio termina uz mjesečnu:** iznos je sada **podesiv** u Cijenama (po 30 min + opcioni minimum), s primjerima; označen kao otvorena odluka. Pravilo nije mijenjano (i dalje 7,50 KM / 30 min, bez minimuma).
5. **Produkcija:** registracija/oporavak lozinke isključeni dok SMS nije povezan i izričito uključen; kod se nikad ne vraća na ekran u produkciji.
6. **Probni kod** jasno označen „PROBNI KOD (samo probno okruženje)“.
7. **Proba:** postavke se mogu držati izvan foldera sajta; svaka objava pravi arhivu za vraćanje; backup se može napraviti i preuzeti (samo vlasnik) i provjeriti alatom `tools/proba-backup-check.php`.
8. **Javni prikaz:** dodir na tuđi termin otvara javni detalj (utakmica, liga, vrijeme, staze); natpis kvalifikacija više ne proviruje između pozicija.
9. **Testovi:** +48 ciljanih provjera (istovremeno rezervisanje u odvojenim procesima, zamjenska staza, rano produženje, podesiv ostatak, zaključivanje dana, pristup bez ovlaštenja, produkcijska zaštita).

## 3. Šta prolazi i šta nije testirano
**Prolazi:** 224/224 (MySQL), 224/224 (SQLite), 28/28 na probnom sajtu, provjera oporavka backupa (dvije kopije, uključujući kopiju napravljenu prije izmjena).
**Nije testirano / ograničenja:**
- Stvarno slanje SMS-a i emaila (kanali nisu odabrani); slanje je testirano samo do „nije poslano“ i ponovnih pokušaja.
- MySQL na Hostingeru (proba radi na SQLite; MySQL je testiran lokalno na MariaDB 13).
- Vizuelni izgled PDF-a u svim pregledačima (provjeren u jednom; tekst se ispisuje standardnim fontom, dijakritici linijama).
- ~~Objava glavnog sajta može obrisati fajlove probe~~ — **riješeno**: proba je od 12.11.2026 zaseban sajt na drugom Hostinger paketu (Premium, besplatna adresa, bez DNS izmjene); podaci prebačeni, uloge 28/28 i oporavak backupa provjereni na novom sajtu.
- Ručna provjera prijavljenih uloga u pregledniku urađena je lokalno (isti kod); na probnom sajtu prijavljene uloge provjerene su preko API-ja (lozinke se ne unose ručno u pregledač).

## 4. Preostale odluke vlasnika
1. SMS servis za kodove i obavijesti (bez njega nema javne registracije u produkciji).
2. Email za obračune klubova i email adrese KK Rad, ŽKK Olimp, KK Zrinski.
3. **Nepokriveni dio termina uz mjesečnu** — prijedlog: 7,50 KM po započetih 30 min, bez minimuma. Primjeri: 2 h termin, 1,5 h iz mjesečne → 7,50 KM; 1,5 h termin, 30 min iz mjesečne → 15,00 KM; staza bez pokrića → obična rekreacija (najmanje 15 KM). Podesivo u Cijenama.
4. Produženje mjesečne prije isteka — implementirano po specifikaciji (novi period od dana uplate, preostali dani propadaju), uz upozorenje. Ostaje na vlasniku da li želi drugačije.
5. Nedolazak na rođendan — prijedlog: bez automatskog duga.
6. Baza na hostingu (MySQL) ili nastavak sa SQLite za probu.
7. Gašenje ili preusmjeravanje stare probe (proba.brckoworldcup.com) i eventualna ljepša adresa za novu (npr. proba.arenabrcko.com — jedan DNS zapis).
8. Datum objave na arenabrcko.com i prelaska dugmeta sa Sportina.

## 5. Uslovi prije produkcijske objave
1. Vlasnik pregledao probu i odobrio objavu.
2. SMS kanal povezan i provjeren na stvarnom broju; tek tada `registration_enabled => true`.
3. SMTP sanduče povezano; probni obračun stigao na email s PDF-om.
4. MySQL baza na hostingu (lozinku upisuje vlasnik), `env => prod`, `cookie_secure => true`, `show_dev_codes` isključeno.
5. Kod izvan `public_html`, u `public_html` samo `_rs/` + jedno `.htaccess` pravilo; postupak objave glavnog sajta dopunjen da ne briše `_rs/`.
6. Web-cron za produkcionu adresu; provjereno da se pokreće.
7. Backup napravljen, preuzet i vraćen u odvojeno okruženje (alat postoji).
8. Svi testovi prolaze na verziji koja se objavljuje; arhiva prethodne verzije sačuvana.
9. Otvorene odluke 3–5 potvrđene (ili prihvaćen prijedlog).
