<?php
declare(strict_types=1);

namespace Panic;

/**
 * GET /api/venues
 *
 * Returns the venues list with their associated resources.
 * Used by the calendar zone map and the sidebar venue-name label.
 * Requires authentication; any logged-in role can read venues.
 */
final class Venues extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

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
}
