<?php
/**
 * Tests for the recurring-events 90-day booking-horizon cap
 * (src/Events/Series.php::horizonCutoff() / ::datesBeyondHorizon()).
 *
 * Pure date math — no DB, no bootstrap. Hermetic; picked up automatically
 * by tests/run-php-tests.sh.
 *
 * Run with: php tests/events_series_horizon_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Events\Series;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Series 90-day horizon cap ===\n\n";

// ── horizonCutoff() is exactly today + 90 days ──────────────────────────────
$expected = (new DateTimeImmutable('today'))->modify('+90 days')->format('Y-m-d');
ok(Series::horizonCutoff() === $expected, "horizonCutoff() is today+90d ({$expected})");

// ── datesBeyondHorizon(): dates on/before the cutoff are kept, none beyond ──
$cutoff = '2026-10-22';
ok(Series::datesBeyondHorizon(['2026-10-22'], $cutoff) === [],
    'a date exactly on the cutoff is NOT beyond horizon (inclusive)');
ok(Series::datesBeyondHorizon(['2026-10-21'], $cutoff) === [],
    'a date one day before the cutoff is not beyond horizon');
ok(Series::datesBeyondHorizon(['2026-10-23'], $cutoff) === ['2026-10-23'],
    'a date one day after the cutoff IS beyond horizon');

// ── Mixed list: only offending dates are returned, in input order ──────────
$dates = ['2026-08-01', '2026-10-23', '2026-09-15', '2027-01-01'];
ok(Series::datesBeyondHorizon($dates, $cutoff) === ['2026-10-23', '2027-01-01'],
    'mixed list returns only the offending dates, preserving input order');

// ── Empty input ──────────────────────────────────────────────────────────────
ok(Series::datesBeyondHorizon([], $cutoff) === [], 'empty date list returns empty');

// ── All within horizon ────────────────────────────────────────────────────────
ok(Series::datesBeyondHorizon(['2026-08-01', '2026-09-01'], $cutoff) === [],
    'a list entirely within the horizon returns empty');

echo "\nSeries horizon cap: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
