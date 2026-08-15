<?php
declare(strict_types=1);

namespace Panic;

use Panic\Events\PublicEventLookup;

final class PublicEvents extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        // The path segment is the event's permanent public_slug (current
        // scheme — see Support::event_public_path() / the /e/{slug} pretty
        // route). Older links shared/printed/QR-coded before that change
        // encoded either the bare numeric id or the mutable `slug` instead;
        // PublicEventLookup::resolve() tries all three, in that priority
        // order, so every previously-issued link keeps working indefinitely.
        $idOrSlug = $this->params['idOrSlug'] ?? $request->query('id') ?? $request->query('slug');
        if (!$idOrSlug) {
            return $this->notFound('Event not found');
        }
        $event = PublicEventLookup::resolve($this->db, (string) $idOrSlug);
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
