<?php
declare(strict_types=1);

namespace Panic\Tasks;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Standalone Tasks app — "task documents" (the left-sidebar project/list
 * entries in tasks-ui.png, e.g. "Q3 Marketing Campaign"). Independent of
 * events; see database/migrations/069_add_tasks_app.sql for the schema
 * rationale. The individual tasks within a document live under
 * Tasks\Items (/api/task-documents/{id}/tasks).
 *
 *   GET    /api/task-documents            list (+ counts, + assignable users)  (view_tasks_app)
 *   GET    /api/task-documents/{id}       one document                          (view_tasks_app)
 *   POST   /api/task-documents            create                                (manage_tasks_app)
 *   PATCH  /api/task-documents/{id}       rename/recolor/status/star/archive    (manage_tasks_app)
 *   DELETE /api/task-documents/{id}       delete (cascades tasks)               (manage_tasks_app)
 */
final class Documents extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $documentId = $this->params['documentId'] ?? null;

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_tasks_app')) {
                return $denied;
            }
            return $documentId ? $this->show((int) $documentId) : $this->index($request);
        }

        if ($denied = $this->requireGlobalCapability('manage_tasks_app')) {
            return $denied;
        }

        return match ($request->method()) {
            'POST' => $this->create($request),
            'PATCH' => $documentId ? $this->update($request, (int) $documentId) : Response::json(['error' => 'Document id is required'], 422),
            'DELETE' => $documentId ? $this->delete((int) $documentId) : Response::json(['error' => 'Document id is required'], 422),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $includeArchived = (string) $request->query('archived', '0') === '1';
        $documents = $this->db->all(
            'SELECT d.*, u.name AS owner_name,
                    (SELECT COUNT(*) FROM tasks t WHERE t.document_id = d.id) AS task_count,
                    (SELECT COUNT(*) FROM tasks t WHERE t.document_id = d.id AND t.status = \'done\') AS done_count
             FROM task_documents d
             LEFT JOIN users u ON u.id = d.owner_user_id
             WHERE ' . ($includeArchived ? '1=1' : 'd.archived_at IS NULL') . '
             ORDER BY d.starred DESC, d.sort_order, d.id'
        );
        return $this->ok([
            'documents' => $documents,
            'users' => $this->db->all('SELECT id, name, email FROM users WHERE is_hidden = 0 ORDER BY name'),
        ]);
    }

    private function show(int $id): Response
    {
        $document = $this->db->one(
            'SELECT d.*, u.name AS owner_name,
                    (SELECT COUNT(*) FROM tasks t WHERE t.document_id = d.id) AS task_count,
                    (SELECT COUNT(*) FROM tasks t WHERE t.document_id = d.id AND t.status = \'done\') AS done_count
             FROM task_documents d
             LEFT JOIN users u ON u.id = d.owner_user_id
             WHERE d.id = ?',
            [$id]
        );
        if (!$document) {
            return $this->notFound('Task document not found');
        }
        return $this->ok(['document' => $document]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'Name is required'], 422);
        }
        $nextOrder = (int) ($this->db->one('SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM task_documents')['n'] ?? 10);
        $id = $this->db->insert(
            'INSERT INTO task_documents (name, icon, color, status, owner_user_id, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $name,
                trim((string) $request->body('icon', '')) ?: 'fa-solid fa-list-check',
                trim((string) $request->body('color', '')) ?: '#2563eb',
                $this->normalizeStatus($request->body('status', 'on_track')),
                $this->userId(),
                $nextOrder,
            ]
        );
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        $existing = $this->db->one('SELECT id FROM task_documents WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Task document not found');
        }

        $fields = [];
        $values = [];
        foreach (['name', 'icon', 'color'] as $key) {
            if ($request->body($key) !== null) {
                $fields[] = "$key = ?";
                $values[] = trim((string) $request->body($key));
            }
        }
        if ($request->body('status') !== null) {
            $fields[] = 'status = ?';
            $values[] = $this->normalizeStatus($request->body('status'));
        }
        if ($request->body('starred') !== null) {
            $fields[] = 'starred = ?';
            $values[] = $request->body('starred') ? 1 : 0;
        }
        if ($request->body('archived') !== null) {
            $fields[] = 'archived_at = ?';
            $values[] = $request->body('archived') ? gmdate('Y-m-d H:i:s') : null;
        }
        if ($fields === []) {
            return $this->ok(['ok' => true]);
        }
        $values[] = $id;
        $this->db->run('UPDATE task_documents SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        $this->db->run('DELETE FROM task_documents WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = (string) $status;
        return in_array($status, ['on_track', 'at_risk', 'off_track', 'complete'], true) ? $status : 'on_track';
    }
}
