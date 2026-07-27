-- Durable application jobs. Public HTTP requests enqueue small JSON payloads
-- in the same transaction as the business row they create; a CLI worker
-- reserves and processes jobs with bounded exponential retries.

CREATE TABLE IF NOT EXISTS `background_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(64) NOT NULL DEFAULT 'default',
  `job_type` varchar(100) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `unique_key` varchar(191) DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `max_attempts` int(10) unsigned NOT NULL DEFAULT 5,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_background_jobs_unique_key` (`unique_key`),
  KEY `idx_background_jobs_ready` (`queue`, `status`, `available_at`, `id`),
  KEY `idx_background_jobs_stale` (`status`, `locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
