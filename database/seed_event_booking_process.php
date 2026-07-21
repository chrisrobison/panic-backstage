<?php
declare(strict_types=1);

/**
 * Seeds the "Event Booking" sample process — the first real process
 * definition for the Automation > Processes graph designer/engine (see
 * database/migrations/066_add_process_automation.sql, src/Processes.php).
 *
 * This ships a complete, publishable graph (trigger → classify → check
 * availability → branch → quote/approval/proposal/signature/deposit →
 * event creation → production tasks → settlement, with a "date unavailable"
 * branch that offers alternatives and loops back or closes the inquiry) plus
 * four demonstration instances at different points in the flow — exactly
 * the acceptance-criteria examples: an approval waiting on a person, a case
 * still in progress, an overdue payment, and a case waiting on the customer.
 *
 * Every process_instances row this creates has is_demo=1 — see Processes/
 * Instances.php's doc comment: these are real rows, just not produced by a
 * live execution runtime (that's Phase 2). No side effects (no emails, no
 * real bookings) were performed to create them.
 *
 * Idempotent: skips entirely if a definition with key_slug 'event-booking'
 * already exists.
 *
 *   php database/seed_event_booking_process.php
 */

namespace Panic;

function seed_event_booking_process(\PDO $pdo): void
{
    $existing = $pdo->prepare('SELECT id FROM process_definitions WHERE key_slug = ?');
    $existing->execute(['event-booking']);
    if ($existing->fetch()) {
        echo "Event Booking process already seeded — skipping.\n";
        return;
    }

    // ── Graph document ───────────────────────────────────────────────────
    $node = static function (string $id, string $type, string $name, float $x, float $y, array $config = []): array {
        return [
            'id' => $id, 'type' => $type, 'name' => $name, 'description' => '',
            'position' => ['x' => $x, 'y' => $y], 'config' => $config, 'runtimePolicy' => [], 'ui' => [],
        ];
    };
    $edge = static function (string $id, string $sourceId, string $targetId, string $sourcePort = 'out', array $extra = []): array {
        return [
            'id' => $id,
            'source' => ['nodeId' => $sourceId, 'port' => $sourcePort],
            'target' => ['nodeId' => $targetId, 'port' => 'in'],
            'type' => $extra['type'] ?? 'normal',
            'outcome' => $extra['outcome'] ?? null,
            'isDefault' => $extra['isDefault'] ?? false,
            'label' => $extra['label'] ?? '',
            'priority' => 0,
        ];
    };

    $nodes = [
        $node('inquiry_received', 'trigger.centerstage_event', 'Inquiry Received', 40, 160),
        $node('classify_inquiry', 'ai.classify_text', 'Classify Inquiry', 300, 160),
        $node('check_availability', 'op.run_script', 'Check Availability', 560, 160, ['operation' => 'venue.check_availability']),
        $node('date_available', 'flow.decision', 'Date Available?', 820, 160, [
            'branches' => [
                ['id' => 'yes', 'label' => 'Yes', 'condition' => 'Requested date/room is open'],
                ['id' => 'no', 'label' => 'No', 'isDefault' => true, 'condition' => 'Requested date/room is unavailable'],
            ],
        ]),

        // Yes branch — happy path through to settlement.
        $node('prepare_quote', 'op.generate_document', 'Prepare Quote', 1080, 40, ['operation' => 'contracts.generate_quote']),
        $node('manager_approval', 'human.approval', 'Manager Approval', 1340, 40, [
            'assigneeRole' => 'Venue Manager', 'dueRule' => '24 hours', 'escalationRule' => 'After 12 hours, notify Owner',
            'outcomes' => [
                ['id' => 'approve', 'label' => 'Approve'],
                ['id' => 'revise', 'label' => 'Revise'],
                ['id' => 'reject', 'label' => 'Reject', 'isDefault' => true],
            ],
        ]),
        $node('send_proposal', 'op.send_email', 'Send Proposal', 1600, 40, ['operation' => 'email.send_proposal']),
        $node('await_signature', 'flow.wait', 'Await Signature', 1860, 40, ['awaitedEvent' => 'contract.signed', 'duration' => '7 days', 'reminderRule' => 'Remind after 3 days']),
        $node('collect_deposit', 'op.request_deposit', 'Collect Deposit', 2120, 40, ['operation' => 'payments.request_deposit']),
        $node('create_event', 'op.update_event_status', 'Create Event', 2380, 40, ['operation' => 'events.set_status', 'fieldMappings' => '{"status":"booked"}']),
        $node('create_tasks', 'op.add_event_task', 'Create Production Tasks', 2640, 40, ['operation' => 'events.apply_task_template']),
        $node('event_complete', 'op.update_event_status', 'Event Complete', 2900, 40, ['operation' => 'events.set_status', 'fieldMappings' => '{"status":"completed"}']),
        $node('settlement', 'op.create_update_record', 'Settlement', 3160, 40, ['operation' => 'events.run_settlement']),
        $node('booking_complete_end', 'flow.end', 'Booking Complete', 3420, 40),

        // Manager Approval side paths.
        $node('declined_end', 'flow.failure_end', 'Inquiry Declined', 1340, 260),

        // Await Signature timeout path.
        $node('signature_followup', 'human.contact_customer', 'Follow Up — Unsigned Proposal', 1860, 260, ['assigneeRole' => 'Sales Coordinator']),
        $node('signature_expired_end', 'flow.end', 'Inquiry Closed (Unsigned)', 2120, 260),

        // No branch — date unavailable.
        $node('suggest_alternatives', 'op.send_email', 'Suggest Alternative Dates', 1080, 420, ['operation' => 'email.send_alternatives']),
        $node('await_customer_response', 'flow.wait', 'Await Customer Response', 1340, 420, ['awaitedEvent' => 'customer.replied', 'duration' => '5 days', 'reminderRule' => 'Remind after 2 days']),
        $node('alt_accepted', 'flow.decision', 'Alternative Accepted?', 1600, 420, [
            'branches' => [
                ['id' => 'yes', 'label' => 'Yes'],
                ['id' => 'no', 'label' => 'No', 'isDefault' => true],
            ],
        ]),
        $node('inquiry_closed', 'flow.end', 'Inquiry Closed', 1860, 480),
        $node('inquiry_closed_no_response', 'flow.end', 'Inquiry Closed (No Response)', 1600, 560),
    ];

    $edges = [
        $edge('e1', 'inquiry_received', 'classify_inquiry'),
        $edge('e2', 'classify_inquiry', 'check_availability'),
        $edge('e3', 'check_availability', 'date_available'),
        $edge('e4', 'date_available', 'prepare_quote', 'yes', ['label' => 'Yes', 'type' => 'conditional', 'outcome' => 'yes']),
        $edge('e5', 'date_available', 'suggest_alternatives', 'no', ['label' => 'No', 'type' => 'conditional', 'outcome' => 'no', 'isDefault' => true]),

        $edge('e6', 'prepare_quote', 'manager_approval'),
        $edge('e7', 'manager_approval', 'send_proposal', 'approve', ['label' => 'Approve', 'type' => 'conditional', 'outcome' => 'approve']),
        $edge('e8', 'manager_approval', 'prepare_quote', 'revise', ['label' => 'Revise', 'type' => 'conditional', 'outcome' => 'revise']),
        $edge('e9', 'manager_approval', 'declined_end', 'reject', ['label' => 'Reject', 'type' => 'conditional', 'outcome' => 'reject', 'isDefault' => true]),

        $edge('e10', 'send_proposal', 'await_signature'),
        $edge('e11', 'await_signature', 'collect_deposit', 'resumed', ['label' => 'Signed', 'type' => 'normal']),
        $edge('e12', 'await_signature', 'signature_followup', 'timeout', ['label' => 'Timeout', 'type' => 'timeout']),
        $edge('e13', 'signature_followup', 'signature_expired_end'),

        $edge('e14', 'collect_deposit', 'create_event'),
        $edge('e15', 'create_event', 'create_tasks'),
        $edge('e16', 'create_tasks', 'event_complete'),
        $edge('e17', 'event_complete', 'settlement'),
        $edge('e18', 'settlement', 'booking_complete_end'),

        $edge('e19', 'suggest_alternatives', 'await_customer_response'),
        $edge('e20', 'await_customer_response', 'alt_accepted', 'resumed', ['label' => 'Replied', 'type' => 'normal']),
        $edge('e21', 'await_customer_response', 'inquiry_closed_no_response', 'timeout', ['label' => 'Timeout', 'type' => 'timeout']),
        $edge('e22', 'alt_accepted', 'check_availability', 'yes', ['label' => 'Yes', 'type' => 'conditional', 'outcome' => 'yes']),
        $edge('e23', 'alt_accepted', 'inquiry_closed', 'no', ['label' => 'No', 'type' => 'conditional', 'outcome' => 'no', 'isDefault' => true]),
    ];

    $graph = [
        'schemaVersion' => 1,
        'meta' => ['name' => 'Event Booking', 'description' => 'Inquiry through settlement — the primary CenterStage booking workflow.'],
        'nodes' => $nodes,
        'edges' => $edges,
        'viewport' => ['x' => 40, 'y' => 40, 'zoom' => 0.55],
        'variables' => [
            ['key' => 'inquiry_id', 'label' => 'Booking inquiry'],
            ['key' => 'event_id', 'label' => 'Linked event'],
        ],
        'permissions' => [],
        'runtimePolicy' => [],
    ];

    // ── Definition + published v1 ────────────────────────────────────────
    $adminId = $pdo->query("SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1")->fetchColumn();
    $adminId = $adminId !== false ? (int) $adminId : null;

    $stmt = $pdo->prepare('INSERT INTO process_definitions (key_slug, name, description, category, created_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['event-booking', 'Event Booking', 'Inquiry through settlement for a new venue booking.', 'booking', $adminId]);
    $definitionId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, note, published_at, published_by, created_by)
         VALUES (?, 1, 'published', ?, 'Initial published version', NOW(), ?, ?)"
    );
    $stmt->execute([$definitionId, json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $adminId, $adminId]);
    $versionId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?')->execute([$versionId, $definitionId]);

    $audit = $pdo->prepare('INSERT INTO process_audit_log (process_definition_id, process_version_id, actor_user_id, action, after_json, note) VALUES (?, ?, ?, ?, ?, ?)');
    $audit->execute([$definitionId, $versionId, $adminId, 'definition_created', json_encode(['name' => 'Event Booking']), null]);
    $audit->execute([$definitionId, $versionId, $adminId, 'published', json_encode(['version_number' => 1]), 'Seeded as the first published version.']);

    // ── Demonstration instances ──────────────────────────────────────────
    $insertInstance = $pdo->prepare(
        "INSERT INTO process_instances (process_definition_id, process_version_id, name, status, current_node_id, entity_type, owner_user_id, is_demo, variables_json, started_at, updated_at, due_at)
         VALUES (?, ?, ?, ?, ?, 'inquiry', ?, 1, ?, ?, ?, ?)"
    );
    $insertEvent = $pdo->prepare(
        "INSERT INTO process_instance_events (process_instance_id, node_id, event_type, label, detail, actor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $now = new \DateTimeImmutable('now');
    $seedInstance = function (
        string $name,
        string $status,
        string $currentNode,
        array $path,
        \DateTimeImmutable $startedAt,
        array $variables,
        ?\DateTimeImmutable $dueAt = null
    ) use ($pdo, $insertInstance, $insertEvent, $definitionId, $versionId, $adminId): void {
        $insertInstance->execute([
            $definitionId, $versionId, $name, $status, $currentNode, $adminId,
            json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $startedAt->format('Y-m-d H:i:s'), $startedAt->format('Y-m-d H:i:s'), $dueAt?->format('Y-m-d H:i:s'),
        ]);
        $instanceId = (int) $pdo->lastInsertId();
        $t = $startedAt;
        foreach ($path as $i => [$nodeId, $label]) {
            $insertEvent->execute([$instanceId, $nodeId, $i === count($path) - 1 ? 'waiting' : 'completed', $label, null, 'system', $t->format('Y-m-d H:i:s')]);
            $t = $t->modify('+20 minutes');
        }
    };

    $seedInstance(
        'Acme Holiday Party', 'waiting', 'manager_approval',
        [
            ['inquiry_received', 'Inquiry received'],
            ['classify_inquiry', 'Classified as private_event'],
            ['check_availability', 'Date confirmed available'],
            ['date_available', 'Date Available? → Yes'],
            ['prepare_quote', 'Quote prepared'],
            ['manager_approval', 'Waiting for Manager Approval'],
        ],
        $now->modify('-2 hours -15 minutes'),
        ['client_org' => 'Acme Inc.', 'requested_date' => $now->modify('+45 days')->format('Y-m-d')],
        $now->modify('+22 hours')
    );

    $seedInstance(
        'Northstar Product Launch', 'active', 'prepare_quote',
        [
            ['inquiry_received', 'Inquiry received'],
            ['classify_inquiry', 'Classified as corporate_event'],
            ['check_availability', 'Date confirmed available'],
            ['date_available', 'Date Available? → Yes'],
            ['prepare_quote', 'Preparing quote'],
        ],
        $now->modify('-1 hours -2 minutes'),
        ['client_org' => 'Northstar Co.', 'requested_date' => $now->modify('+60 days')->format('Y-m-d')]
    );

    $seedInstance(
        'Maya & Luis Wedding', 'overdue', 'collect_deposit',
        [
            ['inquiry_received', 'Inquiry received'],
            ['classify_inquiry', 'Classified as private_event'],
            ['check_availability', 'Date confirmed available'],
            ['date_available', 'Date Available? → Yes'],
            ['prepare_quote', 'Quote prepared'],
            ['manager_approval', 'Approved'],
            ['send_proposal', 'Proposal sent'],
            ['await_signature', 'Contract signed'],
            ['collect_deposit', 'Deposit requested — overdue'],
        ],
        $now->modify('-25 hours -47 minutes'),
        ['client_org' => 'Maya & Luis', 'deposit_amount' => 750],
        $now->modify('-1 hours -47 minutes')
    );

    $seedInstance(
        'Touring Band Inquiry', 'waiting', 'await_customer_response',
        [
            ['inquiry_received', 'Inquiry received'],
            ['classify_inquiry', 'Classified as live_music'],
            ['check_availability', 'Requested date unavailable'],
            ['date_available', 'Date Available? → No'],
            ['suggest_alternatives', 'Alternative dates sent'],
            ['await_customer_response', 'Waiting on customer reply'],
        ],
        $now->modify('-3 days'),
        ['client_org' => 'The Wandering Tour', 'requested_date' => $now->modify('+30 days')->format('Y-m-d')],
        $now->modify('+2 days')
    );

    echo "Event Booking process seeded (definition #$definitionId, version #$versionId, 4 demo instances).\n";
}

// Allow running standalone: php database/seed_event_booking_process.php
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__);
    require $root . '/src/bootstrap.php';
    Env::load($root . '/.env');
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $dbName = getenv('DB_NAME') ?: 'panic_backstage';
    $pdo = new \PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $user, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    seed_event_booking_process($pdo);
}
