-- Migration 003: Passkey (WebAuthn) and password authentication
-- Run once against panic_backstage

-- Ensure password_hash is nullable (already done in 001, but safe to repeat)
ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL;

-- WebAuthn registered credentials (passkeys)
CREATE TABLE IF NOT EXISTS passkeys (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    credential_id  VARCHAR(1024) NOT NULL UNIQUE,   -- base64url-encoded credential ID
    public_key_pem TEXT NOT NULL,                    -- PEM-encoded SubjectPublicKeyInfo
    sign_count     BIGINT NOT NULL DEFAULT 0,
    transports     VARCHAR(255) NULL,                -- JSON array, e.g. ["internal"]
    name           VARCHAR(255) NOT NULL DEFAULT 'Passkey',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at   TIMESTAMP NULL,
    INDEX idx_passkeys_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived WebAuthn challenges (5-minute TTL)
CREATE TABLE IF NOT EXISTS webauthn_challenges (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    challenge  VARCHAR(512) NOT NULL UNIQUE,    -- base64url-encoded challenge
    user_id    INT NULL,                         -- set for registration, NULL for login
    intent     ENUM('register', 'login') NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wc_challenge (challenge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
