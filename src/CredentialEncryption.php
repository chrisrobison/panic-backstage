<?php
declare(strict_types=1);

namespace Panic;

/**
 * Application-level encryption for third-party credentials.
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305) — available in PHP 7.2+.
 * The master key is read from the CREDENTIAL_ENCRYPTION_KEY environment
 * variable (hex-encoded 32 bytes = 64 hex chars).  It is NEVER stored in
 * the database or returned to browser clients.
 *
 * Wire format stored in the database:
 *   base64( version_byte || nonce(24) || ciphertext )
 *
 * Key rotation:
 *   1. Add CREDENTIAL_ENCRYPTION_KEY_NEW=<new-hex-key> to .env.
 *   2. Run: php scripts/rotate-credential-keys.php
 *   3. The script re-encrypts all rows using the new key.
 *   4. Move CREDENTIAL_ENCRYPTION_KEY_NEW → CREDENTIAL_ENCRYPTION_KEY.
 *   5. Remove the old CREDENTIAL_ENCRYPTION_KEY_OLD var.
 */
final class CredentialEncryption
{
    /** Current key version written into ciphertext. */
    private const CURRENT_VERSION = 1;

    /** Expected length of a hex-encoded 32-byte key. */
    private const HEX_KEY_LEN = 64;

    /** Nonce length for XSalsa20-Poly1305. */
    private const NONCE_LEN = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; // 24

    /**
     * Encrypt a plaintext secret.
     *
     * @throws \RuntimeException if libsodium is not available, the key is
     *                           missing/invalid, or encryption fails.
     */
    public static function encrypt(string $plaintext): string
    {
        $key   = self::loadKey();
        $nonce = random_bytes(self::NONCE_LEN);

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Wipe the key from memory immediately after use.
        sodium_memzero($key);

        // Pack: version(1) || nonce(24) || ciphertext
        $packed = chr(self::CURRENT_VERSION) . $nonce . $ciphertext;

        return base64_encode($packed);
    }

    /**
     * Decrypt a ciphertext produced by self::encrypt().
     *
     * Returns null on decryption failure (tampered/wrong-key) rather than
     * throwing, so callers can fall back gracefully.
     *
     * @throws \RuntimeException if libsodium is not available or the key
     *                           is missing/invalid.
     */
    public static function decrypt(string $encoded): ?string
    {
        $key    = self::loadKey();
        $packed = base64_decode($encoded, strict: true);

        if ($packed === false || strlen($packed) < 1 + self::NONCE_LEN + 1) {
            sodium_memzero($key);
            return null;
        }

        // $version = ord($packed[0]); // reserved for future multi-version logic
        $nonce      = substr($packed, 1, self::NONCE_LEN);
        $ciphertext = substr($packed, 1 + self::NONCE_LEN);

        $result = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);

        return $result === false ? null : $result;
    }

    /**
     * Decrypt a credential field from a promote_credentials row.
     *
     * Transparently falls back to the plaintext column when the encrypted
     * column is NULL.  This supports a rolling migration: existing rows work
     * immediately; the encrypt-credentials script migrates them in the
     * background.
     *
     * @param  string|null $encValue    Value of enc_access_token / enc_refresh_token.
     * @param  string|null $plainValue  Value of the legacy access_token / refresh_token.
     * @return string|null              Decrypted secret, plaintext fallback, or null.
     */
    public static function decryptCredentialField(?string $encValue, ?string $plainValue): ?string
    {
        if ($encValue !== null && $encValue !== '') {
            return self::decrypt($encValue);
        }
        // Fall back to plaintext (pre-migration or plaintext-only row).
        return $plainValue;
    }

    /**
     * Return true if the CREDENTIAL_ENCRYPTION_KEY env var is present and
     * valid.  Used by health-checks and setup wizards.
     */
    public static function isConfigured(): bool
    {
        try {
            self::loadKey();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Load and validate the master key from the environment.
     *
     * @return string 32-byte raw key.
     * @throws \RuntimeException
     */
    private static function loadKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException(
                'libsodium is not available. Install the sodium PHP extension.'
            );
        }

        $hex = (string) (getenv('CREDENTIAL_ENCRYPTION_KEY') ?: '');
        if (strlen($hex) !== self::HEX_KEY_LEN) {
            throw new \RuntimeException(
                'CREDENTIAL_ENCRYPTION_KEY must be a 64-character hex string (32 bytes). '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"'
            );
        }

        $raw = hex2bin($hex);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('CREDENTIAL_ENCRYPTION_KEY is not valid hex.');
        }

        return $raw;
    }
}
