-- Auto-publish settings: global (system-level) configuration that controls
-- whether events are automatically broadcast to Promote destinations when
-- their status reaches 'published'.
--
-- This is a single-row table (enforced by the constant primary key value).
-- Query with: SELECT * FROM promote_auto_publish_settings LIMIT 1

CREATE TABLE IF NOT EXISTS `promote_auto_publish_settings` (
  `id`                      TINYINT(1)   NOT NULL DEFAULT 1,
  `auto_publish_enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
  `auto_publish_destinations` TEXT       DEFAULT NULL COMMENT 'JSON array of destination_key strings',
  `updated_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `promote_auto_publish_settings_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the singleton row so it always exists.
INSERT IGNORE INTO `promote_auto_publish_settings` (`id`, `auto_publish_enabled`, `auto_publish_destinations`)
VALUES (1, 0, NULL);
