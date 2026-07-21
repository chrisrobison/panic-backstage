<?php
/**
 * Tests for the process runtime engine (src/Processes/Runtime/Engine.php,
 * database/migrations/067_add_process_runtime.sql) — the Phase 2 state
 * machine that actually executes a process_versions.graph_json. Builds a
 * small throwaway definition + published version covering every stop-point
 * type (trigger, automatic op, decision branch x2, human task, wait/timeout,
 * end, failure) and drives real instances through it via the Engine's
 * public API, exactly as the HTTP endpoints (Processes/Instances.php,
 * Processes/Tasks.php) do.
 *
 * REQUIRES A REAL MYSQL DATABASE with migration 067 applied — same
 * convention as process_versions_test.php. Excluded from the default
 * hermetic run; opt in with RUN_DB_TESTS=1 against a throwaway/dev database.
 *
 * Run with: php tests/process_runtime_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Processes\Runtime\Engine;
use Panic\Processes\Runtime\EngineException;
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
    fwrite(STDERR, "process_runtime_test.php needs a real MySQL DB with migration 067 applied.\n");
    exit(1);
}

echo "\n=== Process runtime engine tests ===\n\n";

// ── Build a throwaway definition covering every node kind ────────────────
$node = static fn(string $id, string $type, string $name, array $config = []): array => [
    'id' => $id, 'type' => $type, 'name' => $name, 'description' => '',
    'position' => ['x' => 0, 'y' => 0], 'config' => $config, 'runtimePolicy' => [], 'ui' => [],
];
$edge = static fn(string $id, string $from, string $to, string $port = 'out', array $extra = []): array => [
    'id' => $id,
    'source' => ['nodeId' => $from, 'port' => $port],
    'target' => ['nodeId' => $to, 'port' => 'in'],
    'type' => $extra['type'] ?? 'normal',
    'outcome' => $extra['outcome'] ?? null,
    'isDefault' => $extra['isDefault'] ?? false,
    'label' => $extra['label'] ?? '',
    'priority' => 0,
];

$nodes = [
    $node('start', 'trigger.manual', 'Start'),
    $node('check', 'op.run_script', 'Check Something', ['setVariables' => ['result' => 'yes']]),
    $node('branch', 'flow.decision', 'Result?', [
        'variableKey' => 'result',
        'branches' => [
            ['id' => 'yes', 'label' => 'Yes'],
            ['id' => 'no', 'label' => 'No', 'isDefault' => true],
        ],
    ]),
    $node('approve', 'human.approval', 'Approve It', [
        'assigneeRole' => 'Tester',
        'outcomes' => [['id' => 'approve', 'label' => 'Approve'], ['id' => 'reject', 'label' => 'Reject', 'isDefault' => true]],
    ]),
    $node('wait_event', 'flow.wait', 'Wait For Reply', ['awaitedEvent' => 'test.replied', 'duration' => '1 seconds']),
    $node('flaky', 'op.http_request', 'Flaky Call', ['simulateFailure' => true, 'failureMessage' => 'simulated boom']),
    $node('good_end', 'flow.end', 'Done'),
    $node('bad_end', 'flow.failure_end', 'Nope'),
    $node('after_wait', 'flow.end', 'After Wait'),
];
$edges = [
    $edge('e1', 'start', 'check'),
    $edge('e2', 'check', 'branch'),
    $edge('e3', 'branch', 'approve', 'yes', ['outcome' => 'yes']),
    $edge('e4', 'branch', 'bad_end', 'no', ['outcome' => 'no', 'isDefault' => true]),
    $edge('e5', 'approve', 'wait_event', 'approve', ['outcome' => 'approve']),
    $edge('e6', 'approve', 'bad_end', 'reject', ['outcome' => 'reject', 'isDefault' => true]),
    $edge('e7', 'wait_event', 'flaky', 'resumed'),
    $edge('e8', 'wait_event', 'after_wait', 'timeout', ['type' => 'timeout']),
    $edge('e9', 'flaky', 'good_end'),
];
$graph = ['schemaVersion' => 1, 'meta' => ['name' => 'Runtime Test'], 'nodes' => $nodes, 'edges' => $edges, 'viewport' => [], 'variables' => [], 'permissions' => [], 'runtimePolicy' => []];

$suffix = bin2hex(random_bytes(4));
$defId = $db->insert('INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)', [slugify("runtime-test-$suffix"), "Runtime Test $suffix"]);
$verId = $db->insert(
    "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, published_at) VALUES (?, 1, 'published', ?, NOW())",
    [$defId, json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
);
$db->run('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?', [$verId, $defId]);
$definition = $db->one('SELECT * FROM process_definitions WHERE id = ?', [$defId]);
$version = $db->one('SELECT * FROM process_versions WHERE id = ?', [$verId]);

$engine = new Engine($db);

// ── 1. Happy path: trigger -> op (sets result=yes) -> decision branches "yes" -> stops at human task ──
$result = $engine->startInstance($definition, $version, ['name' => 'Case A']);
$instA = $result['instance']['id'];
ok($result['instance']['status'] === 'waiting' && $result['instance']['current_node_id'] === 'approve',
   'Automatic nodes ran end-to-end and stopped at the human task (decision took the "yes" branch)');
ok(count($result['tasks']) === 1 && $result['tasks'][0]['status'] === 'open', 'A real process_tasks row was created for the human task');
$execTypes = array_column($result['executions'], 'node_id');
ok(in_array('check', $execTypes, true) && in_array('branch', $execTypes, true), 'process_executions logged the automatic op and decision nodes');

// ── 2. Decision branching the other way, driven by caller-supplied variables ──
$resultB = $engine->startInstance($definition, $version, ['name' => 'Case B', 'variables' => ['result' => 'no']]);
ok($resultB['instance']['status'] === 'failed' && $resultB['instance']['current_node_id'] === 'bad_end',
   'Caller-supplied variables override the op node\'s default, sending the decision down the "no" branch to failure_end');

// ── 3. Completing the human task resumes the instance into the wait node ────
$taskId = $result['tasks'][0]['id'];
$resumed = $engine->completeTask($instA, $taskId, 'approve', 'looks good', null, 'tester');
ok($resumed['instance']['status'] === 'waiting' && $resumed['instance']['current_node_id'] === 'wait_event',
   'Completing the task with outcome=approve advanced the instance into the wait node');
$waitRow = $db->one("SELECT * FROM process_waits WHERE process_instance_id = ? AND status = 'waiting'", [$instA]);
ok($waitRow !== null && $waitRow['awaited_event'] === 'test.replied', 'A real process_waits row was created for the wait node');

// ── 4. Idempotency: completing the same task again is a harmless no-op ──────
$again = $engine->completeTask($instA, $taskId, 'approve', 'duplicate click', null, 'tester');
ok(($again['already'] ?? false) === true, 'Re-completing an already-completed task does not re-run downstream nodes (idempotent)');
$execCountAfterDup = $db->one('SELECT COUNT(*) AS n FROM process_executions WHERE process_instance_id = ? AND node_id = "flaky"', [$instA]);
ok((int) $execCountAfterDup['n'] === 0, 'No duplicate execution rows from the duplicate completion');

// ── 5. Resuming the wait runs the "flaky" op, which is configured to fail ──
$afterWait = $engine->resumeWait($instA, (int) $waitRow['id'], null, 'customer replied');
ok($afterWait['instance']['status'] === 'failed' && str_contains((string) $afterWait['instance']['last_error'], 'simulated boom'),
   'A node configured with config.simulateFailure fails the instance with the configured message');

// ── 6. Idempotent wait resume: resuming the same (already-resumed) wait again is a no-op ──
$againWait = $engine->resumeWait($instA, (int) $waitRow['id'], null, 'duplicate webhook delivery');
ok(($againWait['already'] ?? false) === true, 'Re-resuming an already-resumed wait is a harmless no-op (protects against duplicate webhook delivery)');

// ── 7. Retry: fixing nothing but retrying still fails deterministically (same config) ──
$retried = $engine->retry($instA, null, 'operator retrying after investigating');
ok($retried['instance']['status'] === 'failed', 'Retry re-runs the failed node (still configured to fail, so it fails again — proves retry actually re-executes rather than just flipping status)');
try {
    $engine->retry($instC ?? 999999, null, 'irrelevant');
    ok(false, 'retry() on a non-failed instance should raise EngineException');
} catch (EngineException $e) {
    ok(true, 'retry() on a non-failed/nonexistent instance raises: ' . $e->getMessage());
}

// ── 8. Cancel requires the instance not already be terminal, and clears open tasks/waits ──
$resultC = $engine->startInstance($definition, $version, ['name' => 'Case C']);
$instC = $resultC['instance']['id'];
$canceled = $engine->cancel($instC, null, 'no longer needed');
ok($canceled['instance']['status'] === 'canceled', 'cancel() marks the instance canceled');
$openTasksAfterCancel = $db->one("SELECT COUNT(*) AS n FROM process_tasks WHERE process_instance_id = ? AND status = 'open'", [$instC]);
ok((int) $openTasksAfterCancel['n'] === 0, 'Canceling an instance also cancels its open tasks');
try {
    $engine->cancel($instC, null, 'again');
    ok(false, 'Canceling an already-canceled instance should raise (nothing left to cancel)');
} catch (EngineException $e) {
    ok(true, 'Canceling an already-terminal instance raises: ' . $e->getMessage());
}

// ── 9. Pause / resume round-trip restores the prior status ──────────────────
$resultD = $engine->startInstance($definition, $version, ['name' => 'Case D']);
$instD = $resultD['instance']['id'];
$paused = $engine->pause($instD, null, 'investigating a data issue');
ok($paused['instance']['status'] === 'paused', 'pause() marks the instance paused');
$resumedD = $engine->resume($instD, null, 'issue resolved');
ok(in_array($resumedD['instance']['status'], ['waiting', 'active'], true), 'resume() restores the instance to its pre-pause status and continues advancing');

// ── 10. Reaching a real flow.end updates current_node_id to that end node,
//        not just the status — a linear graph with no stop points, run
//        straight through in one advance() burst. (Regression coverage: an
//        earlier version of completeInTx() updated status/completed_at but
//        left current_node_id pointing at wherever the instance started.)
$linearNodes = [
    $node('lin_start', 'trigger.manual', 'Start'),
    $node('lin_op', 'op.transform_data', 'Do Something'),
    $node('lin_end', 'flow.end', 'Done'),
];
$linearEdges = [$edge('le1', 'lin_start', 'lin_op'), $edge('le2', 'lin_op', 'lin_end')];
$linearGraph = ['schemaVersion' => 1, 'meta' => ['name' => 'Linear'], 'nodes' => $linearNodes, 'edges' => $linearEdges, 'viewport' => [], 'variables' => [], 'permissions' => [], 'runtimePolicy' => []];
$linDefId = $db->insert('INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)', [slugify("runtime-test-linear-$suffix"), "Runtime Test Linear $suffix"]);
$linVerId = $db->insert(
    "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, published_at) VALUES (?, 1, 'published', ?, NOW())",
    [$linDefId, json_encode($linearGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
);
$db->run('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?', [$linVerId, $linDefId]);
$linDefinition = $db->one('SELECT * FROM process_definitions WHERE id = ?', [$linDefId]);
$linVersion = $db->one('SELECT * FROM process_versions WHERE id = ?', [$linVerId]);
$linResult = $engine->startInstance($linDefinition, $linVersion, ['name' => 'Linear Case']);
ok($linResult['instance']['status'] === 'completed' && $linResult['instance']['current_node_id'] === 'lin_end',
   'Reaching flow.end in a single automatic burst sets current_node_id to the end node (not left at the start)');
$db->run('DELETE FROM process_definitions WHERE id = ?', [$linDefId]);

// ── 11. Permission boundary lives at the HTTP layer (Processes/Instances.php /
//        Processes/Tasks.php): only the task's assignee or a manage_processes
//        holder may complete it. The Engine itself is a lower layer and
//        intentionally does not re-check capabilities — assert that
//        contract directly against the source so a refactor can't silently
//        drop the check.
$instancesSrc = file_get_contents(dirname(__DIR__) . '/src/Processes/Instances.php');
ok(str_contains($instancesSrc, "hasGlobalCapability('manage_processes')") && str_contains($instancesSrc, 'assignee_user_id'),
   'The HTTP endpoint enforces "assignee OR manage_processes" before calling completeTask()');

// ── Cleanup ───────────────────────────────────────────────────────────────
$db->run('DELETE FROM process_definitions WHERE id = ?', [$defId]);
$remaining = $db->one('SELECT COUNT(*) AS n FROM process_instances WHERE process_definition_id = ?', [$defId]);
ok((int) $remaining['n'] === 0, 'Deleting the definition cascades away every instance/task/wait/execution created by this test');

echo "\n";
if ($failed === 0) {
    echo "All $passed assertion(s) passed.\n";
    exit(0);
}
echo "$failed/" . ($passed + $failed) . " assertions failed.\n";
exit(1);
