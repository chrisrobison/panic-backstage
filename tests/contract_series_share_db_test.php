<?php
/**
 * DB-backed test: a contract created with apply_to_series=true covers every
 * occurrence in a recurring series, not just the event it was generated
 * from — the "same contract associated to them" feature. Exercises:
 *   - Events\Contracts::create()'s apply_to_series option (contracts.series_id)
 *   - Events\Contracts::index() surfacing a sibling's series-wide contract
 *   - Events::hasExecutedContract() / readiness()'s private-event Contract gate
 *     recognizing that shared contract on a sibling that has none of its own
 *
 * REQUIRES A REAL MYSQL DATABASE with at least one venue and one venue_admin
 * user — runs against the shared dev database (no separate test DB), prefixes
 * everything it creates with "PB TEST CONTRACT SERIES — ", and deletes it all
 * in a finally block regardless of pass/fail. Excluded from the default
 * hermetic pass — opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/contract_series_share_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;
use Panic\Events\Contracts as EventContracts;
use Panic\Events\Series;
use Panic\Request;
use Panic\Response;

$root = dirname(__DIR__);
Env::load($root . '/.env');
$_ENV['SHEET_SYNC_ENABLED'] = '0';
putenv('SHEET_SYNC_ENABLED=0');
putenv('GCAL_SYNC_ENABLED=0');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { echo "  ✓ $label\n"; $passed++; }
    else { echo "  ✗ FAIL: $label\n"; $failed++; }
}

function responseValue(Response $response, string $property): mixed {
    $reflection = new ReflectionProperty(Response::class, $property);
    $reflection->setAccessible(true);
    return $reflection->getValue($response);
}
function responseStatus(Response $response): int { return responseValue($response, 'status'); }
function responseBody(Response $response): array { return responseValue($response, 'body'); }

echo "\n=== Series-wide contracts (\"Apply to entire series\") ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (Throwable $error) {
    fwrite(STDERR, "Could not connect to the configured database: {$error->getMessage()}\n");
    exit(1);
}

$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$venue || !$admin) {
    fwrite(STDERR, "contract_series_share_db_test.php needs a venue and a venue_admin user — skipping.\n");
    exit(1);
}
$venueId = (int) $venue['id'];
$adminId = (int) $admin['id'];

$auth = new Auth();
$auth->setUser($admin);
$marker = 'PB TEST CONTRACT SERIES — ' . bin2hex(random_bytes(4));
$createdEventIds = [];
$createdSeriesIds = [];
$createdContractIds = [];

try {
    // Two genuinely free dates within the horizon — private events don't book
    // a room via checkRoomConflict the same way, but Series::attemptCreate()
    // still runs the same conflict check regardless of event_type, so avoid
    // whatever's actually booked rather than risking a 409 unrelated to what
    // this test is actually checking.
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
    $dates = [];
    $cursor = new DateTimeImmutable('+3 days');
    while (count($dates) < 2) {
        $d = $cursor->format('Y-m-d');
        if ($d > $horizonCutoff) {
            throw new RuntimeException('Could not find 2 free dates within the 90-day horizon for this test.');
        }
        if (!isset($booked[$d])) { $dates[] = $d; $booked[$d] = true; }
        $cursor = $cursor->modify('+1 day');
    }
    [$anchorDate, $occDate] = $dates;

    // Private event: readiness()'s Contract gate is only surfaced for
    // event_type='private_event' — see Events::readiness()'s private branch —
    // so this is the most direct way to assert the gate itself, not just the
    // banner text nextAction() produces for either branch.
    $anchorId = $db->insert(
        "INSERT INTO events (venue_id, title, slug, event_type, status, date, owner_user_id, promoter_name, promoter_email)
         VALUES (?, ?, ?, 'private_event', 'proposed', ?, ?, ?, 'client@example.invalid')",
        [$venueId, $marker . ' — anchor', \Panic\slugify($marker . '-anchor-' . $anchorDate), $anchorDate, $adminId, $marker . ' Client']
    );
    $createdEventIds[] = $anchorId;

    $seriesEndpoint = new Series($db, $auth, ['eventId' => $anchorId], $root);
    $seriesResult = $seriesEndpoint->attemptCreate($anchorId, [$occDate], 'test series', null, 'after_count', null, 2, $adminId, (string) $admin['role']);
    ok($seriesResult['ok'] === true && count($seriesResult['created_event_ids']) === 1, 'series founded: anchor + 1 occurrence');
    $siblingId = (int) $seriesResult['created_event_ids'][0];
    $seriesId = (int) $seriesResult['series_id'];
    $createdEventIds[] = $siblingId;
    $createdSeriesIds[] = $seriesId;

    // A signed-and-attached asset on the anchor — ContractService::attachUploaded()
    // writes status='signed' immediately, so this is the fastest path to an
    // executed contract without driving the full deal-builder/e-sign flow.
    $assetId = $db->insert(
        "INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status)
         VALUES (?, 'contract', ?, 'signed.pdf', 'signed.pdf', 'uploads/pb-test-signed.pdf', ?, 'approved')",
        [$anchorId, $marker . ' signed doc', $adminId]
    );

    $contractsOnAnchor = new EventContracts($db, $auth, ['eventId' => $anchorId], $root);
    $createResp = $contractsOnAnchor->handle(new Request('POST', "/api/events/$anchorId/contracts", [], [
        'asset_id' => $assetId,
        'title' => $marker . ' Contract',
        'apply_to_series' => true,
    ], [], []));
    ok(responseStatus($createResp) === 200, 'POST /events/{anchor}/contracts with apply_to_series succeeds');
    $contractId = (int) (responseBody($createResp)['id'] ?? 0);
    ok($contractId > 0, 'contract created');
    if ($contractId > 0) $createdContractIds[] = $contractId;

    $contractRow = $db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
    ok(($contractRow['event_id'] ?? null) == $anchorId, 'contract still belongs to the event it was generated from (event_id unchanged)');
    ok(($contractRow['series_id'] ?? null) == $seriesId, 'apply_to_series stamped contracts.series_id with the anchor\'s series');
    ok(($contractRow['status'] ?? null) === 'signed', 'uploaded/attached contract starts signed (satisfies the executed-contract gate)');

    // Sibling has zero contracts of its own — everything below comes only
    // from the shared series-wide contract.
    $siblingOwnContracts = $db->one('SELECT COUNT(*) AS n FROM contracts WHERE event_id = ?', [$siblingId]);
    ok((int) ($siblingOwnContracts['n'] ?? -1) === 0, 'sibling occurrence has no contracts row of its own');

    $contractsOnSibling = new EventContracts($db, $auth, ['eventId' => $siblingId], $root);
    $listResp = $contractsOnSibling->handle(new Request('GET', "/api/events/$siblingId/contracts", [], [], [], []));
    ok(responseStatus($listResp) === 200, 'GET /events/{sibling}/contracts succeeds');
    $listBody = responseBody($listResp);
    $foundOnSibling = array_filter($listBody['contracts'] ?? [], fn ($c) => (int) $c['id'] === $contractId);
    ok(count($foundOnSibling) === 1, 'the series-wide contract shows up when listing the SIBLING\'s contracts, not just the anchor\'s');
    ok(($listBody['series']['id'] ?? null) == $seriesId && ($listBody['series']['occurrence_count'] ?? null) === 2, 'response echoes the sibling\'s own series info (id + occurrence count)');

    // Bump the sibling to 'confirmed' directly (bypassing the intake-fields
    // gate that PATCH /events/{id} would otherwise enforce — irrelevant to
    // what's under test) so nextAction()'s $hasContract-dependent branch for
    // 'confirmed' actually fires; at 'proposed' the copy doesn't mention
    // contracts at all regardless of $hasContract.
    $db->run("UPDATE events SET status = 'confirmed' WHERE id = ?", [$siblingId]);

    $eventsEndpoint = new Events($db, $auth, ['eventId' => $siblingId], $root);
    $showResp = $eventsEndpoint->handle(new Request('GET', "/api/events/$siblingId", [], [], [], []));
    ok(responseStatus($showResp) === 200, 'GET /events/{sibling} succeeds');
    $showBody = responseBody($showResp);
    $contractItem = null;
    foreach ($showBody['readiness'] ?? [] as $item) {
        if (($item['label'] ?? null) === 'Contract') { $contractItem = $item; break; }
    }
    ok($contractItem !== null && $contractItem['ok'] === true && $contractItem['state'] === 'On file',
        'readiness() reports "Contract: On file" for the sibling via the shared series contract, though it has none of its own');
    ok(str_contains($showBody['nextAction'] ?? '', 'Booked'), 'nextAction() no longer asks to send a contract now that the series contract satisfies the gate');
} finally {
    // contracts.event_id/series_id are both ON DELETE SET NULL (not CASCADE —
    // a contract must survive its event being deleted, e.g. for audit/legal
    // history), so the contract row itself needs an explicit delete or it
    // leaks as an orphaned "PB TEST CONTRACT SERIES" row. Delete it before
    // the events/series it points to.
    if ($createdContractIds) {
        $placeholders = implode(',', array_fill(0, count($createdContractIds), '?'));
        $db->run("DELETE FROM contracts WHERE id IN ($placeholders)", array_unique($createdContractIds));
    }
    if ($createdEventIds) {
        // Unlink every series member first — DELETE /events/{id} does not
        // clean up siblings or the event_series row itself (see
        // Series::remove(), which the extend-series feature added this
        // finally-block convention for).
        foreach (array_unique($createdEventIds) as $id) {
            $db->run('UPDATE events SET series_id = NULL WHERE id = ?', [$id]);
        }
        $placeholders = implode(',', array_fill(0, count($createdEventIds), '?'));
        $db->run("DELETE FROM events WHERE id IN ($placeholders)", array_unique($createdEventIds));
    }
    if ($createdSeriesIds) {
        $placeholders = implode(',', array_fill(0, count($createdSeriesIds), '?'));
        $db->run("DELETE FROM event_series WHERE id IN ($placeholders)", array_unique($createdSeriesIds));
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
