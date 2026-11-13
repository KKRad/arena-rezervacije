# Sistem rezervacija Arene Bakarni — završni izvještaj (13.11.2026)

Proba (zaseban sajt, van dometa objave glavnog sajta): **https://aliceblue-eagle-979980.hostingersite.com**
Kod: `SAJTOVI/arena-rezervacije`. Ništa nije objavljeno na arenabrcko.com; dugme „Rezerviši stazu“ i dalje vodi na Sportin; stara proba (DNS/glavni sajt) nije dirana.

Ovaj izvještaj pokriva osam tačaka koje je vlasnik tražio u posljednjem krugu provjere. Po dogovoru usred rada, **šira dorada obavijesti (SMS/Viber/WhatsApp svim primaocima) je svjesno ostavljena za kasnije** — prioritet ovog kruga je bio da registracija i rezervisanje rade, što je potvrđeno.

---

## 1. Šta je završeno i popravljeno

### 1) Email umjesto SMS-a za registraciju i oporavak lozinke
- Registracija i oporavak lozinke sada idu preko **potvrđenog emaila** (kod se šalje na email, ne SMS-om). **Prijava ostaje nepromijenjena: telefon + lozinka.**
- Povezivanje postojećeg emaila (bez kupovine servisa): SMTP podaci idu u `config.php` na serveru (`channels.email`) — vlasnik ih unosi sâm na serveru ili diktira lozinku u terminalu, nikad u chatu. Isti kanal se koristi i za slanje obračuna klubovima.
- Svaki klub (KK Rad, ŽKK Olimp, KK Zrinski) ima svoje polje za email obračuna (već postojalo, provjereno da i dalje radi).
- Ako klub nema unesen email: obračun se **ne tvrdi kao poslan**, ali ostaje trajno sačuvan (PDF + stavke) i dostupan za naknadno slanje — dodano novo dugme „Pošalji email“ koje radi bilo kada, **bez ponovnog obračunavanja stavki** (iste stavke, isti iznos, samo se pokuša slanje).
- Migracija baze 003 dodaje `email_verified_utc`; postojeći nalozi koji su ranije bili potvrđeni telefonom ostaju potvrđeni (ne moraju se ponovo registrovati).

### 2) Mjesečne — potvrđeno pravilo, bez izmjena
Novi period počinje danom evidentirane uplate (nepromijenjeno). Pri ranom produženju ekran prije potvrde prikazuje: trenutni datum isteka, novi period važenja, upozorenje da preostali dani prethodnog perioda propadaju, i traži obaveznu potvrdu — **server odbija aktivaciju bez nje** (ne samo ekran).

### 3) Dvije otvorene poslovne odluke — jasno označene, podesive, s primjerima
Obje su u ekranu **Administracija → Cijene**, s oznakom „otvorena odluka“ i uživo izračunatim primjerom — nisu predstavljene kao odluka vlasnika:
- **Nepokriveni dio termina uz mjesečnu:** trenutno 7,50 KM po 30 min, **bez** minimuma od 15 KM. Primjer na ekranu: *2 h termin, 1,5 h iz mjesečne → nepokrivenih 30 min = 7,50 KM; 1,5 h termin, 30 min iz mjesečne → nepokrivenih 60 min = 15,00 KM.* Iznos i minimum su polja koja vlasnik može promijeniti (verzionisano — stare rezervacije zadržavaju staru cijenu).
- **Nedolazak na rođendan:** trenutno **bez** automatskog duga — sada i stvarno **podesivo** (prekidač), isključeno podrazumijevano. Primjer na ekranu, izračunat iz trenutnog cjenovnika: *rođendan na 3 staze, 2 h, nedolazak → ako se uključi, dug 90,00 KM (ista formula kao rekreacija).*

### 4) MySQL i očuvanje podataka
- Sistem radi i na MySQL i na SQLite bez izmjene koda (kao i ranije).
- Novi alat `tools/sqlite-to-mysql.php` prenosi **cijelu probnu bazu** u MySQL: korisnike i ovlaštenja, rezervacije i stalne termine, mjesečne i bonus sate, gotovinsku evidenciju, klubove/stavke/arhivu obračuna, historiju promjena i red obavijesti — i to tako da **izvorna SQLite baza ostaje netaknuta** (samo se čita).
- Stvarno izvršeno danas nad svježim backupom žive probe: svih 32 tabele prenesene s tačnim brojem redova (npr. 11 rezervacija, 428 zauzetih blokova, 3 uplate/71 KM, 1 mjesečna karta, 28 ovlaštenja) — **ne samo brojevi**, nego i stvaran rad aplikacije nad prenesenom bazom (prijava vlasnika, kalendar, dnevni obračun) preko posebnog probnog skripta unutar istog alata.
- Povratak na prethodno stanje: nije ni potreban — izvor nije mijenjan ni u jednom koraku; ciljna MySQL baza je potpuno odvojena i briše se bez posljedica.

### 5) Odvajanje probe i backup
- Probna aplikacija je **zaseban sajt** (aliceblue-eagle-979980.hostingersite.com, drugi Hostinger paket) — objava glavnog sajta je više **ne može** obrisati (provjereno ranije ovog mjeseca ponovnom objavom glavnog sajta).
- Baza, backup i PDF obračuni su **izvan** javno dostupnog foldera sajta (403 na probu).
- DNS nije mijenjan, stara proba (proba.brckoworldcup.com) nije ugašena niti preusmjerena — čeka odobrenje vlasnika.
- **Pravi restore drill** (ne samo "backup postoji i cron radi"): novi alat `tools/restore-drill.php` iz vraćene kopije backupa diže **stvaran privremeni web server** na novom portu i preko pravog HTTP-a radi prijavu, otvara administraciju, dnevni obračun, detalj rezervacije i arhivu obračuna — tek to dokazuje da se iz backupa može **raditi**, ne samo čitati brojke. Server i privremeni fajlovi se sami uklanjaju; izvorna proba i njen backup se ne diraju. Izvršeno danas: uspješno (7/7 provjera).

### 6) Ponovna provjera ranije dogovorenih funkcija
Sve stavke s liste su ponovo provjerene (vidi tabelu u odjeljku 2) i rade; dvije nove su popravljene u ovom krugu (klupski obračun bez emaila, rođendanski dug kao prekidač). Nijedan ranije popravljen nedostatak se nije vratio (puni paket testova ponovo prolazi).

### 7) PDF i pregledači
- Napravljen i vizuelno provjeren obračun s **40 stavki na 2 strane** (prelom tabele, ponovljeno zaglavlje, ukupan iznos na drugoj strani) — č, ć, đ, š, ž, navodnici i crtica ispravni (klub nazvan namjerno „KK "Rad" Brčko — Čika Đoko" radi testa).
- Provjereno u **tri nezavisna PDF motora**: Claude-ov PDF prikaz (slikovno, oba primjerka ispravna), `pdftotext`/Poppler (tekst se ispravno izvlači sa svim dijakritikom — bitno za pretragu/kopiranje), i Chrome-ov ugrađeni PDF prikazivač (PDF.js) — otvara dokument, prepoznaje obje strane.
- Preuzimanje privitka provjereno na pravoj probi preko HTTP-a (zaglavlja `Content-Type`/`Content-Disposition: attachment`, ispravna gzip datoteka).
- **Safari nije doslovno testiran** (nema automatskog pristupa Safariju iz ovog okruženja) — PDF je standardan (Type1 Helvetica, WinAnsi), pa rizik je nizak, ali preporučujem da vlasnik jednom otvori jedan obračun u Safariju na telefonu prije objave.

### 8) Priprema objave na arenabrcko.com
Plan je u `docs/OBJAVA.md` i obuhvata redom: probnu adresu (gotovo), email (uputstvo za povezivanje), bazu (MySQL + alat za prenos), backup (automatski + ručna provjera), **Telegram** (razmotren — tehnički bi bio samo još jedan zamjenjivi kanal obavijesti, namjerno nije rađen sada jer su obavijesti odložene), i tačan postupak vraćanja na prethodnu verziju (arhiva + git oznaka). Ništa od ovoga nije izvršeno na glavnoj domeni — samo pripremljeno i dokumentovano.

---

## 2. Šta je stvarno testirano — s rezultatima

| Provjera | Rezultat |
|---|---|
| Automatski testovi (poslovna pravila) — MySQL | **242/242** |
| Isto, SQLite | **242/242** |
| Uloge i javni prikaz na živoj probi preko pravog API-ja (`proba-uloge-check.php`) | **28/28** |
| Registracija emailom → potvrda → rezervacija, uživo preko HTTP-a na probi (ne lokalno) | **uspjeh** (nova korisnica registrovana, odmah rezervisala termin) |
| Istovremeno rezervisanje iste staze — 4 odvojena operativna sistemska procesa, 5 rundi | uvijek tačno **jedan** uspije, na MySQL i SQLite |
| Prenos probne baze u MySQL (sve kategorije) + stvaran rad aplikacije nad prenesenom bazom | **uspjeh**, brojevi se poklapaju, prijava i upiti rade |
| Restore drill — stvaran HTTP server iz vraćenog backupa | **7/7**, uspješno |
| Backup — napravljen na serveru, preuzet, vraćen i provjeren (`PRAGMA integrity_check`, brojevi redova) | **OK** |
| Nova objava probe ne briše podatke (baza/backup izvan foldera sajta) | potvrđeno ponovnom objavom |
| PDF — dijakritici, prelom na 2 strane, tačni iznosi | potvrđeno u 3 motora (Claude prikaz, Poppler, Chrome PDF.js) |
| PDF — preuzimanje privitka (zaglavlja, ispravan sadržaj) | potvrđeno preko HTTP-a na probi |
| Pristup bez ovlaštenja (gost/anoniman/Šank/menadžer na tuđa prava) | 24+ poziva ispravno odbijeno (401/403) |

**Nije testirano / ograničenja:**
- Stvarno slanje emaila (SMTP nije povezan) — testirano do statusa „nije poslano“, ispravno se i prikazuje kao takvo.
- MySQL na samom Hostingeru (testirano lokalno, na istom MariaDB-u koji se i inače koristi za razvoj).
- PDF u Safariju — nije doslovno testiran (vidi tačku 7 iznad).
- Telegram i šira obavijest (SMS/Viber/WhatsApp) — namjerno odloženo, nije rađeno.

---

## 3. Šta čeka podatke/odluku vlasnika
1. **SMTP podaci** za email (host, port, korisnik, lozinka, pošiljalac) — da bi registracija i obračuni stvarno slali poštu.
2. **Email adrese triju klubova** (polje već postoji u profilu kluba — samo ih treba unijeti).
3. Odluka o bazi za produkciju: **MySQL** (preporuka) ili nastavak na SQLite.
4. Da li i kada preusmjeriti/ugasiti staru probu (proba.brckoworldcup.com) i da li joj dodijeliti ljepšu adresu (npr. `proba.arenabrcko.com` — jedan DNS zapis).
5. Datum objave na arenabrcko.com i trenutak prelaska dugmeta sa Sportina.
6. Da li i kada uvesti SMS/Viber/WhatsApp/Telegram za obavijesti gostima i osoblju (odloženo po dogovoru — sistem radi i bez toga).

## 4. Dvije preostale poslovne odluke (s primjerima)
1. **Nepokriveni dio termina uz mjesečnu** — *prijedlog, nije potvrđena odluka*: 7,50 KM po započetih 30 min, bez minimuma.
   - *Primjer A:* termin 2 h, mjesečna pokriva 1,5 h → nepokrivenih 30 min = **7,50 KM**.
   - *Primjer B:* termin 1,5 h, mjesečna pokriva 30 min → nepokrivenih 60 min = **15,00 KM**.
   - Podesivo u Administracija → Cijene (iznos i opcioni minimum), odvojeno po verziji cjenovnika.
2. **Nedolazak na rođendan** — *prijedlog, nije potvrđena odluka*: bez automatskog duga.
   - *Primjer:* rođendan na 3 staze, 2 h, gost ne dođe → ako bi se naplaćivalo kao rekreacija, dug bi iznosio **90,00 KM**. Trenutno se ništa ne evidentira automatski; osoblje može ručno, po nahođenju.
   - Sada stvarno podesivo prekidačem u Cijenama (isključeno podrazumijevano).

## 5. Tačno šta ostaje prije produkcijske objave
1. Vlasnik pregledao probu i dao saglasnost za objavu.
2. Email (SMTP) kanal povezan i provjeren na stvarnoj adresi; tek tada se uključuje `registration_enabled`.
3. Email adrese klubova unesene; probni obračun stigao na email s PDF prilogom.
4. Odluka o bazi (MySQL ili SQLite) sprovedena; ako MySQL — prenos alatom i provjera (postupak gotov, samo ga treba primijeniti na produkcione podatke kad ih bude).
5. Kod postavljen izvan `public_html`, u `public_html` samo `_rs/` + jedno `.htaccess` pravilo; paket glavnog sajta dopunjen da ga ne briše.
6. Web-cron na produkcionoj adresi postavljen i provjeren da radi.
7. Backup napravljen, preuzet i **restore drill** uspješno izveden na produkcionim podacima prije nego što se sistem otvori javnosti.
8. Svi testovi (242) prolaze na verziji koja se objavljuje; sačuvana arhiva prethodne verzije za brzo vraćanje.
9. Dvije otvorene odluke (odjeljak 4) potvrđene ili svjesno ostavljene na trenutnim prijedlozima.
10. Dugme „Rezerviši stazu“ mijenja se sa Sportina tek nakon što vlasnik to izričito zatraži.
