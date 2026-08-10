<?php
declare(strict_types=1);

namespace Panic;

/**
 * Minimal, zero-dependency Google Calendar writer for the staff event mirror.
 *
 * Authenticates as a *service account* using the same JWT-bearer flow as
 * GoogleSheets (RS256 via ext-openssl, exchanged for an OAuth2 access token),
 * then creates/patches/deletes events through the Calendar REST API. No
 * Composer packages required.
 *
 * Setup (one-time, done in Google Cloud + Google Calendar):
 *   1. GCP project -> enable "Google Calendar API".
 *   2. Reuse the existing service account key (GOOGLE_SA_KEY_FILE) — the same
 *      credential already used for Sheets; only the OAuth *scope* differs.
 *   3. In the target calendar's settings -> "Share with specific people", add
 *      the service account's client_email with "Make changes to events".
 *      The service account is external to the Workspace domain, so if the
 *      admin restricts off-domain sharing this option is hidden or silently
 *      downgrades to free/busy — that is the usual cause of a 404 below.
 *   4. Point .env at the calendar:
 *        GOOGLE_CALENDAR_ID=bookings@themab.org
 *        GCAL_SYNC_ENABLED=1
 *
 * IMPORTANT: the key file must be readable by the web/cron user but not
 * world-readable — `-rw-r----- cdr www-data`, exactly like .env.
 *
 * Direction: this mirror is strictly ONE-WAY (Backstage -> Google). Nothing is
 * ever read back into the app, so a staff member editing an event in Google
 * will simply have their edit overwritten on the next sweep. Events created by
 * hand in Google are never touched: the reconcile step only considers events
 * tagged with our own `panicApp` extended property.
 *
 * Cancellations: a canceled event is DELETED from the calendar and unlinked,
 * freeing the night. (An earlier revision kept a "CANCELED — " entry instead;
 * removal is the deliberate current policy — don't "fix" it back.)
 *
 * Every public method swallows its own errors, logs to
 * storage/logs/calendar-sync.log, and returns null/false on failure so a
 * Google problem never breaks the caller.
 */
final class GoogleCalendar
{
    private const SCOPE     = 'https://www.googleapis.com/auth/calendar';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/calendar/v3/calendars';

    /** Default show length when an event has a start but no end_time. Matches Feed.php. */
    private const DEFAULT_DURATION_HOURS = 3;

    /** Fallback start for a timed event with no show_time/doors_time. Matches Feed.php. */
    private const DEFAULT_START_TIME = '19:00:00';

    /**
     * Marker written into every event we create, so the reconcile sweep can
     * tell "ours" from entries staff added by hand. Queried back via the
     * `privateExtendedProperty` filter.
     */
    public const TAG_KEY   = 'panicApp';
    public const TAG_VALUE = '1';

    /** Companion marker holding the Backstage event id, for orphan cleanup. */
    public const ID_KEY = 'panicEventId';

    /**
     * Statuses that never reach the calendar. `empty` rows are placeholder
     * shells created by the grid UI, not bookings — pushing them would litter
     * the team's calendar with untitled noise.
     */
    public const SKIP_STATUSES = ['empty'];

    /**
     * Statuses that must not remain on the calendar. An event that reaches one
     * of these is DELETED from Google and unlinked (gcal_event_id cleared), so
     * the night reads as free.
     */
    public const REMOVE_STATUSES = ['canceled'];

    /** Status -> title prefix. Anything unlisted renders with a bare title. */
    public const TITLE_PREFIX = [
        'proposed' => 'HOLD — ',
    ];

    /** True when this event should not exist on the calendar at all. */
    public static function shouldRemove(string $status): bool
    {
        return in_array($status, self::REMOVE_STATUSES, true);
    }

    private ?array $key = null;
    private string $logFile;
    private string $cacheFile;
    private ?string $keyFile;
    private string $calendarId;

    public function __construct(string $root)
    {
        $logsDir         = \Panic\Tenant\TenantContext::clientDir($root) . '/logs';
        $this->logFile   = $logsDir . '/calendar-sync.log';
        // Deliberately NOT the Sheets cache path: the cached bearer token is
        // scope-specific, and replaying a sheets-scoped token against Calendar
        // fails as an opaque 403.
        $this->cacheFile  = sys_get_temp_dir() . '/backstage-calendar-token.json';
        $this->keyFile    = getenv('GOOGLE_SA_KEY_FILE') ?: null;
        $this->calendarId = (string) (getenv('GOOGLE_CALENDAR_ID') ?: '');

        @mkdir(dirname($this->logFile), 0755, true);
    }

    /**
     * Master kill switch. Set `GCAL_SYNC_ENABLED=0` in .env to make the mirror
     * inert without removing the cron entry. Absent/blank means ENABLED so a
     * fresh install works once the calendar id is set; only an explicit falsey
     * value turns it off.
     */
    public static function syncEnabled(): bool
    {
        $raw = getenv('GCAL_SYNC_ENABLED');
        if ($raw === false || trim((string) $raw) === '') {
            return true;
        }
        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'off', 'no', 'n'], true);
    }

    public function isConfigured(): bool
    {
        return $this->keyFile !== null && $this->keyFile !== '' && $this->calendarId !== '';
    }

    public function calendarId(): string
    {
        return $this->calendarId;
    }

    // ─── Event mapping ───────────────────────────────────────────────────────────

    /**
     * Build the Calendar API resource for a Backstage event row.
     *
     * Holds legitimately carry no times — commit f469666 stopped the app
     * inventing them — so a row with no doors/show/end time becomes an
     * ALL-DAY entry rather than a fabricated 7pm slot. Google's all-day `end`
     * is exclusive, hence the +1 day.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function eventBody(array $event, string $appUrl): array
    {
        $tzName = (string) ($event['venue_timezone'] ?? '' ?: 'America/Los_Angeles');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $tz     = new \DateTimeZone('America/Los_Angeles');
            $tzName = 'America/Los_Angeles';
        }

        $status = (string) ($event['status'] ?? '');
        $prefix = self::TITLE_PREFIX[$status] ?? '';
        $title  = trim((string) ($event['title'] ?? '')) ?: '(untitled event)';

        $date     = (string) $event['date'];
        $hasTimes = !empty($event['load_in_time']) || !empty($event['doors_time']) || !empty($event['show_time'])
            || !empty($event['end_time']) || !empty($event['load_out_time']);

        if ($hasTimes) {
            // Staff calendar blocks the operational occupancy window. Public
            // show times still appear in the description below.
            $startTime = (string) ($event['load_in_time'] ?: $event['doors_time'] ?: $event['show_time'] ?: self::DEFAULT_START_TIME);
            $start     = new \DateTime($date . ' ' . $startTime, $tz);

            $occupancyEnd = $event['load_out_time'] ?: $event['end_time'];
            if (!empty($occupancyEnd)) {
                $endDate = !empty($event['end_date']) ? (string) $event['end_date'] : $date;
                $end     = new \DateTime($endDate . ' ' . $occupancyEnd, $tz);
                if ($end < $start) {
                    $end->modify('+1 day'); // show runs past midnight
                } elseif ($end == $start) {
                    // end_time == start_time means "no real end recorded", not a
                    // 24-hour booking. Feed.php's `<=` rolls these to +1 day and
                    // renders a day-long block; use the normal default instead.
                    $end = (clone $start)->modify('+' . self::DEFAULT_DURATION_HOURS . ' hours');
                }
            } else {
                $end = (clone $start)->modify('+' . self::DEFAULT_DURATION_HOURS . ' hours');
            }

            $when = [
                'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tzName],
                'end'   => ['dateTime' => $end->format('Y-m-d\TH:i:s'),   'timeZone' => $tzName],
            ];
        } else {
            $endDate = !empty($event['end_date']) ? (string) $event['end_date'] : $date;
            $endExcl = (new \DateTime($endDate, $tz))->modify('+1 day')->format('Y-m-d');
            $when    = [
                'start' => ['date' => $date],
                'end'   => ['date' => $endExcl],
            ];
        }

        $body = $when + [
            'summary'     => $prefix . $title,
            'description' => self::description($event, $appUrl),
            'location'    => self::location($event),
            'extendedProperties' => [
                'private' => [
                    self::TAG_KEY => self::TAG_VALUE,
                    self::ID_KEY  => (string) (int) $event['id'],
                ],
            ],
            // Staff calendar: an alert on every hold would be noise.
            'reminders'   => ['useDefault' => false, 'overrides' => []],
            'status'      => 'confirmed',
            'transparency' => 'opaque',
        ];

        if ($body['location'] === '') {
            unset($body['location']);
        }

        return $body;
    }

    /** @param array<string, mixed> $event */
    private static function description(array $event, string $appUrl): string
    {
        $lines  = [];
        $status = (string) ($event['status'] ?? '');
        $lines[] = 'Status: ' . ucwords(str_replace('_', ' ', $status));

        if (!empty($event['event_type'])) {
            $lines[] = 'Type: ' . ucwords(str_replace('_', ' ', (string) $event['event_type']));
        }
        if (!empty($event['room'])) {
            $lines[] = 'Room: ' . ucfirst((string) $event['room']);
        }

        $times = [];
        if (!empty($event['load_in_time'])) $times[] = 'Load-in ' . self::hhmm((string) $event['load_in_time']);
        if (!empty($event['doors_time']))   $times[] = 'Doors ' . self::hhmm((string) $event['doors_time']);
        if (!empty($event['show_time']))    $times[] = 'Show ' . self::hhmm((string) $event['show_time']);
        if (!empty($event['load_out_time'])) $times[] = 'Load-out ' . self::hhmm((string) $event['load_out_time']);
        if ($times) {
            $lines[] = implode(' · ', $times);
        }

        if (!empty($event['promoter_name'])) {
            $lines[] = 'Contract contact: ' . $event['promoter_name'];
        }
        if (!empty($event['booker_name'])) {
            $lines[] = 'Booker: ' . $event['booker_name'];
        }
        if (!empty($event['capacity'])) {
            $lines[] = 'Capacity: ' . (int) $event['capacity'];
        }

        $lines[] = '';
        $lines[] = rtrim($appUrl, '/') . '/event.html?id=' . (int) $event['id'];
        $lines[] = '';
        $lines[] = '— Mirrored from Panic Backstage. Edits here are overwritten on the next sync.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $event */
    private static function location(array $event): string
    {
        $parts = array_filter([
            $event['venue_name']    ?? null,
            $event['venue_address'] ?? null,
        ], static fn ($v) => trim((string) $v) !== '');

        return implode(', ', array_map(static fn ($v) => trim((string) $v), $parts));
    }

    private static function hhmm(string $time): string
    {
        $ts = strtotime($time);
        return $ts === false ? $time : date('g:i A', $ts);
    }

    // ─── API operations ──────────────────────────────────────────────────────────

    /**
     * Create an event. Returns the new Google event id, or null on failure.
     *
     * @param array<string, mixed> $body
     */
    public function createEvent(array $body): ?string
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        [$code, $resp] = $this->http('POST', $this->eventsUrl(), $token, $body);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['id'])) {
            $this->log("FAIL: create '{$body['summary']}' -> HTTP {$code} " . substr($resp, 0, 300));
            return null;
        }
        return (string) $json['id'];
    }

    /**
     * Patch an existing event.
     *
     * Returns the HTTP status so the caller can distinguish "gone" (404/410 —
     * someone deleted it by hand in Google, so the stored id should be cleared
     * and the event re-created) from a transient failure worth retrying.
     *
     * @param array<string, mixed> $body
     */
    public function patchEvent(string $eventId, array $body): int
    {
        $token = $this->accessToken();
        if ($token === null) {
            return 0;
        }
        [$code, $resp] = $this->http('PATCH', $this->eventsUrl($eventId), $token, $body);
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: patch {$eventId} -> HTTP {$code} " . substr($resp, 0, 300));
        }
        return $code;
    }

    /** Delete an event. 204 = deleted, 404/410 = already gone (also fine). */
    public function deleteEvent(string $eventId): int
    {
        $token = $this->accessToken();
        if ($token === null) {
            return 0;
        }
        [$code, $resp] = $this->http('DELETE', $this->eventsUrl($eventId), $token, null);
        if ($code >= 300 && $code !== 404 && $code !== 410) {
            $this->log("FAIL: delete {$eventId} -> HTTP {$code} " . substr($resp, 0, 300));
        }
        return $code;
    }

    /**
     * List the events on the calendar that WE created, from `$since` onward.
     *
     * Filtered server-side by our private extended property, so hand-made staff
     * entries are never returned and therefore never at risk of deletion.
     *
     * @return array<int, array{id: string, appEventId: int, summary: string}>|null null on failure
     */
    public function listAppEvents(\DateTimeInterface $since): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        $out       = [];
        $pageToken = null;

        do {
            $params = [
                'privateExtendedProperty' => self::TAG_KEY . '=' . self::TAG_VALUE,
                'timeMin'                 => $since->format(\DateTimeInterface::RFC3339),
                'maxResults'              => '250',
                'singleEvents'            => 'true',
                'showDeleted'             => 'false',
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $url = $this->eventsUrl() . '?' . http_build_query($params);

            [$code, $resp] = $this->http('GET', $url, $token, null);
            $json = json_decode($resp, true);
            if ($code < 200 || $code >= 300 || !is_array($json)) {
                $this->log("FAIL: list -> HTTP {$code} " . substr($resp, 0, 300));
                return null;
            }

            foreach (($json['items'] ?? []) as $item) {
                $appId = $item['extendedProperties']['private'][self::ID_KEY] ?? null;
                $out[] = [
                    'id'         => (string) ($item['id'] ?? ''),
                    'appEventId' => (int) $appId,
                    'summary'    => (string) ($item['summary'] ?? ''),
                ];
            }

            $pageToken = $json['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $out;
    }

    private function eventsUrl(?string $eventId = null): string
    {
        $url = self::API_BASE . '/' . rawurlencode($this->calendarId) . '/events';
        if ($eventId !== null) {
            $url .= '/' . rawurlencode($eventId);
        }
        return $url;
    }

    // ─── Auth (mirrors GoogleSheets; only SCOPE and the cache path differ) ────────

    private function accessToken(): ?string
    {
        $cached = @json_decode((string) @file_get_contents($this->cacheFile), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return (string) $cached['token'];
        }

        $key = $this->loadKey();
        if ($key === null) {
            return null;
        }

        $now   = time();
        $claim = [
            'iss'   => $key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $key['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $jwt = $this->signJwt($claim, $key['private_key']);
        if ($jwt === null) {
            return null;
        }

        [$code, $resp] = $this->httpForm(self::TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['access_token'])) {
            $this->log("FAIL: token exchange -> HTTP {$code} {$resp}");
            return null;
        }

        $token = (string) $json['access_token'];
        $exp   = $now + (int) ($json['expires_in'] ?? 3600);
        @file_put_contents($this->cacheFile, json_encode(['token' => $token, 'exp' => $exp]));
        @chmod($this->cacheFile, 0600);
        return $token;
    }

    /** @return array{client_email:string,private_key:string,token_uri:string}|null */
    private function loadKey(): ?array
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!$this->keyFile) {
            $this->log('skip: GOOGLE_SA_KEY_FILE not set');
            return null;
        }
        if (!is_readable($this->keyFile)) {
            $this->log("FAIL: key file not readable: {$this->keyFile} (check perms — must be readable by the cron/web user)");
            return null;
        }
        $json = json_decode((string) file_get_contents($this->keyFile), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            $this->log('FAIL: key file missing client_email/private_key');
            return null;
        }
        return $this->key = [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'token_uri'    => (string) ($json['token_uri'] ?? self::TOKEN_URI),
        ];
    }

    /** @param array<string, mixed> $claim */
    private function signJwt(array $claim, string $privateKey): ?string
    {
        $segments = [
            $this->b64u((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64u((string) json_encode($claim)),
        ];
        $input = implode('.', $segments);
        $sig   = '';
        if (!openssl_sign($input, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->log('FAIL: openssl_sign on JWT (bad private key?)');
            return null;
        }
        $segments[] = $this->b64u($sig);
        return implode('.', $segments);
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ─── HTTP ────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{0:int,1:string} [httpCode, body]
     */
    private function http(string $method, string $url, string $token, ?array $jsonBody): array
    {
        $ch      = curl_init($url);
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $opts    = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, "curl: {$err}"];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }

    /**
     * @param array<string, string> $form
     * @return array{0:int,1:string} [httpCode, body]
     */
    private function httpForm(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, "curl: {$err}"];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }

    public function log(string $msg): void
    {
        @file_put_contents($this->logFile, sprintf("[%s] %s\n", date('c'), $msg), FILE_APPEND);
    }
}
