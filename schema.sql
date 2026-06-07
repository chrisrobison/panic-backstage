CREATE DATABASE IF NOT EXISTS panic_backstage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE panic_backstage;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  phone VARCHAR(64) NULL DEFAULT NULL,
  password_hash VARCHAR(255) NULL DEFAULT NULL,
  role ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
  access_status ENUM('active','requested') NOT NULL DEFAULT 'active',
  request_notes TEXT NULL DEFAULT NULL,
  hide_credential_setup_prompt TINYINT(1) NOT NULL DEFAULT 0,
  default_landing VARCHAR(32) NULL DEFAULT NULL,
  nav_collapsed TINYINT(1) NOT NULL DEFAULT 0,
  events_sort VARCHAR(8) NULL DEFAULT NULL,
  alt_emails JSON NULL,                            -- verified secondary emails; only entries with non-null verified_at may authenticate
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- MySQL 8.0.17+ multi-valued UNIQUE index over the array's email members.
  UNIQUE INDEX uq_users_alt_emails ( (CAST(alt_emails->'$[*].email' AS CHAR(255) ARRAY)) )
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
  deposit_amount DECIMAL(10,2) DEFAULT NULL,     -- artist/promoter deposit on file (sheet's "Paid Deposit")
  potential_revenue DECIMAL(10,2) DEFAULT NULL,  -- sheet col 6
  ticket_url VARCHAR(500),
  ticket_system VARCHAR(40) DEFAULT NULL,        -- sheet col 15 (TIXR / Eventbrite / Door / …)
  contract_url VARCHAR(500) DEFAULT NULL,        -- sheet col 16
  walkthrough_done TINYINT(1) NOT NULL DEFAULT 0,-- sheet col 17 "Walk Through Happened?"
  settlement_doc_url VARCHAR(500) DEFAULT NULL,  -- sheet col 19
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
  pronoun      VARCHAR(40)  DEFAULT NULL,  -- sheet col 4
  default_role ENUM(
    'manager','security','bartender','barback','door',
    'sound','lighting','stagehand','runner','cleaner','other'
  ) NOT NULL DEFAULT 'other',
  position     VARCHAR(120) DEFAULT NULL,  -- sheet col 8 — free-text job title, more specific than default_role
  hourly_rate  DECIMAL(10,2) DEFAULT NULL,
  hire_date    DATE DEFAULT NULL,          -- 'Staff Contact' sheet "Hire Date" column
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

-- ── Contract / Deal Builder (migration 017) ─────────────────────────────────
-- Structured deal terms first, rendered document second. See migration 017 for
-- the full rationale. event_id is NULLABLE so recurring residencies can be
-- venue-bound rather than tied to a single event row.

CREATE TABLE IF NOT EXISTS contract_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  category ENUM('base','financial','operational','legal','risk') NOT NULL DEFAULT 'operational',
  body_template MEDIUMTEXT NOT NULL,
  required_fields_json JSON,
  risk_level ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  contract_type ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  intro_text MEDIUMTEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_template_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  module_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  condition_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_module (template_id, module_id),
  FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES contract_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NULL,
  venue_id INT NULL,
  template_id INT NULL,
  contract_type ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','needs_review','approved','sent','signed','canceled','superseded') NOT NULL DEFAULT 'draft',
  counterparty_name VARCHAR(255) NULL,
  counterparty_org VARCHAR(255) NULL,
  counterparty_email VARCHAR(255) NULL,
  rental_fee DECIMAL(10,2) NULL,
  deposit_amount DECIMAL(10,2) NULL,
  balance_due_date DATE NULL,
  bar_minimum DECIMAL(10,2) NULL,
  guarantee_amount DECIMAL(10,2) NULL,
  door_split_artist DECIMAL(5,2) NULL,
  door_split_venue DECIMAL(5,2) NULL,
  door_split_promoter DECIMAL(5,2) NULL,
  advance_ticket_price DECIMAL(10,2) NULL,
  door_ticket_price DECIMAL(10,2) NULL,
  security_count INT NULL,
  security_rate DECIMAL(10,2) NULL,
  security_paid_by ENUM('venue','artist','promoter','client','shared') NULL,
  sound_tech_included TINYINT(1) NULL,
  lighting_tech_included TINYINT(1) NULL,
  merch_venue_percent DECIMAL(5,2) NULL,
  recurrence_rule VARCHAR(255) NULL,
  term_start DATE NULL,
  term_end DATE NULL,
  trial_period_weeks INT NULL,
  termination_notice_days INT NULL,
  review_cadence VARCHAR(120) NULL,
  revenue_split_house DECIMAL(5,2) NULL,
  revenue_split_producer DECIMAL(5,2) NULL,
  variables_json JSON,
  internal_notes TEXT,
  current_version_id INT NULL,
  created_by_user_id INT NULL,
  approved_by_user_id INT NULL,
  sent_at TIMESTAMP NULL,
  signed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contracts_event (event_id),
  INDEX idx_contracts_status (status),
  INDEX idx_contracts_type (contract_type),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
  FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  module_id INT NULL,
  module_key VARCHAR(80) NULL,
  title VARCHAR(255) NOT NULL,
  body_template MEDIUMTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  included TINYINT(1) NOT NULL DEFAULT 1,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  auto_selected TINYINT(1) NOT NULL DEFAULT 0,
  risk_level ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  required_fields_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sections_contract (contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES contract_modules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  version_number INT NOT NULL DEFAULT 1,
  rendered_html MEDIUMTEXT,
  rendered_text MEDIUMTEXT,
  variables_snapshot_json JSON,
  summary_json JSON,
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_versions_contract (contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- In-house event ticketing (migration 020_event_ticketing.sql)
-- ===========================================================================

-- ticket_types: tiers + inventory counter
CREATE TABLE ticket_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  price_cents INT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  quantity_total INT NOT NULL,
  quantity_sold INT NOT NULL DEFAULT 0,          -- atomic counter (fulfilled + comped tickets)
  sales_start DATETIME NULL,
  sales_end DATETIME NULL,
  status ENUM('draft','on_sale','paused','sold_out','closed') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_ticket_types_event (event_id)
);

-- ticket_orders: a purchase or comp batch. Stores the provider that processed it (for safe provider switching).
CREATE TABLE ticket_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  buyer_user_id INT NULL,
  buyer_name VARCHAR(200) NULL,
  buyer_email VARCHAR(255) NULL,
  buyer_phone VARCHAR(40) NULL,
  provider VARCHAR(40) NULL,                      -- 'stripe' | 'square' | 'comp'
  provider_ref VARCHAR(191) NULL,                 -- checkout session id
  provider_payment_ref VARCHAR(191) NULL,         -- payment/charge id (used for refunds)
  amount_cents INT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('pending','paid','fulfilled','canceled','refunded','expired') NOT NULL DEFAULT 'pending',
  is_comp TINYINT(1) NOT NULL DEFAULT 0,
  hold_expires_at DATETIME NULL,                  -- reservation TTL while payment is in flight
  paid_at DATETIME NULL,
  refunded_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (buyer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ticket_orders_event (event_id),
  INDEX idx_ticket_orders_provider_ref (provider, provider_ref),
  INDEX idx_ticket_orders_status (status)
);

CREATE TABLE ticket_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  ticket_type_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price_cents INT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  INDEX idx_ticket_order_items_order (order_id)
);

-- tickets: one row per issued ticket (created on fulfillment or comp). Secret token is NEVER stored plaintext.
CREATE TABLE tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  ticket_type_id INT NOT NULL,
  order_id INT NULL,
  code VARCHAR(40) NOT NULL,                       -- short human reference, NOT the secret
  token_hash CHAR(64) NOT NULL,                    -- sha256 hex of the random secret token
  holder_name VARCHAR(200) NULL,
  holder_email VARCHAR(255) NULL,
  status ENUM('issued','redeemed','void') NOT NULL DEFAULT 'issued',
  redeemed_at DATETIME NULL,
  redeemed_by_user_id INT NULL,
  redeemed_via_scanner_id INT NULL,
  voided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tickets_token (token_hash),
  UNIQUE KEY uq_tickets_code (code),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE SET NULL,
  INDEX idx_tickets_event (event_id)
);

CREATE TABLE ticket_scans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NULL,
  event_id INT NOT NULL,
  result ENUM('admitted','already_redeemed','void','not_found','wrong_event','expired_link') NOT NULL,
  scanner_link_id INT NULL,
  scanned_by_user_id INT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket_scans_ticket (ticket_id),
  INDEX idx_ticket_scans_event (event_id),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

-- per-event scanner links: door-staff auth without user accounts
CREATE TABLE event_scanner_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  label VARCHAR(120) NULL,
  token_hash CHAR(64) NOT NULL,                    -- sha256 of the link secret
  pin_hash VARCHAR(255) NULL,                      -- optional, via password_hash()
  created_by_user_id INT NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_scanner_token (token_hash),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_event_scanner_links_event (event_id)
);

-- active payment provider config (secrets stay in .env; this holds the switchable selection + non-secret settings)
CREATE TABLE payment_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  active_provider VARCHAR(40) NOT NULL DEFAULT 'square',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  settings_json JSON NULL,
  updated_by_user_id INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- events: add internal ticketing toggle (ticket_url/ticket_system already exist for external systems)
ALTER TABLE events ADD COLUMN ticketing_mode ENUM('external','internal') NOT NULL DEFAULT 'external';

-- ===========================================================================
-- Multi-email identity (migration 022_multi_email_identity.sql)
-- ===========================================================================

-- One-time tokens to confirm ownership of a newly added alias (hashed, single-use).
CREATE TABLE email_verification_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_email_verif_user (user_id),
  INDEX idx_email_verif_email (email)
);

-- Audit trail for account merges (what was folded into what, and the moved refs).
CREATE TABLE user_merges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  survivor_user_id INT NOT NULL,
  loser_user_id INT NOT NULL,
  loser_email VARCHAR(255) NULL,
  performed_by_user_id INT NULL,
  details JSON NULL,                 -- per-table repoint counts, moved emails, signals
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_merges_survivor (survivor_user_id)
);
