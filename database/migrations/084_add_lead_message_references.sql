-- Persist every RFC 5322 References/In-Reply-To Message-ID attached to an
-- inbound lead message. A shared ancestor still identifies one conversation
-- when the original email was never imported into the Booking Inbox.

CREATE TABLE IF NOT EXISTS `lead_message_references` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_message_id` int(11) NOT NULL,
  `reference_id` varchar(255) NOT NULL,
  `ordinal` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_message_reference` (`lead_message_id`, `reference_id`),
  KEY `idx_lead_message_references_id` (`reference_id`, `lead_message_id`),
  CONSTRAINT `lead_message_references_message_fk`
    FOREIGN KEY (`lead_message_id`) REFERENCES `lead_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
