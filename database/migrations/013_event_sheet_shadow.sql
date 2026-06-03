-- 013_event_sheet_shadow.sql
--
-- Field-level change tracking for the inbound Google Sheet sync.
--
-- Stores the last-synced sheet values per event (keyed by the app event id,
-- i.e. the value in the sheet's hidden "App ID" column). On each inbound sync
-- the importer compares the current sheet value of each field against this
-- shadow: if it changed, the sheet wins and the DB + shadow are updated; if it
-- is unchanged, the app's value is left untouched. This is what lets app edits
-- survive re-syncs unless a sheet operator actually edits the same field.
--
-- No FK to events on purpose: the shadow is an internal cache, and a stale row
-- pointing at a since-deleted event is harmless (it simply never matches).

CREATE TABLE IF NOT EXISTS event_sheet_shadow (
    event_id  INT          NOT NULL PRIMARY KEY,
    raw_json  LONGTEXT     NOT NULL,
    synced_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
