<?php
declare(strict_types=1);

namespace Panic;

/**
 * Admin endpoint for the staff roster — security, bartenders, door, sound, etc.
 *
 *   GET    /api/staff-members          list (with optional ?active=0|1 filter)
 *   POST   /api/staff-members          create
 *   PATCH  /api/staff-members/{id}     update
 *   DELETE /api/staff-members/{id}     delete
 *
 * Gated by the manage_staff_roster global capability (venue_admin).
 */
final class StaffMembers extends BaseEndpoint
{
    public const ROLES = [
        'manager','security','bartender','barback','door',
        'sound','lighting','stagehand','runner','cleaner','other',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_roster')) {
            return $denied;
        }
        $id = $this->params['staffId'] ?? null;
        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];
        $activeFilter = $request->query('active');
        if ($activeFilter === '0' || $activeFilter === '1') {
            $where[] = 's.active = ?';
            $params[] = (int) $activeFilter;
        }
        $sql = 'SELECT s.*, u.name user_name, u.email user_email
                FROM staff_members s LEFT JOIN users u ON u.id = s.user_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.active DESC, s.name';
        return $this->ok([
            'staff' => $this->db->all($sql, $params),
            'roles' => self::ROLES,
            'users' => $this->db->all('SELECT id, name, email FROM users ORDER BY name'),
        ]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM staff_members WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Staff member not found');
        }
        return $this->ok(['staff' => $row]);
    }

    private function create(Request $request): Response
    {
        [$payload, $error] = $this->payload($request, isCreate: true);
        if ($error) return $error;
        $id = $this->db->insert(
            'INSERT INTO staff_members (name, email, phone, pronoun, default_role, employment_type, position, hourly_rate, hire_date, notes, active, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $payload['name'],
                $payload['email'],
                $payload['phone'],
                $payload['pronoun'],
                $payload['default_role'],
                $payload['employment_type'],
                $payload['position'],
                $payload['hourly_rate'],
                $payload['hire_date'],
                $payload['notes'],
                $payload['active'],
                $payload['user_id'],
            ]
        );
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id FROM staff_members WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Staff member not found');
        [$payload, $error] = $this->payload($request, isCreate: false);
        if ($error) return $error;
        $this->db->run(
            'UPDATE staff_members SET name=?, email=?, phone=?, pronoun=?, default_role=?, employment_type=?, position=?, hourly_rate=?, hire_date=?, notes=?, active=?, user_id=? WHERE id=?',
            [
                $payload['name'],
                $payload['email'],
                $payload['phone'],
                $payload['pronoun'],
                $payload['default_role'],
                $payload['employment_type'],
                $payload['position'],
                $payload['hourly_rate'],
                $payload['hire_date'],
                $payload['notes'],
                $payload['active'],
                $payload['user_id'],
                $id,
            ]
        );
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        // event_staffing.staff_member_id is ON DELETE SET NULL — shifts survive as "TBD".
        $this->db->run('DELETE FROM staff_members WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request, bool $isCreate): array
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return [[], Response::json(['error' => 'name is required'], 422)];
        }
        $role = (string) $request->body('default_role', 'other');
        if (!in_array($role, self::ROLES, true)) {
            return [[], Response::json(['error' => 'Invalid default_role'], 422)];
        }
        $employmentType = (string) $request->body('employment_type', 'employee');
        if (!in_array($employmentType, ['employee', 'contractor'], true)) {
            return [[], Response::json(['error' => 'Invalid employment_type'], 422)];
        }
        $email = trim((string) $request->body('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], Response::json(['error' => 'Invalid email'], 422)];
        }
        $rate = $request->body('hourly_rate');
        $rate = ($rate === '' || $rate === null) ? null : (float) $rate;

        $userId = $request->body('user_id');
        $userId = ($userId === '' || $userId === null) ? null : (int) $userId;

        // Accept YYYY-MM-DD (the date input's format); anything unparseable -> null.
        $hire = trim((string) $request->body('hire_date', ''));
        $hireDate = ($hire !== '' && ($ts = strtotime($hire)) !== false) ? date('Y-m-d', $ts) : null;

        return [[
            'name'            => $name,
            'email'           => $email !== '' ? strtolower($email) : null,
            'phone'           => trim((string) $request->body('phone', '')) ?: null,
            'pronoun'         => trim((string) $request->body('pronoun', '')) ?: null,
            'default_role'    => $role,
            'employment_type' => $employmentType,
            'position'        => trim((string) $request->body('position', '')) ?: null,
            'hourly_rate'  => $rate,
            'hire_date'    => $hireDate,
            'notes'        => trim((string) $request->body('notes', '')) ?: null,
            'active'       => boolish($request->body('active', $isCreate ? 1 : 0)),
            'user_id'      => $userId,
        ], null];
    }
}
