<?php
/**
 * Tests for src/Leads/ClaimService.php — only the guard that rejects an
 * unknown claim-preserving action before any DB access (validated first,
 * inside recordPreservingAction(), before the lead_claims lookup). The
 * claim/release/extend paths themselves are DB-writing and are exercised
 * via the ingestion-pipeline integration testing instead, same split as
 * RoutingEngine.
 *
 * Run with: php tests/leads_claim_service_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\ClaimService;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox claim service tests ===\n\n";

$expectedActions = [
    'sent_response', 'scheduled_tour', 'sent_availability',
    'logged_call', 'requested_information', 'manager_approved_followup_task',
];
ok(ClaimService::PRESERVING_ACTIONS === $expectedActions, "PRESERVING_ACTIONS matches the spec's fixed list exactly");

$service = new ClaimService();
$lead = ['id' => 999999];

$threw = false;
try {
    $service->recordPreservingAction(new Database(), $lead, 1, 'not_a_real_action');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
ok($threw, "An unknown action is rejected before any DB access (InvalidArgumentException)");

// Deliberately NOT calling recordPreservingAction() with a *valid* action
// here: past the guard it reaches real UPDATE statements (lead_claims,
// and — for 'sent_response' specifically — leads.first_response_at). Even
// against a nonexistent lead id those are real writes issued to this box's
// only database (production), so that path is covered by the ingestion
// integration testing instead, not a "hermetic" unit test.

echo "\nBooking Inbox claim service: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
