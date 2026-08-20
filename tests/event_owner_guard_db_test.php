<?php
/**
 * DB-backed regression test for automatic event ownership (#32) and its
 * follow-up: ownership stays read-only for everyone except a venue admin,
 * who can reassign it from the Details tab's Owner dropdown.
 */

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

    // A non-admin collaborator with edit_event (a promoter/booker) still
    // cannot reassign ownership, even by crafting the PATCH directly.
    $booker = $db->one(
        "SELECT id, name, email, role FROM users WHERE role NOT IN ('venue_admin', 'global_viewer') AND id != ? ORDER BY id LIMIT 1",
        [$admin['id']]
    );
    if (!$booker) {
        fwrite(STDERR, "event_owner_guard_db_test.php needs a second, non-admin user.\n");
        exit(1);
    }
    $db->run('INSERT INTO event_collaborators (event_id, user_id, role) VALUES (?, ?, ?)', [$eventId, $booker['id'], 'promoter']);
    $bookerAuth = new Auth();
    $bookerAuth->setUser($booker);
    $bookerUpdate = new Events($db, $bookerAuth, ['eventId' => $eventId], $root);
    $bookerAttempt = $bookerUpdate->handle(new Request('PATCH', "/api/events/$eventId", [], [
        'owner_user_id' => $booker['id'],
    ], [], []));
    ok(responseValue($bookerAttempt, 'status') === 422, 'a non-admin booker cannot reassign ownership');
    $afterBooker = $db->one('SELECT owner_user_id FROM events WHERE id = ?', [$eventId]);
    ok((int) ($afterBooker['owner_user_id'] ?? 0) === (int) $admin['id'], 'owner remains unchanged after a booker\'s rejected PATCH');

    // A venue admin CAN reassign ownership — the point of the follow-up fix.
    $update = new Events($db, $auth, ['eventId' => $eventId], $root);
    $reassign = $update->handle(new Request('PATCH', "/api/events/$eventId", [], [
        'owner_user_id' => $booker['id'],
    ], [], []));
    ok(responseValue($reassign, 'status') === 200, 'a venue admin can reassign ownership');
    $after = $db->one('SELECT owner_user_id FROM events WHERE id = ?', [$eventId]);
    ok((int) ($after['owner_user_id'] ?? 0) === (int) $booker['id'], 'owner updates after an admin PATCH');

    // An admin can also clear ownership entirely.
    $clear = $update->handle(new Request('PATCH', "/api/events/$eventId", [], [
        'owner_user_id' => null,
    ], [], []));
    ok(responseValue($clear, 'status') === 200, 'a venue admin can clear ownership');
    $afterClear = $db->one('SELECT owner_user_id FROM events WHERE id = ?', [$eventId]);
    ok(($afterClear['owner_user_id'] ?? null) === null, 'owner is null after an admin clears it');
} finally {
    if ($eventId > 0) $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
