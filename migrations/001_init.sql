-- Arena Bakarni — sistem rezervacija. MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4.
-- Poslovna vremena (start_at/end_at, business_day) = lokalno Europe/Sarajevo. *_utc = UTC.
-- Novac u feninzima (INT).

CREATE TABLE clubs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  short VARCHAR(20) NOT NULL,
  email VARCHAR(190) NULL,
  match_category VARCHAR(30) NOT NULL DEFAULT 'match_other',
  match_price_mode ENUM('fixed','lane_hour') NOT NULL DEFAULT 'lane_hour',
  match_fixed_cents INT NOT NULL DEFAULT 0,
  match_lane_hour_cents INT NOT NULL DEFAULT 1000,
  active TINYINT NOT NULL DEFAULT 1,
  created_utc DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  phone VARCHAR(20) NULL UNIQUE,
  phone_verified_utc DATETIME NULL,
  email VARCHAR(190) NULL,
  username VARCHAR(40) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role ENUM('owner','manager','staff','guest') NOT NULL DEFAULT 'guest',
  club_id INT NULL,
  blocked_utc DATETIME NULL,
  blocked_by INT NULL,
  blocked_reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  updated_utc DATETIME NULL,
  CONSTRAINT fk_users_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB;

CREATE TABLE user_permissions (
  user_id INT NOT NULL,
  perm VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, perm),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sessions (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  created_utc DATETIME NOT NULL,
  expires_utc DATETIME NOT NULL,
  ip VARCHAR(64) NULL,
  KEY (user_id),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE verification_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  purpose ENUM('register','reset') NOT NULL,
  code_hash CHAR(64) NOT NULL,
  payload TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_utc DATETIME NOT NULL,
  expires_utc DATETIME NOT NULL,
  used_utc DATETIME NULL,
  KEY (phone, purpose)
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) PRIMARY KEY,
  hits INT NOT NULL,
  window_start_utc DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE lanes (
  id TINYINT PRIMARY KEY,
  name VARCHAR(30) NOT NULL,
  short VARCHAR(5) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE price_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  valid_from_utc DATETIME NOT NULL,
  recreation_30_cents INT NOT NULL,
  recreation_min_cents INT NOT NULL,
  shoes_cents INT NOT NULL,
  training_lane_hour_cents INT NOT NULL,
  monthly_cents INT NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL
) ENGINE=InnoDB;

CREATE TABLE recurring_series (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  requested_by INT NULL,
  weekday TINYINT NOT NULL,               -- 1 = ponedjeljak … 7 = nedjelja (ISO)
  start_time CHAR(5) NOT NULL,            -- 'HH:MM'
  minutes INT NOT NULL,
  lane_count TINYINT NOT NULL,
  preferred_lanes VARCHAR(20) NULL,       -- '3,4'
  valid_from DATE NOT NULL,
  valid_to DATE NULL,                     -- NULL = dok se ne ukine
  status ENUM('pending','approved','rejected','ended') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_utc DATETIME NULL,
  materialized_to DATE NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_rs_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB;

CREATE TABLE series_exceptions (
  series_id INT NOT NULL,
  date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  PRIMARY KEY (series_id, date),
  CONSTRAINT fk_se_series FOREIGN KEY (series_id) REFERENCES recurring_series(id)
) ENGINE=InnoDB;

CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('tournament','qualification') NOT NULL,
  title VARCHAR(160) NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  slot_minutes INT NULL,                 -- kvalifikacije: 75
  signup_phone VARCHAR(40) NULL,
  public_note TEXT NULL,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  KEY (start_at)
) ENGINE=InnoDB;

CREATE TABLE event_lanes (
  event_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  PRIMARY KEY (event_id, lane_id),
  CONSTRAINT fk_el_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB;

CREATE TABLE event_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  player_name VARCHAR(160) NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  UNIQUE KEY uq_entry_pos (event_id, lane_id, start_at),
  CONSTRAINT fk_ee_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,                       -- odgovorni rezervant (NULL za klupski stalni termin bez igrača)
  category VARCHAR(30) NOT NULL,
  club_id INT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  cancel_kind VARCHAR(20) NULL,           -- guest, guest_late, arena, staff, series_end
  cancel_reason VARCHAR(255) NULL,
  cancelled_utc DATETIME NULL,
  cancelled_by INT NULL,
  attendance ENUM('pending','arrived','no_show') NOT NULL DEFAULT 'pending',
  attendance_utc DATETIME NULL,
  attendance_by INT NULL,
  released_at DATETIME NULL,              -- lokalno: od kada su staze oslobođene (nedolazak)
  processed_utc DATETIME NULL,
  processed_by INT NULL,
  source ENUM('online','staff','series') NOT NULL,
  series_id INT NULL,
  occurrence_date DATE NULL,
  match_home VARCHAR(120) NULL,
  match_guest VARCHAR(120) NULL,
  match_league VARCHAR(160) NULL,
  notes TEXT NULL,
  price_version_id INT NOT NULL,
  club_amount_cents INT NULL,             -- snimak klupske vrijednosti (trening/utakmica) po cijeni u trenutku unosa
  used_minutes INT NULL,                  -- stvarno korišteno (kraće korištenje)
  late_change TINYINT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  updated_utc DATETIME NULL,
  UNIQUE KEY uq_series_occ (series_id, occurrence_date),
  KEY (start_at), KEY (user_id), KEY (club_id),
  CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_res_club FOREIGN KEY (club_id) REFERENCES clubs(id),
  CONSTRAINT fk_res_price FOREIGN KEY (price_version_id) REFERENCES price_versions(id),
  CONSTRAINT fk_res_series FOREIGN KEY (series_id) REFERENCES recurring_series(id)
) ENGINE=InnoDB;

CREATE TABLE reservation_lanes (
  reservation_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  PRIMARY KEY (reservation_id, lane_id),
  CONSTRAINT fk_rl_res FOREIGN KEY (reservation_id) REFERENCES reservations(id)
) ENGINE=InnoDB;

-- Fizička garancija: jedna staza, jedan blok od 15 min, jedan vlasnik.
CREATE TABLE lane_slots (
  lane_id TINYINT NOT NULL,
  slot_start DATETIME NOT NULL,
  reservation_id INT NULL,
  event_id INT NULL,
  PRIMARY KEY (lane_id, slot_start),
  KEY (reservation_id), KEY (event_id)
) ENGINE=InnoDB;

CREATE TABLE day_locks (
  day DATE PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_day DATE NOT NULL,
  user_id INT NULL,
  reservation_id INT NULL,
  debt_id INT NULL,
  pass_id INT NULL,
  kind ENUM('lane','shoes','monthly','debt') NOT NULL,
  category VARCHAR(30) NULL,
  amount_cents INT NOT NULL,
  method ENUM('cash') NOT NULL DEFAULT 'cash',
  reverses_payment_id INT NULL UNIQUE,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  KEY (business_day), KEY (reservation_id)
) ENGINE=InnoDB;

CREATE TABLE monthly_passes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  kind ENUM('standard','vip') NOT NULL DEFAULT 'standard',
  weekly_minutes INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,                 -- isključivo
  payment_id INT NULL,
  status ENUM('active','ended') NOT NULL DEFAULT 'active',
  ended_reason VARCHAR(120) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  KEY (user_id, start_date),
  CONSTRAINT fk_mp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE monthly_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  kind ENUM('new','renew') NOT NULL,
  status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
  handled_by INT NULL,
  handled_utc DATETIME NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_mr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE hour_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  user_id INT NOT NULL,
  source ENUM('regular','bonus') NOT NULL,
  pass_id INT NULL,
  week_start DATE NULL,
  minutes INT NOT NULL,
  state ENUM('planned','used','forfeited','compensated') NOT NULL DEFAULT 'planned',
  created_utc DATETIME NOT NULL,
  KEY (user_id), KEY (pass_id, week_start), KEY (reservation_id)
) ENGINE=InnoDB;

CREATE TABLE bonus_grants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  minutes INT NOT NULL,
  kind ENUM('arena_cancel','manual') NOT NULL,
  reason VARCHAR(255) NOT NULL,
  source_reservation_id INT NULL UNIQUE,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  KEY (user_id)
) ENGINE=InnoDB;

CREATE TABLE charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  kind ENUM('lane','shoes') NOT NULL,
  lane_id TINYINT NULL,
  minutes INT NULL,
  qty INT NOT NULL DEFAULT 1,
  amount_cents INT NOT NULL,
  note VARCHAR(160) NULL,
  KEY (reservation_id)
) ENGINE=InnoDB;

CREATE TABLE debts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reservation_id INT NULL UNIQUE,
  amount_cents INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('open','paid','forgiven') NOT NULL DEFAULT 'open',
  closed_by INT NULL,
  closed_utc DATETIME NULL,
  close_reason VARCHAR(255) NULL,
  created_utc DATETIME NOT NULL,
  KEY (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE warnings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reservation_id INT NULL UNIQUE,
  kind VARCHAR(30) NOT NULL,
  text VARCHAR(255) NOT NULL,
  created_utc DATETIME NOT NULL,
  KEY (user_id)
) ENGINE=InnoDB;

CREATE TABLE club_statements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  number VARCHAR(30) NOT NULL UNIQUE,
  kind ENUM('full','single_match') NOT NULL,
  cutoff_at DATETIME NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  total_cents INT NOT NULL,
  pdf_path VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_cs_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB;

CREATE TABLE club_statement_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  statement_id INT NOT NULL,
  reservation_id INT NOT NULL UNIQUE,      -- sprečava dvostruki obračun
  item_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  lane_count INT NOT NULL,
  minutes INT NOT NULL,
  unit_label VARCHAR(80) NOT NULL,
  amount_cents INT NOT NULL,
  CONSTRAINT fk_csi_st FOREIGN KEY (statement_id) REFERENCES club_statements(id)
) ENGINE=InnoDB;

CREATE TABLE day_closes (
  day DATE PRIMARY KEY,
  totals_json TEXT NOT NULL,
  closed_by INT NOT NULL,
  closed_utc DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE notification_recipients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'sms',
  types TEXT NOT NULL,                     -- JSON lista tipova
  active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  idem_key VARCHAR(190) NOT NULL UNIQUE,
  channel VARCHAR(20) NOT NULL,
  to_addr VARCHAR(190) NOT NULL,
  type VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NULL,
  body TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  status ENUM('pending','sending','sent','failed','not_configured') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_utc DATETIME NOT NULL,
  last_error VARCHAR(500) NULL,
  provider_ref VARCHAR(190) NULL,
  sent_utc DATETIME NULL,
  created_utc DATETIME NOT NULL,
  KEY (status, next_attempt_utc)
) ENGINE=InnoDB;

CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  at_utc DATETIME NOT NULL,
  user_id INT NULL,
  actor_label VARCHAR(160) NOT NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id VARCHAR(40) NULL,
  data_json TEXT NULL,
  reason VARCHAR(255) NULL,
  KEY (entity, entity_id), KEY (at_utc)
) ENGINE=InnoDB;

CREATE TABLE schema_migrations (
  name VARCHAR(100) PRIMARY KEY,
  applied_utc DATETIME NOT NULL
) ENGINE=InnoDB;

-- Početni podaci
INSERT INTO lanes (id, name, short) VALUES (1,'Staza 1','S1'),(2,'Staza 2','S2'),(3,'Staza 3','S3'),(4,'Staza 4','S4'),(5,'Staza 5','S5'),(6,'Staza 6','S6');
INSERT INTO price_versions (valid_from_utc, recreation_30_cents, recreation_min_cents, shoes_cents, training_lane_hour_cents, monthly_cents, note)
  VALUES (UTC_TIMESTAMP(), 750, 1500, 300, 1000, 5000, 'Početni cjenovnik');
INSERT INTO clubs (name, short, match_category, created_utc) VALUES
  ('KK Rad','Rad','match_rad',UTC_TIMESTAMP()),
  ('ŽKK Olimp','Olimp','match_olimp',UTC_TIMESTAMP()),
  ('KK Zrinski','Zrinski','match_zrinski',UTC_TIMESTAMP());
INSERT INTO settings (k, v) VALUES ('standard_weekly_minutes','120'), ('series_window_days','56');
