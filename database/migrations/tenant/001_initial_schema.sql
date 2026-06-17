-- =============================================================================
-- Panic Backstage — tenant database initial schema
--
-- Applied by TenantProvisioner to every fresh tenant database.
-- Converted from database/schema.sql to CREATE TABLE IF NOT EXISTS so this
-- file is safe to re-run (idempotent); it will not destroy existing data.
--
-- Squashed through migration 014_global_viewer_role (2026-06-14).
-- Future schema changes go in 002_*.sql, 003_*.sql etc.
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- Migration ledger (used by scripts/migrate.php for future migrations).
-- Schema intentionally matches the existing single-tenant ledger (no checksum)
-- so the same migrate.php works against all database types.
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `filename`   VARCHAR(255) NOT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bands / acts master list.
CREATE TABLE IF NOT EXISTS `bands` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(255) NOT NULL,
  `contact_name`  VARCHAR(255) DEFAULT NULL,
  `contact_email` VARCHAR(255) DEFAULT NULL,
  `contact_phone` VARCHAR(80)  DEFAULT NULL,
  `instagram_url` VARCHAR(500) DEFAULT NULL,
  `website_url`   VARCHAR(500) DEFAULT NULL,
  `bio`           TEXT         DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM contacts.
CREATE TABLE IF NOT EXISTS `contacts` (
  `id`                BIGINT(20)     NOT NULL AUTO_INCREMENT,
  `external_id`       BIGINT(20)     DEFAULT NULL,
  `source`            VARCHAR(40)    NOT NULL DEFAULT 'manual',
  `first_name`        VARCHAR(120)   DEFAULT NULL,
  `last_name`         VARCHAR(160)   DEFAULT NULL,
  `email`             VARCHAR(255)   DEFAULT NULL,
  `phone`             VARCHAR(40)    DEFAULT NULL,
  `gender`            VARCHAR(20)    DEFAULT NULL,
  `birthday`          DATE           DEFAULT NULL,
  `events_count`      INT(11)        NOT NULL DEFAULT 0,
  `q_events_count`    INT(11)        NOT NULL DEFAULT 0,
  `tickets_count`     INT(11)        NOT NULL DEFAULT 0,
  `usd_spend`         DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `follows`           INT(11)        NOT NULL DEFAULT 0,
  `last_interaction`  DATETIME       DEFAULT NULL,
  `influencer_id`     VARCHAR(80)    DEFAULT NULL,
  `marketing_opted_in` TINYINT(1)   NOT NULL DEFAULT 0,
  `opt_in_date`       DATE           DEFAULT NULL,
  `notes`             TEXT           DEFAULT NULL,
  `created_at`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_external` (`source`, `external_id`),
  KEY `idx_email`     (`email`),
  KEY `idx_last_name` (`last_name`),
  KEY `idx_marketing` (`marketing_opted_in`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract clause library.
CREATE TABLE IF NOT EXISTS `contract_modules` (
  `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
  `module_key`           VARCHAR(80)  NOT NULL,
  `name`                 VARCHAR(255) NOT NULL,
  `category`             ENUM('base','financial','operational','legal','risk') NOT NULL DEFAULT 'operational',
  `body_template`        MEDIUMTEXT   NOT NULL,
  `required_fields_json` LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `risk_level`           ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  `is_locked`            TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`           INT(11)      NOT NULL DEFAULT 0,
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract templates.
CREATE TABLE IF NOT EXISTS `contract_templates` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(255) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `contract_type` ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  `intro_text`    MEDIUMTEXT   DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table (referenced by many other tables; create before FKs).
CREATE TABLE IF NOT EXISTS `users` (
  `id`                          INT(11)      NOT NULL AUTO_INCREMENT,
  `name`                        VARCHAR(255) NOT NULL,
  `email`                       VARCHAR(255) NOT NULL,
  `phone`                       VARCHAR(64)  DEFAULT NULL,
  `password_hash`               VARCHAR(255) DEFAULT NULL,
  `role`                        ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer','global_viewer') NOT NULL DEFAULT 'viewer',
  `access_status`               ENUM('active','requested') NOT NULL DEFAULT 'active',
  `request_notes`               TEXT         DEFAULT NULL,
  `hide_credential_setup_prompt` TINYINT(1)  NOT NULL DEFAULT 0,
  `default_landing`             VARCHAR(32)  DEFAULT NULL,
  `nav_collapsed`               TINYINT(1)   NOT NULL DEFAULT 0,
  `events_sort`                 VARCHAR(8)   DEFAULT NULL,
  `alt_emails`                  LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`alt_emails`)),
  `created_at`                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Venues.
CREATE TABLE IF NOT EXISTS `venues` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `slug`       VARCHAR(255) NOT NULL,
  `address`    VARCHAR(255) DEFAULT NULL,
  `city`       VARCHAR(120) DEFAULT NULL,
  `state`      VARCHAR(60)  DEFAULT NULL,
  `timezone`   VARCHAR(80)  NOT NULL DEFAULT 'America/Los_Angeles',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events.
CREATE TABLE IF NOT EXISTS `events` (
  `id`                     INT(11)       NOT NULL AUTO_INCREMENT,
  `external_id`            VARCHAR(50)   DEFAULT NULL,
  `venue_id`               INT(11)       NOT NULL,
  `title`                  VARCHAR(255)  NOT NULL,
  `slug`                   VARCHAR(255)  NOT NULL,
  `event_type`             ENUM('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  `status`                 ENUM('empty','proposed','confirmed','booked','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled') NOT NULL DEFAULT 'proposed',
  `description_public`     TEXT          DEFAULT NULL,
  `description_internal`   TEXT          DEFAULT NULL,
  `av_requirements`        TEXT          DEFAULT NULL,
  `catering_notes`         TEXT          DEFAULT NULL,
  `referral_source`        VARCHAR(255)  DEFAULT NULL,
  `promoter_name`          VARCHAR(255)  DEFAULT NULL,
  `promoter_email`         VARCHAR(255)  DEFAULT NULL,
  `promoter_phone`         VARCHAR(50)   DEFAULT NULL,
  `client_org`             VARCHAR(255)  DEFAULT NULL,
  `booker_name`            VARCHAR(255)  DEFAULT NULL,
  `booker_email`           VARCHAR(255)  DEFAULT NULL,
  `booker_phone`           VARCHAR(50)   DEFAULT NULL,
  `date`                   DATE          NOT NULL,
  `doors_time`             TIME          DEFAULT NULL,
  `show_time`              TIME          DEFAULT NULL,
  `end_time`               TIME          DEFAULT NULL,
  `load_in_time`           TIME          DEFAULT NULL,
  `age_restriction`        VARCHAR(80)   DEFAULT NULL,
  `ticket_price`           DECIMAL(10,2) DEFAULT 0.00,
  `deposit_amount`         DECIMAL(10,2) DEFAULT NULL,
  `potential_revenue`      DECIMAL(10,2) DEFAULT NULL,
  `ticket_url`             VARCHAR(500)  DEFAULT NULL,
  `ticket_system`          VARCHAR(40)   DEFAULT NULL,
  `contract_url`           VARCHAR(500)  DEFAULT NULL,
  `venue_contract_url`     VARCHAR(500)  DEFAULT NULL,
  `walkthrough_done`       TINYINT(1)    NOT NULL DEFAULT 0,
  `settlement_doc_url`     VARCHAR(500)  DEFAULT NULL,
  `capacity`               INT(11)       DEFAULT NULL,
  `estimated_guests`       INT(11)       DEFAULT NULL,
  `room`                   ENUM('upstairs','downstairs','both') DEFAULT NULL,
  `public_visibility`      TINYINT(1)    NOT NULL DEFAULT 0,
  `owner_user_id`          INT(11)       DEFAULT NULL,
  `ticketing_mode`         ENUM('external','internal') NOT NULL DEFAULT 'external',
  `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug`                 (`slug`),
  UNIQUE KEY `idx_events_external_id` (`external_id`),
  KEY `venue_id`       (`venue_id`),
  KEY `owner_user_id`  (`owner_user_id`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`venue_id`)      REFERENCES `venues` (`id`),
  CONSTRAINT `events_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contracts (references events, venues, contract_templates, users).
CREATE TABLE IF NOT EXISTS `contracts` (
  `id`                    INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`              INT(11)       DEFAULT NULL,
  `venue_id`              INT(11)       DEFAULT NULL,
  `template_id`           INT(11)       DEFAULT NULL,
  `contract_type`         ENUM('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  `title`                 VARCHAR(255)  NOT NULL,
  `status`                ENUM('draft','needs_review','approved','sent','signed','canceled','superseded') NOT NULL DEFAULT 'draft',
  `counterparty_name`     VARCHAR(255)  DEFAULT NULL,
  `counterparty_org`      VARCHAR(255)  DEFAULT NULL,
  `counterparty_email`    VARCHAR(255)  DEFAULT NULL,
  `rental_fee`            DECIMAL(10,2) DEFAULT NULL,
  `deposit_amount`        DECIMAL(10,2) DEFAULT NULL,
  `balance_due_date`      DATE          DEFAULT NULL,
  `bar_minimum`           DECIMAL(10,2) DEFAULT NULL,
  `guarantee_amount`      DECIMAL(10,2) DEFAULT NULL,
  `door_split_artist`     DECIMAL(5,2)  DEFAULT NULL,
  `door_split_venue`      DECIMAL(5,2)  DEFAULT NULL,
  `door_split_promoter`   DECIMAL(5,2)  DEFAULT NULL,
  `advance_ticket_price`  DECIMAL(10,2) DEFAULT NULL,
  `door_ticket_price`     DECIMAL(10,2) DEFAULT NULL,
  `security_count`        INT(11)       DEFAULT NULL,
  `security_rate`         DECIMAL(10,2) DEFAULT NULL,
  `security_paid_by`      ENUM('venue','artist','promoter','client','shared') DEFAULT NULL,
  `sound_tech_included`   TINYINT(1)    DEFAULT NULL,
  `lighting_tech_included` TINYINT(1)  DEFAULT NULL,
  `merch_venue_percent`   DECIMAL(5,2)  DEFAULT NULL,
  `recurrence_rule`       VARCHAR(255)  DEFAULT NULL,
  `term_start`            DATE          DEFAULT NULL,
  `term_end`              DATE          DEFAULT NULL,
  `trial_period_weeks`    INT(11)       DEFAULT NULL,
  `termination_notice_days` INT(11)     DEFAULT NULL,
  `review_cadence`        VARCHAR(120)  DEFAULT NULL,
  `revenue_split_house`   DECIMAL(5,2)  DEFAULT NULL,
  `revenue_split_producer` DECIMAL(5,2) DEFAULT NULL,
  `variables_json`        LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables_json`)),
  `internal_notes`        TEXT          DEFAULT NULL,
  `current_version_id`    INT(11)       DEFAULT NULL,
  `created_by_user_id`    INT(11)       DEFAULT NULL,
  `approved_by_user_id`   INT(11)       DEFAULT NULL,
  `sent_at`               TIMESTAMP     NULL DEFAULT NULL,
  `signed_at`             TIMESTAMP     NULL DEFAULT NULL,
  `created_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contracts_event`   (`event_id`),
  KEY `idx_contracts_status`  (`status`),
  KEY `idx_contracts_type`    (`contract_type`),
  KEY `venue_id`              (`venue_id`),
  KEY `template_id`           (`template_id`),
  KEY `created_by_user_id`    (`created_by_user_id`),
  KEY `approved_by_user_id`   (`approved_by_user_id`),
  CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`event_id`)           REFERENCES `events`             (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_2` FOREIGN KEY (`venue_id`)           REFERENCES `venues`             (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_3` FOREIGN KEY (`template_id`)        REFERENCES `contract_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_4` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`              (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_5` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`             (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract sections (per-contract copy of modules).
CREATE TABLE IF NOT EXISTS `contract_sections` (
  `id`                   INT(11)     NOT NULL AUTO_INCREMENT,
  `contract_id`          INT(11)     NOT NULL,
  `module_id`            INT(11)     DEFAULT NULL,
  `module_key`           VARCHAR(80) DEFAULT NULL,
  `title`                VARCHAR(255) NOT NULL,
  `body_template`        MEDIUMTEXT  NOT NULL,
  `sort_order`           INT(11)     NOT NULL DEFAULT 0,
  `included`             TINYINT(1)  NOT NULL DEFAULT 1,
  `is_locked`            TINYINT(1)  NOT NULL DEFAULT 0,
  `auto_selected`        TINYINT(1)  NOT NULL DEFAULT 0,
  `risk_level`           ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  `required_fields_json` LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `created_at`           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sections_contract` (`contract_id`),
  KEY `module_id`             (`module_id`),
  CONSTRAINT `contract_sections_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts`        (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_sections_ibfk_2` FOREIGN KEY (`module_id`)   REFERENCES `contract_modules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract template ↔ module mappings.
CREATE TABLE IF NOT EXISTS `contract_template_modules` (
  `id`             INT(11)  NOT NULL AUTO_INCREMENT,
  `template_id`    INT(11)  NOT NULL,
  `module_id`      INT(11)  NOT NULL,
  `sort_order`     INT(11)  NOT NULL DEFAULT 0,
  `is_required`    TINYINT(1) NOT NULL DEFAULT 0,
  `condition_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`condition_json`)),
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_module` (`template_id`, `module_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `contract_template_modules_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `contract_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_template_modules_ibfk_2` FOREIGN KEY (`module_id`)   REFERENCES `contract_modules`   (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract versions (rendered snapshots).
CREATE TABLE IF NOT EXISTS `contract_versions` (
  `id`                       INT(11)   NOT NULL AUTO_INCREMENT,
  `contract_id`              INT(11)   NOT NULL,
  `version_number`           INT(11)   NOT NULL DEFAULT 1,
  `rendered_html`            MEDIUMTEXT DEFAULT NULL,
  `rendered_text`            MEDIUMTEXT DEFAULT NULL,
  `variables_snapshot_json`  LONGTEXT  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables_snapshot_json`)),
  `summary_json`             LONGTEXT  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary_json`)),
  `created_by_user_id`       INT(11)   DEFAULT NULL,
  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_versions_contract`  (`contract_id`),
  KEY `created_by_user_id`     (`created_by_user_id`),
  CONSTRAINT `contract_versions_ibfk_1` FOREIGN KEY (`contract_id`)        REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_versions_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification tokens.
CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_verif_user`  (`user_id`),
  KEY `idx_email_verif_email` (`email`),
  CONSTRAINT `email_verification_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event activity log.
CREATE TABLE IF NOT EXISTS `event_activity_log` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `event_id`     INT(11)     NOT NULL,
  `user_id`      INT(11)     DEFAULT NULL,
  `action`       VARCHAR(120) NOT NULL,
  `details_json` LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `user_id`  (`user_id`),
  CONSTRAINT `event_activity_log_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_activity_log_ibfk_2` FOREIGN KEY (`user_id`)  REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event assets (uploaded files).
CREATE TABLE IF NOT EXISTS `event_assets` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)      NOT NULL,
  `asset_type`          ENUM('flyer','poster','band_photo','logo','social_square','social_story','press_photo','other') NOT NULL DEFAULT 'other',
  `title`               VARCHAR(255) NOT NULL,
  `filename`            VARCHAR(255) NOT NULL,
  `original_filename`   VARCHAR(255) NOT NULL,
  `file_path`           VARCHAR(500) NOT NULL,
  `uploaded_by_user_id` INT(11)      DEFAULT NULL,
  `approval_status`     ENUM('draft','needs_review','approved','rejected') NOT NULL DEFAULT 'needs_review',
  `notes`               TEXT         DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id`            (`event_id`),
  KEY `uploaded_by_user_id` (`uploaded_by_user_id`),
  CONSTRAINT `event_assets_ibfk_1` FOREIGN KEY (`event_id`)            REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_assets_ibfk_2` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Open items / blockers.
CREATE TABLE IF NOT EXISTS `event_blockers` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`      INT(11)      NOT NULL,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `owner_user_id` INT(11)      DEFAULT NULL,
  `status`        ENUM('open','waiting','resolved','canceled') NOT NULL DEFAULT 'open',
  `due_date`      DATE         DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id`      (`event_id`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `event_blockers_ibfk_1` FOREIGN KEY (`event_id`)      REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_blockers_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event collaborators.
CREATE TABLE IF NOT EXISTS `event_collaborators` (
  `id`         INT(11)   NOT NULL AUTO_INCREMENT,
  `event_id`   INT(11)   NOT NULL,
  `user_id`    INT(11)   NOT NULL,
  `role`       ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_user` (`event_id`, `user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `event_collaborators_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_collaborators_ibfk_2` FOREIGN KEY (`user_id`)  REFERENCES `users`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guest list.
CREATE TABLE IF NOT EXISTS `event_guest_list` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`           INT(11)      NOT NULL,
  `name`               VARCHAR(255) NOT NULL,
  `email`              VARCHAR(255) DEFAULT NULL,
  `party_size`         INT(11)      NOT NULL DEFAULT 1,
  `list_type`          ENUM('comp','guest','will_call','vip','press','industry') NOT NULL DEFAULT 'guest',
  `comp_order_id`      INT(11)      DEFAULT NULL,
  `guest_of`           VARCHAR(255) DEFAULT NULL,
  `notes`              TEXT         DEFAULT NULL,
  `checked_in`         TINYINT(1)   NOT NULL DEFAULT 0,
  `checked_in_at`      TIMESTAMP    NULL DEFAULT NULL,
  `created_by_user_id` INT(11)      DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guest_event`     (`event_id`),
  KEY `created_by_user_id`  (`created_by_user_id`),
  CONSTRAINT `event_guest_list_ibfk_1` FOREIGN KEY (`event_id`)           REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_guest_list_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event invites.
CREATE TABLE IF NOT EXISTS `event_invites` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`   INT(11)      NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `role`       ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
  `token`      VARCHAR(255) NOT NULL,
  `used_at`    TIMESTAMP    NULL DEFAULT NULL,
  `expires_at` TIMESTAMP    NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token`    (`token`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_invites_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event lineup.
CREATE TABLE IF NOT EXISTS `event_lineup` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`           INT(11)      NOT NULL,
  `band_id`            INT(11)      DEFAULT NULL,
  `billing_order`      INT(11)      NOT NULL DEFAULT 0,
  `display_name`       VARCHAR(255) NOT NULL,
  `set_time`           TIME         DEFAULT NULL,
  `set_length_minutes` INT(11)      DEFAULT NULL,
  `payout_terms`       VARCHAR(255) DEFAULT NULL,
  `status`             ENUM('invited','tentative','confirmed','canceled') NOT NULL DEFAULT 'tentative',
  `notes`              TEXT         DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `band_id`  (`band_id`),
  CONSTRAINT `event_lineup_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_lineup_ibfk_2` FOREIGN KEY (`band_id`)  REFERENCES `bands`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Door scanner QR links.
CREATE TABLE IF NOT EXISTS `event_scanner_links` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)     NOT NULL,
  `label`               VARCHAR(120) DEFAULT NULL,
  `token_hash`          CHAR(64)    NOT NULL,
  `token`               VARCHAR(64) DEFAULT NULL,
  `pin_hash`            VARCHAR(255) DEFAULT NULL,
  `created_by_user_id`  INT(11)     DEFAULT NULL,
  `expires_at`          DATETIME    DEFAULT NULL,
  `revoked_at`          DATETIME    DEFAULT NULL,
  `last_used_at`        DATETIME    DEFAULT NULL,
  `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_scanner_token`       (`token_hash`),
  KEY `idx_event_scanner_links_event` (`event_id`),
  CONSTRAINT `event_scanner_links_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Day-of schedule items.
CREATE TABLE IF NOT EXISTS `event_schedule_items` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`   INT(11)      NOT NULL,
  `title`      VARCHAR(255) NOT NULL,
  `item_type`  ENUM('load_in','soundcheck','doors','set','changeover','curfew','staff_call','other') NOT NULL DEFAULT 'other',
  `start_time` TIME         DEFAULT NULL,
  `end_time`   TIME         DEFAULT NULL,
  `notes`      TEXT         DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_schedule_items_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Financial settlements.
CREATE TABLE IF NOT EXISTS `event_settlements` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)       NOT NULL,
  `gross_ticket_sales`  DECIMAL(10,2) DEFAULT 0.00,
  `tickets_sold`        INT(11)       DEFAULT 0,
  `bar_sales`           DECIMAL(10,2) DEFAULT 0.00,
  `expenses`            DECIMAL(10,2) DEFAULT 0.00,
  `band_payouts`        DECIMAL(10,2) DEFAULT 0.00,
  `promoter_payout`     DECIMAL(10,2) DEFAULT 0.00,
  `venue_net`           DECIMAL(10,2) DEFAULT 0.00,
  `notes`               TEXT          DEFAULT NULL,
  `settled_by_user_id`  INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id`           (`event_id`),
  KEY `settled_by_user_id` (`settled_by_user_id`),
  CONSTRAINT `event_settlements_ibfk_1` FOREIGN KEY (`event_id`)           REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_settlements_ibfk_2` FOREIGN KEY (`settled_by_user_id`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google Sheets shadow copy (for sync reconciliation).
CREATE TABLE IF NOT EXISTS `event_sheet_shadow` (
  `event_id`  INT(11)  NOT NULL,
  `raw_json`  LONGTEXT NOT NULL,
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Event staffing assignments.
CREATE TABLE IF NOT EXISTS `event_staffing` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`        INT(11)       NOT NULL,
  `staff_member_id` INT(11)       DEFAULT NULL,
  `role`            ENUM('manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other') NOT NULL DEFAULT 'other',
  `call_time`       TIME          DEFAULT NULL,
  `end_time`        TIME          DEFAULT NULL,
  `hourly_rate`     DECIMAL(10,2) DEFAULT NULL,
  `status`          ENUM('scheduled','confirmed','declined','no_show','completed','canceled') NOT NULL DEFAULT 'scheduled',
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staffing_event` (`event_id`),
  KEY `idx_staffing_role`  (`role`),
  KEY `staff_member_id`    (`staff_member_id`),
  CONSTRAINT `event_staffing_ibfk_1` FOREIGN KEY (`event_id`)        REFERENCES `events`        (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_staffing_ibfk_2` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event tasks / checklist.
CREATE TABLE IF NOT EXISTS `event_tasks` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`         INT(11)      NOT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `description`      TEXT         DEFAULT NULL,
  `status`           ENUM('todo','in_progress','blocked','done','canceled') NOT NULL DEFAULT 'todo',
  `assigned_user_id` INT(11)      DEFAULT NULL,
  `due_date`         DATE         DEFAULT NULL,
  `priority`         ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id`         (`event_id`),
  KEY `assigned_user_id` (`assigned_user_id`),
  CONSTRAINT `event_tasks_ibfk_1` FOREIGN KEY (`event_id`)         REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_tasks_ibfk_2` FOREIGN KEY (`assigned_user_id`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event templates.
CREATE TABLE IF NOT EXISTS `event_templates` (
  `id`                        INT(11)       NOT NULL AUTO_INCREMENT,
  `venue_id`                  INT(11)       NOT NULL,
  `name`                      VARCHAR(255)  NOT NULL,
  `event_type`                ENUM('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  `default_title`             VARCHAR(255)  DEFAULT NULL,
  `default_description_public` TEXT         DEFAULT NULL,
  `default_ticket_price`      DECIMAL(10,2) DEFAULT 0.00,
  `default_age_restriction`   VARCHAR(80)   DEFAULT NULL,
  `checklist_json`            LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist_json`)),
  `schedule_json`             LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule_json`)),
  `created_at`                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `event_templates_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Magic-link auth tokens.
CREATE TABLE IF NOT EXISTS `magic_link_tokens` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `used_at`    TIMESTAMP    NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash`   (`token_hash`),
  KEY `idx_mlt_hash`  (`token_hash`),
  KEY `idx_mlt_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passkeys (WebAuthn).
CREATE TABLE IF NOT EXISTS `passkeys` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`        INT(11)       NOT NULL,
  `credential_id`  VARCHAR(1024) NOT NULL,
  `public_key_pem` TEXT          NOT NULL,
  `sign_count`     BIGINT(20)    NOT NULL DEFAULT 0,
  `transports`     VARCHAR(255)  DEFAULT NULL,
  `name`           VARCHAR(255)  NOT NULL DEFAULT 'Passkey',
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`   TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credential_id`    (`credential_id`) USING HASH,
  KEY `idx_passkeys_user` (`user_id`),
  CONSTRAINT `passkeys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment provider settings.
CREATE TABLE IF NOT EXISTS `payment_settings` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `active_provider`     VARCHAR(40) NOT NULL DEFAULT 'square',
  `currency`            CHAR(3)     NOT NULL DEFAULT 'USD',
  `settings_json`       LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings_json`)),
  `updated_by_user_id`  INT(11)     DEFAULT NULL,
  `updated_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — broadcast results.
CREATE TABLE IF NOT EXISTS `promote_broadcast_results` (
  `id`               INT(11)     NOT NULL AUTO_INCREMENT,
  `broadcast_id`     INT(11)     NOT NULL,
  `destination_key`  VARCHAR(80) NOT NULL,
  `destination_group` VARCHAR(80) NOT NULL,
  `status`           ENUM('queued','sent','manual_required','needs_auth','failed','skipped') NOT NULL DEFAULT 'queued',
  `external_url`     VARCHAR(500) DEFAULT NULL,
  `error_message`    TEXT         DEFAULT NULL,
  `response_json`    LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `created_at`       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `broadcast_id` (`broadcast_id`),
  CONSTRAINT `promote_broadcast_results_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `promote_broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — broadcasts.
CREATE TABLE IF NOT EXISTS `promote_broadcasts` (
  `id`                  INT(11)   NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)   NOT NULL,
  `post_id`             INT(11)   NOT NULL,
  `created_by_user_id`  INT(11)   DEFAULT NULL,
  `send_mode`           ENUM('now','scheduled') NOT NULL DEFAULT 'now',
  `scheduled_at`        DATETIME  DEFAULT NULL,
  `status`              ENUM('draft','queued','processing','completed','partial_failure','failed') NOT NULL DEFAULT 'queued',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id`            (`post_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `event_id`           (`event_id`),
  CONSTRAINT `promote_broadcasts_ibfk_2`     FOREIGN KEY (`post_id`)            REFERENCES `promote_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_broadcasts_ibfk_3`     FOREIGN KEY (`created_by_user_id`) REFERENCES `users`         (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_broadcasts_ibfk_event` FOREIGN KEY (`event_id`)           REFERENCES `events`        (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — credentials (OAuth tokens per destination).
CREATE TABLE IF NOT EXISTS `promote_credentials` (
  `id`                INT(11)     NOT NULL AUTO_INCREMENT,
  `venue_id`          INT(11)     NOT NULL,
  `destination_key`   VARCHAR(80) NOT NULL,
  `access_token`      TEXT        DEFAULT NULL,
  `refresh_token`     TEXT        DEFAULT NULL,
  `token_expires_at`  DATETIME    DEFAULT NULL,
  `config`            LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `status`            ENUM('connected','needs_auth','error') NOT NULL DEFAULT 'needs_auth',
  `error_message`     TEXT        DEFAULT NULL,
  `connected_at`      DATETIME    DEFAULT NULL,
  `created_at`        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_venue_destination` (`venue_id`, `destination_key`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `promote_credentials_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — destinations (configured channels).
CREATE TABLE IF NOT EXISTS `promote_destinations` (
  `id`                INT(11)     NOT NULL AUTO_INCREMENT,
  `destination_key`   VARCHAR(80) NOT NULL,
  `destination_group` ENUM('direct_post','event_platform','editorial_submission','email') NOT NULL,
  `label`             VARCHAR(120) NOT NULL,
  `status`            ENUM('connected','needs_auth','manual_submission','disabled') NOT NULL DEFAULT 'manual_submission',
  `config_json`       LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_json`)),
  `created_at`        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_destination_key` (`destination_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — post variants (per-channel copy).
CREATE TABLE IF NOT EXISTS `promote_post_variants` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `post_id`      INT(11)     NOT NULL,
  `channel`      VARCHAR(80) NOT NULL,
  `title`        VARCHAR(255) DEFAULT NULL,
  `body`         TEXT         DEFAULT NULL,
  `status`       ENUM('draft','ready','needs_review','approved') NOT NULL DEFAULT 'draft',
  `warnings_json` LONGTEXT   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`warnings_json`)),
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_channel` (`post_id`, `channel`),
  CONSTRAINT `promote_post_variants_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `promote_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — posts (master content unit).
CREATE TABLE IF NOT EXISTS `promote_posts` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)      NOT NULL,
  `asset_id`            INT(11)      DEFAULT NULL,
  `title`               VARCHAR(255) NOT NULL,
  `master_text`         TEXT         DEFAULT NULL,
  `target_url`          VARCHAR(500) DEFAULT NULL,
  `status`              ENUM('draft','approved','scheduled','sent','archived') NOT NULL DEFAULT 'draft',
  `scheduled_at`        DATETIME     DEFAULT NULL,
  `created_by_user_id`  INT(11)      DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `asset_id`            (`asset_id`),
  KEY `created_by_user_id`  (`created_by_user_id`),
  KEY `event_id`            (`event_id`),
  CONSTRAINT `promote_posts_ibfk_2`     FOREIGN KEY (`asset_id`)           REFERENCES `event_assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_posts_ibfk_3`     FOREIGN KEY (`created_by_user_id`) REFERENCES `users`        (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_posts_ibfk_event` FOREIGN KEY (`event_id`)           REFERENCES `events`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panic Promote — per-event settings.
CREATE TABLE IF NOT EXISTS `promote_settings` (
  `event_id`            INT(11)   NOT NULL,
  `status`              ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  `goal_tickets`        INT(11)   DEFAULT NULL,
  `notes`               TEXT      DEFAULT NULL,
  `created_by_user_id`  INT(11)   DEFAULT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `promote_settings_ibfk_2` (`created_by_user_id`),
  CONSTRAINT `promote_settings_ibfk_1` FOREIGN KEY (`event_id`)           REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_settings_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- JWT refresh tokens.
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `revoked_at` TIMESTAMP    NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_rt_hash` (`token_hash`),
  KEY `user_id`     (`user_id`),
  CONSTRAINT `refresh_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google Sheets import link table.
CREATE TABLE IF NOT EXISTS `sheet_import_links` (
  `event_id`     INT(11)      NOT NULL,
  `sheet_row`    INT(11)      NOT NULL,
  `title_snap`   VARCHAR(200) NOT NULL DEFAULT '',
  `date_snap`    DATE         DEFAULT NULL,
  `linked`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_unlinked` (`linked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Google Sheets sync queue.
CREATE TABLE IF NOT EXISTS `sheet_sync_queue` (
  `id`         INT(11)   NOT NULL AUTO_INCREMENT,
  `event_id`   INT(11)   NOT NULL,
  `status`     ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  `attempts`   INT(11)   NOT NULL DEFAULT 0,
  `last_error` TEXT      DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `pushed_at`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event` (`event_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `sheet_sync_queue_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff roster.
CREATE TABLE IF NOT EXISTS `staff_members` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(255)  NOT NULL,
  `email`        VARCHAR(255)  DEFAULT NULL,
  `phone`        VARCHAR(64)   DEFAULT NULL,
  `pronoun`      VARCHAR(40)   DEFAULT NULL,
  `default_role` ENUM('manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other') NOT NULL DEFAULT 'other',
  `position`     VARCHAR(120)  DEFAULT NULL,
  `hourly_rate`  DECIMAL(10,2) DEFAULT NULL,
  `hire_date`    DATE          DEFAULT NULL,
  `notes`        TEXT          DEFAULT NULL,
  `active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `user_id`      INT(11)       DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_active`       (`active`),
  KEY `idx_staff_default_role` (`default_role`),
  KEY `user_id`                (`user_id`),
  CONSTRAINT `staff_members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket order line items.
CREATE TABLE IF NOT EXISTS `ticket_order_items` (
  `id`               INT(11) NOT NULL AUTO_INCREMENT,
  `order_id`         INT(11) NOT NULL,
  `ticket_type_id`   INT(11) NOT NULL,
  `quantity`         INT(11) NOT NULL,
  `unit_price_cents` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_type_id`                (`ticket_type_id`),
  KEY `idx_ticket_order_items_order`  (`order_id`),
  CONSTRAINT `ticket_order_items_ibfk_1` FOREIGN KEY (`order_id`)       REFERENCES `ticket_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_order_items_ibfk_2` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket orders.
CREATE TABLE IF NOT EXISTS `ticket_orders` (
  `id`                    INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`              INT(11)       NOT NULL,
  `buyer_user_id`         INT(11)       DEFAULT NULL,
  `buyer_name`            VARCHAR(200)  DEFAULT NULL,
  `buyer_email`           VARCHAR(255)  DEFAULT NULL,
  `buyer_phone`           VARCHAR(40)   DEFAULT NULL,
  `provider`              VARCHAR(40)   DEFAULT NULL,
  `provider_ref`          VARCHAR(191)  DEFAULT NULL,
  `provider_payment_ref`  VARCHAR(191)  DEFAULT NULL,
  `amount_cents`          INT(11)       NOT NULL DEFAULT 0,
  `currency`              CHAR(3)       NOT NULL DEFAULT 'USD',
  `status`                ENUM('pending','paid','fulfilled','canceled','refunded','expired') NOT NULL DEFAULT 'pending',
  `is_comp`               TINYINT(1)    NOT NULL DEFAULT 0,
  `hold_expires_at`       DATETIME      DEFAULT NULL,
  `paid_at`               DATETIME      DEFAULT NULL,
  `refunded_at`           DATETIME      DEFAULT NULL,
  `created_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `buyer_user_id`                  (`buyer_user_id`),
  KEY `idx_ticket_orders_event`        (`event_id`),
  KEY `idx_ticket_orders_provider_ref` (`provider`, `provider_ref`),
  KEY `idx_ticket_orders_status`       (`status`),
  CONSTRAINT `ticket_orders_ibfk_1` FOREIGN KEY (`event_id`)      REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_orders_ibfk_2` FOREIGN KEY (`buyer_user_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket types (tiers).
CREATE TABLE IF NOT EXISTS `ticket_types` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`       INT(11)       NOT NULL,
  `name`           VARCHAR(120)  NOT NULL,
  `description`    VARCHAR(500)  DEFAULT NULL,
  `price_cents`    INT(11)       NOT NULL DEFAULT 0,
  `currency`       CHAR(3)       NOT NULL DEFAULT 'USD',
  `quantity_total` INT(11)       NOT NULL,
  `quantity_sold`  INT(11)       NOT NULL DEFAULT 0,
  `sales_start`    DATETIME      DEFAULT NULL,
  `sales_end`      DATETIME      DEFAULT NULL,
  `status`         ENUM('draft','on_sale','paused','sold_out','closed') NOT NULL DEFAULT 'draft',
  `sort_order`     INT(11)       NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_types_event` (`event_id`),
  CONSTRAINT `ticket_types_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual tickets (issued per order item).
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`                        INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`                  INT(11)      NOT NULL,
  `ticket_type_id`            INT(11)      NOT NULL,
  `order_id`                  INT(11)      DEFAULT NULL,
  `code`                      VARCHAR(40)  NOT NULL,
  `token_hash`                CHAR(64)     NOT NULL,
  `token`                     VARCHAR(64)  DEFAULT NULL,
  `holder_name`               VARCHAR(200) DEFAULT NULL,
  `holder_email`              VARCHAR(255) DEFAULT NULL,
  `status`                    ENUM('issued','redeemed','void') NOT NULL DEFAULT 'issued',
  `redeemed_at`               DATETIME     DEFAULT NULL,
  `redeemed_by_user_id`       INT(11)      DEFAULT NULL,
  `redeemed_via_scanner_id`   INT(11)      DEFAULT NULL,
  `voided_at`                 DATETIME     DEFAULT NULL,
  `created_at`                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_token` (`token_hash`),
  UNIQUE KEY `uq_tickets_code`  (`code`),
  KEY `ticket_type_id`    (`ticket_type_id`),
  KEY `order_id`          (`order_id`),
  KEY `idx_tickets_event` (`event_id`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`event_id`)       REFERENCES `events`       (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`order_id`)       REFERENCES `ticket_orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket scan log.
CREATE TABLE IF NOT EXISTS `ticket_scans` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `ticket_id`           INT(11)     DEFAULT NULL,
  `event_id`            INT(11)     NOT NULL,
  `result`              ENUM('admitted','already_redeemed','void','not_found','wrong_event','expired_link') NOT NULL,
  `scanner_link_id`     INT(11)     DEFAULT NULL,
  `scanned_by_user_id`  INT(11)     DEFAULT NULL,
  `ip`                  VARCHAR(45) DEFAULT NULL,
  `user_agent`          VARCHAR(255) DEFAULT NULL,
  `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_scans_ticket` (`ticket_id`),
  KEY `idx_ticket_scans_event`  (`event_id`),
  CONSTRAINT `fk_ticket_scans_event`  FOREIGN KEY (`event_id`)  REFERENCES `events`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_scans_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User account merge audit trail.
CREATE TABLE IF NOT EXISTS `user_merges` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `survivor_user_id`    INT(11)     NOT NULL,
  `loser_user_id`       INT(11)     NOT NULL,
  `loser_email`         VARCHAR(255) DEFAULT NULL,
  `performed_by_user_id` INT(11)   DEFAULT NULL,
  `details`             LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_merges_survivor` (`survivor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WebAuthn challenges.
CREATE TABLE IF NOT EXISTS `webauthn_challenges` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `challenge`  VARCHAR(512) NOT NULL,
  `user_id`    INT(11)      DEFAULT NULL,
  `intent`     ENUM('register','login') NOT NULL,
  `expires_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `challenge`        (`challenge`),
  KEY `idx_wc_challenge` (`challenge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
