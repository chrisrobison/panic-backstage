-- 058_add_assets_approved_status.sql
--
-- Adds 'assets_approved' to events.status, between 'needs_assets' and
-- 'ready_to_announce'.
--
-- Why: issue #14 — once a Core Collaborator's promo materials (poster,
-- description, ticket link) have been gathered and approved, the event
-- should move to a distinct "Assets Approved" milestone that fans out
-- notifications to the team (add to website / linktree+Instagram / weekly
-- newsletter) rather than being folded into the existing "Needs Assets"
-- or "Ready to Announce" stages.
--
-- MODIFY COLUMN on an ENUM is safe to re-run: applying the same definition
-- twice is a no-op, not an error.
ALTER TABLE events
  MODIFY COLUMN status ENUM(
    'empty','proposed','confirmed','booked','needs_assets','assets_approved',
    'ready_to_announce','published','advanced','completed','settled','canceled'
  ) NOT NULL DEFAULT 'proposed';
