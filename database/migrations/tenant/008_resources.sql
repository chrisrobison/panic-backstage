-- Migration 008 (tenant): resources — bookable spaces within a venue.
--
-- A "resource" is a named, independently-bookable area within a venue:
-- a stage, a room, a floor, a studio, a rooftop bar, etc.
--
-- This replaces the Mabuhay-specific mabuhay-upstairs / mabuhay-both slug
-- comparisons in Events::checkRoomConflict() with a generic data-driven model:
--
--   venues.venue_group  — venues that share the same group string are in the
--                         same building and subject to cross-room conflict checks
--   venues.zone         — 'up' | 'down' | 'both' | NULL — drives the calendar
--                         split-column display and the conflict logic
--
-- Events gain an optional resource_id FK for future direct booking; existing
-- rows are unaffected (resource_id is nullable and defaults to NULL).
--
-- Conflict rules (enforced in Events::checkRoomConflict()):
--   • An event in zone='both' (whole-building) conflicts with all rooms in the group.
--   • An event in a specific room also conflicts with any whole-building booking.
--   • Events in different rooms (different venue_ids, no 'both') do NOT conflict.

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

-- ── 2. Extend venues with zone + venue_group ──────────────────────────────────
--
-- zone        — calendar display zone for this venue row ('up','down','both', or NULL)
-- venue_group — arbitrary string grouping rooms in the same building for conflict checks

ALTER TABLE `venues`
  ADD COLUMN IF NOT EXISTS `zone`        VARCHAR(20)  DEFAULT NULL AFTER `timezone`,
  ADD COLUMN IF NOT EXISTS `venue_group` VARCHAR(100) DEFAULT NULL AFTER `zone`;

-- ── 3. Add optional resource_id to events (nullable, backward-compatible) ──────

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `resource_id` INT(11) DEFAULT NULL AFTER `venue_id`;

ALTER TABLE `events`
  ADD KEY IF NOT EXISTS `resource_id` (`resource_id`);

-- FK added separately so it can be skipped safely on installs where the
-- column already exists but the constraint was never created.
ALTER TABLE `events`
  ADD CONSTRAINT `events_resource_fk`
    FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE SET NULL;

-- ── 4. Seed Mabuhay venue rows with zone + venue_group ───────────────────────
-- No-op on installs that don't have these slugs.

UPDATE `venues` SET `zone` = 'up',   `venue_group` = 'main' WHERE `slug` = 'mabuhay-upstairs';
UPDATE `venues` SET `zone` = 'down', `venue_group` = 'main' WHERE `slug` = 'mabuhay-gardens';
UPDATE `venues` SET `zone` = 'both', `venue_group` = 'main' WHERE `slug` = 'mabuhay-both';

-- ── 5. Seed resources for Mabuhay ────────────────────────────────────────────
-- Creates one resource per room venue.  These are idempotent via INSERT IGNORE.

INSERT IGNORE INTO `resources` (`venue_id`, `name`, `slug`, `zone`, `sort_order`)
SELECT `id`, 'Upstairs', 'upstairs', 'up', 1
FROM   `venues` WHERE `slug` = 'mabuhay-upstairs';

INSERT IGNORE INTO `resources` (`venue_id`, `name`, `slug`, `zone`, `sort_order`)
SELECT `id`, 'Downstairs (21+)', 'downstairs', 'down', 2
FROM   `venues` WHERE `slug` = 'mabuhay-gardens';
