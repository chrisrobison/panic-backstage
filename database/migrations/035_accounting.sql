-- ── 035: Accounting integration framework ────────────────────────────────────
-- Chart of accounts mapping, sync log, and provider settings columns.
-- Supports QuickBooks Online (qbo) and Xero as future sync targets.
-- The sync stubs are wired; credentials are configured via .env + promote_settings.

SET foreign_key_checks = 0;

-- ── 1. accounting_coa_map ─────────────────────────────────────────────────────
-- Maps event_ledger_entries.category values to account codes in QBO or Xero.

CREATE TABLE IF NOT EXISTS `accounting_coa_map` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `ledger_category` VARCHAR(60)  NOT NULL COMMENT 'event_ledger_entries.category value',
  `provider`        ENUM('qbo','xero','none') NOT NULL DEFAULT 'none',
  `account_code`    VARCHAR(60)  DEFAULT NULL COMMENT 'e.g. "4000" in QBO or "200" in Xero',
  `account_name`    VARCHAR(255) DEFAULT NULL,
  `line_type`       ENUM('revenue','cost','payment','receivable') DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_provider` (`ledger_category`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. accounting_sync_log ────────────────────────────────────────────────────
-- Tracks every sync attempt (pending → synced / failed / skipped).

CREATE TABLE IF NOT EXISTS `accounting_sync_log` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `event_id`     INT(11)      NOT NULL,
  `provider`     ENUM('qbo','xero') NOT NULL,
  `status`       ENUM('pending','synced','failed','skipped') NOT NULL DEFAULT 'pending',
  `external_id`  VARCHAR(255) DEFAULT NULL COMMENT 'Journal entry ID in QBO/Xero',
  `payload_json` LONGTEXT     DEFAULT NULL,
  `error`        TEXT         DEFAULT NULL,
  `synced_at`    DATETIME     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. promote_settings accounting columns ────────────────────────────────────
-- Provider selection and sync toggle; credentials live in .env.

ALTER TABLE `promote_settings`
  ADD COLUMN IF NOT EXISTS `accounting_provider`      ENUM('none','qbo','xero') NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS `accounting_sync_enabled`  TINYINT(1)                NOT NULL DEFAULT 0;

SET foreign_key_checks = 1;
