<?php
/**
 * DB-backed Booking Inbox row scoping for a restricted promoter.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Inbox;
use Panic\LeadsInbox;
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
function payload(Response $response): array
{
    $reflection = new ReflectionClass($response);
    $status = $reflection->getProperty('status');
    $body = $reflection->getProperty('body');
    return ['status' => $status->getValue($response), 'body' => $body->getValue($response)];
}

echo "\n=== Booking Inbox role scope (DB-backed) ===\n\n";

$db = new Database();
$promoter = $db->one("SELECT * FROM users WHERE role = 'promoter' AND is_hidden = 0 ORDER BY id LIMIT 1");
if ($promoter === null) {
    fwrite(STDERR, "A visible promoter is required for this test.\n");
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$scopedId = $db->insert(
    "INSERT INTO leads (status,source,contact_email,event_name,assigned_to_user_id)
     VALUES ('new','other',?,?,?)",
    ["scoped-{$marker}@example.invalid", "PB Scoped {$marker}", $promoter['id']]
);
$otherId = $db->insert(
    "INSERT INTO leads (status,source,contact_email,event_name)
     VALUES ('new','other',?,?)",
    ["other-{$marker}@example.invalid", "PB Other {$marker}"]
);

try {
    $auth = new Auth();
    $auth->setUser($promoter);
    $get = new Request('GET', '/', [], [], [], []);

    $scoped = payload((new LeadsInbox($db, $auth, ['leadId' => $scopedId, 'child' => 'detail'], dirname(__DIR__)))->handle($get));
    ok($scoped['status'] === 200 && (int) ($scoped['body']['lead']['id'] ?? 0) === $scopedId, 'promoter can open an assigned inquiry detail');

    $other = payload((new LeadsInbox($db, $auth, ['leadId' => $otherId, 'child' => 'detail'], dirname(__DIR__)))->handle($get));
    ok($other['status'] === 403, 'promoter cannot open an unrelated inquiry detail');

    $list = payload((new Inbox($db, $auth, ['action' => 'list'], dirname(__DIR__)))->handle(
        new Request('GET', '/', ['view' => 'all'], [], [], [])
    ));
    $ids = array_map('intval', array_column($list['body']['leads'] ?? [], 'id'));
    ok(in_array($scopedId, $ids, true), 'scoped list includes the assigned inquiry');
    ok(!in_array($otherId, $ids, true), 'scoped list excludes the unrelated inquiry');
} finally {
    $db->run('DELETE FROM leads WHERE id IN (?, ?)', [$scopedId, $otherId]);
}

echo "\nBooking Inbox role scope: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
