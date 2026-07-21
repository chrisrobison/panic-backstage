<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_process_audit;
use function Panic\slugify;

/**
 * Process definitions — Automation > Processes (see database/migrations/
 * 066_add_process_automation.sql and docs/AGENTS-relevant notes on the
 * process-graph engine). A "process definition" is the named, versioned
 * container ("Event Booking"); the actual graph document lives on its
 * process_versions rows (see Processes/Versions.php) — published versions
 * are immutable, editing always happens on a draft.
 *
 *   GET    /api/processes          list, with instance status counts   (view_processes)
 *   POST   /api/processes          create a new definition + draft v1  (manage_processes)
 *   GET    /api/processes/{id}     definition + its versions summary   (view_processes)
 *   PATCH  /api/processes/{id}     rename/describe/archive             (manage_processes)
 *   DELETE /api/processes/{id}     delete (cascades versions/instances) (manage_processes)
 */
final class Processes extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $processId = $this->intOrNullParam('processId');

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_processes')) {
                return $denied;
            }
            return $processId ? $this->show($processId) : $this->index();
        }

        if ($denied = $this->requireGlobalCapability('manage_processes')) {
            return $denied;
        }

        return match ($request->method()) {
            'POST' => $this->create($request),
            'PATCH' => $processId ? $this->update($request, $processId) : Response::json(['error' => 'Process id is required'], 422),
            'DELETE' => $processId ? $this->deleteDefinition($processId) : Response::json(['error' => 'Process id is required'], 422),
            default => Response::methodNotAllowed(),
        };
    }

    private function intOrNullParam(string $key): ?int
    {
        $value = $this->params[$key] ?? null;
        return $value !== null ? (int) $value : null;
    }

    private function index(): Response
    {
        $definitions = $this->db->all(
            'SELECT d.*, pv.version_number AS published_version_number, pv.published_at AS published_version_published_at
             FROM process_definitions d
             LEFT JOIN process_versions pv ON pv.id = d.current_published_version_id
             WHERE d.archived = 0
             ORDER BY d.name'
        );

        $counts = $this->db->all(
            "SELECT process_definition_id, status, COUNT(*) AS n FROM process_instances GROUP BY process_definition_id, status"
        );
        $countsByDefinition = [];
        foreach ($counts as $row) {
            $countsByDefinition[(int) $row['process_definition_id']][$row['status']] = (int) $row['n'];
        }

        $out = array_map(function (array $row) use ($countsByDefinition) {
            return $this->castDefinition($row, $countsByDefinition[(int) $row['id']] ?? []);
        }, $definitions);

        return $this->ok(['processes' => $out, 'capabilities' => $this->processCapabilities()]);
    }

    private function show(int $id): Response
    {
        $definition = $this->db->one('SELECT * FROM process_definitions WHERE id = ?', [$id]);
        if (!$definition) {
            return $this->notFound('Process not found');
        }

        $versions = $this->db->all(
            'SELECT id, version_number, status, note, published_at, published_by, created_by, created_at, updated_at
             FROM process_versions WHERE process_definition_id = ? ORDER BY version_number DESC',
            [$id]
        );

        $draft = $this->db->one(
            "SELECT * FROM process_versions WHERE process_definition_id = ? AND status = 'draft' ORDER BY version_number DESC LIMIT 1",
            [$id]
        );
        $published = $definition['current_published_version_id']
            ? $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$definition['current_published_version_id']])
            : null;

        $counts = $this->db->all(
            'SELECT status, COUNT(*) AS n FROM process_instances WHERE process_definition_id = ? GROUP BY status',
            [$id]
        );
        $countsMap = [];
        foreach ($counts as $row) {
            $countsMap[$row['status']] = (int) $row['n'];
        }

        return $this->ok([
            'process' => $this->castDefinition($definition, $countsMap),
            'versions' => array_map([$this, 'castVersionSummary'], $versions),
            'draftVersion' => $draft ? $this->castVersion($draft) : null,
            'publishedVersion' => $published ? $this->castVersion($published) : null,
            'assignableUsers' => $this->db->all("SELECT id, name, email, role FROM users WHERE is_hidden = 0 ORDER BY name"),
            'capabilities' => $this->processCapabilities(),
        ]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }

        $baseSlug = slugify($name);
        $slug = $baseSlug;
        $suffix = 2;
        while ($this->db->one('SELECT id FROM process_definitions WHERE key_slug = ?', [$slug])) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $id = $this->db->insert(
            'INSERT INTO process_definitions (key_slug, name, description, category, created_by) VALUES (?, ?, ?, ?, ?)',
            [$slug, $name, $request->body('description') ?: null, $request->body('category') ?: null, $this->userId()]
        );

        $graph = self::defaultGraph($name);
        $versionId = $this->db->insert(
            'INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, note, created_by) VALUES (?, 1, ?, ?, ?, ?)',
            [$id, 'draft', json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Initial draft', $this->userId()]
        );

        log_process_audit($this->db, $id, $versionId, $this->userId(), 'definition_created', [], ['name' => $name]);

        return $this->ok(['id' => $id, 'draftVersionId' => $versionId]);
    }

    private function update(Request $request, int $id): Response
    {
        $existing = $this->db->one('SELECT id FROM process_definitions WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Process not found');
        }

        $fields = [];
        $values = [];
        foreach (['name', 'description', 'category'] as $key) {
            if ($request->body($key) !== null) {
                $fields[] = "$key = ?";
                $values[] = trim((string) $request->body($key)) ?: null;
            }
        }
        if ($request->body('archived') !== null) {
            $fields[] = 'archived = ?';
            $values[] = boolish($request->body('archived'));
        }
        if (!$fields) {
            return Response::json(['error' => 'No fields to update'], 422);
        }
        $values[] = $id;
        $this->db->run('UPDATE process_definitions SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);
        return $this->ok(['ok' => true]);
    }

    private function deleteDefinition(int $id): Response
    {
        $existing = $this->db->one('SELECT id FROM process_definitions WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Process not found');
        }
        $this->db->run('DELETE FROM process_definitions WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function processCapabilities(): array
    {
        return [
            'view_processes' => $this->hasGlobalCapability('view_processes') || $this->hasGlobalCapability('manage_processes'),
            'manage_processes' => $this->hasGlobalCapability('manage_processes'),
        ];
    }

    private function castDefinition(array $row, array $instanceCounts): array
    {
        return [
            'id' => (int) $row['id'],
            'key_slug' => $row['key_slug'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            'archived' => (bool) $row['archived'],
            'current_published_version_id' => $row['current_published_version_id'] !== null ? (int) $row['current_published_version_id'] : null,
            'published_version_number' => $row['published_version_number'] ?? null,
            'published_at' => $row['published_version_published_at'] ?? $row['published_at'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'instance_counts' => [
                'active' => $instanceCounts['active'] ?? 0,
                'waiting' => $instanceCounts['waiting'] ?? 0,
                'overdue' => $instanceCounts['overdue'] ?? 0,
                'failed' => $instanceCounts['failed'] ?? 0,
                'completed' => $instanceCounts['completed'] ?? 0,
                'canceled' => $instanceCounts['canceled'] ?? 0,
            ],
        ];
    }

    private function castVersionSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'version_number' => (int) $row['version_number'],
            'status' => $row['status'],
            'note' => $row['note'],
            'published_at' => $row['published_at'],
            'published_by' => $row['published_by'] !== null ? (int) $row['published_by'] : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function castVersion(array $row): array
    {
        $summary = $this->castVersionSummary($row);
        $graph = json_decode((string) $row['graph_json'], true);
        $summary['graph'] = is_array($graph) ? $graph : self::defaultGraph('Untitled Process');
        $validation = $row['validation_json'] ? json_decode((string) $row['validation_json'], true) : null;
        $summary['validation'] = is_array($validation) ? $validation : null;
        return $summary;
    }

    /** The empty graph document a brand-new draft starts from. Kept in sync
     *  conceptually with public/assets/processes/graph-schema.js's
     *  createEmptyGraph() — this is the server-side default used only at
     *  definition-creation time; every other read/write round-trips
     *  whatever the client already normalized. */
    public static function defaultGraph(string $name): array
    {
        return [
            'schemaVersion' => 1,
            'meta' => ['name' => $name, 'description' => ''],
            'nodes' => [],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'variables' => [],
            'permissions' => [],
            'runtimePolicy' => [],
        ];
    }
}
