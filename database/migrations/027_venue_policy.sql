-- Migration 015 (single-tenant): venue policy configuration (versioned).
--
-- Adds structured, versioned venue operational policy configuration:
--   venue_policies — one active policy per venue at any time; versioned with
--                    effective dates so historical bookings reference the
--                    policy version in effect when they were booked.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `venue_policies` (
  `id`                    INT(11)       NOT NULL AUTO_INCREMENT,
  `venue_id`              INT(11)       NOT NULL,
  `version`               INT(11)       NOT NULL DEFAULT 1,
  `is_active`             TINYINT(1)    NOT NULL DEFAULT 1,
  `effective_from`        DATE          NOT NULL DEFAULT (CURDATE()),
  `effective_to`          DATE          DEFAULT NULL,
  -- Room / capacity
  `rooms_json`            LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                            CHECK (json_valid(`rooms_json`)),
  -- Age and alcohol rules
  `default_age_rule`      ENUM('all_ages','18_plus','21_plus','venue_discretion')
                            NOT NULL DEFAULT 'venue_discretion',
  `default_alcohol_mode`  ENUM('none','cash_bar','hosted_bar','bar_minimum','venue_discretion')
                            NOT NULL DEFAULT 'venue_discretion',
  `default_bar_minimum`   DECIMAL(10,2) DEFAULT NULL,
  -- Deposit policy
  `deposit_required`      TINYINT(1)    NOT NULL DEFAULT 1,
  `deposit_pct`           DECIMAL(5,2)  DEFAULT NULL,
  `deposit_flat`          DECIMAL(10,2) DEFAULT NULL,
  `deposit_due_days`      INT(11)       NOT NULL DEFAULT 14,
  -- Operating hours / curfew
  `doors_earliest`        TIME          DEFAULT NULL,
  `curfew_time`           TIME          DEFAULT NULL,
  `load_in_earliest`      TIME          DEFAULT NULL,
  -- Staffing defaults (JSON: role → hourly_rate)
  `staffing_rates_json`   LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                            CHECK (json_valid(`staffing_rates_json`)),
  -- Contract requirement
  `contract_required`     TINYINT(1)    NOT NULL DEFAULT 1,
  `coi_required`          TINYINT(1)    NOT NULL DEFAULT 0,
  -- Notes
  `notes`                 TEXT          DEFAULT NULL,
  `is_verified`           TINYINT(1)    NOT NULL DEFAULT 0,
  `created_by_id`         INT(11)       DEFAULT NULL,
  `created_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_policy_venue`   (`venue_id`),
  KEY `idx_policy_active`  (`venue_id`, `is_active`),
  CONSTRAINT `venue_policies_ibfk_venue`   FOREIGN KEY (`venue_id`)   REFERENCES `venues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `venue_policies_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
