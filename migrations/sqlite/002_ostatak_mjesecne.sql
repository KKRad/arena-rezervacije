-- GENERISANO iz ../002_ostatak_mjesecne.sql (tools/mysql2sqlite.py) — ne uređivati ručno

ALTER TABLE price_versions ADD COLUMN monthly_remainder_30_cents INT NOT NULL DEFAULT 750;
ALTER TABLE price_versions ADD COLUMN monthly_remainder_min_cents INT NOT NULL DEFAULT 0;
