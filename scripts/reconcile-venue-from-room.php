<?php
declare(strict_types=1);

namespace Panic;

/**
 * Keep events.venue_id aligned with events.room.
 *
 * `room` (Upstairs / Downstairs / Both) is the field the Google Sheet drives
 * and is the source of truth for which floor a show is on. `venue_id` is the
 * "Venue" dropdown the rest of the app (calendar floor-split, public page,
 * print packets) reads. Historically the import only ever set `room`, so every
 * event's venue_id stayed pinned to the downstairs room and the two drifted
 * apart. This maps each room value onto the matching venue, by slug:
 *
 *     upstairs   -> mabuhay-upstairs  (Mabuhay Gardens: On Broadway)
 *     downstairs -> mabuhay-gardens   (Mabuhay Gardens: The Mab)
 *     both       -> mabuhay-both      (Mabuhay Gardens: Both Rooms)
 *
 * Idempotent and safe to re-run: it only touches rows whose venue_id is out of
 * step, and leaves events with a blank / "tbd" room untouched. Run standalone
 * for a one-off backfill, or let scripts/import-mabevents.php call it after each
 * sheet import so the two fields never drift again.
 *
 *   php scripts/reconcile-venue-from-room.php
 *
 * Shared as a function so the cron importer (which holds a raw PDO) and this
 * CLI entry point run identical SQL. Returns the affected row count.
 */
function reconcileVenueFromRoom(\PDO $pdo): int
{
    $sql = "UPDATE events e
            JOIN venues v ON v.slug = CASE e.room
                WHEN 'upstairs'   THEN 'mabuhay-upstairs'
                WHEN 'downstairs' THEN 'mabuhay-gardens'
                WHEN 'both'       THEN 'mabuhay-both'
            END
            SET e.venue_id = v.id
            WHERE e.room IN ('upstairs','downstairs','both')
              AND e.venue_id <> v.id";
    return (int) ($pdo->exec($sql) ?: 0);
}

// Run only when executed directly — requiring this file (e.g. from the
// importer) just defines the function above.
if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require __DIR__ . '/../src/bootstrap.php';
    Env::load(__DIR__ . '/../.env');
    $db = new Database();
    $n = reconcileVenueFromRoom($db->pdo());
    echo "Reconciled venue_id from room: {$n} event(s) updated.\n";
}
