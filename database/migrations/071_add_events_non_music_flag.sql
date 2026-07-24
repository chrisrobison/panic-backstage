-- Flag an event as a non-music event (workshop, comedy, etc.) so the admin
-- Event Details form and the public event page can adjust their time-field
-- wording/visibility accordingly: "Show" becomes "Start" and the Doors /
-- Load-In-Tech fields are hidden (their underlying columns are left alone —
-- see src/Events.php and public/assets/event-workspace.js).
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `is_non_music` tinyint(1) NOT NULL DEFAULT 0 AFTER `load_in_time`;
