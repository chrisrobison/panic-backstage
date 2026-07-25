-- Booking Inbox — AI classification runs.
--
-- One row per classification attempt (source='ai') or human correction
-- (source='human_correction'); `is_current` marks the row the rest of the
-- app should read. Deterministic code (src/Leads/RoutingEngine.php,
-- src/Leads/StatusMachine.php) only ever reads the stored columns here —
-- the model itself never has a path to mutate permissions, routing rules,
-- or delete anything (see src/Leads/Classifier.php).

CREATE TABLE IF NOT EXISTS `lead_classifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL COMMENT 'Inbound message that triggered this run, if any',
  `source` enum('ai','human_correction') NOT NULL DEFAULT 'ai',
  `model` varchar(120) DEFAULT NULL,
  `prompt_version` varchar(40) DEFAULT NULL,
  `extracted_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extracted_json`)),
  `field_confidence_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`field_confidence_json`)),
  `overall_confidence` decimal(5,2) DEFAULT NULL,
  `spam_probability` decimal(5,2) DEFAULT NULL,
  `recommended_action` varchar(255) DEFAULT NULL,
  `missing_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`missing_fields_json`)),
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `corrected_by_user_id` int(11) DEFAULT NULL,
  `processing_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_classifications_lead_current` (`lead_id`, `is_current`),
  CONSTRAINT `lead_classifications_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_classifications_message_fk` FOREIGN KEY (`message_id`) REFERENCES `lead_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_classifications_corrector_fk` FOREIGN KEY (`corrected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
