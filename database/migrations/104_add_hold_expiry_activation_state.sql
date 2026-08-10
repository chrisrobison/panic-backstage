-- Persist the one-time baseline activation of automatic Hold expiry. Keeping
-- this in the DB makes scheduled activation safe across deploys/restarts and
-- prevents the first live sweep from mass-canceling pre-existing Holds.

ALTER TABLE `app_settings`
  ADD COLUMN IF NOT EXISTS `hold_expiry_activated_at` datetime DEFAULT NULL AFTER `logo_url`;
