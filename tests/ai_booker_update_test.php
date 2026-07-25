<?php
/**
 * Hermetic tests for the pure logic in Panic\Ai\BookerUpdate
 * (src/Ai/BookerUpdate.php) — the field-allowlist guardrail behind the AI
 * Assistant's propose_booker_update tool (see docs/AI-ASSISTANT-PLAN.md).
 *
 * DB-touching methods (matchEvents(), apply()) are covered separately in
 * tests/ai_phase2_db_test.php (opt-in, needs a real MySQL connection).
 * This file only exercises disallowedFields()/sanitizeFields()/buildDiff(),
 * none of which touch the database — no bootstrap DB connection needed.
 *
 * Run with: php tests/ai_booker_update_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Ai\BookerUpdate;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Ai\\BookerUpdate ===\n\n";

// ── disallowedFields(): the field-allowlist guardrail ───────────────────────
ok(BookerUpdate::disallowedFields(['promoter_name' => 'x', 'booker_email' => 'y']) === [],
    'only-allowed keys: nothing disallowed');
ok(BookerUpdate::disallowedFields(['status' => 'canceled']) === ['status'],
    'a single disallowed key (status) is reported');
ok(
    array_values(BookerUpdate::disallowedFields(['promoter_name' => 'x', 'status' => 'canceled', 'ticket_price' => 0])) === ['status', 'ticket_price'],
    'a mix of allowed + disallowed keys reports only the disallowed ones, in input order'
);
ok(BookerUpdate::disallowedFields([]) === [], 'empty fields: nothing disallowed');

// ── sanitizeFields(): strips anything not in ALLOWED_FIELDS, trims strings ──
$sanitized = BookerUpdate::sanitizeFields([
    'promoter_name' => '  Zingflower Presents  ',
    'status' => 'canceled', // must never survive sanitizeFields, even if present
    'booker_email' => 'new@example.com',
]);
ok($sanitized === ['promoter_name' => 'Zingflower Presents', 'booker_email' => 'new@example.com'],
    'sanitizeFields() strips disallowed keys and trims allowed string values');
ok(BookerUpdate::sanitizeFields(['status' => 'canceled', 'delete_event' => true]) === [],
    'sanitizeFields() with ONLY disallowed keys returns empty — nothing sneaks through');
ok(BookerUpdate::sanitizeFields([]) === [], 'sanitizeFields() on empty input returns empty');

// Order is always ALLOWED_FIELDS' own order, not the input's — keeps diffs
// (and the UPDATE ... SET clause built from the same keys) deterministic.
$orderCheck = BookerUpdate::sanitizeFields(['booker_email' => 'b@x.com', 'promoter_name' => 'A']);
ok(array_keys($orderCheck) === ['promoter_name', 'booker_email'],
    'sanitizeFields() output key order follows ALLOWED_FIELDS, not input order');

// ── buildDiff(): before/after per matched event, sanitized fields only ──────
$events = [
    ['id' => 101, 'title' => 'Zingflower Night', 'promoter_name' => 'Old Promoter', 'promoter_email' => 'old@x.com'],
    ['id' => 102, 'title' => 'Another Zingflower Show', 'promoter_name' => null, 'promoter_email' => null],
];
$diff = BookerUpdate::buildDiff($events, ['promoter_name' => 'New Promoter', 'status' => 'canceled']);
ok(count($diff) === 2, 'buildDiff() returns one row per matched event');
ok($diff[0]['event_id'] === 101 && $diff[0]['title'] === 'Zingflower Night',
    'buildDiff() row carries the event id + title through');
ok($diff[0]['fields'] === ['promoter_name' => ['before' => 'Old Promoter', 'after' => 'New Promoter']],
    'buildDiff() only diffs sanitized (allowlisted) fields — "status" never appears');
ok($diff[1]['fields']['promoter_name']['before'] === null,
    'buildDiff() reports a null "before" for an event with no existing value, rather than erroring');
ok(BookerUpdate::buildDiff([], ['promoter_name' => 'X']) === [], 'buildDiff() on no matched events returns empty');

echo "\nAi\\BookerUpdate: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
