<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Eventbrite event-creation adapter.
 *
 * Creates a draft Eventbrite event from our event + post data, optionally
 * creates a ticket class, then publishes it (when send_mode = 'now').
 *
 * Required env vars:
 *   EVENTBRITE_API_KEY   — private token from eventbrite.com/account-settings/apps
 *   EVENTBRITE_ORG_ID    — your organizer's numeric org ID (see setup note below)
 *
 * Optional env vars:
 *   EVENTBRITE_VENUE_ID  — pre-created Eventbrite venue ID for Mabuhay Gardens.
 *                          If omitted, venue info is embedded in the description.
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Log in to eventbrite.com (christopher.robison@gmail.com).
 *   2. Go to Account → Create & Manage Events → click "Create an event".
 *      Eventbrite will prompt you to create an Organizer profile the first time.
 *   3. After creating the Organizer, hit:
 *        GET /api/promote/eventbrite/org
 *      The endpoint will call the Eventbrite API, return your org IDs, and you
 *      can paste the correct one into EVENTBRITE_ORG_ID in .env.
 *   4. (Optional) Pre-create a venue for Mabuhay Gardens in Eventbrite's UI,
 *      then set EVENTBRITE_VENUE_ID to that venue's numeric ID.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class EventbriteAdapter
{
    private const BASE    = 'https://www.eventbriteapi.com/v3';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $orgId,
    ) {}

    /**
     * Create (and optionally publish) an Eventbrite event.
     *
     * @param  array  $event    DB events row (joined with venues if available)
     * @param  array  $post     DB promote_posts row
     * @param  string $sendMode 'now' | 'scheduled'
     * @return array{status: string, external_url: string|null, error_message: string|null, response_json: string|null}
     */
    public function dispatch(array $event, array $post, string $sendMode): array
    {
        if (!$this->orgId) {
            return $this->result('needs_auth', null, 'EVENTBRITE_ORG_ID not configured. See class docblock for setup steps.');
        }

        try {
            $ebEventId = $this->createEvent($event, $post);
            $this->createTicketClass($ebEventId, $event);

            if ($sendMode === 'now') {
                $this->publishEvent($ebEventId);
            }

            $url    = "https://www.eventbrite.com/e/{$ebEventId}";
            $status = $sendMode === 'scheduled' ? 'queued' : 'sent';
            return $this->result($status, $url, null, json_encode(['eventbrite_event_id' => $ebEventId]));

        } catch (\Throwable $e) {
            return $this->result('failed', null, $e->getMessage());
        }
    }

    // ── Private: event creation ───────────────────────────────────────────────

    private function createEvent(array $event, array $post): string
    {
        $title      = (string) ($event['title'] ?? $post['title'] ?? 'Untitled Event');
        $tz         = 'America/Los_Angeles';
        $dateStr    = (string) ($event['date'] ?? '');
        $showTime   = (string) ($event['show_time'] ?? '20:00:00');
        $doorsTime  = (string) ($event['doors_time'] ?? '');

        if (!$dateStr) {
            throw new \RuntimeException('Event is missing a date — cannot create Eventbrite listing');
        }

        // Use doors time for the "start" shown on Eventbrite if available,
        // show time as the programme start, end is estimated 3 h after show.
        $doorsDt = $doorsTime ? strtotime("$dateStr $doorsTime") : strtotime("$dateStr $showTime");
        $showDt  = strtotime("$dateStr $showTime");
        $endDt   = $showDt + 3 * 3600;

        $startUtc = gmdate('Y-m-d\TH:i:s\Z', $doorsDt);
        $endUtc   = gmdate('Y-m-d\TH:i:s\Z', $endDt);

        $payload = [
            'event' => [
                'name'           => ['html' => htmlspecialchars($title, ENT_QUOTES | ENT_HTML5)],
                'description'    => ['html' => $this->buildDescriptionHtml($event, $post)],
                'start'          => ['timezone' => $tz, 'utc' => $startUtc],
                'end'            => ['timezone' => $tz, 'utc' => $endUtc],
                'currency'       => 'USD',
                'online_event'   => false,
                'listed'         => true,
                'shareable'      => true,
                'show_remaining' => false,
            ],
        ];

        $venueId = (string) (getenv('EVENTBRITE_VENUE_ID') ?: '');
        if ($venueId) {
            $payload['event']['venue_id'] = $venueId;
        }

        $data = $this->apiPost("/organizations/{$this->orgId}/events/", $payload);

        if (empty($data['id'])) {
            throw new \RuntimeException('Eventbrite returned no event ID — response: ' . json_encode($data));
        }

        return (string) $data['id'];
    }

    private function createTicketClass(string $eventId, array $event): void
    {
        $ticketUrl = (string) ($event['ticket_url'] ?? '');
        $capacity  = (int) ($event['capacity'] ?? 0);

        $payload = [
            'ticket_class' => [
                'name'           => $ticketUrl ? 'General Admission' : 'Free Admission',
                'free'           => !$ticketUrl,
                'quantity_total' => $capacity > 0 ? $capacity : 500,
            ],
        ];

        $this->apiPost("/events/{$eventId}/ticket_classes/", $payload);
    }

    private function publishEvent(string $eventId): void
    {
        $this->apiPost("/events/{$eventId}/publish/", []);
    }

    // ── Private: copy helpers ─────────────────────────────────────────────────

    private function buildDescriptionHtml(array $event, array $post): string
    {
        $body      = trim((string) ($post['master_text'] ?? ''));
        $venue     = (string) ($event['venue_name']  ?? 'Mabuhay Gardens');
        $city      = (string) ($event['venue_city']  ?? 'San Francisco');
        $state     = (string) ($event['venue_state'] ?? 'CA');
        $age       = (string) ($event['age_restriction'] ?? '');
        $doors     = (string) ($event['doors_time'] ?? '');
        $show      = (string) ($event['show_time']  ?? '');
        $ticketUrl = (string) ($post['target_url']  ?? $event['ticket_url'] ?? '');

        $paragraphs = [];

        if ($body) {
            $paragraphs[] = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5)) . '</p>';
        }

        $meta = [];
        $meta[] = htmlspecialchars("$venue · $city, $state", ENT_QUOTES | ENT_HTML5);
        if ($doors) $meta[] = 'Doors: ' . htmlspecialchars(date('g:ia', strtotime($doors)), ENT_QUOTES | ENT_HTML5);
        if ($show)  $meta[] = 'Show: '  . htmlspecialchars(date('g:ia', strtotime($show)),  ENT_QUOTES | ENT_HTML5);
        if ($age)   $meta[] = htmlspecialchars($age, ENT_QUOTES | ENT_HTML5);
        $paragraphs[] = '<p>' . implode('<br>', $meta) . '</p>';

        if ($ticketUrl) {
            $safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_HTML5);
            $paragraphs[] = '<p><a href="' . $safeUrl . '">Buy Tickets</a></p>';
        }

        return implode("\n", $paragraphs);
    }

    // ── Private: HTTP ─────────────────────────────────────────────────────────

    private function apiPost(string $path, array $payload): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Eventbrite cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $desc = $data['error_description'] ?? ($data['error'] ?? "HTTP $status");
            throw new \RuntimeException("Eventbrite API error ($status): $desc");
        }

        return $data;
    }

    private function result(string $status, ?string $url, ?string $error, ?string $json = null): array
    {
        return [
            'status'        => $status,
            'external_url'  => $url,
            'error_message' => $error,
            'response_json' => $json,
        ];
    }
}
