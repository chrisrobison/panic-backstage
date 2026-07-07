<?php
/**
 * Tests for RateLimiter (src/RateLimiter.php), added to throttle the
 * previously-unlimited login/magic-link auth endpoints.
 *
 * REQUIRES A REAL MYSQL DATABASE — RateLimiter's SQL (ON DUPLICATE KEY
 * UPDATE, NOW(6)) is MySQL-specific, and migration 052 (rate_limits table)
 * must be applied. Point DB_* / .env at a throwaway database before running
 * this directly; it is excluded from tests/run-php-tests.sh's default
 * (hermetic) pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: php tests/rate_limiter_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\RateLimiter;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== RateLimiter ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1'); // fail fast with a clear message if unreachable
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    fwrite(STDERR, "rate_limiter_test.php needs a real MySQL DB with migration 052 applied.\n");
    exit(1);
}

// Randomised bucket prefix so concurrent/repeated runs never collide.
$prefix = 'test:' . bin2hex(random_bytes(8)) . ':';

// ── 1. Allows exactly maxAttempts, then blocks ───────────────────────────────
$bucket = $prefix . 'cap3';
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = RateLimiter::tooMany($db, $bucket, 3, 60);
}
ok($results === [false, false, false, true],
    'attempts 1-3 allowed, 4th blocked (maxAttempts=3): got [' . implode(',', array_map(fn($v) => $v ? 'true' : 'false', $results)) . ']');

// ── 2. Independent buckets don't interfere ──────────────────────────────────
$bucketA = $prefix . 'indep-a';
$bucketB = $prefix . 'indep-b';
for ($i = 0; $i < 3; $i++) {
    RateLimiter::tooMany($db, $bucketA, 3, 60);
}
$aBlocked = RateLimiter::tooMany($db, $bucketA, 3, 60);
$bBlocked = RateLimiter::tooMany($db, $bucketB, 3, 60);
ok($aBlocked === true, 'bucket A is exhausted after 4 hits (cap 3)');
ok($bBlocked === false, 'bucket B is untouched by bucket A\'s attempts');

// ── 3. Window expiry resets the count ────────────────────────────────────────
$bucketW = $prefix . 'window-reset';
RateLimiter::tooMany($db, $bucketW, 1, 1); // consumes the single allowed slot
ok(RateLimiter::tooMany($db, $bucketW, 1, 1) === true, 'second hit within the 1s window is blocked');
sleep(2);
ok(RateLimiter::tooMany($db, $bucketW, 1, 1) === false, 'hit after the window elapses is allowed again');

// ── Cleanup ──────────────────────────────────────────────────────────────────
$db->run('DELETE FROM rate_limits WHERE bucket LIKE ?', [$prefix . '%']);

echo "\nRateLimiter: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
