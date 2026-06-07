-- 022_multi_email_identity.sql
-- Secondary emails live in a JSON array on users (per product decision). Only
-- entries with a non-null verified_at may authenticate.
-- NOTE: this database is MariaDB, which does NOT support MySQL 8 multi-valued
-- indexes (CAST(... AS CHAR ARRAY)). Global email uniqueness is therefore
-- enforced at the application layer (Panic\Identity::emailIsTaken), which checks
-- a candidate against every users.email and every user's alt_emails before an
-- alias is added/verified/promoted. Lookups use JSON_CONTAINS/JSON_EXTRACT,
-- which work on both MariaDB and MySQL 8.
ALTER TABLE users ADD COLUMN alt_emails JSON NULL;

-- One-time tokens to confirm ownership of a newly added alias (hashed, single-use).
CREATE TABLE email_verification_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_email_verif_user (user_id),
  INDEX idx_email_verif_email (email)
);

-- Audit trail for account merges (what was folded into what, and the moved refs).
CREATE TABLE user_merges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  survivor_user_id INT NOT NULL,
  loser_user_id INT NOT NULL,
  loser_email VARCHAR(255) NULL,
  performed_by_user_id INT NULL,
  details JSON NULL,                 -- per-table repoint counts, moved emails, signals
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_merges_survivor (survivor_user_id)
);
