-- =============================================================
-- Migration 010: Drop promote_campaigns abstraction layer
--
-- Replaces the 1:1 promote_campaigns table with a lightweight
-- promote_settings table (PK = event_id).  Posts and broadcasts
-- are now keyed directly to event_id rather than campaign_id.
-- =============================================================

-- 1. New promote_settings table (mirrors the useful columns from
--    promote_campaigns; event_id is the primary key so it's 1:1 by design)
CREATE TABLE IF NOT EXISTS `promote_settings` (
  `event_id`            int(11) NOT NULL,
  `status`              enum('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  `goal_tickets`        int(11) DEFAULT NULL,
  `notes`               text DEFAULT NULL,
  `created_by_user_id`  int(11) DEFAULT NULL,
  `created_at`          timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at`          timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_id`),
  CONSTRAINT `promote_settings_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_settings_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Migrate existing campaign rows into promote_settings
INSERT INTO `promote_settings` (`event_id`, `status`, `goal_tickets`, `notes`, `created_by_user_id`, `created_at`, `updated_at`)
SELECT `event_id`, `status`, `goal_tickets`, `notes`, `created_by_user_id`, `created_at`, `updated_at`
FROM `promote_campaigns`
ON DUPLICATE KEY UPDATE
  `status`             = VALUES(`status`),
  `goal_tickets`       = VALUES(`goal_tickets`),
  `notes`              = VALUES(`notes`),
  `created_by_user_id` = VALUES(`created_by_user_id`);

-- 3. Add event_id to promote_posts and back-fill from campaign join
ALTER TABLE `promote_posts` ADD COLUMN `event_id` int(11) NULL AFTER `id`;

UPDATE `promote_posts` p
  JOIN `promote_campaigns` c ON c.id = p.campaign_id
SET p.event_id = c.event_id;

ALTER TABLE `promote_posts` MODIFY COLUMN `event_id` int(11) NOT NULL;

-- 4. Drop campaign FK + column from promote_posts; add event FK
ALTER TABLE `promote_posts` DROP FOREIGN KEY `promote_posts_ibfk_1`;
ALTER TABLE `promote_posts` DROP KEY `campaign_id`;
ALTER TABLE `promote_posts` DROP COLUMN `campaign_id`;
ALTER TABLE `promote_posts` ADD KEY `event_id` (`event_id`);
ALTER TABLE `promote_posts` ADD CONSTRAINT `promote_posts_ibfk_event`
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

-- 5. Add event_id to promote_broadcasts and back-fill
ALTER TABLE `promote_broadcasts` ADD COLUMN `event_id` int(11) NULL AFTER `id`;

UPDATE `promote_broadcasts` b
  JOIN `promote_campaigns` c ON c.id = b.campaign_id
SET b.event_id = c.event_id;

ALTER TABLE `promote_broadcasts` MODIFY COLUMN `event_id` int(11) NOT NULL;

-- 6. Drop campaign FK + column from promote_broadcasts; add event FK
ALTER TABLE `promote_broadcasts` DROP FOREIGN KEY `promote_broadcasts_ibfk_1`;
ALTER TABLE `promote_broadcasts` DROP KEY `campaign_id`;
ALTER TABLE `promote_broadcasts` DROP COLUMN `campaign_id`;
ALTER TABLE `promote_broadcasts` ADD KEY `event_id` (`event_id`);
ALTER TABLE `promote_broadcasts` ADD CONSTRAINT `promote_broadcasts_ibfk_event`
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

-- 7. Drop promote_campaigns (all FKs pointing to it are already gone)
DROP TABLE IF EXISTS `promote_campaigns`;
