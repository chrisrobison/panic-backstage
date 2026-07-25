<?php
declare(strict_types=1);

/**
 * The Booking Inbox's scheduled SLA sweep — everything that must happen
 * with *nobody* attached: an assigned-but-unclaimed inquiry passing its
 * claim deadline, a claimed-but-unanswered inquiry passing its response
 * deadline. Run periodically from cron (see cron-lead-sla-tick.sh), same
 * shape as scripts/process-tick.php (the Automation module's equivalent
 * sweep) and scripts/expire-holds.php.
 *
 *   php scripts/lead-sla-tick.php [--dry-run]
 *
 * What it does, each run:
 *   1. Every lead still 'assigned' (not yet claimed) whose sla_claim_due_at
 *      has passed is returned to the unassigned queue: assigned_to_user_id
 *      is cleared, status drops back to 'classified', and the miss is
 *      logged — "unclaimed inquiry returns to the queue after the claim
 *      deadline."
 *   2. Every active claim (lead_claims.status='active') whose expires_at
 *      has passed is released via ClaimService::release() with
 *      source='automation' — "claimed but unanswered inquiry is released
 *      ... after the response deadline."
 *
 * Idempotent by construction: both sweeps are `WHERE ... AND <not already
 * past this state>`, so an overlapping/duplicate run is a no-op for rows
 * another run already moved past the deadline.
 *
 * This script only ever touches Booking Inbox rows (leads/lead_claims) — it
 * is intentionally NOT wired into the live crontab as part of this change;
 * see the crontab line in docs/booking-inbox.md for the operator to add
 * deliberately once ready.
 *
 * Exit codes:
 *   0  success (zero or more leads processed)
 *   1  configuration / database error
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\ClaimService;
use function Panic\log_lead_activity;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$dryRun = in_array('--dry-run', $argv, true);

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$claims = new ClaimService();

// ── 1. Assigned but unclaimed, past the claim deadline ──────────────────────
$overdueClaims = $db->all(
    "SELECT id, assigned_to_user_id FROM leads
     WHERE status = 'assigned' AND claimed_by_user_id IS NULL
       AND sla_claim_due_at IS NOT NULL AND sla_claim_due_at <= NOW()"
);
echo count($overdueClaims) . " assigned lead(s) past their claim deadline.\n";
foreach ($overdueClaims as $lead) {
    if ($dryRun) {
        echo "  [dry-run] would return lead #{$lead['id']} to the unassigned queue\n";
        continue;
    }
    $db->run("UPDATE leads SET assigned_to_user_id = NULL, status = 'classified' WHERE id = ?", [$lead['id']]);
    log_lead_activity($db, (int) $lead['id'], null, 'claim_deadline_missed', [
        'was_assigned_to_user_id' => $lead['assigned_to_user_id'],
    ]);
    echo "  returned lead #{$lead['id']} to the unassigned queue\n";
}

// ── 2. Active claims past the response deadline ─────────────────────────────
$expiredClaims = $db->all(
    "SELECT l.* FROM leads l
     JOIN lead_claims c ON c.lead_id = l.id AND c.status = 'active'
     WHERE c.expires_at IS NOT NULL AND c.expires_at <= NOW()"
);
echo count($expiredClaims) . " active claim(s) past the response deadline.\n";
foreach ($expiredClaims as $lead) {
    if ($dryRun) {
        echo "  [dry-run] would release the expired claim on lead #{$lead['id']}\n";
        continue;
    }
    $claims->release($db, $lead, null, 'Response deadline passed with no claim-preserving action.', 'automation');
    echo "  released expired claim on lead #{$lead['id']}\n";
}

echo "lead-sla-tick done.\n";
