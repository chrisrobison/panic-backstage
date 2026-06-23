-- Migration 016 (single-tenant): application-level encryption for Promote credentials.
--
-- Adds encrypted columns to promote_credentials alongside the existing
-- plaintext columns. The encryption migration script (scripts/encrypt-credentials.php)
-- reads plaintext access_token / refresh_token, encrypts them using
-- sodium_crypto_secretbox with the CREDENTIAL_ENCRYPTION_KEY env var,
-- writes the ciphertext to enc_access_token / enc_refresh_token, then
-- NULLs the plaintext columns.
--
-- The CredentialEncryption service falls back to plaintext when
-- enc_access_token IS NULL, so existing integrations continue working
-- until the migration script has run.
--
-- Also adds the systems_inventory table (non-credential platform catalog).

SET NAMES utf8mb4;

-- ── 1. Add encrypted columns to promote_credentials ──────────────────────────

ALTER TABLE `promote_credentials`
  ADD COLUMN IF NOT EXISTS `enc_access_token`  TEXT    DEFAULT NULL AFTER `access_token`,
  ADD COLUMN IF NOT EXISTS `enc_refresh_token` TEXT    DEFAULT NULL AFTER `refresh_token`,
  ADD COLUMN IF NOT EXISTS `enc_key_version`   TINYINT UNSIGNED NOT NULL DEFAULT 1
                              AFTER `enc_refresh_token`;

-- ── 2. systems_inventory ─────────────────────────────────────────────────────
-- Lightweight catalog of connected external systems/platforms.
-- NEVER stores passwords, recovery codes, MFA secrets, or credentials.

CREATE TABLE IF NOT EXISTS `systems_inventory` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(255)  NOT NULL,
  `category`            ENUM('social','ticketing','payment','email','analytics','security',
                             'hosting','dns','storage','communication','pos','other')
                          NOT NULL DEFAULT 'other',
  `url`                 VARCHAR(500)  DEFAULT NULL,
  `owner_user_id`       INT(11)       DEFAULT NULL,
  `owner_name`          VARCHAR(255)  DEFAULT NULL,
  `owner_email`         VARCHAR(255)  DEFAULT NULL,
  `purpose`             TEXT          DEFAULT NULL,
  `recovery_path`       TEXT          DEFAULT NULL,
  `vault_reference`     VARCHAR(500)  DEFAULT NULL,
  `renewal_date`        DATE          DEFAULT NULL,
  `expiry_alert_days`   INT(11)       NOT NULL DEFAULT 30,
  `notes`               TEXT          DEFAULT NULL,
  `is_active`           TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by_id`       INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_category` (`category`),
  KEY `owner_user_id`          (`owner_user_id`),
  CONSTRAINT `inventory_ibfk_owner`   FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
