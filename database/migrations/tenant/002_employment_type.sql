-- Migration 002 — add employment_type to staff_members
-- Tracks whether a crew member is a W-2 employee or a 1099 contractor.
-- Defaults to 'employee' so existing rows stay valid without a backfill.

ALTER TABLE `staff_members`
  ADD COLUMN `employment_type` ENUM('employee','contractor') NOT NULL DEFAULT 'employee'
  AFTER `default_role`;
