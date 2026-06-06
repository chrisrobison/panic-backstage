-- Migration 017: Contract / Deal Builder
-- Run once against panic_backstage, after migration 016.
--
-- Structured-deal-terms-first contract system. A contract captures the deal as
-- queryable columns (the money "spine") plus a variables_json long tail, then
-- renders a document from reusable clause MODULES assembled via a TEMPLATE.
--
-- A contract's event_id is NULLABLE on purpose: event-bound contracts (rentals,
-- single shows) point at one event; series-bound contracts (recurring weekly
-- residencies) are venue-bound instead, since no single event row represents
-- "every Thursday".
--
-- DDL only. Seed the clause library separately with:
--   php database/seed_contracts.php

-- Reusable clause library --------------------------------------------------
CREATE TABLE IF NOT EXISTS contract_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(80) NOT NULL UNIQUE,            -- stable slug, e.g. 'recurring_event'
  name VARCHAR(255) NOT NULL,
  category ENUM('base','financial','operational','legal','risk') NOT NULL DEFAULT 'operational',
  body_template MEDIUMTEXT NOT NULL,                 -- clause text with {{variable}} tokens
  required_fields_json JSON,                          -- ["recurrence_rule","revenue_split_house"]
  risk_level ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  is_locked TINYINT(1) NOT NULL DEFAULT 0,            -- legal clauses only admins may edit/remove
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A named, ordered set of modules for a contract type -----------------------
CREATE TABLE IF NOT EXISTS contract_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  contract_type ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  intro_text MEDIUMTEXT,                              -- optional preamble above the sections
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_template_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  module_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,          -- always included, condition ignored
  condition_json JSON,                                 -- include_when rule for smart auto-select
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_module (template_id, module_id),
  FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES contract_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The deal record -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NULL,                                   -- NULL for series-bound residencies
  venue_id INT NULL,
  template_id INT NULL,
  contract_type ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','needs_review','approved','sent','signed','canceled','superseded') NOT NULL DEFAULT 'draft',

  -- counterparty
  counterparty_name VARCHAR(255) NULL,
  counterparty_org VARCHAR(255) NULL,
  counterparty_email VARCHAR(255) NULL,

  -- money / deal-term spine (queryable; feeds settlement + reporting)
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

  -- recurring / residency terms
  recurrence_rule VARCHAR(255) NULL,                   -- "Weekly — Thursdays"
  term_start DATE NULL,
  term_end DATE NULL,
  trial_period_weeks INT NULL,
  termination_notice_days INT NULL,
  review_cadence VARCHAR(120) NULL,                    -- "Monthly"
  revenue_split_house DECIMAL(5,2) NULL,
  revenue_split_producer DECIMAL(5,2) NULL,

  -- long tail + internal
  variables_json JSON,                                 -- module-specific variables
  internal_notes TEXT,                                 -- NEVER rendered into the document

  -- workflow metadata
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

-- The modules actually placed on a contract (editable snapshot of the clause) -
CREATE TABLE IF NOT EXISTS contract_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  module_id INT NULL,                                  -- NULL = ad-hoc custom section
  module_key VARCHAR(80) NULL,                         -- snapshot of source module key
  title VARCHAR(255) NOT NULL,
  body_template MEDIUMTEXT NOT NULL,                   -- editable snapshot of the module body
  sort_order INT NOT NULL DEFAULT 0,
  included TINYINT(1) NOT NULL DEFAULT 1,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  auto_selected TINYINT(1) NOT NULL DEFAULT 0,         -- added by smart auto-selection
  risk_level ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  required_fields_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sections_contract (contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES contract_modules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable rendered snapshots ----------------------------------------------
CREATE TABLE IF NOT EXISTS contract_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  version_number INT NOT NULL DEFAULT 1,
  rendered_html MEDIUMTEXT,
  rendered_text MEDIUMTEXT,
  variables_snapshot_json JSON,
  summary_json JSON,                                   -- deal summary at render time
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_versions_contract (contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
