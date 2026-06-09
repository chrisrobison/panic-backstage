-- Migration 020_event_ticketing.sql — in-house ticketing
-- Run once against panic_backstage, after migration 019.
--
-- Adds first-party (in-house) event ticketing: tiered ticket types with an
-- inventory counter, orders (purchases and comp batches) with provider tracking
-- for safe provider switching, per-ticket issuance with hashed secret tokens,
-- door scans, per-event scanner links (door staff without user accounts), and a
-- switchable payment-provider config row. An events toggle selects external vs.
-- internal ticketing (ticket_url/ticket_system already exist for external).

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
  INDEX idx_ticket_scans_event (event_id)
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
