-- 093_add_gcal_sync_columns.sql
--
-- Link column for the one-way Google Calendar mirror (docs/google-calendar-sync.md).
--
-- `gcal_event_id`  — the Google Calendar event id this row was pushed as. NULL
--                    means "never synced"; the sweep creates it on the next run.
--                    Not unique: a hand-deleted Google event makes the sweep
--                    clear this and re-create, so transient duplicates are
--                    resolved by overwriting, never by a constraint failure.
-- `gcal_synced_at` — when the push last succeeded. The sweep only touches rows
--                    where `gcal_event_id IS NULL OR updated_at > gcal_synced_at`,
--                    so a steady-state nightly run makes ~0 API calls instead of
--                    blindly re-patching every upcoming event.

ALTER TABLE `events`
  ADD COLUMN `gcal_event_id`  VARCHAR(255) DEFAULT NULL AFTER `ticketing_mode`,
  ADD COLUMN `gcal_synced_at` TIMESTAMP    NULL DEFAULT NULL AFTER `gcal_event_id`,
  ADD KEY `idx_events_gcal` (`gcal_event_id`);
