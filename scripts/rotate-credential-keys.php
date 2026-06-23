#!/usr/bin/env php
<?php
/**
 * rotate-credential-keys.php — re-encrypt promote_credentials with a new key.
 *
 * Usage:
 *   CREDENTIAL_ENCRYPTION_KEY=<current>  \
 *   CREDENTIAL_ENCRYPTION_KEY_NEW=<new>  \
 *   php scripts/rotate-credential-keys.php [--dry-run] [--db=<name>]
 *
 * Steps:
 *   1. Reads the current key (CREDENTIAL_ENCRYPTION_KEY).
 *   2. Reads the new key (CREDENTIAL_ENCRYPTION_KEY_NEW).
 *   3. For every row with enc_access_token IS NOT NULL:
 *      - Decrypts with the current key.
 *      - Re-encrypts with the new key.
 *      - Writes enc_key_version = 2 (or next version).
 *   4. After running: set CREDENTIAL_ENCRYPTION_KEY = <new key> in .env.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Env;
use Panic\Database;
use Panic\CredentialEncryption;

Env::load($root . '/.env');

$dryRun = in_array('--dry-run', $argv, true);
$dbArg  = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $dbArg = substr($arg, 5);
    }
}

$newKeyHex = (string)(getenv('CREDENTIAL_ENCRYPTION_KEY_NEW') ?: '');
if (strlen($newKeyHex) !== 64) {
    echo "ERROR: CREDENTIAL_ENCRYPTION_KEY_NEW must be a 64-char hex string.\n";
    exit(1);
}

if (!CredentialEncryption::isConfigured()) {
    echo "ERROR: CREDENTIAL_ENCRYPTION_KEY (current key) is not set.\n";
    exit(1);
}

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n";
}

if ($dbArg) {
    putenv("DB_NAME=$dbArg");
}

$db  = new Database();
$pdo = $db->pdo();

$rows = $db->all(
    'SELECT id, enc_access_token, enc_refresh_token FROM promote_credentials WHERE enc_access_token IS NOT NULL'
);

if (empty($rows)) {
    echo "No encrypted credentials found — nothing to rotate.\n";
    exit(0);
}

echo sprintf("Rotating %d row(s)...\n", count($rows));

$ok = 0; $failed = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];

    try {
        $ptAccess  = $row['enc_access_token']  !== null ? CredentialEncryption::decrypt($row['enc_access_token'])  : null;
        $ptRefresh = $row['enc_refresh_token'] !== null ? CredentialEncryption::decrypt($row['enc_refresh_token']) : null;

        if ($ptAccess === null && $row['enc_access_token'] !== null) {
            echo "  WARN: Could not decrypt access_token for row id=$id — skipping.\n";
            $failed++;
            continue;
        }

        // Re-encrypt with new key (temporarily swap env var).
        $currentKey = getenv('CREDENTIAL_ENCRYPTION_KEY');
        putenv("CREDENTIAL_ENCRYPTION_KEY=$newKeyHex");

        $newEncAccess  = $ptAccess  !== null ? CredentialEncryption::encrypt($ptAccess)  : null;
        $newEncRefresh = $ptRefresh !== null ? CredentialEncryption::encrypt($ptRefresh) : null;

        // Restore current key.
        putenv("CREDENTIAL_ENCRYPTION_KEY=$currentKey");

        if ($dryRun) {
            echo "  [DRY] Would rotate row id=$id.\n";
            $ok++;
            continue;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE promote_credentials SET enc_access_token=?, enc_refresh_token=?, enc_key_version=2 WHERE id=?'
        );
        $stmt->execute([$newEncAccess, $newEncRefresh, $id]);
        $pdo->commit();

        $ok++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "  ERROR rotating row id=$id: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo sprintf("\nRotated: %d | Failed: %d\n", $ok, $failed);
echo "\nNext step: update CREDENTIAL_ENCRYPTION_KEY in .env to the new key value.\n";

exit($failed > 0 ? 1 : 0);
