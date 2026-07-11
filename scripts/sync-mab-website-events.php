<?php
/**
 * sync-mab-website-events.php
 *
 * Reconciles panic_backstage's events table against the "Upcoming events"
 * carousel currently live on themab.org, so GET /api/feed/events.json (and
 * the <mab-events-carousel> web component that consumes it) shows exactly
 * what the public site shows. See database/mab-website-events-sync.sql for
 * the per-event mapping/notes. Safe to re-run.
 *
 * Usage:
 *   php backstage/scripts/sync-mab-website-events.php
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

$venueId = $pdo->query("SELECT id FROM venues WHERE slug = 'mabuhay-gardens' LIMIT 1")->fetchColumn();
if (!$venueId) {
    fwrite(STDERR, "Venue 'mabuhay-gardens' not found. Run seed.php first.\n");
    exit(1);
}

$sqlFile = $root . '/database/mab-website-events-sync.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Could not read $sqlFile\n");
    exit(1);
}

echo "Syncing events from themab.org carousel...\n";
try {
    foreach (parseSqlStatements($sql) as $stmt) {
        $pdo->exec($stmt);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Sync failed: " . $e->getMessage() . "\n");
    exit(1);
}

$count = (int) $pdo->query(
    "SELECT COUNT(*) FROM events WHERE public_visibility = 1 AND status <> 'canceled' AND date >= CURDATE()"
)->fetchColumn();
echo "  Done! $count publicly-visible upcoming event(s) in the feed.\n";

// ── Helper (same as scripts/import-mabevents.php) ──────────────────────────
function parseSqlStatements(string $sql): array
{
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn ($s) => $s !== ''
    );
    return array_values($statements);
}
