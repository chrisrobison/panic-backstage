-- Booking Inbox — append-only audit log, formal status-transition history,
-- and manager-approval requests for restricted-role high-value decisions.
--
-- `lead_audit_log` is the semantic action log the spec's exhaustive audit
-- list requires (ingestion, viewing, assignment, claim, expiration,
-- reassignment, response, draft create/edit, classification + correction,
-- routing decision, manager override, onboarding, decline/archive,
-- duplicate/spam marking, attachment access, export, automation
-- execution/failure) — see log_lead_activity() in src/Support.php. This is
-- distinct from the existing generic `db_history` table (Database.php),
-- which is a low-level trigger-driven row-diff/undo log for every table;
-- lead_audit_log instead records *why* something happened, one row per
-- meaningful action, in the same per-domain-log convention as
-- task_activity / process_audit_log / contract_audit_log. No endpoint
-- exposes update or delete on this table — it is insert-only by
-- construction.

CREATE TABLE IF NOT EXISTS `lead_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL COMMENT 'NULL for lead-independent actions (routing rule edits, exports)',
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = automation/system actor',
  `action` varchar(80) NOT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  KEY `idx_lead_audit_lead` (`lead_id`, `created_at`),
  KEY `idx_lead_audit_action` (`action`, `created_at`),
  KEY `idx_lead_audit_user` (`user_id`, `created_at`),
  CONSTRAINT `lead_audit_log_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_audit_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = automation',
  `reason` varchar(500) DEFAULT NULL,
  `related_message_id` int(11) DEFAULT NULL,
  `source` enum('human','automation') NOT NULL DEFAULT 'human',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_status_history_lead` (`lead_id`, `created_at`),
  CONSTRAINT `lead_status_history_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_status_history_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_status_history_message_fk` FOREIGN KEY (`related_message_id`) REFERENCES `lead_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A restricted-role user asking a manager to decline/lose/archive a
-- high-value lead on their behalf (they lack decline_high_value_leads).
-- src/Leads/StatusMachine.php creates one of these instead of applying the
-- transition when that gate fails; a venue_admin resolves it, which is what
-- actually performs the status change (and is itself audited).
CREATE TABLE IF NOT EXISTS `lead_approval_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `requested_by_user_id` int(11) DEFAULT NULL,
  `requested_status` varchar(40) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `decided_by_user_id` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_approval_requests_lead` (`lead_id`),
  KEY `idx_lead_approval_requests_status` (`status`),
  CONSTRAINT `lead_approval_requests_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_approval_requests_requester_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_approval_requests_decider_fk` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
