# Objava na arenabrcko.com — plan (NIŠTA NIJE OBJAVLJENO; svaki korak tek uz odobrenje vlasnika)

## Zašto ovaj hosting
arenabrcko.com je parkiran na Hostinger sajtu `brckoworldcup.com` (nalog u270359984, **Single Web Hosting**). Paket podržava PHP 8, MySQL, cron i SSL, ali ne Node.js. Zato je aplikacija napisana u PHP-u i radi na postojećem paketu, na istoj domeni, bez doplate i bez selidbe.
Ako se kasnije pokaže potreba za više resursa: nadogradnja na Business paket istog naloga (domena i adrese ostaju iste).

## Koraci
1. **Baza (Hostinger hPanel → Baze podataka):** nova MySQL baza + korisnik (npr. `u270359984_rezerv`). Lozinku generiše/upisuje vlasnik u hPanelu; ne šalje se u chat.
2. **Kod izvan public_html:** folder `/home/u270359984/domains/brckoworldcup.com/arena-rezervacije/` ← sadržaj ovog projekta bez `public/`, `tests/`, `config.local.php`, `storage/`.
3. **config.php** u tom folderu (na serveru, ne u gitu):
   ```php
   <?php return ['env' => 'prod', 'db' => ['dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=…;charset=utf8mb4', 'user' => '…', 'pass' => '…'],
     'base_url' => 'https://arenabrcko.com', 'cookie_secure' => true, 'channels' => [ /* SMS i email kad se odaberu */ ]];
   ```
4. **Migracija i vlasnik:** ako paket ima SSH: `php tools/migrate.php`, zatim `php tools/create-owner.php` (lozinku upisuje vlasnik sam). Ako nema SSH: u phpMyAdmin uvesti `migrations/001_init.sql`, a vlasnik lokalno pokrene `php tools/owner-sql.php` (lozinka skriveno, ispisuje se samo SQL s hashom) i taj red zalijepi u phpMyAdmin.
5. **public_html:** dodati `public_html/_rs/` (iz `public/_rs/`) i u `.htaccess` postojećeg sajta pravilo iz `public/_rs/htaccess-pravila.txt`:
   `RewriteRule ^(rezervacije|nalog|administracija)(/.*)?$ /_rs/index.php [L,QSA]`.
   Postojeće stranice se ne diraju. **Važno:** objava postojećeg sajta (zip iz `arena-bakarni-site/tools/package.py`) briše `public_html` — zato `package.py` treba dobiti `_rs/` i pravilo u `.htaccess` (izmjena u `arena-bakarni-site` se radi zajedno s objavom).
6. **Cron** (hPanel → Napredno → Cron poslovi): `*/5 * * * * php …/arena-rezervacije/cron.php all`.
7. **Kanali:** SMS (kodovi + obavijesti) i SMTP sanduče za obračune (npr. `obracuni@arenabrcko.com`) — upisati u `config.php` → `channels`.
8. **Provjera na produkciji:** registracija sa stvarnim SMS-om, rezervacija, naplata, zaključenje dana, obračun kluba s emailom; `php tools/restore-check.php` na zasebnoj bazi.
9. **Prelazak sa Sportina:** tek kad vlasnik odobri — dugme „Rezerviši stazu“ u zaglavlju sajta (`src/partials/header.html`) preusmjeriti na `/rezervacije/`, dodati link u meni i podnožje, dodati `/rezervacije/` u sitemap.

## Probno okruženje (POSTAVLJENO 12.11.2026)
- Adresa: **https://proba.brckoworldcup.com** (poddomena sajta brckoworldcup.com, folder `public_html/proba-rezervacije`). arenabrcko.com nije moguć jer je parkiran domen (Single paket = jedan sajt).
- Baza: privremeno **SQLite** datoteka `/home/u270359984/domains/brckoworldcup.com/proba-data/arena-rs.sqlite` — IZVAN foldera sajta, pa je nova objava ne briše (provjereno 12.11.2026). Tu su i backup i PDF obračuni. (Vlasnik još odlučuje o MySQL bazi na hostingu.) Prelazak na MySQL: `php tools/package-proba.php <baza> <korisnik>` + `tools/set-proba-db-pass.sh`.
- Paket: `php tools/package-proba.php` → `build/proba/` → Hostinger deploy samo na proba.brckoworldcup.com. Probne lozinke se prave samo jednom (`PROBA-NALOZI.txt` + `storage/proba-seed.json`, lokalno, nisu u gitu) i ne mijenjaju se pri novoj objavi.
- Zaštita: `.htaccess` odbija app/, storage/, migrations/, tools/, config.php (provjereno: 403); noindex; žuta traka „PROBNO OKRUŽENJE“; kod se prikazuje na ekranu (SMS se ne šalje).
- Cron: CLI varijanta (`cd …/proba-rezervacije && php cron.php all`) je upisana ali se na ovom paketu NE izvršava (provjereno 12.11.2026: bez izlaza, bez zapisa). Koristi se web-cron (hPanel → Advanced → Cron Jobs → Custom, `*/5 * * * *`):
  `wget -q -O /dev/null https://proba.brckoworldcup.com/rezervacije/api/cron`
  Zadnje pokretanje vidi se u Administraciji (Kalendar, iznad kalendara). Isto važi za produkciju (`https://arenabrcko.com/rezervacije/api/cron`).
- Pažnja: folder je unutar `public_html` glavnog sajta; objava glavnog sajta zip paketom ga može obrisati.
