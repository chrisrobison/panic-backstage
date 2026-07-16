<?php

/**
 * Panic Backstage — one-time consolidation of Mabuhay Gardens' 3 "venue" rows
 * into 1 venue + rooms
 *
 * Mabuhay's two physical spaces (upstairs/downstairs) were originally
 * modeled as three separate `venues` rows (id 1 "Mabuhay Gardens", id 2
 * "Upstairs Mabuhay Gardens", id 3 "Mabuhay Gardens: Both Rooms") instead of
 * one venue with `resources` (rooms). That's now backwards from the app's
 * real venue+room model (see `resources` table, `Venues.php`'s
 * `/venues/{id}/resources` CRUD, and the Admin → Venue rooms panel, all of
 * which already assume a venue can have multiple rooms).
 *
 * This script is Mabuhay-specific data cleanup, NOT a generic schema
 * migration — it hardcodes this instance's exact venue/resource ids and
 * must NEVER be run against another tenant database (a different tenant's
 * venue_id=2/3 could be a legitimate distinct venue, not a duplicate room).
 * That's why it lives in scripts/ as a standalone one-off instead of
 * database/migrations/ (which IS shared across every tenant DB — see
 * database/migrations/README.md).
 *
 * What it does, in order (see the plan this was built from for the full
 * verified rationale — venue_id/resource_id counts, FK cascade behavior):
 *   1. Repoints events on venue_id=2 ("Upstairs") to venue_id=1, resource_id
 *      2 ("Downstairs (21+)") or 3 ("Upstairs (All Ages)") based on the
 *      event's own free-text `room` column (source of truth over venue_id
 *      for the 2 known-anomalous rows).
 *   2. Repoints events on venue_id=3 ("Both Rooms") to venue_id=1,
 *      resource_id 4 ("Both Rooms").
 *   3. Backfills resource_id=2 (Downstairs) on any remaining venue_id=1
 *      event that still has no resource_id.
 *   4. Repoints `contracts`, `venue_policies`, `pos_location_map` rows off
 *      venue_id 2/3 onto venue_id=1 (contracts FK is ON DELETE SET NULL,
 *      venue_policies is ON DELETE CASCADE — both would silently lose data
 *      if not moved first).
 *   5. Clears venue_id=1's now-vestigial `zone`/`venue_group` columns.
 *   6. Deletes venues 2 and 3 (cascades: the orphan "Upstairs" resource
 *      row under venue_id=2 goes with it).
 *
 * Idempotent: safe to re-run — every step is scoped by venue_id/resource_id
 * conditions, so a second run is a no-op once venues 2/3 are gone.
 *
 * Usage:
 *   php scripts/consolidate-mabuhay-venues.php [--dry-run]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$db  = new Panic\Database();
$pdo = $db->pdo();

echo $dryRun ? "── DRY RUN — no writes ──\n\n" : "── Applying ──\n\n";

$before = [
    'events by venue_id'   => $db->all('SELECT venue_id, COUNT(*) n FROM events GROUP BY venue_id ORDER BY venue_id'),
    'contracts by venue_id' => $db->all('SELECT venue_id, COUNT(*) n FROM contracts GROUP BY venue_id ORDER BY venue_id'),
];
foreach ($before as $label => $rows) {
    echo "{$label}:\n";
    foreach ($rows as $r) echo "   venue_id={$r['venue_id']}: {$r['n']}\n";
}
echo "\n";

if (!$dryRun) $pdo->beginTransaction();

try {
    $steps = [
        // 1. venue_id=2 (Upstairs) -> venue 1, resource 2 or 3 by room text
        ["UPDATE events SET venue_id = 1, resource_id = CASE WHEN room = 'downstairs' THEN 2 ELSE 3 END WHERE venue_id = 2", []],
        // 2. venue_id=3 (Both Rooms) -> venue 1, resource 4
        ["UPDATE events SET venue_id = 1, resource_id = 4 WHERE venue_id = 3", []],
        // 3. remaining venue_id=1 events with no resource_id -> Downstairs
        ["UPDATE events SET resource_id = 2 WHERE venue_id = 1 AND resource_id IS NULL", []],
        // 4. move dependent rows off venue 2/3 before deleting them
        ["UPDATE contracts SET venue_id = 1 WHERE venue_id IN (2, 3)", []],
        ["UPDATE venue_policies SET venue_id = 1 WHERE venue_id = 3", []],
        ["UPDATE pos_location_map SET venue_id = 1 WHERE venue_id = 3", []],
        // 5. clear vestigial zone/venue_group on the surviving venue
        ["UPDATE venues SET zone = NULL, venue_group = NULL WHERE id = 1", []],
        // 6. delete the now-empty duplicate venue rows (cascades resources)
        ["DELETE FROM venues WHERE id IN (2, 3)", []],
    ];

    foreach ($steps as [$sql, $params]) {
        $affected = $dryRun ? null : $db->run($sql, $params);
        echo ($dryRun ? '[dry-run] ' : "[{$affected} row(s)] ") . $sql . "\n";
    }

    if (!$dryRun) $pdo->commit();
} catch (\Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . " — rolled back, no changes made.\n");
    exit(1);
}

echo "\n";
if ($dryRun) {
    echo "Dry run only — nothing was written.\n";
} else {
    $after = $db->all('SELECT id, name FROM venues ORDER BY id');
    echo "venues remaining:\n";
    foreach ($after as $v) echo "   {$v['id']}: {$v['name']}\n";
    $byResource = $db->all('SELECT resource_id, COUNT(*) n FROM events GROUP BY resource_id ORDER BY resource_id');
    echo "events by resource_id:\n";
    foreach ($byResource as $r) echo "   resource_id=" . ($r['resource_id'] ?? 'NULL') . ": {$r['n']}\n";
}
echo "\nDone.\n";
