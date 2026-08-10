<?php
/**
 * DB-backed regression test for editable event cloning (issue #34).
 *
 * Uses the configured development database, disables both external syncs,
 * creates marker-prefixed fixtures on unused future dates, and removes them
 * in a finally block. Opt in with RUN_DB_TESTS=1.
 *
 * Run with: RUN_DB_TESTS=1 php tests/events_clone_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;
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

echo "\n=== Editable event clone (issue #34) ===\n\n";

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
    fwrite(STDERR, "events_clone_db_test.php needs a venue and venue_admin user.\n");
    exit(1);
}
$venueId = (int) $venue['id'];
$room = $db->one('SELECT id FROM resources WHERE venue_id = ? AND active = 1 ORDER BY id LIMIT 1', [$venueId]);
$roomId = $room ? (int) $room['id'] : null;

$auth = new Auth();
$auth->setUser($admin);
$marker = 'PB TEST CLONE — ' . bin2hex(random_bytes(4));
$created = [];

try {
    $cursor = new DateTimeImmutable('+500 days');
    while ($db->one(
        "SELECT id FROM events WHERE venue_id = ? AND status NOT IN ('empty','canceled') AND date <= ? AND COALESCE(end_date, date) >= ? LIMIT 1",
        [$venueId, $cursor->format('Y-m-d'), $cursor->format('Y-m-d')]
    )) {
        $cursor = $cursor->modify('+1 day');
    }
    $sourceDate = $cursor->format('Y-m-d');
    $cloneDate = $cursor->modify('+1 day')->format('Y-m-d');

    $sourceId = $db->insert(
        "INSERT INTO events
            (venue_id, resource_id, title, slug, event_type, status, date,
             load_in_time, doors_time, show_time, end_time, load_out_time, age_restriction,
             description_public, description_internal, av_requirements, catering_notes, contract_details,
             ticket_price, ticket_url, ticket_system, deposit_amount, contract_url,
             capacity, public_visibility, owner_user_id,
             promoter_name, promoter_email, promoter_phone, booker_name, booker_email, booker_phone)
         VALUES (?, ?, ?, ?, 'live_music', 'booked', ?, '17:00', '18:00', '19:00', '22:00', '23:00', '21+',
                 'Public copy', 'Private copy', 'Projector', 'Green room water', 'Guarantee $500; house sound included.',
                 25, 'https://tickets.example.invalid/source', 'external', 500, 'https://contracts.example.invalid/source',
                 200, 1, ?, 'Contract Person', 'contract@example.invalid', '555-1000', 'Venue Booker', 'booker@example.invalid', '555-2000')",
        [$venueId, $roomId, $marker, \Panic\slugify($marker . '-' . $sourceDate), $sourceDate, (int) $admin['id']]
    );
    $created[] = $sourceId;
    $db->run('INSERT INTO event_tasks (event_id, title) VALUES (?, ?)', [$sourceId, $marker . ' source task']);

    $endpoint = new Events($db, $auth, ['cloneEventId' => $sourceId], $root);
    $request = new Request('POST', "/api/events/$sourceId/clone", [], [
        'title' => $marker . ' Saturday',
        'date' => $cloneDate,
        'load_in_time' => '11:00',
        'doors_time' => '12:00',
        'show_time' => '13:00',
        'end_time' => '16:00',
    ], [], []);
    $response = $endpoint->handle($request);
    $body = responseValue($response, 'body');
    ok(responseValue($response, 'status') === 200, 'clone endpoint returns 200');
    $cloneId = (int) ($body['id'] ?? 0);
    ok($cloneId > 0 && (int) ($body['source_event_id'] ?? 0) === $sourceId, 'response identifies clone and source');
    if ($cloneId > 0) $created[] = $cloneId;

    $clone = $db->one('SELECT * FROM events WHERE id = ?', [$cloneId]);
    ok(($clone['status'] ?? null) === 'proposed', 'clone always starts as a Hold');
    ok(($clone['date'] ?? null) === $cloneDate && ($clone['show_time'] ?? null) === '13:00:00' && ($clone['load_out_time'] ?? null) === '23:00:00', 'date/times use editable overrides and preserve load-out');
    ok(($clone['description_public'] ?? null) === 'Public copy' && ($clone['contract_details'] ?? null) === 'Guarantee $500; house sound included.' && ($clone['promoter_name'] ?? null) === 'Contract Person', 'reusable details, contract terms, and contacts are copied');
    ok((int) ($clone['public_visibility'] ?? 1) === 0, 'clone is not published automatically');
    ok(empty($clone['series_id']) && empty($clone['contract_url']) && empty($clone['ticket_url']) && empty($clone['deposit_amount']), 'series, contract, ticket URL, and deposit are reset');
    $taskCount = $db->one('SELECT COUNT(*) n FROM event_tasks WHERE event_id = ?', [$cloneId]);
    ok((int) ($taskCount['n'] ?? -1) === 0, 'operational child records are not copied');
} finally {
    if ($created) {
        $placeholders = implode(',', array_fill(0, count($created), '?'));
        $db->run("DELETE FROM events WHERE id IN ($placeholders)", $created);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
