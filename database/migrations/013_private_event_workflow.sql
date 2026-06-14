-- =============================================================
-- Migration 013: Private event workflow — additional columns
--
-- 1. client_org      — company / organization renting the space
-- 2. estimated_guests — expected headcount (distinct from hard-cap capacity)
-- 3. av_requirements  — AV / sound / tech notes from the client
-- 4. catering_notes   — bar / catering / alcohol service notes
--
-- Contact fields (promoter_name/email/phone) are reused for the
-- primary client contact; no schema change needed there.
-- =============================================================

START TRANSACTION;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS client_org       VARCHAR(255) NULL AFTER promoter_phone,
  ADD COLUMN IF NOT EXISTS estimated_guests INT          NULL AFTER capacity,
  ADD COLUMN IF NOT EXISTS av_requirements  TEXT         NULL AFTER description_internal,
  ADD COLUMN IF NOT EXISTS catering_notes   TEXT         NULL AFTER av_requirements;

COMMIT;
