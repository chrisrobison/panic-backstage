<?php
/**
 * Tests for src/Leads/StatusMachine.php — the Booking Inbox's authoritative
 * status-transition validator (database/migrations/071_add_booking_inbox_core.sql
 * / 076_add_booking_inbox_audit.sql).
 *
 * Only exercises the gates that return before touching the database
 * (unknown status, no-op, terminal-without-override, reason-required) —
 * genuinely hermetic, no writes, no rows read. The apply()/isHighValue()
 * paths (which do read/write lead_status_history, lead_audit_log,
 * lead_inbox_settings) are covered by the curl-based integration suite
 * instead, same split as the rest of this test folder.
 *
 * Run with: php tests/leads_status_machine_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\StatusMachine;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox status machine tests ===\n\n";

$machine = new StatusMachine(new Database());
$baseLead = ['id' => 999999, 'status' => 'new', 'budget' => null];

// ── Unknown target status ────────────────────────────────────────────────────

$r = $machine->transition($baseLead, 'not_a_real_status', 1, null, false, false);
ok($r['ok'] === false && $r['code'] === 422, "Unknown status is rejected (422)");

// ── No-op transition ──────────────────────────────────────────────────────────

$r = $machine->transition($baseLead, 'new', 1, null, false, false);
ok($r['ok'] === true && ($r['unchanged'] ?? false) === true, "Same-status transition is a no-op success");

// ── Terminal status requires override to leave ───────────────────────────────

foreach (StatusMachine::TERMINAL_STATUSES as $terminal) {
    $lead = ['id' => 999999, 'status' => $terminal, 'budget' => null];
    $blocked = $machine->transition($lead, 'qualifying', 1, null, false, false);
    ok($blocked['ok'] === false && $blocked['code'] === 409,
       "Leaving terminal status '$terminal' without override is blocked (409)");
}

// A terminal status is not itself in the reason-required set unless it's
// also independently one (declined/lost/spam/duplicate/archived/canceled
// all happen to be terminal AND reason-required, but 'onboarded'/'booked'/
// 'converted' are terminal without requiring a reason to *enter* them).
// Asserted against the constants directly (not via transition()) since a
// fully-successful call would fall through to apply() and issue a real
// write — see the note at the bottom of this file.
foreach (['onboarded', 'booked', 'converted'] as $terminalNoReason) {
    ok(!in_array($terminalNoReason, StatusMachine::REASON_REQUIRED, true),
       "Entering terminal status '$terminalNoReason' does not require a reason");
}

// ── Reason required for declined/lost/spam/duplicate/archived/canceled ──────

foreach (StatusMachine::REASON_REQUIRED as $target) {
    $lead = ['id' => 999999, 'status' => 'qualifying', 'budget' => null];
    $noReason = $machine->transition($lead, $target, 1, '', false, false);
    ok($noReason['ok'] === false && $noReason['code'] === 422,
       "Transition to '$target' without a reason is rejected (422)");

    $noReasonNull = $machine->transition($lead, $target, 1, null, false, false);
    ok($noReasonNull['ok'] === false && $noReasonNull['code'] === 422,
       "Transition to '$target' with a null reason is rejected (422)");
}

// Deliberately NOT testing a fully-successful transition here (e.g. decline
// with override + a reason): once every gate passes, transition() calls
// apply(), which writes UPDATE leads / INSERT lead_status_history /
// lead_audit_log for real — against this box's only database, which is
// production. That path belongs in the curl-based integration suite against
// a real seeded lead, not here.

echo "\nBooking Inbox status machine: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
