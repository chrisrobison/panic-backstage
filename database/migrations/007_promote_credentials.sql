-- ── 007_promote_credentials ──────────────────────────────────────────────────
-- Per-venue credentials for Promote platform integrations.
-- Stores API keys, OAuth tokens, and JSON config (page IDs, org IDs, etc.)
-- for each destination platform, scoped to a venue.
--
-- status values:
--   connected   — credentials are present and believed valid
--   needs_auth  — no credentials yet, or token expired
--   error       — last attempt failed; error_message has details
-- ─────────────────────────────────────────────────────────────────────────────

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `promote_credentials` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,
  `venue_id`         int(11)      NOT NULL,
  `destination_key`  varchar(80)  NOT NULL,
  -- access_token holds the primary secret (API key, OAuth access token, etc.)
  `access_token`     text         DEFAULT NULL,
  -- refresh_token for OAuth flows that support token refresh
  `refresh_token`    text         DEFAULT NULL,
  `token_expires_at` datetime     DEFAULT NULL,
  -- config stores platform-specific settings as JSON
  -- e.g. Eventbrite: {"org_id":"123","eb_venue_id":"456"}
  --      Facebook:   {"page_id":"789","page_name":"Mabuhay Gardens"}
  --      Email:      {"provider":"mailchimp","list_id":"abc","from_name":"Mabuhay"}
  `config`           longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                                  DEFAULT NULL CHECK (json_valid(`config`)),
  `status`           enum('connected','needs_auth','error')
                                  NOT NULL DEFAULT 'needs_auth',
  `error_message`    text         DEFAULT NULL,
  `connected_at`     datetime     DEFAULT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`       timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_venue_destination` (`venue_id`, `destination_key`),
  KEY `venue_id` (`venue_id`),
  CONSTRAINT `promote_credentials_ibfk_1`
    FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed needs_auth rows for every destination that can be connected,
-- using venue_id=1 (primary venue) as the default.
-- Safe to re-run: ON DUPLICATE KEY ignores existing rows.
INSERT INTO `promote_credentials` (`venue_id`, `destination_key`, `status`)
SELECT 1, destination_key, 'needs_auth'
FROM promote_destinations
WHERE destination_group IN ('direct_post', 'event_platform', 'email')
  AND status != 'disabled'
ON DUPLICATE KEY UPDATE `venue_id` = VALUES(`venue_id`);

COMMIT;
