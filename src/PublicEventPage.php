<?php
declare(strict_types=1);

namespace Panic;

use Panic\Events\PublicEventLookup;

use function Panic\event_public_path;

/**
 * Public event page: GET /e/{public_slug}
 *
 * public/event.html (the ?id=/?slug= address this superseded — see
 * Support::event_public_path()) is a pure client-rendered SPA shell: it
 * ships a generic <title> and no meta description or Open Graph/Twitter
 * tags at all, so search engines and social-share unfurlers — including the
 * Facebook/X/Reddit/WhatsApp buttons the page renders for itself — see
 * nothing but a blank page. This endpoint renders the same page server-side
 * with real per-event <title>/description/canonical/OG/Twitter tags and
 * schema.org Event JSON-LD, then embeds the identical <pb-public-event-page>
 * custom element + app.js/app.css event.html uses, so a human visitor gets
 * the exact same interactive page (ticket purchase, map, share buttons)
 * hydrating on top.
 *
 * Rendered dynamically per request rather than as a pre-generated static
 * file: an event's price, sold-out state, cancellation, or
 * public_visibility can change at any moment, and a stale cached page could
 * misinform a buyer or a search snippet. This mirrors TicketView's
 * (GET /t/{token}) same choice for the same reason.
 */
final class PublicEventPage extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $slug = trim((string) ($this->params['slug'] ?? ''));
        $event = $slug !== '' ? PublicEventLookup::resolve($this->db, $slug) : null;
        if ($event === null) {
            return $this->html($this->notFoundPage(), 404);
        }

        $lineup = $this->db->all(
            "SELECT display_name FROM event_lineup WHERE event_id = ? AND status != 'canceled' ORDER BY billing_order, set_time",
            [$event['id']]
        );
        $flyer = $this->db->one(
            "SELECT file_path FROM event_assets WHERE event_id = ? AND asset_type = 'flyer' AND approval_status = 'approved' ORDER BY created_at DESC LIMIT 1",
            [$event['id']]
        );
        // Same "only when we actually sell tickets here, and only currently
        // buyable tiers" filter PublicEvents/PublicTickets use, so the JSON-LD
        // offer price never disagrees with the purchase widget itself.
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

        return $this->html($this->eventPage($event, $lineup, $flyer, $ticketTypes), 200);
    }

    private function eventPage(array $event, array $lineup, ?array $flyer, array $ticketTypes): string
    {
        $title       = trim((string) ($event['title'] ?? ''));
        $titleText   = $title !== '' ? "{$title} - Panic Backstage" : 'Panic Backstage Event';
        $canonical   = $this->appUrl() . '/' . event_public_path($event);
        $description = $this->description($event, $lineup);
        $image       = $this->flyerUrl($flyer);
        $jsonLd      = $this->jsonLd($event, $lineup, $image, $ticketTypes, $canonical);
        [$cssVer, $jsVer] = $this->assetVersions();
        $appUrl = $this->appUrl();

        $titleTag     = $this->e($titleText);
        $descTag      = $this->e($description);
        $canonicalTag = $this->e($canonical);
        $imageTags    = $image !== ''
            ? "\n  <meta property=\"og:image\" content=\"" . $this->e($image) . "\">\n  <meta name=\"twitter:image\" content=\"" . $this->e($image) . "\">"
            : '';
        // Hidden/canceled/past-privacy events already 404 above; a visible
        // event's page is meant to be found, unlike login/invite/sign, which
        // carry the site-wide noindex treatment (.htaccess sensitive_html_headers).
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$titleTag}</title>
  <meta name="description" content="{$descTag}">
  <link rel="canonical" href="{$canonicalTag}">
  <meta property="og:type" content="website">
  <meta property="og:title" content="{$titleTag}">
  <meta property="og:description" content="{$descTag}">
  <meta property="og:url" content="{$canonicalTag}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{$titleTag}">
  <meta name="twitter:description" content="{$descTag}">{$imageTags}
  <script type="application/ld+json">{$jsonLd}</script>
  <link rel="apple-touch-icon" sizes="180x180" href="{$appUrl}/assets/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="{$appUrl}/assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="{$appUrl}/assets/favicon/favicon-16x16.png">
  <link rel="icon" href="{$appUrl}/assets/favicon/favicon.ico" sizes="any">
  <link rel="manifest" href="{$appUrl}/assets/favicon/site.webmanifest">
  <link rel="stylesheet" href="{$appUrl}/assets/vendor/fontawesome/all.min.css">
  <link rel="stylesheet" href="{$appUrl}/assets/app.css?v={$cssVer}">
  <!-- CDN venue map. No local vendoring. -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
</head>
<body class="public-body">
  <pb-public-event-page></pb-public-event-page>
  <script type="module" src="{$appUrl}/assets/app.js?v={$jsVer}"></script>
</body>
</html>
HTML;
    }

    private function notFoundPage(): string
    {
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Event not found</title>
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d0d12;color:#f4f4f6;font-family:system-ui,sans-serif;padding:24px;text-align:center;}
  .box{max-width:360px;}
  h1{font-size:1.2rem;margin:0 0 8px;}
  p{color:#a8a8b8;margin:0;}
</style>
</head>
<body><div class="box"><h1>Event not found</h1><p>This event link is invalid, no longer public, or has been removed.</p></div></body>
</html>
HTML;
    }

    /** Short, single-paragraph summary for <meta description>/OG/Twitter and JSON-LD. */
    private function description(array $event, array $lineup): string
    {
        $when = $this->humanWhen($event);
        $venue = trim((string) ($event['venue_name'] ?? ''));
        $support = $this->lineupLine($lineup, (string) ($event['title'] ?? ''));
        $lead = trim(implode(' ', array_filter([$when !== '' ? "{$when}." : '', $venue !== '' ? "At {$venue}." : ''])));
        $own = trim((string) ($event['description_public'] ?? ''));
        // Prefer the event's own public blurb (it's already written for public
        // consumption), with the date/venue/lineup facts folded in ahead of it
        // when there's room — those facts are what actually helps a search
        // snippet or a share-card reader decide to click.
        $pieces = array_filter([$lead, $support, $own]);
        $text = implode(' ', $pieces);
        return $this->clip($text !== '' ? $text : trim((string) ($event['title'] ?? 'Event')), 300);
    }

    private function humanWhen(array $event): string
    {
        $ts = strtotime((string) ($event['date'] ?? ''));
        if ($ts === false) {
            return '';
        }
        $out = date('l, F j, Y', $ts);
        $show = $this->fmtTime($event['show_time'] ?? null);
        $doors = $this->fmtTime($event['doors_time'] ?? null);
        if ($doors && $show) {
            return "{$out} \u{2014} Doors {$doors}, Show {$show}";
        }
        if ($show) {
            return "{$out} \u{2014} Show {$show}";
        }
        return $out;
    }

    private function fmtTime(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts !== false ? date('g:i A', $ts) : '';
    }

    private function lineupLine(array $lineup, string $headliner): string
    {
        $support = array_values(array_filter(
            array_map(static fn (array $row) => trim((string) $row['display_name']), $lineup),
            static fn (string $name) => $name !== '' && strcasecmp($name, $headliner) !== 0
        ));
        return $support ? 'With ' . implode(', ', $support) . '.' : '';
    }

    private function flyerUrl(?array $flyer): string
    {
        $path = trim((string) ($flyer['file_path'] ?? ''));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return $this->appUrl() . '/' . ltrim($path, '/');
    }

    /**
     * schema.org Event structured data — the payload Google's rich-results
     * event snippet reads. JSON_HEX_* flags make the output safe to place
     * directly inside a <script> tag (escapes </script>-breakout characters).
     */
    private function jsonLd(array $event, array $lineup, string $image, array $ticketTypes, string $canonical): string
    {
        $date = (string) ($event['date'] ?? '');
        $startTime = trim((string) ($event['show_time'] ?? $event['doors_time'] ?? '')) ?: '19:00:00';
        $start = $this->isoDateTime($date, $startTime);

        $endDate = trim((string) ($event['end_date'] ?? '')) ?: $date;
        $endTime = trim((string) ($event['end_time'] ?? ''));
        $end = $endTime !== '' ? $this->isoDateTime($endDate, $endTime) : null;

        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => trim((string) ($event['address'] ?? '')) ?: null,
            'addressLocality' => trim((string) ($event['city'] ?? '')) ?: null,
            'addressRegion'   => trim((string) ($event['state'] ?? '')) ?: null,
        ]);

        $data = array_filter([
            '@context'            => 'https://schema.org',
            '@type'                => 'Event',
            'name'                 => trim((string) ($event['title'] ?? '')) ?: null,
            'startDate'            => $start,
            'endDate'              => $end,
            'eventStatus'          => (string) ($event['status'] ?? '') === 'canceled'
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode'  => 'https://schema.org/OfflineEventAttendanceMode',
            'description'          => trim((string) ($event['description_public'] ?? '')) ?: null,
            'image'                => $image !== '' ? [$image] : null,
            'url'                  => $canonical,
            'location'             => array_filter([
                '@type'   => 'Place',
                'name'    => trim((string) ($event['venue_name'] ?? '')) ?: null,
                'address' => $address ?: null,
            ]),
            'performer'            => array_values(array_filter(array_map(
                static fn (array $row): ?array => trim((string) $row['display_name']) !== ''
                    ? ['@type' => 'PerformingGroup', 'name' => trim((string) $row['display_name'])]
                    : null,
                $lineup
            ))) ?: null,
            'offers'               => $this->offers($event, $ticketTypes, $canonical),
        ], static fn ($v) => $v !== null && $v !== []);

        return (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    private function offers(array $event, array $ticketTypes, string $url): ?array
    {
        if ($event['ticketing_mode'] === 'internal' && $ticketTypes) {
            $prices = array_map(static fn (array $t) => (float) ($t['price_cents'] ?? 0) / 100, $ticketTypes);
            $low = min($prices);
            return [
                '@type'         => 'Offer',
                'price'         => number_format($low, 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => $url,
            ];
        }
        $price = $event['ticket_price'] ?? null;
        if ($price !== null && (float) $price > 0) {
            return [
                '@type'         => 'Offer',
                'price'         => number_format((float) $price, 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => $event['ticket_url'] ?: $url,
            ];
        }
        return null;
    }

    private function isoDateTime(string $date, string $time): ?string
    {
        if ($date === '') {
            return null;
        }
        try {
            // No explicit timezone argument — this runs under the same
            // ambient default timezone (America/Los_Angeles) bootstrap.php
            // sets for all other human-facing date formatting (see the
            // db_timestamp_to_epoch() doc comment in Support.php); DateTime's
            // ATOM format includes that zone's UTC offset in the output.
            return (new \DateTimeImmutable(trim("{$date} {$time}")))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pulls the current app.css/app.js cache-busting ?v= numbers straight out
     * of public/event.html instead of hardcoding them here, so the two never
     * drift apart when event.html's version bumps.
     */
    private function assetVersions(): array
    {
        $html = @file_get_contents($this->root . '/public/event.html') ?: '';
        preg_match('/app\.css\?v=(\d+)/', $html, $cssMatch);
        preg_match('/app\.js\?v=(\d+)/', $html, $jsMatch);
        return [$cssMatch[1] ?? '1', $jsMatch[1] ?? '1'];
    }

    private function appUrl(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Truncate to a max length on a word boundary, appending an ellipsis. */
    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
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

    private function html(string $body, int $status): Response
    {
        // This response never carries a literal .html extension, so it's
        // outside the reach of root .htaccess's "never let the browser hold
        // a stale HTML shell" <FilesMatch "\.html$"> rule — set the same
        // Cache-Control here explicitly so a revalidate-on-load policy still
        // applies to this dynamically-rendered page.
        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache, must-revalidate'];
        if ($status !== 200) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        return new Response($body, $status, $headers);
    }
}
