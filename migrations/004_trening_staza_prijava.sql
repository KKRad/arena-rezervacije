-- Omogućava članu kluba da se "ubaci" na slobodnu stazu u terminu treninga njegovog kluba
-- (staza koju niko od igrača nije preuzeo i dalje se vodi kao trening; klub se naplaćuje za cijeli termin bez obzira na popunjenost).
ALTER TABLE reservation_lanes ADD COLUMN claimed_by_user_id INT NULL;
ALTER TABLE reservation_lanes ADD COLUMN claimed_utc DATETIME NULL;
