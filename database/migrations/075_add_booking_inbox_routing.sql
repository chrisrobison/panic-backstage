-- Booking Inbox — configurable, versioned routing rules + per-venue SLA
-- settings.
--
-- Mirrors the process_definitions/process_versions pattern
-- (066_add_process_automation.sql) exactly: a `routing_rules` header row
-- plus immutable-once-published `routing_rule_versions`. Editing a
-- published rule means authoring a new draft version, never mutating a
-- published one — so every routing decision recorded against a
-- routing_rule_version_id (lead_assignments.routing_rule_version_id,
-- migration 073) stays meaningfully explainable forever, even after the
-- rule is later changed.
--
-- Seed example rules (Comedy → Colleen, Punk/ska → Kathy, etc.) are loaded
-- as data by database/seed_demo_data.php, not hard-coded here or in PHP —
-- see src/Leads/RoutingEngine.php.

CREATE TABLE IF NOT EXISTS `routing_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 100 COMMENT 'Lower runs first; first match wins',
  -- No FK to routing_rule_versions.id — same deliberate avoidance of a
  -- circular constraint as process_definitions.current_published_version_id.
  `current_published_version_id` int(11) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_routing_rules_active_priority` (`is_active`, `priority`),
  CONSTRAINT `routing_rules_creator_fk` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `routing_rule_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `routing_rule_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `conditions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'category, genre, attendance, budget, space, age_restriction, date, source, promoter, prior_customer, confidence_threshold, staff specialty/workload/availability' CHECK (json_valid(`conditions_json`)),
  `action_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'assign_to_user_id | assign_to_role | fallback_unassigned' CHECK (json_valid(`action_json`)),
  `note` varchar(500) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `published_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_routing_rule_version` (`routing_rule_id`, `version_number`),
  KEY `idx_routing_rule_versions_status` (`status`),
  CONSTRAINT `routing_rule_versions_rule_fk` FOREIGN KEY (`routing_rule_id`) REFERENCES `routing_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `routing_rule_versions_creator_fk` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `routing_rule_versions_publisher_fk` FOREIGN KEY (`published_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per venue: configurable SLA hours, business hours, high-value
-- threshold, and the acknowledgment template — read by
-- src/Leads/ClaimService.php, scripts/lead-sla-tick.php, and the
-- auto-acknowledgment step in the ingestion pipeline. Deliberately separate
-- from `venue_policies` (which is a versioned, effective-dated *deal*
-- policy document, not operational Inbox configuration).
CREATE TABLE IF NOT EXISTS `lead_inbox_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `claim_deadline_hours` decimal(5,2) NOT NULL DEFAULT 2.00,
  `response_deadline_hours` decimal(5,2) NOT NULL DEFAULT 4.00,
  `high_value_threshold` decimal(10,2) DEFAULT NULL,
  `high_value_claim_deadline_hours` decimal(5,2) DEFAULT NULL,
  `high_value_response_deadline_hours` decimal(5,2) DEFAULT NULL,
  `business_hours_start` time NOT NULL DEFAULT '09:00:00',
  `business_hours_end` time NOT NULL DEFAULT '18:00:00',
  `business_days` varchar(20) NOT NULL DEFAULT '1,2,3,4,5' COMMENT 'ISO-8601 weekday numbers, 1=Monday',
  `ack_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ack_subject` varchar(255) NOT NULL DEFAULT 'Thanks for reaching out',
  `ack_body` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_inbox_settings_venue` (`venue_id`),
  CONSTRAINT `lead_inbox_settings_venue_fk` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default settings row for every existing venue so the tick script
-- and ack flow have sane defaults from day one without requiring an admin
-- to configure anything first.
INSERT INTO `lead_inbox_settings` (`venue_id`, `ack_body`)
SELECT `id`,
       'Thanks for contacting us. We received your inquiry and a member of our booking team will follow up shortly.'
FROM `venues`
WHERE `id` NOT IN (SELECT `venue_id` FROM `lead_inbox_settings`);
