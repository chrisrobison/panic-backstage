<?php
/** DB-backed regression test for read-only automatic event ownership (#32). */

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

echo "\n=== Automatic read-only event owner (issue #32) ===\n\n";
$db = new Database();
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$venue || !$admin) {
    fwrite(STDERR, "event_owner_guard_db_test.php needs a venue and venue_admin user.\n");
    exit(1);
}

$auth = new Auth();
$auth->setUser($admin);
$eventId = 0;
try {
    $marker = 'PB TEST OWNER — ' . bin2hex(random_bytes(4));
    $create = new Events($db, $auth, [], $root);
    $response = $create->handle(new Request('POST', '/api/events', [], [
        'title' => $marker,
        'date' => (new DateTimeImmutable('+800 days'))->format('Y-m-d'),
        'venue_id' => (int) $venue['id'],
        'event_type' => 'special_event',
        'status' => 'empty',
        'owner_user_id' => 999999,
    ], [], []));
    $body = responseValue($response, 'body');
    $eventId = (int) ($body['id'] ?? 0);
    ok(responseValue($response, 'status') === 200 && $eventId > 0, 'event creates successfully');

    $row = $db->one('SELECT owner_user_id FROM events WHERE id = ?', [$eventId]);
    ok((int) ($row['owner_user_id'] ?? 0) === (int) $admin['id'], 'create ignores a crafted owner and assigns the caller');

    $update = new Events($db, $auth, ['eventId' => $eventId], $root);
    $reassign = $update->handle(new Request('PATCH', "/api/events/$eventId", [], [
        'owner_user_id' => null,
    ], [], []));
    ok(responseValue($reassign, 'status') === 422, 'crafted owner reassignment is rejected');
    $after = $db->one('SELECT owner_user_id FROM events WHERE id = ?', [$eventId]);
    ok((int) ($after['owner_user_id'] ?? 0) === (int) $admin['id'], 'owner remains unchanged after rejected PATCH');
} finally {
    if ($eventId > 0) $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
