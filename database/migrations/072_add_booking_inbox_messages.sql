-- Booking Inbox — unified conversation feed.
--
-- `lead_messages` is what the Inbox's Conversation tab renders: inbound
-- emails, outbound replies, imported social messages, internal notes, and
-- system events, all in one ordered feed per lead. It sits alongside (not
-- instead of) the existing `lead_intake_emails` table, which stays exactly
-- as-is as the permanent raw-message/dedup audit record — nothing here ever
-- deletes or replaces that. Ingestion (src/LeadEmailParser.php,
-- src/PublicInquiry.php, scripts/ingest-booking-email.php) writes both: the
-- raw audit row to lead_intake_emails, and a normalized inbound row here.

CREATE TABLE IF NOT EXISTS `lead_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `direction` enum('inbound','outbound','internal_note','system') NOT NULL,
  `channel` enum('email','sms','social_dm','phone','manual','system') NOT NULL DEFAULT 'email',
  `status` enum('draft','queued','sent','failed','received') NOT NULL DEFAULT 'received',
  `from_name` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `to_recipients` varchar(1000) DEFAULT NULL,
  `cc_recipients` varchar(1000) DEFAULT NULL,
  `subject` varchar(1000) DEFAULT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `raw_headers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_headers_json`)),
  `external_message_id` varchar(255) DEFAULT NULL COMMENT 'Provider Message-ID / social post id, for threading',
  `in_reply_to` varchar(255) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL COMMENT 'sha256 of normalized body, for duplicate detection',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_by_user_id` int(11) DEFAULT NULL COMMENT 'Staff author for outbound/internal_note; NULL for inbound/system',
  `intake_email_id` int(11) DEFAULT NULL COMMENT 'Back-reference to lead_intake_emails.id for inbound email rows',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_messages_lead` (`lead_id`, `created_at`),
  KEY `idx_lead_messages_checksum` (`lead_id`, `checksum`),
  KEY `idx_lead_messages_sender` (`sent_by_user_id`),
  CONSTRAINT `lead_messages_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_messages_sender_fk` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_messages_intake_fk` FOREIGN KEY (`intake_email_id`) REFERENCES `lead_intake_emails` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `filename` varchar(500) NOT NULL,
  `mime_type` varchar(190) DEFAULT NULL,
  `size_bytes` bigint(20) DEFAULT NULL,
  `storage_path` varchar(500) NOT NULL,
  `uploaded_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_attachments_lead` (`lead_id`),
  KEY `idx_lead_attachments_message` (`message_id`),
  CONSTRAINT `lead_attachments_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_attachments_message_fk` FOREIGN KEY (`message_id`) REFERENCES `lead_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_attachments_uploader_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
