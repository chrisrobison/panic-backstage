<?php
/**
 * DB-backed test for the Opportunities module, Phase 8 (Tasks/activities/
 * realtime/scoring/availability intelligence —
 * docs/OPPORTUNITIES-IMPLEMENTATION.md §4.8).
 *
 * Covers what Phases 1-7's tests don't:
 *   - Conferences::index()/show() and Companies::index()/show() task_count/
 *     overdue_task_count aggregates (real tasks created via the ordinary,
 *     unmodified src/Tasks/Items.php, same "prove it's a real Backstage
 *     task" precedent Phase 3's own conference test established);
 *   - Opportunities::update() logging distinct owner_changed/
 *     probability_changed activity rows (not just a generic 'updated');
 *   - DecisionMakers::create() logging a contact_added activity row;
 *   - Opportunities::riskFlags()'s new Phase 8 codes (no_activity,
 *     proposal_stalled, conference_approaching, target_date_approaching);
 *   - Companies::activity()'s merge of completed opportunity_research_jobs
 *     rows as synthetic "research_completed" activity entries;
 *   - GET /api/opportunities/availability-prospects (capability boundary,
 *     shape, and that a conference with no researched companies gets an
 *     honestly-null estimated_opportunity_pool rather than a fabricated one);
 *   - Opportunities::show()'s new `score` (Scoring::scoreForOpportunity())
 *     wiring — a real end-to-end score/components/reasons response, and
 *     that identifying a decision maker measurably raises buyer_identified.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Prefixes
 * everything it creates with "PB TEST OPP8 — ", and deletes those rows in a
 * finally block regardless of pass/fail. Excluded from the default hermetic
 * pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_phase8_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities;
use Panic\Opportunities\Companies;
use Panic\Opportunities\Conferences;
use Panic\Opportunities\ConferenceCompanies;
use Panic\Opportunities\Contacts;
use Panic\Opportunities\DecisionMakers;
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

echo "\n=== Opportunities module, Phase 8 (Tasks/activities/realtime/scoring/availability, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_phase8_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}
$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPP8 — ' . bin2hex(random_bytes(4));
$created = ['opportunities' => [], 'opportunity_conferences' => [], 'opportunity_companies' => [], 'task_documents' => [], 'research_jobs' => []];

try {
    // ── Fixtures: a company + a conference linked together ──────────────────
    $companyResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Corp']);
    $companyId = (int) (payload($companyResp)['company']['id'] ?? 0);
    ok($companyId > 0, 'create company fixture succeeds');
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    $contactResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $companyId], 'POST', [], [
        'name' => 'Dana Lee', 'title' => 'Field Marketing Director', 'email' => 'dana@example.invalid',
    ]);
    $contactId = (int) (payload($contactResp)['contact']['id'] ?? 0);
    ok($contactId > 0, 'create buyer contact fixture succeeds');

    // Genuinely-free future date (same technique as
    // opportunities_dashboard_db_test.php / room_conflict_guard_db_test.php)
    // for the conference's own span, so empty-night matching behaves
    // predictably against real production event data.
    $busyRows = $db->all(
        "SELECT `date`, end_date FROM events WHERE COALESCE(end_date, `date`) >= CURDATE() AND status NOT IN ('canceled','empty')"
    );
    $busy = [];
    foreach ($busyRows as $row) {
        for ($d = new DateTimeImmutable($row['date']), $end = new DateTimeImmutable($row['end_date'] ?: $row['date']); $d <= $end; $d = $d->modify('+1 day')) {
            $busy[$d->format('Y-m-d')] = true;
        }
    }
    $freeDate = null;
    for ($cursor = new DateTimeImmutable('+8 days'); $cursor < new DateTimeImmutable('+13 days'); $cursor = $cursor->modify('+1 day')) {
        if (!isset($busy[$cursor->format('Y-m-d')])) {
            $freeDate = $cursor->format('Y-m-d');
            break;
        }
    }
    if (!$freeDate) {
        fwrite(STDERR, "Could not find a free day in +8..+13 days — skipping.\n");
        exit(1);
    }

    $confResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Conference', 'starts_at' => $freeDate, 'ends_at' => $freeDate,
        'estimated_attendance' => 5000,
    ]);
    $conferenceId = (int) (payload($confResp)['conference']['id'] ?? 0);
    ok($conferenceId > 0, 'create conference fixture succeeds (on a genuinely free date)');
    if ($conferenceId) { $created['opportunity_conferences'][] = $conferenceId; }
    call(Conferences::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'PATCH', [], ['opportunity_score' => 80]);

    $linkResp = call(ConferenceCompanies::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'POST', [], [
        'company_id' => $companyId, 'role' => 'sponsor',
    ]);
    ok(status($linkResp) === 200, 'linking the company to the conference as a sponsor succeeds');

    // ── Task counts: Conferences::index()/show() and Companies::index()/show() ──
    $ensure = payload(call(TaskLink::class, $db, $adminAuth, ['ownerType' => 'conference', 'ownerId' => $conferenceId], 'POST'));
    $confDocId = (int) ($ensure['task_document_id'] ?? 0);
    ok($confDocId > 0, 'TaskLink provisions a real task_documents row for the conference');

    $overdueTask = payload(call(TaskItems::class, $db, $adminAuth, ['documentId' => $confDocId], 'POST', [], [
        'title' => $marker . ' Follow up with sponsor rep', 'due_date' => date('Y-m-d', strtotime('-2 days')),
    ]));
    $overdueTaskId = (int) ($overdueTask['id'] ?? 0);
    ok($overdueTaskId > 0, 'creating an overdue task via the real, unmodified Tasks endpoint succeeds');

    $confShow = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'GET'));
    ok(($confShow['task_count'] ?? -1) === 1, "Conferences::show() reports task_count=1");
    ok(($confShow['overdue_task_count'] ?? -1) === 1, "Conferences::show() reports overdue_task_count=1 for a past-due open task");

    $confIndex = payload(call(Conferences::class, $db, $adminAuth, [], 'GET', ['q' => $marker]));
    $confRow = null;
    foreach ($confIndex['conferences'] ?? [] as $row) {
        if ((int) $row['id'] === $conferenceId) { $confRow = $row; break; }
    }
    ok($confRow !== null && (int) $confRow['task_count'] === 1 && (int) $confRow['overdue_task_count'] === 1,
        'Conferences::index() aggregates task_count/overdue_task_count for the same conference');

    call(TaskItems::class, $db, $adminAuth, ['documentId' => $confDocId, 'taskId' => $overdueTaskId], 'PATCH', [], ['status' => 'done']);
    $confShowAfterDone = payload(call(Conferences::class, $db, $adminAuth, ['conferenceId' => $conferenceId], 'GET'));
    ok(($confShowAfterDone['task_count'] ?? -1) === 0, 'completing the task drops Conferences::show() task_count back to 0');

    $companyEnsure = payload(call(TaskLink::class, $db, $adminAuth, ['ownerType' => 'company', 'ownerId' => $companyId], 'POST'));
    $companyDocId = (int) ($companyEnsure['task_document_id'] ?? 0);
    call(TaskItems::class, $db, $adminAuth, ['documentId' => $companyDocId], 'POST', [], [
        'title' => $marker . ' Send proposal', 'due_date' => date('Y-m-d', strtotime('+30 days')),
    ]);
    $companyShow = payload(call(Companies::class, $db, $adminAuth, ['companyId' => $companyId], 'GET'));
    ok(($companyShow['task_count'] ?? -1) === 1, 'Companies::show() reports task_count=1');
    ok(($companyShow['overdue_task_count'] ?? -1) === 0, 'a task due in the future is not counted as overdue');

    $companyIndex = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['q' => $marker]));
    $companyRow = null;
    foreach ($companyIndex['companies'] ?? [] as $row) {
        if ((int) $row['id'] === $companyId) { $companyRow = $row; break; }
    }
    ok($companyRow !== null && (int) $companyRow['task_count'] === 1, 'Companies::index() aggregates task_count for the same company');

    // ── Opportunity fixture for activity/risk-flag/scoring assertions ──────
    $oppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Reception', 'company_id' => $companyId, 'conference_id' => $conferenceId,
        'estimated_value' => 20000, 'guest_count_max' => 150, 'stage' => 'qualified',
    ]);
    $oppId = (int) (payload($oppResp)['opportunity']['id'] ?? 0);
    ok($oppId > 0, 'create opportunity fixture succeeds');
    if ($oppId) { $created['opportunities'][] = $oppId; }

    // ── Activity history: owner_changed / probability_changed / contact_added ──
    $otherUser = $db->one('SELECT id FROM users WHERE id != ? AND is_hidden = 0 ORDER BY id LIMIT 1', [(int) $admin['id']]);
    $otherUserId = $otherUser ? (int) $otherUser['id'] : (int) $admin['id'];

    call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], [
        'owner_user_id' => $otherUserId, 'probability' => 60,
    ]);
    $activitiesAfterFirstPatch = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'GET'))['activities'] ?? [];
    $actionsAfterFirstPatch = array_column($activitiesAfterFirstPatch, 'action');
    ok(in_array('owner_changed', $actionsAfterFirstPatch, true), 'changing owner_user_id logs a distinct owner_changed activity');
    ok(in_array('probability_changed', $actionsAfterFirstPatch, true), 'changing probability logs a distinct probability_changed activity');
    ok(!in_array('updated', $actionsAfterFirstPatch, true), 'a PATCH touching ONLY owner_user_id/probability does not also log a redundant generic "updated" entry');

    $ownerChangedRow = null;
    foreach ($activitiesAfterFirstPatch as $row) {
        if ($row['action'] === 'owner_changed') { $ownerChangedRow = $row; break; }
    }
    $ownerDetails = $ownerChangedRow ? json_decode((string) $ownerChangedRow['details_json'], true) : null;
    ok($ownerDetails !== null && (int) $ownerDetails['to'] === $otherUserId, 'owner_changed details record the correct new owner id');

    call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], [
        'owner_user_id' => (int) $admin['id'], 'next_action' => $marker . ' Call to confirm date',
    ]);
    $activitiesAfterSecondPatch = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'GET'))['activities'] ?? [];
    $updatedRow = null;
    foreach ($activitiesAfterSecondPatch as $row) {
        if ($row['action'] === 'updated') { $updatedRow = $row; break; }
    }
    ok($updatedRow !== null, 'a PATCH that also touches a non-specific field (next_action) DOES still log a generic "updated" entry');
    $updatedDetails = $updatedRow ? json_decode((string) $updatedRow['details_json'], true) : null;
    ok($updatedDetails !== null && in_array('next_action', $updatedDetails['fields'] ?? [], true) && !in_array('owner_user_id', $updatedDetails['fields'] ?? [], true),
        'the generic "updated" entry lists next_action but excludes owner_user_id (already covered by its own owner_changed entry)');

    $dmResp = call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'POST', [], [
        'contact_id' => $contactId, 'role' => 'decision_maker',
    ]);
    ok(status($dmResp) === 200, 'adding a decision maker succeeds');
    $activitiesAfterDm = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'GET'))['activities'] ?? [];
    ok(in_array('contact_added', array_column($activitiesAfterDm, 'action'), true), 'adding a decision maker logs a contact_added activity');

    // ── Scoring (Scoring::scoreForOpportunity(), wired into Opportunities::show()) ──
    $showWithBuyer = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    $score = $showWithBuyer['score'] ?? null;
    ok(is_array($score) && isset($score['score'], $score['components'], $score['reasons']), 'show() exposes a {score, components, reasons} shape');
    ok($score['score'] >= 0 && $score['score'] <= 100, 'the score is within 0-100');
    ok(array_sum($score['components']) === $score['score'], 'the score equals the sum of its own components');
    ok(($score['components']['buyer_identified'] ?? 0) === 10, 'buyer_identified hits its max once a decision_maker-role contact is linked');
    ok(($score['components']['company_participation'] ?? 0) === 12, 'company_participation reflects the real sponsor role at the linked conference (12/15)');
    ok(!empty($score['reasons']), 'a well-populated opportunity produces at least one human-readable reason');

    // ── Risk flags: Phase 8 follow-up-intelligence codes ────────────────────
    // conference_approaching / target_date_approaching: the fixture's own
    // linked conference and a newly-set target_date both fall inside the
    // free-date window picked above (well within 14 days).
    call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], ['target_date' => $freeDate]);
    $showApproaching = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    $flagsApproaching = array_column($showApproaching['risk_flags'] ?? [], 'code');
    ok(in_array('conference_approaching', $flagsApproaching, true), 'risk_flags flags conference_approaching for a conference starting within 14 days');
    ok(in_array('target_date_approaching', $flagsApproaching, true), 'risk_flags flags target_date_approaching for a target_date within 14 days');

    // no_activity: backdate the opportunity's own activity trail past NO_ACTIVITY_DAYS(14).
    $db->run('UPDATE opportunity_activities SET created_at = ? WHERE opportunity_id = ?', [date('Y-m-d H:i:s', strtotime('-20 days')), $oppId]);
    $showStale = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(in_array('no_activity', array_column($showStale['risk_flags'] ?? [], 'code'), true), 'risk_flags flags no_activity once every logged activity is older than NO_ACTIVITY_DAYS');

    // proposal_stalled: move to proposal_sent with no next_action_at while activity stays stale.
    call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], ['stage' => 'proposal_sent', 'next_action_at' => null]);
    $db->run('UPDATE opportunity_activities SET created_at = ? WHERE opportunity_id = ?', [date('Y-m-d H:i:s', strtotime('-12 days')), $oppId]);
    $showStalled = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(in_array('proposal_stalled', array_column($showStalled['risk_flags'] ?? [], 'code'), true), 'risk_flags flags proposal_stalled once proposal_sent has no follow-up and stale activity');

    // ── Companies::activity() merges completed research jobs ───────────────
    $jobId = $db->insert(
        "INSERT INTO opportunity_research_jobs (job_type, status, company_id, requested_by, completed_at)
         VALUES ('research_company', 'completed', ?, ?, ?)",
        [$companyId, (int) $admin['id'], date('Y-m-d H:i:s', strtotime('-1 day'))]
    );
    $created['research_jobs'][] = $jobId;
    $companyActivity = payload(call(Companies::class, $db, $adminAuth, ['companyId' => $companyId, 'child' => 'activity'], 'GET'))['activity'] ?? [];
    $researchEntry = null;
    foreach ($companyActivity as $row) {
        if ($row['action'] === 'research_completed') { $researchEntry = $row; break; }
    }
    ok($researchEntry !== null, 'a completed research job scoped to this company appears in Companies::activity() as a synthetic entry');
    ok($researchEntry !== null && ($researchEntry['details']['job_type'] ?? null) === 'research_company', 'the synthetic research_completed entry carries the real job_type');

    // ── Find prospects for empty dates (GET /opportunities/availability-prospects) ──
    $deniedProspects = call(Opportunities::class, $db, $outsiderAuth, ['action' => 'availability-prospects'], 'GET', [
        'from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+13 days')),
    ]);
    ok(status($deniedProspects) === 403, 'a role without view_opportunities cannot call availability-prospects');

    $missingTo = call(Opportunities::class, $db, $adminAuth, ['action' => 'availability-prospects'], 'GET', ['from' => date('Y-m-d')]);
    ok(status($missingTo) === 422, 'availability-prospects requires a "to" date');

    $prospectsResp = payload(call(Opportunities::class, $db, $adminAuth, ['action' => 'availability-prospects'], 'GET', [
        'from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+13 days')),
    ]));
    $prospectRow = null;
    foreach ($prospectsResp['prospects'] ?? [] as $row) {
        if ((int) ($row['conference']['id'] ?? 0) === $conferenceId) { $prospectRow = $row; break; }
    }
    ok($prospectRow !== null, 'the fixture conference\'s own empty night appears in availability-prospects for a matching range');
    ok($prospectRow !== null && (int) $prospectRow['target_company_count'] === 1, 'the prospect row reports the real linked-company count (1)');
    ok($prospectRow !== null && $prospectRow['estimated_opportunity_pool']['is_heuristic'] === true, 'estimated_opportunity_pool is always explicitly labeled a heuristic');
    ok($prospectRow !== null && is_string($prospectRow['estimated_opportunity_pool']['basis'] ?? null) && $prospectRow['estimated_opportunity_pool']['basis'] !== '',
        'estimated_opportunity_pool always carries a human-readable basis string, never a bare number with no explanation');
} finally {
    foreach ($created['research_jobs'] as $id) {
        try { $db->run('DELETE FROM opportunity_research_jobs WHERE id = ?', [$id]); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for research job $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunities'] as $id) {
        try { $db->run('DELETE FROM opportunities WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP8 — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for opportunity $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        $doc = $db->one('SELECT task_document_id FROM opportunity_conferences WHERE id = ?', [$id]);
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP8 — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
        if (!empty($doc['task_document_id'])) {
            try { $db->run('DELETE FROM tasks WHERE document_id = ?', [$doc['task_document_id']]); } catch (\Throwable $e) {}
            try { $db->run('DELETE FROM task_documents WHERE id = ?', [$doc['task_document_id']]); } catch (\Throwable $e) {}
        }
    }
    foreach ($created['opportunity_companies'] as $id) {
        $doc = $db->one('SELECT task_document_id FROM opportunity_companies WHERE id = ?', [$id]);
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPP8 — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
        if (!empty($doc['task_document_id'])) {
            try { $db->run('DELETE FROM tasks WHERE document_id = ?', [$doc['task_document_id']]); } catch (\Throwable $e) {}
            try { $db->run('DELETE FROM task_documents WHERE id = ?', [$doc['task_document_id']]); } catch (\Throwable $e) {}
        }
    }
    $total = count($created['opportunities']) + count($created['opportunity_conferences']) + count($created['opportunity_companies']) + count($created['research_jobs']);
    echo "\n  (cleaned up $total throwaway row(s) plus their contact/task/decision-maker children)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
