<?php
/**
 * DB-backed onboarding mapping and one-event-per-inquiry constraint.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\Onboarding;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Onboarding (DB-backed) ===\n\n";

$db = new Database();
$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$user = $db->one("SELECT id FROM users WHERE role = 'venue_admin' AND is_hidden = 0 ORDER BY id LIMIT 1");
if ($venue === null || $user === null) {
    fwrite(STDERR, "A venue and venue administrator are required for this test.\n");
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$leadId = $db->insert(
    "INSERT INTO leads
     (status, source, contact_name, contact_email, event_name, event_type, projected_attendance, notes)
     VALUES ('new','other','PB Test',?,?,?,120,?)",
    ["onboarding-{$marker}@example.invalid", "PB Test Event {$marker}", 'private_event', 'Original notes']
);
$eventId = null;

try {
    $lead = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $created = Onboarding::createEventFromLead($db, $lead, [
        'venue_id' => (int) $venue['id'],
        'date' => '2037-01-17',
        'estimated_guests' => 275,
        'notes' => 'Reviewed onboarding notes',
    ], (int) $user['id'], 'onboarded');
    $eventId = (int) $created['event_id'];

    $event = $db->one('SELECT estimated_guests, description_internal, lead_id FROM events WHERE id = ?', [$eventId]);
    ok((int) $event['estimated_guests'] === 275, 'estimated guest override is preserved');
    ok($event['description_internal'] === 'Reviewed onboarding notes', 'notes override is preserved');
    ok((int) $event['lead_id'] === $leadId, 'event remains linked to its inquiry');

    $threw = false;
    try {
        Onboarding::createEventFromLead($db, $lead, ['venue_id' => (int) $venue['id']], (int) $user['id'], 'onboarded');
    } catch (\RuntimeException) {
        $threw = true;
    }
    ok($threw, 'a second onboarding attempt is rejected');
    $count = $db->one('SELECT COUNT(*) n FROM events WHERE lead_id = ?', [$leadId]);
    ok((int) $count['n'] === 1, 'database contains exactly one event for the inquiry');
} finally {
    if ($eventId !== null) {
        $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
    }
    $db->run('DELETE FROM leads WHERE id = ?', [$leadId]);
}

echo "\nOnboarding DB: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
