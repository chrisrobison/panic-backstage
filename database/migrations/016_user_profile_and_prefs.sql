-- Migration 016: Self-service profile + UI preferences
-- Run once against panic_backstage, after migration 015
--
-- Adds a phone number for self-service profile editing and three UI
-- preference columns surfaced on the new Preferences page. Mirrors the
-- "columns on users" convention established by 007_credential_prompt_pref.sql.

-- Phone number (self-service profile, optional)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS phone VARCHAR(64) NULL DEFAULT NULL AFTER email;

-- Route key the app loads when no hash is present (e.g. 'dashboard', 'calendar').
-- NULL = fall back to dashboard.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS default_landing VARCHAR(32) NULL DEFAULT NULL;

-- Whether the desktop sidebar starts collapsed (icon-only rail).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS nav_collapsed TINYINT(1) NOT NULL DEFAULT 0;

-- Default sort direction for the Events list ('asc' = oldest first, 'desc' = newest first).
-- NULL = use the app default.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS events_sort VARCHAR(8) NULL DEFAULT NULL;
