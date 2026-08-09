-- Firebase Cloud Messaging (FCM HTTP v1) web push — see README "Push
-- notifications (Firebase Cloud Messaging)" and src/Notifications/.
--
-- Two additive pieces:
--
--  1. `push_subscriptions` — one row per (user, browser/device) FCM
--     registration. A user may register several devices; the SAME
--     registration must never produce a second row, so the unique key is on
--     `token_hash` (SHA-256 of the token) rather than the token itself:
--     FCM registration tokens run well past the 191-byte utf8mb4 index limit
--     and are treated as sensitive application data, so they are matched by
--     digest and never logged. `enabled` lets a device be switched off (or
--     auto-disabled after FCM reports it dead) while keeping the row's
--     diagnostics; the API's DELETE removes it outright.
--
--     Registrations live in the CURRENT tenant database like every other
--     table here, so a push can never address a user in another tenant.
--
--  2. Per-user PUSH notification preferences on `users`, deliberately
--     SEPARATE from the existing `notify_*` EMAIL columns (migration 006).
--     Agreeing to email event updates must not silently opt somebody into
--     phone interruptions, so the two sets are chosen independently — see
--     src/NotificationPreferences.php (email) and
--     src/Notifications/PushPreferences.php (push).
--
--     Default 1 matches the email convention and costs nothing: no push is
--     ever delivered until the user explicitly registers a device from
--     Preferences, which is a separate, permission-gated opt-in.

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(1024) NOT NULL
    COMMENT 'FCM registration token. Sensitive: never returned to the client, never logged.',
  `token_hash` char(64) NOT NULL
    COMMENT 'SHA-256 of `token` — the idempotency key for re-registration (the token itself is too long to index).',
  `enabled` tinyint(1) NOT NULL DEFAULT 1
    COMMENT '0 = user turned this device off, or FCM reported the token dead.',
  `device_label` varchar(120) DEFAULT NULL
    COMMENT 'Human label shown in Preferences, e.g. "Chrome on macOS".',
  `platform` varchar(40) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL
    COMMENT 'Last time the browser re-registered this token (refreshed on every enable).',
  `last_success_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL
    COMMENT 'Sanitized FCM error status from the last failed send (never contains the token).',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_subscriptions_token_hash` (`token_hash`),
  KEY `idx_push_subscriptions_user` (`user_id`, `enabled`),
  CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `push_booking_updates` tinyint(1) NOT NULL DEFAULT 1
    COMMENT 'Push: a new booking inquiry needs attention in the Booking Inbox'
    AFTER `notify_access_requests`,
  ADD COLUMN IF NOT EXISTS `push_contracts` tinyint(1) NOT NULL DEFAULT 1
    COMMENT 'Push: a contract was signed or declined'
    AFTER `push_booking_updates`,
  ADD COLUMN IF NOT EXISTS `push_task_assignments` tinyint(1) NOT NULL DEFAULT 1
    COMMENT 'Push: an inquiry or task was assigned to me'
    AFTER `push_contracts`,
  ADD COLUMN IF NOT EXISTS `push_day_of_show` tinyint(1) NOT NULL DEFAULT 1
    COMMENT 'Push: day-of-show schedule changes and blockers (reserved for a later release)'
    AFTER `push_task_assignments`;
