<?php
declare(strict_types=1);

namespace Panic\Tasks;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Standalone Tasks app — a single task's Activity feed (task detail
 * panel's "ACTIVITY" section in tasks-ui.png): a merged, time-ordered view
 * of task_comments (user-written notes) and task_activity (system-logged
 * changes — see Items::update()/create()), same idea as the event
 * workspace's activityEntry() log but scoped to one task instead of one
 * event.
 *
 *   GET  /api/task-documents/{docId}/tasks/{id}/activity   merged feed  (view_tasks_app)
 *   POST /api/task-documents/{docId}/tasks/{id}/comments   add a note   (manage_tasks_app)
 */
final class Activity extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $documentId = (int) ($this->params['documentId'] ?? 0);
        $taskId = (int) ($this->params['taskId'] ?? 0);
        if (!$taskId || !$this->db->one('SELECT id FROM tasks WHERE id = ? AND document_id = ?', [$taskId, $documentId])) {
            return $this->notFound('Task not found');
        }

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_tasks_app')) {
                return $denied;
            }
            return $this->feed($taskId);
        }

        if ($request->method() === 'POST') {
            if ($denied = $this->requireGlobalCapability('manage_tasks_app')) {
                return $denied;
            }
            return $this->comment($request, $taskId);
        }

        return Response::methodNotAllowed();
    }

    private function feed(int $taskId): Response
    {
        $comments = $this->db->all(
            'SELECT c.id, c.body, c.created_at, c.user_id, u.name AS user_name
             FROM task_comments c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.task_id = ?',
            [$taskId]
        );
        $activity = $this->db->all(
            'SELECT a.id, a.action, a.details_json, a.created_at, a.user_id, u.name AS user_name
             FROM task_activity a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.task_id = ?',
            [$taskId]
        );

        $items = [];
        foreach ($comments as $c) {
            $items[] = ['type' => 'comment', 'id' => (int) $c['id'], 'body' => $c['body'], 'user_id' => $c['user_id'] ? (int) $c['user_id'] : null, 'user_name' => $c['user_name'], 'created_at' => $c['created_at']];
        }
        foreach ($activity as $a) {
            $details = $a['details_json'] ? json_decode((string) $a['details_json'], true) : null;
            $items[] = ['type' => 'activity', 'id' => (int) $a['id'], 'action' => $a['action'], 'details' => is_array($details) ? $details : null, 'user_id' => $a['user_id'] ? (int) $a['user_id'] : null, 'user_name' => $a['user_name'], 'created_at' => $a['created_at']];
        }
        usort($items, fn ($x, $y) => strcmp((string) $y['created_at'], (string) $x['created_at']));

        return $this->ok(['items' => $items]);
    }

    private function comment(Request $request, int $taskId): Response
    {
        $body = trim((string) $request->body('body', ''));
        if ($body === '') {
            return Response::json(['error' => 'Comment cannot be empty'], 422);
        }
        $id = $this->db->insert('INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)', [$taskId, $this->userId(), $body]);
        return $this->ok(['id' => $id]);
    }
}
