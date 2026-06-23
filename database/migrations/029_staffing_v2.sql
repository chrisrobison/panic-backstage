-- Migration 017 (single-tenant): staffing v2 — source tracking, actual hours, labor cost.
--
-- Extends event_staffing with:
--   source        — generated (from capacity), template, or manual
--   clock_in/out  — actual day-of times
--   actual_hours  — calculated or overridden actual hours worked
--   approved_overtime_hours — approved OT beyond scheduled hours
--
-- The from-capacity endpoint is updated to set source='generated'.
-- Manually created rows default to source='manual'.

SET NAMES utf8mb4;

ALTER TABLE `event_staffing`
  ADD COLUMN IF NOT EXISTS `source`
    ENUM('generated','template','manual') NOT NULL DEFAULT 'manual'
    AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `estimated_hours`   DECIMAL(5,2) DEFAULT NULL AFTER `source`,
  ADD COLUMN IF NOT EXISTS `clock_in`          DATETIME DEFAULT NULL AFTER `estimated_hours`,
  ADD COLUMN IF NOT EXISTS `clock_out`         DATETIME DEFAULT NULL AFTER `clock_in`,
  ADD COLUMN IF NOT EXISTS `actual_hours`      DECIMAL(5,2) DEFAULT NULL AFTER `clock_out`,
  ADD COLUMN IF NOT EXISTS `approved_overtime_hours` DECIMAL(5,2) DEFAULT NULL AFTER `actual_hours`;
