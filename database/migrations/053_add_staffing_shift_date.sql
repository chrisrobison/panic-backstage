-- 053_add_staffing_shift_date.sql
--
-- event_staffing had no date of its own — call_time/end_time are plain TIME
-- columns tied only to event_id, so a multi-day event (events.date/end_date)
-- had no way to say which day a shift belonged to. shift_date makes each
-- shift day-scoped; existing rows backfill to the event's start date so
-- current single-day events are unaffected.

ALTER TABLE event_staffing
  ADD COLUMN IF NOT EXISTS shift_date DATE DEFAULT NULL AFTER event_id;

UPDATE event_staffing es
  JOIN events e ON e.id = es.event_id
  SET es.shift_date = e.date
  WHERE es.shift_date IS NULL;
