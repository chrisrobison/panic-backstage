<?php
declare(strict_types=1);

namespace Panic;

/**
 * Individual staff certification/training records (RBS card #1234, expires
 * 2027-01-01, etc.) — attaches to staff_members (the existing venue
 * roster), not users, since a certification can apply to a contractor or
 * staff member who has no Backstage login at all.
 *
 *   GET    /api/staff-certifications              list (optional ?staff_member_id=)
 *   POST   /api/staff-certifications               create
 *   PATCH  /api/staff-certifications/{id}          update
 *   DELETE /api/staff-certifications/{id}          delete
 *
 * Admin-only (manage_certifications) — see docs/staff/knowledge-audit.md
 * for why self-service isn't offered yet (verifying a physical card is a
 * manager task in this first pass).
 */
final class StaffCertifications extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_certifications')) {
            return $denied;
        }
        $id = $this->params['certId'] ?? null;
        return match ($request->method()) {
            'GET' => $id ? $this->show((int) $id) : $this->index($request),
            'POST' => $this->create($request),
            'PATCH' => $id ? $this->update($request, (int) $id) : $this->notFound('certId required'),
            'DELETE' => $id ? $this->delete((int) $id) : $this->notFound('certId required'),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];
        $staffMemberId = $request->query('staff_member_id');
        if ($staffMemberId) {
            $where[] = 'c.staff_member_id = ?';
            $params[] = (int) $staffMemberId;
        }
        $sql = 'SELECT c.*, t.name certification_name, t.slug certification_slug, t.expiration_required,
                       s.name staff_name
                FROM staff_certifications c
                JOIN staff_certification_types t ON t.id = c.certification_type_id
                JOIN staff_members s ON s.id = c.staff_member_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.expires_at IS NULL, c.expires_at, s.name';
        return $this->ok(['certifications' => $this->db->all($sql, $params)]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM staff_certifications WHERE id = ?', [$id]);
        return $row ? $this->ok(['certification' => $row]) : $this->notFound('Certification record not found');
    }

    private function create(Request $request): Response
    {
        $staffMemberId = $request->body('staff_member_id');
        $typeId = $request->body('certification_type_id');
        if (!$staffMemberId || !$typeId) {
            return Response::json(['error' => 'staff_member_id and certification_type_id are required'], 422);
        }
        $id = $this->db->insert(
            'INSERT INTO staff_certifications
                (staff_member_id, certification_type_id, issued_at, expires_at, certificate_number, document_path, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $staffMemberId,
                (int) $typeId,
                $request->body('issued_at') ?: null,
                $request->body('expires_at') ?: null,
                $request->body('certificate_number'),
                $request->body('document_path'),
                $request->body('notes'),
            ]
        );
        return $this->ok(['certification' => $this->db->one('SELECT * FROM staff_certifications WHERE id = ?', [$id])]);
    }

    private function update(Request $request, int $id): Response
    {
        $row = $this->db->one('SELECT * FROM staff_certifications WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Certification record not found');
        }
        $fields = ['issued_at', 'expires_at', 'certificate_number', 'document_path', 'notes'];
        $body = $request->body();
        $set = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $set[] = "$f = ?";
                $params[] = $body[$f] !== '' ? $body[$f] : null;
            }
        }
        if (array_key_exists('verify', $body) && $body['verify']) {
            $set[] = 'verified_at = NOW()';
            $set[] = 'verified_by = ?';
            $params[] = $this->userId();
        }
        if ($set) {
            $params[] = $id;
            $this->db->run('UPDATE staff_certifications SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
        }
        return $this->ok(['certification' => $this->db->one('SELECT * FROM staff_certifications WHERE id = ?', [$id])]);
    }

    private function delete(int $id): Response
    {
        $this->db->run('DELETE FROM staff_certifications WHERE id = ?', [$id]);
        return $this->ok(['deleted' => true]);
    }
}
