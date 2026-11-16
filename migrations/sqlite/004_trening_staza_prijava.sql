-- GENERISANO iz ../004_trening_staza_prijava.sql (tools/mysql2sqlite.py) — ne uređivati ručno

ALTER TABLE reservation_lanes ADD COLUMN claimed_by_user_id INT NULL;
ALTER TABLE reservation_lanes ADD COLUMN claimed_utc DATETIME NULL;
