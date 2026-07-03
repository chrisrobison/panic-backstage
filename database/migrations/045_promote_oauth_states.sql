-- Short-lived PKCE/CSRF state for the in-app "Connect X account" OAuth 2.0
-- flow (Settings → Promote → X). A row is created by
-- POST /api/promote/oauth/twitter/start (authenticated) and consumed —
-- deleted — by GET /api/promote/oauth/twitter/callback (public, reached via
-- browser top-level redirect from X, so it carries no JWT and authenticates
-- itself against this table instead). Rows older than the flow's TTL are
-- pruned opportunistically on each new /start call.
CREATE TABLE IF NOT EXISTS `promote_oauth_states` (
  `state` varchar(64) NOT NULL,
  `venue_id` int(11) NOT NULL,
  `destination_key` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `code_verifier` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`state`),
  KEY `idx_promote_oauth_states_created_at` (`created_at`),
  CONSTRAINT `promote_oauth_states_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
