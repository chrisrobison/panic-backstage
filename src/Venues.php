<?php
declare(strict_types=1);

namespace Panic;

/**
 * GET    /api/venues                          – list all venues with their resources
 * PATCH  /api/venues/{id}                      – update venue details (venue_admin only)
 *
 * GET    /api/venues/{id}/resources            – list a venue's rooms incl. archived (venue_admin)
 * POST   /api/venues/{id}/resources            – create a room (venue_admin)
 * PATCH  /api/venues/{id}/resources/{rid}       – update a room (venue_admin)
 * DELETE /api/venues/{id}/resources/{rid}       – archive a room (venue_admin)
 *
 * "Rooms" are stored in the `resources` table (bookable spaces within a venue);
 * the read path is used by the calendar zone map and the sidebar venue-name
 * label and any authenticated role may call it.  The write paths are restricted
 * to venue_admins and surfaced through the Admin › Venue tab.
 */
final class Venues extends BaseEndpoint
{
    /** Venue fields callers may update via PATCH /venues/{id}. */
    private const UPDATABLE = ['name', 'address', 'city', 'state', 'timezone', 'phone', 'website_url'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $venueId    = isset($this->params['venueId']) ? (int) $this->params['venueId'] : null;
        $resourceId = isset($this->params['resourceId']) ? (int) $this->params['resourceId'] : null;

        if (($this->params['child'] ?? null) === 'resources') {
            return $this->resources($request, $venueId, $resourceId);
        }

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

    // ── /api/venues/{id}/resources ────────────────────────────────────────────

    private function resources(Request $request, ?int $venueId, ?int $resourceId): Response
    {
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can manage rooms.');
        }
        if ($venueId === null || !$this->db->one('SELECT id FROM venues WHERE id = ?', [$venueId])) {
            return $this->notFound('Venue not found.');
        }

        return match ($request->method()) {
            'GET'    => $this->listResources($venueId),
            'POST'   => $this->createResource($request, $venueId),
            'PATCH'  => $this->updateResource($request, $venueId, $resourceId),
            'DELETE' => $this->archiveResource($venueId, $resourceId),
            default  => Response::json(['error' => 'Method not allowed'], 405),
        };
    }

    /** All rooms for a venue, including archived ones, for the management view. */
    private function listResources(int $venueId): Response
    {
        return $this->ok([
            'resources' => $this->db->all(
                'SELECT * FROM resources WHERE venue_id = ? ORDER BY sort_order, name',
                [$venueId]
            ),
        ]);
    }

    private function createResource(Request $request, int $venueId): Response
    {
        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '') {
            return Response::json(['error' => 'Room name is required.'], 422);
        }

        $sortOrder = array_key_exists('sort_order', $body)
            ? (int) $body['sort_order']
            : (int) ($this->db->one(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM resources WHERE venue_id = ?',
                [$venueId]
            )['n'] ?? 1);

        $id = $this->db->insert(
            'INSERT INTO resources (venue_id, name, slug, description, capacity, zone, sort_order, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $venueId,
                $name,
                $this->uniqueSlug($venueId, $this->slugify($name)),
                $this->cleanText($body['description'] ?? null),
                $this->cleanInt($body['capacity'] ?? null),
                $this->cleanZone($body['zone'] ?? null),
                $sortOrder,
            ]
        );

        return $this->ok(['resource' => $this->db->one('SELECT * FROM resources WHERE id = ?', [$id])]);
    }

    private function updateResource(Request $request, int $venueId, ?int $resourceId): Response
    {
        if ($resourceId === null) {
            return $this->notFound('Room not found.');
        }
        $room = $this->db->one(
            'SELECT * FROM resources WHERE id = ? AND venue_id = ?',
            [$resourceId, $venueId]
        );
        if (!$room) {
            return $this->notFound('Room not found.');
        }

        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $set    = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : '';
            if ($name === '') {
                return Response::json(['error' => 'Room name cannot be empty.'], 422);
            }
            $set[]    = '`name` = ?';
            $params[] = $name;
        }
        if (array_key_exists('description', $body)) {
            $set[]    = '`description` = ?';
            $params[] = $this->cleanText($body['description']);
        }
        if (array_key_exists('capacity', $body)) {
            $set[]    = '`capacity` = ?';
            $params[] = $this->cleanInt($body['capacity']);
        }
        if (array_key_exists('zone', $body)) {
            $set[]    = '`zone` = ?';
            $params[] = $this->cleanZone($body['zone']);
        }
        if (array_key_exists('sort_order', $body)) {
            $set[]    = '`sort_order` = ?';
            $params[] = (int) $body['sort_order'];
        }
        if (array_key_exists('active', $body)) {
            $set[]    = '`active` = ?';
            $params[] = (int) (bool) $body['active'];
        }

        if (empty($set)) {
            return Response::json(['error' => 'No updatable fields provided.'], 422);
        }

        $params[] = $resourceId;
        $this->db->run('UPDATE resources SET ' . implode(', ', $set) . ' WHERE id = ?', $params);

        return $this->ok([
            'resource' => $this->db->one('SELECT * FROM resources WHERE id = ?', [$resourceId]),
        ]);
    }

    /** Soft-delete: archived rooms drop out of the calendar but keep their event history. */
    private function archiveResource(int $venueId, ?int $resourceId): Response
    {
        if ($resourceId === null) {
            return $this->notFound('Room not found.');
        }
        $room = $this->db->one(
            'SELECT id FROM resources WHERE id = ? AND venue_id = ?',
            [$resourceId, $venueId]
        );
        if (!$room) {
            return $this->notFound('Room not found.');
        }

        $this->db->run('UPDATE resources SET active = 0 WHERE id = ?', [$resourceId]);
        return $this->ok(['archived' => true]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'room';
    }

    /** Append -2, -3, … until the slug is free within the venue. */
    private function uniqueSlug(int $venueId, string $base): string
    {
        $slug = $base;
        $n    = 1;
        while ($this->db->one(
            'SELECT id FROM resources WHERE venue_id = ? AND slug = ?',
            [$venueId, $slug]
        )) {
            $slug = $base . '-' . (++$n);
        }
        return $slug;
    }

    private function cleanText($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function cleanInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return max(0, (int) $value);
    }

    private function cleanZone($value): string
    {
        $zone = is_string($value) ? trim($value) : '';
        return $zone !== '' ? $zone : 'primary';
    }
}
