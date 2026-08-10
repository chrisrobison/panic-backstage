<?php
/** DB-backed coverage for issue #21: audience capture + stable series URL. */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\PublicSeries;
use Panic\Request;
use Panic\Response;
use Panic\TicketingService;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}
function responseBody(Response $response): array
{
    $property = new ReflectionProperty(Response::class, 'body');
    $property->setAccessible(true);
    return (array) $property->getValue($response);
}

echo "\n=== Ticket audience capture + stable series URL (DB-backed) ===\n\n";
$db = new Database();
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
if ($venue === null) {
    echo "  no venue available — cannot run\n";
    exit(1);
}

$marker = bin2hex(random_bytes(5));
$email = "pb-audience-{$marker}@example.test";
$uncheckedEmail = "pb-audience-unchecked-{$marker}@example.test";
$eventId = 0;
$seriesId = 0;
$seriesEventIds = [];

try {
    $eventId = $db->insert(
        "INSERT INTO events (venue_id, title, slug, event_type, status, date, public_visibility, ticketing_mode)
         VALUES (?, ?, ?, 'live_music', 'published', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1, 'internal')",
        [(int) $venue['id'], "PB audience {$marker}", "pb-audience-{$marker}"]
    );
    $typeId = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
         VALUES (?, 'Free Registration', 0, 'USD', 20, 'on_sale')",
        [$eventId]
    );
    $makeOrder = static function (string $buyerEmail, int $quantity, int $optIn) use ($db, $eventId, $typeId): int {
        $orderId = $db->insert(
            "INSERT INTO ticket_orders
                (event_id, buyer_name, buyer_email, buyer_phone, marketing_opt_in,
                 amount_cents, currency, status)
             VALUES (?, 'Ada Lovelace', ?, '555-0100', ?, 0, 'USD', 'pending')",
            [$eventId, $buyerEmail, $optIn]
        );
        $db->run(
            'INSERT INTO ticket_order_items (order_id, ticket_type_id, quantity, unit_price_cents) VALUES (?, ?, ?, 0)',
            [$orderId, $typeId, $quantity]
        );
        return $orderId;
    };

    $service = new TicketingService();
    $firstOrder = $makeOrder($email, 2, 1);
    $service->fulfillOrder($db, $firstOrder);
    $contact = $db->one('SELECT * FROM contacts WHERE email = ?', [$email]);
    $storedOrder = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$firstOrder]);
    ok($contact !== null && $contact['source'] === 'ticketing', 'free registration creates a ticketing contact');
    ok((int) $contact['marketing_opted_in'] === 1 && !empty($contact['opt_in_date']), 'affirmative checkout consent is retained with a date');
    ok((int) $contact['events_count'] === 1 && (int) $contact['tickets_count'] === 2, 'contact audience totals reflect the registration');
    ok((int) $storedOrder['contact_id'] === (int) $contact['id'] && !empty($storedOrder['audience_synced_at']), 'order is linked and stamped as audience-synced');

    $service->fulfillOrder($db, $firstOrder);
    $contactAfterRetry = $db->one('SELECT * FROM contacts WHERE id = ?', [(int) $contact['id']]);
    ok((int) $contactAfterRetry['tickets_count'] === 2, 'fulfillment retry does not double-count the audience');

    $secondOrder = $makeOrder($email, 1, 0);
    $service->fulfillOrder($db, $secondOrder);
    $contactAfterSecond = $db->one('SELECT * FROM contacts WHERE id = ?', [(int) $contact['id']]);
    ok((int) $contactAfterSecond['events_count'] === 1 && (int) $contactAfterSecond['tickets_count'] === 3, 'another order for the same event increments tickets but not distinct events');
    ok((int) $contactAfterSecond['marketing_opted_in'] === 1, 'an unchecked later order never revokes existing consent');

    $uncheckedOrder = $makeOrder($uncheckedEmail, 1, 0);
    $service->fulfillOrder($db, $uncheckedOrder);
    $uncheckedContact = $db->one('SELECT * FROM contacts WHERE email = ?', [$uncheckedEmail]);
    ok($uncheckedContact !== null && (int) $uncheckedContact['marketing_opted_in'] === 0, 'registration captures contact details without silently subscribing');

    $seriesSlug = "pb-series-{$marker}";
    $seriesId = $db->insert(
        "INSERT INTO event_series (venue_id, title, public_slug, end_type) VALUES (?, ?, ?, 'after_count')",
        [(int) $venue['id'], "PB series {$marker}", $seriesSlug]
    );
    foreach ([
        ['date' => '2000-01-01', 'status' => 'completed'],
        ['date' => (new DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d'), 'status' => 'published'],
        ['date' => (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'), 'status' => 'canceled'],
    ] as $index => $row) {
        $seriesEventIds[] = $db->insert(
            'INSERT INTO events (venue_id, series_id, title, slug, event_type, status, date, public_visibility) VALUES (?, ?, ?, ?, \'live_music\', ?, ?, 1)',
            [(int) $venue['id'], $seriesId, "PB series {$marker}", "pb-series-{$marker}-{$index}", $row['status'], $row['date']]
        );
    }

    $endpoint = new PublicSeries($db, new Auth(), ['slug' => $seriesSlug], dirname(__DIR__));
    $body = responseBody($endpoint->handle(new Request('GET', '/api/public/series/' . $seriesSlug, [], [], [], [])));
    ok((int) $body['event_id'] === $seriesEventIds[1], 'stable series URL resolves the next visible non-canceled occurrence');
    ok($body['series']['public_page'] === 'event.html?series=' . $seriesSlug, 'series response returns its reusable public page');
} finally {
    if ($eventId) {
        $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
    }
    foreach ($seriesEventIds as $id) {
        $db->run('DELETE FROM events WHERE id = ?', [$id]);
    }
    if ($seriesId) {
        $db->run('DELETE FROM event_series WHERE id = ?', [$seriesId]);
    }
    $db->run('DELETE FROM contacts WHERE email IN (?, ?)', [$email, $uncheckedEmail]);
}

echo "\nTicket audience + series: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
