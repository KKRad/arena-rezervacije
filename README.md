# Arena Bakarni — sistem rezervacija

PHP 8 + MySQL/MariaDB (privremeno i SQLite — probno okruženje https://proba.brckoworldcup.com), bez okvira i bez Composer/npm zavisnosti. Front-end: čisti JavaScript (`public/_rs/assets/app.js`).
Projektni dokument (stranice, pravila, matrica ovlaštenja, model baze, faze, otvorene odluke): [docs/PROJEKT.md](docs/PROJEKT.md).
Plan objave na arenabrcko.com: [docs/OBJAVA.md](docs/OBJAVA.md). Izvještaj za vlasnika: [IZVJESTAJ.md](IZVJESTAJ.md).

## Struktura
```
app/lib/        poslovna logika (core, auth, pricing+coverage, availability, reservations, cash+dan, clubs+serije+obračuni, events, notify, pdf, calendar+stats)
app/api.php     JSON API (/rezervacije/api/…) — svaka radnja provjerava ovlaštenje na serveru
public/_rs/     ulaz (index.php) + CSS/JS — jedino što ide u public_html
migrations/     SQL šema
tests/run.php   automatski testovi (172 provjere)
tools/          migrate, create-owner, seed-dev (samo proba), backup/restore-check, dev-router
cron.php        pozadinski poslovi (poruke, podsjetnici, neobrađeni termini, serije, dnevni backup)
storage/        PDF obračuni, backup (izvan public_html, nije u gitu)
```

## Lokalno (probno okruženje)
Potrebno: `brew install php mariadb`, `brew services start mariadb`. Konfiguracija: `config.local.php` (nije u gitu).

```bash
php tools/migrate.php
```
```bash
php tools/seed-dev.php
```
```bash
php -S localhost:8090 -t public tools/dev-router.php
```
Otvoriti http://localhost:8090/rezervacije/ · /nalog/ · /administracija/. Test nalozi su navedeni u zaglavlju `tools/seed-dev.php` (samo za probu).
Dok SMS nije povezan, verifikacioni kod se u probnom okruženju prikazuje na ekranu registracije i u Administracija → Obavijesti.

## Testovi
```bash
php tests/run.php
```
Koristi zasebnu bazu `db_test` (briše je i pravi iznova). Ista provjera na SQLite (probno okruženje): `DB=sqlite php tests/run.php`. SQLite šema se generiše iz MySQL migracija: `python3 tools/mysql2sqlite.py`. Pokriva: konflikte i istovremeni upis, pravila online rezervacije, transakcije više staza, cijene i minimum, patike, mjesečne/VIP/sedmični limit/buduće rezervacije, bonus (2×, bez trostrukog odobravanja), nedolaske i dugove, oprost, storno, klubove i treninge, stalne termine (izuzeci, virtualne pojave, bez beskonačnih redova), obračune (11–13 u 12:00, izdvojena utakmica, sprečavanje dvostrukog obračuna, PDF, email u redu), turnire/kvalifikacije, rođendane, zabranu zaključivanja neriješenog dana, naloge (povezivanje profila, ograničenja pokušaja, oporavak), javnu privatnost, verziju cijene, red poruka, podsjetnike i statistiku.

## Cron (na serveru)
```bash
*/5 * * * * php /home/u270359984/domains/brckoworldcup.com/arena-rezervacije/cron.php all
```

## Backup i provjera oporavka
`cron.php all` jednom dnevno (poslije 03:00) pravi `storage/backup/arena-rs-*.sql.gz` (30 dana). Provjera:
```bash
php tools/restore-check.php
```
Vraća najnoviji backup u zasebnu probnu bazu (`db_restore` u konfiguraciji) i poredi broj redova po tabeli.
