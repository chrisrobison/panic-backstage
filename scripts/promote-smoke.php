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
 * Coverage:
 *  1.  Admin login via magic-link
 *  2.  Create test event (from template)
 *  3.  Fetch promote overview for event — assert settings, posts, health, destinations, analytics
 *  4.  Create post
 *  5.  Update post
 *  6.  Generate variants — assert all 15 channels + warnings present
 *  7.  Create broadcast with destinations from all 4 groups (send_mode=now)
 *       — facebook_page/instagram/tiktok → needs_auth (no credentials in test env)
 *       — funcheap/foopee/press_list     → manual_required
 *       — email_general/email_press      → needs_auth (real adapter, no credentials in test env)
 *  8.  Fetch event broadcasts list
 *  9.  Fetch health — assert {score, complete, total, items[]}
 *  10. Fetch analytics — assert required keys
 *  11. Fetch destinations
 *  12. 404 for a missing post id
 *  13. Unauthenticated request → 401
 *  14. Invite a viewer; viewer can GET overview, gets 403 on POST
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

    // ── 3. Fetch promote overview for event ──────────────────────────────────
    $overview = $admin->request('GET', "/api/promote/events/$eventId");

    if (!isset($overview['event']['id'])) {
        throw new RuntimeException('Overview missing event data');
    }
    if (!array_key_exists('posts', $overview)) {
        throw new RuntimeException('Overview missing posts array');
    }
    if (!isset($overview['health']['score'], $overview['health']['complete'],
                $overview['health']['total'], $overview['health']['items'])) {
        throw new RuntimeException('Overview missing health keys (score/complete/total/items)');
    }
    if (!array_key_exists('destinations', $overview)) {
        throw new RuntimeException('Overview missing destinations');
    }
    if (!isset($overview['analytics']['website_clicks'])) {
        throw new RuntimeException('Overview missing analytics data');
    }
    if (!array_key_exists('settings', $overview)) {
        throw new RuntimeException('Overview missing settings key');
    }
    ok('promote overview contains event, settings, posts, health, destinations, analytics');

    // ── 4. Create post ────────────────────────────────────────────────────────
    $post = $admin->request('POST', "/api/promote/events/$eventId/posts", [
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

    // ── 5. Update post ────────────────────────────────────────────────────────
    $updated = $admin->request('PATCH', "/api/promote/events/$eventId/posts/$postId", [
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

    // ── 6. Generate variants ──────────────────────────────────────────────────
    $generated = $admin->request(
        'POST',
        "/api/promote/events/$eventId/posts/$postId/variants/generate"
    );
    if (!isset($generated['variants']) || !is_array($generated['variants'])) {
        throw new RuntimeException('Variant generate returned no variants array');
    }
    $channels = array_column($generated['variants'], 'channel');
    $expectedChannels = [
        'instagram', 'facebook', 'tiktok',
        'email', 'email_adhoc',
        'eventbrite', 'luma',
        'funcheap', 'foopee', 'press',
        'sf_chronicle', 'sf_station', 'dothebay',
        'songkick', 'jambase',
    ];
    $missing = array_diff($expectedChannels, $channels);
    if ($missing) {
        throw new RuntimeException('Variant generate missing channels: ' . implode(', ', $missing));
    }
    $noWarnings = array_filter($generated['variants'], function ($v) {
        $w = $v['warnings_json'] ?? null;
        if ($w === null) return true;
        $arr = is_string($w) ? json_decode($w, true) : $w;
        return empty($arr);
    });
    if (!empty($noWarnings)) {
        $channelsWithoutWarnings = array_column(array_values($noWarnings), 'channel');
        throw new RuntimeException(
            'Variants without warnings (expected at least one warning per channel): '
            . implode(', ', $channelsWithoutWarnings)
        );
    }
    ok('variants generated — ' . count($channels) . ' channels with warnings');

    // ── 7. Create broadcast (multi-destination, send_mode=now) ───────────────
    $broadcast = $admin->request('POST', "/api/promote/events/$eventId/broadcasts", [
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

    $expectEmailNeeds = ['email_general', 'email_press'];
    foreach ($expectEmailNeeds as $dest) {
        $got = $resultMap[$dest] ?? 'missing';
        if (!in_array($got, ['needs_auth', 'sent', 'queued'], true)) {
            throw new RuntimeException(
                "Expected $dest → needs_auth|sent|queued (real adapter), got: $got"
            );
        }
    }
    ok('broadcast created with correct per-destination statuses');

    // ── 8. Fetch broadcasts list ──────────────────────────────────────────────
    $broadcasts = $admin->request('GET', "/api/promote/events/$eventId/broadcasts");
    if (!isset($broadcasts['broadcasts']) || !is_array($broadcasts['broadcasts'])) {
        throw new RuntimeException('Broadcasts list returned no broadcasts array');
    }
    if (empty($broadcasts['broadcasts'])) {
        throw new RuntimeException('Broadcasts list empty after creating one');
    }
    ok('broadcasts list returned (' . count($broadcasts['broadcasts']) . ' broadcast(s))');

    // ── 9. Fetch health ──────────────────────────────────────────────────────
    $health = $admin->request('GET', "/api/promote/events/$eventId/health");
    if (!isset($health['health']['score'], $health['health']['complete'],
                $health['health']['total'], $health['health']['items'])) {
        throw new RuntimeException('Health endpoint missing required keys (score/complete/total/items)');
    }
    if (!is_array($health['health']['items']) || count($health['health']['items']) === 0) {
        throw new RuntimeException('Health items array is empty');
    }
    foreach ($health['health']['items'] as $item) {
        foreach (['key', 'label', 'status', 'severity', 'detail'] as $field) {
            if (!array_key_exists($field, $item)) {
                throw new RuntimeException("Health item missing field: $field");
            }
        }
    }
    $score = (int) $health['health']['score'];
    ok("health fetched (score={$score}, complete={$health['health']['complete']}/{$health['health']['total']})");

    // ── 10. Fetch analytics ───────────────────────────────────────────────────
    $analytics = $admin->request('GET', "/api/promote/events/$eventId/analytics");
    if (!isset($analytics['analytics'])) {
        throw new RuntimeException('Analytics endpoint missing analytics key');
    }
    $a = $analytics['analytics'];
    $requiredKeys = ['website_clicks', 'rsvps', 'ticket_conversions', 'email_opens',
                     'broadcast_count', 'destinations_reached', 'listings_live',
                     'manual_pending', 'needs_setup', 'failed_count', 'status_counts'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $a)) {
            throw new RuntimeException("Analytics missing key: $key");
        }
    }
    ok('analytics returned with required keys');

    // ── 11. Fetch destinations ────────────────────────────────────────────────
    $dests = $admin->request('GET', "/api/promote/events/$eventId/destinations");
    if (!isset($dests['destinations']) || !is_array($dests['destinations'])) {
        throw new RuntimeException('Destinations endpoint missing destinations array');
    }
    $destCount = count($dests['destinations']);
    if ($destCount < 17) {
        throw new RuntimeException("Expected at least 17 destinations, got $destCount");
    }
    ok("destinations listed ($destCount destinations)");

    // ── 12. 404 for a missing post id ─────────────────────────────────────────
    $notFound = $admin->request(
        'GET',
        "/api/promote/events/$eventId/posts/999999",
        null,
        [404]
    );
    if ($notFound['status'] !== 404) {
        throw new RuntimeException('Expected 404 for missing post id, got: ' . $notFound['status']);
    }
    ok('404 for missing post id');

    // ── 13. Unauthenticated request → 401 ────────────────────────────────────
    $anon   = new SmokeClient($baseUrl);
    $unauth = $anon->request(
        'GET',
        "/api/promote/events/$eventId",
        null,
        [401]
    );
    if ($unauth['status'] !== 401) {
        throw new RuntimeException('Expected 401 for unauthenticated request, got: ' . $unauth['status']);
    }
    ok('unauthenticated request → 401');

    // ── 14. Viewer role: GET allowed, POST denied (403) ──────────────────────
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

    // Viewer GET promote overview → should succeed (read_event)
    $viewerRead = $viewer->request('GET', "/api/promote/events/$eventId");
    if (!isset($viewerRead['event']['id'])) {
        throw new RuntimeException('Viewer GET promote overview failed unexpectedly');
    }
    ok('viewer can GET promote overview');

    // Viewer POST create post → should be blocked (needs edit_event)
    $viewerPost = $viewer->request(
        'POST',
        "/api/promote/events/$eventId/posts",
        ['title' => 'Viewer unauthorized post', 'status' => 'draft'],
        [403]
    );
    if ($viewerPost['status'] !== 403) {
        throw new RuntimeException('Expected 403 for viewer POST create post, got: ' . $viewerPost['status']);
    }
    ok('viewer POST new post → 403 (read-only role blocked)');

    echo "\nPromote smoke test complete against $baseUrl\n";
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAIL " . $error->getMessage() . "\n");
    exit(1);
}
