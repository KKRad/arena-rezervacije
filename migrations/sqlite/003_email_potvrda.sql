-- GENERISANO iz ../003_email_potvrda.sql (tools/mysql2sqlite.py) — ne uređivati ručno

ALTER TABLE users ADD COLUMN email_verified_utc DATETIME NULL;
UPDATE users SET email_verified_utc = phone_verified_utc WHERE phone_verified_utc IS NOT NULL;
ALTER TABLE verification_codes ADD COLUMN email VARCHAR(190) NULL;
