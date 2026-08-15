<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\Address;
use Panic\Database;

/**
 * Shared "resolve a public event page URL segment to its row" logic, used by
 * both the JSON API (PublicEvents) and the server-rendered /e/{slug} page
 * (PublicEventPage) — kept in one place so the two can never drift apart on
 * lookup order or the public_visibility gate.
 */
final class PublicEventLookup
{
    private const BASE_SELECT = 'SELECT e.*, v.name venue_name, v.address, v.city, v.state, v.phone venue_phone, v.website_url venue_website, r.address room_address
                 FROM events e JOIN venues v ON v.id = e.venue_id LEFT JOIN resources r ON r.id = e.resource_id';

    /**
     * Lookup order: numeric id -> permanent public_slug -> legacy mutable
     * `slug` (see Support::event_public_path()). public_slug is checked
     * before the legacy slug deliberately: `slug` is regenerated on every
     * title/date edit (Events::update()), so some *other* live event's
     * current `slug` could coincidentally match an older event's
     * never-changing public_slug — the address someone is actually sharing
     * today has to win that race, not a stale coincidence.
     *
     * Returns null for a missing event, a non-public one, or an empty
     * $idOrSlug — callers render the same "not found" response either way,
     * so a hidden event's existence is never leaked by a different error.
     */
    public static function resolve(Database $db, string $idOrSlug): ?array
    {
        $idOrSlug = trim($idOrSlug);
        if ($idOrSlug === '') {
            return null;
        }
        $event = ctype_digit($idOrSlug)
            ? $db->one(self::BASE_SELECT . ' WHERE e.id = ? AND e.public_visibility = 1', [(int) $idOrSlug])
            : ($db->one(self::BASE_SELECT . ' WHERE e.public_slug = ? AND e.public_visibility = 1', [$idOrSlug])
                ?? $db->one(self::BASE_SELECT . ' WHERE e.slug = ? AND e.public_visibility = 1', [$idOrSlug]));
        if ($event === null) {
            return null;
        }
        // A room's own address (if set) takes priority over the venue's for
        // the public page — e.g. an annex space at a different street number.
        // Computed once here so every caller (JSON API, server-rendered page)
        // gets the same resolved address instead of re-deriving it.
        $event['address'] = Address::pick($event['room_address'] ?? null, $event['address'] ?? null);
        return $event;
    }
}
