<?php
/**
 * Tests for Panic\Processes\GraphValidator (src/Processes/GraphValidator.php)
 * — the server-side gate that decides whether a process_versions row is
 * allowed to publish. Pure logic, no DB — mirrors public/assets/processes/
 * validator.js's structural rules (that JS copy is exercised by hand in the
 * browser; this is the one that actually blocks the API).
 *
 * Run with: php tests/process_validator_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Processes\GraphValidator;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

function hasError(array $result, string $needle): bool {
    foreach ($result['errors'] as $e) {
        if (str_contains($e['message'], $needle)) return true;
    }
    return false;
}

function hasWarning(array $result, string $needle): bool {
    foreach ($result['warnings'] as $w) {
        if (str_contains($w['message'], $needle)) return true;
    }
    return false;
}

echo "\n=== GraphValidator tests ===\n\n";

// ── 1. Empty graph → error, no nodes ─────────────────────────────────────────
$result = GraphValidator::validate(['nodes' => [], 'edges' => []]);
ok(count($result['errors']) === 1 && hasError($result, 'no nodes'), 'Empty graph reports "no nodes" error');

// ── 2. Missing trigger ────────────────────────────────────────────────────────
$graph = [
    'nodes' => [
        ['id' => 'a', 'type' => 'op.send_email'],
        ['id' => 'b', 'type' => 'flow.end'],
    ],
    'edges' => [
        ['id' => 'e1', 'source' => ['nodeId' => 'a'], 'target' => ['nodeId' => 'b']],
    ],
];
$result = GraphValidator::validate($graph);
ok(hasError($result, 'no way to start'), 'Missing trigger node blocks publish');
// 'a' has an incoming? no — 'a' has no incoming edge and isn't a trigger, so
// it should also be flagged unreachable.
ok(hasError($result, 'Unreachable'), 'Non-trigger node with no incoming edge is unreachable');

// ── 3. A valid minimal linear graph → no errors ──────────────────────────────
$graph = [
    'nodes' => [
        ['id' => 'start', 'type' => 'trigger.manual'],
        ['id' => 'mid', 'type' => 'op.send_email'],
        ['id' => 'end', 'type' => 'flow.end'],
    ],
    'edges' => [
        ['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'mid']],
        ['id' => 'e2', 'source' => ['nodeId' => 'mid'], 'target' => ['nodeId' => 'end']],
    ],
];
$result = GraphValidator::validate($graph);
ok(empty($result['errors']), 'Valid linear trigger→op→end graph has zero errors (got: ' . json_encode($result['errors']) . ')');

// ── 4. Dangling edge → error ──────────────────────────────────────────────────
$graph = [
    'nodes' => [['id' => 'start', 'type' => 'trigger.manual']],
    'edges' => [['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'ghost']]],
];
$result = GraphValidator::validate($graph);
ok(hasError($result, 'missing target node'), 'Edge pointing at a nonexistent node is an error');

// ── 5. Dead end: non-terminal node with no outgoing edge ─────────────────────
$graph = [
    'nodes' => [
        ['id' => 'start', 'type' => 'trigger.manual'],
        ['id' => 'stuck', 'type' => 'op.send_email'],
    ],
    'edges' => [['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'stuck']]],
];
$result = GraphValidator::validate($graph);
ok(hasError($result, 'Dead end'), 'Non-end node with no outgoing edge is a dead end');

// ── 6. flow.end is allowed to have no outgoing edge ──────────────────────────
$graph = [
    'nodes' => [
        ['id' => 'start', 'type' => 'trigger.manual'],
        ['id' => 'stop', 'type' => 'flow.end'],
    ],
    'edges' => [['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'stop']]],
];
$result = GraphValidator::validate($graph);
ok(!hasError($result, 'Dead end'), 'flow.end node is not flagged as a dead end');

// ── 7. Decision with no default branch → error ───────────────────────────────
$graph = [
    'nodes' => [
        ['id' => 'start', 'type' => 'trigger.manual'],
        ['id' => 'q', 'type' => 'flow.decision'],
        ['id' => 'yes', 'type' => 'flow.end'],
    ],
    'edges' => [
        ['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'q']],
        ['id' => 'e2', 'source' => ['nodeId' => 'q'], 'target' => ['nodeId' => 'yes'], 'outcome' => 'yes'],
    ],
];
$result = GraphValidator::validate($graph);
ok(hasError($result, 'no default branch'), 'Decision node with only conditional branches has no default');

// ── 8. Decision WITH a default branch → no default-branch error ─────────────
$graph['edges'][] = ['id' => 'e3', 'source' => ['nodeId' => 'q'], 'target' => ['nodeId' => 'yes'], 'isDefault' => true];
$result = GraphValidator::validate($graph);
ok(!hasError($result, 'no default branch'), 'Decision node with an isDefault edge passes');

// ── 9. Human task with no assignee/role → warning, not error ────────────────
$graph = [
    'nodes' => [
        ['id' => 'start', 'type' => 'trigger.manual'],
        ['id' => 'task', 'type' => 'human.approval', 'config' => []],
        ['id' => 'end', 'type' => 'flow.end'],
    ],
    'edges' => [
        ['id' => 'e1', 'source' => ['nodeId' => 'start'], 'target' => ['nodeId' => 'task']],
        ['id' => 'e2', 'source' => ['nodeId' => 'task'], 'target' => ['nodeId' => 'end']],
    ],
];
$result = GraphValidator::validate($graph);
ok(empty($result['errors']), 'Unassigned human task is not a publish-blocking error');
ok(hasWarning($result, 'No assignee'), 'Unassigned human task produces a warning');

echo "\n";
if ($failed === 0) {
    echo "All $passed assertion(s) passed.\n";
    exit(0);
}
echo "$failed/" . ($passed + $failed) . " assertions failed.\n";
exit(1);
