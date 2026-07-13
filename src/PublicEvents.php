<?php
declare(strict_types=1);

namespace Panic;

final class PublicEvents extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        // The path segment is the event id (current scheme — see
        // Support::event_public_path()). Older links shared/printed/QR-coded
        // before this change encoded the event's slug instead, so fall back
        // to a slug lookup when the value isn't a bare id, keeping those
        // links working indefinitely.
        $idOrSlug = $this->params['idOrSlug'] ?? $request->query('id') ?? $request->query('slug');
        if (!$idOrSlug) {
            return $this->notFound('Event not found');
        }
        $event = ctype_digit((string) $idOrSlug)
            ? $this->db->one(
                'SELECT e.*, v.name venue_name, v.address, v.city, v.state FROM events e JOIN venues v ON v.id = e.venue_id WHERE e.id = ? AND e.public_visibility = 1',
                [(int) $idOrSlug]
            )
            : $this->db->one(
                'SELECT e.*, v.name venue_name, v.address, v.city, v.state FROM events e JOIN venues v ON v.id = e.venue_id WHERE e.slug = ? AND e.public_visibility = 1',
                [(string) $idOrSlug]
            );
        if (!$event) {
            return $this->notFound('Event unavailable');
        }
        // Only surface tiers when we're actually selling them here (self-hosted
        // ticketing) and they're currently buyable — mirrors the filter
        // PublicTickets::listTypes() uses for the purchase widget itself, so the
        // header price and the widget below it never disagree. price_cents-only:
        // this is just for the header's "From $X" price, not the full purchase UI.
        $ticketTypes = $event['ticketing_mode'] === 'internal'
            ? $this->db->all(
                "SELECT price_cents FROM ticket_types
                  WHERE event_id = ?
                    AND status = 'on_sale'
                    AND (sales_start IS NULL OR sales_start <= NOW())
                    AND (sales_end   IS NULL OR sales_end   >= NOW())",
                [$event['id']]
            )
            : [];
        return $this->ok([
            'event' => $event,
            'lineup' => $this->db->all("SELECT * FROM event_lineup WHERE event_id = ? AND status != 'canceled' ORDER BY billing_order, set_time", [$event['id']]),
            'flyer' => $this->db->one("SELECT * FROM event_assets WHERE event_id = ? AND asset_type = 'flyer' AND approval_status = 'approved' ORDER BY created_at DESC LIMIT 1", [$event['id']]),
            'ticket_types' => $ticketTypes,
        ]);
    }
}
