<?php
/**
 * Tests for src/Leads/RoutingEngine.php's pure condition-matching logic
 * (database/migrations/075_add_booking_inbox_routing.sql /
 * 081_seed_booking_inbox_routing_rules.sql).
 *
 * Hermetic: RoutingEngine::matches() takes plain arrays and returns a bool —
 * no DB access. The DB-writing half (route()/assign(), which reads
 * routing_rule_versions and writes lead_assignments/lead_audit_log) is
 * exercised by actually running the ingestion pipeline end-to-end instead
 * (see the Phase 3/4 commit messages) rather than here, for the same
 * production-data-safety reason every other DB-touching test in this folder
 * is opt-in only.
 *
 * Run with: php tests/leads_routing_engine_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Leads\RoutingEngine;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox routing engine tests ===\n\n";

$engine = new RoutingEngine();

// ── Category/genre containment matching (free-text model output) ───────────

ok($engine->matches(['music_genre_in' => ['punk', 'ska']], ['music_genre' => 'punk/ska'], 0.9),
   "Compound genre 'punk/ska' matches an allow-list of ['punk','ska'] (substring containment)");

ok($engine->matches(['music_genre_in' => ['metal', 'hardcore']], ['music_genre' => 'hardcore punk'], 0.9),
   "'hardcore punk' matches an allow-list containing 'hardcore'");

ok(!$engine->matches(['music_genre_in' => ['metal', 'hardcore']], ['music_genre' => 'jazz'], 0.9),
   "'jazz' does not match a metal/hardcore allow-list");

ok(!$engine->matches(['event_category_in' => ['comedy']], ['event_category' => null], 0.9),
   "A null/empty field never matches a non-empty allow-list");

// ── Numeric range conditions ─────────────────────────────────────────────────

ok($engine->matches(['min_attendance' => 100], ['attendance' => 150], null), "attendance 150 >= min_attendance 100 matches");
ok(!$engine->matches(['min_attendance' => 100], ['attendance' => 50], null), "attendance 50 < min_attendance 100 does not match");
ok($engine->matches(['max_attendance' => 500], ['attendance' => 200], null), "attendance 200 <= max_attendance 500 matches");
ok(!$engine->matches(['max_attendance' => 500], ['attendance' => 600], null), "attendance 600 > max_attendance 500 does not match");
ok($engine->matches(['min_budget' => 1000], ['budget' => 2000], null), "budget 2000 >= min_budget 1000 matches");
ok(!$engine->matches(['min_budget' => 1000], ['budget' => 500], null), "budget 500 < min_budget 1000 does not match");

// ── Confidence gates ──────────────────────────────────────────────────────────

ok($engine->matches(['min_confidence' => 0.7], [], 0.85), "confidence 0.85 clears min_confidence 0.7");
ok(!$engine->matches(['min_confidence' => 0.7], [], 0.5), "confidence 0.5 fails min_confidence 0.7");
ok(!$engine->matches(['min_confidence' => 0.7], [], null), "no classification at all fails a min_confidence gate");

ok($engine->matches(['max_confidence' => 0.5], [], 0.3), "confidence 0.3 is caught by the low-confidence rule (max_confidence 0.5)");
ok(!$engine->matches(['max_confidence' => 0.5], [], 0.9), "confidence 0.9 is NOT caught by the low-confidence rule");
ok($engine->matches(['max_confidence' => 0.5], [], null),
   "no classification at all IS caught by the low-confidence catch-all (exactly the ambiguous case it exists for)");

// ── No conditions at all always matches (the bare fallback shape) ──────────

ok($engine->matches([], [], null), "An empty condition set matches unconditionally");

// ── Combined AND semantics ──────────────────────────────────────────────────

$combined = ['event_category_in' => ['concert'], 'min_attendance' => 100];
ok($engine->matches($combined, ['event_category' => 'concert', 'attendance' => 150], null), "Both conditions satisfied => match");
ok(!$engine->matches($combined, ['event_category' => 'concert', 'attendance' => 50], null), "One condition fails => no match, even though category matched");

echo "\nBooking Inbox routing engine: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
