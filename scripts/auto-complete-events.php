<?php
declare(strict_types=1);

/**
 * Auto-complete past confirmed events.
 *
 *   php scripts/auto-complete-events.php [--dry-run]
 *
 * Selects events where:
 *   - date < CURDATE() (strictly past; protects today's still-running show)
 *   - status = 'confirmed'
 *
 * Flips them to status = 'completed' and writes one event_activity_log row
 * per event with a system actor (user_id NULL). Designed to be invoked
 * nightly from cron via scripts/cron-auto-complete.sh.
 *
 * Exit codes:
 *   0  success (zero or more events flipped)
 *   1  configuration / database error
 *
 * Output: one line per affected event, plus a final tally. Always emits the
 * tally so cron logs are easy to skim.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;

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

$rows = $db->all(
    "SELECT id, title, date, status
     FROM events
     WHERE date < CURDATE()
       AND status = 'confirmed'
     ORDER BY date, id"
);

if (!$rows) {
    printf("[%s] auto-complete: nothing to do (0 events)\n", $ts());
    exit(0);
}

$flipped = 0;
foreach ($rows as $row) {
    $id   = (int) $row['id'];
    $from = (string) $row['status'];
    $date = (string) $row['date'];
    $title = (string) $row['title'];

    if ($dryRun) {
        printf("[%s] would update event %d (%s, %s): %s -> completed\n", $ts(), $id, $title, $date, $from);
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
        printf("[%s] event %d (%s, %s): %s -> completed\n", $ts(), $id, $title, $date, $from);
        $flipped++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "[{$ts()}] event {$id} update failed: " . $e->getMessage() . "\n");
    }
}

printf("[%s] auto-complete: %d event(s) %s\n", $ts(), $flipped, $dryRun ? 'would be updated (dry run)' : 'updated');
exit(0);
