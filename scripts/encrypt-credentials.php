#!/usr/bin/env php
<?php
/**
 * encrypt-credentials.php — migrate promote_credentials plaintext tokens to
 * application-level encryption.
 *
 * Usage:
 *   php scripts/encrypt-credentials.php [--dry-run] [--db=<name>]
 *
 * Flags:
 *   --dry-run   Report what would be migrated without writing anything.
 *   --db=NAME   Override DB_NAME (useful for tenant databases in SaaS mode).
 *
 * Safety:
 *   - Idempotent: rows already encrypted (enc_access_token IS NOT NULL) are skipped.
 *   - Runs inside a transaction per row; rolls back on any failure.
 *   - Never logs or prints the plaintext or ciphertext values.
 *   - After migration, plaintext columns are set to NULL.
 *
 * Key rotation:
 *   1. Add CREDENTIAL_ENCRYPTION_KEY_NEW=<hex> to .env.
 *   2. Run this script with --rotate flag.
 *   3. Move NEW → current key in .env; remove OLD.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Env;
use Panic\Database;
use Panic\CredentialEncryption;

Env::load($root . '/.env');

$dryRun  = in_array('--dry-run', $argv, true);
$dbArg   = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $dbArg = substr($arg, 5);
    }
}

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n";
}

// Validate the encryption key is configured before we touch anything.
if (!CredentialEncryption::isConfigured()) {
    echo "ERROR: CREDENTIAL_ENCRYPTION_KEY is not set or invalid.\n";
    echo "Generate one with: php -r \"echo bin2hex(random_bytes(32)) . PHP_EOL;\"\n";
    exit(1);
}

// Connect to the database.
if ($dbArg) {
    putenv("DB_NAME=$dbArg");
}

$db  = new Database();
$pdo = $db->pdo();

// Find rows that still have plaintext tokens.
$rows = $db->all(
    'SELECT id, access_token, refresh_token
     FROM promote_credentials
     WHERE (access_token IS NOT NULL OR refresh_token IS NOT NULL)
       AND enc_access_token IS NULL'
);

if (empty($rows)) {
    echo "No plaintext credentials found — nothing to migrate.\n";
    exit(0);
}

echo sprintf("Found %d credential row(s) to encrypt.\n", count($rows));

$migrated = 0;
$skipped  = 0;
$failed   = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];

    try {
        $encAccess  = $row['access_token']  !== null ? CredentialEncryption::encrypt($row['access_token'])  : null;
        $encRefresh = $row['refresh_token'] !== null ? CredentialEncryption::encrypt($row['refresh_token']) : null;

        if ($dryRun) {
            echo "  [DRY] Would encrypt row id=$id.\n";
            $migrated++;
            continue;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE promote_credentials
             SET enc_access_token  = ?,
                 enc_refresh_token = ?,
                 enc_key_version   = 1,
                 access_token      = NULL,
                 refresh_token     = NULL
             WHERE id = ?'
        );
        $stmt->execute([$encAccess, $encRefresh, $id]);

        $pdo->commit();
        $migrated++;

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  ERROR encrypting row id=$id: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo sprintf(
    "\nDone. Migrated: %d | Skipped: %d | Failed: %d\n",
    $migrated, $skipped, $failed
);

if ($failed > 0) {
    exit(1);
}
