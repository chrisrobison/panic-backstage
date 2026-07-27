<?php
/**
 * DB-backed false-positive recovery from the inbound-mail quarantine.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Inbox;
use Panic\Request;
use Panic\Response;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}
function responsePayload(Response $response): array
{
    $reflection = new ReflectionClass($response);
    return [
        'status' => $reflection->getProperty('status')->getValue($response),
        'body' => $reflection->getProperty('body')->getValue($response),
    ];
}

echo "\n=== Booking Inbox quarantine recovery (DB-backed) ===\n\n";

$db = new Database();
$admin = $db->one("SELECT * FROM users WHERE role = 'venue_admin' AND is_hidden = 0 ORDER BY id LIMIT 1");
if ($admin === null) {
    fwrite(STDERR, "A visible venue administrator is required for this test.\n");
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$raw = "From: Test Booker <quarantine-{$marker}@example.invalid>\r\n"
    . "To: bookings@themab.org\r\n"
    . "Subject: Private event on January 17\r\n"
    . "Message-ID: <quarantine-{$marker}@example.invalid>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
    . "We would like to book a private event on January 17, 2037 for 180 guests.\r\n";
$parsed = [
    'status' => 'new',
    'source' => 'email',
    'contact_name' => 'Test Booker',
    'contact_email' => "quarantine-{$marker}@example.invalid",
    'event_name' => "PB Quarantine {$marker}",
    'event_type' => 'private_event',
    'projected_attendance' => 180,
    'notes' => 'False-positive quarantine test',
    'risk_level' => 'unknown',
];
$intakeId = $db->insert(
    "INSERT INTO lead_intake_emails
     (message_id,from_name,from_email,to_recipients,subject,parse_method,status,disposition_reason,parsed_json,raw_email,received_at)
     VALUES (?,?,?,?,?,'heuristic','skipped',?,?,?,NOW())",
    [
        "quarantine-{$marker}@example.invalid", 'Test Booker', $parsed['contact_email'],
        'bookings@themab.org', 'Private event on January 17', 'DB regression test',
        json_encode($parsed), $raw,
    ]
);
$leadId = null;
$oldBin = getenv('CLAUDE_CLI_BIN');

try {
    putenv('CLAUDE_CLI_BIN=/definitely/missing/claude');
    $auth = new Auth();
    $auth->setUser($admin);
    $response = responsePayload((new Inbox(
        $db,
        $auth,
        ['action' => 'quarantine', 'itemId' => $intakeId],
        dirname(__DIR__)
    ))->handle(new Request('POST', '/', [], ['action' => 'promote'], [], [])));

    $leadId = (int) ($response['body']['lead_id'] ?? 0);
    ok($response['status'] === 200 && $leadId > 0, 'manager can promote a quarantined message');

    $intake = $db->one('SELECT status, lead_id FROM lead_intake_emails WHERE id = ?', [$intakeId]);
    ok($intake['status'] === 'imported' && (int) $intake['lead_id'] === $leadId, 'retained intake row links to the promoted inquiry');

    $message = $db->one("SELECT direction, status, is_read FROM lead_messages WHERE lead_id = ? LIMIT 1", [$leadId]);
    ok($message['direction'] === 'inbound' && $message['status'] === 'received', 'promoted email becomes an inbound conversation message');
    ok((int) $message['is_read'] === 0, 'promoted email enters the queue as unread');

    $lead = $db->one('SELECT source, status FROM leads WHERE id = ?', [$leadId]);
    ok($lead['source'] === 'email' && !in_array($lead['status'], ['spam', 'duplicate'], true), 'promoted inquiry enters normal deterministic routing');
} finally {
    if ($oldBin === false) {
        putenv('CLAUDE_CLI_BIN');
    } else {
        putenv('CLAUDE_CLI_BIN=' . $oldBin);
    }
    if ($leadId !== null && $leadId > 0) {
        $db->run('DELETE FROM leads WHERE id = ?', [$leadId]);
    }
    $db->run('DELETE FROM lead_intake_emails WHERE id = ?', [$intakeId]);
}

echo "\nBooking Inbox quarantine recovery: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
