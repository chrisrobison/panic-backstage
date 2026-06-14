-- =============================================================
-- Migration 011: Add Twitter/X, Threads, Bluesky, Dice.fm,
--                and Resident Advisor promote destinations.
--
-- Twitter/X, Threads, Bluesky → direct_post (needs_auth until creds saved)
-- Dice.fm, Resident Advisor   → event_platform (manual_submission)
--
-- Safe to re-run (ON DUPLICATE KEY UPDATE).
-- =============================================================

INSERT INTO `promote_destinations` (`destination_key`, `destination_group`, `label`, `status`) VALUES
('twitter',          'direct_post',    'Twitter / X',      'needs_auth'),
('threads',          'direct_post',    'Threads',          'needs_auth'),
('bluesky',          'direct_post',    'Bluesky',          'needs_auth'),
('dice',             'event_platform', 'Dice.fm',          'manual_submission'),
('resident_advisor', 'event_platform', 'Resident Advisor', 'manual_submission')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `destination_group` = VALUES(`destination_group`);
