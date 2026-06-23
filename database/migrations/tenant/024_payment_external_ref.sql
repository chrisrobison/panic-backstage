-- ── tenant/024: event_payments additions for Stripe payment links ─────────────
-- Mirrors 036_payment_external_ref.sql for tenant databases.

ALTER TABLE `event_payments`
  ADD COLUMN IF NOT EXISTS `external_ref` VARCHAR(255) DEFAULT NULL
    COMMENT 'External processor reference, e.g. Stripe payment link ID (plink_...)';

ALTER TABLE `event_payments`
  MODIFY COLUMN `status` ENUM('pending','invoiced','received','failed','refunded','voided')
    NOT NULL DEFAULT 'pending';
