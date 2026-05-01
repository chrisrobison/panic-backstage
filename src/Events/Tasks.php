<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class Tasks extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $taskId = $this->params['taskId'] ?? null;
        if ($denied = $this->requireEventCapability($eventId, $request->method() === 'GET' ? 'read_event' : 'manage_tasks')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->ok(['tasks' => $this->tasks($eventId)]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, (int) $taskId),
            'DELETE' => $this->delete($eventId, (int) $taskId),
            default => Response::methodNotAllowed()
        };
    }

    private function tasks(int $eventId): array
    {
        if ($this->hasEventCapability($eventId, 'view_assigned_tasks') && !$this->hasEventCapability($eventId, 'manage_tasks')) {
            return $this->db->all('SELECT * FROM event_tasks WHERE event_id = ? AND assigned_user_id = ? ORDER BY due_date, id', [$eventId, $this->userId()]);
        }
        return $this->db->all('SELECT * FROM event_tasks WHERE event_id = ? ORDER BY due_date, id', [$eventId]);
    }

    private function create(Request $request, int $eventId): Response
    {
        $id = $this->db->insert('INSERT INTO event_tasks (event_id, title, description, status, assigned_user_id, due_date, priority) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $eventId, $request->body('title'), $request->body('description'), $request->body('status', 'todo'), $request->body('assigned_user_id') ?: null, $request->body('due_date') ?: null, $request->body('priority', 'normal')
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'task created', ['task_id' => $id]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $taskId): Response
    {
        $this->db->run('UPDATE event_tasks SET title=?, description=?, status=?, assigned_user_id=?, due_date=?, priority=? WHERE id=? AND event_id=?', [
            $request->body('title'), $request->body('description'), $request->body('status'), $request->body('assigned_user_id') ?: null, $request->body('due_date') ?: null, $request->body('priority', 'normal'), $taskId, $eventId
        ]);
        if ($request->body('status') === 'done') {
            log_activity($this->db, $eventId, $this->userId(), 'task completed', ['task_id' => $taskId]);
        }
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $taskId): Response
    {
        $this->db->run('DELETE FROM event_tasks WHERE id=? AND event_id=?', [$taskId, $eventId]);
        return Response::noContent();
    }
}
