<?php
declare(strict_types=1);

namespace Panic;

final class PublicEvents extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $slug = $this->params['slug'] ?? $request->query('slug');
        if (!$slug) {
            return $this->notFound('Event not found');
        }
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.address, v.city, v.state FROM events e JOIN venues v ON v.id = e.venue_id WHERE e.slug = ? AND e.public_visibility = 1',
            [$slug]
        );
        if (!$event) {
            return $this->notFound('Event unavailable');
        }
        return $this->ok([
            'event' => $event,
            'lineup' => $this->db->all("SELECT * FROM event_lineup WHERE event_id = ? AND status != 'canceled' ORDER BY billing_order, set_time", [$event['id']]),
            'flyer' => $this->db->one("SELECT * FROM event_assets WHERE event_id = ? AND asset_type = 'flyer' AND approval_status = 'approved' ORDER BY created_at DESC LIMIT 1", [$event['id']]),
        ]);
    }
}
