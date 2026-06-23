-- Migration 011 (single-tenant): event payments and invoices.
--
-- Adds structured payment/invoice tracking to events, replacing the old
-- events.deposit_amount scalar with a full payment lifecycle:
--
--   event_payments — individual payment records (deposit, balance, refund, etc.)
--
-- The deposit_status column on events (added in migration 010) is updated
-- automatically based on event_payments rows by the Payments endpoint.
-- Legacy events (no payment rows) are unaffected; deposit_status defaults
-- to 'not_required'.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. event_payments ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `event_payments` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)       NOT NULL,
  `payment_type`        ENUM('deposit','balance_payment','refund','credit','adjustment',
                             'promoter_payment','client_payment','other')
                          NOT NULL DEFAULT 'other',
  `direction`           ENUM('received','paid_out') NOT NULL DEFAULT 'received',
  `amount`              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`            CHAR(3)       NOT NULL DEFAULT 'USD',
  `status`              ENUM('pending','received','failed','refunded','voided')
                          NOT NULL DEFAULT 'pending',
  `method`              ENUM('cash','check','ach','wire','credit_card','stripe','square',
                             'venmo','zelle','other') DEFAULT NULL,
  `processor_reference` VARCHAR(255)  DEFAULT NULL,
  `invoice_reference`   VARCHAR(255)  DEFAULT NULL,
  `due_date`            DATE          DEFAULT NULL,
  `received_at`         DATETIME      DEFAULT NULL,
  `notes`               TEXT          DEFAULT NULL,
  `created_by_id`       INT(11)       DEFAULT NULL,
  `updated_by_id`       INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_event`  (`event_id`),
  KEY `idx_payments_type`   (`payment_type`),
  KEY `idx_payments_status` (`status`),
  KEY `created_by_id`       (`created_by_id`),
  CONSTRAINT `event_payments_ibfk_event`   FOREIGN KEY (`event_id`)     REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_payments_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. event_payment_audit ────────────────────────────────────────────────────
-- Immutable audit log for every payment change (amount, status, method, etc.)

CREATE TABLE IF NOT EXISTS `event_payment_audit` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `payment_id`    INT(11)   NOT NULL,
  `event_id`      INT(11)   NOT NULL,
  `user_id`       INT(11)   DEFAULT NULL,
  `action`        VARCHAR(80) NOT NULL,
  `old_value_json` LONGTEXT  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                    CHECK (json_valid(`old_value_json`)),
  `new_value_json` LONGTEXT  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                    CHECK (json_valid(`new_value_json`)),
  `note`          TEXT       DEFAULT NULL,
  `created_at`    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  KEY `event_id`   (`event_id`),
  CONSTRAINT `pay_audit_ibfk_payment` FOREIGN KEY (`payment_id`) REFERENCES `event_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
