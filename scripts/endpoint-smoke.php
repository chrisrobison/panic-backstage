<?php
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://localhost:8000', '/');

final class SmokeClient
{
    private string $cookie = '';
    private string $csrf = '';

    public function __construct(private readonly string $baseUrl) {}

    public function request(string $method, string $path, mixed $body = null, array $expectedStatuses = []): array
    {
        $headers = ['Accept: application/json'];
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($this->csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($this->baseUrl . $path, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000';
        preg_match('/\s(\d{3})\s/', $statusLine, $match);
        $status = (int) ($match[1] ?? 0);
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $cookieMatch)) {
                $this->cookie = $cookieMatch[1];
            }
        }
        $json = json_decode($raw ?: 'null', true);
        if (is_array($json) && isset($json['csrf'])) {
            $this->csrf = (string) $json['csrf'];
        }
        if ($expectedStatuses && in_array($status, $expectedStatuses, true)) {
            return ['status' => $status, 'body' => is_array($json) ? $json : []];
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("$method $path failed with HTTP $status: " . ($raw ?: 'empty response'));
        }
        return is_array($json) ? $json : [];
    }
}

function ok(string $message): void
{
    echo "OK  $message\n";
}

try {
    $admin = new SmokeClient($baseUrl);
    $admin->request('POST', '/api/login', ['email' => 'admin@mabuhay.local', 'password' => 'changeme']);
    ok('login/session/CSRF');

    $admin->request('GET', '/api/dashboard');
    ok('dashboard data');

    $templates = $admin->request('GET', '/api/templates');
    $templateId = (int) ($templates['templates'][0]['id'] ?? 0);
    if (!$templateId) {
        throw new RuntimeException('No templates returned.');
    }

    $date = (new DateTimeImmutable('+21 days'))->format('Y-m-d');
    $created = $admin->request('POST', "/api/events/from-template/$templateId", [
        'date' => $date,
        'doors_time' => '19:00',
        'show_time' => '20:00',
        'title' => 'Smoke Test Showcase',
    ]);
    $eventId = (int) $created['id'];
    ok('template create-from-template');

    $detail = $admin->request('GET', "/api/events/$eventId");
    ok('event detail');

    $item = $admin->request('POST', "/api/events/$eventId/open-items", [
        'title' => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status' => 'open',
        'due_date' => $date,
    ]);
    $admin->request('PATCH', "/api/events/$eventId/open-items/" . (int) $item['id'], [
        'title' => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status' => 'resolved',
        'due_date' => $date,
    ]);
    ok('open item update');

    $inviteEmail = 'smoke-viewer+' . bin2hex(random_bytes(4)) . '@example.com';
    $invite = $admin->request('POST', "/api/events/$eventId/invites", [
        'email' => $inviteEmail,
        'role' => 'viewer',
    ]);
    ok('invite link creation');

    $viewer = new SmokeClient($baseUrl);
    $viewer->request('POST', '/api/invite/' . $invite['token'], [
        'name' => 'Smoke Viewer',
        'password' => 'changeme-smoke',
    ]);
    ok('invite acceptance');

    $viewer->request('GET', "/api/events/$eventId");
    ok('invited user can load collaborating event');

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
            throw new RuntimeException('Viewer unexpectedly loaded an unrelated event.');
        }
        ok('invited user cannot load unrelated events');
    } else {
        ok('unrelated-event access skipped; no second event was available');
    }

    $blockedMutation = $viewer->request('PATCH', "/api/events/$eventId", ['status' => 'published'], [403]);
    if ($blockedMutation['status'] !== 403) {
        throw new RuntimeException('Viewer unexpectedly mutated event details.');
    }
    ok('viewer cannot mutate event details');

    $admin->request('POST', "/api/events/$eventId/settlement", [
        'gross_ticket_sales' => 100,
        'tickets_sold' => 10,
        'bar_sales' => 50,
        'expenses' => 25,
        'band_payouts' => 40,
        'promoter_payout' => 0,
        'venue_net' => 85,
        'notes' => 'Smoke settlement',
    ]);
    ok('settlement save');

    $event = $detail['event'];
    $event['status'] = 'published';
    $event['public_visibility'] = 1;
    $admin->request('PATCH', "/api/events/$eventId", $event);
    $admin->request('GET', '/api/public/events/' . rawurlencode((string) $event['slug']));
    ok('public event visibility');
    ok('designer asset upload is manual; multipart upload is not exercised by this smoke script');

    echo "Smoke test complete against $baseUrl\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL " . $error->getMessage() . "\n");
    exit(1);
}
