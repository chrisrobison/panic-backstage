<?php
declare(strict_types=1);

namespace Panic;

/**
 * Shared event-query and card-rendering logic for "shows lineup" emails —
 * extracted from scripts/generate-weekly-lineup-email.php so both the CLI
 * script and the in-app campaigns tool (generate a campaign from picked
 * events) share one implementation instead of two copies drifting apart.
 *
 * Selection gate mirrors src/Feed.php's public syndication feed, narrowed to
 * statuses that mean a show has actually been publicly announced:
 *   public_visibility = 1
 *   status IN ('published', 'advanced')
 */
final class EventEmailComposer
{
    /**
     * Rolling-window event query: everything eligible between today and
     * today + (days-1), optionally restricted to one venue.
     *
     * @return list<array<string,mixed>>
     */
    public static function eligibleEventsInWindow(Database $db, int $days, string $venueSlug = ''): array
    {
        $where  = [
            'e.public_visibility = 1',
            "e.status IN ('published', 'advanced')",
            'e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)',
        ];
        $params = [$days - 1];

        if ($venueSlug !== '') {
            $where[]  = 'v.slug = ?';
            $params[] = $venueSlug;
        }

        return $db->all(
            'SELECT e.*, v.name AS venue_name, v.address AS venue_address,
                    v.city AS venue_city, v.state AS venue_state,
                    (SELECT a.file_path FROM event_assets a
                       WHERE a.event_id = e.id AND a.asset_type = \'flyer\'
                         AND a.approval_status = \'approved\'
                       ORDER BY a.created_at DESC LIMIT 1) AS flyer_path
             FROM events e
             JOIN venues v ON v.id = e.venue_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.date ASC, e.show_time ASC',
            $params
        );
    }

    /**
     * Same eligibility gate as eligibleEventsInWindow(), but for a specific
     * set of event IDs (used by the campaign "Generate from Event(s)" flow
     * and its event-picker). Returns events in natural date/time order, not
     * necessarily the order $eventIds was given in.
     *
     * @param list<int> $eventIds
     * @return list<array<string,mixed>>
     */
    public static function eligibleEventsByIds(Database $db, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $ids = array_map('intval', $eventIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $db->all(
            'SELECT e.*, v.name AS venue_name, v.address AS venue_address,
                    v.city AS venue_city, v.state AS venue_state,
                    (SELECT a.file_path FROM event_assets a
                       WHERE a.event_id = e.id AND a.asset_type = \'flyer\'
                         AND a.approval_status = \'approved\'
                       ORDER BY a.created_at DESC LIMIT 1) AS flyer_path
             FROM events e
             JOIN venues v ON v.id = e.venue_id
             WHERE e.public_visibility = 1
               AND e.status IN (\'published\', \'advanced\')
               AND e.id IN (' . $placeholders . ')
             ORDER BY e.date ASC, e.show_time ASC',
            $ids
        );
    }

    /**
     * Per-event lineup, excluding canceled slots, in billing order.
     *
     * @return list<array<string,mixed>>
     */
    public static function lineupFor(Database $db, int $eventId): array
    {
        return $db->all(
            "SELECT display_name FROM event_lineup WHERE event_id = ? AND status <> 'canceled' ORDER BY billing_order, set_time",
            [$eventId]
        );
    }

    /**
     * Loop $events, fetch each one's lineup, and render both the HTML card
     * fragment and the plain-text block fragment.
     *
     * @param list<array<string,mixed>> $events
     * @return array{html: string, text: string}
     */
    public static function buildEventsFragment(Database $db, array $events): array
    {
        if (!$events) {
            return [
                'html' => '<div style="padding:24px 0;text-align:center;font-size:15px;color:#9b8e82;">'
                    . 'No public shows are on the books for this window yet — check back soon.</div>',
                'text' => 'No public shows are on the books for this window yet — check back soon.',
            ];
        }

        $htmlCards  = [];
        $textBlocks = [];
        foreach ($events as $event) {
            $lineup       = self::lineupFor($db, (int) $event['id']);
            $htmlCards[]  = self::renderEventCardHtml($event, $lineup);
            $textBlocks[] = self::renderEventBlockText($event, $lineup);
        }

        return [
            'html' => implode('', $htmlCards),
            'text' => implode("\n\n----\n\n", $textBlocks),
        ];
    }

    /**
     * Human date-range label for the actual min/max dates among $events
     * (not "today") — e.g. "July 4 – 17, 2026" (same month), "July 28 – Aug
     * 3, 2026" (cross-month), or a cross-year variant. Returns '' when
     * $events is empty.
     *
     * @param list<array<string,mixed>> $events
     */
    public static function dateRangeLabelForEvents(array $events): string
    {
        if (!$events) {
            return '';
        }

        $dates = array_map(static fn (array $e) => (string) $e['date'], $events);
        sort($dates);
        $start = new \DateTime($dates[0]);
        $end   = new \DateTime($dates[count($dates) - 1]);

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('F j, Y');
        }
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('F j') . ' – ' . $end->format('j, Y');
        }
        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('F j') . ' – ' . $end->format('F j, Y');
        }
        return $start->format('F j, Y') . ' – ' . $end->format('F j, Y');
    }

    // ── Render one event card (HTML) ─────────────────────────────────────────

    public static function renderEventCardHtml(array $event, array $lineup): string
    {
        $title    = trim((string) $event['title']);
        $when     = self::humanWhen($event);
        $room     = self::humanRoom($event);
        $price    = self::humanPrice($event);
        $ageR     = trim((string) ($event['age_restriction'] ?? ''));
        $desc     = self::clip((string) ($event['description_public'] ?? ''), 320);
        $support  = self::lineupLine($lineup, $title);
        $flyer    = self::flyerUrl($event);
        $ticketUrl = trim((string) ($event['ticket_url'] ?? ''));
        $ctaUrl   = $ticketUrl !== '' ? $ticketUrl : self::eventUrl($event);
        $ctaLabel = $ticketUrl !== '' ? 'Get Tickets' : 'Event Details';

        $flyerHtml = '';
        if ($flyer !== '') {
            $flyerHtml = '<img src="' . self::esc($flyer) . '" alt="' . self::esc($title) . ' flyer" width="100%" '
                . 'style="display:block;width:100%;max-width:100%;height:auto;border-radius:14px 14px 0 0;">';
        }

        $tags = array_filter([$room, $ageR, $price]);
        $tagsHtml = '';
        foreach ($tags as $tag) {
            $tagsHtml .= '<span style="display:inline-block;margin:0 6px 6px 0;padding:3px 10px;'
                . 'font-size:11px;letter-spacing:0.5px;text-transform:uppercase;color:#c9b27e;'
                . 'border:1px solid #4a4340;border-radius:999px;">' . self::esc($tag) . '</span>';
        }

        $supportHtml = $support !== ''
            ? '<div style="margin-top:6px;font-size:13px;color:#9b8e82;">' . self::esc($support) . '</div>'
            : '';

        $descHtml = $desc !== ''
            ? '<div style="margin-top:10px;font-size:14px;line-height:1.55;color:#d8d1c8;">' . nl2br(self::esc($desc)) . '</div>'
            : '';

        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
            . 'style="margin:0 0 20px;background:#171717;border:1px solid #3b3636;border-radius:14px;overflow:hidden;">'
            . '<tr><td>' . $flyerHtml . '</td></tr>'
            . '<tr><td style="padding:20px 22px;">'
            . '<div style="font-size:13px;font-weight:bold;color:#fff;letter-spacing:0.3px;">' . self::esc($when) . '</div>'
            . '<h2 style="margin:6px 0 8px;font-size:20px;line-height:1.3;color:#fff;font-weight:800;">' . self::esc($title) . '</h2>'
            . $supportHtml
            . '<div style="margin-top:10px;">' . $tagsHtml . '</div>'
            . $descHtml
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:16px;">'
            . '<tr><td bgcolor="#c1121f" style="border-radius:999px;">'
            . '<a href="' . self::esc($ctaUrl) . '" style="display:inline-block;padding:11px 22px;color:#ffffff;'
            . 'text-decoration:none;font-weight:bold;font-size:13px;border-radius:999px;">' . self::esc($ctaLabel) . '</a>'
            . '</td></tr></table>'
            . '</td></tr></table>';
    }

    // ── Render one event block (plain text) ──────────────────────────────────

    public static function renderEventBlockText(array $event, array $lineup): string
    {
        $lines = [];
        $lines[] = strtoupper(trim((string) $event['title']));
        $lines[] = self::humanWhen($event);

        $tags = array_filter([self::humanRoom($event), trim((string) ($event['age_restriction'] ?? '')), self::humanPrice($event)]);
        if ($tags) {
            $lines[] = implode(' · ', $tags);
        }

        $support = self::lineupLine($lineup, trim((string) $event['title']));
        if ($support !== '') {
            $lines[] = $support;
        }

        $desc = self::clip((string) ($event['description_public'] ?? ''), 320);
        if ($desc !== '') {
            $lines[] = '';
            $lines[] = $desc;
        }

        $ticketUrl = trim((string) ($event['ticket_url'] ?? ''));
        $lines[] = '';
        $lines[] = ($ticketUrl !== '' ? 'Tickets: ' : 'Details: ') . ($ticketUrl !== '' ? $ticketUrl : self::eventUrl($event));

        return implode("\n", $lines);
    }

    // ── Private helpers (moved verbatim from the script) ─────────────────────

    private static function appUrl(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/');
    }

    private static function eventUrl(array $event): string
    {
        return self::appUrl() . '/event.html?slug=' . rawurlencode((string) $event['slug']);
    }

    private static function flyerUrl(array $event): string
    {
        $path = (string) ($event['flyer_path'] ?? '');
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::appUrl() . '/' . ltrim($path, '/');
    }

    private static function fmtTime(?string $time): string
    {
        if (!$time) {
            return '';
        }
        $tstamp = strtotime('1970-01-01 ' . $time);
        return $tstamp ? date('g:i A', $tstamp) : '';
    }

    private static function humanWhen(array $event): string
    {
        $date = strtotime((string) $event['date']);
        if ($date === false) {
            return '';
        }
        $out   = date('D, M j', $date);
        $doors = self::fmtTime($event['doors_time'] ?? null);
        $show  = self::fmtTime($event['show_time'] ?? null);
        if ($doors && $show) {
            $out .= ' · Doors ' . $doors . ' / Show ' . $show;
        } elseif ($show) {
            $out .= ' · Show ' . $show;
        } elseif ($doors) {
            $out .= ' · Doors ' . $doors;
        }
        return $out;
    }

    private static function humanRoom(array $event): string
    {
        $room = (string) ($event['room'] ?? '');
        return $room === '' ? '' : ucwords(str_replace('_', ' ', $room));
    }

    private static function humanPrice(array $event): string
    {
        $price = $event['ticket_price'] ?? null;
        if ($price === null || $price === '' || (float) $price <= 0.0) {
            return 'Free';
        }
        return '$' . number_format((float) $price, (float) $price == floor((float) $price) ? 0 : 2);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Truncate to a max length on a word boundary, appending an ellipsis. */
    private static function clip(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $clipped = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($clipped, ' ');
        if ($lastSpace !== false) {
            $clipped = mb_substr($clipped, 0, $lastSpace);
        }
        return $clipped . '…';
    }

    /** @param array<int, array<string,mixed>> $lineup */
    private static function lineupLine(array $lineup, string $headliner): string
    {
        $support = array_values(array_filter(
            array_map(static fn ($row) => (string) $row['display_name'], $lineup),
            static fn ($name) => $name !== '' && strcasecmp($name, $headliner) !== 0
        ));
        return $support ? 'With ' . implode(', ', $support) : '';
    }
}
