<?php
declare(strict_types=1);

namespace Panic;

/**
 * Clause library admin (venue admins only).
 *
 *   GET    /api/contract-modules            list all modules
 *   POST   /api/contract-modules            create a clause
 *   PATCH  /api/contract-modules/{id}       update a clause
 *   DELETE /api/contract-modules/{id}       delete a clause
 */
final class ContractModules extends BaseEndpoint
{
    private const CATEGORIES = ['base', 'financial', 'operational', 'legal', 'risk'];
    private const RISK_LEVELS = ['none', 'low', 'medium', 'high'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_contract_library')) {
            return $denied;
        }
        $id = $this->params['moduleId'] ?? null;
        return match ($request->method()) {
            'GET'    => $this->ok([
                'modules'     => $this->db->all('SELECT * FROM contract_modules ORDER BY category, sort_order, name'),
                'categories'  => self::CATEGORIES,
                'risk_levels' => self::RISK_LEVELS,
            ]),
            'POST'   => $this->save($request, null),
            'PATCH'  => $this->save($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function save(Request $request, ?int $id): Response
    {
        $b = $request->body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }
        $body = (string) ($b['body_template'] ?? '');
        $category = in_array($b['category'] ?? '', self::CATEGORIES, true) ? $b['category'] : 'operational';
        $risk = in_array($b['risk_level'] ?? '', self::RISK_LEVELS, true) ? $b['risk_level'] : 'none';
        $required = $this->normalizeFields($b['required_fields_json'] ?? ($b['required_fields'] ?? null));
        $locked = boolish($b['is_locked'] ?? 0);
        $active = array_key_exists('is_active', $b) ? boolish($b['is_active']) : 1;
        $sort = (int) ($b['sort_order'] ?? 0);

        if ($id) {
            $existing = $this->db->one('SELECT id FROM contract_modules WHERE id = ?', [$id]);
            if (!$existing) {
                return $this->notFound('Module not found');
            }
            $key = trim((string) ($b['module_key'] ?? ''));
            if ($key !== '' && $this->db->one('SELECT id FROM contract_modules WHERE module_key = ? AND id <> ?', [$key, $id])) {
                return Response::json(['error' => 'module_key already in use'], 422);
            }
            if ($key === '') {
                $this->db->run(
                    'UPDATE contract_modules SET name=?, category=?, body_template=?, required_fields_json=?, risk_level=?, is_locked=?, is_active=?, sort_order=? WHERE id=?',
                    [$name, $category, $body, $required, $risk, $locked, $active, $sort, $id]
                );
            } else {
                $this->db->run(
                    'UPDATE contract_modules SET module_key=?, name=?, category=?, body_template=?, required_fields_json=?, risk_level=?, is_locked=?, is_active=?, sort_order=? WHERE id=?',
                    [$key, $name, $category, $body, $required, $risk, $locked, $active, $sort, $id]
                );
            }
            return $this->ok(['ok' => true]);
        }

        $key = trim((string) ($b['module_key'] ?? '')) ?: slugify($name);
        if ($this->db->one('SELECT id FROM contract_modules WHERE module_key = ?', [$key])) {
            return Response::json(['error' => "module_key '$key' already exists"], 422);
        }
        $newId = $this->db->insert(
            'INSERT INTO contract_modules (module_key, name, category, body_template, required_fields_json, risk_level, is_locked, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$key, $name, $category, $body, $required, $risk, $locked, $active, $sort]
        );
        return $this->ok(['id' => $newId]);
    }

    private function delete(int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        $this->db->run('DELETE FROM contract_modules WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /** Accept an array, or a comma/newline-separated string, of variable keys. */
    private function normalizeFields(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode(array_values(array_filter(array_map('trim', $value), 'strlen')));
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(array_values($decoded));
            }
            $parts = preg_split('/[,\n]+/', $value) ?: [];
            return json_encode(array_values(array_filter(array_map('trim', $parts), 'strlen')));
        }
        return '[]';
    }
}
