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
- Ručno: alat iznad ili `POST /rezervacije/api/backup/run` (samo vlasnik).
- **Provjera vraćanja:** `php tools/proba-backup-check.php` → `PRAGMA integrity_check`, broj redova, aplikacija nad vraćenom kopijom. Provjereno 12.11.2026 na novom sajtu: „OPORAVAK PROVJEREN“.

### Stara proba (proba.brckoworldcup.com)
Zamijenjena novim sajtom. I dalje radi (ista verzija koda, vlastiti podaci u `domains/brckoworldcup.com/proba-data/` i vlastiti web-cron) dok vlasnik ne odobri gašenje/preusmjeravanje; podaci uneseni tamo nakon selidbe ne prelaze automatski.
