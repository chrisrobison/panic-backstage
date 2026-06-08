-- 023_sheet_import_links.sql
--
-- Tracks events that were CREATED from a new Google Sheet row so their app id
-- can be written back into the sheet's hidden "App ID" column (Z) precisely and
-- reliably — without re-guessing which row an event came from via slug.
--
-- How it fits the inbound sync:
--   1. generate-import-sql.py inserts a brand-new (blank-App-ID) sheet row as a
--      local event and, in the same transaction, records (event_id, sheet_row)
--      here with linked = 0.
--   2. app-id-sync.php `link-imports` reads the linked = 0 rows, writes each
--      event id into column Z of its exact sheet row, and flips linked = 1 once
--      the value is confirmed present in the sheet.
--   3. While a link is still linked = 0, the generator reuses the existing event
--      for that row instead of inserting again — so a failed/retried write-back
--      can never spawn a duplicate. This is what makes "blank App ID = create a
--      new event" a safe, drift-free rule.
--
-- title_snap / date_snap are the sheet title+date captured at creation time, used
-- by the write-back step to verify the captured row still matches (and to relocate
-- it if rows shifted) before writing column Z.
--
-- No FK to events on purpose (mirrors event_sheet_shadow, migration 013): this is
-- an internal bookkeeping cache; a stale row pointing at a deleted event is
-- harmless (it simply never confirms and can be pruned).

CREATE TABLE IF NOT EXISTS sheet_import_links (
    event_id     INT          NOT NULL PRIMARY KEY,
    sheet_row    INT          NOT NULL,
    title_snap   VARCHAR(200) NOT NULL DEFAULT '',
    date_snap    DATE         NULL,
    linked       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP    NULL,
    KEY idx_unlinked (linked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
