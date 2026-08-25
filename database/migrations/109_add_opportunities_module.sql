-- Opportunities module — Phase 1: durable backend model (tables +
-- capabilities added separately in src/Capabilities.php), no UI yet. See
-- docs/OPPORTUNITIES-IMPLEMENTATION.md for the full architecture rationale.
--
-- This migration implements the doc's §3.1 table design, but scoped to what
-- Phase 1 (docs/opportunity-ui/opportunity-ui.txt) actually lists/tests:
-- `opportunity_contacts`, `opportunity_decision_makers`, and
-- `opportunity_qualification` are deliberately deferred to the phases that
-- consume them (4, 5, 5) — Phase 1's own "suggested tables" list omits them,
-- and creating them now with no reader/writer would violate "do not create
-- unnecessary tables". `opportunities.primary_contact_id` likewise lands in
-- Phase 4 alongside `opportunity_contacts` rather than as an unconstrained
-- column today. `opportunity_note_versions` (revision history) is explicitly
-- Phase 6 work.
--
-- FK policy: SET NULL for optional/soft links (a deleted user/task-doc/event
-- shouldn't take a prospect record with it), CASCADE for rows that only ever
-- make sense attached to their parent (conference<->company link, signals,
-- notes' links/tags, activities, research jobs), RESTRICT (InnoDB default,
-- no ON DELETE clause) for `opportunities.company_id` — an opportunity must
-- always resolve to a real company, so the company has to be reassigned/the
-- opportunity deleted first, never silently orphaned.

CREATE TABLE IF NOT EXISTS `opportunity_conferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `website_url` varchar(500) DEFAULT NULL,
  `venue_name` varchar(255) DEFAULT NULL,
  `venue_address` varchar(500) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `estimated_attendance` int(11) DEFAULT NULL,
  `estimated_exhibitors` int(11) DEFAULT NULL,
  `estimated_sponsors` int(11) DEFAULT NULL,
  -- Nullable, no default: distance/availability logic must show "unknown
  -- until researched" rather than assume a venue location (spec requirement
  -- — never call a geocoding API automatically).
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `distance_from_venue_miles` decimal(6,2) DEFAULT NULL,
  `opportunity_score` tinyint(3) unsigned DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `last_researched_at` datetime DEFAULT NULL,
  `task_document_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_conferences_slug` (`slug`),
  KEY `idx_opportunity_conferences_starts` (`starts_at`),
  KEY `idx_opportunity_conferences_location` (`city`, `state`),
  KEY `idx_opportunity_conferences_task_doc` (`task_document_id`),
  KEY `idx_opportunity_conferences_created_by` (`created_by`),
  CONSTRAINT `opportunity_conferences_task_doc_fk` FOREIGN KEY (`task_document_id`) REFERENCES `task_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunity_conferences_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunity_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  -- Normalized (lowercase, scheme/www stripped) for dedup; unique but
  -- nullable — MySQL unique indexes allow multiple NULLs, so a company
  -- researched before its domain is known doesn't collide with another.
  `domain` varchar(255) DEFAULT NULL,
  `website_url` varchar(500) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `industry` varchar(120) DEFAULT NULL,
  `employee_range` varchar(40) DEFAULT NULL,
  `hq_city` varchar(120) DEFAULT NULL,
  `hq_state` varchar(120) DEFAULT NULL,
  `local_office` tinyint(1) NOT NULL DEFAULT 0,
  `linkedin_url` varchar(500) DEFAULT NULL,
  `relationship_status` enum('prospect','active','past_client','do_not_contact','unknown') NOT NULL DEFAULT 'prospect',
  `description` text DEFAULT NULL,
  `last_researched_at` datetime DEFAULT NULL,
  `task_document_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_companies_domain` (`domain`),
  KEY `idx_opportunity_companies_name` (`name`),
  KEY `idx_opportunity_companies_task_doc` (`task_document_id`),
  KEY `idx_opportunity_companies_created_by` (`created_by`),
  CONSTRAINT `opportunity_companies_task_doc_fk` FOREIGN KEY (`task_document_id`) REFERENCES `task_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunity_companies_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunity_conference_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conference_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `role` enum('organizer','headline_sponsor','sponsor','exhibitor','speaker','partner','vendor','delegation','attendee','unknown') NOT NULL DEFAULT 'unknown',
  `sponsor_tier` varchar(80) DEFAULT NULL,
  `booth` varchar(80) DEFAULT NULL,
  `participation_notes` text DEFAULT NULL,
  `confidence` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `source_url` varchar(500) DEFAULT NULL,
  `observed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opp_conference_companies` (`conference_id`, `company_id`),
  KEY `idx_opp_conference_companies_company` (`company_id`),
  KEY `idx_opp_conference_companies_created_by` (`created_by`),
  CONSTRAINT `opp_conference_companies_conf_fk` FOREIGN KEY (`conference_id`) REFERENCES `opportunity_conferences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opp_conference_companies_company_fk` FOREIGN KEY (`company_id`) REFERENCES `opportunity_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opp_conference_companies_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL,
  `conference_id` int(11) DEFAULT NULL,
  `stage` enum('new_signal','researching','contacted','qualified','proposal_sent','verbal_yes','won','lost','nurture') NOT NULL DEFAULT 'new_signal',
  `probability` tinyint(3) unsigned DEFAULT NULL,
  `estimated_value` decimal(10,2) DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `target_date_end` date DEFAULT NULL,
  `guest_count_min` int(11) DEFAULT NULL,
  `guest_count_max` int(11) DEFAULT NULL,
  `event_type` varchar(80) DEFAULT NULL,
  `event_concept` text DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  -- Set only by the (Phase 5) conversion flow; preserved forever afterward —
  -- the opportunity keeps its historical value, it does not get deleted or
  -- turned into a second event record. Mirrors leads.converted_event_id.
  `won_event_id` int(11) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `task_document_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunities_stage` (`stage`),
  KEY `idx_opportunities_company` (`company_id`),
  KEY `idx_opportunities_conference` (`conference_id`),
  KEY `idx_opportunities_owner` (`owner_user_id`),
  KEY `idx_opportunities_target_date` (`target_date`),
  KEY `idx_opportunities_won_event` (`won_event_id`),
  KEY `idx_opportunities_task_doc` (`task_document_id`),
  KEY `idx_opportunities_created_by` (`created_by`),
  CONSTRAINT `opportunities_company_fk` FOREIGN KEY (`company_id`) REFERENCES `opportunity_companies` (`id`),
  CONSTRAINT `opportunities_conference_fk` FOREIGN KEY (`conference_id`) REFERENCES `opportunity_conferences` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunities_owner_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunities_won_event_fk` FOREIGN KEY (`won_event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunities_task_doc_fk` FOREIGN KEY (`task_document_id`) REFERENCES `task_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opportunities_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunity_signals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  -- At least one of these three must be set — enforced in PHP (matches repo
  -- convention of validating in the endpoint, not with a SQL CHECK).
  `company_id` int(11) DEFAULT NULL,
  `conference_id` int(11) DEFAULT NULL,
  `opportunity_id` int(11) DEFAULT NULL,
  `signal_type` enum('proximity','availability','sponsorship','exhibitor','hospitality_history','side_event_history','hiring','company_size','speaking','budget','other') NOT NULL DEFAULT 'other',
  `description` text NOT NULL,
  `weight` decimal(6,2) DEFAULT NULL,
  `confidence` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `source_url` varchar(500) DEFAULT NULL,
  `source_title` varchar(255) DEFAULT NULL,
  `observed_at` datetime DEFAULT NULL,
  `is_ai_generated` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_signals_company` (`company_id`),
  KEY `idx_opportunity_signals_conference` (`conference_id`),
  KEY `idx_opportunity_signals_opportunity` (`opportunity_id`),
  KEY `idx_opportunity_signals_type` (`signal_type`),
  KEY `idx_opportunity_signals_created_by` (`created_by`),
  CONSTRAINT `opportunity_signals_company_fk` FOREIGN KEY (`company_id`) REFERENCES `opportunity_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_signals_conference_fk` FOREIGN KEY (`conference_id`) REFERENCES `opportunity_conferences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_signals_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_signals_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First-class notes (spec: "do not bury notes inside a generic JSON blob").
-- A note's target(s) live in opportunity_note_links, not a single FK column,
-- because one note can legitimately describe a conference + company +
-- contact + opportunity all at once (see docs/OPPORTUNITIES-IMPLEMENTATION.md
-- §1.12). Revision history (opportunity_note_versions) is Phase 6.
CREATE TABLE IF NOT EXISTS `opportunity_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `body` mediumtext NOT NULL,
  `note_type` enum('general','meeting','call','research','internal') NOT NULL DEFAULT 'general',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  -- Human vs AI provenance distinction the spec requires. A simple boolean
  -- is enough here (unlike lead_classifications' versioned-row pattern)
  -- because notes aren't machine-revised in place — see architecture doc §1.13.
  `is_ai_generated` tinyint(1) NOT NULL DEFAULT 0,
  `ai_model` varchar(100) DEFAULT NULL,
  `ai_prompt_version` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_notes_type` (`note_type`),
  KEY `idx_opportunity_notes_pinned` (`is_pinned`),
  KEY `idx_opportunity_notes_created_by` (`created_by`),
  CONSTRAINT `opportunity_notes_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunity_note_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `note_id` int(11) NOT NULL,
  `linked_type` enum('conference','company','contact','opportunity') NOT NULL,
  -- Not a SQL FK: `linked_id` targets one of four different tables depending
  -- on `linked_type` (including `opportunity_contacts`, which doesn't exist
  -- yet as of Phase 1 — see header note). Existence is validated in PHP.
  `linked_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_note_links` (`note_id`, `linked_type`, `linked_id`),
  KEY `idx_opportunity_note_links_target` (`linked_type`, `linked_id`),
  CONSTRAINT `opportunity_note_links_note_fk` FOREIGN KEY (`note_id`) REFERENCES `opportunity_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opportunity_note_tags` (
  `note_id` int(11) NOT NULL,
  `tag` varchar(64) NOT NULL,
  PRIMARY KEY (`note_id`, `tag`),
  KEY `idx_opportunity_note_tags_tag` (`tag`),
  CONSTRAINT `opportunity_note_tags_note_fk` FOREIGN KEY (`note_id`) REFERENCES `opportunity_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only activity/audit feed, written by log_opportunity_activity()
-- (src/Support.php) — same shape as log_activity()/log_lead_activity()/etc.
-- already used elsewhere in this codebase.
CREATE TABLE IF NOT EXISTS `opportunity_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `opportunity_id` int(11) NOT NULL,
  `action` varchar(64) NOT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_activities_opportunity` (`opportunity_id`, `created_at`),
  KEY `idx_opportunity_activities_created_by` (`created_by`),
  CONSTRAINT `opportunity_activities_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_activities_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable AI research job tracking. Table only, in Phase 1 — no endpoint, no
-- JobWorker::dispatch() wiring, no Claude CLI invocation yet (all Phase 7,
-- per docs/OPPORTUNITIES-IMPLEMENTATION.md §1.13/§3.1). Present now so the
-- rest of the schema doesn't need another migration later just to grow a
-- correlation FK against it.
CREATE TABLE IF NOT EXISTS `opportunity_research_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_type` varchar(64) NOT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `conference_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `opportunity_id` int(11) DEFAULT NULL,
  `input_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input_json`)),
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `error` text DEFAULT NULL,
  -- Loose reference (no FK) to background_jobs.id — that table is a generic
  -- queue shared by every job type in the app; scoping a FK to it from here
  -- would be an unusual direction for that table to be constrained in.
  `background_job_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_research_jobs_status` (`status`),
  KEY `idx_opportunity_research_jobs_conference` (`conference_id`),
  KEY `idx_opportunity_research_jobs_company` (`company_id`),
  KEY `idx_opportunity_research_jobs_opportunity` (`opportunity_id`),
  KEY `idx_opportunity_research_jobs_requested_by` (`requested_by`),
  CONSTRAINT `opportunity_research_jobs_conference_fk` FOREIGN KEY (`conference_id`) REFERENCES `opportunity_conferences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_research_jobs_company_fk` FOREIGN KEY (`company_id`) REFERENCES `opportunity_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_research_jobs_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_research_jobs_requested_by_fk` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
