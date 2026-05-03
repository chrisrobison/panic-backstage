-- Migration 001: JWT/passwordless auth
-- Run once against panic_backstage

-- Make password_hash optional (new accounts will never have one)
ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL;

-- One-time tokens for magic-link login (15-minute TTL)
CREATE TABLE IF NOT EXISTS magic_link_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mlt_hash  (token_hash),
    INDEX idx_mlt_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-lived refresh tokens (60-day TTL, rotated on each use)
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rt_hash (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
