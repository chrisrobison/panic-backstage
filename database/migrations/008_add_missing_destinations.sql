-- =============================================================
-- Migration 008: Add missing editorial & event platform destinations
--
-- Adds SF Chronicle, SF Station, DoTheBay (editorial_submission)
-- and SongKick, JamBase (event_platform) — all manual_submission.
-- Safe to re-run (ON DUPLICATE KEY UPDATE).
-- =============================================================

INSERT INTO `promote_destinations` (`destination_key`, `destination_group`, `label`, `status`) VALUES
('sf_chronicle', 'editorial_submission', 'SF Chronicle', 'manual_submission'),
('sf_station',   'editorial_submission', 'SF Station',   'manual_submission'),
('dothebay',     'editorial_submission', 'DoTheBay',     'manual_submission'),
('songkick',     'event_platform',       'SongKick',     'manual_submission'),
('jambase',      'event_platform',       'JamBase',      'manual_submission')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);
