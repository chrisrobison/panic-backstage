<?php
declare(strict_types=1);

/**
 * Fallback sweep for two-way Google Sheet sync.
 *
 * Real-time pushes happen inline on PATCH /api/events/{id}. Anything that
 * failed (Google hiccup, key not yet installed, transient network) is left as
 * a `pending` row in sheet_sync_queue. This script drains those rows and
 * retries the push. Run it from the existing 5-minute cron after the inbound
 * sync, so write-back and read stay close together.
 *
 * Usage:
 *   php scripts/push-sheet-queue.php          # process pending rows
 *   php scripts/push-sheet-queue.php --verbose
 *
 * Idempotent and safe to run repeatedly. Rows that keep failing past
 * MAX_ATTEMPTS are flipped to `failed` so they stop consuming each run but
 * remain visible for inspection.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\GoogleSheets;

const MAX_ATTEMPTS = 20;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$verbose = in_array('--verbose', array_slice($argv, 1), true);
$db      = new Database();
$sheets  = new GoogleSheets($root);

if (!$sheets->isConfigured()) {
    fwrite(STDERR, "GoogleSheets not configured (GOOGLE_SA_KEY_FILE / GOOGLE_SHEET_ID). Nothing to do.\n");
    exit(0);
}

$pushable = array_keys(GoogleSheets::FIELD_COLUMN);
$cols     = implode(', ', array_map(fn ($c) => "e.{$c}", $pushable));

$rows = $db->all(
    "SELECT q.event_id, e.external_id, {$cols}
     FROM   sheet_sync_queue q
     JOIN   events e ON e.id = q.event_id
     WHERE  q.status = 'pending' AND q.attempts < ?
     ORDER BY q.updated_at ASC
     LIMIT 200",
    [MAX_ATTEMPTS]
);

$ok = 0; $fail = 0; $skip = 0;

foreach ($rows as $r) {
    $eventId = (int) $r['event_id'];

    $fields = [];
    foreach ($pushable as $f) {
        if (array_key_exists($f, $r)) {
            $fields[$f] = $r[$f];
        }
    }

    // Locate the row by the immutable app id (App ID column). An event whose
    // row hasn't been linked yet (no App ID written) returns false and stays
    // pending — it is NOT marked done, so the silent-success bug is gone and the
    // failure is visible in last_error for inspection / backfill.
    if ($sheets->pushEventByAppId($eventId, $fields)) {
        $db->run(
            "UPDATE sheet_sync_queue
             SET status = 'done', attempts = attempts + 1, last_error = NULL, pushed_at = NOW()
             WHERE event_id = ?",
            [$eventId]
        );
        $ok++;
        if ($verbose) echo "  ✓ event #{$eventId}\n";
    } else {
        $db->run(
            "UPDATE sheet_sync_queue
             SET attempts = attempts + 1,
                 status = IF(attempts + 1 >= ?, 'failed', 'pending'),
                 last_error = 'row not linked or push failed (see storage/logs/sheet-sync.log)'
             WHERE event_id = ?",
            [MAX_ATTEMPTS, $eventId]
        );
        $fail++;
        if ($verbose) echo "  ✗ event #{$eventId}\n";
    }
}

printf("sheet write-back: %d ok, %d failed, %d skipped (of %d pending)\n", $ok, $fail, $skip, count($rows));
