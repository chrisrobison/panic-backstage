<?php
/**
 * Tests for the booking status gate (contract + deposit requirement).
 *
 * Exercises Events::bookingGateBlockers() directly — the pure helper
 * extracted from Events::validateStatusTransition()'s "booked" gate — so
 * this test can never silently drift from the real implementation the way a
 * local reimplementation could.
 *
 * Run with: php tests/booking_gate_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Events;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking gate logic tests (Events::bookingGateBlockers) ===\n\n";

/** True when $contracts contains a signed/fully_executed row. */
function hasExecutedContract(array $contracts): bool
{
    foreach ($contracts as $c) {
        if (in_array($c['status'], ['signed', 'fully_executed'], true)) {
            return true;
        }
    }
    return false;
}

// ── 1. No contract, no deposit → blocked ──────────────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'requested'];
$contracts = [];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(count($blocks) === 2, "No contract, no deposit: two blockers (contract + deposit)");

// ── 2. Signed contract, no deposit → still blocked ────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'requested'];
$contracts = [['status' => 'signed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(count($blocks) === 1, "Signed contract: contract gate passes");
ok(str_contains($blocks[0] ?? '', 'Deposit'), "Deposit requested: still blocked on deposit");

// ── 3. Sent contract (not signed) → blocked on contract ──────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'sent']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(count($blocks) === 1, "Sent (not signed) contract: contract gate blocks");

// ── 4. Draft contract → blocked ───────────────────────────────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'draft']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(count($blocks) === 1, "Draft contract: contract gate blocks");

// ── 5. Signed contract + received deposit → allowed ──────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'received'];
$contracts = [['status' => 'signed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(empty($blocks), "Signed contract + received deposit: booking allowed");

// ── 6. Signed contract + waived deposit → allowed ────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'waived'];
$contracts = [['status' => 'signed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(empty($blocks), "Signed contract + waived deposit: booking allowed");

// ── 7. Legacy event (contract_url set, no deposit required) → allowed ─────────
$event     = ['contract_url' => 'https://example.com/contract.pdf', 'deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(empty($blocks), "Legacy contract_url + no deposit: booking allowed (backward compat)");

// ── 8. Partially received deposit → blocked ───────────────────────────────────
$event     = ['deposit_amount' => 500, 'deposit_status' => 'partially_received'];
$contracts = [['status' => 'signed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(count($blocks) === 1 && str_contains($blocks[0], 'partially'), "Partial deposit: still blocked");

// ── 9. fully_executed contract → allowed ─────────────────────────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'fully_executed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(empty($blocks), "fully_executed contract: booking allowed");

// ── 10. Deposit not_required with no deposit amount → allowed ────────────────
$event     = ['deposit_amount' => 0, 'deposit_status' => 'not_required'];
$contracts = [['status' => 'signed']];
$blocks    = Events::bookingGateBlockers($event, hasExecutedContract($contracts));
ok(empty($blocks), "No deposit required: deposit gate skipped");

// ── 11. Private event: contract-missing message differs from public event ────
$event  = ['event_type' => 'private_event', 'deposit_amount' => 0, 'deposit_status' => 'not_required'];
$blocks = Events::bookingGateBlockers($event, false);
ok(count($blocks) === 1 && str_contains($blocks[0], 'rental contract'), "Private event: private-specific contract message");

$event  = ['event_type' => 'live_music', 'deposit_amount' => 0, 'deposit_status' => 'not_required'];
$blocks = Events::bookingGateBlockers($event, false);
ok(count($blocks) === 1 && str_contains($blocks[0], 'Fully executed'), "Public event: public-specific contract message");

echo "\nBooking gate: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
