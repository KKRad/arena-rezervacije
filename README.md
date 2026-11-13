# Arena Bakarni — sistem rezervacija

PHP 8 + MySQL/MariaDB (privremeno i SQLite — probno okruženje https://aliceblue-eagle-979980.hostingersite.com), bez okvira i bez Composer/npm zavisnosti. Front-end: čisti JavaScript (`public/_rs/assets/app.js`).
Projektni dokument (stranice, pravila, matrica ovlaštenja, model baze, faze, otvorene odluke): [docs/PROJEKT.md](docs/PROJEKT.md).
Plan objave na arenabrcko.com: [docs/OBJAVA.md](docs/OBJAVA.md). Izvještaj za vlasnika: [IZVJESTAJ.md](IZVJESTAJ.md).

## Struktura
```
app/lib/        poslovna logika (core, auth, pricing+coverage, availability, reservations, cash+dan, clubs+serije+obračuni, events, notify, pdf, calendar+stats)
app/api.php     JSON API (/rezervacije/api/…) — svaka radnja provjerava ovlaštenje na serveru
public/_rs/     ulaz (index.php) + CSS/JS — jedino što ide u public_html
migrations/     SQL šema
tests/run.php   automatski testovi (242 provjere; MySQL i DB=sqlite)
tools/          migrate, create-owner, seed-dev (samo proba), backup/restore-check, sqlite-to-mysql, restore-drill, proba-uloge-check, proba-backup-check, dev-router
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
Dok email kanal nije povezan, verifikacioni kod se u probnom okruženju prikazuje na ekranu registracije (jasno označen kao probni) i u Administracija → Obavijesti.

## Testovi
```bash
php tests/run.php
```
Koristi zasebnu bazu `db_test` (briše je i pravi iznova). Ista provjera na SQLite (probno okruženje): `DB=sqlite php tests/run.php`. SQLite šema se generiše iz MySQL migracija: `python3 tools/mysql2sqlite.py`. Pokriva: konflikte i istovremeni upis (uklj. 4 odvojena procesa u isti mah), pravila online rezervacije, transakcije više staza, cijene i minimum, podesiv nepokriveni dio uz mjesečnu, patike, mjesečne/VIP/sedmični limit/buduće rezervacije/rano produženje uz obaveznu potvrdu, bonus (2×, bez trostrukog odobravanja), nedolaske i dugove (uklj. podesiv dug za rođendan), oprost, storno, klubove i treninge, naknadno slanje obračuna emailom bez ponovnog obračuna, stalne termine (izuzeci, zamjenska staza, virtualne pojave, bez beskonačnih redova), obračune (11–13 u 12:00, izdvojena utakmica, sprečavanje dvostrukog obračuna, PDF, email u redu), turnire/kvalifikacije, rođendane, zaključivanje dana (blokiraju samo završeni termini; naplata poslije zaključenja ide u sljedeći dan), registraciju i oporavak lozinke emailom (nova korisnica odmah rezerviše), ograničenje pokušaja prijave, pristup bez ovlaštenja (24+ interna poziva), produkcijsku zaštitu registracije, javnu privatnost, verziju cijene, red poruka, podsjetnike i statistiku.

## Provjere na probnom sajtu
```bash
php tools/proba-uloge-check.php 2026-11-27
```
```bash
php tools/proba-backup-check.php
```
Prva provjerava uloge i javni prikaz preko pravog API-ja probe; druga pravi backup na serveru, preuzima ga i vraća u odvojenu lokalnu bazu. Postupak objave i vraćanja: `docs/OBJAVA.md`.

## Restore drill i prenos u MySQL
```bash
php tools/restore-drill.php
```
Pravi dokaz oporavka: iz vraćenog backupa podiže privremeni web server na novom portu i preko stvarnog HTTP-a uradi prijavu, administraciju, dnevni obračun i detalj rezervacije — ne samo brojevi redova. Server i privremeni fajlovi se automatski uklanjaju.
```bash
php tools/sqlite-to-mysql.php <sqlite_datoteka> <mysql_baza> [host] [port]
```
Prenosi probnu SQLite bazu u MySQL (sve tabele, svi ID-jevi), ispisuje brojeve redova po kategoriji i pokreće aplikaciju nad prenesenom bazom. Izvorna SQLite baza se nikad ne mijenja.

## Cron (na serveru)
```bash
*/5 * * * * wget -q -O /dev/null https://<domena>/rezervacije/api/cron   # CLI php cron na Single paketu ne radi
```

## Backup i provjera oporavka
`cron.php all` jednom dnevno (poslije 03:00) pravi `storage/backup/arena-rs-*.sql.gz` (30 dana). Provjera:
```bash
php tools/restore-check.php
```
Vraća najnoviji backup u zasebnu probnu bazu (`db_restore` u konfiguraciji) i poredi broj redova po tabeli.
