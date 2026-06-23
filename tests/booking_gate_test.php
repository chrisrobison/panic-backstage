<?php
/**
 * Tests for the booking status gate (contract + deposit requirement).
 *
 * Tests the logic that blocks events from entering 'booked' status without
 * a fully executed contract and received/waived deposit.
 *
 * Run with: php tests/booking_gate_test.php
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking gate logic tests ===\n\n";

/**
 * Simulate the booking gate logic from Events::validateStatusTransition().
 * Returns array of blocking messages (empty = allowed).
 */
function checkBookingGate(array $event, array $contracts): array
{
    $missing = [];

    // Contract gate: must be signed/fully_executed
    $hasContractUrl      = !empty($event['contract_url']);
    $hasExecutedContract = false;
    foreach ($contracts as $c) {
        if (in_array($c['status'], ['signed','fully_executed'], true)) {
            $hasExecutedContract = true;
            break;
        }
    }
    if (!$hasContractUrl && !$hasExecutedContract) {
        $missing[] = 'contract_missing';
    }

    // Deposit gate
    $depositStatus   = $event['deposit_status'] ?? 'not_required';
    $depositRequired = ($event['deposit_amount'] ?? 0) > 0;

    if ($depositRequired && !in_array($depositStatus, ['received','waived','not_required'], true)) {
        $missing[] = 'deposit_not_received';
    }

    return $missing;
}

// ── 1. No contract, no deposit → blocked ──────────────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'requested'];
$contracts = [];
$blocks    = checkBookingGate($event, $contracts);
ok(in_array('contract_missing', $blocks),     "No contract: contract_missing block");
ok(in_array('deposit_not_received', $blocks), "No deposit: deposit_not_received block");

// ── 2. Signed contract, no deposit → still blocked ────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'requested'];
$contracts = [['status' => 'signed']];
$blocks    = checkBookingGate($event, $contracts);
ok(!in_array('contract_missing', $blocks),    "Signed contract: contract gate passes");
ok(in_array('deposit_not_received', $blocks), "Deposit requested: still blocked");

// ── 3. Sent contract (not signed) → blocked on contract ──────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'sent']];
$blocks    = checkBookingGate($event, $contracts);
ok(in_array('contract_missing', $blocks), "Sent (not signed) contract: contract gate blocks");

// ── 4. Draft contract → blocked ───────────────────────────────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'draft']];
$blocks    = checkBookingGate($event, $contracts);
ok(in_array('contract_missing', $blocks), "Draft contract: contract gate blocks");

// ── 5. Signed contract + received deposit → allowed ──────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'received'];
$contracts = [['status' => 'signed']];
$blocks    = checkBookingGate($event, $contracts);
ok(empty($blocks), "Signed contract + received deposit: booking allowed");

// ── 6. Signed contract + waived deposit → allowed ────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'waived'];
$contracts = [['status' => 'signed']];
$blocks    = checkBookingGate($event, $contracts);
ok(empty($blocks), "Signed contract + waived deposit: booking allowed");

// ── 7. Legacy event (contract_url set, no deposit required) → allowed ─────────
$event     = ['contract_url' => 'https://example.com/contract.pdf', 'deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [];
$blocks    = checkBookingGate($event, $contracts);
ok(empty($blocks), "Legacy contract_url + no deposit: booking allowed (backward compat)");

// ── 8. Partially received deposit → blocked ───────────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'partially_received'];
$contracts = [['status' => 'signed']];
$blocks    = checkBookingGate($event, $contracts);
ok(in_array('deposit_not_received', $blocks), "Partial deposit: still blocked");

// ── 9. fully_executed contract → allowed ─────────────────────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'fully_executed']];
$blocks    = checkBookingGate($event, $contracts);
ok(empty($blocks), "fully_executed contract: booking allowed");

// ── 10. Deposit not_required with no deposit amount → allowed ────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'signed']];
$blocks    = checkBookingGate($event, $contracts);
ok(empty($blocks), "No deposit required: deposit gate skipped");

echo "\nBooking gate: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
