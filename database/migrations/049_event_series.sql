-- Recurring events: a series is a lightweight grouping record. Occurrences
-- are ordinary, fully independent `events` rows (their own contract,
-- staffing, ticketing, etc.) that happen to share a `series_id` — editing one
-- never touches the others. See src/Events/Series.php.
CREATE TABLE IF NOT EXISTS `event_series` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `pattern_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pattern_json`)),
  `description` varchar(255) DEFAULT NULL COMMENT 'Human label, e.g. "Every other Tuesday"',
  `end_type` enum('on_date','after_count') NOT NULL DEFAULT 'after_count',
  `end_date` date DEFAULT NULL,
  `occurrence_count` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_series_venue` (`venue_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `event_series_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`),
  CONSTRAINT `event_series_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `events`
  ADD COLUMN `series_id` int(11) DEFAULT NULL AFTER `resource_id`;

ALTER TABLE `events`
  ADD KEY `idx_events_series` (`series_id`),
  ADD CONSTRAINT `events_series_fk` FOREIGN KEY (`series_id`) REFERENCES `event_series` (`id`) ON DELETE SET NULL;
