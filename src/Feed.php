<?php
declare(strict_types=1);

namespace Panic;

use function Panic\event_public_path;

/**
 * Public event syndication feeds (unauthenticated).
 *
 *   GET /api/feed                 → JSON index of available feeds
 *   GET /api/feed/events.ics      → iCalendar (subscribe in Google/Apple Calendar)
 *   GET /api/feed/events.rss      → RSS 2.0 (aggregators, "what's on" widgets)
 *
 * Query params (both formats):
 *   ?venue={slug}   restrict to one venue
 *   ?days={N}       only events within the next N days (default: all upcoming)
 *   ?past=1         include past events too (default: upcoming only)
 *   ?limit={N}      cap the number of events (default 500, max 1000)
 *
 * Only events with public_visibility = 1 are ever exposed — the same gate
 * PublicEvents uses. Canceled events are excluded. No auth, no secrets:
 * this is a read-only projection of already-public data, which is exactly
 * the kind of "they pull from us" syndication that avoids per-site scraping.
 */
final class Feed extends BaseEndpoint
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT     = 1000;
    private const DEFAULT_DURATION_HOURS = 3;

    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $format = strtolower((string) ($this->params['format'] ?? $request->query('format', '')));
        $events = $this->fetchEvents($request);

        if (str_contains($format, 'ics') || str_contains($format, 'ical')) {
            return $this->text($this->renderIcs($events), 'text/calendar; charset=utf-8', 'events.ics');
        }
        if (str_contains($format, 'rss') || str_contains($format, 'xml')) {
            return $this->text($this->renderRss($events), 'application/rss+xml; charset=utf-8', 'events.rss');
        }

        // /api/feed → discovery index
        $base = $this->appUrl();
        return $this->ok([
            'feeds' => [
                'ics' => $base . '/api/feed/events.ics',
                'rss' => $base . '/api/feed/events.rss',
            ],
            'params'   => ['venue' => 'slug', 'days' => 'int', 'past' => '0|1', 'limit' => 'int'],
            'upcoming' => count($events),
        ]);
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function fetchEvents(Request $request): array
    {
        $where  = ['e.public_visibility = 1', "e.status <> 'canceled'"];
        $params = [];

        if (!$request->query('past')) {
            $where[] = 'e.date >= CURDATE()';
        }

        $venue = trim((string) $request->query('venue', ''));
        if ($venue !== '') {
            $where[]  = 'v.slug = ?';
            $params[] = $venue;
        }

        $days = (int) $request->query('days', 0);
        if ($days > 0) {
            $where[]  = 'e.date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));

        $sql = 'SELECT e.*, v.name AS venue_name, v.address AS venue_address,
                       v.city AS venue_city, v.state AS venue_state, v.timezone AS venue_timezone,
                       (SELECT a.file_path FROM event_assets a
                          WHERE a.event_id = e.id AND a.asset_type = \'flyer\'
                            AND a.approval_status = \'approved\'
                          ORDER BY a.created_at DESC LIMIT 1) AS flyer_path
                FROM events e
                JOIN venues v ON v.id = e.venue_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY e.date ASC, e.show_time ASC
                LIMIT ' . $limit;

        return $this->db->all($sql, $params);
    }

    // ── iCalendar ─────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $events */
    private function renderIcs(array $events): string
    {
        $host  = $this->host();
        $stamp = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Panic Backstage//Panic Promote Feed//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->icsValue($this->calendarName($events)),
        ];

        foreach ($events as $event) {
            [$startUtc, $endUtc] = $this->eventBounds($event);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-' . (int) $event['id'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . $startUtc;
            $lines[] = 'DTEND:' . $endUtc;
            $lines[] = 'SUMMARY:' . $this->icsValue((string) $event['title']);
            if ($desc = $this->eventDescription($event)) {
                $lines[] = 'DESCRIPTION:' . $this->icsValue($desc);
            }
            if ($loc = $this->venueLocation($event)) {
                $lines[] = 'LOCATION:' . $this->icsValue($loc);
            }
            $lines[] = 'URL:' . $this->icsValue($this->eventUrl($event));
            if ($cat = $this->humanType($event)) {
                $lines[] = 'CATEGORIES:' . $this->icsValue($cat);
            }
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF line endings + 75-octet folding, per RFC 5545.
        return implode("\r\n", array_map([$this, 'icsFold'], $lines)) . "\r\n";
    }

    /**
     * Resolve an event to absolute UTC start/end instants in its venue timezone,
     * so calendar clients show the correct local time without a VTIMEZONE block.
     *
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string} [DTSTART, DTEND] as Ymd\THis\Z
     */
    private function eventBounds(array $event): array
    {
        $tzName = (string) ($event['venue_timezone'] ?: 'America/Los_Angeles');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $tz = new \DateTimeZone('America/Los_Angeles');
        }

        $date      = (string) $event['date'];
        $startTime = (string) ($event['show_time'] ?: $event['doors_time'] ?: '19:00:00');
        $start     = new \DateTime($date . ' ' . $startTime, $tz);

        if (!empty($event['end_time'])) {
            $end = new \DateTime($date . ' ' . $event['end_time'], $tz);
            if ($end <= $start) {
                $end->modify('+1 day'); // show runs past midnight
            }
        } else {
            $end = (clone $start)->modify('+' . self::DEFAULT_DURATION_HOURS . ' hours');
        }

        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Ymd\THis\Z'),
            $end->setTimezone($utc)->format('Ymd\THis\Z'),
        ];
    }

    private function icsValue(string $value): string
    {
        $value = str_replace(["\\", "\n", "\r", ',', ';'], ['\\\\', '\\n', '', '\\,', '\\;'], $value);
        return $value;
    }

    /** Fold a content line to <= 75 octets with CRLF + space continuation. */
    private function icsFold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $chunk = '';
        $len = 0;
        // Walk bytes but don't split a multibyte UTF-8 sequence across a fold.
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $byte = $line[$i];
            $isContinuation = (ord($byte) & 0xC0) === 0x80;
            if ($len >= 74 && !$isContinuation) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
                $len = 0;
            }
            $chunk .= $byte;
            $len++;
        }
        return $out . ($out === '' ? '' : "\r\n ") . $chunk;
    }

    private function calendarName(array $events): string
    {
        $venue = $events[0]['venue_name'] ?? null;
        return $venue ? ($venue . ' — Events') : 'Events';
    }

    // ── RSS 2.0 ───────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $events */
    private function renderRss(array $events): string
    {
        $base    = $this->appUrl();
        $self     = $base . '/api/feed/events.rss';
        $now      = gmdate('D, d M Y H:i:s') . ' GMT';
        $title    = $this->calendarName($events);

        $items = '';
        foreach ($events as $event) {
            $url     = $this->eventUrl($event);
            [$start] = $this->eventBounds($event);
            $pubDate = \DateTime::createFromFormat('Ymd\THis\Z', $start, new \DateTimeZone('UTC'));
            $pubDate = $pubDate ? $pubDate->format('D, d M Y H:i:s') . ' GMT' : $now;

            $descHtml = $this->rssItemHtml($event);

            $enclosure = '';
            if ($flyer = $this->flyerUrl($event)) {
                $enclosure = '      <enclosure url="' . $this->xml($flyer) . '" type="' . $this->imageMime($flyer) . '" length="0" />' . "\n";
            }

            $cat = $this->humanType($event);
            $category = $cat !== '' ? '      <category>' . $this->xml($cat) . "</category>\n" : '';

            $items .= "    <item>\n"
                . '      <title>' . $this->xml((string) $event['title']) . "</title>\n"
                . '      <link>' . $this->xml($url) . "</link>\n"
                . '      <guid isPermaLink="true">' . $this->xml($url) . "</guid>\n"
                . '      <pubDate>' . $pubDate . "</pubDate>\n"
                . $category
                . '      <description><![CDATA[' . $descHtml . "]]></description>\n"
                . $enclosure
                . "    </item>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . $this->xml($title) . "</title>\n"
            . '    <link>' . $this->xml($base) . "</link>\n"
            . '    <description>' . $this->xml('Upcoming events at ' . $title) . "</description>\n"
            . "    <language>en-us</language>\n"
            . '    <lastBuildDate>' . $now . "</lastBuildDate>\n"
            . '    <atom:link href="' . $this->xml($self) . '" rel="self" type="application/rss+xml" />' . "\n"
            . $items
            . "  </channel>\n"
            . "</rss>\n";
    }

    private function rssItemHtml(array $event): string
    {
        $bits = [];
        $when = $this->humanWhen($event);
        if ($when) {
            $bits[] = '<p><strong>' . htmlspecialchars($when, ENT_QUOTES) . '</strong></p>';
        }
        if ($loc = $this->venueLocation($event)) {
            $bits[] = '<p>' . htmlspecialchars($loc, ENT_QUOTES) . '</p>';
        }
        if ($flyer = $this->flyerUrl($event)) {
            $bits[] = '<p><img src="' . htmlspecialchars($flyer, ENT_QUOTES) . '" alt="" /></p>';
        }
        if (!empty($event['description_public'])) {
            $bits[] = '<p>' . nl2br(htmlspecialchars((string) $event['description_public'], ENT_QUOTES)) . '</p>';
        }
        if (!empty($event['ticket_url'])) {
            $t = htmlspecialchars((string) $event['ticket_url'], ENT_QUOTES);
            $bits[] = '<p><a href="' . $t . '">Tickets</a></p>';
        }
        return implode("\n", $bits);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function eventDescription(array $event): string
    {
        $parts = [];
        if ($when = $this->humanWhen($event)) {
            $parts[] = $when;
        }
        if (!empty($event['age_restriction'])) {
            $parts[] = (string) $event['age_restriction'];
        }
        if (!empty($event['description_public'])) {
            $parts[] = trim((string) $event['description_public']);
        }
        if (!empty($event['ticket_url'])) {
            $parts[] = 'Tickets: ' . $event['ticket_url'];
        }
        $parts[] = $this->eventUrl($event);
        return implode("\n\n", array_filter($parts));
    }

    private function humanWhen(array $event): string
    {
        $date = strtotime((string) $event['date']);
        if ($date === false) {
            return '';
        }
        $out = date('D, M j, Y', $date);
        $doors = $this->fmtTime($event['doors_time'] ?? null);
        $show  = $this->fmtTime($event['show_time'] ?? null);
        if ($doors && $show) {
            $out .= ' · Doors ' . $doors . ' / Show ' . $show;
        } elseif ($show) {
            $out .= ' · ' . $show;
        } elseif ($doors) {
            $out .= ' · Doors ' . $doors;
        }
        return $out;
    }

    private function fmtTime(?string $time): string
    {
        if (!$time) {
            return '';
        }
        $ts = strtotime('1970-01-01 ' . $time);
        return $ts ? date('g:i A', $ts) : '';
    }

    private function venueLocation(array $event): string
    {
        $parts = array_filter([
            $event['venue_name']    ?? null,
            $event['venue_address'] ?? null,
            trim(implode(', ', array_filter([$event['venue_city'] ?? null, $event['venue_state'] ?? null]))),
        ]);
        return implode(', ', $parts);
    }

    private function humanType(array $event): string
    {
        $type = (string) ($event['event_type'] ?? '');
        return $type === '' ? '' : ucwords(str_replace('_', ' ', $type));
    }

    private function eventUrl(array $event): string
    {
        return $this->appUrl() . '/' . event_public_path($event);
    }

    private function flyerUrl(array $event): string
    {
        $path = (string) ($event['flyer_path'] ?? '');
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return $this->appUrl() . '/' . ltrim($path, '/');
    }

    private function imageMime(string $url): string
    {
        return match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }

    private function appUrl(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/');
    }

    private function host(): string
    {
        $host = parse_url($this->appUrl(), PHP_URL_HOST);
        return $host ?: ($_SERVER['HTTP_HOST'] ?? 'panicbooking.com');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function text(string $body, string $contentType, string $filename): Response
    {
        return new Response($body, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'public, max-age=900',
        ]);
    }
}
