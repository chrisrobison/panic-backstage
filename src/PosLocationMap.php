<?php
declare(strict_types=1);

namespace Panic;

/**
 * CRUD for the Square POS location → venue mapping table.
 *
 *   GET    /api/pos-location-map              list all mappings (+ venue names)
 *   POST   /api/pos-location-map              create a mapping
 *   PATCH  /api/pos-location-map/{id}         update a mapping
 *   DELETE /api/pos-location-map/{id}         delete a mapping
 *   POST   /api/pos-location-map/{id}/set-active?event_id=  set active event override
 *   POST   /api/pos-location-map/{id}/clear-active           clear the override
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
        $sub       = $this->params['sub'] ?? null;

        // Sub-actions: set-active / clear-active
        if ($mappingId && $request->method() === 'POST') {
            if ($sub === 'set-active')   return $this->setActive($request, $mappingId);
            if ($sub === 'clear-active') return $this->clearActive($mappingId);
        }

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

    /**
     * Set the active event override for this POS location.
     * All incoming POS payments will be posted to this event until cleared.
     * Called from the event workspace "Set as POS Event" button.
     */
    private function setActive(Request $request, int $mappingId): Response
    {
        $b       = $request->body();
        $eventId = (int) ($b['event_id'] ?? $request->query('event_id') ?? 0);
        if ($eventId <= 0) {
            return Response::json(['error' => 'event_id is required'], 422);
        }

        // Verify the event exists
        $event = $this->db->one('SELECT id, title FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }

        $this->db->run(
            'UPDATE pos_location_map SET active_event_id = ?, active_event_set_at = NOW() WHERE id = ?',
            [$eventId, $mappingId]
        );

        log_activity($this->db, $eventId, $this->userId(), 'pos_active_event_set', [
            'mapping_id' => $mappingId,
        ]);

        return $this->ok([
            'active_event_id'    => $eventId,
            'active_event_title' => $event['title'],
            'set_at'             => date('c'),
        ]);
    }

    /**
     * Clear the active event override — revert to date-based matching.
     */
    private function clearActive(int $mappingId): Response
    {
        $this->db->run(
            'UPDATE pos_location_map SET active_event_id = NULL, active_event_set_at = NULL WHERE id = ?',
            [$mappingId]
        );

        return $this->ok(['cleared' => true]);
    }
}
