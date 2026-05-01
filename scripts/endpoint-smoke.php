<?php
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$cookie = '';
$csrf = '';

function request(string $method, string $path, mixed $body = null): array
{
    global $baseUrl, $cookie, $csrf;
    $headers = ['Accept: application/json'];
    $content = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_THROW_ON_ERROR);
    }
    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($baseUrl . $path, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $status = (int) ($match[1] ?? 0);
    foreach ($http_response_header ?? [] as $header) {
        if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $cookieMatch)) {
            $cookie = $cookieMatch[1];
        }
    }
    $json = json_decode($raw ?: 'null', true);
    if (is_array($json) && isset($json['csrf'])) {
        $csrf = (string) $json['csrf'];
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("$method $path failed with HTTP $status: " . ($raw ?: 'empty response'));
    }
    return is_array($json) ? $json : [];
}

function ok(string $message): void
{
    echo "OK  $message\n";
}

try {
    request('POST', '/api/login', ['email' => 'admin@mabuhay.local', 'password' => 'changeme']);
    ok('login/session/CSRF');

    request('GET', '/api/dashboard');
    ok('dashboard data');

    $templates = request('GET', '/api/templates');
    $templateId = (int) ($templates['templates'][0]['id'] ?? 0);
    if (!$templateId) {
        throw new RuntimeException('No templates returned.');
    }

    $date = (new DateTimeImmutable('+21 days'))->format('Y-m-d');
    $created = request('POST', "/api/events/from-template/$templateId", [
        'date' => $date,
        'doors_time' => '19:00',
        'show_time' => '20:00',
        'title' => 'Smoke Test Showcase',
    ]);
    $eventId = (int) $created['id'];
    ok('template create-from-template');

    $detail = request('GET', "/api/events/$eventId");
    ok('event detail');

    $item = request('POST', "/api/events/$eventId/open-items", [
        'title' => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status' => 'open',
        'due_date' => $date,
    ]);
    request('PATCH', "/api/events/$eventId/open-items/" . (int) $item['id'], [
        'title' => 'Smoke test open item',
        'description' => 'Created by endpoint smoke test',
        'status' => 'resolved',
        'due_date' => $date,
    ]);
    ok('open item update');

    request('POST', "/api/events/$eventId/settlement", [
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
    request('PATCH', "/api/events/$eventId", $event);
    request('GET', '/api/public/events/' . rawurlencode((string) $event['slug']));
    ok('public event visibility');

    echo "Smoke test complete against $baseUrl\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL " . $error->getMessage() . "\n");
    exit(1);
}
