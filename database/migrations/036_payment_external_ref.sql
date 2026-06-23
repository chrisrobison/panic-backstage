-- ── 036: event_payments additions for Stripe payment links ───────────────────
-- 1. external_ref: stores Stripe/Square reference IDs (e.g. plink_...)
-- 2. Extend status ENUM to include 'invoiced' (link sent, payment not yet received)

ALTER TABLE `event_payments`
  ADD COLUMN IF NOT EXISTS `external_ref` VARCHAR(255) DEFAULT NULL
    COMMENT 'External processor reference, e.g. Stripe payment link ID (plink_...)';

-- Extend the status ENUM to add 'invoiced' between 'pending' and 'received'.
ALTER TABLE `event_payments`
  MODIFY COLUMN `status` ENUM('pending','invoiced','received','failed','refunded','voided')
    NOT NULL DEFAULT 'pending';
