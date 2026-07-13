<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Per-day time blocks for a single event (issue #8).
 *
 *   GET    /api/events/{id}/sessions           list sessions, ordered by date
 *   POST   /api/events/{id}/sessions           add a session
 *   PATCH  /api/events/{id}/sessions/{sid}     edit a session
 *   DELETE /api/events/{id}/sessions/{sid}     remove a session
 *
 * Purely additive: an event with zero session rows behaves exactly as it did
 * before this feature existed (a single continuous [date, end_date] span
 * with one doors_time/end_time). Once an event has session rows, they become
 * the source of truth for per-day display (e.g. a two-day workshop with a
 * different time block each day), and events.date/end_date are kept in sync
 * as MIN/MAX(session_date) on every write here so the existing date-range
 * logic elsewhere (room-conflict check, calendar month view, sheet sync,
 * etc.) keeps working unchanged — it just sees a wider or narrower span.
 *
 * Capability: same gate as the core event record (edit_event) rather than a
 * dedicated manage_* capability — sessions define *when the event happens*,
 * same tier as date/end_date/doors_time on the main Details form, not an
 * operational sub-resource like tasks/staffing.
 */
final class Sessions extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId    = $this->requireEventId();
        $sessionId  = (int) ($this->params['sessionId'] ?? 0);
        if ($denied = $this->requireEventCapability($eventId, $request->method() === 'GET' ? 'read_event' : 'edit_event')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'    => $this->ok(['sessions' => $this->list($eventId)]),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, $sessionId),
            'DELETE' => $this->delete($eventId, $sessionId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function list(int $eventId): array
    {
        return $this->db->all(
            'SELECT * FROM event_sessions WHERE event_id = ? ORDER BY session_date, start_time, sort_order, id',
            [$eventId]
        );
    }

    private function create(Request $request, int $eventId): Response
    {
        $date = (string) $request->body('session_date', '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'session_date is required (YYYY-MM-DD)'], 422);
        }
        $id = $this->db->insert(
            'INSERT INTO event_sessions (event_id, session_date, start_time, end_time, label, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $date,
                $request->body('start_time') ?: null,
                $request->body('end_time') ?: null,
                $request->body('label') ?: null,
                (int) ($request->body('sort_order') ?? 0),
            ]
        );
        $this->syncEventDateRange($eventId);
        log_activity($this->db, $eventId, $this->userId(), 'session added', ['date' => $date]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $sessionId): Response
    {
        $date = (string) $request->body('session_date', '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'session_date is required (YYYY-MM-DD)'], 422);
        }
        $this->db->run(
            'UPDATE event_sessions SET session_date=?, start_time=?, end_time=?, label=?, sort_order=? WHERE id=? AND event_id=?',
            [
                $date,
                $request->body('start_time') ?: null,
                $request->body('end_time') ?: null,
                $request->body('label') ?: null,
                (int) ($request->body('sort_order') ?? 0),
                $sessionId,
                $eventId,
            ]
        );
        $this->syncEventDateRange($eventId);
        log_activity($this->db, $eventId, $this->userId(), 'session updated', ['date' => $date]);
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $sessionId): Response
    {
        $this->db->run('DELETE FROM event_sessions WHERE id=? AND event_id=?', [$sessionId, $eventId]);
        $this->syncEventDateRange($eventId);
        log_activity($this->db, $eventId, $this->userId(), 'session removed', ['session_id' => $sessionId]);
        return Response::noContent();
    }

    /**
     * Keep events.date/end_date equal to MIN/MAX(session_date) whenever this
     * event has session rows. If the last session was just deleted, leaves
     * date/end_date untouched — the event falls back to being a normal
     * single/continuous-range event with whatever date/end_date it already
     * had (does not clear or guess a new one).
     */
    private function syncEventDateRange(int $eventId): void
    {
        $range = $this->db->one(
            'SELECT MIN(session_date) min_date, MAX(session_date) max_date FROM event_sessions WHERE event_id = ?',
            [$eventId]
        );
        if (!$range || $range['min_date'] === null) {
            return;
        }
        $minDate = $range['min_date'];
        $maxDate = $range['max_date'];
        $this->db->run(
            'UPDATE events SET date = ?, end_date = ? WHERE id = ?',
            [$minDate, $maxDate === $minDate ? null : $maxDate, $eventId]
        );
    }
}
