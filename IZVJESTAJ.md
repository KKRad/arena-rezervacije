# Sistem rezervacija Arene Bakarni — izvještaj (12.11.2026)

## Stanje
Sistem je urađen kao funkcionalna aplikacija sa bazom podataka i radi u **probnom okruženju na računaru** (ništa nije objavljeno, arenabrcko.com i postojeći sajt nisu dirani).
Svih 7 faza iz specifikacije je implementirano; automatski testovi: **172 provjere, sve prolaze**.

| Faza | Šta radi |
|---|---|
| 1 Nalozi i kalendar | registracija s kodom na telefon, prijava telefonom, oporavak lozinke, povezivanje s profilom koji je napravilo osoblje, blokiranje, ovlaštenja po nalogu (provjera na serveru), kalendar 6 staza bez horizontalnog pomjeranja (telefon i računar), dan/sedmica, „Danas“, izbor datuma |
| 2 Rezervacije i javni pregled | javni link `/rezervacije` bez prijave i bez privatnih podataka; gost odmah rezerviše 1–6 staza (konkretne ili automatski), mijenja, otkazuje, produžava; zaštita od duplog rezervisanja i pri istovremenom kliku |
| 3 Dolazak i gotovina | Došao / Nije došao (nakon 10 min, oslobađanje staze), naplata uz obavezno „Jesu li korištene patike?“, 15 / 22,50 / 30 KM i minimum 15 KM, stari dug kao odvojena stavka, storno ispravke, dnevni obračun i zabrana zaključivanja dok postoje neobrađeni termini |
| 4 Mjesečne i bonus | 50 KM, period od dana uplate, 2 h sedmično (pon–ned), VIP sati, buduće rezervacije troše limit, pokriće redovni → bonus → gotovina, otkaz Arene = 2× bonus (bez trostrukog odobravanja), nedolazak = sati propadaju bez duga, podsjetnici |
| 5 Klubovi | trening na račun kluba (10 KM/staza/sat), „Nije trening — plaćam lično“, stalni termini kao pravilo s izuzecima (bez beskonačnih redova), odobrenje s listom konflikata i preskakanjem datuma, utakmice (2,5 h, 6 staza, fiksno ili po stazi/satu), obračun s PDF-om i emailom, izdvojena utakmica, nemoguć dvostruki obračun, „Obračunato / Neobračunato“ (bez praćenja uplata) |
| 6 Turniri i kvalifikacije | blokada svih staza (od 08:00), konflikti se moraju riješiti prije potvrde, javni raspored, kvalifikacije 75 min sa slobodnim mjestima i „Telefon za prijave“ |
| 7 Obavijesti i statistika | red poruka s ponovnim pokušajima i bez dupliranja, primaoci po vrsti obavijesti, poruka se nikad ne prikazuje kao poslana ako nije; statistika samo za vlasnika (gotovina odvojena od klupskih vrijednosti, staza-sati, poređenje s prethodnim periodom), dnevni backup i provjera oporavka |

## Hosting i domena
arenabrcko.com je na Hostinger paketu *Single Web Hosting* — podržava PHP i MySQL, ali ne Node.js. Zato je aplikacija napisana u PHP-u: radi na **istom hostingu i istoj domeni, bez doplate**, na adresama `arenabrcko.com/rezervacije`, `/nalog` i `/administracija`. Postojeće stranice ostaju kakve jesu. Plan objave korak po korak: `docs/OBJAVA.md`.

## Šta je potrebno od vlasnika (stvarno otvorene odluke)
Za sve je već ugrađeno predloženo rješenje; mijenja se samo ako vlasnik odluči drugačije.
1. **SMS servis** (kodovi za registraciju i obavijesti) — bez njega registracija ne radi na produkciji. Viber/WhatsApp mogu se dodati kasnije.
2. **Email sanduče za obračune klubova** (npr. `obracuni@arenabrcko.com`) i emailovi tri kluba.
3. **Ostatak preko mjesečne** (npr. 2 h termin, 1,5 h pokriveno): predloženo 7,50 KM po započetih 30 min, bez minimuma od 15 KM.
4. **Produženje mjesečne prije isteka:** predloženo — novi period počinje danom uplate, stari se tog dana zatvara (preostali dani starog propadaju). Alternativa: novi period nastavlja se na kraj starog.
5. **Nedolazak na rođendan:** predloženo bez automatskog duga (pravilo duga važi samo za rekreaciju).
6. **Objava:** probna poddomena i konačno uključivanje na arenabrcko.com, te kada dugme „Rezerviši stazu“ prelazi sa Sportina na novi sistem.

Logo: koristi se postojeći logo sa sajta; kada vlasnik dostavi novi, zamjenjuje se jednom datotekom.
