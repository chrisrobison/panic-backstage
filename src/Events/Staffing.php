<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use Panic\StaffMembers;
use function Panic\log_activity;

/**
 *   GET    /api/events/{id}/staffing                    list shifts + roster
 *   POST   /api/events/{id}/staffing                    create a shift
 *   POST   /api/events/{id}/staffing/from-capacity      clear all shifts and auto-populate from capacity tiers
 *   PATCH  /api/events/{id}/staffing/{staffingId}       update a shift
 *   DELETE /api/events/{id}/staffing/{staffingId}       delete a single shift
 *   DELETE /api/events/{id}/staffing                    clear ALL shifts for the event
 *
 * Capability: read_event for GET, manage_staffing for everything else.
 */
final class Staffing extends BaseEndpoint
{
    private const STATUSES = ['scheduled','confirmed','declined','no_show','completed','canceled'];

    /**
     * Recommended headcount by role for events up to each capacity threshold.
     * Tiers cover every 50 guests from 50 → 400; anything above 400 uses the 400 tier.
     *
     * Role order within each tier follows the display order used in the staffing panel.
     */
    private const STAFFING_TIERS = [
        50  => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 1],
            ['role' => 'door',      'count' => 1],
            ['role' => 'sound',     'count' => 1],
        ],
        100 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 2],
            ['role' => 'door',      'count' => 1],
            ['role' => 'security',  'count' => 1],
            ['role' => 'sound',     'count' => 1],
        ],
        150 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 2],
            ['role' => 'barback',   'count' => 1],
            ['role' => 'door',      'count' => 2],
            ['role' => 'security',  'count' => 1],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 1],
        ],
        200 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 3],
            ['role' => 'barback',   'count' => 1],
            ['role' => 'door',      'count' => 2],
            ['role' => 'security',  'count' => 2],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 1],
        ],
        250 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 3],
            ['role' => 'barback',   'count' => 2],
            ['role' => 'door',      'count' => 2],
            ['role' => 'security',  'count' => 3],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 1],
            ['role' => 'runner',    'count' => 1],
        ],
        300 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 4],
            ['role' => 'barback',   'count' => 2],
            ['role' => 'door',      'count' => 2],
            ['role' => 'security',  'count' => 4],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 2],
            ['role' => 'runner',    'count' => 1],
        ],
        350 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 5],
            ['role' => 'barback',   'count' => 2],
            ['role' => 'door',      'count' => 3],
            ['role' => 'security',  'count' => 5],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 2],
            ['role' => 'runner',    'count' => 1],
        ],
        400 => [
            ['role' => 'manager',   'count' => 1],
            ['role' => 'bartender', 'count' => 5],
            ['role' => 'barback',   'count' => 3],
            ['role' => 'door',      'count' => 3],
            ['role' => 'security',  'count' => 6],
            ['role' => 'sound',     'count' => 1],
            ['role' => 'lighting',  'count' => 1],
            ['role' => 'stagehand', 'count' => 2],
            ['role' => 'runner',    'count' => 1],
        ],
    ];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $staffingId = $this->params['staffingId'] ?? null;
        $action     = $this->params['action'] ?? null;
        $cap = $request->method() === 'GET' ? 'read_event' : 'manage_staffing';
        if ($denied = $this->requireEventCapability($eventId, $cap)) {
            return $denied;
        }
        // POST /staffing/from-capacity → clear + auto-populate from capacity tiers
        if ($action === 'from-capacity') {
            return $request->method() === 'POST'
                ? $this->fromCapacity($request, $eventId)
                : Response::methodNotAllowed();
        }
        return match ($request->method()) {
            'GET'    => $this->index($eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, (int) $staffingId),
            'DELETE' => $staffingId ? $this->delete($eventId, (int) $staffingId) : $this->clearAll($eventId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(int $eventId): Response
    {
        return $this->ok([
            'staffing' => $this->staffingFor($eventId),
            'staff'    => $this->activeRoster(),
            'roles'    => StaffMembers::ROLES,
            'statuses' => self::STATUSES,
        ]);
    }

    private function create(Request $request, int $eventId): Response
    {
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $id = $this->db->insert(
            'INSERT INTO event_staffing (event_id, staff_member_id, role, call_time, end_time, hourly_rate, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $payload['staff_member_id'],
                $payload['role'],
                $payload['call_time'],
                $payload['end_time'],
                $payload['hourly_rate'],
                $payload['status'],
                $payload['notes'],
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'staffing added', [
            'staffing_id' => $id,
            'role'        => $payload['role'],
        ]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $staffingId): Response
    {
        if (!$staffingId) return $this->notFound();
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $rows = $this->db->run(
            'UPDATE event_staffing
             SET staff_member_id=?, role=?, call_time=?, end_time=?, hourly_rate=?, status=?, notes=?
             WHERE id=? AND event_id=?',
            [
                $payload['staff_member_id'],
                $payload['role'],
                $payload['call_time'],
                $payload['end_time'],
                $payload['hourly_rate'],
                $payload['status'],
                $payload['notes'],
                $staffingId,
                $eventId,
            ]
        );
        if ($rows === 0) return $this->notFound('Staffing row not found');
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $staffingId): Response
    {
        if (!$staffingId) return $this->notFound();
        $this->db->run('DELETE FROM event_staffing WHERE id = ? AND event_id = ?', [$staffingId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'staffing removed', ['staffing_id' => $staffingId]);
        return Response::noContent();
    }

    /**
     * DELETE /api/events/{id}/staffing  (no staffingId)
     * Remove all shifts for the event.
     */
    private function clearAll(int $eventId): Response
    {
        $this->db->run('DELETE FROM event_staffing WHERE event_id = ?', [$eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'staffing cleared', []);
        return Response::noContent();
    }

    /**
     * POST /api/events/{id}/staffing/from-capacity
     * Clear existing shifts and rebuild from capacity-based staffing tiers.
     * Reads capacity from the request body; falls back to the event's capacity field.
     */
    private function fromCapacity(Request $request, int $eventId): Response
    {
        $capacity = (int) $request->body('capacity', 0);
        if ($capacity <= 0) {
            $event    = $this->db->one('SELECT capacity FROM events WHERE id = ?', [$eventId]);
            $capacity = (int) ($event['capacity'] ?? 0);
        }
        if ($capacity <= 0) {
            return Response::json(['error' => 'capacity is required (set it on the event or pass it in the request body)'], 422);
        }

        $tier = $this->tierForCapacity($capacity);

        // Atomically replace staffing.
        $this->db->run('DELETE FROM event_staffing WHERE event_id = ?', [$eventId]);
        foreach ($tier as $entry) {
            $role  = $entry['role'];
            $count = max(1, (int) ($entry['count'] ?? 1));
            for ($i = 0; $i < $count; $i++) {
                $this->db->run(
                    'INSERT INTO event_staffing (event_id, role, status) VALUES (?, ?, ?)',
                    [$eventId, $role, 'scheduled']
                );
            }
        }
        $total = array_sum(array_column($tier, 'count'));
        log_activity($this->db, $eventId, $this->userId(), 'staffing auto-populated', [
            'capacity' => $capacity,
            'shifts'   => $total,
        ]);
        return $this->ok(['staffing' => $this->staffingFor($eventId)]);
    }

    /**
     * Return the appropriate staffing tier for the given capacity.
     * Capacities above 400 use the 400-person tier.
     */
    private function tierForCapacity(int $capacity): array
    {
        foreach (self::STAFFING_TIERS as $limit => $tier) {
            if ($capacity <= $limit) {
                return $tier;
            }
        }
        return self::STAFFING_TIERS[400];
    }

    /**
     * Create staffing rows from a staffing_json template list (no-capacity path).
     * Used by Events::fromTemplate() after event creation.
     *
     * @param  array  $entries  Decoded staffing_json — [{role, count, notes?}, …]
     */
    public function createFromTemplate(int $eventId, array $entries): void
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $role  = $entry['role'] ?? 'other';
            if (!in_array($role, StaffMembers::ROLES, true)) $role = 'other';
            $count = max(1, (int) ($entry['count'] ?? 1));
            $notes = isset($entry['notes']) && $entry['notes'] !== '' ? $entry['notes'] : null;
            for ($i = 0; $i < $count; $i++) {
                $this->db->run(
                    'INSERT INTO event_staffing (event_id, role, status, notes) VALUES (?, ?, ?, ?)',
                    [$eventId, $role, 'scheduled', $notes]
                );
            }
        }
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request): array
    {
        $role = (string) $request->body('role', 'other');
        if (!in_array($role, StaffMembers::ROLES, true)) {
            return [[], Response::json(['error' => 'Invalid role'], 422)];
        }
        $status = (string) $request->body('status', 'scheduled');
        if (!in_array($status, self::STATUSES, true)) {
            return [[], Response::json(['error' => 'Invalid status'], 422)];
        }
        $staffId = $request->body('staff_member_id');
        $staffId = ($staffId === '' || $staffId === null) ? null : (int) $staffId;
        $rate = $request->body('hourly_rate');
        $rate = ($rate === '' || $rate === null) ? null : (float) $rate;

        return [[
            'staff_member_id' => $staffId,
            'role'            => $role,
            'call_time'       => $this->timeOrNull($request->body('call_time')),
            'end_time'        => $this->timeOrNull($request->body('end_time')),
            'hourly_rate'     => $rate,
            'status'          => $status,
            'notes'           => trim((string) $request->body('notes', '')) ?: null,
        ], null];
    }

    private function timeOrNull(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return $value === '' ? null : $value;
    }

    public function staffingFor(int $eventId): array
    {
        return $this->db->all(
            'SELECT s.*, sm.name staff_name, sm.email staff_email, sm.phone staff_phone, sm.default_role staff_default_role
             FROM event_staffing s
             LEFT JOIN staff_members sm ON sm.id = s.staff_member_id
             WHERE s.event_id = ?
             ORDER BY s.call_time, FIELD(s.role,"manager","sound","lighting","security","door","bartender","barback","stagehand","runner","cleaner","other"), s.id',
            [$eventId]
        );
    }

    public function activeRoster(): array
    {
        return $this->db->all(
            'SELECT id, name, email, phone, default_role, hourly_rate
             FROM staff_members WHERE active = 1 ORDER BY name'
        );
    }
}
