-- Migration 009 — Add email_adhoc destination
-- Adds the "Ad-hoc Email Recipients" destination that was listed in the original
-- PROMOTE-PLAN.md but not included in the initial seed.
-- This destination is manual_submission: staff copy the generated body and
-- send manually to custom recipient addresses.

INSERT INTO promote_destinations (destination_key, destination_group, label, status) VALUES
('email_adhoc', 'email', 'Ad-hoc Email Recipients', 'manual_submission')
ON DUPLICATE KEY UPDATE label = VALUES(label);
