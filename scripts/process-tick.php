<?php
declare(strict_types=1);

/**
 * The process runtime's scheduled heartbeat — Phase 2. Everything the
 * runtime does while a browser/request is attached (starting an instance,
 * completing a task, resuming a wait) happens synchronously in
 * src/Processes/Runtime/Engine.php. The two things that must happen with
 * *nobody* attached — a wait's timeout elapsing, a task going overdue — are
 * handled here, run periodically from cron (see cron-process-tick.sh),
 * exactly like scripts/expire-holds.php's day-old Hold sweep.
 *
 *   php scripts/process-tick.php [--dry-run]
 *
 * What it does, each run:
 *   1. Every process_waits row still 'waiting' whose timeout_at has passed
 *      gets resumed via Engine::timeoutWait() — which follows the node's
 *      'timeout' edge if the graph defines one, or completes the instance
 *      cleanly if it doesn't (same dead-end handling as everywhere else in
 *      the engine).
 *   2. Every open process_tasks row whose due_at has passed and hasn't been
 *      marked escalated yet gets escalated_at stamped and its instance
 *      flagged 'overdue' (a real task never auto-completes itself — a human
 *      still has to act — this only surfaces it as overdue/escalated).
 *
 * Idempotent by construction: both queries are `WHERE ... AND <not already
 * handled>`, and Engine::timeoutWait()'s own conditional UPDATE means a
 * duplicate/overlapping tick run is a no-op for rows another run already
 * claimed.
 *
 * Exit codes:
 *   0  success (zero or more waits/tasks processed)
 *   1  configuration / database error
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Processes\CenterStage\BookingHandlers;
use Panic\Processes\Runtime\Engine;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$dryRun = in_array('--dry-run', $argv, true);

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$engine = new Engine($db, BookingHandlers::registry());

// ── 1. Timed-out waits ────────────────────────────────────────────────────
$dueWaits = $db->all(
    "SELECT id, process_instance_id, node_id FROM process_waits WHERE status = 'waiting' AND timeout_at IS NOT NULL AND timeout_at <= NOW()"
);
echo count($dueWaits) . " wait(s) past their timeout.\n";
foreach ($dueWaits as $wait) {
    if ($dryRun) {
        echo "  [dry-run] would time out wait #{$wait['id']} (instance #{$wait['process_instance_id']}, node {$wait['node_id']})\n";
        continue;
    }
    try {
        $engine->timeoutWait((int) $wait['id']);
        echo "  timed out wait #{$wait['id']} (instance #{$wait['process_instance_id']}, node {$wait['node_id']})\n";
    } catch (\Throwable $e) {
        echo "  FAILED wait #{$wait['id']}: {$e->getMessage()}\n";
    }
}

// ── 2. Overdue human tasks ────────────────────────────────────────────────
$overdueTasks = $db->all(
    "SELECT id, process_instance_id, title FROM process_tasks WHERE status = 'open' AND due_at IS NOT NULL AND due_at <= NOW() AND escalated_at IS NULL"
);
echo count($overdueTasks) . " task(s) newly overdue.\n";
foreach ($overdueTasks as $task) {
    if ($dryRun) {
        echo "  [dry-run] would escalate task #{$task['id']} \"{$task['title']}\" (instance #{$task['process_instance_id']})\n";
        continue;
    }
    $db->run("UPDATE process_tasks SET escalated_at = NOW() WHERE id = ?", [$task['id']]);
    $db->run("UPDATE process_instances SET status = 'overdue' WHERE id = ? AND status IN ('waiting', 'active')", [$task['process_instance_id']]);
    $db->run(
        "INSERT INTO process_instance_events (process_instance_id, node_id, event_type, label, detail, actor, created_at)
         VALUES (?, NULL, 'note', ?, 'Past due date with no action taken.', 'system (scheduled job)', NOW())",
        [$task['process_instance_id'], 'Task overdue: ' . $task['title']]
    );
    echo "  escalated task #{$task['id']} \"{$task['title']}\" (instance #{$task['process_instance_id']})\n";
}

echo "process-tick done.\n";
