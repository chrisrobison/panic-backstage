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
}
