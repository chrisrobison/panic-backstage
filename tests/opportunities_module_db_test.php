<?php
/**
 * DB-backed acceptance test for the Opportunities module, Phase 1
 * (docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/opportunity-ui.txt).
 *
 * Exercises the Phase 1 acceptance criteria end to end against the real
 * endpoint classes (not just SQL): create a conference, create a company,
 * associate the company with the conference, create an opportunity, retrieve
 * its detail, move its pipeline stage, add a note, add a signal — plus a
 * capability-boundary check (a role with neither view_opportunities nor
 * manage_opportunities gets 403) and a Kernel routing spot-check.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Runs against the
 * shared dev database, prefixes everything it creates with
 * "PB TEST OPP — ", and deletes those rows in a finally block regardless of
 * pass/fail. Excluded from the default hermetic pass — opt in with
 * RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_module_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Kernel;
use Panic\Opportunities;
use Panic\Opportunities\Companies;
use Panic\Opportunities\ConferenceCompanies;
use Panic\Opportunities\Conferences;
use Panic\Opportunities\Notes;
use Panic\Opportunities\Signals;
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

/** Call an endpoint class's handle() directly, bypassing HTTP globals. */
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

echo "\n=== Opportunities module, Phase 1 (DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_module_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}

$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);

// `band` has neither view_opportunities nor manage_opportunities — the
// capability floor this test asserts against.
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPP — ' . bin2hex(random_bytes(4));
$created = ['opportunities' => [], 'opportunity_conferences' => [], 'opportunity_companies' => []];

try {
    // ── Kernel routing spot-check ────────────────────────────────────────────
    $resolve = new ReflectionMethod(Kernel::class, 'resolve');
    $resolve->setAccessible(true);
    $kernel = new Kernel($root, $db, $adminAuth);

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunities/dashboard');
    ok($class === Opportunities::class && ($params['action'] ?? null) === 'dashboard', 'GET /api/opportunities/dashboard routes to Opportunities::dashboard');

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunities/42/notes/7');
    ok($class === Notes::class && $params === ['linkedType' => 'opportunity', 'linkedId' => 42, 'noteId' => 7],
        '/api/opportunities/{id}/notes/{noteId} routes to Opportunities\\Notes with linkedType=opportunity');

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunity-conferences/9/companies');
    ok($class === ConferenceCompanies::class && $params['conferenceId'] === 9,
        '/api/opportunity-conferences/{id}/companies routes to Opportunities\\ConferenceCompanies');

    [$class] = $resolve->invoke($kernel, '/api/opportunity-notes');
    ok($class === Notes::class, '/api/opportunity-notes (cross-cutting) routes to Opportunities\\Notes');

    // ── Capability boundary ──────────────────────────────────────────────────
    $denied = call(Conferences::class, $db, $outsiderAuth, [], 'GET');
    ok(status($denied) === 403, 'a role without view_opportunities is denied GET /api/opportunity-conferences (403)');

    // ── Create conference ─────────────────────────────────────────────────────
    $confResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Conference',
        'city' => 'San Francisco',
        'state' => 'CA',
        'starts_at' => date('Y-m-d', strtotime('+200 days')),
        'ends_at'   => date('Y-m-d', strtotime('+202 days')),
    ]);
    ok(status($confResp) === 200, 'create conference succeeds');
    $conference = payload($confResp)['conference'] ?? null;
    ok(is_array($conference) && !empty($conference['id']) && !empty($conference['slug']), 'created conference has an id and slug');
    $conferenceId = (int) ($conference['id'] ?? 0);
    if ($conferenceId) { $created['opportunity_conferences'][] = $conferenceId; }

    // ── Create company ────────────────────────────────────────────────────────
    $coResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], [
        'name'   => $marker . ' Corp',
        'domain' => 'https://www.' . strtolower(str_replace([' ', '—'], '', $marker)) . '.example.com/about',
        'relationship_status' => 'prospect',
    ]);
    ok(status($coResp) === 200, 'create company succeeds');
    $company = payload($coResp)['company'] ?? null;
    ok(is_array($company) && !empty($company['id']), 'created company has an id');
    ok(($company['domain'] ?? null) !== null && !str_contains((string) $company['domain'], 'https://') && !str_contains((string) $company['domain'], 'www.'),
        'company domain was normalized (scheme/www/path stripped)');
    $companyId = (int) ($company['id'] ?? 0);
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    // ── Associate company with conference ────────────────────────────────────
    $linkResp = call(ConferenceCompanies::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'POST', [], [
        'company_id' => $companyId,
        'role' => 'sponsor',
        'sponsor_tier' => 'Gold',
    ]);
    ok(status($linkResp) === 200, 'associate company with conference succeeds');
    $dupeResp = call(ConferenceCompanies::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'POST', [], ['company_id' => $companyId]);
    ok(status($dupeResp) === 422, 'a duplicate conference/company association is rejected');

    // ── Create opportunity ────────────────────────────────────────────────────
    $oppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name'          => $marker . ' Partner Reception',
        'company_id'    => $companyId,
        'conference_id' => $conferenceId,
        'estimated_value' => 15000,
        'guest_count_min' => 100,
        'guest_count_max' => 150,
    ]);
    ok(status($oppResp) === 200, 'create opportunity succeeds');
    $opportunity = payload($oppResp)['opportunity'] ?? null;
    ok(is_array($opportunity) && ($opportunity['stage'] ?? null) === 'new_signal', 'new opportunity defaults to stage=new_signal');
    $opportunityId = (int) ($opportunity['id'] ?? 0);
    if ($opportunityId) { $created['opportunities'][] = $opportunityId; }

    $badCompanyResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], ['name' => 'x', 'company_id' => 999999999]);
    ok(status($badCompanyResp) === 422, 'creating an opportunity against a nonexistent company_id is rejected (FK validation)');

    // ── Retrieve opportunity detail ──────────────────────────────────────────
    $showResp = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $opportunityId], 'GET');
    ok(status($showResp) === 200, 'retrieve opportunity detail succeeds');
    $shown = payload($showResp)['opportunity'] ?? null;
    ok(($shown['company_name'] ?? null) === $company['name'], 'opportunity detail includes the joined company name');
    ok(($shown['conference_name'] ?? null) === $conference['name'], 'opportunity detail includes the joined conference name');

    // ── Move opportunity stage ───────────────────────────────────────────────
    $moveResp = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $opportunityId], 'PATCH', [], ['stage' => 'contacted']);
    ok(status($moveResp) === 200, 'move opportunity stage succeeds');
    ok((payload($moveResp)['opportunity']['stage'] ?? null) === 'contacted', 'opportunity stage actually moved to contacted');

    $badStageResp = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $opportunityId], 'PATCH', [], ['stage' => 'not_a_real_stage']);
    ok(status($badStageResp) === 422, 'an invalid stage value is rejected');

    // ── Add note (nested under opportunity, polymorphic table underneath) ───
    $noteResp = call(Notes::class, $db, $adminAuth, ['linkedType' => 'opportunity', 'linkedId' => $opportunityId, 'noteId' => null], 'POST', [], [
        'body' => $marker . ' walkthrough notes',
        'note_type' => 'meeting',
        'tags' => ['walkthrough', 'budget'],
    ]);
    ok(status($noteResp) === 200, 'add note succeeds');
    $note = payload($noteResp)['note'] ?? null;
    ok(is_array($note) && ($note['links'][0]['type'] ?? null) === 'opportunity' && $note['links'][0]['id'] === $opportunityId,
        'note is linked to the opportunity via opportunity_note_links');
    ok(in_array('walkthrough', $note['tags'] ?? [], true), 'note tags were persisted');

    $contactNoteResp = call(Notes::class, $db, $adminAuth, [], 'POST', [], ['body' => 'x', 'linked_type' => 'contact', 'linked_id' => 1]);
    ok(status($contactNoteResp) === 422, 'linking a note to linked_type=contact is rejected (opportunity_contacts does not exist until Phase 4)');

    // ── Add signal ────────────────────────────────────────────────────────────
    $signalResp = call(Signals::class, $db, $adminAuth, ['scopeType' => 'opportunity', 'scopeId' => $opportunityId], 'POST', [], [
        'signal_type' => 'sponsorship',
        'description' => $marker . ' is a Gold sponsor',
        'confidence' => 'high',
    ]);
    ok(status($signalResp) === 200, 'add signal succeeds');
    ok((payload($signalResp)['signal']['signal_type'] ?? null) === 'sponsorship', 'signal persisted with the given signal_type');

    $badSignalResp = call(Signals::class, $db, $adminAuth, ['scopeType' => 'opportunity', 'scopeId' => $opportunityId], 'POST', [], [
        'signal_type' => 'not_a_real_type', 'description' => 'x',
    ]);
    ok(status($badSignalResp) === 422, 'an invalid signal_type is rejected');

    // ── Activity feed picked up the create + stage change + note + signal ───
    $activitiesResp = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $opportunityId, 'child' => 'activities'], 'GET');
    ok(status($activitiesResp) === 200, 'read the opportunity activity feed succeeds');
    $actions = array_column(payload($activitiesResp)['activities'] ?? [], 'action');
    foreach (['created', 'stage_changed', 'note_added', 'signal_added'] as $expected) {
        ok(in_array($expected, $actions, true), "activity feed recorded a '$expected' entry");
    }

    // ── manage_opportunities boundary on write ───────────────────────────────
    $deniedWrite = call(Opportunities::class, $db, $outsiderAuth, [], 'POST', [], ['name' => 'nope', 'company_id' => $companyId]);
    ok(status($deniedWrite) === 403, 'a role without manage_opportunities cannot create an opportunity (403)');
} finally {
    // Children (notes/links/signals/activities) cascade via FK ON DELETE
    // CASCADE — deleting the opportunity/conference/company rows is enough.
    foreach ($created['opportunities'] as $id) {
        try { $db->run('DELETE FROM opportunities WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for opportunity $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    echo "\n  (cleaned up " . (count($created['opportunities']) + count($created['opportunity_conferences']) + count($created['opportunity_companies'])) . " throwaway row(s))\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
