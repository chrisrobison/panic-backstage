<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Destination list for an event's promote view.
 *
 *   GET /api/promote/events/{id}/destinations
 */
final class Destinations extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        $eventId = (int) ($this->params['eventId'] ?? 0);
        if (!$eventId) {
            return $this->notFound('Event not found');
        }
        if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
            return $denied;
        }
        $destinations = $this->db->all(
            "SELECT * FROM promote_destinations WHERE status != 'disabled' ORDER BY destination_group, label"
        );
        return $this->ok(['destinations' => $destinations]);
    }
}
