-- Migration 018: Staff hire date
-- Run once against panic_backstage, after migration 017.
--
-- Adds an optional hire/start date to the staff roster. Mirrors the new
-- "Hire Date" column in the 'Staff Contact' Google Sheet tab; the two-way
-- staff sync (scripts/sync-staff.php) keeps them aligned.

ALTER TABLE staff_members
  ADD COLUMN IF NOT EXISTS hire_date DATE NULL DEFAULT NULL AFTER hourly_rate;
