-- 054_replace_closeout_checklist_fields.sql
--
-- event_closeout_state's checklist columns (actual_hours_confirmed,
-- bar_revenue_reconciled, ticket_revenue_reconciled, vendor_costs_entered,
-- incidents_reviewed, final_invoice_prepared, payment_obligations_recorded)
-- were never wired to any UI — the closeout & billing panel shipped in
-- 4cce14a with a different, user-facing checklist (Contract Signed, Deposit
-- Received, Vendors Confirmed, Staffing Confirmed, Bar Closed, Cash
-- Reconciled, All Invoices Collected) and the two were never reconciled.
-- The table has no live data for the old columns (nothing ever wrote to
-- them outside Ledger::finalize()'s own force-finalize path), so this swaps
-- them for the columns the UI actually checks off.

ALTER TABLE event_closeout_state
  DROP COLUMN IF EXISTS actual_hours_confirmed,
  DROP COLUMN IF EXISTS bar_revenue_reconciled,
  DROP COLUMN IF EXISTS ticket_revenue_reconciled,
  DROP COLUMN IF EXISTS vendor_costs_entered,
  DROP COLUMN IF EXISTS incidents_reviewed,
  DROP COLUMN IF EXISTS final_invoice_prepared,
  DROP COLUMN IF EXISTS payment_obligations_recorded,
  ADD COLUMN IF NOT EXISTS contract_signed        TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS deposit_received        TINYINT(1) NOT NULL DEFAULT 0 AFTER contract_signed,
  ADD COLUMN IF NOT EXISTS vendors_confirmed       TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_received,
  ADD COLUMN IF NOT EXISTS staffing_confirmed      TINYINT(1) NOT NULL DEFAULT 0 AFTER vendors_confirmed,
  ADD COLUMN IF NOT EXISTS bar_closed              TINYINT(1) NOT NULL DEFAULT 0 AFTER staffing_confirmed,
  ADD COLUMN IF NOT EXISTS cash_reconciled         TINYINT(1) NOT NULL DEFAULT 0 AFTER bar_closed,
  ADD COLUMN IF NOT EXISTS all_invoices_collected  TINYINT(1) NOT NULL DEFAULT 0 AFTER cash_reconciled;
