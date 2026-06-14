-- =============================================================
-- Migration 012: Hold workflow redesign + schema additions
--
-- 1. Rename the "In Negotiations" (hold) stage:
--    - All events currently at status = 'hold' are moved to
--      'proposed' (the new canonical "Hold" stage).
--    - 'hold' is then dropped from the ENUM.
--    - In the UI, 'proposed' now displays as "Hold".
-- 2. Add load_in_time column for Load-In / Tech call time.
-- 3. Add venue_contract_url column for the venue's signed-copy
--    URL (dual-signature tracking — enforcement TBD, OQ-8).
-- =============================================================

START TRANSACTION;

-- ── 1. Migrate In-Negotiations events to Hold (proposed) ───────
UPDATE events
   SET status = 'proposed'
 WHERE status = 'hold';

-- ── 2. Drop 'hold' from the status ENUM ───────────────────────
--    Order is kept identical to the previous ENUM minus 'hold'.
ALTER TABLE events MODIFY COLUMN status ENUM(
  'empty','proposed','confirmed','booked',
  'needs_assets','ready_to_announce','published','advanced',
  'completed','settled','canceled'
) NOT NULL DEFAULT 'proposed';

-- ── 3. Load-In / Tech call time ───────────────────────────────
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS load_in_time TIME NULL AFTER end_time;

-- ── 4. Venue signed-contract URL (dual-signature) ─────────────
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS venue_contract_url VARCHAR(500) NULL AFTER contract_url;

COMMIT;
