-- =============================================================================
-- Panic Backstage — Super-admin registry schema
--
-- Applied once to the `panic_backstage_super` database.
-- Run with: php scripts/migrate.php super
--
-- Tables:
--   tenants           — one row per customer installation
--   tenant_domains    — hostname(s) that map to each tenant
--   super_admin_users — global administrator accounts (password_hash auth)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `slug`          VARCHAR(80)      NOT NULL COMMENT 'URL-safe identifier, e.g. "mabuhay"',
  `name`          VARCHAR(255)     NOT NULL COMMENT 'Human display name',
  `database_name` VARCHAR(120)     NOT NULL COMMENT 'MySQL database, e.g. "panic_backstage_mabuhay"',
  `status`        ENUM('provisioning','active','suspended') NOT NULL DEFAULT 'active',
  `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug`          (`slug`),
  UNIQUE KEY `uq_tenants_database_name` (`database_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tenant_domains` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL,
  `domain`     VARCHAR(253) NOT NULL COMMENT 'Full hostname, e.g. "mabuhay.panicbackstage.com"',
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_domains_domain` (`domain`),
  INDEX `idx_td_domain`     (`domain`),
  INDEX `idx_td_tenant_id`  (`tenant_id`),
  CONSTRAINT `fk_td_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `super_admin_users` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'bcrypt via password_hash()',
  `display_name` VARCHAR(160) NOT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_super_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
