-- Migration 020 (single-tenant): resources — bookable spaces within a venue.
--
-- See database/migrations/tenant/008_resources.sql for full commentary.
-- This file is the single-tenant equivalent applied via: php scripts/migrate.php

-- ── 1. resources table ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `resources` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `venue_id`    INT(11)      NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `slug`        VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `capacity`    INT(11)      DEFAULT NULL,
  `zone`        VARCHAR(20)  NOT NULL DEFAULT 'primary',
  `sort_order`  INT(11)      NOT NULL DEFAULT 0,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `venue_slug` (`venue_id`, `slug`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Extend venues ──────────────────────────────────────────────────────────

ALTER TABLE `venues`
  ADD COLUMN IF NOT EXISTS `zone`        VARCHAR(20)  DEFAULT NULL AFTER `timezone`,
  ADD COLUMN IF NOT EXISTS `venue_group` VARCHAR(100) DEFAULT NULL AFTER `zone`;

-- ── 3. Optional resource_id on events ─────────────────────────────────────────

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `resource_id` INT(11) DEFAULT NULL AFTER `venue_id`;

ALTER TABLE `events`
  ADD KEY IF NOT EXISTS `resource_id` (`resource_id`);

ALTER TABLE `events`
  ADD CONSTRAINT `events_resource_fk`
    FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE SET NULL;

-- ── 4. Seed Mabuhay venue zone/group ─────────────────────────────────────────

UPDATE `venues` SET `zone` = 'up',   `venue_group` = 'main' WHERE `slug` = 'mabuhay-upstairs';
UPDATE `venues` SET `zone` = 'down', `venue_group` = 'main' WHERE `slug` = 'mabuhay-gardens';
UPDATE `venues` SET `zone` = 'both', `venue_group` = 'main' WHERE `slug` = 'mabuhay-both';

-- ── 5. Seed Mabuhay resources ────────────────────────────────────────────────

INSERT IGNORE INTO `resources` (`venue_id`, `name`, `slug`, `zone`, `sort_order`)
SELECT `id`, 'Upstairs', 'upstairs', 'up', 1
FROM   `venues` WHERE `slug` = 'mabuhay-upstairs';

INSERT IGNORE INTO `resources` (`venue_id`, `name`, `slug`, `zone`, `sort_order`)
SELECT `id`, 'Downstairs (21+)', 'downstairs', 'down', 2
FROM   `venues` WHERE `slug` = 'mabuhay-gardens';
