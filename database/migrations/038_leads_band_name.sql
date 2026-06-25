-- Migration 038: add band_name to leads table
--
-- Adds a free-text field for the artist(s) / band(s) associated with the lead.
-- projected_attendance already exists from migration 022.

SET NAMES utf8mb4;

ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `band_name` VARCHAR(500) DEFAULT NULL AFTER `event_type`;
