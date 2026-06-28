<?php
declare(strict_types=1);

namespace Panic;

/**
 * GET    /api/venues          – list all venues with their resources
 * PATCH  /api/venues/{id}     – update venue details (venue_admin only)
 *
 * The read path is used by the calendar zone map and the sidebar venue-name
 * label; any authenticated role may call it.  The write path is restricted to
 * venue_admins and is surfaced through the Admin › Venue tab.
 */
final class Venues extends BaseEndpoint
{
    /** Fields callers may update via PATCH. */
    private const UPDATABLE = ['name', 'address', 'city', 'state', 'timezone', 'phone', 'website_url'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $venueId = isset($this->params['venueId']) ? (int) $this->params['venueId'] : null;

        if ($request->method() === 'PATCH' && $venueId !== null) {
            return $this->update($request, $venueId);
        }

        return $this->list();
    }

    // ── GET /api/venues ───────────────────────────────────────────────────────

    private function list(): Response
    {
        $venues    = $this->db->all('SELECT * FROM venues ORDER BY name');
        $resources = $this->db->all(
            'SELECT * FROM resources WHERE active = 1 ORDER BY venue_id, sort_order, name'
        );

        // Attach resources to their parent venue for convenience
        $byVenue = [];
        foreach ($resources as $r) {
            $byVenue[(int) $r['venue_id']][] = $r;
        }
        foreach ($venues as &$v) {
            $v['resources'] = $byVenue[(int) $v['id']] ?? [];
        }
        unset($v);

        return $this->ok([
            'venues'    => $venues,
            'resources' => $resources,
        ]);
    }

    // ── PATCH /api/venues/{id} ────────────────────────────────────────────────

    private function update(Request $request, int $id): Response
    {
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can update venue details.');
        }

        $venue = $this->db->one('SELECT * FROM venues WHERE id = ?', [$id]);
        if (!$venue) {
            return $this->notFound('Venue not found.');
        }

        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $setClauses = [];
        $params     = [];

        foreach (self::UPDATABLE as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            // name must be non-empty
            if ($field === 'name' && (!is_string($value) || trim($value) === '')) {
                return Response::json(['error' => 'Venue name cannot be empty.'], 422);
            }
            $setClauses[] = "`$field` = ?";
            $params[]     = is_string($value) ? trim($value) : $value;
        }

        if (empty($setClauses)) {
            return Response::json(['error' => 'No updatable fields provided.'], 422);
        }

        $params[] = $id;
        $this->db->run(
            'UPDATE venues SET ' . implode(', ', $setClauses) . ' WHERE id = ?',
            $params
        );

        $updated = $this->db->one('SELECT * FROM venues WHERE id = ?', [$id]);
        return $this->ok(['venue' => $updated]);
    }
}
