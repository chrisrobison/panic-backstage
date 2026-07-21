<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Cross-process "what automation is running against this real record"
 * lookup. This is the piece that lets ANY page in the app — not just the
 * Automation section — show and act on a process instance's current step:
 * the Event workspace embeds <pb-process-step-form> driven by whatever
 * this returns (see public/assets/event-workspace.js's automation card),
 * with zero automation-specific knowledge added to Events.php itself.
 *
 *   GET /api/process-instances?entityType=event&entityId=123   (view_processes)
 */
final class EntityInstances extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('view_processes')) {
            return $denied;
        }

        $entityType = trim((string) $request->query('entityType', ''));
        $entityId = (int) $request->query('entityId', 0);
        if ($entityType === '' || $entityId <= 0) {
            return Response::json(['error' => 'entityType and entityId are required'], 422);
        }

        $instances = $this->db->all(
            "SELECT i.*, d.name AS process_name
             FROM process_instances i
             JOIN process_definitions d ON d.id = i.process_definition_id
             WHERE i.entity_type = ? AND i.entity_id = ?
             ORDER BY (i.status NOT IN ('completed','canceled')) DESC, i.started_at DESC",
            [$entityType, $entityId]
        );

        return $this->ok(['instances' => array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'process_definition_id' => (int) $row['process_definition_id'],
                'process_name' => $row['process_name'],
                'name' => $row['name'],
                'status' => $row['status'],
                'current_node_id' => $row['current_node_id'],
                'started_at' => $row['started_at'],
            ];
        }, $instances)]);
    }
}
