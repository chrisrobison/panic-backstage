<?php
/**
 * Tests for src/Leads/Classifier.php — the Booking Inbox's AI classification
 * scoring/gating logic (database/migrations/074_add_booking_inbox_classification.sql).
 *
 * Hermetic: the constructor's $enabled param is forced explicitly (rather
 * than left null to auto-detect via ClaudeCli::isAvailable(), which would
 * make these tests depend on whether a real `claude` binary happens to be
 * installed on the box running them), so classify() short-circuits before
 * ever spawning a subprocess or touching the DB. score() is a pure function
 * over already-extracted data.
 *
 * Run with: php tests/leads_classifier_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\Classifier;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox classifier tests ===\n\n";

// ── isEnabled() / classify() short-circuit when disabled ────────────────────

$disabled = new Classifier(false);
ok($disabled->isEnabled() === false, "enabled=false => isEnabled() is false");

$result = $disabled->classify(new Database(), 999999, 'Some inquiry text');
ok($result === null, "classify() returns null (never touches the DB) when disabled");

$enabled = new Classifier(true);
ok($enabled->isEnabled() === true, "enabled=true => isEnabled() is true");

$emptyBody = $enabled->classify(new Database(), 999999, '   ');
ok($emptyBody === null, "classify() returns null for blank body text without calling the model");

// ── score(): explainable 0-100 heuristic ─────────────────────────────────────

$c = new Classifier(false);

ok($c->score([], null, 0.9) === 0, "High spam probability (>=0.7) forces score to 0");
ok($c->score([], null, 0.69) > 0, "Just-under-threshold spam probability does not zero the score");

$baseline = $c->score([], null, null);
ok($baseline === 40, "No signals at all => baseline score of 40");

$highValue = $c->score(['likely_booking_value' => 10000], null, null);
ok($highValue === 70, "likely_booking_value=10000 adds the full +30 value component (40+30)");

$capped = $c->score(['likely_booking_value' => 999999], null, null);
ok($capped <= 100, "Score never exceeds 100 regardless of an extreme likely_booking_value");

$urgent = $c->score(['urgency' => 'high'], null, null);
ok($urgent === 55, "urgency=high adds +15 (40+15)");

$medium = $c->score(['urgency' => 'medium'], null, null);
ok($medium === 48, "urgency=medium adds +8 (40+8)");

$confident = $c->score([], 1.0, null);
ok($confident === 55, "overall_confidence=1.0 adds +15 (40+15)");

$everything = $c->score(['likely_booking_value' => 6000, 'urgency' => 'high'], 1.0, 0.1);
ok($everything === 100, "Strong signals across the board saturate at 100");

ok($c->score([], null, null) >= 0, "Score is never negative");

echo "\nBooking Inbox classifier: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
