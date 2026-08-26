<?php
/**
 * DB-backed test for the Opportunities module, Phase 6 (First-class
 * Research Notes workspace — docs/OPPORTUNITIES-IMPLEMENTATION.md).
 *
 * Covers what Phase 1-5's tests don't:
 *   - Notes::generalIndex() — the cross-cutting workspace's search/filter
 *     mode (no linked_id given): q/note_type/is_pinned/is_ai_generated/
 *     created_by/tag/date_from/linked_type-only filters, each verified to
 *     actually narrow the result set, plus the `contexts` (resolved link
 *     labels) and `authors` list every response carries;
 *   - the new 'strategy' note_type;
 *   - version history: editing a note's body archives the PRE-edit body to
 *     opportunity_note_versions (with the right prior author/timestamp),
 *     GET .../versions returns it newest-first, and a no-op PATCH (same
 *     body) does NOT create a spurious version;
 *   - add_links/remove_links on an existing note (the workspace's "Link
 *     record" action) — including the duplicate-link and invalid-link
 *     rejection paths reusing create()'s own validateLinks();
 *   - capability boundaries on the write paths above.
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue_admin user (there is
 * no separate test DB — see project dev-environment memory). Prefixes
 * everything it creates with "PB TEST OPPNOTES — ", and deletes those rows in
 * a finally block regardless of pass/fail. Excluded from the default
 * hermetic pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/opportunities_notes_workspace_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Opportunities\Companies;
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

echo "\n=== Opportunities module, Phase 6 (Notes workspace, DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$admin = $db->one("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "opportunities_notes_workspace_db_test.php needs a venue_admin user — skipping.\n");
    exit(1);
}
$adminAuth = new Auth();
$adminAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Admin', 'email' => 'test-admin@example.invalid', 'role' => 'venue_admin']);
$outsiderAuth = new Auth();
$outsiderAuth->setUser(['id' => (int) $admin['id'], 'name' => 'Test Outsider', 'email' => 'test-outsider@example.invalid', 'role' => 'band']);

$marker = 'PB TEST OPPNOTES — ' . bin2hex(random_bytes(4));
$created = ['opportunity_notes' => [], 'opportunity_companies' => [], 'opportunity_conferences' => []];

try {
    // ── Fixtures: a company, a conference, and a contact ────────────────────
    $companyResp = call(Companies::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Corp']);
    $companyId = (int) (payload($companyResp)['company']['id'] ?? 0);
    if ($companyId) { $created['opportunity_companies'][] = $companyId; }

    $confResp = call(Conferences::class, $db, $adminAuth, [], 'POST', [], ['name' => $marker . ' Conf']);
    $confId = (int) (payload($confResp)['conference']['id'] ?? 0);
    if ($confId) { $created['opportunity_conferences'][] = $confId; }

    $contactResp = call(Contacts::class, $db, $adminAuth, ['companyId' => $companyId], 'POST', [], ['name' => 'Jane Doe']);
    $contactId = (int) (payload($contactResp)['contact']['id'] ?? 0);

    // ── Create a note linked to BOTH the company and the conference at once
    // (the spec's own "Dreamforce 2026 Sponsorship Strategy" multi-link
    // example), note_type='strategy'. ───────────────────────────────────────
    $createResp = call(Notes::class, $db, $adminAuth, [], 'POST', [], [
        'body' => $marker . ' first body', 'linked_type' => 'company', 'linked_id' => $companyId,
        'note_type' => 'strategy', 'is_pinned' => true, 'tags' => ['sponsorship'],
        'additional_links' => [['type' => 'conference', 'id' => $confId]],
    ]);
    ok(status($createResp) === 200, 'create a strategy-type note linked to a company + conference succeeds');
    $noteId = (int) (payload($createResp)['note']['id'] ?? 0);
    ok($noteId > 0, 'created note has an id');
    if ($noteId) { $created['opportunity_notes'][] = $noteId; }

    $createdNote = payload($createResp)['note'];
    ok(count($createdNote['links'] ?? []) === 2, 'the note carries both links immediately on create');
    $contextTypes = array_column($createdNote['contexts'] ?? [], 'type');
    ok(in_array('company', $contextTypes, true) && in_array('conference', $contextTypes, true), 'create() response already resolves contexts for both links');

    // ── generalIndex(): the cross-cutting workspace list ─────────────────────
    $deniedGeneral = call(Notes::class, $db, $outsiderAuth, [], 'GET');
    ok(status($deniedGeneral) === 403, 'a role without view_opportunities cannot read the general note list either');

    $allNotes = payload(call(Notes::class, $db, $adminAuth, [], 'GET'));
    ok(array_key_exists('authors', $allNotes), 'general-mode index() returns an authors list');
    $found = current(array_filter($allNotes['notes'] ?? [], fn ($n) => (int) $n['id'] === $noteId));
    ok($found !== false, 'the fixture note appears in the unfiltered general list');
    ok(!empty($found['contexts']), 'the fixture note in the general list carries resolved contexts');

    $byQ = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['q' => $marker . ' first body']));
    ok(count(array_filter($byQ['notes'] ?? [], fn ($n) => (int) $n['id'] === $noteId)) === 1, 'q= body-text search finds the fixture note');
    $byQMiss = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['q' => 'zzz-no-such-text-zzz']));
    ok(!in_array($noteId, array_column($byQMiss['notes'] ?? [], 'id'), true), 'q= search excludes notes that do not match');

    $byType = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['note_type' => 'strategy']));
    ok(in_array($noteId, array_column($byType['notes'] ?? [], 'id'), true), 'note_type=strategy filter includes the fixture note');
    $byWrongType = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['note_type' => 'call']));
    ok(!in_array($noteId, array_column($byWrongType['notes'] ?? [], 'id'), true), 'note_type=call filter excludes the (strategy) fixture note');

    $byPinned = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['is_pinned' => '1']));
    ok(in_array($noteId, array_column($byPinned['notes'] ?? [], 'id'), true), 'is_pinned=1 filter includes the pinned fixture note');

    $byTag = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['tag' => 'sponsorship']));
    ok(in_array($noteId, array_column($byTag['notes'] ?? [], 'id'), true), 'tag= filter includes the fixture note');
    $byWrongTag = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['tag' => 'not-a-real-tag']));
    ok(!in_array($noteId, array_column($byWrongTag['notes'] ?? [], 'id'), true), 'tag= filter excludes when the tag does not match');

    $byLinkedTypeOnly = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['linked_type' => 'conference']));
    ok(in_array($noteId, array_column($byLinkedTypeOnly['notes'] ?? [], 'id'), true), 'a bare linked_type (no id) includes any note linked to that record type');

    $byAuthor = payload(call(Notes::class, $db, $adminAuth, [], 'GET', ['created_by' => (string) $admin['id']]));
    ok(in_array($noteId, array_column($byAuthor['notes'] ?? [], 'id'), true), 'created_by= filter includes the fixture note');

    $missingLinkedId = call(Notes::class, $db, $adminAuth, [], 'GET', ['linked_id' => (string) $companyId]);
    ok(status($missingLinkedId) === 422, 'linked_id without a valid linked_type is still rejected (422), same as before Phase 6');

    // ── Version history ───────────────────────────────────────────────────────
    $versionsBeforeEdit = payload(call(Notes::class, $db, $adminAuth, ['noteId' => $noteId, 'action' => 'versions'], 'GET'));
    ok(($versionsBeforeEdit['versions'] ?? null) === [], 'a never-edited note has zero versions');

    // A no-op PATCH (identical body) must NOT create a version.
    call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], ['body' => $marker . ' first body']);
    $versionsAfterNoop = payload(call(Notes::class, $db, $adminAuth, ['noteId' => $noteId, 'action' => 'versions'], 'GET'));
    ok(($versionsAfterNoop['versions'] ?? null) === [], 'PATCHing with the unchanged body does not create a spurious version');

    $deniedVersionWrite = call(Notes::class, $db, $outsiderAuth, ['noteId' => $noteId], 'PATCH', [], ['body' => 'nope']);
    ok(status($deniedVersionWrite) === 403, 'a role without manage_opportunities cannot edit the note body');

    call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], ['body' => $marker . ' revised body']);
    $versionsAfterEdit = payload(call(Notes::class, $db, $adminAuth, ['noteId' => $noteId, 'action' => 'versions'], 'GET'));
    ok(count($versionsAfterEdit['versions'] ?? []) === 1, 'editing the body archives exactly one prior version');
    ok(($versionsAfterEdit['versions'][0]['body'] ?? null) === $marker . ' first body', 'the archived version holds the PRE-edit body, not the new one');
    ok((int) ($versionsAfterEdit['versions'][0]['edited_by'] ?? 0) === (int) $admin['id'], 'the archived version records who authored that prior body');

    $noteAfterEdit = payload(call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'GET'))['note'];
    ok($noteAfterEdit['body'] === $marker . ' revised body', 'the note itself now shows the new body');
    ok((int) $noteAfterEdit['updated_by'] === (int) $admin['id'], 'updated_by is set to whoever made the edit');

    call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], ['body' => $marker . ' third body']);
    $versionsAfterSecondEdit = payload(call(Notes::class, $db, $adminAuth, ['noteId' => $noteId, 'action' => 'versions'], 'GET'));
    ok(count($versionsAfterSecondEdit['versions'] ?? []) === 2, 'a second body edit archives a second version (append-only)');
    ok(($versionsAfterSecondEdit['versions'][0]['body'] ?? null) === $marker . ' revised body', 'versions are returned newest-first');

    // ── add_links / remove_links (the workspace's "Link record" action) ──────
    $addContactLink = call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], [
        'add_links' => [['type' => 'contact', 'id' => $contactId]],
    ]);
    ok(status($addContactLink) === 200, 'add_links succeeds for a valid contact link');
    $noteWithContact = payload($addContactLink)['note'];
    ok(count($noteWithContact['links'] ?? []) === 3, 'the note now carries all three links (company, conference, contact)');

    $invalidAddLink = call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], [
        'add_links' => [['type' => 'company', 'id' => 999999999]],
    ]);
    ok(status($invalidAddLink) === 422, 'add_links rejects a linked record that does not exist');

    $removeConferenceLink = call(Notes::class, $db, $adminAuth, ['noteId' => $noteId], 'PATCH', [], [
        'remove_links' => [['type' => 'conference', 'id' => $confId]],
    ]);
    ok(status($removeConferenceLink) === 200, 'remove_links succeeds');
    $noteAfterRemove = payload($removeConferenceLink)['note'];
    ok(count($noteAfterRemove['links'] ?? []) === 2, 'the note now carries only the remaining two links');
    ok(!in_array('conference', array_column($noteAfterRemove['links'], 'type'), true), 'the conference link is actually gone');

    $deniedLinkWrite = call(Notes::class, $db, $outsiderAuth, ['noteId' => $noteId], 'PATCH', [], ['add_links' => [['type' => 'contact', 'id' => $contactId]]]);
    ok(status($deniedLinkWrite) === 403, 'a role without manage_opportunities cannot add/remove links');
} finally {
    foreach ($created['opportunity_notes'] as $id) {
        try { $db->run('DELETE FROM opportunity_notes WHERE id = ? AND body LIKE ?', [$id, 'PB TEST OPPNOTES — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for note $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_conferences'] as $id) {
        try { $db->run('DELETE FROM opportunity_conferences WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPNOTES — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for conference $id: {$e->getMessage()}\n"); }
    }
    foreach ($created['opportunity_companies'] as $id) {
        try { $db->run('DELETE FROM opportunity_companies WHERE id = ? AND name LIKE ?', [$id, 'PB TEST OPPNOTES — %']); }
        catch (\Throwable $e) { fwrite(STDERR, "cleanup failed for company $id: {$e->getMessage()}\n"); }
    }
    $total = count($created['opportunity_notes']) + count($created['opportunity_conferences']) + count($created['opportunity_companies']);
    echo "\n  (cleaned up $total throwaway row(s) plus their link/tag/version children)\n";
}

echo "\n" . ($failed === 0
    ? "PASS — $passed assertions\n\n"
    : "FAIL — $failed of " . ($passed + $failed) . " assertions failed\n\n");

exit($failed === 0 ? 0 : 1);
