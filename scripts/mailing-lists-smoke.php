<?php
declare(strict_types=1);

/**
 * Smoke test for the mailing-list management upgrades (bulk add, CSV import,
 * contact-centric membership view, segment/smart lists). Follows the same
 * magic-link login pattern as scripts/endpoint-smoke.php.
 *
 * Usage: php scripts/mailing-lists-smoke.php [base-url] [mail-dir]
 */

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8091', '/');
$mailDir = $argv[2] ?? dirname(__DIR__) . '/storage/mail';

final class SmokeClient
{
    private string $accessToken = '';
    public function __construct(private readonly string $baseUrl) {}
    public function setToken(string $token): void { $this->accessToken = $token; }

    public function request(string $method, string $path, mixed $body = null, ?array $rawMultipart = null): array
    {
        $headers = ['Accept: application/json'];
        $content = null;
        if ($rawMultipart !== null) {
            [$content, $boundary] = $rawMultipart;
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        } elseif ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $content, 'ignore_errors' => true,
        ]]);
        $raw = file_get_contents($this->baseUrl . $path, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000';
        preg_match('/\s(\d{3})\s/', $statusLine, $m);
        $status = (int) ($m[1] ?? 0);
        $json = json_decode($raw ?: 'null', true);
        return ['status' => $status, 'body' => is_array($json) ? $json : ['_raw' => $raw]];
    }
}

function ok(string $msg): void { echo "OK  $msg\n"; }
function fail(string $msg): never { echo "FAIL  $msg\n"; exit(1); }

function extractTokenFromLatestMail(string $mailDir, string $sentToContains): string
{
    $files = glob($mailDir . '/*.eml');
    if (!$files) throw new RuntimeException("No .eml files found in $mailDir");
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($files as $f) {
        if (!str_contains(basename($f), $sentToContains)) continue;
        $content = file_get_contents($f);
        if (preg_match('/[?&]token=([a-f0-9]{48})/i', $content, $m)) return $m[1];
    }
    throw new RuntimeException("Could not find a magic-link token for $sentToContains");
}

function multipartBody(string $fieldName, string $fileName, string $content): array
{
    $boundary = 'SmokeBoundary' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n" .
        "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$fileName}\"\r\n" .
        "Content-Type: text/csv\r\n\r\n" .
        $content . "\r\n--{$boundary}--\r\n";
    return [$body, $boundary];
}

try {
    $admin = new SmokeClient($baseUrl);
    $admin->request('POST', '/api/auth/magic-link', ['email' => 'admin@mabuhay.local']);
    sleep(1);
    $token = extractTokenFromLatestMail($mailDir, 'admin@mabuhay.local');
    $auth = $admin->request('POST', '/api/auth/verify', ['token' => $token]);
    if (empty($auth['body']['access_token'])) fail('No access_token from magic-link verify');
    $admin->setToken($auth['body']['access_token']);
    ok('logged in as admin@mabuhay.local');

    $suffix = bin2hex(random_bytes(3));

    // ── 1. Static list + bulk add by filter ─────────────────────────────────
    $r = $admin->request('POST', '/api/mailing-lists', ['name' => "Smoke Static $suffix", 'description' => 'smoke test']);
    if ($r['status'] !== 200) fail('create static list: ' . json_encode($r));
    $staticListId = $r['body']['list']['id'];
    if (($r['body']['list']['list_type'] ?? null) !== 'static') fail('expected list_type=static, got ' . json_encode($r['body']['list']));
    ok("created static list #$staticListId");

    $r = $admin->request('POST', "/api/mailing-lists/$staticListId/add-by-filter", ['opted' => '1']);
    if ($r['status'] !== 200) fail('add-by-filter: ' . json_encode($r));
    $addedByFilter = $r['body']['added'];
    if ($addedByFilter < 1) fail('add-by-filter added 0 contacts — expected at least 1 opted-in contact');
    ok("add-by-filter added $addedByFilter opted-in contacts");

    $r = $admin->request('GET', "/api/mailing-lists/$staticListId");
    if ((int) $r['body']['list']['member_count'] !== $addedByFilter) fail('member_count mismatch after add-by-filter: ' . json_encode($r['body']['list']));
    ok('member_count matches after bulk add');

    // ── 2. CSV import ────────────────────────────────────────────────────────
    $csvEmail1 = "smoke-csv-new-$suffix@example.com";
    // Reuse an existing contact's email (first member just added) to exercise the "matched" path too.
    $membersResp = $admin->request('GET', "/api/mailing-lists/$staticListId/members?limit=1");
    $existingEmail = $membersResp['body']['members'][0]['email'] ?? null;
    if (!$existingEmail) fail('could not fetch an existing member email for CSV match test');

    $csv = "email,first_name,last_name\n" .
        "$csvEmail1,Smoke,CsvNew\n" .
        "$existingEmail,Existing,Matched\n" .
        ",Missing,Email\n"; // deliberately bad row
    [$body, $boundary] = multipartBody('csv', 'contacts.csv', $csv);
    $r = $admin->request('POST', "/api/mailing-lists/$staticListId/import", null, [$body, $boundary]);
    if ($r['status'] !== 200) fail('CSV import: ' . json_encode($r));
    $imp = $r['body'];
    if (($imp['created'] ?? 0) < 1) fail('CSV import created 0 new contacts: ' . json_encode($imp));
    if (($imp['updated'] ?? 0) < 1) fail('CSV import matched 0 existing contacts: ' . json_encode($imp));
    if (($imp['skipped'] ?? 0) < 1) fail('CSV import should have skipped the blank-email row: ' . json_encode($imp));
    ok("CSV import: {$imp['created']} created, {$imp['updated']} matched, {$imp['skipped']} skipped");

    // ── 3. Contact-centric membership view ───────────────────────────────────
    $contactsResp = $admin->request('GET', '/api/contacts?q=' . urlencode($csvEmail1));
    $contactId = $contactsResp['body']['contacts'][0]['id'] ?? null;
    if (!$contactId) fail('could not find the CSV-created contact via /contacts search');

    $r = $admin->request('GET', "/api/contacts/$contactId/lists");
    if ($r['status'] !== 200) fail('GET /contacts/{id}/lists: ' . json_encode($r));
    $onList = false;
    foreach ($r['body']['memberships'] as $m) { if ((int) $m['list_id'] === (int) $staticListId) $onList = true; }
    if (!$onList) fail('CSV-imported contact should show the static list in its memberships: ' . json_encode($r['body']));
    if (!($r['body']['can_manage'] ?? false)) fail('admin should have can_manage=true on contact memberships');
    ok('contact-centric view shows the list membership + can_manage');

    $r = $admin->request('PATCH', "/api/mailing-lists/$staticListId/members/$contactId", ['status' => 'unsubscribed']);
    if ($r['status'] !== 200 || $r['body']['membership']['status'] !== 'unsubscribed') fail('toggle membership status: ' . json_encode($r));
    ok('toggled membership status via MailingLists endpoint (as the contact modal would)');

    // ── 4. Segment (smart) list ──────────────────────────────────────────────
    $r = $admin->request('POST', '/api/mailing-lists', [
        'name' => "Smoke Segment $suffix",
        'list_type' => 'segment',
        'segment_rules' => ['opted' => '1'],
    ]);
    if ($r['status'] !== 200) fail('create segment list: ' . json_encode($r));
    $segmentListId = $r['body']['list']['id'];
    $segTotal1 = (int) $r['body']['list']['member_count'];
    if ($segTotal1 < 1) fail('segment list should auto-populate on create, got member_count=0');
    if ($r['body']['list']['segment_rules']['opted'] !== '1' && $r['body']['list']['segment_rules']['opted'] !== 1) {
        fail('segment_rules not round-tripped correctly: ' . json_encode($r['body']['list']));
    }
    ok("segment list #$segmentListId auto-populated with $segTotal1 members on create");

    // Manual-add-style calls must be rejected on a segment list's members (still
    // technically reachable, but let's confirm refresh/rules endpoints behave).
    $r = $admin->request('POST', "/api/mailing-lists/$segmentListId/refresh");
    if ($r['status'] !== 200) fail('refresh segment list: ' . json_encode($r));
    ok('refresh segment list: added=' . $r['body']['added'] . ' removed=' . $r['body']['removed'] . ' total=' . $r['body']['total_matching']);

    $r = $admin->request('PATCH', "/api/mailing-lists/$segmentListId", ['list_type' => 'static']);
    if ($r['status'] !== 422) fail('changing list_type should be rejected with 422, got ' . $r['status']);
    ok('list_type change correctly rejected');

    $r = $admin->request('PATCH', "/api/mailing-lists/$segmentListId", ['segment_rules' => ['min_spend' => 999999]]);
    if ($r['status'] !== 200) fail('update segment_rules: ' . json_encode($r));
    $r2 = $admin->request('GET', "/api/mailing-lists/$segmentListId");
    if ((int) $r2['body']['list']['member_count'] !== 0) fail('after tightening rules to an impossible spend threshold, member_count should be 0: ' . json_encode($r2['body']));
    ok('editing segment_rules auto-re-synced membership (tightened rule -> 0 members)');

    // A manually-added member on a static list must never be evicted by a
    // segment refresh elsewhere — sanity check that manual/bulk/csv_import
    // added_via rows on the STATIC list are untouched by the segment list's
    // refresh (they're different lists, but this also exercises added_via
    // scoping doesn't leak across lists).
    $r = $admin->request('GET', "/api/mailing-lists/$staticListId/members?limit=5");
    if ($r['status'] !== 200 || $r['body']['total'] < 1) fail('static list members should be unaffected by segment refresh: ' . json_encode($r));
    ok('static list membership untouched by unrelated segment refresh');

    // ── Cleanup ───────────────────────────────────────────────────────────────
    $admin->request('DELETE', "/api/mailing-lists/$staticListId");
    $admin->request('DELETE', "/api/mailing-lists/$segmentListId");
    ok('cleaned up smoke-test lists');

    echo "\nALL MAILING-LIST SMOKE TESTS PASSED\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
