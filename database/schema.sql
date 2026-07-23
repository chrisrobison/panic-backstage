-- =============================================================================
-- Panic Backstage — canonical database schema
--
-- This file is the single source of truth for a fresh database install.
-- It is regenerated from the live database whenever migrations are squashed.
-- Last squash: 2026-07-16  (through migration 063_admin_nav_navigation_item)
--
-- Fresh install:
--   mysql -u <user> -p <dbname> < database/schema.sql
--
-- After a fresh install, zero migrations are pending because everything is
-- already baked in here. New schema changes go in database/migrations/.
--
-- To squash again in the future:
--   mysqldump --no-data --single-transaction --add-drop-table --routines \
--     --skip-triggers --set-charset <dbname> > database/schema.sql
--   Delete migration files, clear schema_migrations table, commit.
--
-- Note: --skip-triggers is deliberate. The per-table audit triggers
-- (trg_<table>_ai/_au/_ad -> db_history) are DEFINER-bound and generated
-- out-of-band by scripts/generate-audit-triggers.php; they are not part of
-- the portable baseline. Run that script separately after a fresh install
-- if you want them.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

DROP TABLE IF EXISTS `accounting_coa_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_coa_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ledger_category` varchar(60) NOT NULL COMMENT 'event_ledger_entries.category value',
  `provider` enum('qbo','xero','none') NOT NULL DEFAULT 'none',
  `account_code` varchar(60) DEFAULT NULL COMMENT 'e.g. "4000" in QBO or "200" in Xero',
  `account_name` varchar(255) DEFAULT NULL,
  `line_type` enum('revenue','cost','payment','receivable') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_provider` (`ledger_category`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `accounting_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `provider` enum('qbo','xero') NOT NULL,
  `status` enum('pending','synced','failed','skipped') NOT NULL DEFAULT 'pending',
  `external_id` varchar(255) DEFAULT NULL COMMENT 'Journal entry ID in QBO/Xero',
  `payload_json` longtext DEFAULT NULL,
  `error` text DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `bands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(80) DEFAULT NULL,
  `instagram_url` varchar(500) DEFAULT NULL,
  `website_url` varchar(500) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `client_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `role` enum('client','promoter','artist','co_promoter','other') NOT NULL DEFAULT 'client',
  `revenue` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_profile_event` (`profile_id`,`event_id`),
  KEY `profile_id` (`profile_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `client_events_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_events_ibfk_profile` FOREIGN KEY (`profile_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `client_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('note','task','followup','communication','audit') NOT NULL DEFAULT 'note',
  `body` text NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `profile_id` (`profile_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `client_notes_ibfk_profile` FOREIGN KEY (`profile_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_notes_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `client_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('promoter','client','artist','company','venue','other') NOT NULL DEFAULT 'client',
  `name` varchar(255) NOT NULL,
  `org_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `website` varchar(500) DEFAULT NULL,
  `instagram_url` varchar(500) DEFAULT NULL,
  `relationship_owner_id` int(11) DEFAULT NULL,
  `relationship_status` enum('prospect','active','paused','ended','vip') NOT NULL DEFAULT 'prospect',
  `revenue_tier` enum('unknown','low','medium','high','vip') NOT NULL DEFAULT 'unknown',
  `rebook_potential` enum('unknown','unlikely','possible','likely','confirmed') NOT NULL DEFAULT 'unknown',
  `preferred_room` varchar(120) DEFAULT NULL,
  `preferred_event_types` varchar(255) DEFAULT NULL,
  `event_count` int(11) NOT NULL DEFAULT 0,
  `total_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_event_date` date DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `consent_marketing` tinyint(1) NOT NULL DEFAULT 0,
  `consent_date` date DEFAULT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_profiles_status` (`relationship_status`),
  KEY `idx_profiles_tier` (`revenue_tier`),
  KEY `relationship_owner_id` (`relationship_owner_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `client_profiles_ibfk_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_profiles_ibfk_owner` FOREIGN KEY (`relationship_owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contact_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(60) NOT NULL,
  `message` varchar(500) NOT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `contact_activity_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_activity_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2099 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contact_storage_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_storage_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `contact_limit` int(11) NOT NULL DEFAULT 250000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_contact_storage_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contact_tag_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_tag_assignments` (
  `contact_id` bigint(20) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `contact_tag_assignments_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tag_assignments_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `contact_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#2563eb',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_contact_tag_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contacts` (
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
) ENGINE=InnoDB AUTO_INCREMENT=874 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `signer_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_contract` (`contract_id`),
  KEY `idx_audit_signer` (`signer_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `contract_audit_log_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(80) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` enum('base','financial','operational','legal','risk') NOT NULL DEFAULT 'operational',
  `body_template` mediumtext NOT NULL,
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `risk_level` enum('none','low','medium','high') NOT NULL DEFAULT 'none',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `module_id` int(11) DEFAULT NULL,
  `module_key` varchar(80) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body_template` mediumtext NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `included` tinyint(1) NOT NULL DEFAULT 1,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `auto_selected` tinyint(1) NOT NULL DEFAULT 0,
  `risk_level` enum('none','low','medium','high') NOT NULL DEFAULT 'none',
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sections_contract` (`contract_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `contract_sections_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_sections_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `contract_modules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=677 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_signers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_signers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `role` varchar(40) NOT NULL DEFAULT 'renter' COMMENT 'renter|promoter|artist_rep|venue|guarantor',
  `name` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(320) NOT NULL DEFAULT '',
  `phone` varchar(40) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `status` enum('pending','sent','viewed','signed','declined','voided','expired') NOT NULL DEFAULT 'pending',
  `signing_token_hash` varchar(64) DEFAULT NULL COMMENT 'sha256(raw_token) — raw token is never persisted',
  `token_expires_at` datetime DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `signature_text` varchar(255) DEFAULT NULL COMMENT 'typed name used as electronic signature',
  `signature_image_path` varchar(500) DEFAULT NULL COMMENT 'drawn-signature PNG path relative to project root (optional)',
  `provider_recipient_id` varchar(255) DEFAULT NULL COMMENT 'signer ID assigned by external provider',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_signers_contract` (`contract_id`),
  KEY `idx_signers_token_hash` (`signing_token_hash`),
  KEY `idx_signers_status` (`status`),
  CONSTRAINT `contract_signers_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_template_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_template_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `condition_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`condition_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_module` (`template_id`,`module_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `contract_template_modules_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `contract_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_template_modules_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `contract_modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `contract_type` enum('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  `intro_text` mediumtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contract_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `rendered_html` mediumtext DEFAULT NULL,
  `rendered_text` mediumtext DEFAULT NULL,
  `variables_snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables_snapshot_json`)),
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary_json`)),
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_versions_contract` (`contract_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `contract_versions_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_versions_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) DEFAULT NULL,
  `venue_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `contract_type` enum('private_event','promoter_show','artist_performance','recurring_night','fundraiser','house_show','other') NOT NULL DEFAULT 'other',
  `title` varchar(255) NOT NULL,
  `status` enum('draft','needs_review','approved','sent','signed','canceled','superseded','ready_to_send','viewed','partially_signed','signed_by_client','countersigned','fully_executed','voided','declined','expired','error') NOT NULL DEFAULT 'draft',
  `provider` varchar(40) NOT NULL DEFAULT 'internal' COMMENT 'internal|mock|dropbox_sign|docusign',
  `provider_envelope_id` varchar(255) DEFAULT NULL,
  `provider_status` varchar(80) DEFAULT NULL,
  `preview_pdf_path` varchar(500) DEFAULT NULL,
  `final_pdf_path` varchar(500) DEFAULT NULL,
  `final_pdf_sha256` varchar(64) DEFAULT NULL,
  `counterparty_name` varchar(255) DEFAULT NULL,
  `counterparty_org` varchar(255) DEFAULT NULL,
  `counterparty_email` varchar(255) DEFAULT NULL,
  `rental_fee` decimal(10,2) DEFAULT NULL,
  `deposit_amount` decimal(10,2) DEFAULT NULL,
  `balance_due_date` date DEFAULT NULL,
  `bar_minimum` decimal(10,2) DEFAULT NULL,
  `guarantee_amount` decimal(10,2) DEFAULT NULL,
  `door_split_artist` decimal(5,2) DEFAULT NULL,
  `door_split_venue` decimal(5,2) DEFAULT NULL,
  `door_split_promoter` decimal(5,2) DEFAULT NULL,
  `advance_ticket_price` decimal(10,2) DEFAULT NULL,
  `door_ticket_price` decimal(10,2) DEFAULT NULL,
  `security_count` int(11) DEFAULT NULL,
  `security_rate` decimal(10,2) DEFAULT NULL,
  `security_paid_by` enum('venue','artist','promoter','client','shared') DEFAULT NULL,
  `sound_tech_included` tinyint(1) DEFAULT NULL,
  `lighting_tech_included` tinyint(1) DEFAULT NULL,
  `merch_venue_percent` decimal(5,2) DEFAULT NULL,
  `recurrence_rule` varchar(255) DEFAULT NULL,
  `term_start` date DEFAULT NULL,
  `term_end` date DEFAULT NULL,
  `trial_period_weeks` int(11) DEFAULT NULL,
  `termination_notice_days` int(11) DEFAULT NULL,
  `review_cadence` varchar(120) DEFAULT NULL,
  `revenue_split_house` decimal(5,2) DEFAULT NULL,
  `revenue_split_producer` decimal(5,2) DEFAULT NULL,
  `variables_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables_json`)),
  `internal_notes` text DEFAULT NULL,
  `current_version_id` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `fully_executed_at` timestamp NULL DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contracts_event` (`event_id`),
  KEY `idx_contracts_status` (`status`),
  KEY `idx_contracts_type` (`contract_type`),
  KEY `venue_id` (`venue_id`),
  KEY `template_id` (`template_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `approved_by_user_id` (`approved_by_user_id`),
  KEY `idx_contracts_asset` (`asset_id`),
  CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_2` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_3` FOREIGN KEY (`template_id`) REFERENCES `contract_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_4` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_ibfk_5` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `db_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `db_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL,
  `pk_column` varchar(64) NOT NULL,
  `pk_value` varchar(255) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `actor` varchar(128) DEFAULT NULL,
  `old_row` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_row`)),
  `new_row` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_row`)),
  `undo_sql` mediumtext NOT NULL,
  `undone_at` timestamp(6) NULL DEFAULT NULL,
  `undone_by_actor` varchar(128) DEFAULT NULL,
  `undo_of_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  KEY `idx_table_pk` (`table_name`,`pk_value`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_undo_of` (`undo_of_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11967 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_campaign_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_campaign_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_event` (`campaign_id`,`event_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `email_campaign_events_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_campaign_events_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_campaign_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_campaign_recipients` (
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_campaigns` (
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_verification_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_verification_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_verif_user` (`user_id`),
  KEY `idx_email_verif_email` (`email`),
  CONSTRAINT `email_verification_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `event_activity_log_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_activity_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2810 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `asset_type` enum('flyer','poster','band_photo','logo','social_square','social_story','press_photo','qr_code','contract','other') NOT NULL DEFAULT 'other',
  `title` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_by_user_id` int(11) DEFAULT NULL,
  `approval_status` enum('draft','needs_review','approved','rejected') NOT NULL DEFAULT 'needs_review',
  `notes` text DEFAULT NULL,
  `generation_source` varchar(50) DEFAULT NULL,
  `generation_prompt` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `uploaded_by_user_id` (`uploaded_by_user_id`),
  CONSTRAINT `event_assets_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_assets_ibfk_2` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_blockers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_blockers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `status` enum('open','waiting','resolved','canceled') NOT NULL DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `event_blockers_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_blockers_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_closeout_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_closeout_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `status` enum('open','in_progress','pending_review','finalized','reopened') NOT NULL DEFAULT 'open',
  `contract_signed` tinyint(1) NOT NULL DEFAULT 0,
  `deposit_received` tinyint(1) NOT NULL DEFAULT 0,
  `vendors_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `staffing_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `bar_closed` tinyint(1) NOT NULL DEFAULT 0,
  `cash_reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `all_invoices_collected` tinyint(1) NOT NULL DEFAULT 0,
  `finalized_by_id` int(11) DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `reopen_reason` text DEFAULT NULL,
  `reopened_by_id` int(11) DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`),
  KEY `finalized_by_id` (`finalized_by_id`),
  KEY `closeout_ibfk_reopener` (`reopened_by_id`),
  CONSTRAINT `closeout_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `closeout_ibfk_finalizer` FOREIGN KEY (`finalized_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `closeout_ibfk_reopener` FOREIGN KEY (`reopened_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_collaborators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_collaborators` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_user` (`event_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `event_collaborators_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_collaborators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_execution_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_execution_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `record_type` enum('incident','change_order','bar_note','damage','overage','checklist','deviation','safety_note','general') NOT NULL DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `occurred_at` datetime DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `client_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by` varchar(255) DEFAULT NULL,
  `linked_ledger_entry_id` int(11) DEFAULT NULL,
  `is_restricted` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_id` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `attachments_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments_json`)),
  `author_id` int(11) DEFAULT NULL,
  `author_role` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exec_event` (`event_id`),
  KEY `idx_exec_type` (`record_type`),
  KEY `author_id` (`author_id`),
  CONSTRAINT `exec_ibfk_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exec_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_guest_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_guest_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `party_size` int(11) NOT NULL DEFAULT 1,
  `list_type` enum('comp','guest','will_call','vip','press','industry') NOT NULL DEFAULT 'guest',
  `comp_order_id` int(11) DEFAULT NULL,
  `guest_of` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `checked_in` tinyint(1) NOT NULL DEFAULT 0,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guest_event` (`event_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `event_guest_list_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_guest_list_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer') NOT NULL DEFAULT 'viewer',
  `token` varchar(255) NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_invites_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_ledger_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `category` enum('tickets','ticket_fees','bar_sales','rental_fee','hosted_bar','merch_share','sponsorship','equipment_rental','overtime_charge','other_revenue','artist_guarantee','promoter_settlement','labor','sound_production','security','cleaning','rentals','catering','vendor_cost','processing_fees','taxes','refunds','other_cost','deposit_received','invoice_payment','credit','outstanding_balance','artist_payout','promoter_payout','vendor_payout','staff_payout','adjustment') NOT NULL,
  `line_type` enum('revenue','cost','payment','receivable') NOT NULL DEFAULT 'revenue',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `description` varchar(500) DEFAULT NULL,
  `source` enum('manual','ticketing_sync','pos_import','vendor_link','staffing_link','payment_link','change_order_link','system') NOT NULL DEFAULT 'manual',
  `source_ref_id` int(11) DEFAULT NULL,
  `source_ref_str` varchar(255) DEFAULT NULL COMMENT 'String external reference (e.g. Square payment ID) for sources that do not use an integer FK',
  `reconciler_id` int(11) DEFAULT NULL,
  `reconciled_at` datetime DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_event` (`event_id`),
  KEY `idx_ledger_category` (`category`),
  KEY `idx_ledger_type` (`line_type`),
  KEY `reconciler_id` (`reconciler_id`),
  KEY `created_by_id` (`created_by_id`),
  KEY `idx_ledger_source_ref_str` (`source`,`source_ref_str`(64)),
  CONSTRAINT `ledger_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ledger_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ledger_ibfk_reconciler` FOREIGN KEY (`reconciler_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_lineup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_lineup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `band_id` int(11) DEFAULT NULL,
  `billing_order` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(255) NOT NULL,
  `set_time` time DEFAULT NULL,
  `set_length_minutes` int(11) DEFAULT NULL,
  `payout_terms` varchar(255) DEFAULT NULL,
  `status` enum('invited','tentative','confirmed','canceled') NOT NULL DEFAULT 'tentative',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `band_id` (`band_id`),
  CONSTRAINT `event_lineup_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_lineup_ibfk_2` FOREIGN KEY (`band_id`) REFERENCES `bands` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_payment_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_payment_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `old_value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value_json`)),
  `new_value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value_json`)),
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `pay_audit_ibfk_payment` FOREIGN KEY (`payment_id`) REFERENCES `event_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `payment_type` enum('deposit','balance_payment','refund','credit','adjustment','promoter_payment','client_payment','other') NOT NULL DEFAULT 'other',
  `direction` enum('received','paid_out') NOT NULL DEFAULT 'received',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('pending','invoiced','received','failed','refunded','voided') NOT NULL DEFAULT 'pending',
  `method` enum('cash','check','ach','wire','credit_card','stripe','square','venmo','zelle','other') DEFAULT NULL,
  `processor_reference` varchar(255) DEFAULT NULL,
  `invoice_reference` varchar(255) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `updated_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `external_ref` varchar(255) DEFAULT NULL COMMENT 'External processor reference, e.g. Stripe payment link ID (plink_...)',
  PRIMARY KEY (`id`),
  KEY `idx_payments_event` (`event_id`),
  KEY `idx_payments_type` (`payment_type`),
  KEY `idx_payments_status` (`status`),
  KEY `created_by_id` (`created_by_id`),
  CONSTRAINT `event_payments_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `event_payments_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_scanner_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_scanner_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `token` varchar(64) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_scanner_token` (`token_hash`),
  KEY `idx_event_scanner_links_event` (`event_id`),
  CONSTRAINT `event_scanner_links_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_schedule_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_schedule_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `item_type` enum('load_in','soundcheck','doors','set','changeover','curfew','staff_call','other') NOT NULL DEFAULT 'other',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_schedule_items_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=131452 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_series` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `pattern_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pattern_json`)),
  `description` varchar(255) DEFAULT NULL COMMENT 'Human label, e.g. "Every other Tuesday"',
  `end_type` enum('on_date','after_count') NOT NULL DEFAULT 'after_count',
  `end_date` date DEFAULT NULL,
  `occurrence_count` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_series_venue` (`venue_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `event_series_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`),
  CONSTRAINT `event_series_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `label` varchar(120) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_sessions_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `gross_ticket_sales` decimal(10,2) DEFAULT 0.00,
  `tickets_sold` int(11) DEFAULT 0,
  `bar_sales` decimal(10,2) DEFAULT 0.00,
  `expenses` decimal(10,2) DEFAULT 0.00,
  `band_payouts` decimal(10,2) DEFAULT 0.00,
  `promoter_payout` decimal(10,2) DEFAULT 0.00,
  `venue_net` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `settled_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`),
  KEY `settled_by_user_id` (`settled_by_user_id`),
  CONSTRAINT `event_settlements_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_settlements_ibfk_2` FOREIGN KEY (`settled_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_sheet_shadow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_sheet_shadow` (
  `event_id` int(11) NOT NULL,
  `raw_json` longtext NOT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_staffing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_staffing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `shift_date` date DEFAULT NULL,
  `staff_member_id` int(11) DEFAULT NULL,
  `role` enum('manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other') NOT NULL DEFAULT 'other',
  `call_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `status` enum('scheduled','confirmed','declined','no_show','completed','canceled') NOT NULL DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `source` enum('generated','template','manual') NOT NULL DEFAULT 'manual',
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `clock_in` datetime DEFAULT NULL,
  `clock_out` datetime DEFAULT NULL,
  `actual_hours` decimal(5,2) DEFAULT NULL,
  `approved_overtime_hours` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staffing_event` (`event_id`),
  KEY `idx_staffing_role` (`role`),
  KEY `staff_member_id` (`staff_member_id`),
  CONSTRAINT `event_staffing_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_staffing_ibfk_2` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=274 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('todo','in_progress','blocked','done','canceled') NOT NULL DEFAULT 'todo',
  `assigned_user_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `assigned_user_id` (`assigned_user_id`),
  CONSTRAINT `event_tasks_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_tasks_ibfk_2` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=421 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `event_type` enum('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  `default_title` varchar(255) DEFAULT NULL,
  `default_description_public` text DEFAULT NULL,
  `default_ticket_price` decimal(10,2) DEFAULT 0.00,
  `default_age_restriction` varchar(80) DEFAULT NULL,
  `checklist_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist_json`)),
  `schedule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `staffing_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`staffing_json`)),
  PRIMARY KEY (`id`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `event_templates_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `event_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(60) DEFAULT NULL,
  `service_category` enum('sound','lighting','av','catering','security','cleaning','photography','videography','florist','rental','transportation','staffing_agency','entertainment','production','venue_support','other') NOT NULL DEFAULT 'other',
  `description` text DEFAULT NULL,
  `quote_amount` decimal(10,2) DEFAULT NULL,
  `approved_amount` decimal(10,2) DEFAULT NULL,
  `actual_amount` decimal(10,2) DEFAULT NULL,
  `payment_status` enum('not_required','unpaid','partial','paid','voided') NOT NULL DEFAULT 'unpaid',
  `coi_required` tinyint(1) NOT NULL DEFAULT 0,
  `coi_status` enum('not_required','requested','received','expired','waived') NOT NULL DEFAULT 'not_required',
  `coi_expiry_date` date DEFAULT NULL,
  `confirmation_status` enum('unconfirmed','confirmed','canceled') NOT NULL DEFAULT 'unconfirmed',
  `confirmed_at` datetime DEFAULT NULL,
  `load_in_time` time DEFAULT NULL,
  `load_out_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendors_event` (`event_id`),
  KEY `idx_vendors_category` (`service_category`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `event_vendors_ibfk_creator` (`created_by_id`),
  CONSTRAINT `event_vendors_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `event_vendors_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_vendors_ibfk_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_id` varchar(50) DEFAULT NULL,
  `venue_id` int(11) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `series_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `event_type` enum('live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event') NOT NULL,
  `status` enum('empty','proposed','confirmed','booked','needs_assets','assets_approved','ready_to_announce','published','advanced','completed','settled','canceled') NOT NULL DEFAULT 'proposed',
  `description_public` text DEFAULT NULL,
  `public_subtitle` varchar(255) DEFAULT NULL,
  `public_tags` varchar(255) DEFAULT NULL,
  `public_schedule_pricing` text DEFAULT NULL,
  `description_internal` text DEFAULT NULL,
  `av_requirements` text DEFAULT NULL,
  `catering_notes` text DEFAULT NULL,
  `referral_source` varchar(255) DEFAULT NULL,
  `promoter_name` varchar(255) DEFAULT NULL,
  `promoter_email` varchar(255) DEFAULT NULL,
  `promoter_phone` varchar(50) DEFAULT NULL,
  `client_org` varchar(255) DEFAULT NULL,
  `booker_name` varchar(255) DEFAULT NULL,
  `booker_email` varchar(255) DEFAULT NULL,
  `booker_phone` varchar(50) DEFAULT NULL,
  `date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `doors_time` time DEFAULT NULL,
  `show_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `load_in_time` time DEFAULT NULL,
  `age_restriction` varchar(80) DEFAULT NULL,
  `ticket_price` decimal(10,2) DEFAULT 0.00,
  `deposit_amount` decimal(10,2) DEFAULT NULL,
  `deposit_status` enum('not_required','requested','partially_received','received','waived','refunded') NOT NULL DEFAULT 'not_required',
  `deposit_waived_by_id` int(11) DEFAULT NULL,
  `deposit_waived_reason` varchar(500) DEFAULT NULL,
  `potential_revenue` decimal(10,2) DEFAULT NULL,
  `ticket_url` varchar(500) DEFAULT NULL,
  `ticket_system` varchar(40) DEFAULT NULL,
  `contract_url` varchar(500) DEFAULT NULL,
  `venue_contract_url` varchar(500) DEFAULT NULL,
  `walkthrough_done` tinyint(1) NOT NULL DEFAULT 0,
  `settlement_doc_url` varchar(500) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `estimated_guests` int(11) DEFAULT NULL,
  `room` enum('upstairs','downstairs','both') DEFAULT NULL,
  `public_visibility` tinyint(1) NOT NULL DEFAULT 0,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `policy_snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`policy_snapshot_json`)),
  `owner_user_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `client_profile_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ticketing_mode` enum('external','internal') NOT NULL DEFAULT 'external',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `idx_events_external_id` (`external_id`),
  KEY `venue_id` (`venue_id`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `resource_id` (`resource_id`),
  KEY `lead_id` (`lead_id`),
  KEY `idx_events_series` (`series_id`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`),
  CONSTRAINT `events_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `events_resource_fk` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `events_series_fk` FOREIGN KEY (`series_id`) REFERENCES `event_series` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=671531 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `lead_deal_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lead_deal_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `deal_type` enum('rental_buyout','guarantee','door_split','guarantee_plus_pct','bar_minimum','hybrid','private_hosted_bar','other') NOT NULL DEFAULT 'other',
  `room_capacity` int(11) DEFAULT NULL,
  `expected_attendance` int(11) DEFAULT NULL,
  `ticket_price` decimal(10,2) DEFAULT NULL,
  `ticket_fee_per` decimal(10,2) DEFAULT NULL,
  `rental_fee` decimal(10,2) DEFAULT NULL,
  `artist_guarantee` decimal(10,2) DEFAULT NULL,
  `projected_bar_spend` decimal(10,2) DEFAULT NULL,
  `bar_minimum` decimal(10,2) DEFAULT NULL,
  `labor_forecast` decimal(10,2) DEFAULT NULL,
  `production_costs` decimal(10,2) DEFAULT NULL,
  `facility_costs` decimal(10,2) DEFAULT NULL,
  `other_costs` decimal(10,2) DEFAULT NULL,
  `calc_gross_revenue` decimal(10,2) DEFAULT NULL,
  `calc_estimated_cost` decimal(10,2) DEFAULT NULL,
  `calc_venue_net` decimal(10,2) DEFAULT NULL,
  `calc_margin_pct` decimal(6,2) DEFAULT NULL,
  `calc_break_even_attendance` int(11) DEFAULT NULL,
  `calc_min_tickets_guarantee` int(11) DEFAULT NULL,
  `risk_flags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`risk_flags_json`)),
  `approval_status` enum('pending','approved','needs_review','declined') NOT NULL DEFAULT 'pending',
  `approved_by_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `event_id` (`event_id`),
  KEY `approved_by_id` (`approved_by_id`),
  KEY `created_by_id` (`created_by_id`),
  CONSTRAINT `lead_eval_ibfk_approver` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_eval_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_eval_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_eval_ibfk_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `lead_intake_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lead_intake_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `channel` varchar(40) NOT NULL DEFAULT 'email',
  `message_id` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `reply_to` varchar(255) DEFAULT NULL,
  `to_recipients` varchar(1000) DEFAULT NULL,
  `subject` varchar(1000) DEFAULT NULL,
  `parse_method` enum('jotform','llm','jotform+llm','heuristic','none') NOT NULL DEFAULT 'none',
  `status` enum('imported','duplicate','error','skipped') NOT NULL DEFAULT 'imported',
  `error_message` text DEFAULT NULL,
  `parsed_json` longtext DEFAULT NULL,
  `raw_email` mediumtext DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_message_id` (`message_id`),
  KEY `idx_intake_lead` (`lead_id`),
  KEY `idx_intake_status` (`status`),
  CONSTRAINT `lead_intake_ibfk_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `lead_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lead_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('note','task','status_change','audit') NOT NULL DEFAULT 'note',
  `body` text NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `lead_notes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` enum('new','triage','evaluating','needs_review','approved','declined','converted','canceled') NOT NULL DEFAULT 'new',
  `source` enum('internal','website','promoter','referral','peerspace','eventective','giggster','phone','email','manual','other') NOT NULL DEFAULT 'manual',
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_org` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(60) DEFAULT NULL,
  `event_name` varchar(255) DEFAULT NULL,
  `event_type` varchar(80) DEFAULT NULL,
  `band_name` varchar(500) DEFAULT NULL,
  `desired_date` date DEFAULT NULL,
  `desired_date_alt` date DEFAULT NULL,
  `rooms_requested` varchar(255) DEFAULT NULL,
  `projected_attendance` int(11) DEFAULT NULL,
  `budget` decimal(10,2) DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `alcohol_plan` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `point_person_id` int(11) DEFAULT NULL,
  `risk_level` enum('low','medium','high','unknown') NOT NULL DEFAULT 'unknown',
  `decline_reason` varchar(255) DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  `decision_by_id` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `converted_event_id` int(11) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_source` (`source`),
  KEY `idx_leads_date` (`desired_date`),
  KEY `point_person_id` (`point_person_id`),
  KEY `decision_by_id` (`decision_by_id`),
  KEY `created_by_id` (`created_by_id`),
  KEY `converted_event_id` (`converted_event_id`),
  CONSTRAINT `leads_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_decision` FOREIGN KEY (`decision_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_event` FOREIGN KEY (`converted_event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_point` FOREIGN KEY (`point_person_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=171 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `list_export_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `list_export_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) DEFAULT NULL,
  `list_name_snapshot` varchar(160) DEFAULT NULL,
  `format` varchar(10) NOT NULL DEFAULT 'csv',
  `row_count` int(11) NOT NULL DEFAULT 0,
  `filters_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`filters_json` is null or json_valid(`filters_json`)),
  `exported_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`),
  KEY `exported_by_user_id` (`exported_by_user_id`),
  CONSTRAINT `list_export_history_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE SET NULL,
  CONSTRAINT `list_export_history_ibfk_2` FOREIGN KEY (`exported_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `list_import_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `list_import_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `created_count` int(11) NOT NULL DEFAULT 0,
  `updated_count` int(11) NOT NULL DEFAULT 0,
  `added_to_list` int(11) NOT NULL DEFAULT 0,
  `skipped_count` int(11) NOT NULL DEFAULT 0,
  `errors_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`errors_json` is null or json_valid(`errors_json`)),
  `imported_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`),
  KEY `imported_by_user_id` (`imported_by_user_id`),
  CONSTRAINT `list_import_history_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `list_import_history_ibfk_2` FOREIGN KEY (`imported_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `list_membership`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `list_membership` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `status` enum('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed',
  `added_via` enum('manual','bulk','csv_import','segment') NOT NULL DEFAULT 'manual',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_list_contact` (`list_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `list_membership_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `list_membership_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6261 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `magic_link_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `magic_link_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_mlt_hash` (`token_hash`),
  KEY `idx_mlt_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=347 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `mailing_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mailing_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `list_type` enum('static','segment') NOT NULL DEFAULT 'static',
  `segment_rules` text DEFAULT NULL COMMENT 'JSON object of filter criteria, e.g. {"opted":1,"min_spend":500}',
  `segment_refreshed_at` datetime DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mailing_list_name` (`name`),
  KEY `created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `mailing_lists_ibfk_1` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sender_user_id` int(11) DEFAULT NULL,
  `recipient_user_id` int(11) NOT NULL,
  `recipient_email` varchar(320) NOT NULL DEFAULT '',
  `subject` varchar(998) NOT NULL DEFAULT '',
  `body_text` mediumtext DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `template` varchar(120) DEFAULT NULL,
  `in_reply_to_id` bigint(20) DEFAULT NULL,
  `outbox_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inbox` (`recipient_user_id`,`archived_at`,`created_at`),
  KEY `idx_sent` (`sender_user_id`,`created_at`),
  KEY `idx_unread` (`recipient_user_id`,`read_at`)
) ENGINE=InnoDB AUTO_INCREMENT=968 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `nav_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nav_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `label` varchar(80) NOT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'fa-solid fa-circle',
  `link` varchar(255) DEFAULT NULL,
  `capability` varchar(60) DEFAULT NULL,
  `open_in_new_window` tinyint(1) NOT NULL DEFAULT 0,
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  `is_home` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `nav_items_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `nav_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `outbox` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `to_address` varchar(320) NOT NULL,
  `subject` varchar(998) NOT NULL DEFAULT '',
  `text_body` mediumtext DEFAULT NULL,
  `html_body` mediumtext DEFAULT NULL,
  `template` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sent_at` (`sent_at`),
  KEY `idx_to` (`to_address`(64))
) ENGINE=InnoDB AUTO_INCREMENT=999 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `passkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(1024) NOT NULL,
  `public_key_pem` text NOT NULL,
  `sign_count` bigint(20) NOT NULL DEFAULT 0,
  `transports` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'Passkey',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credential_id` (`credential_id`) USING HASH,
  KEY `idx_passkeys_user` (`user_id`),
  CONSTRAINT `passkeys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payment_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `active_provider` varchar(40) NOT NULL DEFAULT 'square',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `settings_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings_json`)),
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `portal_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portal_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `label` varchar(255) DEFAULT NULL COMMENT 'e.g. "Sent to promoter Jane Smith"',
  `created_by_id` int(11) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `is_revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `event_id` (`event_id`),
  KEY `created_by_id` (`created_by_id`),
  CONSTRAINT `portal_tokens_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_tokens_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pos_location_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_location_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pos_provider` enum('square') NOT NULL DEFAULT 'square',
  `location_id` varchar(128) NOT NULL COMMENT 'Square location ID (e.g. LXXXXXXXXXXX)',
  `venue_id` int(11) NOT NULL,
  `default_category` enum('bar_sales','merch_share','other_revenue') NOT NULL DEFAULT 'bar_sales',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_event_id` int(11) DEFAULT NULL COMMENT 'Explicit event override — set this to route all POS sales to a specific event',
  `active_event_set_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_location` (`pos_provider`,`location_id`),
  KEY `venue_id` (`venue_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_auto_publish_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_auto_publish_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `auto_publish_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `auto_publish_destinations` text DEFAULT NULL COMMENT 'JSON array of destination_key strings',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `promote_auto_publish_settings_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_broadcast_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_broadcast_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `destination_key` varchar(80) NOT NULL,
  `destination_group` varchar(80) NOT NULL,
  `status` enum('queued','sent','manual_required','needs_auth','failed','skipped') NOT NULL DEFAULT 'queued',
  `external_url` varchar(500) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `broadcast_id` (`broadcast_id`),
  CONSTRAINT `promote_broadcast_results_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `promote_broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_broadcasts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `send_mode` enum('now','scheduled') NOT NULL DEFAULT 'now',
  `scheduled_at` datetime DEFAULT NULL,
  `status` enum('draft','queued','processing','completed','partial_failure','failed') NOT NULL DEFAULT 'queued',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `promote_broadcasts_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `promote_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_broadcasts_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_broadcasts_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `destination_key` varchar(80) NOT NULL,
  `access_token` text DEFAULT NULL,
  `enc_access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `enc_refresh_token` text DEFAULT NULL,
  `enc_key_version` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `token_expires_at` datetime DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `status` enum('connected','needs_auth','error') NOT NULL DEFAULT 'needs_auth',
  `error_message` text DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_venue_destination` (`venue_id`,`destination_key`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `promote_credentials_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_destinations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destination_key` varchar(80) NOT NULL,
  `destination_group` enum('direct_post','event_platform','editorial_submission','email') NOT NULL,
  `label` varchar(120) NOT NULL,
  `status` enum('connected','needs_auth','manual_submission','disabled') NOT NULL DEFAULT 'manual_submission',
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_destination_key` (`destination_key`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_oauth_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_oauth_states` (
  `state` varchar(64) NOT NULL,
  `venue_id` int(11) NOT NULL,
  `destination_key` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `code_verifier` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`state`),
  KEY `idx_promote_oauth_states_created_at` (`created_at`),
  KEY `promote_oauth_states_ibfk_1` (`venue_id`),
  CONSTRAINT `promote_oauth_states_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_post_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_post_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `channel` varchar(80) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `status` enum('draft','ready','needs_review','approved') NOT NULL DEFAULT 'draft',
  `warnings_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`warnings_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_channel` (`post_id`,`channel`),
  CONSTRAINT `promote_post_variants_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `promote_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=249 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `master_text` text DEFAULT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `status` enum('draft','approved','scheduled','sent','archived') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `promote_posts_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `event_assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_posts_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `promote_posts_ibfk_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promote_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promote_settings` (
  `event_id` int(11) NOT NULL,
  `status` enum('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  `goal_tickets` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `accounting_provider` enum('none','qbo','xero') NOT NULL DEFAULT 'none',
  `accounting_sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_id`),
  KEY `promote_settings_ibfk_2` (`created_by_user_id`),
  CONSTRAINT `promote_settings_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promote_settings_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `bucket` varchar(191) NOT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 1,
  `window_started_at` timestamp(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_rt_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `refresh_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=384 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `zone` varchar(20) NOT NULL DEFAULT 'primary',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `venue_slug` (`venue_id`,`slug`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `filename` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sheet_import_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sheet_import_links` (
  `event_id` int(11) NOT NULL,
  `sheet_row` int(11) NOT NULL,
  `title_snap` varchar(200) NOT NULL DEFAULT '',
  `date_snap` date DEFAULT NULL,
  `linked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_unlinked` (`linked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sheet_sync_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sheet_sync_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `status` enum('pending','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pushed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event` (`event_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `sheet_sync_queue_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1316 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `staff_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `pronoun` varchar(40) DEFAULT NULL,
  `default_role` enum('manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other') NOT NULL DEFAULT 'other',
  `employment_type` enum('employee','contractor') NOT NULL DEFAULT 'employee',
  `position` varchar(120) DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_active` (`active`),
  KEY `idx_staff_default_role` (`default_role`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `staff_members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `systems_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `systems_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` enum('social','ticketing','payment','email','analytics','security','hosting','dns','storage','communication','pos','other') NOT NULL DEFAULT 'other',
  `url` varchar(500) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `owner_name` varchar(255) DEFAULT NULL,
  `owner_email` varchar(255) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `recovery_path` text DEFAULT NULL,
  `vault_reference` varchar(500) DEFAULT NULL,
  `renewal_date` date DEFAULT NULL,
  `expiry_alert_days` int(11) NOT NULL DEFAULT 30,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_category` (`category`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `inventory_ibfk_creator` (`created_by_id`),
  CONSTRAINT `inventory_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_ibfk_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `ticket_type_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price_cents` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_type_id` (`ticket_type_id`),
  KEY `idx_ticket_order_items_order` (`order_id`),
  CONSTRAINT `ticket_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `ticket_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_order_items_ibfk_2` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `buyer_user_id` int(11) DEFAULT NULL,
  `buyer_name` varchar(200) DEFAULT NULL,
  `buyer_email` varchar(255) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `provider` varchar(40) DEFAULT NULL,
  `provider_ref` varchar(191) DEFAULT NULL,
  `provider_payment_ref` varchar(191) DEFAULT NULL,
  `receipt_token` varchar(64) DEFAULT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT 0,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('pending','paid','fulfilled','canceled','refunded','expired') NOT NULL DEFAULT 'pending',
  `is_comp` tinyint(1) NOT NULL DEFAULT 0,
  `hold_expires_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `emailed_at` datetime DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ticket_orders_receipt_token` (`receipt_token`),
  KEY `buyer_user_id` (`buyer_user_id`),
  KEY `idx_ticket_orders_event` (`event_id`),
  KEY `idx_ticket_orders_provider_ref` (`provider`,`provider_ref`),
  KEY `idx_ticket_orders_status` (`status`),
  CONSTRAINT `ticket_orders_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_orders_ibfk_2` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_scans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) DEFAULT NULL,
  `event_id` int(11) NOT NULL,
  `result` enum('admitted','already_redeemed','void','not_found','wrong_event','expired_link') NOT NULL,
  `scanner_link_id` int(11) DEFAULT NULL,
  `scanned_by_user_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_scans_ticket` (`ticket_id`),
  KEY `idx_ticket_scans_event` (`event_id`),
  CONSTRAINT `fk_ticket_scans_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_scans_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `price_cents` int(11) NOT NULL DEFAULT 0,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `quantity_total` int(11) NOT NULL,
  `quantity_sold` int(11) NOT NULL DEFAULT 0,
  `sales_start` datetime DEFAULT NULL,
  `sales_end` datetime DEFAULT NULL,
  `status` enum('draft','on_sale','paused','sold_out','closed') NOT NULL DEFAULT 'draft',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_types_event` (`event_id`),
  CONSTRAINT `ticket_types_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `ticket_type_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `code` varchar(40) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `token` varchar(64) DEFAULT NULL,
  `holder_name` varchar(200) DEFAULT NULL,
  `holder_email` varchar(255) DEFAULT NULL,
  `status` enum('issued','redeemed','void') NOT NULL DEFAULT 'issued',
  `redeemed_at` datetime DEFAULT NULL,
  `redeemed_by_user_id` int(11) DEFAULT NULL,
  `redeemed_via_scanner_id` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_token` (`token_hash`),
  UNIQUE KEY `uq_tickets_code` (`code`),
  KEY `ticket_type_id` (`ticket_type_id`),
  KEY `order_id` (`order_id`),
  KEY `idx_tickets_event` (`event_id`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `ticket_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_merges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_merges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `survivor_user_id` int(11) NOT NULL,
  `loser_user_id` int(11) NOT NULL,
  `loser_email` varchar(255) DEFAULT NULL,
  `performed_by_user_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_merges_survivor` (`survivor_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `token_version` int(10) unsigned NOT NULL DEFAULT 0,
  `role` enum('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer','global_viewer') NOT NULL DEFAULT 'viewer',
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `support_super_admin_id` int(10) unsigned DEFAULT NULL,
  `access_status` enum('active','requested') NOT NULL DEFAULT 'active',
  `request_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `hide_credential_setup_prompt` tinyint(1) NOT NULL DEFAULT 0,
  `onboarding_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `default_landing` varchar(32) DEFAULT NULL,
  `nav_collapsed` tinyint(1) NOT NULL DEFAULT 0,
  `events_sort` varchar(8) DEFAULT NULL,
  `dashboard_metrics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of dashboard metric-card keys the user has chosen to show',
  `notify_event_updates` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Receive event status-change + private-event-inquiry emails',
  `notify_contracts` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Receive contract sent/signed/voided notification emails',
  `notify_access_requests` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Receive new-access-request notification emails',
  `alt_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`alt_emails`)),
  `privacy_policy_accepted_at` datetime DEFAULT NULL COMMENT 'When the user agreed to the privacy policy',
  `privacy_policy_version` varchar(32) DEFAULT NULL COMMENT 'Version of the privacy policy the user agreed to',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_support_super_admin` (`support_super_admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=123849 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `venue_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venue_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venue_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` date NOT NULL DEFAULT curdate(),
  `effective_to` date DEFAULT NULL,
  `rooms_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rooms_json`)),
  `default_age_rule` enum('all_ages','18_plus','21_plus','venue_discretion') NOT NULL DEFAULT 'venue_discretion',
  `default_alcohol_mode` enum('none','cash_bar','hosted_bar','bar_minimum','venue_discretion') NOT NULL DEFAULT 'venue_discretion',
  `default_bar_minimum` decimal(10,2) DEFAULT NULL,
  `deposit_required` tinyint(1) NOT NULL DEFAULT 1,
  `deposit_pct` decimal(5,2) DEFAULT NULL,
  `deposit_flat` decimal(10,2) DEFAULT NULL,
  `deposit_due_days` int(11) NOT NULL DEFAULT 14,
  `doors_earliest` time DEFAULT NULL,
  `curfew_time` time DEFAULT NULL,
  `load_in_earliest` time DEFAULT NULL,
  `staffing_rates_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`staffing_rates_json`)),
  `contract_required` tinyint(1) NOT NULL DEFAULT 1,
  `coi_required` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_policy_venue` (`venue_id`),
  KEY `idx_policy_active` (`venue_id`,`is_active`),
  KEY `venue_policies_ibfk_creator` (`created_by_id`),
  CONSTRAINT `venue_policies_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venue_policies_ibfk_venue` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `venues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(60) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL COMMENT 'Main venue phone number',
  `website_url` varchar(500) DEFAULT NULL COMMENT 'Public venue website',
  `timezone` varchar(80) NOT NULL DEFAULT 'America/Los_Angeles',
  `zone` varchar(20) DEFAULT NULL,
  `venue_group` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `webauthn_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webauthn_challenges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `challenge` varchar(512) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `intent` enum('register','login') NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `challenge` (`challenge`),
  KEY `idx_wc_challenge` (`challenge`)
) ENGINE=InnoDB AUTO_INCREMENT=445 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wizard_defaults`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wizard_defaults` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `defaults_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`defaults_json`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `wizard_defaults_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
