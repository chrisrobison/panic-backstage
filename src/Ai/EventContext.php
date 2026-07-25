<?php
declare(strict_types=1);

namespace Panic\Ai;

use Panic\Database;

/**
 * The one curated event field-subset both AI Assistant code paths use:
 *   - scripts/ai-mcp-server.php's get_event tool (model-initiated lookup)
 *   - Ai\Assistant::ask()'s direct fetch when the drawer was opened with an
 *     event_id, so "what's the status of this event" doesn't need a tool
 *     round-trip just to answer about the event already on screen.
 *
 * Shared here so the two field lists can't quietly drift apart — never a
 * raw row dump, always this explicit list (title, dates, status,
 * promoter/booker contact fields, capacity, ticketing info, lineup count).
 * Callers are responsible for the capability check (Capabilities::hasEvent(
 * ..., 'read_event')) *before* calling this — it does no access control of
 * its own.
 */
final class EventContext
{
    public static function curated(Database $db, int $eventId): array
    {
        $event = $db->one(
            'SELECT e.id, e.title, e.event_type, e.status, e.date, e.end_date,
                    e.doors_time, e.show_time, e.end_time, e.is_non_music,
                    e.promoter_name, e.promoter_email, e.promoter_phone,
                    e.client_org, e.booker_name, e.booker_email, e.booker_phone,
                    e.capacity, e.estimated_guests, e.room,
                    e.ticket_price, e.ticket_url, e.ticket_system, e.ticketing_mode,
                    v.name AS venue_name
               FROM events e
               LEFT JOIN venues v ON v.id = e.venue_id
              WHERE e.id = ?',
            [$eventId]
        );
        if (!$event) {
            return [];
        }

        $event['lineup_count'] = (int) ($db->one(
            "SELECT COUNT(*) AS c FROM event_lineup WHERE event_id = ? AND status != 'canceled'",
            [$eventId]
        )['c'] ?? 0);

        return $event;
    }
}
