-- Migration 042 (single-tenant): add phone and website_url to venues.
--
-- These fields are surfaced in the Admin › Venue tab so venue admins can fill
-- in contact details that appear on contracts, emails, and public event pages.
ALTER TABLE `venues`
  ADD COLUMN IF NOT EXISTS `phone`       VARCHAR(40)  DEFAULT NULL COMMENT 'Main venue phone number' AFTER `state`,
  ADD COLUMN IF NOT EXISTS `website_url` VARCHAR(500) DEFAULT NULL COMMENT 'Public venue website'    AFTER `phone`;
