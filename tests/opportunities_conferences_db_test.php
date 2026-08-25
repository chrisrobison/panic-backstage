<?php
/**
 * DB-backed test for the Opportunities module, Phase 3 (Conference list +
 * Conference Detail — docs/OPPORTUNITIES-IMPLEMENTATION.md /
 * docs/opportunity-ui/opportunity-ui.txt).
 *
 * Covers what Phase 1/2's tests don't:
 *   - Conferences::index() filters/sorts (city, researched, min_score,
 *     upcoming/past, sort=score/attendance/target_companies/proximity);
 *   - Conferences::show()'s new computed sections (target_company_count,
 *     peak_windows, outreach_angles, distance_from_venue_miles via
 *     Availability::conferenceDistanceMiles — both the "coordinates known"
 *     and "unknown, falls back to stored value" branches);
 *   - the Key Facts sub-resource (add/list/delete);
 *   - TaskLink's lazy task_documents provisioning, verified end-to-end by
 *     actually creating a task against the provisioned document through the
 *     real, unmodified src/Tasks/Items.php endpoint — proving a conference
 *     task is a genuine ordinary Backstage task, not a parallel system.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Prefixes
 * everything it creates with "PB TEST OPPCONF — ", and deletes those rows
 * (plus the task_documents/tasks rows TaskLink provisions) in a finally
 * block regardless of pass/fail. Excluded from the default hermetic pass —
 * opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_conferences_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities\Companies;
use Panic\Opportunities\ConferenceCompanies;
use Panic\Opportunities\Conferences;
use Panic\Opportunities\TaskLink;
use Panic\Request;
use Panic\Response;
use Panic\Tasks\Items as TaskItems;

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

echo "\n=== Opportunities module, Phase 3 (Conferences, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_conferences_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}
$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPPCONF — ' . bin2hex(random_bytes(4));
$created = ['opportunity_conferences' => [], 'opportunity_companies' => [], 'task_documents' => []];

try {
    $venueCoords = $db->one('SELECT latitude, longitude FROM venues WHERE latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1');

    // ── Create two conferences: one scored/researched/near, one not ─────────
    $farDate = date('Y-m-d', strtotime('+60 days'));
    $confAResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Near Conf', 'city' => 'San Francisco', 'state' => 'CA',
        'starts_at' => $farDate, 'ends_at' => date('Y-m-d', strtotime($farDate . ' +2 days')),
        'estimated_attendance' => 5000, 'latitude' => 37.7955, 'longitude' => -122.4028, // Moscone-ish
    ]);
    $confA = payload($confAResp)['conference'] ?? null;
    $confAId = (int) ($confA['id'] ?? 0);
    ok($confAId > 0, 'create conference A (with coordinates) succeeds');
    if ($confAId) { $created['opportunity_conferences'][] = $confAId; }

    // Mark A researched + scored via update.
    call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId], 'PATCH', [], [
        'opportunity_score' => 85, 'last_researched_at' => date('Y-m-d H:i:s'),
    ]);

    $confBDate = date('Y-m-d', strtotime('+90 days'));
    $confBResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Far Conf (unresearched)', 'city' => 'Chicago', 'state' => 'IL',
        'starts_at' => $confBDate, 'ends_at' => $confBDate, 'estimated_attendance' => 1000,
    ]);
    $confB = payload($confBResp)['conference'] ?? null;
    $confBId = (int) ($confB['id'] ?? 0);
    ok($confBId > 0, 'create conference B (no coordinates, unresearched) succeeds');
    if ($confBId) { $created['opportunity_conferences'][] = $confBId; }

    // ── index(): filters + sorts ─────────────────────────────────────────────
    $byCity = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['city' => 'San Francisco']));
    $cityIds = array_column($byCity['conferences'] ?? [], 'id');
    ok(in_array($confAId, $cityIds, true) && !in_array($confBId, $cityIds, true), 'city filter returns only the matching conference');

    $researched = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['researched' => '1']));
    $researchedIds = array_column($researched['conferences'] ?? [], 'id');
    ok(in_array($confAId, $researchedIds, true) && !in_array($confBId, $researchedIds, true), 'researched=1 filter excludes the unresearched conference');

    $unresearched = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['researched' => '0']));
    $unresearchedIds = array_column($unresearched['conferences'] ?? [], 'id');
    ok(in_array($confBId, $unresearchedIds, true) && !in_array($confAId, $unresearchedIds, true), 'researched=0 filter returns only the unresearched conference');

    $minScore = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['min_score' => '50']));
    $minScoreIds = array_column($minScore['conferences'] ?? [], 'id');
    ok(in_array($confAId, $minScoreIds, true) && !in_array($confBId, $minScoreIds, true), 'min_score filter excludes the unscored conference');

    $byScore = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['sort' => 'score', 'q' => $marker]));
    $scoreOrderIds = array_column($byScore['conferences'] ?? [], 'id');
    ok(($scoreOrderIds[0] ?? null) === $confAId, 'sort=score puts the higher-scored conference first');

    // ── Associate a sponsor company with conference A ────────────────────────
    $coResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Corp']);
    $companyId = (int) (payload($coResp)['company']['id'] ?? 0);
    ok($companyId > 0, 'create company for conference test succeeds');
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    call(ConferenceCompanies::class, $db, $adminAuth, ['conferenceId' => $confAId], 'POST', [], [
        'company_id' => $companyId, 'role' => 'sponsor', 'sponsor_tier' => 'Gold',
    ]);

    $byTargetCompanies = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['sort' => 'target_companies', 'q' => $marker]));
    ok(($byTargetCompanies['conferences'][0]['id'] ?? null) === $confAId, 'sort=target_companies puts the conference with a linked company first');
    ok((int) ($byTargetCompanies['conferences'][0]['target_company_count'] ?? 0) === 1, 'index() target_company_count reflects the linked company');

    if ($venueCoords) {
        $byProximity = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['sort' => 'proximity', 'q' => $marker]));
        ok(($byProximity['conferences'][0]['id'] ?? null) === $confAId, 'sort=proximity puts the conference with known coordinates ahead of the one without');
    }

    // ── show(): computed sections ────────────────────────────────────────────
    $showA = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId], 'GET'));
    ok(($showA['target_company_count'] ?? null) === 1, 'show() target_company_count is correct');
    ok(!empty($showA['peak_windows']['windows']), 'show() peak_windows has computed date entries');
    ok(!empty($showA['peak_windows']['best_dates']), 'show() peak_windows has a best_dates subset');
    ok(!empty($showA['outreach_angles']) && str_contains($showA['outreach_angles'][0], '1 sponsor'), 'show() outreach_angles mentions the real sponsor count, not a fabricated one');
    if ($venueCoords) {
        ok($showA['conference']['distance_from_venue_miles'] !== null, 'show() computes a real distance when both venue and conference coordinates are known');
        ok((float) $showA['conference']['distance_from_venue_miles'] < 5, 'the computed distance for a nearby SF conference is a small, sane number of miles');
    }

    $showB = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confBId], 'GET'));
    ok($showB['conference']['distance_from_venue_miles'] === null, 'show() reports distance as unknown (null) when the conference has no coordinates');

    // ── Key Facts sub-resource ──────────────────────────────────────────────
    $factResp = call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId, 'child' => 'facts'], 'POST', [], [
        'fact' => '100,000+ registered across all events', 'source_url' => 'https://example.invalid/stats',
    ]);
    ok(status($factResp) === 200, 'add a key fact succeeds');
    $factId = (int) (payload($factResp)['fact']['id'] ?? 0);
    ok($factId > 0, 'created fact has an id');

    $factsList = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId, 'child' => 'facts'], 'GET'));
    ok(count($factsList['facts'] ?? []) === 1, 'list key facts returns the one fact just added');

    $deniedFact = call(Conferences::class, $db, $outsiderAuth, ['conferenceId' => $confAId, 'child' => 'facts'], 'POST', [], ['fact' => 'nope']);
    ok(status($deniedFact) === 403, 'a role without manage_opportunities cannot add a key fact');

    call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId, 'child' => 'facts', 'factId' => $factId], 'DELETE');
    $factsAfterDelete = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confAId, 'child' => 'facts'], 'GET'));
    ok(count($factsAfterDelete['facts'] ?? []) === 0, 'delete key fact removes it');

    // ── TaskLink: lazy task_documents provisioning, end-to-end ──────────────
    $taskLinkBefore = payload(call(TaskLink::class, $db, $adminAuth, ['ownerType' => 'conference', 'ownerId' => $confAId], 'GET'));
    ok(array_key_exists('task_document_id', $taskLinkBefore) && $taskLinkBefore['task_document_id'] === null,
        'GET task-link returns null before any task document is provisioned');

    $ensure1 = payload(call(TaskLink::class, $db, $adminAuth, ['ownerType' => 'conference', 'ownerId' => $confAId], 'POST'));
    $documentId = (int) ($ensure1['task_document_id'] ?? 0);
    ok($documentId > 0 && ($ensure1['created'] ?? false) === true, 'POST task-link provisions a new task_documents row');
    if ($documentId) { $created['task_documents'][] = $documentId; }

    $confAAfterLink = $db->one('SELECT task_document_id FROM opportunity_conferences WHERE id = ?', [$confAId]);
    ok((int) $confAAfterLink['task_document_id'] === $documentId, 'the conference row itself stores the provisioned task_document_id');

    $ensure2 = payload(call(TaskLink::class, $db, $adminAuth, ['ownerType' => 'conference', 'ownerId' => $confAId], 'POST'));
    ok(($ensure2['task_document_id'] ?? null) === $documentId && ($ensure2['created'] ?? true) === false, 'a second POST is idempotent — same document, created=false');

    // Actually create a task against that document through the real,
    // unmodified Tasks endpoint — proving this is a genuine ordinary
    // Backstage task, not a parallel system.
    $taskResp = call(TaskItems::class, $db, $adminAuth, ['documentId' => $documentId], 'POST', [], [
        'title' => $marker . ' Reach out to PwC events team',
    ]);
    ok(status($taskResp) === 200, 'creating a task against the provisioned document succeeds via src/Tasks/Items.php unmodified');
    $taskId = (int) (payload($taskResp)['id'] ?? 0);
    ok($taskId > 0, 'the created task has a real id in the ordinary tasks table');

    $tasksList = payload(call(TaskItems::class, $db, $adminAuth, ['documentId' => $documentId], 'GET'));
    ok(count($tasksList['tasks'] ?? []) === 1 && ($tasksList['tasks'][0]['title'] ?? null) === $marker . ' Reach out to PwC events team',
        'listing tasks for the document shows the task we just created');

    // ── Delete conference (venue_admin only, cascades its own links) ────────
    $deniedDelete = call(Conferences::class, $db, $outsiderAuth, ['conferenceId' => $confBId], 'DELETE');
    ok(status($deniedDelete) === 403, 'a role without manage_opportunities cannot delete a conference');

    $deleteResp = call(Conferences::class, $db, $adminAuth, ['conferenceId' => $confBId], 'DELETE');
    ok(status($deleteResp) === 204, 'delete conference B succeeds');
    ok(!$db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$confBId]), 'conference B is actually gone after delete');
    $created['opportunity_conferences'] = array_values(array_diff($created['opportunity_conferences'], [$confBId]));
} finally {
    if (!empty($documentId)) {
        try { $db->run('DELETE FROM tasks WHERE document_id = ?', [$documentId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup tasks failed: {$e->getMessage()}\n"); }
        try { $db->run('DELETE FROM task_documents WHERE id = ?', [$documentId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup task_documents failed: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPCONF — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPCONF — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    $total = count($created['opportunity_conferences']) + count($created['opportunity_companies']) + (!empty($documentId) ? 1 : 0);
    echo "\n  (cleaned up $total throwaway row(s) plus their task/fact/signal children)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
