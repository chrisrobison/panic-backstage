<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Read-only audit trail for one process definition — draft saves, publishes,
 * and (once Phase 2 lands) manual instance interventions. Backs the History
 * tab alongside the version list already returned by Processes::show().
 *
 *   GET /api/processes/{id}/audit   (view_processes)
 */
final class Audit extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('view_processes')) {
            return $denied;
        }

        $processId = (int) ($this->params['processId'] ?? 0);
        if (!$this->db->one('SELECT id FROM process_definitions WHERE id = ?', [$processId])) {
            return $this->notFound('Process not found');
        }

        $entries = $this->db->all(
            'SELECT a.*, u.name AS actor_name, v.version_number
             FROM process_audit_log a
             LEFT JOIN users u ON u.id = a.actor_user_id
             LEFT JOIN process_versions v ON v.id = a.process_version_id
             WHERE a.process_definition_id = ?
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 200',
            [$processId]
        );

        return $this->ok(['entries' => array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'action' => $row['action'],
                'actor_name' => $row['actor_name'],
                'version_number' => $row['version_number'] !== null ? (int) $row['version_number'] : null,
                'note' => $row['note'],
                'before' => $row['before_json'] ? json_decode((string) $row['before_json'], true) : null,
                'after' => $row['after_json'] ? json_decode((string) $row['after_json'], true) : null,
                'created_at' => $row['created_at'],
            ];
        }, $entries)]);
    }
}
