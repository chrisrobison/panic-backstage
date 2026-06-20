-- Migration 018 (single-tenant): per-user email notification preferences.
-- Mirrors tenant migration 006. Lets each user opt out of categories of system
-- notification emails from the Preferences page. All default to 1 (opted-in) so
-- existing users keep their current behaviour with no backfill. Transactional /
-- security mail (magic-link login, email confirmation, access-approved) is NOT
-- governed by these flags and always sends.
ALTER TABLE `users`
  ADD COLUMN `notify_event_updates`   TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'Receive event status-change + private-event-inquiry emails'
      AFTER `events_sort`,
  ADD COLUMN `notify_contracts`       TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'Receive contract sent/signed/voided notification emails'
      AFTER `notify_event_updates`,
  ADD COLUMN `notify_access_requests` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'Receive new-access-request notification emails'
      AFTER `notify_contracts`;
