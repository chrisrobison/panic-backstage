-- 052_add_rate_limits.sql
--
-- Backing store for RateLimiter (src/RateLimiter.php), a fixed-window
-- request counter. First consumers: AuthEndpoint's login and magic-link
-- request actions, which previously had no throttle at all — unlimited
-- password guessing and mailbox spam were both possible.

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket             VARCHAR(191) NOT NULL,
  count              INT UNSIGNED NOT NULL DEFAULT 1,
  window_started_at  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
