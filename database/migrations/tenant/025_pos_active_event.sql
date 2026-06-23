-- Migration 025 (tenant): add active_event_id override to pos_location_map

ALTER TABLE `pos_location_map`
  ADD COLUMN IF NOT EXISTS `active_event_id`     INT(11)  DEFAULT NULL
    COMMENT 'Explicit event override — set via "Set as POS Event" in the workspace' AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `active_event_set_at` DATETIME DEFAULT NULL AFTER `active_event_id`;
