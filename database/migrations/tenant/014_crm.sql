-- Migration 014 (tenant): client/promoter CRM profiles.
--
-- Adds a relationship-management layer for repeat clients and promoters:
--   client_profiles — promoter/client master record
--   client_events   — links profiles to their events (many-to-many)
--   client_notes    — notes, communication log, and follow-up tasks per profile

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. client_profiles ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `client_profiles` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `type`                ENUM('promoter','client','artist','company','venue','other')
                          NOT NULL DEFAULT 'client',
  `name`                VARCHAR(255)  NOT NULL,
  `org_name`            VARCHAR(255)  DEFAULT NULL,
  `email`               VARCHAR(255)  DEFAULT NULL,
  `phone`               VARCHAR(60)   DEFAULT NULL,
  `website`             VARCHAR(500)  DEFAULT NULL,
  `instagram_url`       VARCHAR(500)  DEFAULT NULL,
  `relationship_owner_id` INT(11)     DEFAULT NULL,
  `relationship_status` ENUM('prospect','active','paused','ended','vip')
                          NOT NULL DEFAULT 'prospect',
  `revenue_tier`        ENUM('unknown','low','medium','high','vip')
                          NOT NULL DEFAULT 'unknown',
  `rebook_potential`    ENUM('unknown','unlikely','possible','likely','confirmed')
                          NOT NULL DEFAULT 'unknown',
  `preferred_room`      VARCHAR(120)  DEFAULT NULL,
  `preferred_event_types` VARCHAR(255) DEFAULT NULL,
  `event_count`         INT(11)       NOT NULL DEFAULT 0,
  `total_revenue`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `last_event_date`     DATE          DEFAULT NULL,
  `tags`                VARCHAR(500)  DEFAULT NULL,
  `notes`               TEXT          DEFAULT NULL,
  `consent_marketing`   TINYINT(1)    NOT NULL DEFAULT 0,
  `consent_date`        DATE          DEFAULT NULL,
  `contact_id`          INT(11)       DEFAULT NULL,
  `created_by_id`       INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_profiles_status`  (`relationship_status`),
  KEY `idx_profiles_tier`    (`revenue_tier`),
  KEY `relationship_owner_id` (`relationship_owner_id`),
  KEY `contact_id`           (`contact_id`),
  CONSTRAINT `client_profiles_ibfk_owner`   FOREIGN KEY (`relationship_owner_id`) REFERENCES `users`    (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_profiles_ibfk_contact` FOREIGN KEY (`contact_id`)            REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. client_events ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `client_events` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `profile_id`    INT(11)   NOT NULL,
  `event_id`      INT(11)   NOT NULL,
  `role`          ENUM('client','promoter','artist','co_promoter','other') NOT NULL DEFAULT 'client',
  `revenue`       DECIMAL(10,2) DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_profile_event` (`profile_id`, `event_id`),
  KEY `profile_id` (`profile_id`),
  KEY `event_id`   (`event_id`),
  CONSTRAINT `client_events_ibfk_profile` FOREIGN KEY (`profile_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_events_ibfk_event`   FOREIGN KEY (`event_id`)   REFERENCES `events`          (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. client_notes ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `client_notes` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `profile_id`  INT(11)   NOT NULL,
  `user_id`     INT(11)   DEFAULT NULL,
  `type`        ENUM('note','task','followup','communication','audit') NOT NULL DEFAULT 'note',
  `body`        TEXT      NOT NULL,
  `is_done`     TINYINT(1) NOT NULL DEFAULT 0,
  `due_date`    DATE      DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `profile_id` (`profile_id`),
  KEY `user_id`    (`user_id`),
  CONSTRAINT `client_notes_ibfk_profile` FOREIGN KEY (`profile_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_notes_ibfk_user`    FOREIGN KEY (`user_id`)     REFERENCES `users`           (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Add client_profile_id to events ───────────────────────────────────────

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `client_profile_id` INT(11) DEFAULT NULL AFTER `lead_id`;

SET foreign_key_checks = 1;
