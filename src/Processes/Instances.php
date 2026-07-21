<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Processes\Runtime\Engine;
use Panic\Processes\Runtime\EngineException;
use Panic\Request;
use Panic\Response;

/**
 * Process instances — the "Live Cases" tab's data, plus (Phase 2) the
 * execution surface that actually moves a case. GET is the read model
 * described below; POST is real: it starts instances and drives them
 * through Runtime/Engine.php. Every row returned here is real — the only
 * ones NOT produced by a live runtime are the four is_demo=1 seeded
 * examples (see database/migrations/066_add_process_automation.sql /
 * database/seed_event_booking_process.php), which the engine never touches.
 *
 *   GET  /api/processes/{id}/instances                          list + per-node counts (view_processes)
 *   GET  /api/processes/{id}/instances/{iid}                    one case + timeline     (view_processes)
 *   POST /api/processes/{id}/instances                          start a new instance    (manage_processes)
 *   POST /api/processes/{id}/instances/{iid}/tasks/{tid}/complete  complete a human task (assignee OR manage_processes)
 *   POST /api/processes/{id}/instances/{iid}/waits/{wid}/resume    resume a wait           (manage_processes)
 *   POST /api/processes/{id}/instances/{iid}/retry                                         (manage_processes, note required)
 *   POST /api/processes/{id}/instances/{iid}/cancel                                         (manage_processes, note required)
 *   POST /api/processes/{id}/instances/{iid}/pause                                          (manage_processes, note required)
 *   POST /api/processes/{id}/instances/{iid}/resume                                         (manage_processes, note required)
 *   POST /api/processes/{id}/instances/{iid}/move                                           (manage_processes, note required)
 *
 * A note is required (422 if blank) for every manual operator action, per
 * the spec's audit/safety requirements — the Engine just stores whatever
 * note it's handed onto process_audit_log / process_instance_events.
 */
final class Instances extends BaseEndpoint
{
    private const NOTE_REQUIRED = ['retry', 'cancel', 'pause', 'resume', 'move'];

    public function handle(Request $request): Response
    {
        $processId = (int) ($this->params['processId'] ?? 0);
        $definition = $this->db->one('SELECT * FROM process_definitions WHERE id = ?', [$processId]);
        if (!$definition) {
            return $this->notFound('Process not found');
        }

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_processes')) {
                return $denied;
            }
            $instanceId = $this->params['instanceId'] ?? null;
            return $instanceId ? $this->show($processId, (int) $instanceId) : $this->index($request, $processId);
        }

        if ($request->method() === 'POST') {
            return $this->dispatchAction($request, $definition);
        }

        return Response::methodNotAllowed();
    }

    private function dispatchAction(Request $request, array $definition): Response
    {
        $processId = (int) $definition['id'];
        $instanceId = $this->params['instanceId'] ?? null;
        $action = (string) ($this->params['action'] ?? '');

        if (!$instanceId) {
            if ($denied = $this->requireGlobalCapability('manage_processes')) {
                return $denied;
            }
            return $this->startInstance($request, $definition);
        }
        $instanceId = (int) $instanceId;

        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ? AND process_definition_id = ?', [$instanceId, $processId]);
        if (!$instance) {
            return $this->notFound('Instance not found');
        }

        $engine = new Engine($this->db);

        try {
            if (in_array($action, self::NOTE_REQUIRED, true)) {
                $note = trim((string) $request->body('note', ''));
                if ($note === '') {
                    return Response::json(['error' => 'A note explaining this action is required.'], 422);
                }
                if ($denied = $this->requireGlobalCapability('manage_processes')) {
                    return $denied;
                }
                $result = match ($action) {
                    'retry' => $engine->retry($instanceId, $this->userId(), $note),
                    'cancel' => $engine->cancel($instanceId, $this->userId(), $note),
                    'pause' => $engine->pause($instanceId, $this->userId(), $note),
                    'resume' => $engine->resume($instanceId, $this->userId(), $note),
                    'move' => $engine->moveTo($instanceId, (string) $request->body('nodeId', ''), $this->userId(), $note),
                };
                return $this->ok($this->castResult($result));
            }

            if ($action === 'tasks') {
                $taskId = (int) ($this->params['taskId'] ?? 0);
                $task = $this->db->one('SELECT * FROM process_tasks WHERE id = ? AND process_instance_id = ?', [$taskId, $instanceId]);
                if (!$task) {
                    return $this->notFound('Task not found');
                }
                if (!$this->hasGlobalCapability('manage_processes') && (int) ($task['assignee_user_id'] ?? 0) !== $this->userId()) {
                    return $this->forbidden('Only the assignee (or a process manager) can complete this task.');
                }
                $outcome = trim((string) $request->body('outcome', ''));
                if ($outcome === '') {
                    return Response::json(['error' => 'outcome is required'], 422);
                }
                $result = $engine->completeTask($instanceId, $taskId, $outcome, $request->body('note') ?: null, $this->userId(), $this->actorLabel());
                return $this->ok($this->castResult($result));
            }

            if ($action === 'waits') {
                if ($denied = $this->requireGlobalCapability('manage_processes')) {
                    return $denied;
                }
                $waitId = (int) ($this->params['waitId'] ?? 0);
                $result = $engine->resumeWait($instanceId, $waitId, $request->body('correlationKey') ?: null, $request->body('note') ?: null, $this->actorLabel());
                return $this->ok($this->castResult($result));
            }

            return Response::json(['error' => 'Unsupported action'], 422);
        } catch (EngineException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }
    }

    private function startInstance(Request $request, array $definition): Response
    {
        $versionId = $request->body('versionId') ? (int) $request->body('versionId') : $definition['current_published_version_id'];
        if (!$versionId) {
            return Response::json(['error' => 'This process has no published version to start from.'], 422);
        }
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$versionId, $definition['id']]);
        if (!$version) {
            return $this->notFound('Version not found');
        }
        if ($version['status'] !== 'published' && !$request->body('allowDraft')) {
            return Response::json(['error' => 'Only a published version can be started (pass allowDraft to test a draft).'], 422);
        }

        try {
            $engine = new Engine($this->db);
            $result = $engine->startInstance($definition, $version, [
                'name' => $request->body('name'),
                'variables' => is_array($request->body('variables')) ? $request->body('variables') : [],
                'entity_type' => $request->body('entityType') ?: null,
                'entity_id' => $request->body('entityId') ? (int) $request->body('entityId') : null,
                'owner_user_id' => $this->userId(),
                'actor' => $this->actorLabel(),
                'actor_user_id' => $this->userId(),
            ]);
            return $this->ok($this->castResult($result));
        } catch (EngineException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function actorLabel(): string
    {
        $user = $this->auth->user();
        return (string) ($user['name'] ?? $user['email'] ?? 'operator');
    }

    private function castResult(array $result): array
    {
        if (!isset($result['instance'])) {
            // The idempotent "already handled" shortcut (Engine::completeTask()/
            // resumeWait() when the task/wait was no longer open/waiting) —
            // nothing further to cast.
            return $result;
        }
        return [
            'instance' => $this->castDetail($result['instance']),
            'tasks' => $result['tasks'],
            'waits' => $result['waits'],
            'executions' => $result['executions'],
        ];
    }

    private function index(Request $request, int $processId): Response
    {
        $status = (string) $request->query('status', '');
        $node = (string) $request->query('node', '');
        $q = trim((string) $request->query('q', ''));

        $where = ['process_definition_id = ?'];
        $params = [$processId];
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($node !== '') {
            $where[] = 'current_node_id = ?';
            $params[] = $node;
        }
        if ($q !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $instances = $this->db->all(
            'SELECT i.*, u.name AS owner_name FROM process_instances i
             LEFT JOIN users u ON u.id = i.owner_user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (i.status = "overdue") DESC, i.updated_at DESC',
            $params
        );

        $nodeCounts = $this->db->all(
            'SELECT current_node_id, status, COUNT(*) AS n FROM process_instances
             WHERE process_definition_id = ? AND current_node_id IS NOT NULL
             GROUP BY current_node_id, status',
            [$processId]
        );
        $byNode = [];
        foreach ($nodeCounts as $row) {
            $byNode[$row['current_node_id']][$row['status']] = (int) $row['n'];
        }

        return $this->ok([
            'instances' => array_map([$this, 'castSummary'], $instances),
            'nodeCounts' => $byNode,
        ]);
    }

    private function show(int $processId, int $instanceId): Response
    {
        $instance = $this->db->one(
            'SELECT i.*, u.name AS owner_name FROM process_instances i
             LEFT JOIN users u ON u.id = i.owner_user_id
             WHERE i.id = ? AND i.process_definition_id = ?',
            [$instanceId, $processId]
        );
        if (!$instance) {
            return $this->notFound('Instance not found');
        }

        $events = $this->db->all(
            'SELECT * FROM process_instance_events WHERE process_instance_id = ? ORDER BY created_at ASC, id ASC',
            [$instanceId]
        );

        $version = $this->db->one('SELECT id, version_number, graph_json FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
        $graph = $version ? json_decode((string) $version['graph_json'], true) : null;

        // Real (Phase 2) execution rows — empty arrays for the seeded
        // is_demo=1 instances, which the runtime never touches.
        $tasks = $this->db->all('SELECT * FROM process_tasks WHERE process_instance_id = ? ORDER BY created_at DESC', [$instanceId]);
        $waits = $this->db->all('SELECT * FROM process_waits WHERE process_instance_id = ? ORDER BY created_at DESC', [$instanceId]);

        return $this->ok([
            'instance' => $this->castDetail($instance),
            'events' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'node_id' => $row['node_id'],
                    'event_type' => $row['event_type'],
                    'label' => $row['label'],
                    'detail' => $row['detail'],
                    'actor' => $row['actor'],
                    'created_at' => $row['created_at'],
                ];
            }, $events),
            'tasks' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'node_id' => $row['node_id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'outcome' => $row['outcome'],
                    'assignee_user_id' => $row['assignee_user_id'] !== null ? (int) $row['assignee_user_id'] : null,
                    'assignee_role' => $row['assignee_role'],
                    'due_at' => $row['due_at'],
                ];
            }, $tasks),
            'waits' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'node_id' => $row['node_id'],
                    'status' => $row['status'],
                    'awaited_event' => $row['awaited_event'],
                    'timeout_at' => $row['timeout_at'],
                ];
            }, $waits),
            'graph' => is_array($graph) ? $graph : null,
            'version_number' => $version['version_number'] ?? null,
        ]);
    }

    private function castSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'current_node_id' => $row['current_node_id'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'owner_name' => $row['owner_name'],
            'due_at' => $row['due_at'],
            'is_demo' => (bool) $row['is_demo'],
            'started_at' => $row['started_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function castDetail(array $row): array
    {
        $summary = $this->castSummary($row);
        $variables = $row['variables_json'] ? json_decode((string) $row['variables_json'], true) : null;
        $summary['variables'] = is_array($variables) ? $variables : [];
        $summary['process_version_id'] = (int) $row['process_version_id'];
        $summary['completed_at'] = $row['completed_at'];
        return $summary;
    }
}
