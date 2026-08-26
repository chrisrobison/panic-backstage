<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Database;
use Panic\Request;
use Panic\Response;

/**
 * Lazily provisions one `task_documents` row per opportunity/conference/
 * company record the first time a task is created from it, per the §1.11
 * decision in docs/OPPORTUNITIES-IMPLEMENTATION.md: no new tasks table, no
 * polymorphic columns on `tasks`/`event_tasks` — a task created from a
 * conference stays an ordinary Backstage task, served by the existing,
 * unmodified `/api/task-documents/{id}/tasks` endpoints
 * (src/Tasks/Items.php). This class only owns the one-time
 * provision-and-link step; it never touches `tasks` rows itself.
 *
 *   GET  /api/opportunity-conferences/{id}/tasks   {task_document_id: int|null}
 *   POST /api/opportunity-conferences/{id}/tasks    ensure one exists (idempotent),
 *                                                    return its id
 *
 * Only wired for conferences in Phase 3 (`opportunity_companies`.task_document_id
 * and `opportunities`.task_document_id already exist from the Phase 1
 * migration and this class already supports both owner types — Phase 4/5
 * just need to add their own Kernel route, not touch this file).
 *
 * Capabilities: view_opportunities (read), manage_opportunities (provision).
 */
final class TaskLink extends BaseEndpoint
{
    private const OWNER_TABLES = [
        'conference'  => 'opportunity_conferences',
        'company'     => 'opportunity_companies',
        'opportunity' => 'opportunities',
    ];

    public function handle(Request $request): Response
    {
        $ownerType = (string) ($this->params['ownerType'] ?? '');
        $ownerId   = (int) ($this->params['ownerId'] ?? 0);

        if (!isset(self::OWNER_TABLES[$ownerType]) || $ownerId <= 0) {
            return $this->notFound('Unknown task-link owner');
        }

        return match ($request->method()) {
            'GET'   => $this->show($ownerType, $ownerId),
            'POST'  => $this->ensure($ownerType, $ownerId),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(string $ownerType, int $ownerId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        $row = $this->findOwner($ownerType, $ownerId);
        if (!$row) {
            return $this->notFound(ucfirst($ownerType) . ' not found');
        }
        return $this->ok(['task_document_id' => $row['task_document_id'] !== null ? (int) $row['task_document_id'] : null]);
    }

    private function ensure(string $ownerType, int $ownerId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        $row = $this->findOwner($ownerType, $ownerId);
        if (!$row) {
            return $this->notFound(ucfirst($ownerType) . ' not found');
        }

        if ($row['task_document_id'] !== null) {
            return $this->ok(['task_document_id' => (int) $row['task_document_id'], 'created' => false]);
        }

        $documentId = $this->db->insert(
            "INSERT INTO task_documents (name, icon, color, status, owner_user_id, sort_order)
             VALUES (?, 'fa-solid fa-bullseye', '#2563eb', 'on_track', ?, 0)",
            [substr((string) $row['name'] . ' — Tasks', 0, 190), $this->userId()]
        );

        $table = self::OWNER_TABLES[$ownerType];
        // Re-check-then-set under the row's own uniqueness (id PK), matching
        // the "lock-then-recheck" spirit used elsewhere in this codebase for
        // exactly-once provisioning — a genuinely concurrent double-click is
        // vanishingly unlikely here (single interactive user per record) so
        // a plain UPDATE ... WHERE task_document_id IS NULL is enough: if it
        // affects 0 rows a concurrent request already won, and we fall back
        // to reading whatever id it set rather than leaving an orphaned
        // extra task_documents row referenced by nothing.
        $affected = $this->db->run(
            "UPDATE `$table` SET task_document_id = ? WHERE id = ? AND task_document_id IS NULL",
            [$documentId, $ownerId]
        );
        if ($affected === 0) {
            $existing = $this->findOwner($ownerType, $ownerId);
            return $this->ok(['task_document_id' => (int) $existing['task_document_id'], 'created' => false]);
        }

        return $this->ok(['task_document_id' => $documentId, 'created' => true]);
    }

    private function findOwner(string $ownerType, int $ownerId): ?array
    {
        $table = self::OWNER_TABLES[$ownerType];
        return $this->db->one("SELECT id, name, task_document_id FROM `$table` WHERE id = ?", [$ownerId]);
    }

    /**
     * Open (not-done) task count + overdue-of-those count for one lazily-
     * provisioned task_documents row — Phase 8 (spec: "show task counts and
     * overdue status in relevant views"). Shared static helper so
     * Conferences::show()/Companies::show() (and any future owner type)
     * don't each duplicate the same small query.
     *
     * @return array{task_count:int, overdue_task_count:int}
     */
    public static function taskCounts(Database $db, ?int $taskDocumentId): array
    {
        if (!$taskDocumentId) {
            return ['task_count' => 0, 'overdue_task_count' => 0];
        }
        $row = $db->one(
            "SELECT COUNT(*) task_count,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) overdue_task_count
             FROM tasks WHERE document_id = ? AND status != 'done'",
            [$taskDocumentId]
        );
        return [
            'task_count'         => (int) ($row['task_count'] ?? 0),
            'overdue_task_count' => (int) ($row['overdue_task_count'] ?? 0),
        ];
    }
}
