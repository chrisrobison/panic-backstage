<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Process instances — the "Live Cases" tab's data. Phase 1 ships this as a
 * read model over seeded demonstration rows (process_instances.is_demo = 1;
 * see database/migrations/066_add_process_automation.sql and the
 * "Event Booking" seed in database/migrations/067_seed_event_booking_
 * process.sql) — every row returned here is real data in the table, just
 * not yet produced by a live runtime, which is Phase 2. Operator actions
 * (retry/cancel/pause/resume/move) are intentionally NOT implemented here
 * yet for the same reason: there is no execution engine underneath them to
 * act on safely.
 *
 *   GET /api/processes/{id}/instances                 list + per-node counts (view_processes)
 *   GET /api/processes/{id}/instances/{instanceId}     one case + timeline    (view_processes)
 */
final class Instances extends BaseEndpoint
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
        $definition = $this->db->one('SELECT id FROM process_definitions WHERE id = ?', [$processId]);
        if (!$definition) {
            return $this->notFound('Process not found');
        }

        $instanceId = $this->params['instanceId'] ?? null;
        return $instanceId ? $this->show($processId, (int) $instanceId) : $this->index($request, $processId);
    }

    private function index(Request $request, int $processId): Response
    {
        $status = (string) $request->query('status', '');
        $node = (string) $request->query('node', '');
        $q = trim((string) $request->query('q', ''));

        $where = ['process_definition_id = ?'];
        $params = [$processId];
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($node !== '') {
            $where[] = 'current_node_id = ?';
            $params[] = $node;
        }
        if ($q !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $instances = $this->db->all(
            'SELECT i.*, u.name AS owner_name FROM process_instances i
             LEFT JOIN users u ON u.id = i.owner_user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (i.status = "overdue") DESC, i.updated_at DESC',
            $params
        );

        $nodeCounts = $this->db->all(
            'SELECT current_node_id, status, COUNT(*) AS n FROM process_instances
             WHERE process_definition_id = ? AND current_node_id IS NOT NULL
             GROUP BY current_node_id, status',
            [$processId]
        );
        $byNode = [];
        foreach ($nodeCounts as $row) {
            $byNode[$row['current_node_id']][$row['status']] = (int) $row['n'];
        }

        return $this->ok([
            'instances' => array_map([$this, 'castSummary'], $instances),
            'nodeCounts' => $byNode,
        ]);
    }

    private function show(int $processId, int $instanceId): Response
    {
        $instance = $this->db->one(
            'SELECT i.*, u.name AS owner_name FROM process_instances i
             LEFT JOIN users u ON u.id = i.owner_user_id
             WHERE i.id = ? AND i.process_definition_id = ?',
            [$instanceId, $processId]
        );
        if (!$instance) {
            return $this->notFound('Instance not found');
        }

        $events = $this->db->all(
            'SELECT * FROM process_instance_events WHERE process_instance_id = ? ORDER BY created_at ASC, id ASC',
            [$instanceId]
        );

        $version = $this->db->one('SELECT id, version_number, graph_json FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
        $graph = $version ? json_decode((string) $version['graph_json'], true) : null;

        return $this->ok([
            'instance' => $this->castDetail($instance),
            'events' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'node_id' => $row['node_id'],
                    'event_type' => $row['event_type'],
                    'label' => $row['label'],
                    'detail' => $row['detail'],
                    'actor' => $row['actor'],
                    'created_at' => $row['created_at'],
                ];
            }, $events),
            'graph' => is_array($graph) ? $graph : null,
            'version_number' => $version['version_number'] ?? null,
        ]);
    }

    private function castSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'current_node_id' => $row['current_node_id'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'owner_name' => $row['owner_name'],
            'due_at' => $row['due_at'],
            'is_demo' => (bool) $row['is_demo'],
            'started_at' => $row['started_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function castDetail(array $row): array
    {
        $summary = $this->castSummary($row);
        $variables = $row['variables_json'] ? json_decode((string) $row['variables_json'], true) : null;
        $summary['variables'] = is_array($variables) ? $variables : [];
        $summary['process_version_id'] = (int) $row['process_version_id'];
        $summary['completed_at'] = $row['completed_at'];
        return $summary;
    }
}
