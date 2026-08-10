<?php
declare(strict_types=1);

/**
 * One-way mirror of Backstage events onto a shared Google Calendar.
 *
 * See docs/google-calendar-sync.md. Run nightly from cron-google-calendar.sh.
 *
 * Usage:
 *   php scripts/sync-google-calendar.php --dry-run    # show what would change
 *   php scripts/sync-google-calendar.php              # apply
 *   php scripts/sync-google-calendar.php --verbose    # per-event detail
 *
 * Design: a stateless reconcile sweep, NOT a write-hook + retry queue. It
 * recomputes the desired calendar state from the DB on every run, so it
 * self-heals drift and needs no queue table to fall behind (the sheet sync's
 * queue is currently paused over exactly that class of bug). Consequences:
 *   - Safe to run repeatedly; a second run right after a first reports all-skip.
 *   - "Within a day" freshness is the design target, matching the nightly cron.
 *     Anything needing minutes would want an inline push instead.
 *
 * Scope: every event dated within the last WINDOW_PAST_DAYS or later, excluding
 * placeholder `empty` rows. Holds are included as all-day "HOLD — " entries.
 * Canceled events are DELETED from the calendar and unlinked, freeing the night.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\GoogleCalendar;

/** How far back to keep events accurate. Recently-past shows still get status fixes. */
const WINDOW_PAST_DAYS = 7;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$args    = array_slice($argv, 1);
$dryRun  = in_array('--dry-run', $args, true);
$verbose = in_array('--verbose', $args, true) || $dryRun;

$cal = new GoogleCalendar($root);

if (!GoogleCalendar::syncEnabled()) {
    fwrite(STDERR, "Google Calendar sync is disabled (GCAL_SYNC_ENABLED=0). Nothing to do.\n");
    exit(0);
}
if (!$cal->isConfigured()) {
    fwrite(STDERR, "GoogleCalendar not configured (GOOGLE_SA_KEY_FILE / GOOGLE_CALENDAR_ID). Nothing to do.\n");
    exit(0);
}

$db     = new Database();
$appUrl = rtrim((string) (getenv('APP_URL') ?: 'https://panicbooking.com/backstage'), '/');

$skipList = implode(',', array_map(static fn ($s) => "'" . $s . "'", GoogleCalendar::SKIP_STATUSES));

/*
 * `PB UI TEST%` is the repo's convention for throwaway fixtures created by
 * tests/ui against this same production DB. They're meant to be deleted in a
 * `finally`, but leaks happen (three were sitting at date 2099 when this sync
 * was written) — and a leaked fixture must never reach the team's calendar.
 *
 * Push only what actually changed. `updated_at` auto-touches on every row
 * change, so in steady state a nightly run makes ~0 API calls instead of
 * blindly re-patching every upcoming event.
 */
$rows = $db->all(
    "SELECT e.id, e.title, e.status, e.event_type, e.date, e.end_date,
            e.doors_time, e.show_time, e.end_time, e.load_in_time, e.load_out_time,
            e.room, e.capacity, e.promoter_name, e.booker_name,
            e.gcal_event_id, e.gcal_synced_at, e.updated_at,
            v.name AS venue_name, v.address AS venue_address, v.timezone AS venue_timezone
     FROM   events e
     JOIN   venues v ON v.id = e.venue_id
     WHERE  e.date >= (CURDATE() - INTERVAL ? DAY)
       AND  e.status NOT IN ({$skipList})
       AND  e.title NOT LIKE 'PB UI TEST%'
     ORDER BY e.date ASC, e.id ASC",
    [WINDOW_PAST_DAYS]
);

$created = 0; $updated = 0; $skipped = 0; $failed = 0; $deleted = 0;
$liveIds = [];

foreach ($rows as $row) {
    $id       = (int) $row['id'];
    $gcalId   = $row['gcal_event_id'] ?: null;
    $synced   = $row['gcal_synced_at'] ?: null;
    $isStale  = $gcalId === null || $synced === null || strtotime((string) $row['updated_at']) > strtotime((string) $synced);

    if ($gcalId !== null) {
        $liveIds[$gcalId] = true;
    }

    /*
     * Canceled events are removed from the calendar, not relabelled — the night
     * should read as free.
     *
     * This MUST come before the staleness gate: an event that was already
     * synced under the old "CANCELED — " policy has gcal_synced_at >= updated_at,
     * so a staleness check would skip it and the entry would linger forever.
     * Self-terminating — once deleted the id is NULL, so later runs skip it.
     */
    if (GoogleCalendar::shouldRemove((string) $row['status'])) {
        if ($gcalId === null) {
            $skipped++;
            continue;
        }
        if ($dryRun) {
            printf("  %-6s #%d %s (%s, %s)\n", 'DELETE', $id, $row['title'], $row['status'], $row['date']);
            $deleted++;
            continue;
        }
        $code = $cal->deleteEvent($gcalId);
        if ($code === 204 || $code === 404 || $code === 410) {
            $db->run('UPDATE events SET gcal_event_id = NULL, gcal_synced_at = NULL WHERE id = ?', [$id]);
            unset($liveIds[$gcalId]);
            $deleted++;
            if ($verbose) echo "  DELETE #{$id} {$row['title']} (canceled)\n";
        } else {
            $failed++;
            if ($verbose) echo "  FAIL   #{$id} delete (HTTP {$code})\n";
        }
        continue;
    }

    if (!$isStale) {
        $skipped++;
        continue;
    }

    $body  = GoogleCalendar::eventBody($row, $appUrl);
    $label = sprintf('#%d %s (%s, %s)', $id, $body['summary'], $row['status'], $row['date']);

    if ($dryRun) {
        $when = isset($body['start']['date'])
            ? 'all-day ' . $body['start']['date']
            : $body['start']['dateTime'] . ' → ' . $body['end']['dateTime'];
        printf("  %-6s %s\n         %s\n", $gcalId === null ? 'CREATE' : 'UPDATE', $label, $when);
        $gcalId === null ? $created++ : $updated++;
        continue;
    }

    if ($gcalId !== null) {
        $code = $cal->patchEvent($gcalId, $body);
        if ($code === 404 || $code === 410) {
            // Hand-deleted in Google — forget the id and fall through to create.
            $cal->log("event {$id}: gcal event {$gcalId} gone (HTTP {$code}), re-creating");
            unset($liveIds[$gcalId]);
            $gcalId = null;
        } elseif ($code >= 200 && $code < 300) {
            $db->run('UPDATE events SET gcal_synced_at = NOW() WHERE id = ?', [$id]);
            $updated++;
            if ($verbose) echo "  UPDATE {$label}\n";
            continue;
        } else {
            $failed++;
            if ($verbose) echo "  FAIL   {$label} (HTTP {$code})\n";
            continue;
        }
    }

    $newId = $cal->createEvent($body);
    if ($newId === null) {
        $failed++;
        if ($verbose) echo "  FAIL   {$label} (create)\n";
        continue;
    }
    $db->run('UPDATE events SET gcal_event_id = ?, gcal_synced_at = NOW() WHERE id = ?', [$newId, $id]);
    $liveIds[$newId] = true;
    $created++;
    if ($verbose) echo "  CREATE {$label}\n";
}

/*
 * Orphan reconcile. DELETE /api/events/{id} cascades the row away, taking
 * gcal_event_id with it — without this step the Google entry would linger
 * forever with nothing left to point at it. Only events carrying our own
 * `panicApp` marker are considered, so entries staff created by hand on this
 * shared calendar are never at risk.
 */
$since   = new DateTimeImmutable('-' . WINDOW_PAST_DAYS . ' days');
$appRows = $cal->listAppEvents($since);

if ($appRows === null) {
    fwrite(STDERR, "WARN: could not list calendar events; skipping orphan reconcile.\n");
} else {
    foreach ($appRows as $item) {
        if ($item['id'] === '' || isset($liveIds[$item['id']])) {
            continue;
        }
        // Belt and braces: only delete if the app row really is gone.
        if ($item['appEventId'] > 0) {
            $still = $db->one('SELECT id FROM events WHERE id = ?', [$item['appEventId']]);
            if ($still !== null) {
                continue; // outside the window, or skipped this run — leave it alone
            }
        }
        if ($dryRun) {
            printf("  %-6s orphan %s (%s)\n", 'DELETE', $item['id'], $item['summary']);
            $deleted++;
            continue;
        }
        $code = $cal->deleteEvent($item['id']);
        if ($code === 204 || $code === 404 || $code === 410) {
            $deleted++;
            if ($verbose) echo "  DELETE orphan {$item['summary']}\n";
        } else {
            $failed++;
        }
    }
}

$summary = sprintf(
    '%s%d created, %d updated, %d unchanged, %d orphans removed, %d failed (of %d in window)',
    $dryRun ? 'DRY RUN: ' : '',
    $created, $updated, $skipped, $deleted, $failed, count($rows)
);

echo $summary . "\n";
if (!$dryRun) {
    $cal->log($summary);
}

exit($failed > 0 ? 1 : 0);
