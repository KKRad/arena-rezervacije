-- GENERISANO iz ../001_init.sql (tools/mysql2sqlite.py) — ne uređivati ručno

CREATE TABLE clubs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(120) NOT NULL UNIQUE,
  short VARCHAR(20) NOT NULL,
  email VARCHAR(190) NULL,
  match_category VARCHAR(30) NOT NULL DEFAULT 'match_other',
  match_price_mode TEXT NOT NULL DEFAULT 'lane_hour',
  match_fixed_cents INT NOT NULL DEFAULT 0,
  match_lane_hour_cents INT NOT NULL DEFAULT 1000,
  active TINYINT NOT NULL DEFAULT 1,
  created_utc DATETIME NOT NULL
);
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  phone VARCHAR(20) NULL UNIQUE,
  phone_verified_utc DATETIME NULL,
  email VARCHAR(190) NULL,
  username VARCHAR(40) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role TEXT NOT NULL DEFAULT 'guest',
  club_id INT NULL,
  blocked_utc DATETIME NULL,
  blocked_by INT NULL,
  blocked_reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  updated_utc DATETIME NULL,
  CONSTRAINT fk_users_club FOREIGN KEY (club_id) REFERENCES clubs(id)
);
CREATE TABLE user_permissions (
  user_id INT NOT NULL,
  perm VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, perm),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE sessions (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  created_utc DATETIME NOT NULL,
  expires_utc DATETIME NOT NULL,
  ip VARCHAR(64) NULL,
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE verification_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phone VARCHAR(20) NOT NULL,
  purpose TEXT NOT NULL,
  code_hash CHAR(64) NOT NULL,
  payload TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_utc DATETIME NOT NULL,
  expires_utc DATETIME NOT NULL,
  used_utc DATETIME NULL
);
CREATE TABLE rate_limits (
  bucket VARCHAR(120) PRIMARY KEY,
  hits INT NOT NULL,
  window_start_utc DATETIME NOT NULL
);
CREATE TABLE lanes (
  id TINYINT PRIMARY KEY,
  name VARCHAR(30) NOT NULL,
  short VARCHAR(5) NOT NULL
);
CREATE TABLE price_versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  valid_from_utc DATETIME NOT NULL,
  recreation_30_cents INT NOT NULL,
  recreation_min_cents INT NOT NULL,
  shoes_cents INT NOT NULL,
  training_lane_hour_cents INT NOT NULL,
  monthly_cents INT NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL
);
CREATE TABLE recurring_series (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  club_id INT NOT NULL,
  requested_by INT NULL,
  weekday TINYINT NOT NULL,               
  start_time CHAR(5) NOT NULL,            
  minutes INT NOT NULL,
  lane_count TINYINT NOT NULL,
  preferred_lanes VARCHAR(20) NULL,       
  valid_from DATE NOT NULL,
  valid_to DATE NULL,                     
  status TEXT NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_utc DATETIME NULL,
  materialized_to DATE NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_rs_club FOREIGN KEY (club_id) REFERENCES clubs(id)
);
CREATE TABLE series_exceptions (
  series_id INT NOT NULL,
  date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  PRIMARY KEY (series_id, date),
  CONSTRAINT fk_se_series FOREIGN KEY (series_id) REFERENCES recurring_series(id)
);
CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,
  title VARCHAR(160) NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  slot_minutes INT NULL,                 
  signup_phone VARCHAR(40) NULL,
  public_note TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  created_by INT NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE event_lanes (
  event_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  PRIMARY KEY (event_id, lane_id),
  CONSTRAINT fk_el_event FOREIGN KEY (event_id) REFERENCES events(id)
);
CREATE TABLE event_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  player_name VARCHAR(160) NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  UNIQUE (event_id, lane_id, start_at),
  CONSTRAINT fk_ee_event FOREIGN KEY (event_id) REFERENCES events(id)
);
CREATE TABLE reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NULL,                       
  category VARCHAR(30) NOT NULL,
  club_id INT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  cancel_kind VARCHAR(20) NULL,           
  cancel_reason VARCHAR(255) NULL,
  cancelled_utc DATETIME NULL,
  cancelled_by INT NULL,
  attendance TEXT NOT NULL DEFAULT 'pending',
  attendance_utc DATETIME NULL,
  attendance_by INT NULL,
  released_at DATETIME NULL,              
  processed_utc DATETIME NULL,
  processed_by INT NULL,
  source TEXT NOT NULL,
  series_id INT NULL,
  occurrence_date DATE NULL,
  match_home VARCHAR(120) NULL,
  match_guest VARCHAR(120) NULL,
  match_league VARCHAR(160) NULL,
  notes TEXT NULL,
  price_version_id INT NOT NULL,
  club_amount_cents INT NULL,             
  used_minutes INT NULL,                  
  late_change TINYINT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  updated_utc DATETIME NULL,
  UNIQUE (series_id, occurrence_date),
  CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_res_club FOREIGN KEY (club_id) REFERENCES clubs(id),
  CONSTRAINT fk_res_price FOREIGN KEY (price_version_id) REFERENCES price_versions(id),
  CONSTRAINT fk_res_series FOREIGN KEY (series_id) REFERENCES recurring_series(id)
);
CREATE TABLE reservation_lanes (
  reservation_id INT NOT NULL,
  lane_id TINYINT NOT NULL,
  PRIMARY KEY (reservation_id, lane_id),
  CONSTRAINT fk_rl_res FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);
CREATE TABLE lane_slots (
  lane_id TINYINT NOT NULL,
  slot_start DATETIME NOT NULL,
  reservation_id INT NULL,
  event_id INT NULL,
  PRIMARY KEY (lane_id, slot_start)
);
CREATE TABLE day_locks (
  day DATE PRIMARY KEY
);
CREATE TABLE payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_day DATE NOT NULL,
  user_id INT NULL,
  reservation_id INT NULL,
  debt_id INT NULL,
  pass_id INT NULL,
  kind TEXT NOT NULL,
  category VARCHAR(30) NULL,
  amount_cents INT NOT NULL,
  method TEXT NOT NULL DEFAULT 'cash',
  reverses_payment_id INT NULL UNIQUE,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE monthly_passes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'standard',
  weekly_minutes INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,                 
  payment_id INT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  ended_reason VARCHAR(120) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_mp_user FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE monthly_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NOT NULL,
  kind TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  handled_by INT NULL,
  handled_utc DATETIME NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_mr_user FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE hour_allocations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_id INT NOT NULL,
  user_id INT NOT NULL,
  source TEXT NOT NULL,
  pass_id INT NULL,
  week_start DATE NULL,
  minutes INT NOT NULL,
  state TEXT NOT NULL DEFAULT 'planned',
  created_utc DATETIME NOT NULL
);
CREATE TABLE bonus_grants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NOT NULL,
  minutes INT NOT NULL,
  kind TEXT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  source_reservation_id INT NULL UNIQUE,
  created_by INT NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE charges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_id INT NOT NULL,
  kind TEXT NOT NULL,
  lane_id TINYINT NULL,
  minutes INT NULL,
  qty INT NOT NULL DEFAULT 1,
  amount_cents INT NOT NULL,
  note VARCHAR(160) NULL
);
CREATE TABLE debts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NOT NULL,
  reservation_id INT NULL UNIQUE,
  amount_cents INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  closed_by INT NULL,
  closed_utc DATETIME NULL,
  close_reason VARCHAR(255) NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE warnings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NOT NULL,
  reservation_id INT NULL UNIQUE,
  kind VARCHAR(30) NOT NULL,
  text VARCHAR(255) NOT NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE club_statements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  club_id INT NOT NULL,
  number VARCHAR(30) NOT NULL UNIQUE,
  kind TEXT NOT NULL,
  cutoff_at DATETIME NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  total_cents INT NOT NULL,
  pdf_path VARCHAR(255) NULL,
  created_by INT NULL,
  created_utc DATETIME NOT NULL,
  CONSTRAINT fk_cs_club FOREIGN KEY (club_id) REFERENCES clubs(id)
);
CREATE TABLE club_statement_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  statement_id INT NOT NULL,
  reservation_id INT NOT NULL UNIQUE,      
  item_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  lane_count INT NOT NULL,
  minutes INT NOT NULL,
  unit_label VARCHAR(80) NOT NULL,
  amount_cents INT NOT NULL,
  CONSTRAINT fk_csi_st FOREIGN KEY (statement_id) REFERENCES club_statements(id)
);
CREATE TABLE day_closes (
  day DATE PRIMARY KEY,
  totals_json TEXT NOT NULL,
  closed_by INT NOT NULL,
  closed_utc DATETIME NOT NULL
);
CREATE TABLE notification_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'sms',
  types TEXT NOT NULL,                     
  active TINYINT NOT NULL DEFAULT 1
);
CREATE TABLE outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  idem_key VARCHAR(190) NOT NULL UNIQUE,
  channel VARCHAR(20) NOT NULL,
  to_addr VARCHAR(190) NOT NULL,
  type VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NULL,
  body TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_utc DATETIME NOT NULL,
  last_error VARCHAR(500) NULL,
  provider_ref VARCHAR(190) NULL,
  sent_utc DATETIME NULL,
  created_utc DATETIME NOT NULL
);
CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NOT NULL
);
CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  at_utc DATETIME NOT NULL,
  user_id INT NULL,
  actor_label VARCHAR(160) NOT NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id VARCHAR(40) NULL,
  data_json TEXT NULL,
  reason VARCHAR(255) NULL
);
CREATE TABLE schema_migrations (
  name VARCHAR(100) PRIMARY KEY,
  applied_utc DATETIME NOT NULL
);
INSERT INTO lanes (id, name, short) VALUES (1,'Staza 1','S1'),(2,'Staza 2','S2'),(3,'Staza 3','S3'),(4,'Staza 4','S4'),(5,'Staza 5','S5'),(6,'Staza 6','S6');
INSERT INTO price_versions (valid_from_utc, recreation_30_cents, recreation_min_cents, shoes_cents, training_lane_hour_cents, monthly_cents, note)
  VALUES (datetime('now'), 750, 1500, 300, 1000, 5000, 'Početni cjenovnik');
INSERT INTO clubs (name, short, match_category, created_utc) VALUES
  ('KK Rad','Rad','match_rad',datetime('now')),
  ('ŽKK Olimp','Olimp','match_olimp',datetime('now')),
  ('KK Zrinski','Zrinski','match_zrinski',datetime('now'));
INSERT INTO settings (k, v) VALUES ('standard_weekly_minutes','120'), ('series_window_days','56');
