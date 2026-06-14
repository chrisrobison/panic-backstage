<?php
declare(strict_types=1);

/**
 * Auto-archive past events and send settlement notifications.
 *
 *   php scripts/auto-complete-events.php [--dry-run]
 *
 * Selects events where:
 *   - date < CURDATE() (strictly past; protects today's still-running show)
 *   - status is any active pre-completion status (proposed, confirmed, booked,
 *     needs_assets, ready_to_announce, published, advanced)
 *
 * Flips them to status = 'completed' (displayed as "Archived") and writes one
 * event_activity_log row per event. Then sends a settlement notification email
 * to all venue_admins so the settlement process can begin.
 *
 * Designed to be invoked nightly from cron via scripts/cron-auto-complete.sh.
 *
 * Exit codes:
 *   0  success (zero or more events flipped)
 *   1  configuration / database error
 *
 * Output: one line per affected event, plus a final tally.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Mailer;

use function Panic\log_activity;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, '[auto-complete] DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$ts = fn () => date('Y-m-d H:i:s');

// All active statuses that should auto-archive when the event date passes.
// 'settled' and 'completed' are already done; 'canceled' is intentionally skipped.
$activeStatuses = ['proposed', 'confirmed', 'booked', 'needs_assets', 'ready_to_announce', 'published', 'advanced'];
$placeholders   = implode(',', array_fill(0, count($activeStatuses), '?'));

$rows = $db->all(
    "SELECT e.id, e.title, e.date, e.status, e.show_time, v.name venue_name
     FROM events e
     LEFT JOIN venues v ON v.id = e.venue_id
     WHERE e.date < CURDATE()
       AND e.status IN ({$placeholders})
     ORDER BY e.date, e.id",
    $activeStatuses
);

if (!$rows) {
    printf("[%s] auto-complete: nothing to do (0 events)\n", $ts());
    exit(0);
}

// Venue admins receive settlement notifications
$admins = $db->all(
    "SELECT name, email FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'"
);
$mailer = new Mailer($root);
$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

$flipped = 0;
foreach ($rows as $row) {
    $id       = (int) $row['id'];
    $from     = (string) $row['status'];
    $date     = (string) $row['date'];
    $title    = (string) $row['title'];
    $venue    = (string) ($row['venue_name'] ?? 'Mabuhay Gardens');

    if ($dryRun) {
        printf("[%s] would archive event %d (%s, %s): %s -> completed\n", $ts(), $id, $title, $date, $from);
        $flipped++;
        continue;
    }

    try {
        $db->run('UPDATE events SET status = ? WHERE id = ?', ['completed', $id]);
        log_activity($db, $id, null, 'status auto-completed', [
            'from' => $from,
            'to'   => 'completed',
            'date' => $date,
        ]);
        printf("[%s] archived event %d (%s, %s): %s -> completed\n", $ts(), $id, $title, $date, $from);
        $flipped++;

        // Send settlement notification to venue_admins
        // NOTE: OQ-5 — when a dedicated settlement email list is decided,
        // replace $admins here with the configured recipient list.
        if ($admins) {
            $link    = "{$appUrl}/#event-{$id}";
            $subject = "[Backstage] Settlement needed: {$title}";
            $showTime = '';
            if (!empty($row['show_time'])) {
                $t = strtotime((string) $row['show_time']);
                $showTime = $t ? date('g:i A', $t) : (string) $row['show_time'];
            }
            $vars = [
                'event_name'      => htmlspecialchars($title,     ENT_QUOTES, 'UTF-8'),
                'event_date'      => htmlspecialchars($date,      ENT_QUOTES, 'UTF-8'),
                'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                'event_venue'     => htmlspecialchars($venue,     ENT_QUOTES, 'UTF-8'),
                'event_admin_url' => htmlspecialchars($link,      ENT_QUOTES, 'UTF-8'),
                'old_status'      => htmlspecialchars(ucwords(str_replace('_', ' ', $from)), ENT_QUOTES, 'UTF-8'),
                'new_status'      => 'Archived (needs settlement)',
                'status_color'    => '#d97706',
                'promoter_name'   => '—',
                'booker_name'     => '—',
            ];
            foreach ($admins as $admin) {
                try {
                    $mailer->sendTemplate($admin['email'], $subject, 'status-changed', $vars);
                } catch (\Throwable $mailErr) {
                    @error_log("[auto-complete] settlement email failed for event {$id}: " . $mailErr->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[{$ts()}] event {$id} update failed: " . $e->getMessage() . "\n");
    }
}

printf("[%s] auto-complete: %d event(s) %s\n", $ts(), $flipped, $dryRun ? 'would be archived (dry run)' : 'archived');
exit(0);
