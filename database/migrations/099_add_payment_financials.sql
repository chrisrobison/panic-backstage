-- Issue #27: persist the processor's actual fee and tax figures so event
-- settlements use provider-reported amounts rather than estimates.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS processor_fee_cents INT NULL AFTER amount_cents,
  ADD COLUMN IF NOT EXISTS tax_cents INT NULL AFTER processor_fee_cents,
  ADD COLUMN IF NOT EXISTS financials_status ENUM('pending','partial','reported','unavailable') NOT NULL DEFAULT 'pending' AFTER tax_cents,
  ADD COLUMN IF NOT EXISTS financials_updated_at DATETIME NULL AFTER financials_status;

ALTER TABLE event_payments
  ADD COLUMN IF NOT EXISTS processor_fee_cents INT NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS tax_cents INT NULL AFTER processor_fee_cents,
  ADD COLUMN IF NOT EXISTS financials_status ENUM('pending','partial','reported','unavailable') NOT NULL DEFAULT 'pending' AFTER tax_cents,
  ADD COLUMN IF NOT EXISTS financials_updated_at DATETIME NULL AFTER financials_status;

ALTER TABLE event_ledger_entries
  ADD UNIQUE KEY IF NOT EXISTS uq_ledger_provider_financial
    (event_id, source, source_ref_str, category);
