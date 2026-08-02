<?php
declare(strict_types=1);

namespace Panic;

/**
 * Shared "room address falls back to venue address" resolution, used
 * everywhere a display-facing street address is built: tickets
 * (TicketView, TicketingService), the public event page (PublicEvents),
 * contracts (ContractRenderer), outbound emails (EventEmailComposer,
 * Campaigns), syndication feeds (Feed), promotion adapters (Promote), and
 * internal print views (Events → print.js).
 *
 * Rooms (the `resources` table) may set their own `address`; when blank they
 * simply use the parent venue's `address`. City/state/timezone/etc. always
 * come from the venue — only the street line is overridable per room.
 */
final class Address
{
    /** Room address wins when set and non-blank; otherwise the venue's. */
    public static function pick(?string $roomAddress, ?string $venueAddress): ?string
    {
        return self::clean($roomAddress) ?? self::clean($venueAddress);
    }

    /**
     * "Venue Name · Address, City, State" — the one-line format used in
     * ticket emails, campaign emails, and syndication feeds. Any part that's
     * blank is simply omitted (no dangling "· ," or ", ").
     */
    public static function line(
        ?string $venueName,
        ?string $roomAddress,
        ?string $venueAddress,
        ?string $city,
        ?string $state
    ): string {
        $address  = self::pick($roomAddress, $venueAddress);
        $locality = implode(', ', array_filter([self::clean($city), self::clean($state)]));
        $parts = array_filter([
            self::clean($venueName),
            trim(implode(', ', array_filter([$address, $locality !== '' ? $locality : null]))),
        ], fn ($v) => $v !== null && $v !== '');
        return implode(' · ', $parts);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
