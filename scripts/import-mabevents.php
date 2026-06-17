<?php
/**
 * import-mabevents.php
 *
 * Imports all MabEvents.xlsx data into panic_backstage.
 *
 * Usage:
 *   php backstage/scripts/import-mabevents.php
 *
 * Prerequisites:
 *   1. database/schema.sql has been applied (or seed.php has been run) — it is
 *      the baseline schema and already includes the MabEvents columns.
 *   2. The 'mabuhay-gardens' venue row exists (created by seed.php)
 *   3. The .env file is present in backstage/
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

// ── Step 1: check prerequisites ────────────────────────────────────────────
// The MabEvents columns ship in the baseline database/schema.sql, so there is
// no migration to apply here — schema.sql / seed.php must have been run first.
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

// ── Step 5: keep venue_id aligned with the freshly-imported room values ──────
// The sheet only carries `room` (Upstairs/Downstairs/Both); derive the matching
// `venue_id` so the Venue dropdown + calendar floor-split reflect the same floor
// instead of drifting back to the downstairs default.
require_once __DIR__ . '/reconcile-venue-from-room.php';
$venueFixed = \Panic\reconcileVenueFromRoom($pdo);
if ($venueFixed > 0) {
    echo "  Venue realigned to room: $venueFixed event(s)\n";
}

// ── Step 6: assign EVT-N codes to any imported event that had none ─────────
// Sheet rows whose column A was blank at export time arrive with external_id
// NULL. Assign the next sequential EVT-N to each so every event has a stable
// human-readable ID for cross-system referencing. Oldest-first (date ASC, id
// ASC) keeps codes in roughly chronological order. After this, the cron /
// sync-mabevents.py pipeline calls app-id-sync.php push-codes to write them
// back to column A of the Tracker sheet.
$missing = $pdo->query(
    "SELECT id, title, date FROM events WHERE external_id IS NULL OR external_id = '' ORDER BY date ASC, id ASC"
)->fetchAll();

$codesAssigned = 0;
foreach ($missing as $ev) {
    $id = (int) $ev['id'];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $maxRow = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(external_id, 5) AS UNSIGNED)), 0) FROM events WHERE external_id LIKE 'EVT-%'")->fetchColumn();
        $code   = 'EVT-' . ((int) $maxRow + 1);
        try {
            $stmt = $pdo->prepare("UPDATE events SET external_id = ? WHERE id = ? AND (external_id IS NULL OR external_id = '')");
            $stmt->execute([$code, $id]);
            $codesAssigned++;
            break;
        } catch (PDOException $e) {
            // Unique-index collision — retry with the next number.
        }
    }
}
if ($codesAssigned > 0) {
    echo "  EVT codes assigned  : $codesAssigned (sheet rows that had no ID in column A)\n";
    echo "  → run app-id-sync.php push-codes to write these back to the sheet\n";
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
