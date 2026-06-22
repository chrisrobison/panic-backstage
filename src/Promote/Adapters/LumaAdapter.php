<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Luma event-creation adapter.
 *
 * Creates a Luma event from our event + post data via the Luma Public API v1.
 *
 * API base:  https://public-api.luma.com
 * Auth:      x-luma-api-key header
 * Docs:      https://docs.luma.com/reference/post_v1-events-create.md
 *
 * Required fields: name, start_at, timezone
 * Optional:  description_md, end_at, geo_address_json, max_capacity,
 *            meeting_url (online link), visibility
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Log in to lu.ma and open your calendar.
 *   2. Go to Settings → API → Generate key.
 *   3. Paste the key into Settings → Promote → Luma → API Key.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LumaAdapter
{
    private const BASE    = 'https://public-api.luma.com';
    private const TZ      = 'America/Los_Angeles';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Create a Luma event.
     *
     * @param  array  $event    DB events row (joined with venues)
     * @param  array  $post     DB promote_posts row
     * @param  string $sendMode 'now' | 'scheduled' — Luma publishes immediately on
     *                          create; we record 'queued' for scheduled posts so the
     *                          operator knows to check the Luma event date is correct.
     * @return array{status: string, external_url: string|null, error_message: string|null, response_json: string|null}
     */
    public function dispatch(array $event, array $post, string $sendMode): array
    {
        try {
            $lumaId = $this->createEvent($event, $post);

            // Luma event URLs are https://lu.ma/{id}
            $externalUrl = "https://lu.ma/{$lumaId}";
            $status      = $sendMode === 'scheduled' ? 'queued' : 'sent';

            return [
                'status'        => $status,
                'external_url'  => $externalUrl,
                'error_message' => null,
                'response_json' => json_encode(['luma_event_id' => $lumaId]) ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => $e->getMessage(),
                'response_json' => null,
            ];
        }
    }

    // ── Private: event creation ───────────────────────────────────────────────

    private function createEvent(array $event, array $post): string
    {
        $title     = (string) ($event['title'] ?? $post['title'] ?? 'Untitled Event');
        $dateStr   = (string) ($event['date']       ?? '');
        $showTime  = (string) ($event['show_time']  ?? '20:00:00');
        $doorsTime = (string) ($event['doors_time'] ?? '');

        if (!$dateStr) {
            throw new \RuntimeException('Event is missing a date — cannot create Luma listing.');
        }

        // Doors time is the public "start" (what attendees experience first);
        // show time + 3 h is a reasonable end estimate.
        $startTime = $doorsTime ?: $showTime;
        $startTs   = strtotime("$dateStr $startTime");
        $endTs     = strtotime("$dateStr $showTime") + 3 * 3600;

        if (!$startTs) {
            throw new \RuntimeException("Could not parse event date/time: $dateStr $startTime");
        }

        $startAt = gmdate('Y-m-d\TH:i:s\Z', $startTs);
        $endAt   = gmdate('Y-m-d\TH:i:s\Z', $endTs);

        $payload = [
            'name'           => $title,
            'start_at'       => $startAt,
            'end_at'         => $endAt,
            'timezone'       => self::TZ,
            'description_md' => $this->buildDescriptionMd($event, $post),
        ];

        // Physical venue address
        $address = $this->buildAddress($event);
        if ($address) {
            $payload['geo_address_json'] = [
                'type'    => 'manual',
                'address' => $address,
            ];
        }

        // Capacity
        $capacity = (int) ($event['capacity'] ?? 0);
        if ($capacity > 0) {
            $payload['max_capacity'] = $capacity;
        }

        $response = $this->apiRequest('POST', '/v1/events/create', $payload);

        $lumaId = $response['id'] ?? null;
        if (!$lumaId) {
            throw new \RuntimeException(
                'Luma did not return an event ID — response: ' . json_encode($response)
            );
        }

        return (string) $lumaId;
    }

    // ── Private: copy builders ────────────────────────────────────────────────

    /**
     * Build a Markdown description from event + post data.
     * Luma renders Markdown natively; this produces clean output in the listing.
     */
    private function buildDescriptionMd(array $event, array $post): string
    {
        $body      = trim((string) ($post['master_text'] ?? ''));
        $venue     = (string) ($event['venue_name']     ?? getenv('VENUE_NAME') ?: 'Venue');
        $city      = (string) ($event['venue_city']     ?? 'San Francisco');
        $state     = (string) ($event['venue_state']    ?? 'CA');
        $age       = (string) ($event['age_restriction'] ?? '');
        $doors     = (string) ($event['doors_time']     ?? '');
        $show      = (string) ($event['show_time']      ?? '');
        $ticketUrl = (string) ($post['target_url']      ?? $event['ticket_url'] ?? '');

        $parts = [];

        if ($body) {
            $parts[] = $body;
        }

        // Venue + times block
        $meta = [];
        $meta[] = "**{$venue}** · {$city}, {$state}";
        if ($doors) {
            $meta[] = '🚪 Doors: ' . date('g:ia', strtotime($doors));
        }
        if ($show) {
            $meta[] = '🎵 Show: ' . date('g:ia', strtotime($show));
        }
        if ($age) {
            $meta[] = "🔞 {$age}";
        }
        $parts[] = implode('  ' . PHP_EOL, $meta);   // two trailing spaces = Markdown line break

        // Ticket link as a Markdown link
        if ($ticketUrl) {
            $parts[] = "[🎟 Buy Tickets]({$ticketUrl})";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build a human-readable venue address string for geo_address_json.
     * Uses the events.venue join fields when available; falls back to a
     * known default for Mabuhay Gardens.
     */
    private function buildAddress(array $event): string
    {
        $venueName = (string) ($event['venue_name']    ?? '');
        $address   = (string) ($event['venue_address'] ?? '');
        $city      = (string) ($event['venue_city']    ?? '');
        $state     = (string) ($event['venue_state']   ?? '');
        $zip       = (string) ($event['venue_zip']     ?? '');

        // Prefer structured address from DB
        if ($address && $city) {
            $line = $venueName ? "$venueName, $address" : $address;
            $line .= ", $city";
            if ($state) $line .= ", $state";
            if ($zip)   $line .= " $zip";
            return $line;
        }

        // Fall back to venue name + city
        if ($city) {
            $prefix = $venueName ?: getenv('VENUE_NAME') ?: 'Venue';
            return "$prefix, $city" . ($state ? ", $state" : '');
        }

        // Last-resort fallback using configured venue name
        $fallbackName = getenv('VENUE_NAME') ?: 'Venue';
        $fallbackCity = getenv('VENUE_CITY') ?: '';
        $fallbackState = getenv('VENUE_STATE') ?: '';
        return $fallbackCity
            ? "$fallbackName, $fallbackCity" . ($fallbackState ? ", $fallbackState" : '')
            : $fallbackName;
    }

    // ── Private: HTTP ─────────────────────────────────────────────────────────

    private function apiRequest(string $method, string $path, array $payload): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'x-luma-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Luma cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $msg = $data['message'] ?? $data['error'] ?? "HTTP $status";
            throw new \RuntimeException("Luma API error ($status): $msg");
        }

        return $data;
    }
}
