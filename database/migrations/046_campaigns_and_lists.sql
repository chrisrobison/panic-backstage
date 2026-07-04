-- Email campaigns + mailing lists — foundation for the in-app marketing email
-- tool. Staff can build a "campaign" (draft → edited → sent, with per-
-- recipient delivery tracking) either from picked events or from scratch,
-- and send it to a named, reusable "mailing list" layered on top of the
-- existing `contacts` table (which today only has a flat
-- `marketing_opted_in` flag).
--
-- `list_membership.status` lets a contact stay associated with a list while
-- being excluded from sends (recipient resolution only counts
-- status='subscribed'). `contact_id`/`list_id`/`outbox_id` on
-- `email_campaign_recipients` are ON DELETE SET NULL — a contact, list, or
-- outbox row can be deleted later without destroying the historical send
-- record (`email_snapshot` is the audit trail).
CREATE TABLE IF NOT EXISTS `mailing_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mailing_list_name` (`name`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `mailing_lists_ibfk_1` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `list_membership` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `status` enum('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_list_contact` (`list_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `list_membership_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `list_membership_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL COMMENT 'Internal label, not shown to recipients',
  `subject` varchar(998) NOT NULL DEFAULT '',
  `source` enum('blank','events') NOT NULL DEFAULT 'blank',
  `status` enum('draft','sending','sent','partial_failure','failed') NOT NULL DEFAULT 'draft',
  `html_body` mediumtext DEFAULT NULL,
  `text_body` mediumtext DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_status` (`status`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `email_campaigns_ibfk_1` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_campaign_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_event` (`campaign_id`,`event_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `email_campaign_events_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_campaign_events_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_campaign_recipients` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `list_id` int(11) DEFAULT NULL COMMENT 'Which selected list this recipient was pulled from, if any',
  `email_snapshot` varchar(320) NOT NULL,
  `status` enum('pending','sent','failed','skipped_opted_out') NOT NULL DEFAULT 'pending',
  `outbox_id` bigint(20) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `contact_id` (`contact_id`),
  KEY `list_id` (`list_id`),
  KEY `outbox_id` (`outbox_id`),
  CONSTRAINT `email_campaign_recipients_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_campaign_recipients_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_campaign_recipients_ibfk_3` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_campaign_recipients_ibfk_4` FOREIGN KEY (`outbox_id`) REFERENCES `outbox` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
