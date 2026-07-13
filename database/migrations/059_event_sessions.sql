-- 059_event_sessions.sql
--
-- Adds event_sessions: per-day time blocks for a single event.
--
-- Why: issue #8. A multi-day event today is one continuous [date, end_date]
-- span with a single doors_time/end_time applied uniformly — correct for a
-- show that runs past midnight into the next day, wrong for something like
-- a two-day workshop where each day has its own distinct time block (e.g.
-- Aug 22 1-5pm, Aug 23 1-4pm). event_sessions is purely additive: an event
-- with no session rows behaves exactly as it does today (continuous range,
-- single time block). An event WITH session rows uses them as the source of
-- truth for per-day display; events.date/end_date are kept in sync as
-- MIN/MAX(session_date) so every existing date-range query (room-conflict
-- check, calendar month view, etc.) keeps working unchanged.
CREATE TABLE IF NOT EXISTS event_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  event_id INT NOT NULL,
  session_date DATE NOT NULL,
  start_time TIME NULL DEFAULT NULL,
  end_time TIME NULL DEFAULT NULL,
  label VARCHAR(120) NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY event_id (event_id),
  CONSTRAINT event_sessions_ibfk_1 FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
