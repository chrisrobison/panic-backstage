<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use DateTimeImmutable;
use Panic\Database;

/**
 * Venue-availability matching: which dates in an upcoming conference's own
 * span have no active Backstage booking anywhere in this tenant's venue(s)
 * ("empty nights"). This is the key differentiator the spec calls out — the
 * app already knows its own calendar, so this never needs to ask anything
 * external.
 *
 * Phase 2 ships the first cut (used by the Discover dashboard's "Empty
 * Nights to Fill" KPI and "Venue Availability Match" panel); Phase 8 is
 * expected to build "find prospects for empty dates" on top of the same
 * `emptyNightMatches()` primitive rather than duplicating the date math.
 *
 * Deliberately two queries total regardless of how many conferences/dates
 * are involved (one for candidate conferences, one for busy event dates in
 * the relevant range) — no N+1 per conference or per day.
 */
final class Availability
{
    /**
     * @return list<array{conference: array<string, mixed>, date: string}>
     */
    public static function emptyNightMatches(Database $db, int $windowDays = 30): array
    {
        $windowDays = max(1, min(365, $windowDays));

        $conferences = $db->all(
            'SELECT * FROM opportunity_conferences
             WHERE starts_at IS NOT NULL
               AND starts_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND COALESCE(ends_at, starts_at) >= CURDATE()
             ORDER BY starts_at ASC',
            [$windowDays]
        );
        if (!$conferences) {
            return [];
        }

        $starts = array_column($conferences, 'starts_at');
        $ends   = array_map(static fn (array $c) => $c['ends_at'] ?? $c['starts_at'], $conferences);
        $minDate = min($starts);
        $maxDate = max($ends);

        // Every event overlapping [minDate, maxDate] anywhere in this DB's
        // venue(s) — single-tenant DBs hold exactly one venue's calendar
        // (multi-venue is handled by separate tenant DBs, not multiple rows
        // here), so no venue_id filter is needed to mean "this venue".
        $busyRows = $db->all(
            "SELECT `date`, end_date FROM events
             WHERE `date` <= ? AND COALESCE(end_date, `date`) >= ?
               AND status NOT IN ('canceled', 'empty')",
            [$maxDate, $minDate]
        );

        $busy = [];
        foreach ($busyRows as $row) {
            $cursor = new DateTimeImmutable((string) $row['date']);
            $end    = new DateTimeImmutable((string) ($row['end_date'] ?: $row['date']));
            for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $busy[$cursor->format('Y-m-d')] = true;
            }
        }

        $matches = [];
        foreach ($conferences as $conference) {
            $cursor = new DateTimeImmutable((string) $conference['starts_at']);
            $end    = new DateTimeImmutable((string) ($conference['ends_at'] ?: $conference['starts_at']));
            for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $key = $cursor->format('Y-m-d');
                if (!isset($busy[$key])) {
                    $matches[] = ['conference' => $conference, 'date' => $key];
                }
            }
        }

        return $matches;
    }

    /**
     * This tenant's own venue coordinates, if a venue_admin has entered them
     * (Admin > Venue — see src/Venues.php's `latitude`/`longitude` fields,
     * migration 111). Null means "not researched yet" — callers must show
     * "Unknown" distance, never fall back to guessing or geocoding.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function venueCoordinates(Database $db): ?array
    {
        $venue = $db->one(
            'SELECT latitude, longitude FROM venues WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id LIMIT 1'
        );
        return $venue ? ['lat' => (float) $venue['latitude'], 'lng' => (float) $venue['longitude']] : null;
    }

    /** Great-circle (Haversine) distance in miles — purely local math, never an external API call. */
    public static function distanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadiusMiles * $c, 2);
    }

    /**
     * Distance from the venue to $conference, computed locally when both
     * coordinate pairs are known; otherwise falls back to whatever's already
     * stored on the conference row (a human-researched approximation, e.g.
     * "0.6 mi" entered without exact coordinates) — never fabricated, and
     * never silently overwrites a manual value just because coordinates
     * happen to be unavailable this request.
     */
    public static function conferenceDistanceMiles(array $conference, ?array $venueCoords): ?float
    {
        if ($venueCoords && $conference['latitude'] !== null && $conference['longitude'] !== null) {
            return self::distanceMiles(
                $venueCoords['lat'],
                $venueCoords['lng'],
                (float) $conference['latitude'],
                (float) $conference['longitude']
            );
        }
        return $conference['distance_from_venue_miles'] !== null ? (float) $conference['distance_from_venue_miles'] : null;
    }
}
