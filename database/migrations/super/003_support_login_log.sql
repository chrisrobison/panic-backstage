-- 003_support_login_log.sql
--
-- Basic audit trail + fleet-wide throttle for the support-login fallback
-- (src/SupportLogin.php, wired into AuthEndpoint::login()): every attempt to
-- authenticate against super_admin_users from a tenant's own login form is
-- recorded here, success or failure, so "who logged into which tenant as
-- support, and when" is always answerable after the fact.
--
-- rate_limits duplicates the tenant-scoped table of the same name
-- (../052_add_rate_limits.sql) inside the super registry DB, so that
-- src/RateLimiter.php — which hardcodes the table name "rate_limits" and
-- just takes a Database connection — can be reused unmodified against
-- Connection::super() instead of Connection::tenant(...). It exists because
-- a super-admin credential is valid on every tenant's login form, so
-- throttling only per-tenant (the existing tenant-scoped RateLimiter call in
-- AuthEndpoint::login()) would let an attacker retry 8 times per tenant,
-- then just move to the next tenant domain — effectively unbounded guesses
-- against one shared credential. This bucket is checked in addition to, not
-- instead of, the normal per-tenant limiter.
CREATE TABLE IF NOT EXISTS support_login_log (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  super_admin_id   INT UNSIGNED NULL COMMENT 'NULL when email_used did not match any super admin',
  tenant_id        INT UNSIGNED NOT NULL,
  email_used       VARCHAR(255) NOT NULL,
  ip               VARCHAR(64) NULL,
  success          TINYINT(1) NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_support_login_log_tenant (tenant_id),
  KEY idx_support_login_log_super_admin (super_admin_id),
  KEY idx_support_login_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket             VARCHAR(191) NOT NULL,
  count              INT UNSIGNED NOT NULL DEFAULT 1,
  window_started_at  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
