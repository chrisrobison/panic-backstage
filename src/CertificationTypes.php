<?php
declare(strict_types=1);

namespace Panic;

/**
 * Certification/training type catalog (RBS, Guard Card, Food Handler, etc.)
 *
 *   GET    /api/certification-types          list
 *   POST   /api/certification-types          create
 *   PATCH  /api/certification-types/{id}     update
 *   DELETE /api/certification-types/{id}     delete
 *
 * Admin-only (manage_certifications). Reading the catalog to populate a
 * "record a certification" form also requires the same capability — unlike
 * staff documents, certification records are managed by admins on behalf of
 * staff, not self-served, since they usually require verifying a physical
 * card/certificate.
 */
final class CertificationTypes extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_certifications')) {
            return $denied;
        }
        $id = $this->params['typeId'] ?? null;
        return match ($request->method()) {
            'GET' => $this->ok(['certification_types' => $this->db->all('SELECT * FROM staff_certification_types ORDER BY active DESC, name')]),
            'POST' => $this->create($request),
            'PATCH' => $id ? $this->update($request, (int) $id) : $this->notFound('typeId required'),
            'DELETE' => $id ? $this->delete((int) $id) : $this->notFound('typeId required'),
            default => Response::methodNotAllowed(),
        };
    }

    private function create(Request $request): Response
    {
        $slug = trim((string) $request->body('slug', ''));
        $name = trim((string) $request->body('name', ''));
        if ($slug === '' || $name === '') {
            return Response::json(['error' => 'slug and name are required'], 422);
        }
        $id = $this->db->insert(
            'INSERT INTO staff_certification_types (slug, name, description, expiration_required, default_validity_months, active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $slug,
                $name,
                $request->body('description'),
                $request->body('expiration_required', false) ? 1 : 0,
                $request->body('default_validity_months') !== null ? (int) $request->body('default_validity_months') : null,
                $request->body('active', true) ? 1 : 0,
            ]
        );
        return $this->ok(['certification_type' => $this->db->one('SELECT * FROM staff_certification_types WHERE id = ?', [$id])]);
    }

    private function update(Request $request, int $id): Response
    {
        $row = $this->db->one('SELECT * FROM staff_certification_types WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Certification type not found');
        }
        $fields = ['slug', 'name', 'description', 'expiration_required', 'default_validity_months', 'active'];
        $body = $request->body();
        $set = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $value = $body[$f];
                if (in_array($f, ['expiration_required', 'active'], true)) {
                    $value = $value ? 1 : 0;
                }
                $set[] = "$f = ?";
                $params[] = $value;
            }
        }
        if ($set) {
            $params[] = $id;
            $this->db->run('UPDATE staff_certification_types SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
        }
        return $this->ok(['certification_type' => $this->db->one('SELECT * FROM staff_certification_types WHERE id = ?', [$id])]);
    }

    private function delete(int $id): Response
    {
        $this->db->run('DELETE FROM staff_certification_types WHERE id = ?', [$id]);
        return $this->ok(['deleted' => true]);
    }
}
