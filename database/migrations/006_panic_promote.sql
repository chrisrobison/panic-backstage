-- =============================================================
-- Migration 006: Panic Promote — marketing campaign module
--
-- Adds tables for promotion campaigns, posts, post variants,
-- broadcast destinations, broadcasts, and broadcast results.
-- Seeds 11 default destinations.
-- =============================================================

CREATE TABLE IF NOT EXISTS `promote_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  `goal_tickets` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_id` (`event_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `promote_campaigns_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_campaigns_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promote_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `master_text` text DEFAULT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `status` enum('draft','approved','scheduled','sent','archived') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `asset_id` (`asset_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `promote_posts_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `promote_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_posts_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `event_assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_posts_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promote_post_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `channel` varchar(80) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `status` enum('draft','ready','needs_review','approved') NOT NULL DEFAULT 'draft',
  `warnings_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`warnings_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_channel` (`post_id`,`channel`),
  CONSTRAINT `promote_post_variants_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `promote_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promote_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destination_key` varchar(80) NOT NULL,
  `destination_group` enum('direct_post','event_platform','editorial_submission','email') NOT NULL,
  `label` varchar(120) NOT NULL,
  `status` enum('connected','needs_auth','manual_submission','disabled') NOT NULL DEFAULT 'manual_submission',
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_destination_key` (`destination_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promote_broadcasts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `send_mode` enum('now','scheduled') NOT NULL DEFAULT 'now',
  `scheduled_at` datetime DEFAULT NULL,
  `status` enum('draft','queued','processing','completed','partial_failure','failed') NOT NULL DEFAULT 'queued',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `post_id` (`post_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `promote_broadcasts_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `promote_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_broadcasts_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `promote_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_broadcasts_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promote_broadcast_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `destination_key` varchar(80) NOT NULL,
  `destination_group` varchar(80) NOT NULL,
  `status` enum('queued','sent','manual_required','needs_auth','failed','skipped') NOT NULL DEFAULT 'queued',
  `external_url` varchar(500) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `broadcast_id` (`broadcast_id`),
  CONSTRAINT `promote_broadcast_results_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `promote_broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default destinations (safe to re-run)
INSERT INTO `promote_destinations` (`destination_key`, `destination_group`, `label`, `status`) VALUES
('facebook_page',  'direct_post',          'Facebook Page',      'needs_auth'),
('instagram',      'direct_post',          'Instagram',          'needs_auth'),
('tiktok',         'direct_post',          'TikTok',             'needs_auth'),
('eventbrite',     'event_platform',       'Eventbrite',         'needs_auth'),
('luma',           'event_platform',       'Luma',               'needs_auth'),
('bandsintown',    'event_platform',       'Bandsintown',        'manual_submission'),
('funcheap',       'editorial_submission', 'Funcheap',           'manual_submission'),
('foopee',         'editorial_submission', 'Foopee',             'manual_submission'),
('press_list',     'editorial_submission', 'Press List',         'manual_submission'),
('email_general',  'email',                'General Email List', 'connected'),
('email_press',    'email',                'Press Email List',   'connected')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);
