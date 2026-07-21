<?php
/**
 * Tests for the Phase 3 CenterStage-specific runtime handlers
 * (src/Processes/CenterStage/BookingHandlers.php, ProcessBridge.php) — the
 * layer that makes op.* nodes actually touch real `events`/`contracts`/
 * `event_tasks` rows instead of the generic Phase 2 simulated handler.
 *
 * Builds a throwaway venue + two events (one to create a real date
 * conflict) + one task-checklist template, drives real process instances
 * through a small linear graph via the real HandlerRegistry, and asserts
 * the real side effects landed — then cleans everything up.
 *
 * REQUIRES A REAL MYSQL DATABASE with migration 067 applied — same
 * convention as process_runtime_test.php. Excluded from the default
 * hermetic run; opt in with RUN_DB_TESTS=1 against a throwaway/dev database.
 *
 * Run with: php tests/process_centerstage_handlers_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Processes\CenterStage\BookingHandlers;
use Panic\Processes\CenterStage\ProcessBridge;
use Panic\Processes\Runtime\Engine;
use function Panic\slugify;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

try {
    $db = new Database();
    $db->one('SELECT 1 FROM process_executions LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not use process_executions: {$e->getMessage()}\n");
    fwrite(STDERR, "process_centerstage_handlers_test.php needs a real MySQL DB with migration 067 applied.\n");
    exit(1);
}

echo "\n=== Phase 3 CenterStage handler tests ===\n\n";

$suffix = bin2hex(random_bytes(4));

// ── Throwaway real venue, events, and a task-checklist template ─────────────
$venueId = $db->insert('INSERT INTO venues (name, slug) VALUES (?, ?)', ["PB Test Venue $suffix", slugify("pb-test-venue-$suffix")]);
$date = date('Y-m-d', strtotime('+90 days'));

$existingEventId = $db->insert(
    "INSERT INTO events (venue_id, title, slug, event_type, status, date) VALUES (?, ?, ?, 'private_event', 'confirmed', ?)",
    [$venueId, "Existing Booking $suffix", slugify("existing-booking-$suffix"), $date]
);
$conflictedEventId = $db->insert(
    "INSERT INTO events (venue_id, title, slug, event_type, status, date, client_org, booker_name, booker_email) VALUES (?, ?, ?, 'private_event', 'proposed', ?, ?, ?, ?)",
    [$venueId, "New Inquiry Conflicted $suffix", slugify("new-inquiry-conflicted-$suffix"), $date, 'Acme Test Co', 'Jamie Booker', "jamie-$suffix@example.test"]
);
$openEventId = $db->insert(
    "INSERT INTO events (venue_id, title, slug, event_type, status, date, client_org, booker_name, booker_email) VALUES (?, ?, ?, 'private_event', 'proposed', ?, ?, ?, ?)",
    [$venueId, "New Inquiry Open Date $suffix", slugify("new-inquiry-open-$suffix"), date('Y-m-d', strtotime('+91 days')), 'Acme Test Co', 'Jamie Booker', "jamie2-$suffix@example.test"]
);
$templateId = $db->insert(
    'INSERT INTO event_templates (venue_id, name, event_type, checklist_json) VALUES (?, ?, ?, ?)',
    [$venueId, "PB Test Checklist $suffix", 'private_event', json_encode([['title' => 'Confirm AV needs', 'priority' => 'high'], ['title' => 'Print signage']])]
);

// ── A small linear graph exercising every real handler in one pass ──────────
$node = static fn(string $id, string $type, string $name, array $config = []): array => [
    'id' => $id, 'type' => $type, 'name' => $name, 'description' => '',
    'position' => ['x' => 0, 'y' => 0], 'config' => $config, 'runtimePolicy' => [], 'ui' => [],
];
$edge = static fn(string $id, string $from, string $to): array => [
    'id' => $id, 'source' => ['nodeId' => $from, 'port' => 'out'], 'target' => ['nodeId' => $to, 'port' => 'in'],
    'type' => 'normal', 'outcome' => null, 'isDefault' => false, 'label' => '', 'priority' => 0,
];
$nodes = [
    $node('start', 'trigger.centerstage_event', 'Start'),
    $node('check', 'op.run_script', 'Check Availability', ['operation' => 'venue.check_availability']),
    $node('quote', 'op.generate_document', 'Prepare Quote', ['operation' => 'contracts.generate_quote']),
    $node('book', 'op.update_event_status', 'Mark Booked', ['operation' => 'events.set_status', 'fieldMappings' => '{"status":"booked"}']),
    $node('tasks', 'op.add_event_task', 'Apply Checklist', ['operation' => 'events.apply_task_template', 'templateId' => $templateId]),
    $node('proposal', 'op.send_email', 'Send Proposal', ['operation' => 'email.send_proposal', 'subject' => 'Your booking quote']),
    $node('wait', 'flow.wait', 'Await Signature', ['awaitedEvent' => 'contract.signed']),
    $node('done', 'flow.end', 'Done'),
];
$edges = [
    $edge('e1', 'start', 'check'), $edge('e2', 'check', 'quote'), $edge('e3', 'quote', 'book'),
    $edge('e4', 'book', 'tasks'), $edge('e5', 'tasks', 'proposal'), $edge('e6', 'proposal', 'wait'),
];
$edges[] = $edge('e7', 'wait', 'done');
$graphWithWait = ['schemaVersion' => 1, 'meta' => ['name' => 'CS Test'], 'nodes' => $nodes, 'edges' => $edges, 'viewport' => [], 'variables' => [], 'permissions' => [], 'runtimePolicy' => []];

$defId = $db->insert('INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)', [slugify("cs-handlers-test-$suffix"), "CS Handlers Test $suffix"]);
$verId = $db->insert(
    "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, published_at) VALUES (?, 1, 'published', ?, NOW())",
    [$defId, json_encode($graphWithWait, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
);
$db->run('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?', [$verId, $defId]);
$definition = $db->one('SELECT * FROM process_definitions WHERE id = ?', [$defId]);
$version = $db->one('SELECT * FROM process_versions WHERE id = ?', [$verId]);

$engine = new Engine($db, BookingHandlers::registry());

// ── 1. Conflicted event: check_availability finds the real conflict ─────────
$resultConflicted = $engine->startInstance($definition, $version, [
    'name' => 'Conflicted Case', 'entity_type' => 'event', 'entity_id' => $conflictedEventId,
]);
$varsConflicted = json_decode((string) $resultConflicted['instance']['variables_json'], true) ?? [];
ok(($varsConflicted['date_available'] ?? null) === 'no', 'venue.check_availability found the real same-venue/same-date conflict and set date_available=no');
ok($resultConflicted['instance']['current_node_id'] === 'wait', 'Even on a conflict, the linear test graph (no branching) still ran every handler through to the wait');

// ── 2. Open-date event: real handlers run end to end ─────────────────────────
$result = $engine->startInstance($definition, $version, [
    'name' => 'Open Date Case', 'entity_type' => 'event', 'entity_id' => $openEventId,
]);
$vars = json_decode((string) $result['instance']['variables_json'], true) ?? [];
ok(($vars['date_available'] ?? null) === 'yes', 'venue.check_availability found no conflict for the open-date event');

$eventAfter = $db->one('SELECT status FROM events WHERE id = ?', [$openEventId]);
ok($eventAfter['status'] === 'booked', 'events.set_status really flipped the real event from proposed to booked');

$contractId = $vars['contract_id'] ?? null;
$contractRow = $contractId ? $db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]) : null;
ok($contractRow !== null && (int) $contractRow['event_id'] === $openEventId, 'contracts.generate_quote created a real contracts row linked to the real event');

$taskCount = $db->one('SELECT COUNT(*) AS n FROM event_tasks WHERE event_id = ?', [$openEventId]);
ok((int) $taskCount['n'] === 2, 'events.apply_task_template created real event_tasks rows from the real checklist template');

$proposalExec = $db->one("SELECT * FROM process_executions WHERE process_instance_id = ? AND node_id = 'proposal'", [$result['instance']['id']]);
$proposalOutput = $proposalExec ? json_decode((string) $proposalExec['output_json'], true) : null;
ok($proposalExec !== null && (int) $proposalExec['simulated'] === 0, 'email.send_proposal ran as a REAL (non-simulated) handler, not the generic fallback');
ok(isset($proposalOutput['gmail_compose_url']) && str_contains($proposalOutput['gmail_compose_url'], 'mail.google.com')
   && str_contains($proposalOutput['gmail_compose_url'], rawurlencode("jamie2-$suffix@example.test")),
   'email.send_proposal built a real Gmail compose link addressed to the real booker_email — and sent nothing itself');

ok($result['instance']['status'] === 'waiting' && $result['instance']['current_node_id'] === 'wait', 'Instance correctly stopped at the real Await Signature wait node');

// ── 3. ProcessBridge resumes the wait when the (simulated) contract gets signed ──
ProcessBridge::onContractSigned($db, $openEventId, (int) $contractId);
$instanceAfterSign = $db->one('SELECT * FROM process_instances WHERE id = ?', [$result['instance']['id']]);
ok($instanceAfterSign['status'] === 'completed' && $instanceAfterSign['current_node_id'] === 'done',
   'ProcessBridge::onContractSigned found and resumed the real waiting instance, which then ran to completion');

// A second call (as if the webhook fired twice) must be a harmless no-op — no duplicate advancement/executions.
$execCountBefore = $db->one('SELECT COUNT(*) AS n FROM process_executions WHERE process_instance_id = ?', [$result['instance']['id']]);
ProcessBridge::onContractSigned($db, $openEventId, (int) $contractId);
$execCountAfter = $db->one('SELECT COUNT(*) AS n FROM process_executions WHERE process_instance_id = ?', [$result['instance']['id']]);
ok((int) $execCountBefore['n'] === (int) $execCountAfter['n'], 'A duplicate contract-signed notification does not re-run anything (idempotent wait resume)');

// ── Cleanup ──────────────────────────────────────────────────────────────────
$db->run('DELETE FROM process_definitions WHERE id = ?', [$defId]);
$db->run('DELETE FROM contracts WHERE event_id IN (?, ?, ?)', [$existingEventId, $conflictedEventId, $openEventId]);
$db->run('DELETE FROM events WHERE id IN (?, ?, ?)', [$existingEventId, $conflictedEventId, $openEventId]);
$db->run('DELETE FROM event_templates WHERE id = ?', [$templateId]);
$db->run('DELETE FROM venues WHERE id = ?', [$venueId]);
$remaining = $db->one('SELECT COUNT(*) AS n FROM events WHERE venue_id = ?', [$venueId]);
ok((int) $remaining['n'] === 0, 'Cleanup: no throwaway events/contracts/tasks/venue/template left behind');

echo "\n";
if ($failed === 0) {
    echo "All $passed assertion(s) passed.\n";
    exit(0);
}
echo "$failed/" . ($passed + $failed) . " assertions failed.\n";
exit(1);
