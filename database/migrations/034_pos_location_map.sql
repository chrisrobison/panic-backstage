-- Migration 034: Square POS location mapping + ledger source_ref_str column.
--
-- pos_location_map: maps Square POS location IDs to venue IDs so that
-- incoming POS webhooks can be matched to the right venue and ledger category.
--
-- Also adds source_ref_str (VARCHAR) to event_ledger_entries so that POS
-- payment IDs (Square alphanumeric strings) can be stored for idempotency
-- checks without requiring the existing source_ref_id (INT) to change.

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
-- Stores string-typed external references (e.g. Square payment IDs) alongside
-- the existing source_ref_id (INT). Used by POS import for idempotency.

ALTER TABLE `event_ledger_entries`
  ADD COLUMN IF NOT EXISTS `source_ref_str` VARCHAR(255) DEFAULT NULL
    COMMENT 'String external reference (e.g. Square payment ID) for sources that do not use an integer FK'
    AFTER `source_ref_id`;

-- Index to make the idempotency lookup fast.
-- Use a regular index (not UNIQUE) because NULLs and manual entries share the column.
ALTER TABLE `event_ledger_entries`
  ADD INDEX IF NOT EXISTS `idx_ledger_source_ref_str` (`source`, `source_ref_str`(64));

SET foreign_key_checks = 1;
