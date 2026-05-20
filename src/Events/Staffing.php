<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use Panic\StaffMembers;
use function Panic\log_activity;

/**
 *   GET    /api/events/{id}/staffing                list shifts + roster
 *   POST   /api/events/{id}/staffing                create a shift
 *   PATCH  /api/events/{id}/staffing/{staffingId}   update a shift
 *   DELETE /api/events/{id}/staffing/{staffingId}   delete a shift
 *
 * Capability: read_event for GET, manage_staffing for everything else.
 */
final class Staffing extends BaseEndpoint
{
    private const STATUSES = ['scheduled','confirmed','declined','no_show','completed','canceled'];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $staffingId = $this->params['staffingId'] ?? null;
        $cap = $request->method() === 'GET' ? 'read_event' : 'manage_staffing';
        if ($denied = $this->requireEventCapability($eventId, $cap)) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'    => $this->index($eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, (int) $staffingId),
            'DELETE' => $this->delete($eventId, (int) $staffingId),
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
