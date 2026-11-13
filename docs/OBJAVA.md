# Objava — plan za arenabrcko.com i postupak za probu (NIŠTA NIJE OBJAVLJENO NA arenabrcko.com)

## Zašto ovaj hosting
arenabrcko.com je parkiran na Hostinger sajtu `brckoworldcup.com` (nalog u270359984, **Single Web Hosting**). Paket podržava PHP 8, MySQL, web-cron i SSL, ali ne Node.js ni CLI cron (provjereno). Zato je aplikacija u PHP-u i radi na postojećem paketu, bez doplate.

## Produkcija — koraci (tek uz odobrenje vlasnika; uslovi su u IZVJESTAJ.md tačka 5)
1. **Baza:** preporuka MySQL — hPanel → Baze podataka → nova MySQL baza + korisnik (lozinku upisuje vlasnik, ne šalje se u chat). Prenos podataka iz probe (ako se do tada nešto stvarno unese): `php tools/sqlite-to-mysql.php <probna_sqlite_kopija> <mysql_baza> <host>` — prenosi SVE tabele (korisnici/ovlaštenja, rezervacije/serije, mjesečne/bonus, gotovina, klubovi/obračuni, historija/red poruka), ispisuje brojeve redova izvor→cilj i stvarno pokreće aplikaciju nad prenesenom bazom prije nego što se bilo šta uključi. Izvorna baza se pritom ne dira — ako nešto ne valja, produkcija jednostavno nastavlja na staroj bazi. Nastavak rada na SQLite je i dalje moguć (sistem radi na oba), ali MySQL je čvršći izbor za pravi promet.
2. **Kod izvan public_html:** `/home/u270359984/domains/brckoworldcup.com/arena-rezervacije/` (bez `public/`, `tests/`, `config.local.php`, `storage/`).
3. **Email (umjesto SMS-a) — registracija i oporavak lozinke:**
   - Poveži postojeći email nalog (Gmail, Hostinger mail i sl.) preko SMTP-a — ne treba se kupovati poseban servis. U `config.php` na serveru (vlasnik ga sâm uređuje ili diktira lozinku Claude-u u terminalu, nikad u chatu):
     ```php
     'channels' => ['email' => ['adapter' => 'smtp', 'host' => '...', 'port' => 465, 'user' => '...', 'pass' => '...', 'from' => 'rezervacije@arenabrcko.com']],
     'registration_enabled' => true,
     ```
   - Dok ovo nije podešeno, registracija i oporavak lozinke ostaju isključeni (provjereno testom) — rezervacije i dalje rade za postojeće naloge i za goste koje unosi osoblje.
   - Isti kanal (`channels.email`) koristi se i za slanje klupskih obračuna (PDF prilog) i za oporavak lozinke — jedno podešavanje pokriva oboje.
   - Napomena: obavijesti gostima i osoblju o novim/otkazanim terminima (SMS/Viber/WhatsApp) namjerno su ostavljene za kasnije — nisu uslov da sistem proradi za registraciju i rezervisanje.
4. **Migracije i vlasnik:** phpMyAdmin → uvoz `migrations/001_init.sql`, `002_ostatak_mjesecne.sql`, `003_email_potvrda.sql` (ili ih `tools/sqlite-to-mysql.php` iz koraka 1 already primijeni ako se koristi taj put). Vlasnik lokalno `php tools/owner-sql.php` (lozinka skriveno) i zalijepi ispis u phpMyAdmin.
5. **public_html:** `_rs/` + pravilo iz `public/_rs/htaccess-pravila.txt`. **Objava glavnog sajta briše public_html** → paket glavnog sajta mora sadržavati `_rs/` i pravilo (mijenja se zajedno s produkcijskom objavom, uz odobrenje).
6. **Web-cron** (hPanel → Advanced → Cron Jobs → Custom, `*/5 * * * *`): `wget -q -O /dev/null https://arenabrcko.com/rezervacije/api/cron`.
7. **Provjera prije uključivanja za javnost:** `php tools/restore-drill.php` nad svježim backupom produkcione baze (stvaran HTTP tok iz vraćene kopije, ne samo brojevi), zatim ručno: registracija sa stvarnim emailom, rezervacija, naplata, zaključenje dana, obračun kluba s emailom.
8. **Sportin dugme:** tek uz odobrenje vlasnika.
9. **Telegram (razmatrano, nije urađeno sada):** vlasnik je u ranijoj specifikaciji spomenuo Telegram kao mogući kanal. Arhitektura obavijesti je već zamjenjiva (`Notify::channel()`), pa bi Telegram bot bio samo još jedan adapter (Telegram Bot API je obično HTTPS POST — uklapa se u postojeći `HttpChannel`). Nije implementiran u ovoj reviziji jer je, po dogovoru, sav rad oko obavijesti (SMS/Viber/WhatsApp/Telegram svim primaocima) svjesno ostavljen za kasnije — prioritet je bio da registracija i rezervisanje rade. Kad se odluči kanal (Telegram ili drugi), dodavanje je mala izmjena.

## Probno okruženje — https://aliceblue-eagle-979980.hostingersite.com (zaseban sajt od 12.11.2026)

Proba je **zaseban sajt** na postojećem Hostinger **Premium** paketu (nalog u310836653, isti paket kao bakarnifakture.com), s besplatnom Hostinger adresom — bez promjene DNS-a i bez diranja glavnog sajta. Objava glavnog sajta (brckoworldcup.com / arenabrcko.com, drugi nalog u270359984) **više ne može obrisati probu**.
Kasnije se može dodati ljepša adresa (npr. `proba.arenabrcko.com` — jedan DNS zapis, uz odobrenje vlasnika).

### Lokacije
| Šta | Gdje | Briše li ga objava probe? | Javno? |
|---|---|---|---|
| Kod probe | `/home/u310836653/domains/aliceblue-eagle-979980.hostingersite.com/public_html/` | da (zamjenjuje se novom verzijom) | samo `_rs/` i slike; ostalo 403 (provjereno) |
| Baza (SQLite) | `…/aliceblue-eagle-979980.hostingersite.com/proba-data/arena-rs.sqlite` | **ne** (provjereno ponovnom objavom) | ne (izvan web foldera) |
| Backup, PDF obračuni | `…/proba-data/backup/`, `…/proba-data/pdf/` | ne | ne |
| Tajne postavke (npr. MySQL lozinka ubuduće) | `…/proba-data/config.php` (ako postoji, ima prednost) | ne | ne |
| Probne lozinke | lokalno: `PROBA-NALOZI.txt`, `storage/proba-seed.json` (nisu u gitu) | — | — |

Podaci stare probe (proba.brckoworldcup.com) preneseni su backupom pri prvom pokretanju (`--import`), zajedno s probnim nalozima — prijave su iste.

### Web-cron (dodaje vlasnik u hPanelu — auto-mode ne dozvoljava izmjenu cron poslova)
hPanel → Websites → **aliceblue-eagle-979980.hostingersite.com** → Advanced → Cron Jobs → Custom, „Once Per Five Minutes“:
`wget -q -O /dev/null https://aliceblue-eagle-979980.hostingersite.com/rezervacije/api/cron`

### Objava nove verzije probe
1. `php tests/run.php` i `DB=sqlite php tests/run.php` — moraju proći.
2. **Kopija prije objave:** `php tools/proba-backup-check.php` (backup na serveru → preuzimanje → vraćanje u odvojenu lokalnu bazu).
3. `php tools/package-proba.php` → `build/proba/` + arhiva `build/archive/proba-<datum>-<git>.zip` (podrazumijevano za ovaj sajt).
4. Hostinger deploy **samo** na `aliceblue-eagle-979980.hostingersite.com` (direktorij `build/proba`).
5. `php tools/proba-uloge-check.php <slobodan datum>` — uloge i javni prikaz na probi (adresa se može promijeniti s `PROBA_URL=…`).

### Vraćanje prethodne verzije
- **Kod:** deploy arhive iz `build/archive/` (tip „static“, putanja do .zip) ili hPanel → File Manager → otpakovati u `public_html/`. Git oznaka `proba-2026-11-12-prije-revizije` = verzija prije revizije.
- **Baza:** migracije samo dodaju kolone, pa stariji kod radi s novijom bazom. Vraćanje podataka: preuzeti backup (`php tools/proba-backup-check.php --preuzmi <ime>`), u File Manageru raspakovati `.sqlite.gz` i zamijeniti `proba-data/arena-rs.sqlite` (prije toga sačuvati postojeću).

### Backup i provjera oporavka
- Automatski: web-cron jednom dnevno (poslije 03:00) → `proba-data/backup/arena-rs-*.sqlite.gz`, čuva se 30 dana.
- Ručno: Administracija → Obavijesti/Backup (vlasnik) ili `POST /rezervacije/api/backup/run`; lista i preuzimanje: `GET /rezervacije/api/backup/list` i `.../backup/download?file=...` (samo vlasnik, provjereno da je stvarno zaštićeno).
- **Provjera vraćanja (dvostepena):**
  1. `php tools/proba-backup-check.php` — preuzme backup sa servera, raspakuje ga u ODVOJENU lokalnu bazu, `PRAGMA integrity_check`, broj redova po tabeli, i direktan upit nad tom bazom (brzo, ali samo na nivou baze).
  2. `php tools/restore-drill.php` — ide korak dalje: iz te vraćene kopije podigne STVARAN privremeni web server (nov port, novi privremeni folder) i preko pravog HTTP-a uradi prijavu, otvori administraciju, dnevni obračun, detalj rezervacije i arhivu obračuna — dokaz da APLIKACIJA radi iz backupa, ne samo da su brojevi tačni. Server i privremeni folder se automatski gase/brišu na kraju; prava proba i pravi backup se ne diraju.
  Oba su provjerena 12–13.11.2026 na probi (uključujući i poslije revizije): „OPORAVAK PROVJEREN“ / „RESTORE DRILL USPJEŠAN“.

### Stara proba (proba.brckoworldcup.com)
Zamijenjena novim sajtom. I dalje radi (ista verzija koda, vlastiti podaci u `domains/brckoworldcup.com/proba-data/` i vlastiti web-cron) dok vlasnik ne odobri gašenje/preusmjeravanje; podaci uneseni tamo nakon selidbe ne prelaze automatski.
