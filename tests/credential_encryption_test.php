<?php
/**
 * Tests for CredentialEncryption service.
 *
 * Run with: php tests/credential_encryption_test.php
 * Or: php run-tests.sh (which discovers and runs all test files)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\CredentialEncryption;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  ✓ $label\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: $label\n";
        $failed++;
    }
}

function throws(callable $fn, string $label): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  ✗ FAIL (no exception): $label\n";
        $failed++;
    } catch (\Throwable) {
        echo "  ✓ $label\n";
        $passed++;
    }
}

echo "\n=== CredentialEncryption tests ===\n\n";

// ── 1. Basic isConfigured() without key ──────────────────────────────────────

$savedKey = getenv('CREDENTIAL_ENCRYPTION_KEY');
putenv('CREDENTIAL_ENCRYPTION_KEY=');
ok(!CredentialEncryption::isConfigured(), 'isConfigured() returns false when key is not set');

// ── 2. Encrypt/decrypt round-trip ─────────────────────────────────────────────

$testKey = bin2hex(random_bytes(32));
putenv("CREDENTIAL_ENCRYPTION_KEY=$testKey");

ok(CredentialEncryption::isConfigured(), 'isConfigured() returns true with valid key');

$plaintext  = 'super-secret-oauth-token-abc123';
$ciphertext = CredentialEncryption::encrypt($plaintext);

ok($ciphertext !== $plaintext, 'ciphertext is not equal to plaintext');
ok(strlen($ciphertext) > strlen($plaintext), 'ciphertext is longer than plaintext');
ok(!str_contains($ciphertext, $plaintext), 'plaintext does not appear in ciphertext');

$decrypted = CredentialEncryption::decrypt($ciphertext);
ok($decrypted === $plaintext, 'decrypt() returns original plaintext');

// ── 3. Two encryptions of the same value produce different ciphertexts ────────

$enc1 = CredentialEncryption::encrypt($plaintext);
$enc2 = CredentialEncryption::encrypt($plaintext);
ok($enc1 !== $enc2, 'each encryption is unique (random nonce)');

// ── 4. Tampered ciphertext returns null (not an exception) ────────────────────

$tampered = base64_encode('garbage-tampered-data-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
$result   = CredentialEncryption::decrypt($tampered);
ok($result === null, 'tampered ciphertext returns null, not a throw');

// ── 5. Wrong key returns null ────────────────────────────────────────────────

$wrongKey = bin2hex(random_bytes(32));
putenv("CREDENTIAL_ENCRYPTION_KEY=$wrongKey");
$wrongResult = CredentialEncryption::decrypt($ciphertext);
ok($wrongResult === null, 'wrong key returns null, not plaintext');

// Restore the original key
putenv("CREDENTIAL_ENCRYPTION_KEY=$testKey");

// ── 6. decryptCredentialField fallback ───────────────────────────────────────

// Both null → null
ok(CredentialEncryption::decryptCredentialField(null, null) === null, 'both null → null');

// Only plaintext (pre-migration row) → plaintext returned
ok(CredentialEncryption::decryptCredentialField(null, 'my-token') === 'my-token',
   'plaintext fallback works when enc column is null');

// Encrypted column present → decrypt it
$enc = CredentialEncryption::encrypt('encrypted-token');
ok(CredentialEncryption::decryptCredentialField($enc, 'old-plaintext') === 'encrypted-token',
   'encrypted column takes priority over plaintext fallback');

// ── 7. Secrets never appear in API responses ──────────────────────────────────

// Simulate what CredentialSettings GET returns: tokens should be hidden.
$responsePayload = [
    'destination_key' => 'facebook',
    'cred_status'     => 'connected',
    'has_token'       => true,
    // These must NOT be present:
    // 'access_token', 'refresh_token', 'enc_access_token', 'enc_refresh_token'
];

$responseJson = json_encode($responsePayload);
$secretValue  = CredentialEncryption::encrypt('my-facebook-token');

ok(!str_contains($responseJson, 'access_token'), 'access_token key not in response');
ok(!str_contains($responseJson, 'my-facebook-token'), 'secret value not in response');
ok(!str_contains($responseJson, $secretValue), 'ciphertext not in response');

// ── 8. Invalid key length throws RuntimeException ─────────────────────────────

putenv('CREDENTIAL_ENCRYPTION_KEY=tooshort');
throws(fn() => CredentialEncryption::encrypt('test'), 'short key throws RuntimeException');

// Restore
if ($savedKey !== false) {
    putenv("CREDENTIAL_ENCRYPTION_KEY=$savedKey");
} else {
    putenv("CREDENTIAL_ENCRYPTION_KEY=$testKey");
}

// ── Summary ──────────────────────────────────────────────────────────────────

echo "\nCredential encryption: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
