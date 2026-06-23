-- Tenant migration 022: Square POS location mapping + ledger source_ref_str column.
--
-- Mirrors database/migrations/034_pos_location_map.sql for multi-tenant installs.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. pos_location_map ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_location_map` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `pos_provider`     ENUM('square') NOT NULL DEFAULT 'square',
  `location_id`      VARCHAR(128) NOT NULL COMMENT 'Square location ID (e.g. LXXXXXXXXXXX)',
  `venue_id`         INT(11)      NOT NULL,
  `default_category` ENUM('bar_sales','merch_share','other_revenue') NOT NULL DEFAULT 'bar_sales',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`            VARCHAR(500) DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_location` (`pos_provider`, `location_id`),
  KEY `venue_id` (`venue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. event_ledger_entries — add source_ref_str ──────────────────────────────

ALTER TABLE `event_ledger_entries`
  ADD COLUMN IF NOT EXISTS `source_ref_str` VARCHAR(255) DEFAULT NULL
    COMMENT 'String external reference (e.g. Square payment ID) for sources that do not use an integer FK'
    AFTER `source_ref_id`;

ALTER TABLE `event_ledger_entries`
  ADD INDEX IF NOT EXISTS `idx_ledger_source_ref_str` (`source`, `source_ref_str`(64));

SET foreign_key_checks = 1;
