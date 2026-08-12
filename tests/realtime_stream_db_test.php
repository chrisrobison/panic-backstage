<?php
/**
 * DB-backed tests for Panic\Realtime's permission filtering and resume-point
 * resolution (src/Realtime.php) — the parts of the realtime SSE endpoint
 * that need a live Database (Capabilities::hasEvent() queries events/
 * event_collaborators). The pure wire-format half (buildFrame()) is covered
 * hermetically in tests/realtime_stream_frame_test.php, and the mapping
 * half in tests/realtime_invalidation_mapper_test.php.
 *
 * resolveSince()/visibleTo() are private — reached via Reflection, same
 * convention as tests/booking_inbox_role_scope_db_test.php's payload()
 * helper for Response's private properties.
 *
 * Needs a real venue + venue_admin user (creates/cleans up one throwaway
 * event). Not run by default — see tests/run-php-tests.sh's DB_TESTS list.
 *
 * Run with: RUN_DB_TESTS=1 ./tests/run-php-tests.sh
 *       or: php tests/realtime_stream_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;
use Panic\Realtime;
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
/** Invoke a private/protected method on $object via Reflection. */
function invokePrivate(object $object, string $method, array $args = []): mixed {
    $reflection = new ReflectionMethod(get_class($object), $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

echo "\n=== Realtime: resume point + permission filtering ===\n\n";

$db = new Database();
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$venue || !$admin) {
    fwrite(STDERR, "realtime_stream_db_test.php needs a venue and venue_admin user.\n");
    exit(1);
}

$auth = new Auth();
$auth->setUser($admin);
$eventId = 0;

try {
    // ── resolveSince(): Last-Event-ID > ?since= > MAX(db_history.id) ────────
    $maxRow = $db->one('SELECT MAX(id) AS max_id FROM db_history');
    $currentMax = (int) ($maxRow['max_id'] ?? 0);

    $realtime = new Realtime($db, $auth, ['action' => 'stream'], $root);

    $withNeither = new Request('GET', '/api/realtime/stream', [], [], [], []);
    ok(invokePrivate($realtime, 'resolveSince', [$withNeither]) === $currentMax,
        'with neither Last-Event-ID nor ?since=, resolveSince() starts at the current MAX(db_history.id) — no backlog replay');

    $withSince = new Request('GET', '/api/realtime/stream', ['since' => '42'], [], [], []);
    ok(invokePrivate($realtime, 'resolveSince', [$withSince]) === 42,
        '?since=42 is honored when Last-Event-ID is absent');

    $withLastEventId = new Request('GET', '/api/realtime/stream', ['since' => '42'], [], [], ['Last-Event-ID' => '99']);
    ok(invokePrivate($realtime, 'resolveSince', [$withLastEventId]) === 99,
        'Last-Event-ID (99) wins over ?since= (42) — matches EventSource\'s native reconnect behavior');

    $withGarbage = new Request('GET', '/api/realtime/stream', ['since' => 'not-a-number'], [], [], []);
    ok(invokePrivate($realtime, 'resolveSince', [$withGarbage]) === $currentMax,
        'a non-numeric ?since= is ignored, not cast to 0 (which would replay the whole table)');

    // ── visibleTo(): 'global' is visible to anyone ──────────────────────────
    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'global'], null, 'viewer', &$cache]) === true,
        "'global' invalidations are visible regardless of role — they carry no identifying data");

    // ── visibleTo(): 'lead' gated by view_booking_inbox (pure role check) ───
    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'lead', 'id' => 1], 1, 'venue_admin', &$cache]) === true,
        'venue_admin sees lead invalidations (has view_booking_inbox)');
    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'lead', 'id' => 1], 1, 'viewer', &$cache]) === false,
        'viewer role (no view_booking_inbox) does not see lead invalidations');

    // ── visibleTo(): 'event' gated by per-event read_event ──────────────────
    $marker = 'PB TEST REALTIME — ' . bin2hex(random_bytes(4));
    $create = new Events($db, $auth, [], $root);
    $response = $create->handle(new Request('POST', '/api/events', [], [
        'title' => $marker,
        'date' => (new DateTimeImmutable('+800 days'))->format('Y-m-d'),
        'venue_id' => (int) $venue['id'],
        'event_type' => 'special_event',
        'status' => 'empty',
    ], [], []));
    $eventId = (int) (responseValue($response, 'body')['id'] ?? 0);
    ok($eventId > 0, 'throwaway event for the visibleTo(event) assertions creates successfully');

    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'event', 'id' => $eventId], (int) $admin['id'], 'venue_admin', &$cache]) === true,
        'venue_admin sees invalidations for any event');

    // A synthetic, never-collaborator user id — Capabilities::eventAccess()
    // only checks ownership/event_collaborators rows, so this needs no real
    // second user account to exercise the "not visible" path.
    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'event', 'id' => $eventId], 999999, 'viewer', &$cache]) === false,
        'a non-owner, non-collaborator viewer does not see this event\'s invalidations');

    $cache = [];
    ok(invokePrivate($realtime, 'visibleTo', [['entity' => 'event', 'id' => 0], 999999, 'viewer', &$cache]) === false,
        'an event id of 0 (should never occur, but defensively) is never visible');

    // Memoization: the second call for the same event id must not re-query.
    $cache = ['nonsense_marker_should_be_untouched' => true];
    $before = $cache;
    invokePrivate($realtime, 'visibleTo', [['entity' => 'event', 'id' => $eventId], (int) $admin['id'], 'venue_admin', &$cache]);
    ok(array_key_exists($eventId, $cache), 'visibleTo() populates the per-connection event access cache for reuse across a batch');
} finally {
    if ($eventId > 0) {
        $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
