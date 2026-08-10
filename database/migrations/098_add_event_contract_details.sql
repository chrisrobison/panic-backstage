-- Issue #31: capture the event-specific agreement summary before intake can
-- be marked complete. This is separate from generated/uploaded contracts so
-- staff can record the negotiated terms while preparing the paperwork.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS contract_details TEXT NULL AFTER catering_notes;
