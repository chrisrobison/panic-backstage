-- Booking Inbox hardening: delivery truth, concurrency constraints, intake
-- quarantine metadata, and configurable reply templates.

ALTER TABLE `lead_messages`
  ADD COLUMN IF NOT EXISTS `idempotency_key` varchar(64) DEFAULT NULL AFTER `checksum`,
  ADD COLUMN IF NOT EXISTS `delivery_attempted_at` datetime DEFAULT NULL AFTER `idempotency_key`,
  ADD COLUMN IF NOT EXISTS `delivery_error` varchar(1000) DEFAULT NULL AFTER `delivery_attempted_at`;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_messages' AND INDEX_NAME = 'uniq_lead_message_idempotency'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `lead_messages` ADD UNIQUE KEY `uniq_lead_message_idempotency` (`lead_id`, `idempotency_key`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `lead_intake_emails`
  ADD COLUMN IF NOT EXISTS `disposition_reason` varchar(500) DEFAULT NULL AFTER `error_message`;

-- MariaDB unique indexes allow multiple NULL values. Keeping active_slot=1
-- only for active claims gives us a database-enforced partial uniqueness rule:
-- at most one (lead_id, 1), with any number of historical (lead_id, NULL) rows.
ALTER TABLE `lead_claims`
  ADD COLUMN IF NOT EXISTS `active_slot` tinyint(1) DEFAULT NULL AFTER `status`;

UPDATE `lead_claims`
SET `active_slot` = CASE WHEN `status` = 'active' THEN 1 ELSE NULL END;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_claims' AND INDEX_NAME = 'uniq_lead_active_claim'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `lead_claims` ADD UNIQUE KEY `uniq_lead_active_claim` (`lead_id`, `active_slot`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One inquiry may create at most one event. NULL lead_id values remain
-- unrestricted, so ordinary manually-created events are unaffected.
SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'uniq_events_lead'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `events` ADD UNIQUE KEY `uniq_events_lead` (`lead_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `lead_reply_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `kind` enum('general','availability','proposal','tour','follow_up') NOT NULL DEFAULT 'general',
  `subject` varchar(1000) DEFAULT NULL,
  `body_text` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_reply_template_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `lead_reply_templates` (`name`, `kind`, `subject`, `body_text`, `sort_order`)
SELECT 'Availability follow-up', 'availability', 'Re: your booking inquiry',
       'Thanks for reaching out. We are reviewing availability for {{desired_date}} and will confirm the available room and time options shortly.',
       10
WHERE NOT EXISTS (SELECT 1 FROM `lead_reply_templates` WHERE `name` = 'Availability follow-up');

INSERT INTO `lead_reply_templates` (`name`, `kind`, `subject`, `body_text`, `sort_order`)
SELECT 'Proposal follow-up', 'proposal', 'Re: your booking inquiry',
       'Thanks for the details. We are preparing a proposal for {{event_name}} and will follow up with the scope, pricing, and next steps.',
       20
WHERE NOT EXISTS (SELECT 1 FROM `lead_reply_templates` WHERE `name` = 'Proposal follow-up');

INSERT INTO `lead_reply_templates` (`name`, `kind`, `subject`, `body_text`, `sort_order`)
SELECT 'Schedule a venue tour', 'tour', 'Re: your booking inquiry',
       'We would be glad to schedule a venue tour. Please send a few dates and times that work for you, and we will confirm one.',
       30
WHERE NOT EXISTS (SELECT 1 FROM `lead_reply_templates` WHERE `name` = 'Schedule a venue tour');

INSERT INTO `lead_reply_templates` (`name`, `kind`, `subject`, `body_text`, `sort_order`)
SELECT 'Request missing information', 'follow_up', 'Re: your booking inquiry',
       'Thanks for your inquiry. Before we confirm next steps, could you send the preferred date, expected attendance, event format, and any production requirements?',
       40
WHERE NOT EXISTS (SELECT 1 FROM `lead_reply_templates` WHERE `name` = 'Request missing information');
