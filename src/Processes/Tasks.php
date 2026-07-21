<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Processes\Runtime\Engine;
use Panic\Processes\Runtime\EngineException;
use Panic\Request;
use Panic\Response;

/**
 * Automation > Tasks — a real, cross-process inbox of open process_tasks
 * rows (see database/migrations/067_add_process_runtime.sql and
 * Runtime/Engine.php::createTask()). This is the one piece of the original
 * Phase 4 "Operational views" scope pulled forward into Phase 2, because
 * once the runtime creates real task rows a cross-process list of them is
 * just a join — nothing further to build first.
 *
 *   GET  /api/process-tasks                filter by status/assignee=me/q  (view_processes)
 *   POST /api/process-tasks/{id}/complete  (assignee OR manage_processes)
 */
final class Tasks extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $taskId = $this->params['taskId'] ?? null;

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_processes')) {
                return $denied;
            }
            return $this->index($request);
        }

        if ($request->method() === 'POST' && $taskId && ($this->params['action'] ?? null) === 'complete') {
            return $this->complete($request, (int) $taskId);
        }

        return Response::methodNotAllowed();
    }

    private function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');
        $assignee = (string) $request->query('assignee', '');
        $q = trim((string) $request->query('q', ''));

        $where = [];
        $params = [];
        if ($status !== '' && $status !== 'all') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        if ($assignee === 'me') {
            $where[] = 't.assignee_user_id = ?';
            $params[] = $this->userId();
        }
        if ($q !== '') {
            $where[] = '(t.title LIKE ? OR i.name LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = 'SELECT t.*, i.name AS instance_name, i.process_definition_id, d.name AS process_name, u.name AS assignee_name
                FROM process_tasks t
                JOIN process_instances i ON i.id = t.process_instance_id
                JOIN process_definitions d ON d.id = i.process_definition_id
                LEFT JOIN users u ON u.id = t.assignee_user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY (t.due_at IS NOT NULL AND t.due_at < NOW() AND t.status = "open") DESC, t.due_at IS NULL, t.due_at ASC, t.created_at DESC
                LIMIT 500';

        $rows = $this->db->all($sql, $params);

        return $this->ok(['tasks' => array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'status' => $row['status'],
                'outcome' => $row['outcome'],
                'due_at' => $row['due_at'],
                'overdue' => $row['status'] === 'open' && $row['due_at'] !== null && strtotime((string) $row['due_at']) < time(),
                'assignee_user_id' => $row['assignee_user_id'] !== null ? (int) $row['assignee_user_id'] : null,
                'assignee_name' => $row['assignee_name'],
                'assignee_role' => $row['assignee_role'],
                'process_instance_id' => (int) $row['process_instance_id'],
                'instance_name' => $row['instance_name'],
                'process_definition_id' => (int) $row['process_definition_id'],
                'process_name' => $row['process_name'],
                'created_at' => $row['created_at'],
                'completed_at' => $row['completed_at'],
            ];
        }, $rows)]);
    }

    private function complete(Request $request, int $taskId): Response
    {
        $task = $this->db->one('SELECT * FROM process_tasks WHERE id = ?', [$taskId]);
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

        $user = $this->auth->user();
        try {
            $engine = new Engine($this->db);
            $result = $engine->completeTask(
                (int) $task['process_instance_id'],
                $taskId,
                $outcome,
                $request->body('note') ?: null,
                $this->userId(),
                (string) ($user['name'] ?? $user['email'] ?? 'operator')
            );
            if (!isset($result['instance'])) {
                return $this->ok($result); // already completed — idempotent no-op
            }
            return $this->ok(['instance' => $result['instance'], 'tasks' => $result['tasks']]);
        } catch (EngineException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }
    }
}
