-- Add a deposit_amount field to events.
--
-- The MabEvents sheet uses status="Paid Deposit" to track when an artist
-- has put money down, but does not record the actual figure. We collapse
-- "Paid Deposit" + "Booked" into the same DB status (confirmed, displayed
-- as "Booked"), and surface the deposit figure as a separate amount field
-- on the event so finance/settlement can see what's been paid.
--
-- NULL = no deposit recorded.  0.00 is a valid value (deposit waived, etc).
--
-- Safe to re-run: guarded by INFORMATION_SCHEMA check.

USE panic_backstage;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'events'
    AND COLUMN_NAME  = 'deposit_amount'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE events ADD COLUMN deposit_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER ticket_price',
  'SELECT "deposit_amount already exists, skipping" AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
