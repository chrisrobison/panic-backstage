<?php
declare(strict_types=1);

namespace Panic;

/**
 * CRUD for the Square POS location → venue mapping table.
 *
 *   GET    /api/pos-location-map          list all mappings (+ venue names)
 *   POST   /api/pos-location-map          create a mapping
 *   PATCH  /api/pos-location-map/{id}     update a mapping
 *   DELETE /api/pos-location-map/{id}     delete a mapping
 *
 * Gated to venue_admin (manage_users global capability).
 */
final class PosLocationMap extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        $mappingId = (int) ($this->params['mappingId'] ?? 0) ?: null;

        return match ($request->method()) {
            'GET'    => $this->index(),
            'POST'   => $this->create($request),
            'PATCH'  => $mappingId ? $this->update($request, $mappingId) : $this->notFound(),
            'DELETE' => $mappingId ? $this->remove($mappingId)           : $this->notFound(),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $rows = $this->db->all(
            "SELECT m.*, v.name venue_name
               FROM pos_location_map m
               LEFT JOIN venues v ON v.id = m.venue_id
              ORDER BY m.pos_provider, m.location_id"
        );

        $venues = $this->db->all('SELECT id, name FROM venues ORDER BY name');

        return $this->ok(['mappings' => $rows, 'venues' => $venues]);
    }

    private function create(Request $request): Response
    {
        $b = $request->body();

        $locationId      = trim((string) ($b['location_id']      ?? ''));
        $venueId         = (int) ($b['venue_id']         ?? 0);
        $defaultCategory = (string) ($b['default_category'] ?? 'bar_sales');
        $notes           = trim((string) ($b['notes']           ?? ''));
        $isActive        = isset($b['is_active']) ? (int) (bool) $b['is_active'] : 1;

        if ($locationId === '') {
            return Response::json(['error' => 'location_id is required'], 422);
        }
        if ($venueId <= 0) {
            return Response::json(['error' => 'venue_id is required'], 422);
        }
        if (!in_array($defaultCategory, ['bar_sales', 'merch_share', 'other_revenue'], true)) {
            return Response::json(['error' => 'Invalid default_category'], 422);
        }

        $id = $this->db->insert(
            "INSERT INTO pos_location_map
             (pos_provider, location_id, venue_id, default_category, is_active, notes)
             VALUES ('square', ?, ?, ?, ?, ?)",
            [$locationId, $venueId, $defaultCategory, $isActive, $notes !== '' ? $notes : null]
        );

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        $row = $this->db->one('SELECT id FROM pos_location_map WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Mapping not found');
        }

        $b = $request->body();

        $sets   = [];
        $params = [];

        if (isset($b['location_id'])) {
            $v = trim((string) $b['location_id']);
            if ($v === '') {
                return Response::json(['error' => 'location_id cannot be empty'], 422);
            }
            $sets[]   = 'location_id = ?';
            $params[] = $v;
        }
        if (isset($b['venue_id'])) {
            $sets[]   = 'venue_id = ?';
            $params[] = (int) $b['venue_id'];
        }
        if (isset($b['default_category'])) {
            $cat = (string) $b['default_category'];
            if (!in_array($cat, ['bar_sales', 'merch_share', 'other_revenue'], true)) {
                return Response::json(['error' => 'Invalid default_category'], 422);
            }
            $sets[]   = 'default_category = ?';
            $params[] = $cat;
        }
        if (isset($b['is_active'])) {
            $sets[]   = 'is_active = ?';
            $params[] = (int) (bool) $b['is_active'];
        }
        if (array_key_exists('notes', $b)) {
            $sets[]   = 'notes = ?';
            $params[] = trim((string) $b['notes']) ?: null;
        }

        if ($sets === []) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $id;
        $this->db->run('UPDATE pos_location_map SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    private function remove(int $id): Response
    {
        $row = $this->db->one('SELECT id FROM pos_location_map WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Mapping not found');
        }

        $this->db->run('DELETE FROM pos_location_map WHERE id = ?', [$id]);

        return $this->ok(['ok' => true]);
    }
}
