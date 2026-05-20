CREATE DATABASE IF NOT EXISTS panic_backstage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE panic_backstage;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL DEFAULT NULL,
  role ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
  hide_credential_setup_prompt TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS venues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  address VARCHAR(255),
  city VARCHAR(120),
  state VARCHAR(60),
  timezone VARCHAR(80) NOT NULL DEFAULT 'America/Los_Angeles',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(50) DEFAULT NULL,          -- legacy tracker ID, e.g. EVT-1050
  venue_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  event_type ENUM('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  status ENUM('empty','proposed','hold','confirmed','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled') NOT NULL DEFAULT 'proposed',
  description_public TEXT,
  description_internal TEXT,
  referral_source VARCHAR(255) DEFAULT NULL,     -- who referred this booking inquiry
  promoter_name VARCHAR(255) DEFAULT NULL,       -- organizer / promoter (free-text)
  date DATE NOT NULL,
  doors_time TIME,
  show_time TIME,
  end_time TIME,
  age_restriction VARCHAR(80),
  ticket_price DECIMAL(10,2) DEFAULT 0,
  ticket_url VARCHAR(500),
  capacity INT,
  room ENUM('upstairs','downstairs','both') DEFAULT NULL,  -- room / floor at multi-space venues
  public_visibility TINYINT(1) NOT NULL DEFAULT 0,
  owner_user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (venue_id) REFERENCES venues(id),
  FOREIGN KEY (owner_user_id) REFERENCES users(id),
  INDEX idx_events_external_id (external_id)
);

CREATE TABLE IF NOT EXISTS event_collaborators (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_user (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  contact_name VARCHAR(255),
  contact_email VARCHAR(255),
  contact_phone VARCHAR(80),
  instagram_url VARCHAR(500),
  website_url VARCHAR(500),
  bio TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS event_lineup (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  band_id INT,
  billing_order INT NOT NULL DEFAULT 0,
  display_name VARCHAR(255) NOT NULL,
  set_time TIME,
  set_length_minutes INT,
  payout_terms VARCHAR(255),
  status ENUM('invited','tentative','confirmed','canceled') NOT NULL DEFAULT 'tentative',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (band_id) REFERENCES bands(id)
);

CREATE TABLE IF NOT EXISTS event_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  status ENUM('todo','in_progress','blocked','done','canceled') NOT NULL DEFAULT 'todo',
  assigned_user_id INT,
  due_date DATE,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS event_blockers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  owner_user_id INT,
  status ENUM('open','waiting','resolved','canceled') NOT NULL DEFAULT 'open',
  due_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS event_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  asset_type ENUM('flyer','poster','band_photo','logo','social_square','social_story','press_photo','other') NOT NULL DEFAULT 'other',
  title VARCHAR(255) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_by_user_id INT,
  approval_status ENUM('draft','needs_review','approved','rejected') NOT NULL DEFAULT 'needs_review',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS event_schedule_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  item_type ENUM('load_in','soundcheck','doors','set','changeover','curfew','staff_call','other') NOT NULL DEFAULT 'other',
  start_time TIME,
  end_time TIME,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS event_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venue_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  event_type ENUM('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  default_title VARCHAR(255),
  default_description_public TEXT,
  default_ticket_price DECIMAL(10,2) DEFAULT 0,
  default_age_restriction VARCHAR(80),
  checklist_json JSON,
  schedule_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (venue_id) REFERENCES venues(id)
);

CREATE TABLE IF NOT EXISTS event_settlements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL UNIQUE,
  gross_ticket_sales DECIMAL(10,2) DEFAULT 0,
  tickets_sold INT DEFAULT 0,
  bar_sales DECIMAL(10,2) DEFAULT 0,
  expenses DECIMAL(10,2) DEFAULT 0,
  band_payouts DECIMAL(10,2) DEFAULT 0,
  promoter_payout DECIMAL(10,2) DEFAULT 0,
  venue_net DECIMAL(10,2) DEFAULT 0,
  notes TEXT,
  settled_by_user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (settled_by_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS event_activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT,
  action VARCHAR(120) NOT NULL,
  details_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- One-time tokens for magic-link login (15-minute TTL)
CREATE TABLE IF NOT EXISTS magic_link_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL UNIQUE,
  expires_at TIMESTAMP NOT NULL,
  used_at    TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mlt_hash  (token_hash),
  INDEX idx_mlt_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-lived refresh tokens (180-day TTL, rotated on each use)
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL UNIQUE,
  expires_at TIMESTAMP NOT NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rt_hash (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WebAuthn registered credentials (passkeys)
CREATE TABLE IF NOT EXISTS passkeys (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  credential_id  VARCHAR(1024) NOT NULL UNIQUE,
  public_key_pem TEXT NOT NULL,
  sign_count     BIGINT NOT NULL DEFAULT 0,
  transports     VARCHAR(255) NULL,
  name           VARCHAR(255) NOT NULL DEFAULT 'Passkey',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at   TIMESTAMP NULL,
  INDEX idx_passkeys_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived WebAuthn challenges (5-minute TTL)
CREATE TABLE IF NOT EXISTS webauthn_challenges (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  challenge  VARCHAR(512) NOT NULL UNIQUE,
  user_id    INT NULL,
  intent     ENUM('register','login') NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wc_challenge (challenge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_invites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  role ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
  token VARCHAR(255) NOT NULL UNIQUE,
  used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

-- Reusable roster of staff members (security, bartender, door, sound, etc.).
-- Kept separate from users so night-of-show staff without logins can still be
-- scheduled. Optionally linked to a users row via user_id.
CREATE TABLE IF NOT EXISTS staff_members (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(255) NOT NULL,
  email        VARCHAR(255) DEFAULT NULL,
  phone        VARCHAR(64)  DEFAULT NULL,
  default_role ENUM(
    'manager','security','bartender','barback','door',
    'sound','lighting','stagehand','runner','cleaner','other'
  ) NOT NULL DEFAULT 'other',
  hourly_rate  DECIMAL(10,2) DEFAULT NULL,
  notes        TEXT DEFAULT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  user_id      INT DEFAULT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staff_active (active),
  INDEX idx_staff_default_role (default_role),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-event staffing assignments. staff_member_id nullable for "TBD" shifts.
CREATE TABLE IF NOT EXISTS event_staffing (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  event_id        INT NOT NULL,
  staff_member_id INT DEFAULT NULL,
  role            ENUM(
    'manager','security','bartender','barback','door',
    'sound','lighting','stagehand','runner','cleaner','other'
  ) NOT NULL DEFAULT 'other',
  call_time       TIME DEFAULT NULL,
  end_time        TIME DEFAULT NULL,
  hourly_rate     DECIMAL(10,2) DEFAULT NULL,
  status          ENUM('scheduled','confirmed','declined','no_show','completed','canceled')
                  NOT NULL DEFAULT 'scheduled',
  notes           TEXT DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staffing_event (event_id),
  INDEX idx_staffing_role (role),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_guest_list (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  party_size INT NOT NULL DEFAULT 1,
  list_type ENUM('comp','guest','will_call','vip','press','industry') NOT NULL DEFAULT 'guest',
  guest_of VARCHAR(255) NULL,
  notes TEXT NULL,
  checked_in TINYINT(1) NOT NULL DEFAULT 0,
  checked_in_at TIMESTAMP NULL,
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_guest_event (event_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
