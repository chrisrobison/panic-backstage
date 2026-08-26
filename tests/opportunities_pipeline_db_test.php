<?php
/**
 * DB-backed test for the Opportunities module, Phase 5 (Pipeline board +
 * Opportunity detail + conversion — docs/OPPORTUNITIES-IMPLEMENTATION.md).
 *
 * Covers what Phase 1-4's tests don't:
 *   - Qualification checklist: lazy default (all-false, no row yet), PATCH
 *     toggling a subset, completed_count math, capability boundary;
 *   - Decision makers: create/list, duplicate-link rejection (409),
 *     cross-company contact rejection (422), delete;
 *   - Opportunities::index()'s new per-card aggregates (note_count,
 *     task_count, warnings) added for the Pipeline board, including the
 *     'stale' and 'date_conflict' warnings;
 *   - Opportunities::show()'s new risk_flags/budget_fit/resources sections;
 *   - The manual "Log Activity" POST on /opportunities/{id}/activities;
 *   - Conversion to event (Opportunities\Conversion): creates exactly one
 *     event prefilled from the opportunity, sets won_event_id/converted_at/
 *     stage=won, is idempotent on a second call, and rejects a lost
 *     opportunity.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Prefixes
 * everything it creates with "PB TEST OPPPIPE — ", and deletes those rows in
 * a finally block regardless of pass/fail. Excluded from the default
 * hermetic pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_pipeline_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities;
use Panic\Opportunities\Companies;
use Panic\Opportunities\Contacts;
use Panic\Opportunities\DecisionMakers;
use Panic\Opportunities\Qualification;
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

echo "\n=== Opportunities module, Phase 5 (Pipeline + Opportunity detail + conversion, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_pipeline_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}
$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPPPIPE — ' . bin2hex(random_bytes(4));
$created = ['events' => [], 'opportunities' => [], 'opportunity_companies' => []];

try {
    // ── Fixtures: a company + two contacts + an open opportunity ────────────
    $companyResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Corp']);
    $companyId = (int) (payload($companyResp)['company']['id'] ?? 0);
    ok($companyId > 0, 'create company fixture succeeds');
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    $contactAResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $companyId], 'POST', [], [
        'name' => 'Dana Lee', 'title' => 'Field Marketing Director', 'email' => 'dana@example.invalid',
    ]);
    $contactAId = (int) (payload($contactAResp)['contact']['id'] ?? 0);
    ok($contactAId > 0, 'create buyer contact A succeeds');

    $otherCompanyResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Other Corp']);
    $otherCompanyId = (int) (payload($otherCompanyResp)['company']['id'] ?? 0);
    if ($otherCompanyId) { $created['opportunity_companies'][] = $otherCompanyId; }
    $foreignContactResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $otherCompanyId], 'POST', [], ['name' => 'Foreign Contact']);
    $foreignContactId = (int) (payload($foreignContactResp)['contact']['id'] ?? 0);

    $targetDate = date('Y-m-d', strtotime('+21 days'));
    $oppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Reception', 'company_id' => $companyId,
        'estimated_value' => 42000, 'guest_count_min' => 100, 'guest_count_max' => 150,
        'target_date' => $targetDate, 'stage' => 'qualified',
    ]);
    $oppId = (int) (payload($oppResp)['opportunity']['id'] ?? 0);
    ok($oppId > 0, 'create opportunity fixture succeeds');
    if ($oppId) { $created['opportunities'][] = $oppId; }

    // ── Qualification: lazy default, then PATCH a subset ────────────────────
    $qualGetDefault = payload(call(Qualification::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(($qualGetDefault['qualification']['completed_count'] ?? -1) === 0, 'a brand new opportunity reads back an all-false qualification checklist (no row yet)');
    ok(($qualGetDefault['qualification']['total_count'] ?? 0) === 9, 'qualification checklist has exactly 9 items');

    $deniedQualPatch = call(Qualification::class, $db, $outsiderAuth, ['opportunityId' => $oppId], 'PATCH', [], ['decision_makers_identified' => true]);
    ok(status($deniedQualPatch) === 403, 'a role without manage_opportunities cannot update the qualification checklist');

    call(Qualification::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], [
        'decision_makers_identified' => true, 'guest_range_confirmed' => true, 'budget_range_identified' => true,
    ]);
    $qualAfterPatch = payload(call(Qualification::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(($qualAfterPatch['qualification']['completed_count'] ?? -1) === 3, 'qualification completed_count reflects the 3 items just set');
    ok((bool) ($qualAfterPatch['qualification']['event_objective_understood'] ?? true) === false, 'an item never PATCHed stays false');

    // ── Decision makers: add, duplicate rejected, cross-company rejected, remove ──
    $addDm = call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'POST', [], [
        'contact_id' => $contactAId, 'role' => 'influencer',
    ]);
    ok(status($addDm) === 200, 'adding a same-company decision maker succeeds');
    $dmLinkId = (int) (payload($addDm)['decision_maker']['id'] ?? 0);
    ok($dmLinkId > 0, 'decision maker link has an id');

    $dupDm = call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'POST', [], [
        'contact_id' => $contactAId, 'role' => 'champion',
    ]);
    ok(status($dupDm) === 409, 'linking the same contact twice to the same opportunity is rejected');

    $foreignDm = call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'POST', [], [
        'contact_id' => $foreignContactId, 'role' => 'influencer',
    ]);
    ok(status($foreignDm) === 422, 'linking a contact belonging to a different company is rejected');

    $dmList = payload(call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    ok(count($dmList['decision_makers'] ?? []) === 1, 'decision-makers list shows exactly the one valid link');
    ok(($dmList['decision_makers'][0]['role'] ?? null) === 'influencer', 'the listed decision maker keeps its assigned role');

    // ── Risk flags: an 'influencer'-only link still counts as no real
    // decision maker/champion identified -> flagged; budget unapproved
    // (no budget_range_min set) -> flagged. ──────────────────────────────────
    $showBeforeDmRole = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    $flagCodes = array_column($showBeforeDmRole['risk_flags'] ?? [], 'code');
    ok(in_array('no_decision_maker', $flagCodes, true), 'risk_flags flags no_decision_maker when only an influencer-role link exists (no champion/decision_maker)');
    ok(in_array('budget_unapproved', $flagCodes, true), 'risk_flags flags budget_unapproved when budget_range_min is unset');
    ok(in_array('no_followup_scheduled', $flagCodes, true), 'risk_flags flags no_followup_scheduled when next_action_at is unset');

    // Swap the influencer link for a decision_maker-role link — verify the
    // flag clears specifically because the role now qualifies.
    call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId, 'linkId' => $dmLinkId], 'DELETE');
    call(DecisionMakers::class, $db, $adminAuth, ['opportunityId' => $oppId], 'POST', [], ['contact_id' => $contactAId, 'role' => 'decision_maker']);
    call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'PATCH', [], ['budget_range_min' => 30000, 'budget_range_max' => 50000]);
    $showAfterFix = payload(call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId], 'GET'));
    $flagCodesAfter = array_column($showAfterFix['risk_flags'] ?? [], 'code');
    ok(!in_array('no_decision_maker', $flagCodesAfter, true), 'no_decision_maker clears once a decision_maker-role link exists');
    ok(!in_array('budget_unapproved', $flagCodesAfter, true), 'budget_unapproved clears once budget_range_min is set');
    ok(($showAfterFix['budget_fit']['status'] ?? null) === 'within_range', 'budget_fit reports within_range once estimated_value falls inside budget_range_min/max');

    // ── Venue resource options — real configured rooms, never hard-coded ────
    $resources = $showAfterFix['resources'] ?? [];
    ok(count($resources) > 0, 'show() returns this tenant\'s real configured venue resources (not a hard-coded Upstairs/Downstairs pair)');
    $recommendedCount = count(array_filter($resources, fn ($r) => $r['recommended']));
    ok($recommendedCount === 1, 'exactly one resource is flagged recommended for a 100-150 guest range');

    // ── date_conflict warning: book a real event on the opportunity's own
    // target_date, then confirm the Pipeline board's index() picks it up. ──
    $venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
    $conflictEventId = $db->insert(
        "INSERT INTO events (venue_id, title, slug, event_type, status, date, created_at)
         VALUES (?,?,?,?,?,?,NOW())",
        [(int) $venue['id'], $marker . ' Conflict Event', 'pb-test-opppipe-conflict-' . bin2hex(random_bytes(3)), 'special_event', 'confirmed', $targetDate]
    );
    $created['events'][] = $conflictEventId;

    $indexAfterConflict = payload(call(Opportunities::class, $db, $adminAuth, [], 'GET', ['company_id' => (string) $companyId]));
    $cardAfterConflict = current(array_filter($indexAfterConflict['opportunities'] ?? [], fn ($o) => (int) $o['id'] === $oppId));
    ok($cardAfterConflict !== false, 'the pipeline list includes our fixture opportunity');
    ok(in_array('date_conflict', $cardAfterConflict['warnings'] ?? [], true), 'index() flags date_conflict once another event is booked on the same target_date');
    ok(($cardAfterConflict['note_count'] ?? -1) === 0, 'index() attaches note_count (0 — no notes added yet)');
    ok(array_key_exists('task_count', $cardAfterConflict), 'index() attaches task_count');
    ok(!empty($indexAfterConflict['users']), 'index() attaches a users list for the Pipeline board\'s Owner filter');

    // Force 'stale' by backdating updated_at directly (the only way to
    // exercise it without waiting 21 real days).
    $db->run('UPDATE opportunities SET updated_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?', [$oppId]);
    $indexAfterStale = payload(call(Opportunities::class, $db, $adminAuth, [], 'GET', ['company_id' => (string) $companyId]));
    $cardAfterStale = current(array_filter($indexAfterStale['opportunities'] ?? [], fn ($o) => (int) $o['id'] === $oppId));
    ok($cardAfterStale && in_array('stale', $cardAfterStale['warnings'] ?? [], true), 'index() flags stale once updated_at is >21 days old');
    // Restore updated_at so later assertions in this file are unaffected.
    $db->run('UPDATE opportunities SET updated_at = NOW() WHERE id = ?', [$oppId]);

    // ── Manual "Log Activity" ────────────────────────────────────────────────
    $deniedLog = call(Opportunities::class, $db, $outsiderAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'POST', [], ['note' => 'nope']);
    ok(status($deniedLog) === 403, 'a role without manage_opportunities cannot log a manual activity');

    $missingNote = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'POST', [], ['activity_type' => 'call']);
    ok(status($missingNote) === 422, 'logging an activity with no note is rejected');

    $logActivity = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'activities'], 'POST', [], [
        'activity_type' => 'call', 'note' => 'Spoke with Dana about budget.',
    ]);
    ok(status($logActivity) === 200, 'logging a manual call activity succeeds');
    $activities = payload($logActivity)['activities'] ?? [];
    $callEntry = current(array_filter($activities, fn ($a) => $a['action'] === 'call_logged'));
    ok($callEntry !== false, 'the manual log entry appears in the feed with action=call_logged');

    // ── Convert to event: idempotent, prefilled, rejects a lost opportunity ──
    $convertResp = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'convert'], 'POST');
    ok(status($convertResp) === 200, 'converting a qualified opportunity to an event succeeds');
    $convertPayload = payload($convertResp);
    $eventId = (int) ($convertPayload['event_id'] ?? 0);
    ok($eventId > 0, 'conversion returns a real event id');
    ok($convertPayload['already_converted'] === false, 'the first conversion is not already_converted');
    if ($eventId) { $created['events'][] = $eventId; }

    $event = $db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
    ok($event !== null, 'the converted event row actually exists');
    ok($event['title'] === $marker . ' Reception', 'the converted event\'s title is prefilled from the opportunity name');
    ok($event['date'] === $targetDate, 'the converted event\'s date is prefilled from target_date');
    ok((int) $event['estimated_guests'] === 150, 'the converted event\'s estimated_guests is prefilled from guest_count_max');
    ok((float) $event['potential_revenue'] === 42000.0, 'the converted event\'s potential_revenue is prefilled from estimated_value');
    ok($event['client_org'] === $marker . ' Corp', 'the converted event\'s client_org is prefilled from the company name');
    ok($event['status'] === 'proposed', 'the converted event starts in proposed status, same as every other event-creation path');

    $oppAfterConvert = $db->one('SELECT stage, won_event_id, converted_at FROM opportunities WHERE id = ?', [$oppId]);
    ok($oppAfterConvert['stage'] === 'won', 'the opportunity moves to won stage after conversion');
    ok((int) $oppAfterConvert['won_event_id'] === $eventId, 'opportunities.won_event_id is set to the created event');
    ok($oppAfterConvert['converted_at'] !== null, 'opportunities.converted_at is set');

    $convertAgain = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $oppId, 'child' => 'convert'], 'POST');
    ok(status($convertAgain) === 200, 'converting an already-converted opportunity still returns 200 (idempotent, not an error)');
    $convertAgainPayload = payload($convertAgain);
    ok((int) $convertAgainPayload['event_id'] === $eventId, 'a second conversion returns the SAME event id rather than creating a new one');
    ok($convertAgainPayload['already_converted'] === true, 'a second conversion reports already_converted: true');
    $eventCountAfter = (int) ($db->one('SELECT COUNT(*) c FROM events WHERE title = ?', [$marker . ' Reception'])['c'] ?? 0);
    ok($eventCountAfter === 1, 'exactly one event exists for this opportunity even after converting twice');

    // A separate lost opportunity cannot be converted.
    $lostOppResp = call(Opportunities::class, $db, $adminAuth, [], 'POST', [], [
        'name' => $marker . ' Lost Opp', 'company_id' => $companyId, 'stage' => 'lost',
    ]);
    $lostOppId = (int) (payload($lostOppResp)['opportunity']['id'] ?? 0);
    if ($lostOppId) { $created['opportunities'][] = $lostOppId; }
    $convertLost = call(Opportunities::class, $db, $adminAuth, ['opportunityId' => $lostOppId, 'child' => 'convert'], 'POST');
    ok(status($convertLost) === 422, 'a lost opportunity cannot be converted to an event');
} finally {
    foreach ($created['events'] as $id) {
        try { $db->run('DELETE FROM events WHERE id = ? AND title LIKE ?', [$id, 'PB TEST OPPPIPE — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for event $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunities'] as $id) {
        try { $db->run('DELETE FROM opportunities WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPPIPE — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for opportunity $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPPIPE — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    $total = count($created['events']) + count($created['opportunities']) + count($created['opportunity_companies']);
    echo "\n  (cleaned up $total throwaway row(s) plus their contact/qualification/decision-maker children)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
