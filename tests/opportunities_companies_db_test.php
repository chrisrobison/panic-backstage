<?php
/**
 * DB-backed test for the Opportunities module, Phase 4 (Company list +
 * Company Detail + buyer contacts — docs/OPPORTUNITIES-IMPLEMENTATION.md).
 *
 * Covers what Phase 1/2/3's tests don't:
 *   - Companies::index() filters (industry, conference_id, researched,
 *     has_open_opportunities) and sorts (pipeline_value, open_opportunities,
 *     conferences, research) — via the single-query derived-table aggregate
 *     join, verified to give correct (non-fanned-out) numbers;
 *   - Companies::show()'s new computed sections (kpis, conference why_relevant,
 *     venue_fit_tags, pitch_ideas) and the new /activity aggregate feed;
 *   - Opportunities/Contacts.php CRUD, email-dedup-within-company rejection,
 *     and the deterministic is_likely_buyer keyword match;
 *   - opportunities.primary_contact_id validation (must belong to the
 *     opportunity's own company) and that deleting a contact clears it via
 *     the real ON DELETE SET NULL FK;
 *   - Notes.php now accepting linked_type=contact (Phase 3 rejected it).
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Prefixes
 * everything it creates with "PB TEST OPPCO — ", and deletes those rows in a
 * finally block regardless of pass/fail. Excluded from the default hermetic
 * pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_companies_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities;
use Panic\Opportunities\Companies;
use Panic\Opportunities\ConferenceCompanies;
use Panic\Opportunities\Conferences;
use Panic\Opportunities\Contacts;
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

echo "\n=== Opportunities module, Phase 4 (Companies + Contacts, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_companies_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}
$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPPCO — ' . bin2hex(random_bytes(4));
$created = ['opportunity_companies' => [], 'opportunity_conferences' => [], 'opportunities' => []];

try {
    // ── Two companies: one tech/large/sponsor (rich), one bare-bones ────────
    $richResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Rich Corp', 'industry' => 'Enterprise Software', 'employee_range' => '10,001+',
        'hq_city' => 'Santa Clara', 'hq_state' => 'CA', 'relationship_status' => 'active',
    ]);
    $richId = (int) (payload($richResp)['company']['id'] ?? 0);
    ok($richId > 0, 'create rich company succeeds');
    if ($richId) { $created['opportunity_companies'][] = $richId; }

    // create() has no last_researched_at column (only update() writes it) —
    // mark it researched via a follow-up PATCH, same as the Conferences test.
    call(Companies::class, $db, $adminAuth, ['companyId' => $richId], 'PATCH', [], ['last_researched_at' => date('Y-m-d H:i:s')]);

    $bareResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Bare Co']);
    $bareId = (int) (payload($bareResp)['company']['id'] ?? 0);
    ok($bareId > 0, 'create bare company succeeds');
    if ($bareId) { $created['opportunity_companies'][] = $bareId; }

    $deniedCreate = call(Companies::class, $db, $outsiderAuth, [], 'POST', [], ['name' => 'nope']);
    ok(status($deniedCreate) === 403, 'a role without manage_opportunities cannot create a company');

    // ── Conference + participation link (drives conference_id filter, why_relevant, venue fit) ──
    $confResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Conf', 'starts_at' => date('Y-m-d', strtotime('+45 days')),
    ]);
    $confId = (int) (payload($confResp)['conference']['id'] ?? 0);
    ok($confId > 0, 'create conference for company test succeeds');
    if ($confId) { $created['opportunity_conferences'][] = $confId; }

    call(ConferenceCompanies::class, $db, $adminAuth, ['conferenceId' => $confId], 'POST', [], [
        'company_id' => $richId, 'role' => 'headline_sponsor', 'sponsor_tier' => 'Platinum',
    ]);

    // ── An open opportunity on the rich company (drives pipeline_value/open_opportunities) ──
    $oppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Reception', 'company_id' => $richId, 'conference_id' => $confId,
        'estimated_value' => 50000, 'stage' => 'qualified',
    ]);
    $oppId = (int) (payload($oppResp)['opportunity']['id'] ?? 0);
    ok($oppId > 0, 'create opportunity for rich company succeeds');
    if ($oppId) { $created['opportunities'][] = $oppId; }

    // ── index(): filters ──────────────────────────────────────────────────
    $byIndustry = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['industry' => 'Enterprise', 'q' => $marker]));
    $industryIds = array_column($byIndustry['companies'] ?? [], 'id');
    ok(in_array($richId, $industryIds, true) && !in_array($bareId, $industryIds, true), 'industry filter returns only the matching company');

    $byConference = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['conference_id' => (string) $confId, 'q' => $marker]));
    $confCompanyIds = array_column($byConference['companies'] ?? [], 'id');
    ok(in_array($richId, $confCompanyIds, true) && !in_array($bareId, $confCompanyIds, true), 'conference_id filter returns only the participating company');

    $researched = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['researched' => '1', 'q' => $marker]));
    $researchedIds = array_column($researched['companies'] ?? [], 'id');
    ok(in_array($richId, $researchedIds, true) && !in_array($bareId, $researchedIds, true), 'researched=1 filter excludes the unresearched company');

    $hasOpen = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['has_open_opportunities' => '1', 'q' => $marker]));
    $hasOpenIds = array_column($hasOpen['companies'] ?? [], 'id');
    ok(in_array($richId, $hasOpenIds, true) && !in_array($bareId, $hasOpenIds, true), 'has_open_opportunities=1 filter excludes the company with no opportunities');

    // ── index(): sorts + aggregate correctness (no fan-out from the two joins) ──
    $byPipeline = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['sort' => 'pipeline_value', 'q' => $marker]));
    $pipelineRows = $byPipeline['companies'] ?? [];
    ok(($pipelineRows[0]['id'] ?? null) === $richId, 'sort=pipeline_value puts the company with an open opportunity first');
    $richRow = current(array_filter($pipelineRows, fn ($c) => (int) $c['id'] === $richId));
    ok($richRow && (float) $richRow['pipeline_value'] === 50000.0, 'pipeline_value is exactly the opportunity value, not multiplied by the conference-link join (fan-out check)');
    ok($richRow && (int) $richRow['open_opportunity_count'] === 1, 'open_opportunity_count is exactly 1, not fanned out');
    ok($richRow && (int) $richRow['conference_count'] === 1, 'conference_count reflects the one linked conference');

    $byConferences = payload(call(Companies::class, $db, $adminAuth, [], 'GET', ['sort' => 'conferences', 'q' => $marker]));
    ok(($byConferences['companies'][0]['id'] ?? null) === $richId, 'sort=conferences puts the company with a linked conference first');

    // ── show(): kpis, why_relevant, venue_fit_tags, pitch_ideas ──────────────
    $showRich = payload(call(Companies::class, $db, $adminAuth, ['companyId' => $richId], 'GET'));
    ok(($showRich['kpis']['open_opportunity_count'] ?? null) === 1, 'show() kpis.open_opportunity_count is correct');
    ok((float) ($showRich['kpis']['pipeline_value'] ?? 0) === 50000.0, 'show() kpis.pipeline_value is correct');
    ok(($showRich['kpis']['conference_count'] ?? null) === 1, 'show() kpis.conference_count is correct');
    $confLink = $showRich['conferences'][0] ?? [];
    ok(str_contains((string) ($confLink['why_relevant'] ?? ''), 'Platinum'), 'show() conference link has a real why_relevant string mentioning the sponsor tier');
    ok(in_array('executive_visibility', $showRich['venue_fit_tags'] ?? [], true), 'venue_fit_tags includes executive_visibility for a headline sponsor');
    ok(in_array('large_audience', $showRich['venue_fit_tags'] ?? [], true), 'venue_fit_tags includes large_audience for a 10,001+ employee company');
    ok(in_array('tech_and_innovation', $showRich['venue_fit_tags'] ?? [], true), 'venue_fit_tags includes tech_and_innovation for a software industry match');
    ok(!empty($showRich['pitch_ideas']), 'show() returns at least one pitch idea when venue-fit tags are present');

    $showBare = payload(call(Companies::class, $db, $adminAuth, ['companyId' => $bareId], 'GET'));
    ok(empty($showBare['venue_fit_tags']), 'a bare company with no data gets no venue-fit tags');
    ok(!empty($showBare['pitch_ideas']) && str_contains($showBare['pitch_ideas'][0], 'Not enough data'), 'a bare company gets the honest "not enough data" pitch-idea fallback, not a fabricated one');

    // ── activity() aggregate feed ─────────────────────────────────────────
    $activity = payload(call(Companies::class, $db, $adminAuth, ['companyId' => $richId, 'child' => 'activity'], 'GET'));
    $actions = array_column($activity['activity'] ?? [], 'action');
    ok(in_array('created', $actions, true), 'company activity feed includes the real "created" event from its opportunity');

    // ── Opportunity Contacts CRUD + dedup + is_likely_buyer ─────────────────
    $deniedContact = call(Contacts::class, $db, $outsiderAuth, ['companyId' => $richId], 'POST', [], ['name' => 'nope']);
    ok(status($deniedContact) === 403, 'a role without manage_opportunities cannot create a contact');

    $contactResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $richId], 'POST', [], [
        'name' => 'Jane Smith', 'title' => 'Field Marketing Director', 'email' => 'Jane.Smith@Example.com',
    ]);
    ok(status($contactResp) === 200, 'create contact succeeds');
    $contactPayload = payload($contactResp)['contact'] ?? [];
    $contactId = (int) ($contactPayload['id'] ?? 0);
    ok($contactId > 0, 'created contact has an id');
    ok(($contactPayload['email'] ?? null) === 'jane.smith@example.com', 'email is normalized to lowercase');
    ok(($contactPayload['is_likely_buyer'] ?? null) === true, 'a "Field Marketing Director" title is flagged is_likely_buyer');

    $nonBuyerResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $richId], 'POST', [], [
        'name' => 'Bob Ledger', 'title' => 'Staff Accountant',
    ]);
    $nonBuyerContactId = (int) (payload($nonBuyerResp)['contact']['id'] ?? 0);
    ok((payload($nonBuyerResp)['contact']['is_likely_buyer'] ?? null) === false, 'a "Staff Accountant" title is not flagged is_likely_buyer');

    $dupResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $richId], 'POST', [], [
        'name' => 'Jane S. Duplicate', 'email' => 'jane.smith@example.com',
    ]);
    ok(status($dupResp) === 422, 'a second contact with the same normalized email at the same company is rejected, not silently duplicated');

    $listContacts = payload(call(Contacts::class, $db, $adminAuth, ['companyId' => $richId], 'GET'));
    ok(count($listContacts['contacts'] ?? []) === 2, 'listing contacts returns exactly the two created (dedup rejected the third)');

    $updateResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $richId, 'contactId' => $contactId], 'PATCH', [], ['status' => 'active']);
    ok((payload($updateResp)['contact']['status'] ?? null) === 'active', 'updating a contact status succeeds');

    // ── Notes can now link to a contact (Phase 3 rejected this) ─────────────
    $noteResp = call(Notes::class, $db, $adminAuth, ['linkedType' => 'contact', 'linkedId' => $contactId], 'POST', [], [
        'body' => $marker . ' Talked to Jane about GTC sponsorship',
    ]);
    ok(status($noteResp) === 200, 'a note can now link to a contact (Phase 4 lifts the Phase 3 rejection)');

    $badLinkResp = call(Notes::class, $db, $adminAuth, ['linkedType' => 'contact', 'linkedId' => 999999999], 'POST', [], ['body' => 'nope']);
    ok(status($badLinkResp) === 422, 'linking a note to a non-existent contact id is still rejected');

    // ── opportunities.primary_contact_id: cross-company rejection + valid set ──
    $wrongCompanyContact = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Bad Contact Opp', 'company_id' => $bareId, 'primary_contact_id' => $contactId,
    ]);
    ok(status($wrongCompanyContact) === 422, 'primary_contact_id from a different company is rejected on create');

    $setContact = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], ['primary_contact_id' => $contactId]);
    ok(status($setContact) === 200, 'setting a valid same-company primary_contact_id succeeds');

    $showOpp = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(($showOpp['opportunity']['primary_contact_name'] ?? null) === 'Jane Smith', 'the opportunity detail now shows the joined primary_contact_name');

    $dashboard = payload(call(Opportunities::class, $db, $adminAuth, ['action' => 'dashboard'], 'GET'));
    $bestRow = current(array_filter($dashboard['best_opportunities'] ?? [], fn ($o) => (int) $o['id'] === $oppId));
    ok($bestRow && ($bestRow['primary_contact_name'] ?? null) === 'Jane Smith', 'the dashboard "Best Opportunities" row now shows a real Likely Buyer name once set');

    // ── Deleting the contact clears primary_contact_id via the real FK ──────
    $deleteContact = call(Contacts::class, $db, $adminAuth, ['companyId' => $richId, 'contactId' => $contactId], 'DELETE');
    ok(status($deleteContact) === 204, 'delete contact succeeds');
    ok(!$db->one('SELECT id FROM opportunity_contacts WHERE id = ?', [$contactId]), 'contact is actually gone after delete');
    $oppAfterContactDelete = $db->one('SELECT primary_contact_id FROM opportunities WHERE id = ?', [$oppId]);
    ok($oppAfterContactDelete && $oppAfterContactDelete['primary_contact_id'] === null, 'deleting the contact clears the opportunity\'s primary_contact_id via ON DELETE SET NULL');
    ok(!$db->one("SELECT id FROM opportunity_note_links WHERE linked_type = 'contact' AND linked_id = ?", [$contactId]), 'deleting the contact also removes its polymorphic note links');

    call(Contacts::class, $db, $adminAuth, ['companyId' => $richId, 'contactId' => $nonBuyerContactId], 'DELETE');

    // ── Company deletion: guarded while opportunities exist, then allowed ───
    $deniedDeleteCompany = call(Companies::class, $db, $outsiderAuth, ['companyId' => $bareId], 'DELETE');
    ok(status($deniedDeleteCompany) === 403, 'a role without manage_opportunities cannot delete a company');

    $blockedDelete = call(Companies::class, $db, $adminAuth, ['companyId' => $richId], 'DELETE');
    ok(status($blockedDelete) === 422, 'deleting a company with an existing opportunity is rejected (no ON DELETE fallback on opportunities.company_id)');
    ok((bool) $db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$richId]), 'the company still exists after the blocked delete');

    $bareDelete = call(Companies::class, $db, $adminAuth, ['companyId' => $bareId], 'DELETE');
    ok(status($bareDelete) === 204, 'deleting a company with no opportunities succeeds');
    ok(!$db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$bareId]), 'the bare company is actually gone after delete');
    $created['opportunity_companies'] = array_values(array_diff($created['opportunity_companies'], [$bareId]));
} finally {
    foreach ($created['opportunities'] as $id) {
        try { $db->run('DELETE FROM opportunities WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPCO — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for opportunity $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPCO — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPCO — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    $total = count($created['opportunities']) + count($created['opportunity_conferences']) + count($created['opportunity_companies']);
    echo "\n  (cleaned up $total throwaway row(s) plus their contact/note/signal children)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
