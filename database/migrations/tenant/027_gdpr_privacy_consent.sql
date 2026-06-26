-- Tenant migration 027: record agreement to the privacy policy.
-- Mirrors single-tenant migration 040. Stores when a user accepted the privacy
-- policy and which version, for GDPR accountability (Art. 5(2)).
SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `privacy_policy_accepted_at` DATETIME    DEFAULT NULL
      COMMENT 'When the user agreed to the privacy policy'
      AFTER `alt_emails`,
  ADD COLUMN `privacy_policy_version`     VARCHAR(32) DEFAULT NULL
      COMMENT 'Version of the privacy policy the user agreed to'
      AFTER `privacy_policy_accepted_at`;
