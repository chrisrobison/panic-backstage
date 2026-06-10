-- Marketing / CRM contacts — the audience that buys tickets and gets event
-- emails. Seeded from the venue's ticketing provider (Tixr "Fan View" export)
-- and editable in-app. `external_id` is the provider's user id; (source,
-- external_id) is unique so re-importing UPSERTs instead of duplicating, while
-- manually-added contacts (external_id NULL) never collide.
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `external_id` bigint(20) DEFAULT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'manual',
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(160) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `events_count` int(11) NOT NULL DEFAULT 0,
  `q_events_count` int(11) NOT NULL DEFAULT 0,
  `tickets_count` int(11) NOT NULL DEFAULT 0,
  `usd_spend` decimal(12,2) NOT NULL DEFAULT 0.00,
  `follows` int(11) NOT NULL DEFAULT 0,
  `last_interaction` datetime DEFAULT NULL,
  `influencer_id` varchar(80) DEFAULT NULL,
  `marketing_opted_in` tinyint(1) NOT NULL DEFAULT 0,
  `opt_in_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_external` (`source`,`external_id`),
  KEY `idx_email` (`email`),
  KEY `idx_last_name` (`last_name`),
  KEY `idx_marketing` (`marketing_opted_in`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
