-- =============================================================================
-- Panic Backstage — super-admin registry schema
--
-- This file is the single source of truth for a fresh panic_backstage_super
-- database. It is regenerated whenever database/migrations/super/ is squashed.
-- Last squash: 2026-07-02  (through migration 001_super_schema)
--
-- Fresh install:
--   mysql -u <user> -p panic_backstage_super < database/schema-super.sql
--   php scripts/migrate.php super          # picks up anything not yet folded in
--
-- New schema changes for the super registry go in database/migrations/super/.
-- To squash again in the future:
--   mysqldump --no-data --single-transaction --add-drop-table --routines \
--     --triggers --set-charset panic_backstage_super > database/schema-super.sql
--   (then clean up the mysqldump boilerplate to match this file's style)
--   Delete migration files, commit.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

DROP TABLE IF EXISTS `super_admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL COMMENT 'bcrypt via password_hash()',
  `display_name` varchar(160) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_super_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tenant_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenant_domains` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) unsigned NOT NULL,
  `domain` varchar(253) NOT NULL COMMENT 'Full hostname, e.g. "mabuhay.panicbackstage.com"',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_domains_domain` (`domain`),
  KEY `idx_td_domain` (`domain`),
  KEY `idx_td_tenant_id` (`tenant_id`),
  CONSTRAINT `fk_td_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL COMMENT 'URL-safe identifier, e.g. "mabuhay"',
  `name` varchar(255) NOT NULL COMMENT 'Human display name',
  `database_name` varchar(120) NOT NULL COMMENT 'MySQL database, e.g. "panic_backstage_mabuhay"',
  `status` enum('provisioning','active','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`),
  UNIQUE KEY `uq_tenants_database_name` (`database_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
