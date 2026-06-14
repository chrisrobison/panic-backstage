<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Health endpoint.
 *
 *   GET /api/promote/events/{id}/health
 */
final class HealthEndpoint extends BaseEndpoint
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
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }
        $posts    = $this->db->all('SELECT * FROM promote_posts WHERE event_id = ?', [$eventId]);
        $assets   = $this->db->all('SELECT * FROM event_assets WHERE event_id = ?', [$eventId]);
        $settings = $this->db->one('SELECT * FROM promote_settings WHERE event_id = ?', [$eventId])
            ?? ['goal_tickets' => null];
        $health = (new PromotionHealth($this->db))->compute($settings, $event, $posts, $assets, $eventId);
        return $this->ok(['health' => $health]);
    }
}
