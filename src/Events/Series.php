<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Capabilities;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;
use function Panic\series_public_path;
use function Panic\slugify;

/**
 * Recurring events: `event_series` is a lightweight grouping + pattern
 * record. Occurrences are ordinary `events` rows created up front (not a
 * virtual/computed repeat), so each one keeps its own contract, staffing,
 * ticketing, guest list, etc. Editing one occurrence never touches the
 * others — series membership is only used for grouping/display.
 *
 * The recurrence pattern (weekly/monthly, interval, weekday, ordinal…) is
 * computed client-side (see public/assets/recurrence.js). This endpoint
 * never re-derives dates from a pattern — it receives the resulting explicit
 * date list, validates it, and stores the pattern/description alongside it
 * purely for later display.
 */
final class Series extends BaseEndpoint
{
    use EventRowHelpers;

    /**
     * Hard cap on occurrences created by a single call — bounds the blast
     * radius of a mistyped pattern (e.g. an accidental "every day").
     */
    private const MAX_OCCURRENCES = 52;

    /**
     * Rolling booking-horizon cap: no occurrence may land more than this many
     * days after today (the day the series is created), regardless of the
     * pattern or how many occurrences are requested. Applied alongside
     * MAX_OCCURRENCES, not instead of it — whichever limit a given pattern
     * hits first is what actually bounds it.
     */
    private const MAX_HORIZON_DAYS = 90;

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        return match ($request->method()) {
            'GET' => $this->show($eventId),
            'POST' => $this->create($request, $eventId),
            'DELETE' => $this->remove($eventId),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(int $eventId): Response
    {
        if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
            return $denied;
        }
        $event = $this->db->one('SELECT id, series_id FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }
        if (!$event['series_id']) {
            return $this->ok(['series' => null]);
        }
        $series = $this->db->one('SELECT * FROM event_series WHERE id = ?', [$event['series_id']]);
        if ($series !== null) {
            $series['public_page'] = series_public_path($series);
        }
        $events = $this->db->all(
            'SELECT id, title, date, status, slug, external_id FROM events WHERE series_id = ? ORDER BY date',
            [$event['series_id']]
        );
        return $this->ok(['series' => $series, 'events' => $events]);
    }

    private function create(Request $request, int $eventId): Response
    {
        $body        = $request->body();
        $dates       = array_values(array_unique(array_filter(array_map('strval', (array) ($body['dates'] ?? [])))));
        $description = trim((string) ($body['description'] ?? '')) ?: null;
        $pattern     = $body['pattern'] ?? null;
        $endType     = ($body['end_type'] ?? '') === 'on_date' ? 'on_date' : 'after_count';
        $endDate     = $endType === 'on_date' ? ($body['end_date'] ?: null) : null;
        $occurrenceCount = $endType === 'after_count' ? (int) ($body['occurrence_count'] ?? (count($dates) + 1)) : null;

        $result = $this->attemptCreate(
            $eventId, $dates, $description, $pattern, $endType, $endDate, $occurrenceCount,
            $this->userId(), $this->role()
        );
        return $this->resultToResponse($result);
    }

    /**
     * Everything create() needs beyond parsing the HTTP request body:
     * capability check, anchor lookup, date validation (format/self-date/
     * MAX_OCCURRENCES/MAX_HORIZON_DAYS), room-conflict check, and the
     * transactional insert. Takes $actingUserId/$actingRole explicitly
     * (rather than reading $this->userId()/$this->role()) so it's callable
     * identically from two contexts:
     *   - create() above, an ordinary authenticated HTTP request
     *   - Ai\Assistant::applyRecurringSeries(), the AI drawer's Apply button
     *     — a human-clicked REST call that already re-validated the
     *     proposal's ownership/expiry before getting here
     * Both run through *this exact same* validation and insert code, so the
     * AI path can never create a series the human "Create recurring events"
     * button wouldn't also allow. Returns a plain array (not a Response) so
     * callers that aren't building an HTTP response (the MCP propose tool's
     * dry run, see previewSeries()) can consume the same result shape.
     *
     * @return array{ok: bool, status?: int, error?: string, horizon_date?: string,
     *     beyond_horizon_dates?: list<string>, conflict_dates?: list<string>,
     *     series_id?: int, created_event_ids?: list<int>}
     */
    public function attemptCreate(
        int $eventId,
        array $dates,
        ?string $description,
        mixed $pattern,
        string $endType,
        ?string $endDate,
        ?int $occurrenceCount,
        int $actingUserId,
        string $actingRole
    ): array {
        $validated = $this->validateSeries($eventId, $dates, $actingUserId, $actingRole);
        if (!$validated['ok']) {
            return $validated;
        }
        $anchor = $validated['anchor'];
        $dates  = $validated['dates'];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $publicSlug = $this->uniquePublicSlug((string) $anchor['title']);
            $seriesId = $this->db->insert(
                'INSERT INTO event_series (venue_id, title, public_slug, pattern_json, description, end_type, end_date, occurrence_count, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $anchor['venue_id'],
                    $anchor['title'],
                    $publicSlug,
                    $pattern !== null ? json_encode($pattern) : null,
                    $description,
                    $endType,
                    $endType === 'on_date' ? ($endDate ?: null) : null,
                    $endType === 'after_count' ? ($occurrenceCount ?? (count($dates) + 1)) : null,
                    $actingUserId,
                ]
            );

            $this->db->run('UPDATE events SET series_id = ? WHERE id = ?', [$seriesId, $eventId]);

            $createdIds = [];
            foreach ($dates as $date) {
                $createdIds[] = $this->cloneOccurrence($anchor, $date, $seriesId, $actingUserId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            @error_log('series create failed for event ' . $eventId . ': ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'error' => 'Could not create the series. Nothing was changed.'];
        }

        log_activity($this->db, $eventId, $actingUserId, 'recurring series created', [
            'series_id' => $seriesId,
            'occurrences' => count($createdIds),
        ]);

        return ['ok' => true, 'series_id' => $seriesId, 'created_event_ids' => $createdIds];
    }

    /** Title-derived public slug, kept unique without ever changing later. */
    private function uniquePublicSlug(string $title): string
    {
        $base = substr(slugify($title), 0, 170);
        $candidate = $base;
        $suffix = 2;
        while ($this->db->one('SELECT id FROM event_series WHERE public_slug = ?', [$candidate]) !== null) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    /**
     * Read-only dry run of attemptCreate()'s validation (capability, anchor,
     * date rules, room conflicts) with no DB writes — used by the AI
     * Assistant's propose_recurring_series MCP tool to compute a proposal
     * diff before any human has approved it. A proposal that passes this is
     * guaranteed to pass attemptCreate()'s validation again at apply time
     * (same method, same rules) — it can still be *rejected* later if the
     * underlying data changed in between (a room got double-booked, the
     * anchor joined another series, access was revoked), which is exactly
     * why apply re-validates rather than trusting the stored diff blindly.
     *
     * @return array{ok: bool, status?: int, error?: string, horizon_date?: string,
     *     beyond_horizon_dates?: list<string>, conflict_dates?: list<string>,
     *     anchor?: array, dates?: list<string>}
     */
    public function previewSeries(int $eventId, array $dates, int $actingUserId, string $actingRole): array
    {
        return $this->validateSeries($eventId, $dates, $actingUserId, $actingRole);
    }

    private function validateSeries(int $eventId, array $dates, int $actingUserId, string $actingRole): array
    {
        // Same not-found/forbidden split as BaseEndpoint::requireEventCapability()
        // (null access = event doesn't exist; non-null but capability-false =
        // exists but not editable by this actor) — preserved here so this
        // shared method behaves identically to the pre-refactor HTTP-only check.
        $access = Capabilities::eventAccess($this->db, $eventId, $actingUserId, $actingRole);
        if (!$access) {
            return ['ok' => false, 'status' => 404, 'error' => 'Event not found'];
        }
        if (!($access['capabilities']['edit_event'] ?? false)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Forbidden'];
        }

        $anchor = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$anchor) {
            return ['ok' => false, 'status' => 404, 'error' => 'Event not found'];
        }
        if (!empty($anchor['series_id'])) {
            return ['ok' => false, 'status' => 422, 'error' => 'This event is already part of a series.'];
        }

        $dates = array_values(array_unique(array_filter(array_map('strval', $dates))));
        if (!$dates) {
            return ['ok' => false, 'status' => 422, 'error' => 'At least one occurrence date is required.'];
        }
        if (count($dates) > self::MAX_OCCURRENCES) {
            return ['ok' => false, 'status' => 422, 'error' => 'Too many occurrences — max ' . self::MAX_OCCURRENCES . ' per series.'];
        }
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return ['ok' => false, 'status' => 422, 'error' => "Invalid date: {$date}"];
            }
            if ($date === $anchor['date']) {
                return ['ok' => false, 'status' => 422, 'error' => "Occurrence dates must not include the event's own date ({$date})."];
            }
        }

        // Rolling 90-day booking-horizon cap, alongside MAX_OCCURRENCES —
        // whichever a given pattern hits first is what actually bounds it.
        // Checked before the (more expensive) room-conflict pass below so a
        // horizon violation is reported without doing conflict-check work for
        // dates that would be rejected anyway.
        $horizonCutoff = self::horizonCutoff();
        $beyondHorizon = self::datesBeyondHorizon($dates, $horizonCutoff);
        if ($beyondHorizon) {
            return [
                'ok' => false, 'status' => 422,
                'error' => 'Too far out — occurrences must land within ' . self::MAX_HORIZON_DAYS
                    . ' days of today (by ' . $horizonCutoff . '). Beyond that: ' . implode(', ', $beyondHorizon),
                'horizon_date' => $horizonCutoff,
                'beyond_horizon_dates' => $beyondHorizon,
            ];
        }

        // Validate every occurrence up front so we never create a partial
        // series — same room-conflict rule Events::create()/update() apply.
        $conflicts = [];
        foreach ($dates as $date) {
            $conflict = $this->checkRoomConflict(
                (int) $anchor['venue_id'],
                $date,
                $anchor['load_in_time'] ?: $anchor['doors_time'] ?: $anchor['show_time'],
                $anchor['load_out_time'] ?: $anchor['end_time'],
                null,
                null,
                $anchor['resource_id'] !== null ? (int) $anchor['resource_id'] : null
            );
            if ($conflict) {
                $conflicts[] = $date;
            }
        }
        if ($conflicts) {
            return [
                'ok' => false, 'status' => 409,
                'error' => 'Room conflict on: ' . implode(', ', $conflicts) . '. Nothing was created — adjust the pattern and try again.',
                'conflict_dates' => $conflicts,
            ];
        }

        return ['ok' => true, 'anchor' => $anchor, 'dates' => $dates];
    }

    private function resultToResponse(array $result): Response
    {
        if (!$result['ok']) {
            $payload = ['error' => $result['error']];
            if (isset($result['horizon_date'])) {
                $payload['horizon_date'] = $result['horizon_date'];
                $payload['beyond_horizon_dates'] = $result['beyond_horizon_dates'];
            }
            if (isset($result['conflict_dates'])) {
                $payload['conflict_dates'] = $result['conflict_dates'];
            }
            return Response::json($payload, $result['status']);
        }
        return $this->ok(['series_id' => $result['series_id'], 'created_event_ids' => $result['created_event_ids']]);
    }

    /**
     * Insert one sibling occurrence, cloning a fixed allowlist of template
     * fields from the anchor event. Status always resets to 'proposed' and
     * occurrence-specific fields (deposit, contract/settlement docs,
     * walkthrough, estimated guests, internal notes) start blank — mirrors
     * how Events::fromTemplate() seeds a new event, not a full row copy.
     */
    private function cloneOccurrence(array $anchor, string $date, int $seriesId, int $actingUserId): int
    {
        $slug = $this->uniqueSlug($anchor['title'] . '-' . $date);
        $id = $this->db->insert(
            'INSERT INTO events
                (venue_id, resource_id, title, slug, event_type, status, series_id, date,
                 doors_time, show_time, end_time, load_in_time, load_out_time, is_non_music, age_restriction,
                 ticket_price, capacity, public_visibility,
                 promoter_name, promoter_email, promoter_phone, client_org,
                 booker_name, booker_email, booker_phone,
                 av_requirements, catering_notes, description_public, ticket_system, ticketing_mode,
                 owner_user_id)
             VALUES (?, ?, ?, ?, ?, \'proposed\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $anchor['venue_id'], $anchor['resource_id'] ?: null, $anchor['title'], $slug, $anchor['event_type'],
                $seriesId, $date,
                $anchor['doors_time'], $anchor['show_time'], $anchor['end_time'], $anchor['load_in_time'], $anchor['load_out_time'], (int) $anchor['is_non_music'], $anchor['age_restriction'],
                (float) ($anchor['ticket_price'] ?? 0), $anchor['capacity'] ?: null, (int) $anchor['public_visibility'],
                $anchor['promoter_name'], $anchor['promoter_email'], $anchor['promoter_phone'], $anchor['client_org'],
                $anchor['booker_name'], $anchor['booker_email'], $anchor['booker_phone'],
                $anchor['av_requirements'], $anchor['catering_notes'], $anchor['description_public'], $anchor['ticket_system'], $anchor['ticketing_mode'],
                $anchor['owner_user_id'] ?: null,
            ]
        );
        if (($anchor['ticketing_mode'] ?? 'external') === 'internal') {
            // A recurring free/paid registration should be ready on every
            // occurrence. Clone the tier definitions, reset sales to zero,
            // and shift any explicit sale window by the same number of days
            // as the occurrence itself.
            $this->db->run(
                "INSERT INTO ticket_types
                    (event_id, name, description, price_cents, currency, quantity_total,
                     quantity_sold, sales_start, sales_end, status, sort_order)
                 SELECT ?, name, description, price_cents, currency, quantity_total, 0,
                        DATE_ADD(sales_start, INTERVAL DATEDIFF(?, ?) DAY),
                        DATE_ADD(sales_end, INTERVAL DATEDIFF(?, ?) DAY),
                        IF(status = 'sold_out', 'on_sale', status), sort_order
                   FROM ticket_types WHERE event_id = ?",
                [$id, $date, $anchor['date'], $date, $anchor['date'], (int) $anchor['id']]
            );
        }
        $this->assignEventCode($id);
        log_activity($this->db, $id, $actingUserId, 'event created', ['title' => $anchor['title'], 'series_id' => $seriesId]);
        $this->pushToSheet($id);
        $this->pushToCalendar($id);
        return $id;
    }

    /**
     * Unlink just this one event from its series (siblings are untouched).
     * If it was the last event referencing that series, the now-orphaned
     * `event_series` row is deleted too.
     */
    private function remove(int $eventId): Response
    {
        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }
        $event = $this->db->one('SELECT series_id FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }
        $seriesId = $event['series_id'];
        if (!$seriesId) {
            return Response::json(['error' => 'This event is not part of a series.'], 422);
        }
        $this->db->run('UPDATE events SET series_id = NULL WHERE id = ?', [$eventId]);
        $remaining = $this->db->one('SELECT COUNT(*) AS n FROM events WHERE series_id = ?', [$seriesId]);
        if ((int) ($remaining['n'] ?? 0) === 0) {
            $this->db->run('DELETE FROM event_series WHERE id = ?', [$seriesId]);
        }
        log_activity($this->db, $eventId, $this->userId(), 'removed from recurring series', ['series_id' => $seriesId]);
        return $this->ok(['ok' => true]);
    }

    /**
     * The last date (inclusive) an occurrence may land on: today +
     * MAX_HORIZON_DAYS. Pure/static so it (and datesBeyondHorizon()) can be
     * unit-tested without a Database/Auth instance — see
     * tests/events_series_horizon_test.php.
     */
    public static function horizonCutoff(): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify('+' . self::MAX_HORIZON_DAYS . ' days')
            ->format('Y-m-d');
    }

    /** Which of $dates (each 'YYYY-MM-DD') fall after $horizonCutoff, in input order. */
    public static function datesBeyondHorizon(array $dates, string $horizonCutoff): array
    {
        return array_values(array_filter($dates, static fn(string $date): bool => $date > $horizonCutoff));
    }
}
