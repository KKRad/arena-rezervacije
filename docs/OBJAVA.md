# Objava — plan za arenabrcko.com i postupak za probu (NIŠTA NIJE OBJAVLJENO NA arenabrcko.com)

## Zašto ovaj hosting
arenabrcko.com je parkiran na Hostinger sajtu `brckoworldcup.com` (nalog u270359984, **Single Web Hosting**). Paket podržava PHP 8, MySQL, web-cron i SSL, ali ne Node.js ni CLI cron (provjereno). Zato je aplikacija u PHP-u i radi na postojećem paketu, bez doplate.

## Produkcija — koraci (tek uz odobrenje vlasnika; uslovi su u IZVJESTAJ.md tačka 5)
1. **Baza:** hPanel → Baze podataka → nova MySQL baza + korisnik. Lozinku upisuje vlasnik; ne šalje se u chat.
2. **Kod izvan public_html:** `/home/u270359984/domains/brckoworldcup.com/arena-rezervacije/` (bez `public/`, `tests/`, `config.local.php`, `storage/`).
3. **config.php** u tom folderu: `env => 'prod'`, MySQL podaci, `cookie_secure => true`, `channels` (SMS, SMTP), `registration_enabled => true` **tek kada je SMS provjeren** (bez toga registracija i oporavak lozinke ostaju isključeni, provjereno testom).
4. **Migracije i vlasnik:** phpMyAdmin → uvoz `migrations/001_init.sql` i `002_ostatak_mjesecne.sql`; vlasnik lokalno `php tools/owner-sql.php` (lozinka skriveno) i zalijepi ispis u phpMyAdmin.
5. **public_html:** `_rs/` + pravilo iz `public/_rs/htaccess-pravila.txt`. **Objava glavnog sajta briše public_html** → paket glavnog sajta mora sadržavati `_rs/` i pravilo (mijenja se zajedno s produkcijskom objavom, uz odobrenje).
6. **Web-cron** (hPanel → Advanced → Cron Jobs → Custom, `*/5 * * * *`): `wget -q -O /dev/null https://arenabrcko.com/rezervacije/api/cron`.
7. **Provjera:** registracija sa stvarnim SMS-om, rezervacija, naplata, zaključenje dana, obračun kluba s emailom; backup napravljen i vraćen u odvojeno okruženje.
8. **Sportin dugme:** tek uz odobrenje vlasnika.

## Probno okruženje — https://proba.brckoworldcup.com

### Lokacije
| Šta | Gdje | Briše li ga objava probe? | Javno? |
|---|---|---|---|
| Kod probe | `public_html/proba-rezervacije/` | da (zamjenjuje se novom verzijom) | samo `_rs/` i slike; ostalo 403 |
| Baza (SQLite) | `domains/brckoworldcup.com/proba-data/arena-rs.sqlite` | **ne** (provjereno, podaci preživjeli dvije objave) | ne (izvan web foldera) |
| Backup, PDF obračuni | `proba-data/backup/`, `proba-data/pdf/` | ne | ne |
| Tajne postavke (npr. MySQL lozinka ubuduće) | `proba-data/config.php` (ako postoji, ima prednost nad `config.php` u paketu) | ne | ne |
| Probne lozinke | lokalno: `PROBA-NALOZI.txt`, `storage/proba-seed.json` (nisu u gitu) | — | — |

### Objava nove verzije probe
1. `php tests/run.php` i `DB=sqlite php tests/run.php` — moraju proći.
2. **Kopija prije objave:** `php tools/proba-backup-check.php` (napravi backup na serveru, preuzme ga i provjeri vraćanjem u odvojenu lokalnu bazu).
3. `php tools/package-proba.php` → `build/proba/` + arhiva `build/archive/proba-<datum>-<git>.zip`.
4. Hostinger deploy **samo** na `proba.brckoworldcup.com` (direktorij `build/proba`).
5. `php tools/proba-uloge-check.php <slobodan datum>` — provjera uloga i javnog prikaza na probi.

### Vraćanje prethodne verzije
- **Kod:** deploy arhive iz `build/archive/` (Hostinger deploy, tip „static“, putanja do .zip) ili hPanel → File Manager → otpakovati u `public_html/proba-rezervacije/`. Git oznaka `proba-2026-11-12-prije-revizije` označava verziju prije revizije 12.11.2026.
- **Baza:** migracije samo dodaju kolone, pa stariji kod radi s novijom bazom. Ako treba vratiti podatke: preuzeti backup (`php tools/proba-backup-check.php --preuzmi <ime>` ili lista u Administraciji), u File Manageru raspakovati `.sqlite.gz` i zamijeniti `proba-data/arena-rs.sqlite` (prije toga sačuvati postojeću datoteku).

### Backup i provjera oporavka
- Automatski: web-cron jednom dnevno (poslije 03:00) pravi `proba-data/backup/arena-rs-*.sqlite.gz`, čuva 30 dana.
- Ručno: `POST /rezervacije/api/backup/run` (samo vlasnik) ili alat iznad.
- **Provjera vraćanja:** `php tools/proba-backup-check.php` → raspakuje u `storage/proba-backups/vraceno-*.sqlite`, `PRAGMA integrity_check`, broji redove, pokreće aplikaciju nad kopijom (kalendar, dnevni obračun). Provjereno 12.11.2026 na dvije kopije (prije i poslije revizije): „OPORAVAK PROVJEREN“.

### Preostali rizik: objava glavnog sajta briše fajlove probe
Folder probe je unutar `public_html` glavnog sajta (Hostinger na Single paketu dozvoljava samo jedan sajt i poddomene unutar njegovog `public_html`). Hostinger objava zamjenjuje cijeli sadržaj foldera, pa bi objava glavnog sajta obrisala **kod** probe. **Podaci, backup i postavke su sigurni** (izvan foldera). Do rješenja: nakon svake objave glavnog sajta ponoviti „Objava nove verzije probe“ (koraci 3–5, oko 2 minute) ili otpakovati zadnju arhivu u File Manageru.

**Prijedlog za potpuno odvajanje (odluka vlasnika, ništa nije mijenjano):** na istom Hostinger nalogu postoji i **Premium Web Hosting** paket (koristi ga bakarnifakture.com, plaćen do 2029) koji dozvoljava više sajtova. Proba se može postaviti kao zaseban sajt na tom paketu:
- (a) s besplatnom privremenom Hostinger adresom (`*.hostingersite.com`) — bez promjene DNS-a; ili
- (b) kao `proba.arenabrcko.com` — traži jedan DNS zapis (A) u zoni arenabrcko.com, uz odobrenje vlasnika.
Tada proba ima svoj folder i svoju objavu, a objava glavnog sajta je ne dira. Alternativa: nadogradnja Single paketa na paket s više sajtova.
