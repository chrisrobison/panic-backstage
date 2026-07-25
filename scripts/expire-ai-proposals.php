<?php
declare(strict_types=1);

/**
 * Sweep stale AI Assistant proposals: any ai_action_proposals row still
 * status='pending' whose expires_at has already passed gets flipped to
 * 'expired'.
 *
 *   php scripts/expire-ai-proposals.php [--dry-run]
 *
 * Why this exists: src/Ai/Assistant.php::applyProposal() already refuses to
 * apply (and flips to 'expired') a stale proposal the moment someone tries
 * — that's the actual security guarantee, and it doesn't depend on this
 * script ever running. What this script cleans up is the case where nobody
 * ever tries: a proposal a user asked for, then ignored, would otherwise
 * sit as status='pending' forever even though its 30-minute window is long
 * past — this keeps `ai_action_proposals.status` an honest reflection of
 * reality (e.g. for anyone reviewing it in Admin > Database browser) rather
 * than relying entirely on the lazy apply-time check. See
 * docs/AI-ASSISTANT-PLAN.md's proposal-expiry note.
 *
 * Modeled on scripts/expire-holds.php's shape (dry-run flag, timestamped
 * one-line-per-run log output, plain exit codes) but much simpler — there's
 * no warning email step and no enable/disable gate, since flipping a
 * genuinely-expired row's status has no user-facing side effect at all (the
 * apply route already refused it either way).
 *
 * Designed to be invoked periodically from cron via
 * scripts/cron-expire-ai-proposals.sh.
 *
 * Exit codes:
 *   0  success (zero or more proposals expired)
 *   1  configuration / database error
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$ts = fn () => date('Y-m-d H:i:s');

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, '[expire-ai-proposals] DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$stale = $db->all(
    "SELECT id, tool_name, expires_at FROM ai_action_proposals WHERE status = 'pending' AND expires_at < NOW()"
);

if (!$stale) {
    printf("[%s] expire-ai-proposals: nothing to do (0 stale pending proposals)\n", $ts());
    exit(0);
}

if ($dryRun) {
    foreach ($stale as $row) {
        printf(
            "[%s] would expire proposal %d (%s, expired %s)\n",
            $ts(),
            (int) $row['id'],
            $row['tool_name'],
            $row['expires_at']
        );
    }
    printf("[%s] expire-ai-proposals: %d proposal(s) would be expired (dry run)\n", $ts(), count($stale));
    exit(0);
}

$ids = array_map(static fn(array $row): int => (int) $row['id'], $stale);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$db->run("UPDATE ai_action_proposals SET status = 'expired' WHERE id IN ({$placeholders})", $ids);

printf("[%s] expire-ai-proposals: expired %d stale pending proposal(s)\n", $ts(), count($ids));
exit(0);
