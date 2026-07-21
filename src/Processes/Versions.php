<?php
declare(strict_types=1);

namespace Panic\Processes;

use Panic\BaseEndpoint;
use Panic\Processes;
use Panic\Request;
use Panic\Response;
use function Panic\log_process_audit;

/**
 * Process versions — the actual graph documents. See Processes.php for the
 * parent-definition endpoint and database/migrations/066_add_process_
 * automation.sql for the immutability rule this enforces: a version with
 * status='published' can never be PATCHed again; publishing a new draft
 * (or making one) is the only path forward. Existing process_instances keep
 * pointing at whatever version they started on.
 *
 *   GET   /api/processes/{id}/versions/{v}            full graph          (view_processes)
 *   POST  /api/processes/{id}/versions                new draft           (manage_processes)
 *   PATCH /api/processes/{id}/versions/{v}             save draft graph   (manage_processes)
 *   POST  /api/processes/{id}/versions/{v}/validate    validate (no save) (manage_processes)
 *   POST  /api/processes/{id}/versions/{v}/publish     freeze + publish   (manage_processes)
 */
final class Versions extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $processId = (int) ($this->params['processId'] ?? 0);
        $versionId = $this->params['versionId'] ?? null;
        $versionId = $versionId !== null ? (int) $versionId : null;
        $action = $this->params['action'] ?? null;

        $definition = $this->db->one('SELECT * FROM process_definitions WHERE id = ?', [$processId]);
        if (!$definition) {
            return $this->notFound('Process not found');
        }

        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_processes')) {
                return $denied;
            }
            if (!$versionId) {
                return Response::json(['error' => 'Version id is required'], 422);
            }
            return $this->show($processId, $versionId);
        }

        if ($denied = $this->requireGlobalCapability('manage_processes')) {
            return $denied;
        }

        if ($request->method() !== 'POST' && $request->method() !== 'PATCH') {
            return Response::methodNotAllowed();
        }

        if ($action === 'validate' && $versionId) {
            return $this->validateVersion($request, $processId, $versionId);
        }
        if ($action === 'publish' && $versionId) {
            return $this->publish($request, $definition, $versionId);
        }
        if ($request->method() === 'POST' && !$versionId) {
            return $this->createDraft($request, $definition);
        }
        if ($request->method() === 'PATCH' && $versionId) {
            return $this->saveDraft($request, $processId, $versionId);
        }

        return Response::json(['error' => 'Unsupported version action'], 422);
    }

    private function show(int $processId, int $versionId): Response
    {
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$versionId, $processId]);
        if (!$version) {
            return $this->notFound('Version not found');
        }
        return $this->ok(['version' => $this->cast($version)]);
    }

    private function createDraft(Request $request, array $definition): Response
    {
        $processId = (int) $definition['id'];
        $fromVersionId = $request->body('fromVersionId') ? (int) $request->body('fromVersionId') : null;

        $source = $fromVersionId
            ? $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$fromVersionId, $processId])
            : ($this->db->one("SELECT * FROM process_versions WHERE process_definition_id = ? AND status = 'draft' ORDER BY version_number DESC LIMIT 1", [$processId])
                ?? ($definition['current_published_version_id'] ? $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$definition['current_published_version_id']]) : null));

        $graphJson = $source ? $source['graph_json'] : json_encode(Processes::defaultGraph($definition['name']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $maxVersion = $this->db->one('SELECT MAX(version_number) AS n FROM process_versions WHERE process_definition_id = ?', [$processId]);
        $nextVersion = ((int) ($maxVersion['n'] ?? 0)) + 1;

        $versionId = $this->db->insert(
            'INSERT INTO process_versions (process_definition_id, version_number, status, graph_json, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$processId, $nextVersion, 'draft', $graphJson, $request->body('note') ?: 'New draft', $this->userId()]
        );

        log_process_audit($this->db, $processId, $versionId, $this->userId(), 'draft_created', [], ['version_number' => $nextVersion]);

        return $this->ok(['id' => $versionId, 'version_number' => $nextVersion]);
    }

    private function saveDraft(Request $request, int $processId, int $versionId): Response
    {
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$versionId, $processId]);
        if (!$version) {
            return $this->notFound('Version not found');
        }
        if ($version['status'] !== 'draft') {
            return Response::json(['error' => 'This version is published and immutable — create a new draft to keep editing.'], 409);
        }

        $graph = $request->body('graph');
        if (!is_array($graph)) {
            return Response::json(['error' => 'graph is required'], 422);
        }

        $this->db->run(
            'UPDATE process_versions SET graph_json = ?, note = COALESCE(?, note) WHERE id = ?',
            [json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $request->body('note') ?: null, $versionId]
        );

        log_process_audit($this->db, $processId, $versionId, $this->userId(), 'draft_saved');

        return $this->ok(['ok' => true]);
    }

    private function validateVersion(Request $request, int $processId, int $versionId): Response
    {
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$versionId, $processId]);
        if (!$version) {
            return $this->notFound('Version not found');
        }
        $graph = is_array($request->body('graph')) ? $request->body('graph') : json_decode((string) $version['graph_json'], true);
        $result = GraphValidator::validate(is_array($graph) ? $graph : []);
        return $this->ok($result);
    }

    private function publish(Request $request, array $definition, int $versionId): Response
    {
        $processId = (int) $definition['id'];
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ? AND process_definition_id = ?', [$versionId, $processId]);
        if (!$version) {
            return $this->notFound('Version not found');
        }
        if ($version['status'] !== 'draft') {
            return Response::json(['error' => 'Version is not a draft'], 409);
        }

        $graph = json_decode((string) $version['graph_json'], true);
        $result = GraphValidator::validate(is_array($graph) ? $graph : []);
        if (!empty($result['errors'])) {
            return Response::json(['error' => 'Validation failed', 'validation' => $result], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->run(
                "UPDATE process_versions SET status = 'published', published_at = NOW(), published_by = ?, validation_json = ? WHERE id = ?",
                [$this->userId(), json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $versionId]
            );
            $this->db->run('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?', [$versionId, $processId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        log_process_audit($this->db, $processId, $versionId, $this->userId(), 'published', [], ['version_number' => $version['version_number']], $request->body('note') ?: null);

        return $this->ok(['ok' => true, 'validation' => $result]);
    }

    private function cast(array $row): array
    {
        $graph = json_decode((string) $row['graph_json'], true);
        return [
            'id' => (int) $row['id'],
            'process_definition_id' => (int) $row['process_definition_id'],
            'version_number' => (int) $row['version_number'],
            'status' => $row['status'],
            'note' => $row['note'],
            'published_at' => $row['published_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'graph' => is_array($graph) ? $graph : [],
        ];
    }
}
