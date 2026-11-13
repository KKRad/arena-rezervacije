-- Registracija i oporavak lozinke sada koriste potvrđeni email (prijava ostaje telefon + lozinka).
-- Postojeći nalozi koji su bili potvrđeni telefonom (stara metoda) ostaju potvrđeni (vlasnik/osoblje ih je ionako pravilo ili su prošli SMS tok).
ALTER TABLE users ADD COLUMN email_verified_utc DATETIME NULL;
UPDATE users SET email_verified_utc = phone_verified_utc WHERE phone_verified_utc IS NOT NULL;

ALTER TABLE verification_codes ADD COLUMN email VARCHAR(190) NULL;
