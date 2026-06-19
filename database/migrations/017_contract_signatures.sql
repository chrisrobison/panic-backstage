-- Migration 017: digital signature support for contracts.
-- Extends contracts with provider tracking + final PDF fields.
-- Adds contract_signers (per-signer magic-link flow) and
-- contract_audit_log (immutable lifecycle log).
-- All new columns are nullable / have defaults so existing contracts
-- remain fully functional without any backfill.

-- ─── 1. Extend contracts.status ───────────────────────────────────────────────
-- Preserve all seven existing values; new workflow states appended at the end.
ALTER TABLE `contracts`
  MODIFY COLUMN `status` ENUM(
    'draft','needs_review','approved','sent','signed','canceled','superseded',
    'ready_to_send','viewed','partially_signed','signed_by_client',
    'countersigned','fully_executed','voided','declined','expired','error'
  ) NOT NULL DEFAULT 'draft';

-- ─── 2. Add signature-provider + final-PDF tracking columns ──────────────────
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `provider`             VARCHAR(40)  NOT NULL DEFAULT 'internal'
      COMMENT 'internal|mock|dropbox_sign|docusign'
      AFTER `status`,
  ADD COLUMN IF NOT EXISTS `provider_envelope_id` VARCHAR(255) DEFAULT NULL
      AFTER `provider`,
  ADD COLUMN IF NOT EXISTS `provider_status`      VARCHAR(80)  DEFAULT NULL
      AFTER `provider_envelope_id`,
  ADD COLUMN IF NOT EXISTS `preview_pdf_path`     VARCHAR(500) DEFAULT NULL
      AFTER `provider_status`,
  ADD COLUMN IF NOT EXISTS `final_pdf_path`       VARCHAR(500) DEFAULT NULL
      AFTER `preview_pdf_path`,
  ADD COLUMN IF NOT EXISTS `final_pdf_sha256`     VARCHAR(64)  DEFAULT NULL
      AFTER `final_pdf_path`,
  ADD COLUMN IF NOT EXISTS `fully_executed_at`    TIMESTAMP    NULL DEFAULT NULL
      AFTER `signed_at`,
  ADD COLUMN IF NOT EXISTS `voided_at`            TIMESTAMP    NULL DEFAULT NULL
      AFTER `fully_executed_at`;

-- ─── 3. Contract signers ──────────────────────────────────────────────────────
-- One row per person required to sign a specific contract.
-- signing_token_hash: sha256 of the one-time magic-link token (token itself never stored).
CREATE TABLE IF NOT EXISTS `contract_signers` (
  `id`                    INT(11)       NOT NULL AUTO_INCREMENT,
  `contract_id`           INT(11)       NOT NULL,
  `role`                  VARCHAR(40)   NOT NULL DEFAULT 'renter'
      COMMENT 'renter|promoter|artist_rep|venue|guarantor',
  `name`                  VARCHAR(255)  NOT NULL DEFAULT '',
  `email`                 VARCHAR(320)  NOT NULL DEFAULT '',
  `phone`                 VARCHAR(40)   DEFAULT NULL,
  `company`               VARCHAR(255)  DEFAULT NULL,
  `title`                 VARCHAR(120)  DEFAULT NULL,
  `status`                ENUM('pending','sent','viewed','signed','declined','voided','expired')
      NOT NULL DEFAULT 'pending',
  `signing_token_hash`    VARCHAR(64)   DEFAULT NULL
      COMMENT 'sha256(raw_token) — raw token is never persisted',
  `token_expires_at`      DATETIME      DEFAULT NULL,
  `viewed_at`             TIMESTAMP     NULL DEFAULT NULL,
  `signed_at`             TIMESTAMP     NULL DEFAULT NULL,
  `declined_at`           TIMESTAMP     NULL DEFAULT NULL,
  `ip_address`            VARCHAR(45)   DEFAULT NULL,
  `user_agent`            VARCHAR(512)  DEFAULT NULL,
  `signature_text`        VARCHAR(255)  DEFAULT NULL
      COMMENT 'typed name used as electronic signature',
  `signature_image_path`  VARCHAR(500)  DEFAULT NULL
      COMMENT 'drawn-signature PNG path relative to project root (optional)',
  `provider_recipient_id` VARCHAR(255)  DEFAULT NULL
      COMMENT 'signer ID assigned by external provider',
  `created_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signers_contract`   (`contract_id`),
  KEY `idx_signers_token_hash` (`signing_token_hash`),
  KEY `idx_signers_status`     (`status`),
  CONSTRAINT `contract_signers_ibfk_1`
    FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4. Contract audit log ────────────────────────────────────────────────────
-- Immutable append-only log of all contract lifecycle events.
-- No UPDATE or DELETE is ever issued against this table from application code.
CREATE TABLE IF NOT EXISTS `contract_audit_log` (
  `id`            BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `contract_id`   INT(11)      NOT NULL,
  `signer_id`     INT(11)      DEFAULT NULL,
  `action`        VARCHAR(80)  NOT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `user_agent`    VARCHAR(512) DEFAULT NULL,
  `metadata_json` LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
      CHECK (json_valid(`metadata_json`)),
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_contract` (`contract_id`),
  KEY `idx_audit_signer`   (`signer_id`),
  KEY `idx_audit_action`   (`action`),
  KEY `idx_audit_created`  (`created_at`),
  CONSTRAINT `contract_audit_log_ibfk_1`
    FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
