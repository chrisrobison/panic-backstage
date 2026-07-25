-- Booking Inbox — assignment/claim history, watchers, presence, drafts.
--
-- `leads.assigned_to_user_id` / `claimed_by_user_id` / `owner_user_id`
-- (migration 071) hold the *current* state for fast list queries.
-- `lead_assignments` / `lead_claims` are the append-only histories behind
-- them — every routing decision and every claim/release/expiry is a new
-- row here, never an update-in-place, so the full history is always
-- auditable (see docs/booking-inbox.md and src/Leads/RoutingEngine.php /
-- src/Leads/ClaimService.php).

CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `assigned_to_user_id` int(11) DEFAULT NULL COMMENT 'NULL = returned to unassigned queue',
  `assigned_by_user_id` int(11) DEFAULT NULL COMMENT 'NULL = automatic routing decision',
  `reason` varchar(500) DEFAULT NULL,
  -- No FK to routing_rule_versions (created in a later migration) — same
  -- deliberate choice as process_definitions.current_published_version_id
  -- (see 066_add_process_automation.sql): avoids forward/circular DDL
  -- ordering, enforced in application code instead.
  `routing_rule_version_id` int(11) DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_assignments_lead` (`lead_id`, `created_at`),
  KEY `idx_lead_assignments_user` (`assigned_to_user_id`),
  KEY `idx_lead_assignments_rule_version` (`routing_rule_version_id`),
  CONSTRAINT `lead_assignments_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_assignments_to_fk` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_assignments_by_fk` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `claimed_by_user_id` int(11) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `status` enum('active','expired','released','superseded') NOT NULL DEFAULT 'active',
  `released_at` datetime DEFAULT NULL,
  `released_by_user_id` int(11) DEFAULT NULL COMMENT 'NULL when released by the SLA sweep',
  `released_reason` varchar(500) DEFAULT NULL,
  `last_preserving_action_at` datetime DEFAULT NULL COMMENT 'Most recent claim-preserving action (reply, tour scheduled, etc.)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_claims_lead_status` (`lead_id`, `status`),
  KEY `idx_lead_claims_user` (`claimed_by_user_id`),
  KEY `idx_lead_claims_expires` (`status`, `expires_at`),
  CONSTRAINT `lead_claims_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_claims_claimant_fk` FOREIGN KEY (`claimed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_claims_releaser_fk` FOREIGN KEY (`released_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_watchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `added_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_watcher` (`lead_id`, `user_id`),
  KEY `idx_lead_watchers_user` (`user_id`),
  CONSTRAINT `lead_watchers_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_watchers_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_watchers_adder_fk` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ephemeral "who's looking at / drafting a reply to this inquiry right now"
-- heartbeat, upserted by the client every ~10s while an inquiry is open
-- (POST /api/leads/{id}/presence) and polled by everyone else viewing the
-- same inquiry. Rows older than ~20s are treated as stale by the reader,
-- not deleted by a job — there's no harm in a little garbage here.
CREATE TABLE IF NOT EXISTS `lead_presence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `state` enum('viewing','drafting') NOT NULL DEFAULT 'viewing',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_presence` (`lead_id`, `user_id`),
  CONSTRAINT `lead_presence_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_presence_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One in-progress draft per (lead, user). `based_on_message_id` records the
-- last message in the thread the composer had loaded when the draft was
-- started — the optimistic-concurrency token checked by the send endpoint
-- (see docs/booking-inbox.md § duplicate-reply prevention).
CREATE TABLE IF NOT EXISTS `lead_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `kind` enum('reply','reply_all','note') NOT NULL DEFAULT 'reply',
  `subject` varchar(1000) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `based_on_message_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_draft` (`lead_id`, `user_id`),
  CONSTRAINT `lead_drafts_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_drafts_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_drafts_based_on_fk` FOREIGN KEY (`based_on_message_id`) REFERENCES `lead_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
