-- Migration 019: Self-service access requests
-- Run once against panic_backstage, after migration 018
--
-- Lets prospective users request an account from the login page. A request is
-- stored as a row in `users` with access_status = 'requested' (no password, no
-- passkey). A venue_admin reviews these on the Admin > Users page and either
-- approves (which sets a role, flips access_status to 'active', and emails a
-- login link) or dismisses (deletes the row).
--
-- Existing users all become 'active' via the column default, so nothing about
-- current sign-in behaviour changes. Eligibility for magic links / password
-- login is gated on access_status = 'active' (see AuthEndpoint).

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS access_status ENUM('active','requested') NOT NULL DEFAULT 'active' AFTER role;

-- Free-text "state your situation" note captured on the request form.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS request_notes TEXT NULL DEFAULT NULL AFTER access_status;
