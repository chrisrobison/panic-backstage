-- =============================================================
-- Migration 005: Booking workflow improvements
--
-- 1. Add 'booked' status (post-contract, pre-marketing step)
--    between 'confirmed' (Intake Complete) and 'needs_assets'.
-- 2. Rename venue display names per new labelling scheme.
-- 3. Add producer and booker contact fields to events.
-- =============================================================

START TRANSACTION;

-- ── 1. New status value ────────────────────────────────────────
ALTER TABLE events MODIFY COLUMN status ENUM(
  'empty','proposed','hold','confirmed','booked',
  'needs_assets','ready_to_announce','published','advanced',
  'completed','settled','canceled'
) NOT NULL DEFAULT 'proposed';

-- ── 2. Venue display names ─────────────────────────────────────
UPDATE venues SET name = 'Upstairs Mabuhay Gardens'         WHERE slug = 'mabuhay-upstairs';
UPDATE venues SET name = 'Downstairs Mabuhay Gardens (21+)' WHERE slug = 'mabuhay-gardens';

-- ── 3. Producer / booker contact columns ──────────────────────
ALTER TABLE events
  ADD COLUMN promoter_email VARCHAR(255) NULL AFTER promoter_name,
  ADD COLUMN promoter_phone VARCHAR(50)  NULL AFTER promoter_email,
  ADD COLUMN booker_name    VARCHAR(255) NULL AFTER promoter_phone,
  ADD COLUMN booker_email   VARCHAR(255) NULL AFTER booker_name,
  ADD COLUMN booker_phone   VARCHAR(50)  NULL AFTER booker_email;

COMMIT;
