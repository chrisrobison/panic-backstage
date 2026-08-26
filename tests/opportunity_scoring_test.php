<?php
/**
 * Tests for Panic\Opportunities\Scoring::compute() — Phase 8's deterministic
 * opportunity scoring service (docs/OPPORTUNITIES-IMPLEMENTATION.md §4.8).
 * Only the pure compute() is exercised here (no DB); scoreForOpportunity()'s
 * context-gathering queries are covered by tests/opportunities_phase8_db_test.php.
 *
 * Run with: php tests/opportunity_scoring_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Opportunities\Scoring;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Opportunities\\Scoring::compute() ===\n\n";

// ── An empty/unknown context scores low but never crashes or goes negative ──
$result = Scoring::compute([]);
ok($result['score'] >= 0, 'empty context never produces a negative score');
ok(array_sum($result['components']) === $result['score'], 'score always equals the sum of its components');
ok($result['reasons'] === [], 'empty context produces no reasons (nothing to explain)');

// ── Every component is capped at its documented maximum ─────────────────────
$maxContext = [
    'conference_linked' => true, 'conference_score' => 100, 'conference_distance_miles' => 1.0,
    'conference_name' => 'GTC DC', 'company_role' => 'headline_sponsor', 'company_name' => 'NVIDIA',
    'signal_count' => 99, 'has_decision_maker' => true, 'has_primary_contact' => true,
    'target_date_status' => 'available', 'budget_fit_status' => 'within_range', 'guest_fit_status' => 'fits',
    'days_since_research' => 1, 'days_until' => 1,
];
$result = Scoring::compute($maxContext);
$caps = [
    'conference_relevance' => 20, 'company_participation' => 15, 'hospitality_signals' => 15,
    'buyer_identified' => 10, 'venue_date_availability' => 15, 'budget_value' => 10,
    'guest_venue_fit' => 5, 'research_freshness' => 5, 'urgency' => 5,
];
foreach ($caps as $key => $max) {
    ok(($result['components'][$key] ?? null) === $max, "$key hits its documented max ($max) under a best-case context");
}
ok($result['score'] === array_sum($caps), 'a best-case context sums to the full 100-point scale (' . array_sum($caps) . ')');
ok(count($result['reasons']) > 0 && count($result['reasons']) <= 6, 'a best-case context produces a short, capped reasons list');

// ── A worst-case (but not empty) context bottoms out every component ────────
$minContext = [
    'conference_linked' => false, 'company_role' => null, 'signal_count' => 0,
    'has_decision_maker' => false, 'has_primary_contact' => false,
    'target_date_status' => 'conflict', 'guest_fit_status' => 'no_fit',
];
$result = Scoring::compute($minContext);
ok($result['components']['conference_relevance'] === 0, 'no conference linked scores zero conference_relevance');
ok($result['components']['company_participation'] === 0, 'no participation role scores zero company_participation');
ok($result['components']['hospitality_signals'] === 0, 'zero signals scores zero hospitality_signals');
ok($result['components']['buyer_identified'] === 0, 'no contact/decision-maker scores zero buyer_identified');
ok($result['components']['venue_date_availability'] === 0, 'a date conflict scores zero venue_date_availability, not a mid-range unknown value');
ok($result['components']['guest_venue_fit'] === 0, 'no room fitting the guest count scores zero guest_venue_fit');

// ── "unknown" is a neutral middle value, not a penalty, where the spec allows it ──
$unknownContext = ['target_date_status' => 'unknown', 'budget_fit_status' => 'unknown', 'guest_fit_status' => 'unknown'];
$result = Scoring::compute($unknownContext);
ok($result['components']['venue_date_availability'] === 5, 'no target date set yet is neutral (5/15), not penalized like a real conflict (0)');
ok($result['components']['budget_value'] === 3, 'unknown budget fit is a low-but-not-zero neutral value');
ok($result['components']['guest_venue_fit'] === 2, 'unknown guest fit is a low-but-not-zero neutral value');

// ── Role weighting is monotonic with the spec's own participation hierarchy ─
$sponsorScore   = Scoring::compute(['company_role' => 'sponsor'])['components']['company_participation'];
$exhibitorScore = Scoring::compute(['company_role' => 'exhibitor'])['components']['company_participation'];
$attendeeScore  = Scoring::compute(['company_role' => 'attendee'])['components']['company_participation'];
ok($sponsorScore > $exhibitorScore && $exhibitorScore > $attendeeScore, 'sponsor > exhibitor > attendee in company_participation weight');

// ── Proximity bonus only applies once a conference is actually linked ───────
$near = Scoring::compute(['conference_linked' => true, 'conference_score' => 50, 'conference_distance_miles' => 2.0])['components']['conference_relevance'];
$far  = Scoring::compute(['conference_linked' => true, 'conference_score' => 50, 'conference_distance_miles' => 50.0])['components']['conference_relevance'];
ok($near > $far, 'a nearby conference scores higher conference_relevance than a distant one at the same opportunity_score');

// ── Urgency scales down as days_until grows ─────────────────────────────────
$soon = Scoring::compute(['days_until' => 5])['components']['urgency'];
$mid  = Scoring::compute(['days_until' => 45])['components']['urgency'];
$far2 = Scoring::compute(['days_until' => 200])['components']['urgency'];
ok($soon > $mid && $mid > $far2, 'urgency strictly decreases as days_until grows (5d > 45d > 200d)');

echo "\nScoring: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
