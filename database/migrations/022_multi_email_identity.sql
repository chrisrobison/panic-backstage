-- 022_multi_email_identity.sql
-- Secondary emails live in a JSON array on users (per product decision). Only
-- entries with a non-null verified_at may authenticate. A UNIQUE multi-valued
-- index is a DB backstop so the same email can't sit in two users' arrays;
-- application code additionally guards against collisions with any users.email.
ALTER TABLE users ADD COLUMN alt_emails JSON NULL;
-- MySQL 8.0.17+ multi-valued UNIQUE index over the array's email members.
ALTER TABLE users ADD UNIQUE INDEX uq_users_alt_emails
  ( (CAST(alt_emails->'$[*].email' AS CHAR(255) ARRAY)) );

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
