<?php
declare(strict_types=1);

namespace Panic;

/**
 * Which staff role(s) a staff document is required/assigned for.
 *
 *   GET    /api/staff-doc-assignments             list all assignments
 *   POST   /api/staff-doc-assignments             create/update one (upsert by document_id+role_key)
 *   DELETE /api/staff-doc-assignments/{id}         remove one
 *
 * role_key is either a staff_members.default_role value (manager, security,
 * bartender, barback, door, sound, lighting, stagehand, runner, cleaner,
 * other) or the sentinel "all_staff" meaning every active staff member
 * regardless of role. Admin-only (manage_staff_docs).
 */
final class StaffDocAssignments extends BaseEndpoint
{
    private const VALID_ROLE_KEYS = [
        'all_staff', 'manager', 'security', 'bartender', 'barback', 'door',
        'sound', 'lighting', 'stagehand', 'runner', 'cleaner', 'other',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        $id = $this->params['assignmentId'] ?? null;
        return match ($request->method()) {
            'GET' => $this->index(),
            'POST' => $this->create($request),
            'DELETE' => $id ? $this->delete((int) $id) : $this->notFound('assignmentId required'),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $rows = $this->db->all(
            'SELECT a.*, d.slug document_slug, d.title document_title, d.document_type
             FROM staff_document_assignments a
             JOIN staff_documents d ON d.id = a.document_id
             ORDER BY d.title, a.role_key'
        );
        return $this->ok(['assignments' => $rows, 'role_keys' => self::VALID_ROLE_KEYS]);
    }

    private function create(Request $request): Response
    {
        $documentId = $request->body('document_id');
        $slug = $request->body('document_slug');
        $roleKey = (string) $request->body('role_key', '');
        $required = $request->body('required', true) ? 1 : 0;

        if (!$documentId && $slug) {
            $doc = $this->db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug]);
            $documentId = $doc['id'] ?? null;
        }
        if (!$documentId || !in_array($roleKey, self::VALID_ROLE_KEYS, true)) {
            return Response::json(['error' => 'document_id (or document_slug) and a valid role_key are required'], 422);
        }

        $existing = $this->db->one(
            'SELECT id FROM staff_document_assignments WHERE document_id = ? AND role_key = ?',
            [(int) $documentId, $roleKey]
        );
        if ($existing) {
            $this->db->run('UPDATE staff_document_assignments SET required = ? WHERE id = ?', [$required, (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert(
                'INSERT INTO staff_document_assignments (document_id, role_key, required) VALUES (?, ?, ?)',
                [(int) $documentId, $roleKey, $required]
            );
        }
        $row = $this->db->one('SELECT * FROM staff_document_assignments WHERE id = ?', [$id]);
        return $this->ok(['assignment' => $row]);
    }

    private function delete(int $id): Response
    {
        $this->db->run('DELETE FROM staff_document_assignments WHERE id = ?', [$id]);
        return $this->ok(['deleted' => true]);
    }
}
