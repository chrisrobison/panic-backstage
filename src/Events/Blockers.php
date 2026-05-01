<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class Blockers extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $blockerId = (int) ($this->params['blockerId'] ?? 0);
        return match ($request->method()) {
            'GET' => $this->ok(['blockers' => $this->db->all('SELECT * FROM event_blockers WHERE event_id = ? ORDER BY due_date, id', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, $blockerId),
            'DELETE' => $this->delete($eventId, $blockerId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $id = $this->db->insert('INSERT INTO event_blockers (event_id, title, description, owner_user_id, status, due_date) VALUES (?, ?, ?, ?, ?, ?)', [
            $eventId, $request->body('title'), $request->body('description'), $request->body('owner_user_id') ?: null, $request->body('status', 'open'), $request->body('due_date') ?: null
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'blocker created', ['blocker_id' => $id]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $blockerId): Response
    {
        $this->db->run('UPDATE event_blockers SET title=?, description=?, owner_user_id=?, status=?, due_date=? WHERE id=? AND event_id=?', [
            $request->body('title'), $request->body('description'), $request->body('owner_user_id') ?: null, $request->body('status', 'open'), $request->body('due_date') ?: null, $blockerId, $eventId
        ]);
        if ($request->body('status') === 'resolved') {
            log_activity($this->db, $eventId, $this->userId(), 'blocker resolved', ['blocker_id' => $blockerId]);
        }
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $blockerId): Response
    {
        $this->db->run('DELETE FROM event_blockers WHERE id=? AND event_id=?', [$blockerId, $eventId]);
        return Response::noContent();
    }
}
