<?php
/**
 * DB-backed test for Opportunities' AI/web research jobs (Phase 7 —
 * src/Opportunities/Research/Jobs.php + Importer.php). Exercises the real
 * endpoint classes directly (not HTTP), same pattern as
 * opportunities_module_db_test.php.
 *
 * Deliberately does NOT invoke Ai\ClaudeCli / Opportunities\Research\Runner
 * — no test in this repo spawns the real `claude` subprocess (see
 * booking_email_parser_test.php's own note on this), and a research job
 * involves live web search, which would make this test slow, flaky, and
 * network-dependent. Instead this test covers everything before and after
 * that subprocess boundary: Kernel routing, Jobs::create()'s scope/dedup/
 * capability validation, and Importer::import()'s actual DB writes —
 * against a hand-crafted `completed` job row with a fixed result_json,
 * exactly the shape Modes::validateResult() would have produced (that
 * function's own untrusted-input handling is covered separately, hermetically,
 * by tests/opportunity_research_modes_test.php).
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (see
 * project dev-environment memory — no separate test DB). Prefixes everything
 * it creates with "PB TEST OPPRESEARCH — " and cleans up in a finally block
 * regardless of pass/fail. Excluded from the default hermetic pass — opt in
 * with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_research_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Kernel;
use Panic\Opportunities\Research\Jobs;
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

echo "\n=== Opportunities AI research jobs, Phase 7 (DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_research_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}

$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);

// `band` has neither research_opportunities nor manage_opportunities.
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPPRESEARCH — ' . bin2hex(random_bytes(4));
$conferenceId = null;
$companyId = null;
$jobIds = [];
$backgroundJobIds = [];

try {
    // ── Kernel routing spot-check ────────────────────────────────────────────
    $resolve = new ReflectionMethod(Kernel::class, 'resolve');
    $resolve->setAccessible(true);
    $kernel = new Kernel($root, $db, $adminAuth);

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunity-research/jobs');
    ok($class === Jobs::class && $params === ['jobId' => null, 'action' => null], 'GET /api/opportunity-research/jobs routes to Research\\Jobs (list)');

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunity-research/jobs/123');
    ok($class === Jobs::class && $params === ['jobId' => 123, 'action' => null], '/api/opportunity-research/jobs/{id} routes with jobId set');

    [$class, $params] = $resolve->invoke($kernel, '/api/opportunity-research/jobs/123/import');
    ok($class === Jobs::class && $params === ['jobId' => 123, 'action' => 'import'], '/api/opportunity-research/jobs/{id}/import routes with action=import');

    // ── Fixtures: a throwaway conference + company ───────────────────────────
    $conferenceId = (int) $db->insert(
        'INSERT INTO opportunity_conferences (name, slug, city, state) VALUES (?,?,?,?)',
        [$marker . ' Conference', 'pb-test-oppresearch-' . bin2hex(random_bytes(4)), 'San Francisco', 'CA']
    );
    $companyId = (int) $db->insert(
        'INSERT INTO opportunity_companies (name) VALUES (?)',
        [$marker . ' Corp']
    );

    // ── Capability boundary ──────────────────────────────────────────────────
    $denied = call(Jobs::class, $db, $outsiderAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'discover_conferences']);
    ok(status($denied) === 403, 'a role without research_opportunities cannot enqueue a job (403)');

    // ── create(): discover_conferences needs no scope ───────────────────────
    $createResp = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], [
        'job_type' => 'discover_conferences',
        'input' => ['location' => 'San Francisco, CA'],
    ]);
    ok(status($createResp) === 200, 'create discover_conferences job succeeds');
    $job = payload($createResp)['job'] ?? null;
    ok(is_array($job) && $job['status'] === 'pending', 'the new job is pending');
    ok(is_array($job['input_json']) && $job['input_json']['location'] === 'San Francisco, CA', 'input_json is decoded to a real object with the request location');
    ok(!empty($job['background_job_id']), 'a background_jobs row id was recorded on the research job');
    $jobId = (int) $job['id'];
    $jobIds[] = $jobId;
    $backgroundJobIds[] = (int) $job['background_job_id'];

    $bgRow = $db->one('SELECT job_type, payload_json FROM background_jobs WHERE id = ?', [(int) $job['background_job_id']]);
    ok($bgRow !== null && $bgRow['job_type'] === 'opportunity_research', 'the enqueued background job has job_type=opportunity_research');
    $bgPayload = json_decode((string) $bgRow['payload_json'], true);
    ok(($bgPayload['research_job_id'] ?? null) === $jobId, 'the background job payload references the research job id');

    // ── create(): unknown job_type rejected ──────────────────────────────────
    $badType = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'do_something_bad']);
    ok(status($badType) === 422, 'an unknown job_type is rejected (422)');

    // ── create(): scope required/validated ───────────────────────────────────
    $missingScope = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'research_conference']);
    ok(status($missingScope) === 422, 'research_conference without conference_id is rejected (422)');

    $badScope = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'research_conference', 'conference_id' => 999999999]);
    ok(status($badScope) === 422, 'research_conference with a non-existent conference_id is rejected (422)');

    $goodScopeResp = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'research_conference', 'conference_id' => $conferenceId]);
    ok(status($goodScopeResp) === 200, 'research_conference with a real conference_id succeeds');
    $scopedJob = payload($goodScopeResp)['job'];
    $jobIds[] = (int) $scopedJob['id'];
    $backgroundJobIds[] = (int) $scopedJob['background_job_id'];

    // ── create(): duplicate pending job for the same scope is rejected ───────
    $dupeResp = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'POST', [], ['job_type' => 'research_conference', 'conference_id' => $conferenceId]);
    ok(status($dupeResp) === 409, 'a duplicate pending research_conference job for the same conference is rejected (409)');

    // ── index() ───────────────────────────────────────────────────────────────
    $listResp = call(Jobs::class, $db, $adminAuth, ['jobId' => null, 'action' => null], 'GET', ['conference_id' => (string) $conferenceId]);
    ok(status($listResp) === 200, 'GET jobs?conference_id= succeeds');
    $listedIds = array_column(payload($listResp)['jobs'] ?? [], 'id');
    ok(in_array((int) $scopedJob['id'], $listedIds, true), 'the scoped job appears in the conference-filtered list');

    // ── show() ────────────────────────────────────────────────────────────────
    $showResp = call(Jobs::class, $db, $adminAuth, ['jobId' => $jobId, 'action' => null], 'GET');
    ok(status($showResp) === 200, 'GET a single job succeeds');
    ok((payload($showResp)['job']['id'] ?? null) === $jobId, 'show() returns the right job');

    $showMissing = call(Jobs::class, $db, $adminAuth, ['jobId' => 999999999, 'action' => null], 'GET');
    ok(status($showMissing) === 404, 'a non-existent job id 404s');

    // ── import(): not completed yet ──────────────────────────────────────────
    $importTooSoon = call(Jobs::class, $db, $adminAuth, ['jobId' => $jobId, 'action' => 'import'], 'POST', [], ['conferences' => [0]]);
    ok(status($importTooSoon) === 422, 'importing a still-pending job is rejected (422)');

    // ── Hand-craft a completed find_target_companies job and import it ──────
    $companiesResult = [
        'companies' => [
            ['name' => $marker . ' Sponsor Co', 'domain' => null, 'role' => 'sponsor', 'why_relevant' => 'Headline sponsor.', 'source_url' => 'https://example.com/sponsors', 'confidence' => 'high'],
            ['name' => $marker . ' Exhibitor Co', 'domain' => null, 'role' => 'exhibitor', 'why_relevant' => 'Booth #42.', 'source_url' => 'https://example.com/exhibitors', 'confidence' => 'medium'],
        ],
    ];
    $ftcJobId = (int) $db->insert(
        "INSERT INTO opportunity_research_jobs (job_type, status, conference_id, input_json, result_json, requested_by, completed_at)
         VALUES ('find_target_companies', 'completed', ?, '{}', ?, ?, NOW())",
        [$conferenceId, json_encode($companiesResult), (int) $admin['id']]
    );
    $jobIds[] = $ftcJobId;

    $importDenied = call(Jobs::class, $db, $outsiderAuth, ['jobId' => $ftcJobId, 'action' => 'import'], 'POST', [], ['companies' => [0]]);
    ok(status($importDenied) === 403, 'a role without manage_opportunities cannot import (403)');

    $importResp = call(Jobs::class, $db, $adminAuth, ['jobId' => $ftcJobId, 'action' => 'import'], 'POST', [], ['companies' => [0, 1]]);
    ok(status($importResp) === 200, 'importing selected companies succeeds');
    $summary = payload($importResp)['summary'] ?? [];
    ok(($summary['companies_created'] ?? 0) === 2, 'both new companies were created');
    ok(($summary['companies_linked'] ?? 0) === 2, 'both companies were linked to the conference');

    $linkCount = (int) ($db->one(
        "SELECT COUNT(*) c FROM opportunity_conference_companies occ
         JOIN opportunity_companies oc ON oc.id = occ.company_id
         WHERE occ.conference_id = ? AND oc.name LIKE ?",
        [$conferenceId, $marker . '%']
    )['c'] ?? 0);
    ok($linkCount === 2, 'exactly 2 conference<->company links exist for the imported companies');

    // Re-importing the same selection is a no-op, not a duplicate.
    $reimportResp = call(Jobs::class, $db, $adminAuth, ['jobId' => $ftcJobId, 'action' => 'import'], 'POST', [], ['companies' => [0, 1]]);
    ok(status($reimportResp) === 200, 're-importing the same selection still succeeds');
    $reimportSummary = payload($reimportResp)['summary'] ?? [];
    ok(($reimportSummary['companies_created'] ?? -1) === 0, 're-importing an already-imported item creates nothing new (idempotent)');

    $linkCountAfter = (int) ($db->one(
        "SELECT COUNT(*) c FROM opportunity_conference_companies occ
         JOIN opportunity_companies oc ON oc.id = occ.company_id
         WHERE occ.conference_id = ? AND oc.name LIKE ?",
        [$conferenceId, $marker . '%']
    )['c'] ?? 0);
    ok($linkCountAfter === 2, 'no duplicate links were created on re-import');

    // ── Hand-craft a completed research_company job (contact vs. signal) ────
    $companyResearchResult = [
        'company' => ['industry' => 'Software'],
        'conference_presence' => [],
        'buyer_roles' => [
            ['title' => 'Director of Events', 'name' => $marker . ' Jane Doe', 'email' => null, 'note' => '', 'source_url' => 'https://example.com/team'],
            ['title' => 'Head of Facilities', 'name' => null, 'email' => null, 'note' => 'No named contact found.', 'source_url' => null],
        ],
        'hospitality_signals' => [],
    ];
    $rcJobId = (int) $db->insert(
        "INSERT INTO opportunity_research_jobs (job_type, status, company_id, input_json, result_json, requested_by, completed_at)
         VALUES ('research_company', 'completed', ?, '{}', ?, ?, NOW())",
        [$companyId, json_encode($companyResearchResult), (int) $admin['id']]
    );
    $jobIds[] = $rcJobId;

    $companyBefore = $db->one('SELECT industry FROM opportunity_companies WHERE id = ?', [$companyId]);
    ok(($companyBefore['industry'] ?? null) === null, 'the company has no industry set yet (sanity check)');

    $rcImportResp = call(Jobs::class, $db, $adminAuth, ['jobId' => $rcJobId, 'action' => 'import'], 'POST', [], [
        'company_fields' => ['industry'],
        'buyer_roles' => [0, 1],
    ]);
    ok(status($rcImportResp) === 200, 'importing research_company results succeeds');
    $rcSummary = payload($rcImportResp)['summary'] ?? [];
    ok(($rcSummary['fields_applied'] ?? 0) === 1, 'exactly one company field was applied');
    ok(($rcSummary['contacts_created'] ?? 0) === 1, 'the named, source-backed buyer role became a real contact');
    ok(($rcSummary['signals_created'] ?? 0) === 1, 'the unnamed buyer role became a signal, not a fabricated contact');

    $companyAfter = $db->one('SELECT industry FROM opportunity_companies WHERE id = ?', [$companyId]);
    ok(($companyAfter['industry'] ?? null) === 'Software', 'the company industry field was actually written');

    $namedContact = $db->one('SELECT id, source_url FROM opportunity_contacts WHERE company_id = ? AND name = ?', [$companyId, $marker . ' Jane Doe']);
    ok($namedContact !== null && !empty($namedContact['source_url']), 'the imported contact preserves its source_url');

    $roleSignal = $db->one("SELECT id FROM opportunity_signals WHERE company_id = ? AND description LIKE 'Likely buyer role:%'", [$companyId]);
    ok($roleSignal !== null, 'the unnamed role suggestion was recorded as a signal, never a fabricated person');

    // A second import of the same job with the same field selection does not
    // clobber the now-populated industry column (never overwrite without
    // confirmation) — simulated by re-running with a DIFFERENT requested
    // value already stored in result_json to prove the "only fill empty"
    // rule, not just re-selecting the same value.
    $db->run("UPDATE opportunity_research_jobs SET result_json = ? WHERE id = ?", [
        json_encode(array_replace($companyResearchResult, ['company' => ['industry' => 'Something Else Entirely']])),
        $rcJobId,
    ]);
    call(Jobs::class, $db, $adminAuth, ['jobId' => $rcJobId, 'action' => 'import'], 'POST', [], ['company_fields' => ['industry']]);
    $companyStill = $db->one('SELECT industry FROM opportunity_companies WHERE id = ?', [$companyId]);
    ok(($companyStill['industry'] ?? null) === 'Software', 'an already-set field is never overwritten by a later import');

    // ── Hand-craft a completed generate_outreach_angles job ──────────────────
    $anglesResult = ['angles' => [['title' => 'VIP Reception', 'description' => 'A curated reception.', 'rationale' => 'Strong exec turnout expected.']]];
    $angleJobId = (int) $db->insert(
        "INSERT INTO opportunity_research_jobs (job_type, status, conference_id, company_id, input_json, result_json, requested_by, completed_at)
         VALUES ('generate_outreach_angles', 'completed', ?, ?, '{}', ?, ?, NOW())",
        [$conferenceId, $companyId, json_encode($anglesResult), (int) $admin['id']]
    );
    $jobIds[] = $angleJobId;

    $angleImportResp = call(Jobs::class, $db, $adminAuth, ['jobId' => $angleJobId, 'action' => 'import'], 'POST', [], ['angles' => [0]]);
    ok(status($angleImportResp) === 200, 'importing an outreach angle succeeds');
    $noteRow = $db->one("SELECT id FROM opportunity_notes WHERE note_type = 'strategy' AND body LIKE '%VIP Reception%' AND is_ai_generated = 1 ORDER BY id DESC LIMIT 1");
    ok($noteRow !== null, 'the imported angle became a real, AI-flagged strategy note');
    if ($noteRow) {
        $linkTypes = array_column($db->all('SELECT linked_type FROM opportunity_note_links WHERE note_id = ?', [(int) $noteRow['id']]), 'linked_type');
        sort($linkTypes);
        ok($linkTypes === ['company', 'conference'], 'the imported note is linked to both the conference and the company it was generated for');
    }
} finally {
    // FK-safe order: contacts/signals/note-links before their owning
    // company/conference; research jobs and background jobs have no FK to
    // anything we created, delete last-ish; conference/company last of all.
    try { $db->run('DELETE FROM opportunity_contacts WHERE company_id = ?', [$companyId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup contacts: {$e->getMessage()}\n"); }
    try { $db->run('DELETE FROM opportunity_signals WHERE company_id = ? OR conference_id = ?', [$companyId, $conferenceId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup signals: {$e->getMessage()}\n"); }
    try { $db->run('DELETE FROM opportunity_conference_companies WHERE conference_id = ?', [$conferenceId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup links: {$e->getMessage()}\n"); }
    try {
        $noteIds = array_column($db->all("SELECT id FROM opportunity_notes WHERE body LIKE '%VIP Reception%' AND is_ai_generated = 1"), 'id');
        foreach ($noteIds as $nid) { $db->run('DELETE FROM opportunity_notes WHERE id = ?', [$nid]); }
    } catch (\Throwable $e) { fwrite(STDERR, "cleanup notes: {$e->getMessage()}\n"); }
    foreach ($jobIds as $id) {
        try { $db->run('DELETE FROM opportunity_research_jobs WHERE id = ?', [$id]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup research job $id: {$e->getMessage()}\n"); }
    }
    foreach ($backgroundJobIds as $id) {
        try { $db->run('DELETE FROM background_jobs WHERE id = ?', [$id]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup background job $id: {$e->getMessage()}\n"); }
    }
    if ($companyId) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? OR name LIKE ?', [$companyId, $marker . '%']); } catch (\Throwable $e) { fwrite(STDERR, "cleanup companies: {$e->getMessage()}\n"); }
    }
    if ($conferenceId) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ?', [$conferenceId]); } catch (\Throwable $e) { fwrite(STDERR, "cleanup conference: {$e->getMessage()}\n"); }
    }
    echo "\n  (cleaned up throwaway fixtures)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
