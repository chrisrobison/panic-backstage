<?php
declare(strict_types=1);

/**
 * Smoke test for the ListMaster backend additions (tags, contact activity
 * log, bulk list-membership ops, bounced status, CSV export, import/export
 * history, contact storage meter). Follows the same magic-link login pattern
 * as scripts/mailing-lists-smoke.php.
 *
 * Usage: php scripts/listmaster-smoke.php [base-url] [mail-dir]
 */

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8091', '/');
$mailDir = $argv[2] ?? dirname(__DIR__) . '/storage/mail';

final class SmokeClient
{
    private string $accessToken = '';
    public function __construct(private readonly string $baseUrl) {}
    public function setToken(string $token): void { $this->accessToken = $token; }

    public function request(string $method, string $path, mixed $body = null): array
    {
        $headers = ['Accept: application/json'];
        $content = null;
        if ($body !== null) {
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
        $isJson = false;
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'application/json') !== false) $isJson = true;
        }
        $json = $isJson ? json_decode($raw ?: 'null', true) : null;
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

    // ── contact-storage ──────────────────────────────────────────────────────
    $r = $admin->request('GET', '/api/contact-storage');
    if ($r['status'] !== 200) fail('GET contact-storage: ' . json_encode($r));
    if (!isset($r['body']['used'], $r['body']['limit'], $r['body']['percent'])) fail('contact-storage missing fields: ' . json_encode($r));
    ok("contact-storage: {$r['body']['used']} of {$r['body']['limit']} ({$r['body']['percent']}%)");

    // ── contact-tags CRUD ────────────────────────────────────────────────────
    $r = $admin->request('POST', '/api/contact-tags', ['name' => "Smoke VIP $suffix", 'color' => '#ff0000']);
    if ($r['status'] !== 200) fail('create tag: ' . json_encode($r));
    $tagId = $r['body']['tag']['id'];
    ok("created tag #$tagId");

    $r = $admin->request('GET', '/api/contact-tags');
    if ($r['status'] !== 200 || !array_filter($r['body']['tags'], fn ($t) => (int) $t['id'] === (int) $tagId)) fail('tag not in index: ' . json_encode($r));
    ok('tag appears in /contact-tags index');

    $r = $admin->request('PATCH', "/api/contact-tags/$tagId", ['color' => '#00ff00']);
    if ($r['status'] !== 200 || $r['body']['tag']['color'] !== '#00ff00') fail('update tag color: ' . json_encode($r));
    ok('updated tag color');

    // ── list + members setup ─────────────────────────────────────────────────
    $r = $admin->request('POST', '/api/mailing-lists', ['name' => "Smoke ListMaster $suffix"]);
    if ($r['status'] !== 200) fail('create list: ' . json_encode($r));
    $listId = $r['body']['list']['id'];
    foreach (['total_count', 'member_count', 'unsubscribed_count', 'bounced_count'] as $f) {
        if (!array_key_exists($f, $r['body']['list'])) fail("list response missing $f: " . json_encode($r['body']['list']));
    }
    ok("created list #$listId with stats fields present");

    $r = $admin->request('POST', "/api/mailing-lists/$listId/add-by-filter", ['opted' => '1']);
    if ($r['status'] !== 200 || $r['body']['added'] < 3) fail('need at least 3 opted-in contacts for this smoke test: ' . json_encode($r));
    ok("added {$r['body']['added']} opted-in contacts to list");

    $r = $admin->request('GET', "/api/mailing-lists/$listId/members?limit=5");
    if ($r['status'] !== 200 || count($r['body']['members']) < 3) fail('list members: ' . json_encode($r));
    $members = $r['body']['members'];
    if (!array_key_exists('tags', $members[0]) || !array_key_exists('lists_count', $members[0])) fail('member row missing tags/lists_count: ' . json_encode($members[0]));
    ok('member rows include tags[] and lists_count');
    $c1 = $members[0]['contact_id'];
    $c2 = $members[1]['contact_id'];
    $c3 = $members[2]['contact_id'];

    // ── contact tag assignment ──────────────────────────────────────────────
    $r = $admin->request('POST', "/api/contacts/$c1/tags", ['tag_id' => $tagId]);
    if ($r['status'] !== 200) fail('assign tag to contact: ' . json_encode($r));
    ok("assigned tag to contact #$c1");

    $r = $admin->request('GET', "/api/contacts/$c1");
    if ($r['status'] !== 200 || empty($r['body']['contact']['tags'])) fail('contact show should include tags: ' . json_encode($r));
    ok('GET /contacts/{id} includes tags[]');

    $r = $admin->request('GET', "/api/mailing-lists/$listId/members?tag=$tagId");
    if ($r['status'] !== 200 || count($r['body']['members']) !== 1) fail('tag filter on members: ' . json_encode($r));
    ok('tag filter on list members returns exactly the tagged contact');

    $r = $admin->request('DELETE', "/api/contacts/$c1/tags/$tagId");
    if ($r['status'] !== 200) fail('unassign tag: ' . json_encode($r));
    ok('unassigned tag');

    $r = $admin->request('POST', '/api/contacts/bulk-tag', ['contact_ids' => [$c1, $c2, $c3], 'tag_id' => $tagId]);
    if ($r['status'] !== 200 || $r['body']['tagged'] !== 3) fail('bulk-tag: ' . json_encode($r));
    ok('bulk-tagged 3 contacts');

    // ── activity feed ────────────────────────────────────────────────────────
    $r = $admin->request('GET', "/api/contacts/$c1/activity");
    if ($r['status'] !== 200 || count($r['body']['activity']) < 1) fail('contact activity: ' . json_encode($r));
    ok('contact activity feed has entries (list_joined / tag_added)');

    // ── bulk status update + bounced ────────────────────────────────────────
    $r = $admin->request('PATCH', "/api/mailing-lists/$listId/members", ['contact_ids' => [$c1], 'status' => 'bounced']);
    if ($r['status'] !== 200 || $r['body']['updated'] !== 1) fail('bulk status update: ' . json_encode($r));
    ok('bulk-marked 1 member bounced');

    $r = $admin->request('GET', "/api/mailing-lists/$listId");
    if ((int) $r['body']['list']['bounced_count'] !== 1) fail('bounced_count should be 1: ' . json_encode($r['body']['list']));
    ok('list bounced_count reflects the bulk update');

    // ── bulk remove ──────────────────────────────────────────────────────────
    $r = $admin->request('DELETE', "/api/mailing-lists/$listId/members", ['contact_ids' => [$c2]]);
    if ($r['status'] !== 200 || $r['body']['removed'] !== 1) fail('bulk remove: ' . json_encode($r));
    ok('bulk-removed 1 member');

    // ── CSV export ───────────────────────────────────────────────────────────
    $r = $admin->request('GET', "/api/mailing-lists/$listId/export-members");
    if ($r['status'] !== 200 || !str_contains((string) $r['body']['_raw'], 'First Name')) fail('export-members CSV: ' . json_encode(['status' => $r['status']]));
    ok('export-members returned a CSV');

    $r = $admin->request('GET', "/api/mailing-lists/export-history?list_id=$listId");
    if ($r['status'] !== 200 || count($r['body']['history']) < 1) fail('export-history: ' . json_encode($r));
    ok('export-history logged the export');

    // ── import history (via existing importCsv path is covered by mailing-lists-smoke.php; just check the listing works) ──
    $r = $admin->request('GET', '/api/mailing-lists/import-history');
    if ($r['status'] !== 200) fail('import-history: ' . json_encode($r));
    ok('import-history endpoint responds');

    echo "\nAll ListMaster smoke checks passed.\n";
} catch (\Throwable $e) {
    fail($e->getMessage());
}
