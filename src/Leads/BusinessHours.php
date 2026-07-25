<?php
declare(strict_types=1);

namespace Panic\Leads;

/**
 * Business-hours-aware SLA due-date arithmetic for the Booking Inbox.
 *
 * "Claim required within 2 business hours" means 2 hours of actual open
 * time, not 2 wall-clock hours — an inquiry that lands at 11pm shouldn't
 * have its claim clock already half-expired before anyone's at a desk the
 * next morning. This walks forward from a UTC instant through the venue's
 * local business-hours window (lead_inbox_settings.business_hours_start/
 * end/business_days, migration 075), consuming $hours of open time, and
 * returns the resulting instant back in UTC.
 *
 * Follows the same local-time-then-convert-to-UTC discipline as the
 * ticketing sales-window fix (commit 93153d4) — every intermediate
 * computation happens in the venue's timezone; only the final result is
 * converted back to UTC for storage, since the DB session is pinned to UTC
 * (src/Database.php).
 */
final class BusinessHours
{
    /**
     * @param string $timezone      e.g. 'America/Los_Angeles' (venues.timezone)
     * @param string $businessStart 'HH:MM:SS' (lead_inbox_settings.business_hours_start)
     * @param string $businessEnd   'HH:MM:SS'
     * @param string $businessDays  comma-separated ISO weekday numbers, 1=Monday..7=Sunday
     */
    public static function addBusinessHours(
        \DateTimeImmutable $fromUtc,
        float $hours,
        string $timezone,
        string $businessStart = '09:00:00',
        string $businessEnd = '18:00:00',
        string $businessDays = '1,2,3,4,5'
    ): \DateTimeImmutable {
        $zone = new \DateTimeZone($timezone);
        $days = array_map('intval', array_filter(array_map('trim', explode(',', $businessDays)), static fn($d) => $d !== ''));
        if ($days === []) {
            $days = [1, 2, 3, 4, 5];
        }

        $local = $fromUtc->setTimezone($zone);
        $local = self::advanceIntoWindow($local, $businessStart, $businessEnd, $days);

        $remainingMinutes = (int) round($hours * 60);
        while ($remainingMinutes > 0) {
            $windowEnd = self::atTime($local, $businessEnd);
            $availableMinutes = (int) floor(($windowEnd->getTimestamp() - $local->getTimestamp()) / 60);

            if ($remainingMinutes <= $availableMinutes) {
                $local = $local->modify("+{$remainingMinutes} minutes");
                $remainingMinutes = 0;
                break;
            }

            $remainingMinutes -= max(0, $availableMinutes);
            $local = self::nextBusinessDayStart($local, $businessStart, $days);
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /** If $local falls outside the business window (or on a non-business day), jump to the next window's start. */
    private static function advanceIntoWindow(\DateTimeImmutable $local, string $start, string $end, array $days): \DateTimeImmutable
    {
        $windowStart = self::atTime($local, $start);
        $windowEnd   = self::atTime($local, $end);
        $isBusinessDay = in_array((int) $local->format('N'), $days, true);

        if ($isBusinessDay && $local >= $windowStart && $local < $windowEnd) {
            return $local;
        }
        if ($isBusinessDay && $local < $windowStart) {
            return $windowStart;
        }
        // On or past the end of today's window (or a non-business day) — jump to the next business day's start.
        return self::nextBusinessDayStart($local, $start, $days);
    }

    private static function nextBusinessDayStart(\DateTimeImmutable $local, string $start, array $days): \DateTimeImmutable
    {
        $next = self::atTime($local, $start)->modify('+1 day');
        for ($i = 0; $i < 14; $i++) {
            if (in_array((int) $next->format('N'), $days, true)) {
                return $next;
            }
            $next = $next->modify('+1 day');
        }
        return $next; // unreachable in practice (would mean zero business days configured)
    }

    private static function atTime(\DateTimeImmutable $local, string $time): \DateTimeImmutable
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '0');
        return $local->setTime((int) $h, (int) $m, (int) ($s ?: 0));
    }
}
