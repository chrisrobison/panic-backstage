-- Migration 009 (tenant): onboarding checklist dismiss flag.
--
-- Adds a per-user flag so venue_admins can permanently dismiss the
-- getting-started checklist once they've completed setup.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `onboarding_dismissed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hide_credential_setup_prompt`;
