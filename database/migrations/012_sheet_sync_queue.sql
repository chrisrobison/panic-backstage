-- 012_sheet_sync_queue.sql
--
-- Outbox for two-way Google Sheet sync (DB -> sheet write-back).
--
-- When an event is edited in the app we enqueue its id here and attempt an
-- immediate push to the sheet. If the push fails (Google API hiccup, token
-- problem, etc.) the row stays pending and the existing 5-minute cron sweeps
-- it up and retries. One pending row per event (UNIQUE) — the pusher always
-- reads the event's *current* state, so collapsing repeated edits into a
-- single pending row is correct and idempotent.

CREATE TABLE IF NOT EXISTS sheet_sync_queue (
    id          INT NOT NULL AUTO_INCREMENT,
    event_id    INT NOT NULL,
    status      ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
    attempts    INT NOT NULL DEFAULT 0,
    last_error  TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pushed_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_event (event_id),
    KEY idx_status (status),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
