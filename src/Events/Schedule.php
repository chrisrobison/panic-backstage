<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class Schedule extends BaseEndpoint
{
    /**
     * Standard run-sheet shapes for the "Add preset" dropdown. Each entry is
     * [offsetMinutes, durationMinutes|null, title, item_type], offsets relative
     * to an anchor time (the event's doors_time, or 19:00 if unset). Purely
     * additive — no dedup against existing rows, same as Tasks::applyTemplate().
     */
    private const PRESETS = [
        '3_bands' => [
            [-240, null, 'Load In', 'load_in'],
            [-120, 90,   'Soundcheck', 'soundcheck'],
            [0,    null, 'Doors', 'doors'],
            [30,   45,   'Band 1 Set', 'set'],
            [75,   15,   'Changeover', 'changeover'],
            [90,   45,   'Band 2 Set', 'set'],
            [135,  15,   'Changeover', 'changeover'],
            [150,  45,   'Band 3 Set', 'set'],
            [195,  null, 'Curfew', 'curfew'],
        ],
        '4_bands' => [
            [-270, null, 'Load In', 'load_in'],
            [-150, 120,  'Soundcheck', 'soundcheck'],
            [0,    null, 'Doors', 'doors'],
            [30,   35,   'Band 1 Set', 'set'],
            [65,   10,   'Changeover', 'changeover'],
            [75,   35,   'Band 2 Set', 'set'],
            [110,  10,   'Changeover', 'changeover'],
            [120,  35,   'Band 3 Set', 'set'],
            [155,  10,   'Changeover', 'changeover'],
            [165,  35,   'Band 4 Set', 'set'],
            [200,  null, 'Curfew', 'curfew'],
        ],
        'staff_only' => [
            [-180, null, 'Load In', 'load_in'],
            [-60,  null, 'Staff Call', 'staff_call'],
            [0,    null, 'Doors', 'doors'],
            [240,  null, 'Curfew', 'curfew'],
        ],
    ];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $scheduleId = (int) ($this->params['scheduleId'] ?? 0);
        $action = $this->params['action'] ?? null;
        if ($denied = $this->requireEventCapability($eventId, $request->method() === 'GET' ? 'read_event' : 'manage_schedule')) {
            return $denied;
        }
        // POST /schedule/from-event-data → non-destructive: add items synthesized
        // from load-in/doors/curfew, lineup set times, and staff call times.
        if ($action === 'from-event-data') {
            return $request->method() === 'POST' ? $this->fromEventData($eventId) : Response::methodNotAllowed();
        }
        // POST /schedule/from-preset → additive: stamp in a standard run-sheet shape.
        if ($action === 'from-preset') {
            return $request->method() === 'POST' ? $this->fromPreset($request, $eventId) : Response::methodNotAllowed();
        }
        return match ($request->method()) {
            'GET' => $this->ok(['schedule' => $this->list($eventId)]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, $scheduleId),
            'DELETE' => $this->delete($eventId, $scheduleId),
            default => Response::methodNotAllowed()
        };
    }

    private function list(int $eventId): array
    {
        return $this->db->all('SELECT * FROM event_schedule_items WHERE event_id = ? ORDER BY start_time, id', [$eventId]);
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

    /**
     * POST /api/events/{id}/schedule/from-event-data
     *
     * Synthesizes run-sheet rows from data already entered elsewhere on the
     * event: load_in_time/doors_time/end_time on the event record, per-band
     * set times from the lineup, and call times from staffing. Non-destructive:
     * skips any candidate whose (item_type, start_time) already exists on the
     * run sheet, so repeated clicks (e.g. after adding another band) only add
     * what's new.
     */
    private function fromEventData(int $eventId): Response
    {
        $event = $this->db->one('SELECT load_in_time, doors_time, end_time FROM events WHERE id = ?', [$eventId]);
        $lineup = $this->db->all(
            'SELECT display_name, set_time, set_length_minutes FROM event_lineup WHERE event_id = ? AND set_time IS NOT NULL ORDER BY billing_order, set_time',
            [$eventId]
        );
        $staffing = $this->db->all(
            'SELECT call_time, role FROM event_staffing WHERE event_id = ? AND call_time IS NOT NULL',
            [$eventId]
        );

        $candidates = [];
        if (!empty($event['load_in_time'])) {
            $candidates[] = ['Load In', 'load_in', $event['load_in_time'], null];
        }
        if (!empty($event['doors_time'])) {
            $candidates[] = ['Doors', 'doors', $event['doors_time'], null];
        }
        if (!empty($event['end_time'])) {
            $candidates[] = ['Curfew', 'curfew', $event['end_time'], null];
        }
        foreach ($lineup as $item) {
            $end = null;
            if (!empty($item['set_length_minutes'])) {
                $end = (new \DateTimeImmutable($item['set_time']))->modify("+{$item['set_length_minutes']} minutes")->format('H:i:s');
            }
            $candidates[] = [$item['display_name'] . ' Set', 'set', $item['set_time'], $end];
        }
        $byCallTime = [];
        foreach ($staffing as $shift) {
            $byCallTime[$shift['call_time']][] = ucwords(str_replace('_', ' ', (string) $shift['role']));
        }
        foreach ($byCallTime as $callTime => $roles) {
            $candidates[] = ['Staff Call: ' . implode(', ', array_unique($roles)), 'staff_call', $callTime, null];
        }

        $existingKeys = array_map(
            fn (array $row) => $row['item_type'] . '|' . $row['start_time'],
            $this->db->all('SELECT item_type, start_time FROM event_schedule_items WHERE event_id = ?', [$eventId])
        );

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $added = 0;
        try {
            foreach ($candidates as [$title, $type, $start, $end]) {
                $key = $type . '|' . $start;
                if (in_array($key, $existingKeys, true)) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)',
                    [$eventId, $title, $type, $start, $end]
                );
                $existingKeys[] = $key;
                $added++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::json(['error' => 'Run sheet populate failed: ' . $e->getMessage()], 500);
        }

        log_activity($this->db, $eventId, $this->userId(), 'schedule populated from event data', ['added' => $added]);
        return $this->ok(['schedule' => $this->list($eventId), 'added' => $added]);
    }

    /**
     * POST /api/events/{id}/schedule/from-preset
     * Body: {"preset": "3_bands"|"4_bands"|"staff_only"}
     *
     * Stamps in a standard run-sheet shape, anchored on the event's doors_time
     * (or 19:00 if unset). Purely additive — does not check for duplicates, so
     * it can be combined with from-event-data or manual edits.
     */
    private function fromPreset(Request $request, int $eventId): Response
    {
        $preset = (string) $request->body('preset', '');
        if (!isset(self::PRESETS[$preset])) {
            return Response::json(['error' => 'Unknown preset'], 422);
        }
        $event = $this->db->one('SELECT doors_time FROM events WHERE id = ?', [$eventId]);
        $anchor = !empty($event['doors_time']) ? $event['doors_time'] : '19:00:00';
        $anchorDt = new \DateTimeImmutable($anchor);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $added = 0;
        try {
            foreach (self::PRESETS[$preset] as [$offset, $duration, $title, $type]) {
                $start = $anchorDt->modify("{$offset} minutes")->format('H:i:s');
                $end = $duration !== null ? $anchorDt->modify(($offset + $duration) . ' minutes')->format('H:i:s') : null;
                $this->db->run(
                    'INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)',
                    [$eventId, $title, $type, $start, $end]
                );
                $added++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::json(['error' => 'Preset apply failed: ' . $e->getMessage()], 500);
        }

        log_activity($this->db, $eventId, $this->userId(), 'schedule preset applied', ['preset' => $preset, 'added' => $added]);
        return $this->ok(['schedule' => $this->list($eventId), 'added' => $added]);
    }
}
