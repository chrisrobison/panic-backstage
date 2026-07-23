<?php
/**
 * Tests for src/Leads/Acknowledgment.php — the Booking Inbox's send-once
 * auto-acknowledgment gate.
 *
 * Only exercises the guards that return before any DB read/write (blank
 * email, internal/manual source, spam/duplicate status) — genuinely
 * hermetic. The send-once dedup check, settings lookup, and actual send are
 * DB/mail-dependent and belong in the integration suite instead.
 *
 * Run with: php tests/leads_acknowledgment_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\Acknowledgment;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox auto-acknowledgment tests ===\n\n";

$ack = new Acknowledgment(dirname(__DIR__));
$db  = new Database();

$noEmail = $ack->maybeSend($db, ['id' => 999999, 'source' => 'website', 'status' => 'new', 'contact_email' => '', 'contact_name' => 'Test']);
ok($noEmail === false, "No contact_email => not sent, no DB touch");

foreach (['internal', 'manual'] as $skipSource) {
    $r = $ack->maybeSend($db, ['id' => 999999, 'source' => $skipSource, 'status' => 'new', 'contact_email' => 'a@example.com', 'contact_name' => 'Test']);
    ok($r === false, "Source '$skipSource' is never auto-acknowledged");
}

foreach (['spam', 'duplicate'] as $skipStatus) {
    $r = $ack->maybeSend($db, ['id' => 999999, 'source' => 'website', 'status' => $skipStatus, 'contact_email' => 'a@example.com', 'contact_name' => 'Test']);
    ok($r === false, "Status '$skipStatus' is never auto-acknowledged");
}

echo "\nBooking Inbox auto-acknowledgment: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
