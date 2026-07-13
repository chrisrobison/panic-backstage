<?php
declare(strict_types=1);

/**
 * Auto-expire stale Holds and warn 48 hours before it happens.
 *
 *   php scripts/expire-holds.php [--dry-run] [--force] [--reset-baseline]
 *
 * Per issue #17: a Hold that's been sitting on the calendar for two weeks
 * without moving forward gets automatically canceled (freeing the date),
 * with a warning email sent 48 hours ahead of time so whoever's holding the
 * date has a chance to advance it or reach out. The reporter explicitly
 * asked to delay turning this on for ~2 months after filing (to give staff
 * time to adjust), so it's gated behind HOLD_EXPIRY_ENABLED in .env — the
 * script (and the cron entry that runs it nightly) can ship now, inert,
 * and get switched on later with a one-line env change.
 *
 * Selects events where:
 *   - status = 'proposed' (Hold)
 *   - "hold started" — the most recent event_activity_log entry showing the
 *     status moving TO 'proposed', falling back to the row's created_at —
 *     is >= 12 days ago (warning window) or >= 14 days ago (expiry)
 *
 * A Hold at >= 14 days is flipped to status = 'canceled' (freeing the venue/
 * date the same way any other canceled event does — not hard-deleted, so
 * the record and its history stay intact) and logged. A Hold at >= 12 days
 * with no warning sent yet gets a "your hold expires in 48 hours" email to
 * the promoter/booker and is logged so it isn't re-sent daily.
 *
 * Designed to be invoked nightly from cron via scripts/cron-expire-holds.sh.
 *
 * IMPORTANT — before ever setting HOLD_EXPIRY_ENABLED=1: run
 *   php scripts/expire-holds.php --reset-baseline
 * once, by hand. As of 2026-07 there are ~70 open Holds in production, most
 * weeks old — without a baseline reset, the very first enabled run would
 * mass-cancel the large majority of them in one pass.
 *
 * Options:
 *   --dry-run         Report what would happen; write nothing, send nothing.
 *   --force            Bypass the HOLD_EXPIRY_ENABLED gate (for manual
 *                       testing only — cron never passes this).
 *   --reset-baseline   One-time: give every currently-open Hold a fresh
 *                       "started now" marker and exit (ignores the enabled
 *                       gate; run by hand, not from cron — see above).
 *   --event-id=N       Restrict the run to a single event id — for safely
 *                       testing/debugging one Hold without touching the
 *                       rest of the pipeline board.
 *
 * Exit codes:
 *   0  success (zero or more holds warned/expired, or feature disabled)
 *   1  configuration / database error
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Mailer;

use function Panic\log_activity;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$args          = array_slice($argv, 1);
$dryRun        = in_array('--dry-run', $args, true);
$force         = in_array('--force', $args, true);
$resetBaseline = in_array('--reset-baseline', $args, true);
$onlyEventId   = 0;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--event-id=')) {
        $onlyEventId = (int) substr($arg, strlen('--event-id='));
    }
}

$ts = fn () => date('Y-m-d H:i:s');

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, '[expire-holds] DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// ── One-time baseline reset ─────────────────────────────────────────────────
// Run this ONCE, by hand, the day this feature is switched on — NOT from
// cron. It gives every currently-open Hold a fresh "started now" marker (the
// same event_activity_log signal holdStartedAt() below reads), so pre-
// existing Holds get a full 14-day runway instead of being instantly
// canceled by their real (possibly months-old) creation date. Skipping this
// before flipping HOLD_EXPIRY_ENABLED on would mass-cancel every Hold
// already older than 2 weeks in one run — as of 2026-07, that's the large
// majority of the ~70 open Holds in production. See issue #17.
if ($resetBaseline) {
    $openHolds = $db->all("SELECT id FROM events WHERE status = 'proposed'");
    foreach ($openHolds as $row) {
        if ($dryRun) continue;
        log_activity($db, (int) $row['id'], null, 'status changed', [
            'changes' => [['field' => 'Status', 'from' => 'proposed', 'to' => 'proposed']],
            'note'    => 'hold-expiry baseline reset (issue #17 activation)',
        ]);
    }
    printf(
        "[%s] expire-holds: reset baseline for %d open hold(s)%s\n",
        $ts(),
        count($openHolds),
        $dryRun ? ' (dry run — nothing written)' : ''
    );
    exit(0);
}

if (!$force && !filter_var(getenv('HOLD_EXPIRY_ENABLED') ?: '', FILTER_VALIDATE_BOOLEAN)) {
    printf("[%s] expire-holds: HOLD_EXPIRY_ENABLED is not set — feature is off, nothing to do.\n", $ts());
    exit(0);
}

const WARNING_DAYS = 12; // send the 48h warning at day 12
const EXPIRY_DAYS  = 14; // cancel at day 14

// "Hold started" = the most recent time the status log shows it moving TO
// 'proposed', falling back to the row's created_at for a Hold that has
// never changed status (the common case — created straight into Hold).
$rows = $db->all(
    "SELECT e.id, e.title, e.date, e.created_at, e.promoter_name, e.promoter_email,
            e.booker_name, e.booker_email, v.name venue_name,
            COALESCE(
                (SELECT MAX(l.created_at) FROM event_activity_log l
                  WHERE l.event_id = e.id AND l.action = 'status changed'
                    AND (l.details_json LIKE '%\"to\":\"proposed\"%' OR l.details_json LIKE '%\"status\":\"proposed\"%')),
                e.created_at
            ) AS hold_started_at
     FROM events e
     LEFT JOIN venues v ON v.id = e.venue_id
     WHERE e.status = 'proposed'" . ($onlyEventId ? ' AND e.id = ' . $onlyEventId : '') . "
     ORDER BY e.date, e.id"
);

if (!$rows) {
    printf("[%s] expire-holds: nothing to do (0 holds)\n", $ts());
    exit(0);
}

$mailer = new Mailer($root, $db);
$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

$warned  = 0;
$expired = 0;

foreach ($rows as $row) {
    $id           = (int) $row['id'];
    $title        = (string) $row['title'];
    $holdStarted  = strtotime((string) $row['hold_started_at']);
    if (!$holdStarted) continue;
    $daysHeld = (int) floor((time() - $holdStarted) / 86400);

    $recipients = array_filter([
        $row['promoter_email'] ? ['name' => $row['promoter_name'] ?? 'there', 'email' => $row['promoter_email']] : null,
        $row['booker_email']   ? ['name' => $row['booker_name']   ?? 'there', 'email' => $row['booker_email']]   : null,
    ]);
    $link = "{$appUrl}/#event-{$id}";

    if ($daysHeld >= EXPIRY_DAYS) {
        if ($dryRun) {
            printf("[%s] would expire hold %d (%s, %s): %d days held -> canceled\n", $ts(), $id, $title, $row['date'], $daysHeld);
            $expired++;
            continue;
        }
        $db->run("UPDATE events SET status = 'canceled' WHERE id = ?", [$id]);
        log_activity($db, $id, null, 'status auto-canceled', [
            'from'      => 'proposed',
            'to'        => 'canceled',
            'reason'    => 'hold expired after ' . $daysHeld . ' days',
        ]);
        printf("[%s] expired hold %d (%s, %s): %d days held -> canceled\n", $ts(), $id, $title, $row['date'], $daysHeld);
        $expired++;

        foreach ($recipients as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) continue;
            $mailer->sendTemplate(
                $recipient['email'],
                '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Hold expired: {$title}",
                'hold-expired',
                [
                    'recipient_name' => htmlspecialchars((string) $recipient['name'], ENT_QUOTES, 'UTF-8'),
                    'event_name'     => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    'event_date'     => htmlspecialchars((string) $row['date'], ENT_QUOTES, 'UTF-8'),
                    'event_venue'    => htmlspecialchars((string) ($row['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
                ]
            );
        }
        continue;
    }

    if ($daysHeld >= WARNING_DAYS) {
        $alreadyWarned = (bool) $db->one(
            "SELECT id FROM event_activity_log WHERE event_id = ? AND action = 'hold expiry warning sent' LIMIT 1",
            [$id]
        );
        if ($alreadyWarned) {
            continue;
        }
        if ($dryRun) {
            printf("[%s] would warn hold %d (%s, %s): %d days held -> expires in %d day(s)\n", $ts(), $id, $title, $row['date'], $daysHeld, EXPIRY_DAYS - $daysHeld);
            $warned++;
            continue;
        }
        log_activity($db, $id, null, 'hold expiry warning sent', ['days_held' => $daysHeld]);
        printf("[%s] warned hold %d (%s, %s): %d days held\n", $ts(), $id, $title, $row['date'], $daysHeld);
        $warned++;

        foreach ($recipients as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) continue;
            $mailer->sendTemplate(
                $recipient['email'],
                '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Hold expires in 48 hours: {$title}",
                'hold-expiry-warning',
                [
                    'recipient_name' => htmlspecialchars((string) $recipient['name'], ENT_QUOTES, 'UTF-8'),
                    'event_name'     => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    'event_date'     => htmlspecialchars((string) $row['date'], ENT_QUOTES, 'UTF-8'),
                    'event_venue'    => htmlspecialchars((string) ($row['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
                ]
            );
        }
    }
}

printf(
    "[%s] expire-holds: %d warned, %d expired%s\n",
    $ts(),
    $warned,
    $expired,
    $dryRun ? ' (dry run)' : ''
);
exit(0);
