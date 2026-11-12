-- Podesiv iznos za dio termina koji nije pokriven mjesečnom/bonusom (otvorena odluka vlasnika; prijedlog 7,50 KM / 30 min, bez minimuma).
ALTER TABLE price_versions ADD COLUMN monthly_remainder_30_cents INT NOT NULL DEFAULT 750;
ALTER TABLE price_versions ADD COLUMN monthly_remainder_min_cents INT NOT NULL DEFAULT 0;
