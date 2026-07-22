<?php
declare(strict_types=1);

namespace Panic\Tasks;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_task_activity;

/**
 * Standalone Tasks app — the tasks within one task document (see
 * Documents.php). Tasks nest via parent_task_id; the client builds the
 * hierarchy (and WBS numbering) from this flat list rather than the server
 * shipping a pre-nested tree, same "flat rows in, tree built client-side"
 * approach event-panels.js already uses elsewhere in this app.
 *
 *   GET    /api/task-documents/{docId}/tasks         list, flat            (view_tasks_app)
 *   POST   /api/task-documents/{docId}/tasks         create                (manage_tasks_app)
 *   PATCH  /api/task-documents/{docId}/tasks/{id}     update (any field)   (manage_tasks_app)
 *   DELETE /api/task-documents/{docId}/tasks/{id}     delete (+ subtasks)  (manage_tasks_app)
 */
final class Items extends BaseEndpoint
{
    private const STATUSES = ['not_started', 'in_progress', 'done'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $documentId = (int) ($this->params['documentId'] ?? 0);
        if (!$documentId || !$this->db->one('SELECT id FROM task_documents WHERE id = ?', [$documentId])) {
            return $this->notFound('Task document not found');
        }
        $taskId = $this->params['taskId'] ?? null;

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_tasks_app')) {
                return $denied;
            }
            return $this->index($documentId);
        }

        if ($denied = $this->requireGlobalCapability('manage_tasks_app')) {
            return $denied;
        }

        return match ($request->method()) {
            'POST' => $this->create($request, $documentId),
            'PATCH' => $taskId ? $this->update($request, $documentId, (int) $taskId) : Response::json(['error' => 'Task id is required'], 422),
            'DELETE' => $taskId ? $this->delete($documentId, (int) $taskId) : Response::json(['error' => 'Task id is required'], 422),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(int $documentId): Response
    {
        $tasks = $this->db->all(
            'SELECT t.*, u.name AS assignee_name
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assignee_user_id
             WHERE t.document_id = ?
             ORDER BY t.parent_task_id IS NULL DESC, t.parent_task_id, t.sort_order, t.id',
            [$documentId]
        );
        return $this->ok(['tasks' => array_map([$this, 'decorate'], $tasks)]);
    }

    private function decorate(array $task): array
    {
        $task['tags'] = $this->decodeJsonList($task['tags_json'] ?? null);
        $task['checklist'] = $this->decodeJsonList($task['checklist_json'] ?? null);
        $task['depends_on'] = array_map('intval', $this->decodeJsonList($task['depends_on_json'] ?? null));
        unset($task['tags_json'], $task['checklist_json'], $task['depends_on_json']);
        return $task;
    }

    private function create(Request $request, int $documentId): Response
    {
        $title = trim((string) $request->body('title', ''));
        if ($title === '') {
            return Response::json(['error' => 'Title is required'], 422);
        }
        $parentTaskId = $request->body('parent_task_id') ? (int) $request->body('parent_task_id') : null;
        if ($parentTaskId && !$this->db->one('SELECT id FROM tasks WHERE id = ? AND document_id = ?', [$parentTaskId, $documentId])) {
            return Response::json(['error' => 'Parent task not found in this document'], 422);
        }
        $nextOrder = (int) ($this->db->one(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM tasks WHERE document_id = ? AND parent_task_id ' . ($parentTaskId ? '= ?' : 'IS NULL'),
            $parentTaskId ? [$documentId, $parentTaskId] : [$documentId]
        )['n'] ?? 10);

        $id = $this->db->insert(
            'INSERT INTO tasks (document_id, parent_task_id, title, description, status, priority, assignee_user_id, start_date, due_date, sort_order, tags_json, checklist_json, depends_on_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $documentId,
                $parentTaskId,
                $title,
                trim((string) $request->body('description', '')) ?: null,
                $this->normalizeEnum($request->body('status', 'not_started'), self::STATUSES, 'not_started'),
                $this->normalizeEnum($request->body('priority', 'medium'), self::PRIORITIES, 'medium'),
                $request->body('assignee_user_id') ?: null,
                $request->body('start_date') ?: null,
                $request->body('due_date') ?: null,
                $nextOrder,
                $this->normalizeJsonList($request->body('tags_json')),
                $this->normalizeJsonList($request->body('checklist_json')),
                $this->normalizeJsonList($request->body('depends_on_json')),
                $this->userId(),
            ]
        );
        log_task_activity($this->db, $id, $this->userId(), 'created this task');
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $documentId, int $taskId): Response
    {
        $existing = $this->db->one('SELECT * FROM tasks WHERE id = ? AND document_id = ?', [$taskId, $documentId]);
        if (!$existing) {
            return $this->notFound('Task not found');
        }

        $map = [
            'title' => fn ($v) => trim((string) $v),
            'description' => fn ($v) => trim((string) $v) ?: null,
            'status' => fn ($v) => $this->normalizeEnum($v, self::STATUSES, 'not_started'),
            'priority' => fn ($v) => $this->normalizeEnum($v, self::PRIORITIES, 'medium'),
            'assignee_user_id' => fn ($v) => $v ?: null,
            'start_date' => fn ($v) => $v ?: null,
            'due_date' => fn ($v) => $v ?: null,
            'sort_order' => fn ($v) => (int) $v,
            'tags_json' => fn ($v) => $this->normalizeJsonList($v),
            'checklist_json' => fn ($v) => $this->normalizeJsonList($v),
            'depends_on_json' => fn ($v) => $this->normalizeJsonList($v),
        ];
        // parent_task_id handled separately below — '' / null both mean "make top-level".
        $fields = [];
        $values = [];
        $changes = [];
        foreach ($map as $key => $normalize) {
            if ($request->body($key) === null && !array_key_exists($key, (array) $request->body())) {
                continue;
            }
            $new = $normalize($request->body($key));
            $old = $existing[$key];
            if ((string) $new !== (string) $old) {
                if (in_array($key, ['title', 'status', 'priority', 'due_date'], true)) {
                    $changes[] = ['field' => $key, 'from' => $old, 'to' => $new];
                }
            }
            $fields[] = "$key = ?";
            $values[] = $new;
        }
        if (array_key_exists('parent_task_id', (array) $request->body())) {
            $parentTaskId = $request->body('parent_task_id') ? (int) $request->body('parent_task_id') : null;
            if ($parentTaskId === $taskId) {
                return Response::json(['error' => 'A task cannot be its own parent'], 422);
            }
            if ($parentTaskId && !$this->db->one('SELECT id FROM tasks WHERE id = ? AND document_id = ?', [$parentTaskId, $documentId])) {
                return Response::json(['error' => 'Parent task not found in this document'], 422);
            }
            $fields[] = 'parent_task_id = ?';
            $values[] = $parentTaskId;
        }
        $newStatus = $request->body('status');
        if ($newStatus !== null) {
            $fields[] = 'completed_at = ?';
            $values[] = $newStatus === 'done' ? gmdate('Y-m-d H:i:s') : null;
        }

        if ($fields === []) {
            return $this->ok(['ok' => true]);
        }
        $values[] = $taskId;
        $this->db->run('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);

        foreach ($changes as $change) {
            if ($change['field'] === 'status') {
                log_task_activity($this->db, $taskId, $this->userId(), 'changed status', ['from' => $change['from'], 'to' => $change['to']]);
            }
        }
        $nonStatusChanges = array_values(array_filter($changes, fn ($c) => $c['field'] !== 'status'));
        if ($nonStatusChanges) {
            log_task_activity($this->db, $taskId, $this->userId(), 'updated this task', ['changes' => $nonStatusChanges]);
        }

        return $this->ok(['ok' => true]);
    }

    private function delete(int $documentId, int $taskId): Response
    {
        $this->db->run('DELETE FROM tasks WHERE id = ? AND document_id = ?', [$taskId, $documentId]);
        return Response::noContent();
    }

    private function normalizeEnum(mixed $value, array $allowed, string $default): string
    {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Accepts either a JSON string or a PHP array; stores canonical JSON. Same convention as Templates::normalizeJsonList(). */
    private function normalizeJsonList(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode(array_values($value));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(array_values($decoded));
            }
        }
        return '[]';
    }

    private function decodeJsonList(?string $value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
