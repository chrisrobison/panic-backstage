CREATE DATABASE IF NOT EXISTS panic_backstage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE panic_backstage;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
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
  venue_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  event_type ENUM('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  status ENUM('empty','proposed','hold','confirmed','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled') NOT NULL DEFAULT 'proposed',
  description_public TEXT,
  description_internal TEXT,
  date DATE NOT NULL,
  doors_time TIME,
  show_time TIME,
  end_time TIME,
  age_restriction VARCHAR(80),
  ticket_price DECIMAL(10,2) DEFAULT 0,
  ticket_url VARCHAR(500),
  capacity INT,
  public_visibility TINYINT(1) NOT NULL DEFAULT 0,
  owner_user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (venue_id) REFERENCES venues(id),
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
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
