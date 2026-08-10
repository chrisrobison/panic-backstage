<?php
/** Regression test for the Intake Complete contract-details gate (#31). */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;
use Panic\Request;
use Panic\Response;

$root = dirname(__DIR__);
Env::load($root . '/.env');
$_ENV['SHEET_SYNC_ENABLED'] = '0';
putenv('SHEET_SYNC_ENABLED=0');
putenv('GCAL_SYNC_ENABLED=0');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { echo "  ✓ $label\n"; $passed++; }
    else { echo "  ✗ FAIL: $label\n"; $failed++; }
}
function responseValue(Response $response, string $property): mixed {
    $reflection = new ReflectionProperty(Response::class, $property);
    $reflection->setAccessible(true);
    return $reflection->getValue($response);
}

echo "\n=== Contract details Intake Complete gate (issue #31) ===\n\n";

$db = new Database();
$endpoint = new Events($db, new Auth(), [], $root);
$validate = new ReflectionMethod(Events::class, 'validateStatusTransition');
$validate->setAccessible(true);

$readyEvent = [
    'event_type' => 'live_music',
    'title' => 'Contract details gate test',
    'date' => '2099-01-01',
    'venue_id' => 1,
    'doors_time' => '19:00:00',
    'end_time' => '23:00:00',
    'promoter_name' => 'Promoter Test',
    'promoter_email' => 'promoter@example.com',
    'promoter_phone' => '415-555-0100',
    'booker_name' => 'Booker Test',
    'booker_email' => 'booker@example.com',
    'booker_phone' => '415-555-0101',
    'load_in_time' => '17:00:00',
    'load_out_time' => '23:30:00',
    'age_restriction' => '21+',
    'ticket_price' => 0,
    'capacity' => 100,
    'deposit_amount' => 0,
    'description_internal' => 'Staff notes recorded.',
    'contract_details' => null,
];

$blocked = $validate->invoke($endpoint, 'confirmed', $readyEvent);
ok($blocked instanceof Response, 'Intake Complete is blocked without contract details');
$body = $blocked instanceof Response ? responseValue($blocked, 'body') : [];
ok(str_contains((string) ($body['error'] ?? ''), 'Contract details'), 'error names the missing Contract details field');

$readyEvent['contract_details'] = 'Artist guarantee $500; house provides sound and one engineer.';
$allowed = $validate->invoke($endpoint, 'confirmed', $readyEvent);
ok($allowed === null, 'Intake Complete is allowed after contract details are recorded');

$readyEvent['contract_details'] = '   ';
$whitespace = $validate->invoke($endpoint, 'confirmed', $readyEvent);
ok($whitespace instanceof Response, 'whitespace-only contract details do not satisfy the gate');

// Exercise both persistence paths used by the workspace and intake report.
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
$eventId = 0;
if ($venue && $admin) {
    $auth = new Auth();
    $auth->setUser($admin);
    try {
        $createEndpoint = new Events($db, $auth, [], $root);
        $created = $createEndpoint->handle(new Request('POST', '/api/events', [], [
            'title' => 'PB TEST CONTRACT DETAILS — ' . bin2hex(random_bytes(4)),
            'date' => (new DateTimeImmutable('+950 days'))->format('Y-m-d'),
            'venue_id' => (int) $venue['id'],
            'event_type' => 'special_event',
            'status' => 'empty',
            'contract_details' => 'Initial negotiated terms.',
        ], [], []));
        $createdBody = responseValue($created, 'body');
        $eventId = (int) ($createdBody['id'] ?? 0);
        $createdRow = $eventId ? $db->one('SELECT contract_details FROM events WHERE id = ?', [$eventId]) : null;
        ok($eventId > 0 && ($createdRow['contract_details'] ?? null) === 'Initial negotiated terms.', 'create persists contract details');

        $updateEndpoint = new Events($db, $auth, ['eventId' => $eventId], $root);
        $fullUpdate = $updateEndpoint->handle(new Request('PATCH', "/api/events/$eventId", [], [
            'contract_details' => '  Updated negotiated terms.  ',
            'description_internal' => 'Updated alongside contract details.',
        ], [], []));
        $updatedRow = $db->one('SELECT contract_details, description_internal FROM events WHERE id = ?', [$eventId]);
        ok(responseValue($fullUpdate, 'status') === 200 && ($updatedRow['contract_details'] ?? null) === 'Updated negotiated terms.', 'workspace full-row update stores trimmed contract details');

        $partialUpdate = $updateEndpoint->handle(new Request('PATCH', "/api/events/$eventId", [], [
            'contract_details' => 'Intake report edit.',
        ], [], []));
        $partialRow = $db->one('SELECT contract_details FROM events WHERE id = ?', [$eventId]);
        ok(responseValue($partialUpdate, 'status') === 200 && ($partialRow['contract_details'] ?? null) === 'Intake report edit.', 'single-field intake edit persists contract details');
    } finally {
        if ($eventId > 0) $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
    }
} else {
    ok(false, 'persistence checks require a venue and venue_admin user');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
