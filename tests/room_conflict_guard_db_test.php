<?php
/**
 * DB-backed tests for the room-occupancy SQL behind issue #26.
 *
 * tests/room_conflict_guard_test.php covers the *policy* (which statuses block
 * which, and the time-order rule) without touching a database. This covers the
 * half that policy can't: that EventRowHelpers::checkRoomConflict() actually
 * honours the status filter it is handed when it queries.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue and one venue_admin
 * user. This runs against the shared dev database (there is no separate test
 * DB), so it picks a genuinely free date rather than hardcoding one, prefixes
 * everything it creates with "PB TEST ROOMGUARD — ", and deletes those rows in
 * a finally block regardless of pass/fail. Excluded from the default hermetic
 * pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/room_conflict_guard_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Room occupancy guard, DB-backed (issue #26) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$venue || !$admin) {
    fwrite(STDERR, "room_conflict_guard_db_test.php needs a venue and a venue_admin user — skipping.\n");
    exit(1);
}
$venueId = (int) $venue['id'];
$userId  = (int) $admin['id'];

// A room to book into. Falls back to venue-wide conflict scoping if this
// venue has no rooms defined, which the guard also supports.
$room     = $db->one('SELECT id FROM resources WHERE venue_id = ? AND active = 1 ORDER BY id LIMIT 1', [$venueId]);
$roomId   = $room ? (int) $room['id'] : null;

$auth = new Auth();
$auth->setUser(['id' => $userId, 'name' => 'Test', 'email' => 'test@example.invalid', 'role' => 'venue_admin']);
$events = new Events($db, $auth, [], $root);

$check = new ReflectionMethod(Events::class, 'checkRoomConflict');
$check->setAccessible(true);
$blockersFor = function (string $status) {
    $m = new ReflectionMethod(Events::class, 'conflictBlockersFor');
    $m->setAccessible(true);
    return $m->invoke(null, $status);
};

// Response keeps its status private with no getter, so read it directly.
$statusOf = function (?\Panic\Response $r): ?int {
    if ($r === null) return null;
    $p = new ReflectionProperty(\Panic\Response::class, 'status');
    $p->setAccessible(true);
    return (int) $p->getValue($r);
};

$marker  = 'PB TEST ROOMGUARD — ' . bin2hex(random_bytes(4));
$created = [];

/** Insert a throwaway event and remember it for cleanup. */
$makeEvent = function (string $date, string $status) use ($db, $venueId, $roomId, $userId, $marker, &$created): int {
    $id = $db->insert(
        "INSERT INTO events (venue_id, resource_id, title, slug, event_type, status, date, doors_time, show_time, end_time, owner_user_id)
         VALUES (?, ?, ?, ?, 'live_music', ?, ?, '19:00', '20:00', '23:00', ?)",
        [$venueId, $roomId, $marker . " $status", \Panic\slugify($marker . " $status " . $date), $status, $date, $userId]
    );
    $created[] = $id;
    return $id;
};

try {
    // Pick two dates with nothing booked in this room, expanding multi-day
    // events across their whole span (same rule checkRoomConflict applies).
    $busyRows = $db->all(
        "SELECT date, end_date FROM events WHERE venue_id = ? AND COALESCE(end_date, date) >= CURDATE() AND status NOT IN ('canceled','empty')",
        [$venueId]
    );
    $busy = [];
    foreach ($busyRows as $row) {
        for ($d = new DateTimeImmutable($row['date']), $end = new DateTimeImmutable($row['end_date'] ?: $row['date']); $d <= $end; $d = $d->modify('+1 day')) {
            $busy[$d->format('Y-m-d')] = true;
        }
    }
    $freeDates = [];
    for ($cursor = new DateTimeImmutable('+400 days'); count($freeDates) < 3; $cursor = $cursor->modify('+1 day')) {
        $d = $cursor->format('Y-m-d');
        if (!isset($busy[$d])) { $freeDates[] = $d; $busy[$d] = true; }
    }
    [$dateA, $dateB, $dateC] = $freeDates;

    // Same window as the fixtures, so any conflict is a real one.
    $conflictArgs = fn(string $date, ?array $blockers) => [
        $venueId, $date, '19:00', '23:00', null, null, $roomId, $blockers,
    ];

    // ── A confirmed booking occupies the room against a new Hold ─────────────
    $makeEvent($dateA, 'confirmed');
    $result = $check->invoke($events, ...$conflictArgs($dateA, $blockersFor('proposed')));
    ok($result !== null, 'a Hold over a confirmed booking is refused (the issue #26 regression)');
    ok($statusOf($result) === 409, 'the refusal is a 409');

    // ── but a competing Hold does not ────────────────────────────────────────
    $makeEvent($dateB, 'proposed');
    ok($check->invoke($events, ...$conflictArgs($dateB, $blockersFor('proposed'))) === null,
        'a Hold over another Hold is still allowed');

    // ── and a confirmed booking is still blocked by that Hold ────────────────
    ok($check->invoke($events, ...$conflictArgs($dateB, $blockersFor('confirmed'))) !== null,
        'a confirmed booking over an existing Hold is still refused');

    // ── an empty draft short-circuits without querying ───────────────────────
    ok($check->invoke($events, ...$conflictArgs($dateA, $blockersFor('empty'))) === null,
        'an empty draft is never blocked');

    // ── a canceled event must not occupy the room ────────────────────────────
    $canceledDate = (new DateTimeImmutable('+402 days'))->format('Y-m-d');
    $makeEvent($canceledDate, 'canceled');
    ok($check->invoke($events, ...$conflictArgs($canceledDate, $blockersFor('proposed'))) === null,
        'a canceled event does not block a new Hold');
    ok($check->invoke($events, ...$conflictArgs($canceledDate, $blockersFor('confirmed'))) === null,
        'a canceled event does not block a new confirmed booking either');

    // ── occupancy spans Load In through Load Out, not Doors through End ─────
    $occupancyId = $makeEvent($dateC, 'confirmed');
    $db->run("UPDATE events SET load_in_time = '12:00', doors_time = '18:00', end_time = '22:00', load_out_time = '23:00' WHERE id = ?", [$occupancyId]);
    ok($check->invoke($events, $venueId, $dateC, '10:00', '11:00', null, null, $roomId, $blockersFor('proposed')) === null,
        'an event clear 30 minutes before load-in fits');
    ok($check->invoke($events, $venueId, $dateC, '11:45', '12:30', null, null, $roomId, $blockersFor('proposed')) !== null,
        'load-in blocks the room before doors');
    ok($check->invoke($events, $venueId, $dateC, '22:30', '23:30', null, null, $roomId, $blockersFor('proposed')) !== null,
        'load-out blocks the room after event end');
} finally {
    foreach ($created as $id) {
        try { $db->run('DELETE FROM events WHERE id = ? AND title LIKE ?', [$id, 'PB TEST ROOMGUARD — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for event $id: {$e->getMessage()}\n"); }
    }
    echo "\n  (cleaned up " . count($created) . " throwaway event(s))\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
