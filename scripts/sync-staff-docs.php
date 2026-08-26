<?php
declare(strict_types=1);

/**
 * Staff Handbook & Compliance — bootstrap/sync the staff_documents table
 * from docs/staff/ (top-level and sop/) Markdown files and publish each
 * one's current version.
 *
 * This is the "seed data" for the feature: rather than a static SQL INSERT
 * list (which would drift from the actual Markdown the moment someone
 * edited a file), it drives the same publish workflow the admin API uses
 * (Panic\StaffDocs::syncFromDisk() / publishFromFile()) — see that class's
 * docblock. Safe to re-run any time: syncing re-registers metadata from
 * frontmatter, and publishing a version whose content hash hasn't changed
 * is a no-op.
 *
 * Usage:
 *   php scripts/sync-staff-docs.php              sync + publish every doc
 *   php scripts/sync-staff-docs.php --sync-only  register/update metadata only, publish nothing
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\StaffDocs;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$db = new Database();
$syncOnly = in_array('--sync-only', $argv, true);

echo "Scanning docs/staff/** ...\n";
foreach (StaffDocs::syncFromDisk($db, $root) as $r) {
    printf("  [%s] %s (%s)\n", $r['action'], $r['slug'], $r['file']);
}

if ($syncOnly) {
    echo "Sync only — not publishing.\n";
    exit(0);
}

echo "\nPublishing current versions ...\n";
$slugs = $db->all('SELECT slug FROM staff_documents ORDER BY slug');
foreach ($slugs as $row) {
    $slug = $row['slug'];
    $result = StaffDocs::publishFromFile($db, $root, $slug, null);
    if (isset($result['error'])) {
        printf("  [ERROR] %s: %s\n", $slug, $result['error']);
        continue;
    }
    printf("  [%s] %s v%s\n", $result['status'], $slug, $result['version']);
}

echo "\nDone.\n";
