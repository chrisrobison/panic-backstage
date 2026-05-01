<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

final class Schedule extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $scheduleId = (int) ($this->params['scheduleId'] ?? 0);
        if ($denied = $this->requireEventCapability($eventId, $request->method() === 'GET' ? 'read_event' : 'manage_schedule')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->ok(['schedule' => $this->db->all('SELECT * FROM event_schedule_items WHERE event_id = ? ORDER BY start_time, id', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, $scheduleId),
            'DELETE' => $this->delete($eventId, $scheduleId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $id = $this->db->insert('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?, ?)', [
            $eventId, $request->body('title'), $request->body('item_type', 'other'), $request->body('start_time') ?: null, $request->body('end_time') ?: null, $request->body('notes')
        ]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $scheduleId): Response
    {
        $this->db->run('UPDATE event_schedule_items SET title=?, item_type=?, start_time=?, end_time=?, notes=? WHERE id=? AND event_id=?', [
            $request->body('title'), $request->body('item_type'), $request->body('start_time') ?: null, $request->body('end_time') ?: null, $request->body('notes'), $scheduleId, $eventId
        ]);
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $scheduleId): Response
    {
        $this->db->run('DELETE FROM event_schedule_items WHERE id=? AND event_id=?', [$scheduleId, $eventId]);
        return Response::noContent();
    }
}
