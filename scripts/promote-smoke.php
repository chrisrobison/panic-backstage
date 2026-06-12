<?php
declare(strict_types=1);

/**
 * Panic Promote — endpoint smoke test.
 *
 * Usage:
 *   php scripts/promote-smoke.php [base-url] [mail-dir]
 *
 *   base-url  defaults to http://localhost:8000
 *   mail-dir  defaults to storage/mail  (relative to project root)
 *
 * Coverage (see PROMOTE-IMPLEMENTATION-MAP.md §H):
 *  1.  Admin login via magic-link
 *  2.  Create test event (from template)
 *  3.  Create campaign for event
 *  4.  Fetch campaign overview — assert event, posts, health, destinations, analytics
 *  5.  Create post
 *  6.  Update post
 *  7.  Generate variants — assert 9 channels + warnings present
 *  8.  Create broadcast with destinations from all 4 groups (send_mode=now)
 *       — facebook_page/instagram/tiktok → needs_auth
 *       — funcheap/foopee/press_list     → manual_required
 *       — email_general/email_press      → sent
 *  9.  Fetch campaign broadcasts list
 *  10. Fetch health — assert {score, complete, total, items[]}
 *  11. Fetch analytics — assert stub zeros
 *  12. Fetch destinations
 *  13. 404 for a missing post id
 *  14. Unauthenticated request → 401
 *  15. Invite a viewer; viewer can GET campaign, gets 403 on POST
 */

$baseUrl = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$mailDir = $argv[2] ?? dirname(__DIR__) . '/storage/mail';

// ---------------------------------------------------------------------------

final class SmokeClient
{
    private string $accessToken = '';

    public function __construct(private readonly string $baseUrl) {}

    public function setToken(string $token): void
    {
        $this->accessToken = $token;
    }

    public function token(): string
    {
        return $this->accessToken;
    }

    public function request(
        string $method,
        string $path,
        mixed $body = null,
        array $expectedStatuses = []
    ): array {
        $headers = ['Accept: application/json'];
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content   = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $content,
                'ignore_errors' => true,
            ],
        ]);

        $raw        = file_get_contents($this->baseUrl . $path, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000';
        preg_match('/\s(\d{3})\s/', $statusLine, $m);
        $status = (int) ($m[1] ?? 0);
        $json   = json_decode($raw ?: 'null', true);

        if ($expectedStatuses && in_array($status, $expectedStatuses, true)) {
            return ['status' => $status, 'body' => is_array($json) ? $json : []];
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("$method $path → HTTP $status: " . ($raw ?: 'empty'));
        }
        return is_array($json) ? $json : [];
    }
}

function ok(string $msg): void
{
    echo "OK  $msg\n";
}

function extractTokenFromLatestMail(string $mailDir): string
{
    $files = glob($mailDir . '/*.eml');
    if (!$files) {
        throw new RuntimeException("No .eml files found in $mailDir");
    }
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $content = file_get_contents($files[0]);
    if (!preg_match('/[?&]token=([a-f0-9]{48})/i', $content, $m)) {
        throw new RuntimeException("Could not find token in " . basename($files[0]));
    }
    return $m[1];
}

// ---------------------------------------------------------------------------

try {
    // ── 1. Admin login via magic-link ────────────────────────────────────────
    $admin = new SmokeClient($baseUrl);

    $admin->request('POST', '/api/auth/magic-link', ['email' => 'admin@mabuhay.local']);
    ok('magic-link email requested');

    sleep(1);
    $token = extractTokenFromLatestMail($mailDir);
    $auth  = $admin->request('POST', '/api/auth/verify', ['token' => $token]);
    if (empty($auth['access_token'])) {
        throw new RuntimeException('No access_token in verify response');
    }
    $admin->setToken($auth['access_token']);
    ok('admin authenticated');

    // ── 2. Create test event ─────────────────────────────────────────────────
    $templates  = $admin->request('GET', '/api/templates');
    $templateId = (int) ($templates['templates'][0]['id'] ?? 0);
    if (!$templateId) {
        throw new RuntimeException('No templates returned — cannot create test event');
    }

    // Use a random far-future date to avoid room conflicts with previous runs.
    $eventDate = (new DateTimeImmutable('+' . (180 + random_int(0, 180)) . ' days'))->format('Y-m-d');
    $created   = $admin->request('POST', "/api/events/from-template/$templateId", [
        'date'       => $eventDate,
        'doors_time' => '19:00',
        'show_time'  => '20:00',
        'title'      => 'Promote Smoke Test Show',
    ]);
    $eventId = (int) $created['id'];
    if (!$eventId) {
        throw new RuntimeException('Event creation returned no id');
    }
    ok("test event created (id=$eventId, date=$eventDate)");

    // ── 3. Create campaign for event ─────────────────────────────────────────
    $campaign = $admin->request('POST', "/api/promote/events/$eventId/campaign");
    if (empty($campaign['campaign']['id'])) {
        throw new RuntimeException('Campaign creation returned no id');
    }
    $campaignId = (int) $campaign['campaign']['id'];
    ok("campaign created (id=$campaignId)");

    // ── 4. Fetch campaign overview ────────────────────────────────────────────
    $overview = $admin->request('GET', "/api/promote/campaigns/$campaignId");

    // Assert event, posts, health, destinations, analytics are present
    if (!isset($overview['event']['id'])) {
        throw new RuntimeException('Campaign overview missing event data');
    }
    if (!array_key_exists('posts', $overview)) {
        throw new RuntimeException('Campaign overview missing posts array');
    }
    if (!isset($overview['health']['score'], $overview['health']['complete'],
                $overview['health']['total'], $overview['health']['items'])) {
        throw new RuntimeException('Campaign overview missing health keys (score/complete/total/items)');
    }
    if (!array_key_exists('destinations', $overview)) {
        throw new RuntimeException('Campaign overview missing destinations');
    }
    if (!isset($overview['analytics']['website_clicks'])) {
        throw new RuntimeException('Campaign overview missing analytics data');
    }
    ok('campaign overview contains event, posts, health, destinations, analytics');

    // ── 5. Create post ────────────────────────────────────────────────────────
    $post = $admin->request('POST', "/api/promote/campaigns/$campaignId/posts", [
        'title'       => 'Smoke post: announce show',
        'master_text' => 'We are excited to announce an incredible night of live music!',
        'target_url'  => 'https://mabuhaygardens.com/tickets',
        'status'      => 'draft',
    ]);
    if (empty($post['post']['id'])) {
        throw new RuntimeException('Post creation returned no id');
    }
    $postId = (int) $post['post']['id'];
    ok("post created (id=$postId)");

    // ── 6. Update post ────────────────────────────────────────────────────────
    $updated = $admin->request('PATCH', "/api/promote/campaigns/$campaignId/posts/$postId", [
        'title'  => 'Smoke post: announce show [updated]',
        'status' => 'approved',
    ]);
    if ((string) ($updated['post']['status'] ?? '') !== 'approved') {
        throw new RuntimeException('Post update did not set status to approved');
    }
    if ((string) ($updated['post']['title'] ?? '') !== 'Smoke post: announce show [updated]') {
        throw new RuntimeException('Post update did not persist new title');
    }
    ok('post updated (title + status)');

    // ── 7. Generate variants ──────────────────────────────────────────────────
    $generated = $admin->request(
        'POST',
        "/api/promote/campaigns/$campaignId/posts/$postId/variants/generate"
    );
    if (!isset($generated['variants']) || !is_array($generated['variants'])) {
        throw new RuntimeException('Variant generate returned no variants array');
    }
    $channels = array_column($generated['variants'], 'channel');
    $expected9 = ['instagram', 'facebook', 'tiktok', 'email', 'eventbrite', 'luma', 'funcheap', 'foopee', 'press'];
    $missing = array_diff($expected9, $channels);
    if ($missing) {
        throw new RuntimeException('Variant generate missing channels: ' . implode(', ', $missing));
    }
    // Each variant should have warnings
    $noWarnings = array_filter($generated['variants'], function ($v) {
        $w = $v['warnings_json'] ?? null;
        if ($w === null) return true;
        $arr = is_string($w) ? json_decode($w, true) : $w;
        return empty($arr);
    });
    if (!empty($noWarnings)) {
        $channelsWithoutWarnings = array_column(array_values($noWarnings), 'channel');
        // Press and email always have warnings; allow social channels to have none only if body is populated
        // But we assert warnings exist for all channels per PROMOTE-PLAN.md
        throw new RuntimeException(
            'Variants without warnings (expected at least one warning per channel): '
            . implode(', ', $channelsWithoutWarnings)
        );
    }
    ok('variants generated — 9 channels with warnings');

    // ── 8. Create broadcast (multi-destination, send_mode=now) ───────────────
    $broadcast = $admin->request('POST', "/api/promote/campaigns/$campaignId/broadcasts", [
        'post_id'      => $postId,
        'send_mode'    => 'now',
        'destinations' => [
            'facebook_page',
            'instagram',
            'tiktok',
            'funcheap',
            'foopee',
            'press_list',
            'email_general',
            'email_press',
        ],
    ]);

    if (empty($broadcast['broadcast']['id'])) {
        throw new RuntimeException('Broadcast creation returned no id');
    }
    $broadcastId = (int) $broadcast['broadcast']['id'];

    $results = $broadcast['broadcast']['results'] ?? [];
    if (empty($results)) {
        throw new RuntimeException('Broadcast created but has no results');
    }
    $resultMap = [];
    foreach ($results as $r) {
        $resultMap[(string) $r['destination_key']] = (string) $r['status'];
    }

    // Assert per-destination result statuses
    $expectNeeds = ['facebook_page', 'instagram', 'tiktok'];
    foreach ($expectNeeds as $dest) {
        if (($resultMap[$dest] ?? null) !== 'needs_auth') {
            throw new RuntimeException(
                "Expected $dest → needs_auth, got: " . ($resultMap[$dest] ?? 'missing')
            );
        }
    }

    $expectManual = ['funcheap', 'foopee', 'press_list'];
    foreach ($expectManual as $dest) {
        if (($resultMap[$dest] ?? null) !== 'manual_required') {
            throw new RuntimeException(
                "Expected $dest → manual_required, got: " . ($resultMap[$dest] ?? 'missing')
            );
        }
    }

    $expectSent = ['email_general', 'email_press'];
    foreach ($expectSent as $dest) {
        if (($resultMap[$dest] ?? null) !== 'sent') {
            throw new RuntimeException(
                "Expected $dest → sent, got: " . ($resultMap[$dest] ?? 'missing')
            );
        }
    }
    ok('broadcast created with correct per-destination statuses (needs_auth/manual_required/sent)');

    // ── 9. Fetch broadcasts list ──────────────────────────────────────────────
    $broadcasts = $admin->request('GET', "/api/promote/campaigns/$campaignId/broadcasts");
    if (!isset($broadcasts['broadcasts']) || !is_array($broadcasts['broadcasts'])) {
        throw new RuntimeException('Broadcasts list returned no broadcasts array');
    }
    if (empty($broadcasts['broadcasts'])) {
        throw new RuntimeException('Broadcasts list empty after creating one');
    }
    ok('broadcasts list returned (' . count($broadcasts['broadcasts']) . ' broadcast(s))');

    // ── 10. Fetch health ──────────────────────────────────────────────────────
    $health = $admin->request('GET', "/api/promote/campaigns/$campaignId/health");
    if (!isset($health['health']['score'], $health['health']['complete'],
                $health['health']['total'], $health['health']['items'])) {
        throw new RuntimeException('Health endpoint missing required keys (score/complete/total/items)');
    }
    if (!is_array($health['health']['items']) || count($health['health']['items']) === 0) {
        throw new RuntimeException('Health items array is empty');
    }
    // Validate each item has the documented shape
    foreach ($health['health']['items'] as $item) {
        foreach (['key', 'label', 'status', 'severity', 'detail'] as $field) {
            if (!array_key_exists($field, $item)) {
                throw new RuntimeException("Health item missing field: $field");
            }
        }
    }
    $score = (int) $health['health']['score'];
    ok("health fetched (score={$score}, complete={$health['health']['complete']}/{$health['health']['total']})");

    // ── 11. Fetch analytics ───────────────────────────────────────────────────
    $analytics = $admin->request('GET', "/api/promote/campaigns/$campaignId/analytics");
    if (!isset($analytics['analytics'])) {
        throw new RuntimeException('Analytics endpoint missing analytics key');
    }
    $a = $analytics['analytics'];
    foreach (['website_clicks', 'rsvps', 'ticket_conversions', 'email_opens'] as $key) {
        if (!array_key_exists($key, $a)) {
            throw new RuntimeException("Analytics missing key: $key");
        }
        if ((int) $a[$key] !== 0) {
            throw new RuntimeException("Analytics $key expected 0 stub, got: " . $a[$key]);
        }
    }
    ok('analytics returned stub zeros');

    // ── 12. Fetch destinations ────────────────────────────────────────────────
    $dests = $admin->request('GET', "/api/promote/campaigns/$campaignId/destinations");
    if (!isset($dests['destinations']) || !is_array($dests['destinations'])) {
        throw new RuntimeException('Destinations endpoint missing destinations array');
    }
    $destCount = count($dests['destinations']);
    if ($destCount < 8) {
        throw new RuntimeException("Expected at least 8 destinations, got $destCount");
    }
    ok("destinations listed ($destCount destinations)");

    // ── 13. 404 for a missing post id ─────────────────────────────────────────
    $notFound = $admin->request(
        'GET',
        "/api/promote/campaigns/$campaignId/posts/999999",
        null,
        [404]
    );
    if ($notFound['status'] !== 404) {
        throw new RuntimeException('Expected 404 for missing post id, got: ' . $notFound['status']);
    }
    ok('404 for missing post id');

    // ── 14. Unauthenticated request → 401 ────────────────────────────────────
    $anon  = new SmokeClient($baseUrl);
    $unauth = $anon->request(
        'GET',
        "/api/promote/campaigns/$campaignId",
        null,
        [401]
    );
    if ($unauth['status'] !== 401) {
        throw new RuntimeException('Expected 401 for unauthenticated request, got: ' . $unauth['status']);
    }
    ok('unauthenticated request → 401');

    // ── 15. Viewer role: GET allowed, POST denied (403) ──────────────────────
    // Invite a viewer using the invite flow (mirrors endpoint-smoke.php pattern)
    $viewerEmail = 'promote-viewer+' . bin2hex(random_bytes(4)) . '@example.com';
    $invite      = $admin->request('POST', "/api/events/$eventId/invites", [
        'email' => $viewerEmail,
        'role'  => 'viewer',
    ]);

    $viewer   = new SmokeClient($baseUrl);
    $accepted = $viewer->request('POST', '/api/invite/' . $invite['token'], [
        'name' => 'Promote Smoke Viewer',
    ]);
    if (empty($accepted['access_token'])) {
        throw new RuntimeException('Viewer invite accept returned no access_token');
    }
    $viewer->setToken($accepted['access_token']);

    // Viewer GET campaign overview → should succeed (read_event)
    $viewerRead = $viewer->request('GET', "/api/promote/campaigns/$campaignId");
    if (empty($viewerRead['campaign']['id'])) {
        throw new RuntimeException('Viewer GET campaign overview failed unexpectedly');
    }
    ok('viewer can GET campaign overview');

    // Viewer POST create post → should be blocked (needs edit_event)
    $viewerPost = $viewer->request(
        'POST',
        "/api/promote/campaigns/$campaignId/posts",
        ['title' => 'Viewer unauthorized post', 'status' => 'draft'],
        [403]
    );
    if ($viewerPost['status'] !== 403) {
        throw new RuntimeException('Expected 403 for viewer POST create post, got: ' . $viewerPost['status']);
    }
    ok('viewer POST new post → 403 (read-only role blocked)');

    // Viewer POST create campaign → should be blocked (needs edit_event)
    $viewerCampaign = $viewer->request(
        'POST',
        "/api/promote/events/$eventId/campaign",
        [],
        [403]
    );
    if ($viewerCampaign['status'] !== 403) {
        throw new RuntimeException('Expected 403 for viewer POST campaign, got: ' . $viewerCampaign['status']);
    }
    ok('viewer POST campaign → 403 (read-only role blocked)');

    echo "\nPromote smoke test complete against $baseUrl\n";
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAIL " . $error->getMessage() . "\n");
    exit(1);
}
