-- Migration 031: add resolution tracking to execution records
ALTER TABLE `event_execution_records`
  ADD COLUMN IF NOT EXISTS `resolved_at`        DATETIME  DEFAULT NULL AFTER `is_restricted`,
  ADD COLUMN IF NOT EXISTS `resolved_by_id`     INT(11)   DEFAULT NULL AFTER `resolved_at`,
  ADD COLUMN IF NOT EXISTS `resolution_notes`   TEXT      DEFAULT NULL AFTER `resolved_by_id`;
