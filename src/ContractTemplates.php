<?php
declare(strict_types=1);

namespace Panic;

/**
 * Contract template admin (venue admins only). A template is an ordered set of
 * clause modules with optional include_when conditions for smart selection.
 *
 *   GET    /api/contract-templates           list templates (+ available modules)
 *   GET    /api/contract-templates/{id}       one template with its module wiring
 *   POST   /api/contract-templates            create
 *   PATCH  /api/contract-templates/{id}       update (optionally replace wiring)
 *   DELETE /api/contract-templates/{id}       delete
 *
 * When the body contains a `modules` array, the template's wiring is rebuilt:
 *   modules: [ { module_id, is_required, sort_order, condition_json }, ... ]
 */
final class ContractTemplates extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_contract_library')) {
            return $denied;
        }
        $id = $this->params['templateId'] ?? null;
        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index(),
            'POST'   => $this->save($request, null),
            'PATCH'  => $this->save($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $templates = $this->db->all('SELECT * FROM contract_templates ORDER BY name');
        foreach ($templates as &$t) {
            $t['module_count'] = (int) ($this->db->one('SELECT COUNT(*) AS c FROM contract_template_modules WHERE template_id = ?', [(int) $t['id']])['c'] ?? 0);
        }
        unset($t);
        return $this->ok([
            'templates' => $templates,
            'modules'   => $this->db->all('SELECT id, module_key, name, category, risk_level FROM contract_modules WHERE is_active = 1 ORDER BY category, sort_order, name'),
            'types'     => ContractRenderer::CONTRACT_TYPES,
        ]);
    }

    private function show(int $id): Response
    {
        $template = $this->db->one('SELECT * FROM contract_templates WHERE id = ?', [$id]);
        if (!$template) {
            return $this->notFound('Template not found');
        }
        $template['modules'] = $this->db->all(
            'SELECT tm.id, tm.module_id, tm.sort_order, tm.is_required, tm.condition_json, m.name, m.module_key, m.category, m.risk_level
             FROM contract_template_modules tm JOIN contract_modules m ON m.id = tm.module_id
             WHERE tm.template_id = ? ORDER BY tm.sort_order',
            [$id]
        );
        return $this->ok([
            'template' => $template,
            'modules'  => $this->db->all('SELECT id, module_key, name, category, risk_level FROM contract_modules WHERE is_active = 1 ORDER BY category, sort_order, name'),
            'types'    => ContractRenderer::CONTRACT_TYPES,
        ]);
    }

    private function save(Request $request, ?int $id): Response
    {
        $b = $request->body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }
        $type = in_array($b['contract_type'] ?? '', ContractRenderer::CONTRACT_TYPES, true) ? $b['contract_type'] : 'other';
        $desc = trim((string) ($b['description'] ?? '')) ?: null;
        $intro = array_key_exists('intro_text', $b) ? (trim((string) $b['intro_text']) ?: null) : null;
        $active = array_key_exists('is_active', $b) ? boolish($b['is_active']) : 1;

        if ($id) {
            if (!$this->db->one('SELECT id FROM contract_templates WHERE id = ?', [$id])) {
                return $this->notFound('Template not found');
            }
            $this->db->run('UPDATE contract_templates SET name=?, description=?, contract_type=?, intro_text=?, is_active=? WHERE id=?', [$name, $desc, $type, $intro, $active, $id]);
            $templateId = $id;
        } else {
            $templateId = $this->db->insert('INSERT INTO contract_templates (name, description, contract_type, intro_text, is_active) VALUES (?, ?, ?, ?, ?)', [$name, $desc, $type, $intro, $active]);
        }

        if (array_key_exists('modules', $b) && is_array($b['modules'])) {
            $this->rebuildWiring($templateId, $b['modules']);
        }

        return $this->ok($id ? ['ok' => true] : ['id' => $templateId]);
    }

    private function rebuildWiring(int $templateId, array $modules): void
    {
        $this->db->run('DELETE FROM contract_template_modules WHERE template_id = ?', [$templateId]);
        $order = 0;
        foreach ($modules as $m) {
            $moduleId = (int) ($m['module_id'] ?? 0);
            if (!$moduleId || !$this->db->one('SELECT id FROM contract_modules WHERE id = ?', [$moduleId])) {
                continue;
            }
            $condition = $m['condition_json'] ?? null;
            if (is_array($condition)) {
                $condition = $condition === [] ? null : json_encode($condition);
            } elseif (is_string($condition) && trim($condition) !== '') {
                $decoded = json_decode($condition, true);
                $condition = is_array($decoded) ? json_encode($decoded) : null;
            } else {
                $condition = null;
            }
            $this->db->run(
                'INSERT INTO contract_template_modules (template_id, module_id, sort_order, is_required, condition_json) VALUES (?, ?, ?, ?, ?)',
                [$templateId, $moduleId, (int) ($m['sort_order'] ?? $order), boolish($m['is_required'] ?? 0), $condition]
            );
            $order++;
        }
    }

    private function delete(int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        $this->db->run('DELETE FROM contract_templates WHERE id = ?', [$id]);
        return Response::noContent();
    }
}
