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
- Baza: privremeno **SQLite** datoteka `storage/arena-rs.sqlite` (vlasnik još odlučuje o MySQL bazi na hostingu). Prelazak na MySQL: `php tools/package-proba.php <baza> <korisnik>` + `tools/set-proba-db-pass.sh`.
- Paket: `php tools/package-proba.php` → `build/proba/` → Hostinger deploy samo na proba.brckoworldcup.com. Svaki novi paket pravi nove probne lozinke (`PROBA-NALOZI.txt`, lokalno) — postojeća baza ih ne mijenja; ponovni deploy ne briše `storage/arena-rs.sqlite` samo ako ga deploy ne prepiše (prije deploya napraviti backup).
- Zaštita: `.htaccess` odbija app/, storage/, migrations/, tools/, config.php (provjereno: 403); noindex; žuta traka „PROBNO OKRUŽENJE“; kod se prikazuje na ekranu (SMS se ne šalje).
- Cron (obavijesti, podsjetnici, dnevni backup) NIJE postavljen — dodaje vlasnik u hPanel → Napredno → Cron poslovi:
  `*/5 * * * *  cd /home/u270359984/domains/brckoworldcup.com/public_html/proba-rezervacije && php cron.php all`
- Pažnja: folder je unutar `public_html` glavnog sajta; objava glavnog sajta zip paketom ga može obrisati.
