-- 021_ticket_scans_fk.sql
-- Fix: ticket_scans was created in 020 without foreign keys, so deleting an
-- event left orphaned audit rows (the ON DELETE CASCADE on sibling tables did
-- not reach ticket_scans). Add the missing constraints to match the rest of the
-- ticketing schema.
--   - event_id  -> ON DELETE CASCADE  (audit rows go with their event)
--   - ticket_id -> ON DELETE SET NULL (preserve the audit trail if a ticket row
--                  is removed, just clear the dangling reference)
-- Safe to apply: the column types/indexes already exist and ticket_scans is
-- empty on first deploy.
ALTER TABLE ticket_scans
  ADD CONSTRAINT fk_ticket_scans_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ticket_scans_event  FOREIGN KEY (event_id)  REFERENCES events(id)  ON DELETE CASCADE;
