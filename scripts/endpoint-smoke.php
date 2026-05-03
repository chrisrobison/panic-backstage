<?php
declare(strict_types=1);

/**
 * Endpoint smoke test — JWT / passwordless auth edition.
 *
 * Usage:
 *   php scripts/endpoint-smoke.php [base-url] [mail-dir]
 *
 *   base-url  defaults to http://localhost:8000
 *   mail-dir  defaults to storage/mail  (relative to project root)
 *
 * The test uses the magic-link flow for login:
 *   1. POST /api/auth/magic-link  → triggers an .eml file on disk
 *   2. Parse the token from the newest .eml in storage/mail/
 *   3. POST /api/auth/verify      → get JWT pair
 *   4. Use Bearer token for all subsequent requests
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

/**
 * Find the most-recently written .eml in $mailDir and extract the
 * magic-link token from it.  The token is the hex string in the login URL.
 */
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
    // ── Admin login via magic link ──────────────────────────────────────────
    $admin = new SmokeClient($baseUrl);

    $admin->request('POST', '/api/auth/magic-link', ['email' => 'admin@mabuhay.local']);
    ok('magic-link email requested');

    sleep(1); // give disk write a moment
    $token = extractTokenFromLatestMail($mailDir);

    $auth = $admin->request('POST', '/api/auth/verify', ['token' => $token]);
    if (empty($auth['access_token'])) {
        throw new RuntimeException('No access_token in verify response');
    }
    $admin->setToken($auth['access_token']);
    $refreshToken = $auth['refresh_token'];
    ok('magic-link verify → JWT issued');

    // ── Token refresh ───────────────────────────────────────────────────────
    $refreshed = $admin->request('POST', '/api/auth/refresh', ['refresh_token' => $refreshToken]);
    if (empty($refreshed['access_token'])) {
        throw new RuntimeException('Token refresh failed');
    }
    $admin->setToken($refreshed['access_token']);
    ok('token refresh (rotation)');

    // ── Authenticated requests ──────────────────────────────────────────────
    $admin->request('GET', '/api/dashboard');
    ok('dashboard data');

    $templates  = $admin->request('GET', '/api/templates');
    $templateId = (int) ($templates['templates'][0]['id'] ?? 0);
    if (!$templateId) {
        throw new RuntimeException('No templates returned');
    }

    $date    = (new DateTimeImmutable('+21 days'))->format('Y-m-d');
    $created = $admin->request('POST', "/api/events/from-template/$templateId", [
        'date'       => $date,
        'doors_time' => '19:00',
        'show_time'  => '20:00',
        'title'      => 'Smoke Test Showcase',
    ]);
    $eventId = (int) $created['id'];
    ok('event created from template');

    $detail = $admin->request('GET', "/api/events/$eventId");
    ok('event detail');

    $item = $admin->request('POST', "/api/events/$eventId/open-items", [
        'title'       => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status'      => 'open',
        'due_date'    => $date,
    ]);
    $admin->request('PATCH', "/api/events/$eventId/open-items/" . (int) $item['id'], [
        'title'       => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status'      => 'resolved',
        'due_date'    => $date,
    ]);
    ok('open item created + resolved');

    // ── Invite flow ─────────────────────────────────────────────────────────
    $inviteEmail = 'smoke-viewer+' . bin2hex(random_bytes(4)) . '@example.com';
    $invite      = $admin->request('POST', "/api/events/$eventId/invites", [
        'email' => $inviteEmail,
        'role'  => 'viewer',
    ]);
    ok('invite link created + email written');

    // Accept invite (new user, no password)
    $viewer = new SmokeClient($baseUrl);
    $accepted = $viewer->request('POST', '/api/invite/' . $invite['token'], [
        'name' => 'Smoke Viewer',
    ]);
    if (empty($accepted['access_token'])) {
        throw new RuntimeException('No access_token after invite accept');
    }
    $viewer->setToken($accepted['access_token']);
    ok('invite accepted → JWT issued (no password)');

    $viewer->request('GET', "/api/events/$eventId");
    ok('invited viewer can load collaborating event');

    // Viewer must not see unrelated events
    $allEvents = $admin->request('GET', '/api/events');
    $unrelated = null;
    foreach ($allEvents['events'] as $event) {
        if ((int) $event['id'] !== $eventId) {
            $unrelated = (int) $event['id'];
            break;
        }
    }
    if ($unrelated) {
        $blocked = $viewer->request('GET', "/api/events/$unrelated", null, [403, 404]);
        if (!in_array($blocked['status'], [403, 404], true)) {
            throw new RuntimeException('Viewer unexpectedly loaded an unrelated event');
        }
        ok('viewer cannot load unrelated events');
    } else {
        ok('(skipped) no second event available to test isolation');
    }

    // Viewer must not mutate event details
    $blockedMutation = $viewer->request('PATCH', "/api/events/$eventId", ['status' => 'published'], [403]);
    if ($blockedMutation['status'] !== 403) {
        throw new RuntimeException('Viewer unexpectedly mutated event details');
    }
    ok('viewer cannot mutate event');

    // ── Settlement ──────────────────────────────────────────────────────────
    $admin->request('POST', "/api/events/$eventId/settlement", [
        'gross_ticket_sales' => 100,
        'tickets_sold'       => 10,
        'bar_sales'          => 50,
        'expenses'           => 25,
        'band_payouts'       => 40,
        'promoter_payout'    => 0,
        'venue_net'          => 85,
        'notes'              => 'Smoke settlement',
    ]);
    ok('settlement saved');

    // ── Public page ─────────────────────────────────────────────────────────
    $eventData = $detail['event'];
    $eventData['status']             = 'published';
    $eventData['public_visibility']  = 1;
    $admin->request('PATCH', "/api/events/$eventId", $eventData);
    $admin->request('GET', '/api/public/events/' . rawurlencode((string) $eventData['slug']));
    ok('public event page visible');

    // ── Logout ──────────────────────────────────────────────────────────────
    $admin->request('POST', '/api/auth/logout', ['refresh_token' => $refreshed['refresh_token']]);
    ok('logout (refresh token revoked)');

    echo "\nSmoke test complete against $baseUrl\n";
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAIL " . $error->getMessage() . "\n");
    exit(1);
}
