-- 014_event_code_unique.sql
--
-- external_id now holds the human-facing, sequential event code ("EVT-1" …),
-- repurposed from the old partial EVT-10xx sheet codes. It is the visible id
-- people refer to (the raw events.id stays as the internal/sync link key).
-- Enforce uniqueness so two events can never share a code.
--
-- Safe to run after the EVT-{n} backfill (all values distinct, no NULLs).

ALTER TABLE events DROP INDEX idx_events_external_id;
ALTER TABLE events ADD UNIQUE INDEX idx_events_external_id (external_id);
