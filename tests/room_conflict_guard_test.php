<?php
/**
 * Tests for the room-occupancy guard and time-order validation (issue #26).
 *
 * Background: a Hold was saved on top of a confirmed show. The server-side
 * conflict check already existed, but every call site gated it on the *new*
 * event's own status being confirmed-or-later, so a Hold ('proposed') skipped
 * it entirely — and Events::fromTemplate(), the calendar's quick-create path,
 * ran no check at all. The same row also had load-in at 22:00 with doors at
 * 18:30, which nothing validated.
 *
 * These exercise the two pure decision functions behind the fix. The SQL that
 * consumes them (EventRowHelpers::checkRoomConflict) needs a database and is
 * covered by tests/room_conflict_guard_db_test.php.
 *
 * Run with: php tests/room_conflict_guard_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

/** Call a private static on Events. */
function callPrivate(string $method, array $args) {
    $m = new ReflectionMethod(\Panic\Events::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$blockersFor  = fn(string $status) => callPrivate('conflictBlockersFor', [$status]);
/** True when the ordering is accepted. */
$orderAccepts = fn(?string $li, ?string $do, ?string $sh) => callPrivate('timeOrderError', [$li, $do, $sh]) === null;

echo "\n=== Room occupancy guard (issue #26) ===\n\n";

// ── 1. Who blocks whom ────────────────────────────────────────────────────────
// The bug in one assertion: a Hold must not sail past a confirmed booking.
$holdBlockers = $blockersFor('proposed');
ok(is_array($holdBlockers) && in_array('confirmed', $holdBlockers, true),
    'a Hold is blocked by a confirmed booking');
ok(is_array($holdBlockers) && in_array('published', $holdBlockers, true),
    'a Hold is blocked by a published booking, not just an equal-status one');
ok(is_array($holdBlockers) && !in_array('proposed', $holdBlockers, true),
    'a Hold is NOT blocked by another Hold — competing holds are normal practice');

// null means "no status filter" → checkRoomConflict's default, every live row.
ok($blockersFor('confirmed') === null, 'a confirmed booking is blocked by anything live');
ok($blockersFor('settled')   === null, 'a late-pipeline booking is blocked by anything live');

// [] means "skip the check entirely".
ok($blockersFor('empty')    === [], 'an empty draft occupies nothing');
ok($blockersFor('canceled') === [], 'a canceled event occupies nothing');

echo "\n=== Time-order validation, past-midnight aware ===\n\n";

// ── 2. Real overnight shows must stay saveable ────────────────────────────────
// All three are live rows in production; a plain load_in <= doors <= show rule
// would have rejected every one of them.
ok($orderAccepts(null, '19:00', '01:30'), 'doors 19:00 → show 01:30 (overnight) is accepted');
ok($orderAccepts(null, '20:00', '01:00'), 'doors 20:00 → show 01:00 (overnight) is accepted');
ok($orderAccepts(null, '19:00', '00:00'), 'doors 19:00 → show midnight is accepted');
ok($orderAccepts('17:00', '19:00', '20:00'), 'an ordinary load-in → doors → show is accepted');

// ── 3. Genuine data-entry errors must be rejected ─────────────────────────────
// Also production rows — these are what the check is for.
ok(!$orderAccepts(null, '19:00', '18:00'), 'show one hour before doors is rejected');
ok(!$orderAccepts(null, '20:30', '20:00'), 'show 30 minutes before doors is rejected');
ok(!$orderAccepts(null, '18:00', '17:00'), 'doors 18:00 → show 17:00 is rejected');
ok(!$orderAccepts(null, '19:00', '09:00'), 'a 14-hour doors→show gap is rejected');
ok(!$orderAccepts('22:00', '18:30', '19:00'),
    "issue #26's own row (load-in 22:00, doors 18:30) is rejected");

// ── 4. Absent values are not errors ───────────────────────────────────────────
// Most events never record a load-in at all (only 28% do), and non-music
// events hide Doors entirely and set Show alone.
ok($orderAccepts(null, null, null),      'an event with no times recorded is accepted');
ok($orderAccepts(null, null, '20:00'),   'show alone is accepted');
ok($orderAccepts('17:00', null, '19:00'),
    'non-music (no doors): load-in is compared against show instead');
ok(!$orderAccepts('22:00', null, '19:00'),
    'non-music: an impossible load-in is still caught via show');
ok($orderAccepts('', '', ''),            'empty strings are treated as absent, not midnight');

// ── 5. Grandfathering: only validate when a time field is actually set ────────
// 5 live rows are already out of order, #26's own among them. Validating a
// merged row on every save would make those permanently unsaveable —
// including by the edit that repairs them — so update() only checks when the
// request touches a time field.
$touches = fn(array $body) => callPrivate('touchesTimeFields', [$body]);
ok($touches(['title' => 'Renamed']) === false,
    'a title-only edit skips validation, so an already-invalid row stays editable');
ok($touches(['status' => 'confirmed']) === false, 'a status-only change skips validation');
ok($touches(['doors_time' => '19:00']) === true, 'setting doors triggers validation');
ok($touches(['load_in_time' => '17:00']) === true, 'setting load-in triggers validation');
ok($touches(['show_time' => '20:00']) === true, 'setting show triggers validation');
// array_key_exists, not isset — clearing a time is still touching it.
ok($touches(['doors_time' => null]) === true, 'explicitly clearing a time still triggers validation');
ok($touches([]) === false, 'an empty body touches nothing');

// ── 6. end_time is deliberately unconstrained ─────────────────────────────────
// `end <= start` is how this codebase encodes a past-midnight finish, so
// timeOrderError() takes no end_time at all. Guard against a future edit
// quietly adding one.
$params = (new ReflectionMethod(\Panic\Events::class, 'timeOrderError'))->getParameters();
ok(count($params) === 3, 'timeOrderError takes only load-in, doors and show — end_time stays unvalidated');

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
