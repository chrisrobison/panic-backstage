-- Closeout/Settlement redesign: track *who* a cost is owed to and let a
-- payment reference the specific cost it pays down, so the Closeout tab can
-- show a "who's still owed money" balance per payee instead of forcing staff
-- to eyeball the separate Costs/Payments lists and do the subtraction
-- themselves. See docs discussion: Settlement + Closeout + Report tabs all
-- touch money with no shared "is this paid?" concept — this is step one
-- (the "medium lift") toward fixing that: additive columns only, no
-- behavior changes to existing rows (all three are nullable/optional).
--
-- payee_name/payee_type apply to cost entries (who the venue owes) and,
-- optionally, to payment entries recorded without a specific paid_entry_id
-- link (a payee-level payment, still enough to net against that payee's
-- committed costs). paid_entry_id is the precise link: a payment entry can
-- point at the exact cost entry it's paying down.
ALTER TABLE `event_ledger_entries`
  ADD COLUMN IF NOT EXISTS `payee_name` varchar(255) DEFAULT NULL
    COMMENT 'Who a cost is owed to / a payment goes to — free text (artist, vendor, promoter, staff name). Not a FK: payees here are rarely Panic user accounts.'
    AFTER `description`,
  ADD COLUMN IF NOT EXISTS `payee_type` enum('artist','promoter','vendor','staff','client','other') DEFAULT NULL
    AFTER `payee_name`,
  ADD COLUMN IF NOT EXISTS `paid_entry_id` int(11) DEFAULT NULL
    COMMENT 'For a payment-type entry: the specific cost-type entry (same event) this payment pays down. NULL for a payee-level payment not linked to one exact cost line.'
    AFTER `payee_type`;

ALTER TABLE `event_ledger_entries`
  ADD KEY IF NOT EXISTS `idx_ledger_payee` (`event_id`, `payee_name`),
  ADD KEY IF NOT EXISTS `idx_ledger_paid_entry` (`paid_entry_id`);

-- Only add the FK if it doesn't already exist (MySQL has no
-- "ADD CONSTRAINT IF NOT EXISTS"; guard via information_schema so a re-run
-- after a partial failure is harmless, per this folder's README).
-- ON DELETE SET NULL: ledger entries are never hard-deleted today (voiding
-- is an UPDATE, see Ledger::voidEntry()), but if that ever changes, losing
-- the cost row should just detach the payment's link rather than block or
-- cascade-delete a real money-movement record.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'event_ledger_entries'
     AND CONSTRAINT_NAME = 'fk_ledger_paid_entry'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `event_ledger_entries` ADD CONSTRAINT `fk_ledger_paid_entry` FOREIGN KEY (`paid_entry_id`) REFERENCES `event_ledger_entries` (`id`) ON DELETE SET NULL',
  'SET @dummy = 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
