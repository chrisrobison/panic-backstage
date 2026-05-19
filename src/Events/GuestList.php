<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class GuestList extends BaseEndpoint
{
    private const LIST_TYPES = ['comp', 'guest', 'will_call', 'vip', 'press', 'industry'];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $guestId = (int) ($this->params['guestId'] ?? 0);
        $method = $request->method();
        $needs = $method === 'GET' ? 'read_event' : 'manage_guest_list';
        if ($denied = $this->requireEventCapability($eventId, $needs)) {
            return $denied;
        }
        return match ($method) {
            'GET' => $this->ok([
                'guests' => $this->db->all(
                    'SELECT g.*, u.name created_by_name
                     FROM event_guest_list g LEFT JOIN users u ON u.id = g.created_by_user_id
                     WHERE g.event_id = ?
                     ORDER BY g.list_type, g.name',
                    [$eventId]
                ),
            ]),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, $guestId),
            'DELETE' => $this->delete($eventId, $guestId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }
        $listType = $this->normalizeListType($request->body('list_type'));
        $partySize = max(1, (int) $request->body('party_size', 1));
        $id = $this->db->insert(
            'INSERT INTO event_guest_list (event_id, name, party_size, list_type, guest_of, notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $name,
                $partySize,
                $listType,
                $this->stringOrNull($request->body('guest_of')),
                $this->stringOrNull($request->body('notes')),
                $this->userId(),
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'guest list changed', ['action' => 'added', 'name' => $name]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $guestId): Response
    {
        if (!$guestId) {
            return $this->notFound();
        }
        $existing = $this->db->one('SELECT * FROM event_guest_list WHERE id = ? AND event_id = ?', [$guestId, $eventId]);
        if (!$existing) {
            return $this->notFound();
        }

        // Dedicated check-in toggle (single-field PATCH)
        if ($request->body('checked_in') !== null && count((array) $request->body()) === 1) {
            $checkedIn = $request->body('checked_in') ? 1 : 0;
            $this->db->run(
                'UPDATE event_guest_list SET checked_in = ?, checked_in_at = ? WHERE id = ? AND event_id = ?',
                [$checkedIn, $checkedIn ? date('Y-m-d H:i:s') : null, $guestId, $eventId]
            );
            log_activity($this->db, $eventId, $this->userId(), 'guest list changed', [
                'action' => $checkedIn ? 'checked_in' : 'unchecked',
                'name' => $existing['name'],
            ]);
            return $this->ok(['ok' => true]);
        }

        $name = trim((string) ($request->body('name') ?? $existing['name']));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }
        $this->db->run(
            'UPDATE event_guest_list SET name=?, party_size=?, list_type=?, guest_of=?, notes=? WHERE id=? AND event_id=?',
            [
                $name,
                max(1, (int) ($request->body('party_size') ?? $existing['party_size'])),
                $this->normalizeListType($request->body('list_type') ?? $existing['list_type']),
                $this->stringOrNull($request->body('guest_of') ?? $existing['guest_of']),
                $this->stringOrNull($request->body('notes') ?? $existing['notes']),
                $guestId,
                $eventId,
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'guest list changed', ['action' => 'updated', 'name' => $name]);
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $guestId): Response
    {
        if (!$guestId) {
            return $this->notFound();
        }
        $existing = $this->db->one('SELECT name FROM event_guest_list WHERE id = ? AND event_id = ?', [$guestId, $eventId]);
        if (!$existing) {
            return $this->notFound();
        }
        $this->db->run('DELETE FROM event_guest_list WHERE id = ? AND event_id = ?', [$guestId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'guest list changed', ['action' => 'deleted', 'name' => $existing['name']]);
        return Response::noContent();
    }

    private function normalizeListType(mixed $value): string
    {
        $value = is_string($value) ? strtolower($value) : 'guest';
        return in_array($value, self::LIST_TYPES, true) ? $value : 'guest';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
