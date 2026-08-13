<?php
/**
 * DB-backed integration tests for the AI Assistant's Phase 2 write path:
 *   - Panic\Ai\BookerUpdate::matchEvents()/apply() (propose_booker_update)
 *   - Panic\Events\Series::previewSeries()/attemptCreate() (propose_recurring_series)
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue row and one
 * venue_admin user — point DB_* / .env at a throwaway database (or the dev
 * copy) before running this directly; it is excluded from
 * tests/run-php-tests.sh's default (hermetic) pass — opt in with
 * RUN_DB_TESTS=1. Everything this script creates is prefixed
 * "PB TEST AI PHASE2 — " and deleted in a finally block regardless of
 * pass/fail (same convention as tests/ui/*.mjs's throwaway events).
 *
 * This exercises real DB writes/reads through the exact same shared methods
 * scripts/ai-mcp-server.php and src/Ai/Assistant.php call — it does not
 * spin up the MCP stdio protocol or the `claude` CLI itself (see
 * docs/AI-ASSISTANT-PLAN.md's testing section for the manual curl-based
 * round trip that covers that layer).
 *
 * Run with: RUN_DB_TESTS=1 php tests/ai_phase2_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Ai\BookerUpdate;
use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events\Series;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== AI Assistant Phase 2 (DB-backed) ===\n\n";

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
    fwrite(STDERR, "ai_phase2_db_test.php needs at least one venue and one venue_admin user in the DB — skipping.\n");
    exit(1);
}
$venueId = (int) $venue['id'];
$userId  = (int) $admin['id'];
$role    = 'venue_admin';

$marker = 'PB TEST AI PHASE2 — ' . bin2hex(random_bytes(4));
$createdEventIds = [];
$createdSeriesIds = [];

$auth = new Auth();
$auth->setUser(['id' => $userId, 'name' => 'Test', 'email' => 'test@example.invalid', 'role' => $role]);

// This runs against the real shared DB (no separate test DB — see
// dev-environment memory), so dates can't just be hardcoded: pick genuinely
// free dates for $venueId within the 90-day horizon rather than risking a
// room-conflict 409 against whatever's actually booked. Mirrors
// EventRowHelpers::checkRoomConflict()'s own overlap logic (date <=
// rangeEnd AND COALESCE(end_date, date) >= date) — expanding every
// multi-day booking across its full span, not just its start date, so a
// residency-style event doesn't silently collide with a "free" pick.
$horizonCutoff = Series::horizonCutoff();
$bookedRows = $db->all(
    "SELECT date, end_date FROM events WHERE venue_id = ? AND COALESCE(end_date, date) >= CURDATE() AND date <= ? AND status NOT IN ('canceled', 'empty')",
    [$venueId, $horizonCutoff]
);
$booked = [];
foreach ($bookedRows as $row) {
    for ($d = new DateTimeImmutable($row['date']), $end = new DateTimeImmutable($row['end_date'] ?: $row['date']); $d <= $end; $d = $d->modify('+1 day')) {
        $booked[$d->format('Y-m-d')] = true;
    }
}

function pickFreeDates(array &$booked, int $count, string $horizonCutoff): array
{
    $dates = [];
    $cursor = (new DateTimeImmutable('+3 days'));
    while (count($dates) < $count) {
        $d = $cursor->format('Y-m-d');
        if ($d > $horizonCutoff) {
            throw new \RuntimeException('Could not find enough free dates within the 90-day horizon for this test.');
        }
        if (!isset($booked[$d])) {
            $dates[] = $d;
            $booked[$d] = true; // reserve so a later pick in this run can't collide with it
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

// 6 free dates: eventA, eventB, unrelated, anchor, + 2 recurring occurrences.
[$dateA, $dateB, $dateUnrelated, $dateAnchor, $dateOcc1, $dateOcc2] = pickFreeDates($booked, 6, $horizonCutoff);

function insertTestEvent(Database $db, int $venueId, string $title, string $date, string $promoterName, array &$createdEventIds): int
{
    $slug = 'pb-test-ai-p2-' . bin2hex(random_bytes(6));
    $id = $db->insert(
        "INSERT INTO events (venue_id, title, slug, event_type, status, date, promoter_name, promoter_email)
         VALUES (?, ?, ?, 'live_music', 'proposed', ?, ?, 'old@example.invalid')",
        [$venueId, $title, $slug, $date, $promoterName]
    );
    $createdEventIds[] = $id;
    return $id;
}

try {
    // ── BookerUpdate::matchEvents() + apply() ────────────────────────────────
    $eventA = insertTestEvent($db, $venueId, "{$marker} — A", $dateA, $marker, $createdEventIds);
    $eventB = insertTestEvent($db, $venueId, "{$marker} — B", $dateB, $marker, $createdEventIds);
    // A third, unrelated-promoter event that must NOT match the filter below.
    insertTestEvent($db, $venueId, "{$marker} — unrelated", $dateUnrelated, 'Some Other Promoter', $createdEventIds);

    $matchedByFilter = BookerUpdate::matchEvents($db, $role, $userId, [], $marker);
    ok(count($matchedByFilter) === 2, 'matchEvents() by promoter_name_filter matches exactly the 2 events with that promoter, not the unrelated one');

    $matchedByIds = BookerUpdate::matchEvents($db, $role, $userId, [$eventA, $eventB], null);
    ok(count($matchedByIds) === 2, 'matchEvents() by explicit event_ids matches exactly those events');

    $disallowed = BookerUpdate::disallowedFields(['status' => 'canceled']);
    ok($disallowed === ['status'], 'the propose-time allowlist check would reject a status field before matchEvents() is even relevant');

    $updatedCount = BookerUpdate::apply($db, [$eventA, $eventB], ['promoter_email' => 'new@example.invalid']);
    ok($updatedCount === 2, 'apply() reports 2 rows updated');
    $rowA = $db->one('SELECT promoter_email FROM events WHERE id = ?', [$eventA]);
    $rowUnrelated = $db->one('SELECT promoter_email FROM events WHERE promoter_name = ?', ['Some Other Promoter']);
    ok($rowA['promoter_email'] === 'new@example.invalid', 'apply() actually wrote the new value to the DB');
    ok($rowUnrelated['promoter_email'] === 'old@example.invalid', 'apply() did not touch the unrelated event');

    // A disallowed field surviving all the way to apply() (simulating a
    // crafted args_json) must never reach the UPDATE — sanitizeFields() is
    // apply()'s only intake, not just a propose-time check.
    $before = $db->one('SELECT status FROM events WHERE id = ?', [$eventA]);
    BookerUpdate::apply($db, [$eventA], ['status' => 'canceled', 'promoter_name' => $marker . ' — renamed']);
    $after = $db->one('SELECT status, promoter_name FROM events WHERE id = ?', [$eventA]);
    ok($before['status'] === $after['status'], 'apply() silently drops a disallowed "status" key even if present in $fields — never reaches SQL');
    ok($after['promoter_name'] === $marker . ' — renamed', 'apply() still applies the allowed sibling field in the same call');

    // ── Series::previewSeries() (dry run) vs attemptCreate() (real) ─────────
    $anchor = insertTestEvent($db, $venueId, "{$marker} — anchor", $dateAnchor, $marker, $createdEventIds);
    $series = new Series($db, $auth, [], $root);
    $occDates = [$dateOcc1, $dateOcc2];

    $eventsBefore = (int) $db->one('SELECT COUNT(*) AS c FROM events')['c'];
    $preview = $series->previewSeries($anchor, $occDates, $userId, $role);
    $eventsAfter = (int) $db->one('SELECT COUNT(*) AS c FROM events')['c'];
    ok($preview['ok'] === true && $preview['dates'] === $occDates, 'previewSeries() validates and echoes back the requested dates');
    ok($eventsBefore === $eventsAfter, 'previewSeries() is a true dry run — no event rows were created');

    $horizonPreview = $series->previewSeries($anchor, [(new DateTimeImmutable('+120 days'))->format('Y-m-d')], $userId, $role);
    ok($horizonPreview['ok'] === false && $horizonPreview['status'] === 422 && isset($horizonPreview['horizon_date']),
        'previewSeries() rejects a date beyond the 90-day horizon, same as the HTTP route would');

    $result = $series->attemptCreate($anchor, $occDates, 'AI test series', null, 'after_count', null, 3, $userId, $role);
    ok($result['ok'] === true && count($result['created_event_ids']) === 2, 'attemptCreate() actually creates the 2 occurrence events');
    foreach ($result['created_event_ids'] ?? [] as $id) { $createdEventIds[] = $id; }
    if (isset($result['series_id'])) { $createdSeriesIds[] = $result['series_id']; }

    $siblingCount = (int) $db->one('SELECT COUNT(*) AS c FROM events WHERE series_id = ?', [$result['series_id']])['c'];
    ok($siblingCount === 3, 'the series now has 3 members total (anchor + 2 created occurrences)');

    // ── attemptCreate() extending an existing series ─────────────────────────
    // Use one of the just-created siblings (not the founding anchor) as the
    // new anchor — mirrors the UI, which lets you "Add more dates" from
    // whichever occurrence's page is open.
    $sibling = $result['created_event_ids'][0];
    [$dateOcc3] = pickFreeDates($booked, 1, $horizonCutoff);

    // $dateOcc2 belongs to the *other* sibling (created_event_ids[1]), not to
    // $sibling itself — this specifically exercises the new "taken by any
    // series member" check, not just the pre-existing "own date" check.
    $duplicateAttempt = $series->attemptCreate($sibling, [$dateOcc2], null, null, 'after_count', null, null, $userId, $role);
    ok($duplicateAttempt['ok'] === false && $duplicateAttempt['status'] === 422,
        'attemptCreate() rejects a date that already belongs to another event in the same series');

    $extendResult = $series->attemptCreate($sibling, [$dateOcc3], 'AI test series — extended', null, 'after_count', null, null, $userId, $role);
    ok($extendResult['ok'] === true && $extendResult['extended'] === true && count($extendResult['created_event_ids']) === 1,
        'attemptCreate() extends the existing series instead of rejecting an anchor that already has a series_id');
    ok($extendResult['series_id'] === $result['series_id'], 'the extended occurrence is appended to the same event_series row, not a new one');
    foreach ($extendResult['created_event_ids'] ?? [] as $id) { $createdEventIds[] = $id; }

    $siblingCountAfterExtend = (int) $db->one('SELECT COUNT(*) AS c FROM events WHERE series_id = ?', [$result['series_id']])['c'];
    ok($siblingCountAfterExtend === 4, 'the series now has 4 members after extending (anchor + 2 + 1 extended)');

    $seriesRow = $db->one('SELECT occurrence_count FROM event_series WHERE id = ?', [$result['series_id']]);
    ok((int) $seriesRow['occurrence_count'] === 4, 'event_series.occurrence_count is reconciled against actual membership after extending');
} finally {
    // ── Cleanup: every throwaway row this script created, regardless of outcome ──
    foreach (array_unique($createdEventIds) as $id) {
        $db->run('UPDATE events SET series_id = NULL WHERE id = ?', [$id]);
        $db->run('DELETE FROM events WHERE id = ?', [$id]);
    }
    foreach (array_unique($createdSeriesIds) as $id) {
        $db->run('DELETE FROM event_series WHERE id = ?', [$id]);
    }
}

echo "\nAI Assistant Phase 2 (DB-backed): $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
