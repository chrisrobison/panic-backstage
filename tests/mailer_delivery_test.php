<?php
/**
 * Tests Mailer's observable local-delivery result without invoking the host MTA.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Mailer;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$label}\n";
        $passed++;
        return;
    }
    echo "  ✗ FAIL: {$label}\n";
    $failed++;
}

echo "\n=== Mailer delivery-state tests ===\n\n";

$previousPath = getenv('SENDMAIL_PATH');

putenv('SENDMAIL_PATH=/bin/true');
$accepted = new Mailer(dirname(__DIR__));
$accepted->skipInboxCopy()->send('delivery-test@example.invalid', 'Accepted', 'Test');
ok($accepted->deliveryAccepted(), '/bin/true reports an accepted delivery');
ok($accepted->deliveryError() === null, 'accepted delivery has no diagnostic');

$missingPath = sys_get_temp_dir() . '/panic-missing-sendmail-' . bin2hex(random_bytes(4));
putenv('SENDMAIL_PATH=' . $missingPath);
$rejected = new Mailer(dirname(__DIR__));
$rejected->skipInboxCopy()->send('delivery-test@example.invalid', 'Rejected', 'Test');
ok(!$rejected->deliveryAccepted(), 'missing sendmail reports a rejected delivery');
ok(
    str_contains((string) $rejected->deliveryError(), 'not executable'),
    'rejected delivery exposes a useful diagnostic'
);

if ($previousPath === false) {
    putenv('SENDMAIL_PATH');
} else {
    putenv('SENDMAIL_PATH=' . $previousPath);
}

echo "\nMailer delivery state: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
