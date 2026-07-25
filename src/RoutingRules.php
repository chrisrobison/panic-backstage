<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_lead_activity;

/**
 * Routing-rule administration (database/migrations/075_add_booking_inbox_routing.sql).
 *
 *   GET    /api/routing-rules                          list rules + their current published version
 *   POST   /api/routing-rules                          create a rule (starts with no published version)
 *   GET    /api/routing-rules/{id}                      one rule + all its versions
 *   PATCH  /api/routing-rules/{id}                       name/description/priority/is_active
 *   POST   /api/routing-rules/{id}/versions              new draft version
 *   POST   /api/routing-rules/{id}/versions/{v}/publish   publish a draft — freezes it, same
 *                                                          immutable-once-published contract as
 *                                                          process_versions (see RoutingEngine.php)
 *
 * manage_lead_routing only — this is exactly the "modify routing rules"
 * capability the spec reserves for the Venue administrator; neither the
 * Trusted booker nor the Restricted external booker role has it (a manager
 * can still override any *individual* assignment via
 * POST /api/leads/{id}/reassign without touching the rules themselves).
 */
final class RoutingRules extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $canManageRouting = $this->hasGlobalCapability('manage_lead_routing');
        if (!$canManageRouting) {
            // Read access is slightly wider than write access: anyone who can
            // see the Inbox at all may look at what rules exist (to
            // understand a routing explanation), just not change them.
            if ($request->method() !== 'GET') {
                return $this->forbidden('Only a venue administrator can modify routing rules');
            }
            if ($denied = $this->requireGlobalCapability('view_booking_inbox')) {
                return $denied;
            }
        }

        $ruleId = $this->params['ruleId'] ?? null;
        $child = $this->params['child'] ?? null;
        $versionNumber = $this->params['versionNumber'] ?? null;
        $action = $this->params['action'] ?? null;

        if ($child === 'versions' && $action === 'publish' && $versionNumber !== null) {
            return $this->publish($request, (int) $ruleId, (int) $versionNumber);
        }
        if ($child === 'versions') {
            return $this->createVersion($request, (int) $ruleId);
        }

        return match ($request->method()) {
            'GET' => $ruleId ? $this->show((int) $ruleId) : $this->index(),
            'POST' => $this->create($request),
            'PATCH' => $this->update($request, (int) $ruleId),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $rules = $this->db->all(
            "SELECT rr.*, rv.conditions_json, rv.action_json, rv.version_number published_version_number
             FROM routing_rules rr
             LEFT JOIN routing_rule_versions rv ON rv.id = rr.current_published_version_id
             ORDER BY rr.priority ASC, rr.id ASC"
        );
        return $this->ok(['rules' => $rules]);
    }

    private function show(int $id): Response
    {
        $rule = $this->db->one('SELECT * FROM routing_rules WHERE id = ?', [$id]);
        if ($rule === null) {
            return $this->notFound('Routing rule not found');
        }
        $versions = $this->db->all('SELECT * FROM routing_rule_versions WHERE routing_rule_id = ? ORDER BY version_number DESC', [$id]);
        return $this->ok(['rule' => $rule, 'versions' => $versions]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }
        $id = $this->db->insert(
            'INSERT INTO routing_rules (name, description, is_active, priority, created_by_id) VALUES (?,?,?,?,?)',
            [$name, $request->body('description'), $request->body('is_active', true) ? 1 : 0, (int) $request->body('priority', 100), $this->userId()]
        );
        log_lead_activity($this->db, null, $this->userId(), 'routing_rule_created', ['rule_id' => $id, 'name' => $name]);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        $rule = $this->db->one('SELECT * FROM routing_rules WHERE id = ?', [$id]);
        if ($rule === null) {
            return $this->notFound('Routing rule not found');
        }
        $sets = [];
        $params = [];
        foreach (['name', 'description', 'priority'] as $field) {
            if ($request->body($field) !== null) {
                $sets[] = "$field = ?";
                $params[] = $request->body($field);
            }
        }
        if ($request->body('is_active') !== null) {
            $sets[] = 'is_active = ?';
            $params[] = $request->body('is_active') ? 1 : 0;
        }
        if ($sets === []) {
            return $this->ok(['ok' => true]);
        }
        $params[] = $id;
        $this->db->run('UPDATE routing_rules SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        log_lead_activity($this->db, null, $this->userId(), 'routing_rule_updated', ['rule_id' => $id, 'changes' => $request->body()]);
        return $this->ok(['ok' => true]);
    }

    private function createVersion(Request $request, int $ruleId): Response
    {
        $rule = $this->db->one('SELECT * FROM routing_rules WHERE id = ?', [$ruleId]);
        if ($rule === null) {
            return $this->notFound('Routing rule not found');
        }
        $conditions = $request->body('conditions', []);
        $action = $request->body('action', []);
        if (!is_array($conditions) || !is_array($action) || $action === []) {
            return Response::json(['error' => 'conditions (object) and action (object) are required'], 422);
        }

        $nextVersion = (int) ($this->db->one('SELECT COALESCE(MAX(version_number), 0) + 1 v FROM routing_rule_versions WHERE routing_rule_id = ?', [$ruleId])['v'] ?? 1);

        $versionId = $this->db->insert(
            'INSERT INTO routing_rule_versions (routing_rule_id, version_number, status, conditions_json, action_json, note, created_by_id)
             VALUES (?,?,?,?,?,?,?)',
            [$ruleId, $nextVersion, 'draft', json_encode($conditions), json_encode($action), $request->body('note'), $this->userId()]
        );
        log_lead_activity($this->db, null, $this->userId(), 'routing_rule_version_drafted', ['rule_id' => $ruleId, 'version_id' => $versionId]);
        return $this->ok(['id' => $versionId, 'version_number' => $nextVersion]);
    }

    private function publish(Request $request, int $ruleId, int $versionNumber): Response
    {
        $version = $this->db->one('SELECT * FROM routing_rule_versions WHERE routing_rule_id = ? AND version_number = ?', [$ruleId, $versionNumber]);
        if ($version === null) {
            return $this->notFound('Routing rule version not found');
        }
        if ($version['status'] === 'published') {
            return Response::json(['error' => 'This version is already published'], 409);
        }

        $this->db->run("UPDATE routing_rule_versions SET status = 'published', published_at = NOW(), published_by_id = ? WHERE id = ?", [$this->userId(), $version['id']]);
        $this->db->run('UPDATE routing_rules SET current_published_version_id = ? WHERE id = ?', [$version['id'], $ruleId]);
        log_lead_activity($this->db, null, $this->userId(), 'routing_rule_published', ['rule_id' => $ruleId, 'version_id' => $version['id']]);

        return $this->ok(['published' => true]);
    }
}
