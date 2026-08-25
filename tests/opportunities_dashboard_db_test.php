<?php
/**
 * DB-backed test for the Opportunities module, Phase 2 (Discover dashboard
 * + venue-availability matching — docs/OPPORTUNITIES-IMPLEMENTATION.md /
 * docs/opportunity-ui/opportunity-ui.txt).
 *
 * Covers what Phase 1's opportunities_module_db_test.php doesn't:
 *   - GET /api/opportunities/dashboard's Phase 2 payload shape (kpis,
 *     best_opportunities, upcoming_conferences, availability_matches,
 *     recent_notes, suggestions) actually reflects real rows;
 *   - Opportunities\Availability::emptyNightMatches() correctly flags a
 *     conference date with no event as an empty-night match, and correctly
 *     stops flagging it once a real event is booked that date — the "avoid
 *     N+1, no fabricated potential" requirement from the Phase 2 spec.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user and one
 * venue (there is no separate test DB — see project dev-environment memory).
 * Picks a genuinely free 2-day window in the next ~300 days (same technique
 * as tests/room_conflict_guard_db_test.php) rather than hardcoding dates,
 * prefixes everything it creates with "PB TEST OPPDASH — ", and deletes
 * those rows in a finally block regardless of pass/fail. Excluded from the
 * default hermetic pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_dashboard_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities;
use Panic\Opportunities\Availability;
use Panic\Opportunities\Companies;
use Panic\Opportunities\Conferences;
use Panic\Opportunities\Notes;
use Panic\Request;
use Panic\Response;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) { echo "  \xE2\x9C\x93 $label\n"; $passed++; }
    else       { echo "  \xE2\x9C\x97 FAIL: $label\n"; $failed++; }
}

function call(string $class, Database $db, Auth $auth, array $params, string $method, array $query = [], array $body = []): Response
{
    $endpoint = new $class($db, $auth, $params, dirname(__DIR__));
    $request  = new Request($method, '/api/test', $query, $body, [], [], []);
    return $endpoint->handle($request);
}

function status(Response $r): int
{
    $p = new ReflectionProperty(Response::class, 'status');
    $p->setAccessible(true);
    return (int) $p->getValue($r);
}

function payload(Response $r): array
{
    $p = new ReflectionProperty(Response::class, 'body');
    $p->setAccessible(true);
    $body = $p->getValue($r);
    return is_array($body) ? $body : [];
}

echo "\n=== Opportunities module, Phase 2 (Discover dashboard, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
if (!$admin || !$venue) {
    fwrite(STDERR, "opportunities_dashboard_db_test.php needs a venue_admin user and a venue — skipping.\n");
    exit(1);
}
$venueId = (int) $venue['id'];

$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);

$marker = 'PB TEST OPPDASH — ' . bin2hex(random_bytes(4));
$created = ['opportunities' => [], 'opportunity_conferences' => [], 'opportunity_companies' => [], 'events' => []];

try {
    // ── Pick two consecutive genuinely-free dates within the next ~300 days,
    // same technique as room_conflict_guard_db_test.php: expand every
    // upcoming event's [date, end_date] span into a busy-date set, then walk
    // forward until two consecutive days are both absent from it. Kept well
    // under Availability's 365-day window clamp.
    $busyRows = $db->all(
        "SELECT `date`, end_date FROM events WHERE COALESCE(end_date, `date`) >= CURDATE() AND status NOT IN ('canceled','empty')"
    );
    $busy = [];
    foreach ($busyRows as $row) {
        for ($d = new DateTimeImmutable($row['date']), $end = new DateTimeImmutable($row['end_date'] ?: $row['date']); $d <= $end; $d = $d->modify('+1 day')) {
            $busy[$d->format('Y-m-d')] = true;
        }
    }
    $dayOne = null;
    $dayTwo = null;
    for ($cursor = new DateTimeImmutable('+250 days'); $cursor < new DateTimeImmutable('+300 days'); $cursor = $cursor->modify('+1 day')) {
        $next = $cursor->modify('+1 day');
        if (!isset($busy[$cursor->format('Y-m-d')]) && !isset($busy[$next->format('Y-m-d')])) {
            $dayOne = $cursor->format('Y-m-d');
            $dayTwo = $next->format('Y-m-d');
            break;
        }
    }
    if (!$dayOne) {
        fwrite(STDERR, "Could not find two consecutive free days in +250..+300 days — skipping.\n");
        exit(1);
    }

    // ── Create a conference spanning [dayOne, dayTwo] and a linked company ──
    $confResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Conference',
        'starts_at' => $dayOne,
        'ends_at'   => $dayTwo,
    ]);
    $conference = payload($confResp)['conference'] ?? null;
    $conferenceId = (int) ($conference['id'] ?? 0);
    ok($conferenceId > 0, 'create conference for dashboard test succeeds');
    if ($conferenceId) { $created['opportunity_conferences'][] = $conferenceId; }

    $coResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Corp']);
    $company = payload($coResp)['company'] ?? null;
    $companyId = (int) ($company['id'] ?? 0);
    ok($companyId > 0, 'create company for dashboard test succeeds');
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    $oppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Opportunity', 'company_id' => $companyId, 'conference_id' => $conferenceId,
        'estimated_value' => 9000, 'probability' => 70,
    ]);
    $opportunity = payload($oppResp)['opportunity'] ?? null;
    $opportunityId = (int) ($opportunity['id'] ?? 0);
    ok($opportunityId > 0, 'create opportunity for dashboard test succeeds');
    if ($opportunityId) { $created['opportunities'][] = $opportunityId; }

    $noteResp = call(Notes::class, $db, $adminAuth, [], 'POST', [], [
        'body' => $marker . ' dashboard note', 'linked_type' => 'company', 'linked_id' => $companyId,
        'additional_links' => [['type' => 'opportunity', 'id' => $opportunityId]],
    ]);
    $noteId = (int) (payload($noteResp)['note']['id'] ?? 0);
    ok($noteId > 0, 'create note for dashboard test succeeds');

    // ── Availability::emptyNightMatches — both dates empty before any event ──
    $windowDays = (int) (new DateTimeImmutable($dayTwo))->diff(new DateTimeImmutable())->days + 5;
    $matches = Availability::emptyNightMatches($db, $windowDays);
    $matchDates = array_map(
        static fn (array $m) => $m['conference']['id'] . '@' . $m['date'],
        array_filter($matches, static fn (array $m) => (int) $m['conference']['id'] === $conferenceId)
    );
    ok(in_array("$conferenceId@$dayOne", $matchDates, true), 'empty-night match includes dayOne before any event is booked');
    ok(in_array("$conferenceId@$dayTwo", $matchDates, true), 'empty-night match includes dayTwo before any event is booked');

    // ── Book an event on dayOne; it should drop out of the matches, dayTwo stays ──
    $eventId = $db->insert(
        "INSERT INTO events (venue_id, title, slug, event_type, status, date, doors_time, show_time, end_time, owner_user_id)
         VALUES (?, ?, ?, 'private_event', 'confirmed', ?, '19:00', '20:00', '23:00', ?)",
        [$venueId, $marker . ' Event', \Panic\slugify($marker . ' Event ' . $dayOne), $dayOne, (int) $admin['id']]
    );
    $created['events'][] = $eventId;

    $matchesAfter = Availability::emptyNightMatches($db, $windowDays);
    $matchDatesAfter = array_map(
        static fn (array $m) => $m['conference']['id'] . '@' . $m['date'],
        array_filter($matchesAfter, static fn (array $m) => (int) $m['conference']['id'] === $conferenceId)
    );
    ok(!in_array("$conferenceId@$dayOne", $matchDatesAfter, true), 'dayOne drops out of the matches once an event books it');
    ok(in_array("$conferenceId@$dayTwo", $matchDatesAfter, true), 'dayTwo is still an empty-night match (unaffected by dayOne\'s booking)');

    // ── Dashboard endpoint: real aggregates reflect our fixtures ────────────
    $dashResp = call(Opportunities::class, $db, $adminAuth, ['action' => 'dashboard'], 'GET', ['window_days' => (string) $windowDays]);
    ok(status($dashResp) === 200, 'dashboard call succeeds');
    $dash = payload($dashResp);

    ok(isset($dash['kpis']['open_opportunities']['value']) && $dash['kpis']['open_opportunities']['value'] >= 1,
        'dashboard kpis.open_opportunities reflects real open opportunities');
    ok(isset($dash['kpis']['empty_nights']['value']), 'dashboard kpis.empty_nights is present');

    $upcomingIds = array_column($dash['upcoming_conferences'] ?? [], 'id');
    ok(in_array($conferenceId, $upcomingIds, true), 'our conference appears in dashboard upcoming_conferences');

    $bestIds = array_column($dash['best_opportunities'] ?? [], 'id');
    ok(in_array($opportunityId, $bestIds, true), 'our opportunity appears in dashboard best_opportunities');

    $matchConfIds = array_column(array_column($dash['availability_matches'] ?? [], 'conference'), 'id');
    ok(in_array($conferenceId, $matchConfIds, true), 'our conference appears in dashboard availability_matches (dayTwo still open)');

    $recentNote = null;
    foreach ($dash['recent_notes'] ?? [] as $n) {
        if ((int) $n['id'] === $noteId) { $recentNote = $n; break; }
    }
    ok($recentNote !== null, 'our note appears in dashboard recent_notes');
    ok($recentNote !== null && str_contains((string) ($recentNote['context'] ?? ''), $marker . ' Corp'),
        "dashboard recent_notes note has a resolved context including the company name");

    ok(is_array($dash['suggestions'] ?? null), 'dashboard suggestions is an array (deterministic, not fabricated prose)');
} finally {
    foreach ($created['events'] as $id) {
        try { $db->run('DELETE FROM events WHERE id = ? AND title LIKE ?', [$id, 'PB TEST OPPDASH — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for event $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunities'] as $id) {
        try { $db->run('DELETE FROM opportunities WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPDASH — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for opportunity $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPDASH — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPDASH — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    $total = count($created['events']) + count($created['opportunities']) + count($created['opportunity_conferences']) + count($created['opportunity_companies']);
    echo "\n  (cleaned up $total throwaway row(s))\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
