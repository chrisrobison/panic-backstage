<?php
/**
 * import-mabevents.php
 *
 * Applies migration 002 and imports all MabEvents.xlsx data into panic_backstage.
 *
 * Usage:
 *   php backstage/scripts/import-mabevents.php
 *
 * Prerequisites:
 *   1. schema.sql has been applied (or seed.php has been run)
 *   2. migration 001 has been applied
 *   3. The 'mabuhay-gardens' venue row exists (created by seed.php)
 *   4. The .env file is present in backstage/
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$host   = getenv('DB_HOST')     ?: '127.0.0.1';
$port   = getenv('DB_PORT')     ?: '3306';
$user   = getenv('DB_USER')     ?: 'root';
$pass   = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME')     ?: 'panic_backstage';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Step 1: apply migration 002 ────────────────────────────────────────────
$migrationFile = $root . '/database/migrations/002_mabevents_fields.sql';
echo "Applying migration 002...\n";
try {
    foreach (parseSqlStatements(file_get_contents($migrationFile)) as $stmt) {
        $pdo->exec($stmt);
    }
    echo "  Migration 002 applied.\n";
} catch (PDOException $e) {
    // Column already exists is fine; anything else is fatal
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "  Columns already exist, skipping.\n";
    } else {
        fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ── Step 2: check prerequisites ────────────────────────────────────────────
$venueId = $pdo->query("SELECT id FROM venues WHERE slug = 'mabuhay-gardens' LIMIT 1")->fetchColumn();
if (!$venueId) {
    fwrite(STDERR, "Venue 'mabuhay-gardens' not found. Run seed.php first.\n");
    exit(1);
}
echo "  Using venue id=$venueId (Mabuhay Gardens)\n";

// Default event owner: prefer the legacy seed admin, otherwise the lowest-id venue_admin.
$ownerId = $pdo->query("SELECT id FROM users WHERE email = 'admin@mabuhay.local' LIMIT 1")->fetchColumn()
       ?: $pdo->query("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$ownerId) {
    fwrite(STDERR, "No suitable owner user found (need email=admin@mabuhay.local OR a user with role='venue_admin').\n");
    exit(1);
}
echo "  Using owner user_id=$ownerId\n";

// ── Step 3: report existing import (idempotent UPSERT — safe to re-run) ───
$existingCount = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE referral_source IS NOT NULL OR external_id IS NOT NULL")->fetchColumn();
if ($existingCount > 0) {
    echo "  $existingCount previously-imported events found — will be UPSERTed (status preserved).\n";
}

// ── Step 4: run the import SQL ─────────────────────────────────────────────
$importFile = $root . '/database/mabevents-import.sql';
echo "Running import SQL...\n";
$sql = file_get_contents($importFile);

// Replace @venue_id / @owner_id placeholders' SET with actual values
// (the SQL already uses SET @venue_id = subquery; we just run the file as-is)
// The generated SQL contains its own START TRANSACTION / COMMIT, so we don't
// wrap it in another PHP-level transaction. On error, MySQL will have rolled
// back automatically up to the most recent commit.
try {
    foreach (parseSqlStatements($sql) as $stmt) {
        $pdo->exec($stmt);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Report ─────────────────────────────────────────────────────────────────
$totalEvents = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$totalUsers  = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$importedEvts = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE referral_source IS NOT NULL OR external_id IS NOT NULL OR promoter_name IS NOT NULL")->fetchColumn();
echo "  Done!\n";
echo "  Total events in DB : $totalEvents\n";
echo "  Imported from xlsx  : $importedEvts\n";
echo "  Total users in DB  : $totalUsers\n";

// ── Helper ─────────────────────────────────────────────────────────────────
function parseSqlStatements(string $sql): array
{
    // Strip comments, split on semicolons
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn ($s) => $s !== ''
    );
    return array_values($statements);
}
