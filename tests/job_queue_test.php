<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Jobs\JobQueue;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$label}\n";
        $failed++;
    }
}

echo "\n=== Background queue policy tests ===\n\n";

ok(JobQueue::retryDelaySeconds(1) === 30, 'first failure retries after 30 seconds');
ok(JobQueue::retryDelaySeconds(2) === 60, 'second failure doubles the delay');
ok(JobQueue::retryDelaySeconds(5) === 480, 'fifth failure keeps exponential backoff');
ok(JobQueue::retryDelaySeconds(20) === 3600, 'backoff is capped at one hour');
ok(JobQueue::retryDelaySeconds(0) === 30, 'invalid low attempt values are clamped');

echo "\nBackground queue policy: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
