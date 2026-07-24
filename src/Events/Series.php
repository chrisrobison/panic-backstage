<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

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
        $events = $this->db->all(
            'SELECT id, title, date, status, slug, external_id FROM events WHERE series_id = ? ORDER BY date',
            [$event['series_id']]
        );
        return $this->ok(['series' => $series, 'events' => $events]);
    }

    private function create(Request $request, int $eventId): Response
    {
        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }
        $anchor = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$anchor) {
            return $this->notFound('Event not found');
        }
        if (!empty($anchor['series_id'])) {
            return Response::json(['error' => 'This event is already part of a series.'], 422);
        }

        $body        = $request->body();
        $dates       = array_values(array_unique(array_filter(array_map('strval', (array) ($body['dates'] ?? [])))));
        $description = trim((string) ($body['description'] ?? '')) ?: null;
        $pattern     = $body['pattern'] ?? null;
        $endType     = ($body['end_type'] ?? '') === 'on_date' ? 'on_date' : 'after_count';

        if (!$dates) {
            return Response::json(['error' => 'At least one occurrence date is required.'], 422);
        }
        if (count($dates) > self::MAX_OCCURRENCES) {
            return Response::json(['error' => 'Too many occurrences — max ' . self::MAX_OCCURRENCES . ' per series.'], 422);
        }
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return Response::json(['error' => "Invalid date: {$date}"], 422);
            }
            if ($date === $anchor['date']) {
                return Response::json(['error' => "Occurrence dates must not include the event's own date ({$date})."], 422);
            }
        }

        // Validate every occurrence up front so we never create a partial
        // series — same room-conflict rule Events::create()/update() apply.
        $conflicts = [];
        foreach ($dates as $date) {
            $conflict = $this->checkRoomConflict(
                (int) $anchor['venue_id'],
                $date,
                $anchor['doors_time'] ?: $anchor['show_time'],
                $anchor['end_time'],
                null,
                null,
                $anchor['resource_id'] !== null ? (int) $anchor['resource_id'] : null
            );
            if ($conflict) {
                $conflicts[] = $date;
            }
        }
        if ($conflicts) {
            return Response::json([
                'error' => 'Room conflict on: ' . implode(', ', $conflicts) . '. Nothing was created — adjust the pattern and try again.',
                'conflict_dates' => $conflicts,
            ], 409);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $seriesId = $this->db->insert(
                'INSERT INTO event_series (venue_id, title, pattern_json, description, end_type, end_date, occurrence_count, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $anchor['venue_id'],
                    $anchor['title'],
                    $pattern !== null ? json_encode($pattern) : null,
                    $description,
                    $endType,
                    $endType === 'on_date' ? ($body['end_date'] ?: null) : null,
                    $endType === 'after_count' ? (int) ($body['occurrence_count'] ?? (count($dates) + 1)) : null,
                    $this->userId(),
                ]
            );

            $this->db->run('UPDATE events SET series_id = ? WHERE id = ?', [$seriesId, $eventId]);

            $createdIds = [];
            foreach ($dates as $date) {
                $createdIds[] = $this->cloneOccurrence($anchor, $date, $seriesId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            @error_log('series create failed for event ' . $eventId . ': ' . $e->getMessage());
            return Response::json(['error' => 'Could not create the series. Nothing was changed.'], 500);
        }

        log_activity($this->db, $eventId, $this->userId(), 'recurring series created', [
            'series_id' => $seriesId,
            'occurrences' => count($createdIds),
        ]);

        return $this->ok(['series_id' => $seriesId, 'created_event_ids' => $createdIds]);
    }

    /**
     * Insert one sibling occurrence, cloning a fixed allowlist of template
     * fields from the anchor event. Status always resets to 'proposed' and
     * occurrence-specific fields (deposit, contract/settlement docs,
     * walkthrough, estimated guests, internal notes) start blank — mirrors
     * how Events::fromTemplate() seeds a new event, not a full row copy.
     */
    private function cloneOccurrence(array $anchor, string $date, int $seriesId): int
    {
        $slug = $this->uniqueSlug($anchor['title'] . '-' . $date);
        $id = $this->db->insert(
            'INSERT INTO events
                (venue_id, resource_id, title, slug, event_type, status, series_id, date,
                 doors_time, show_time, end_time, load_in_time, is_non_music, age_restriction,
                 ticket_price, capacity, public_visibility,
                 promoter_name, promoter_email, promoter_phone, client_org,
                 booker_name, booker_email, booker_phone,
                 av_requirements, catering_notes, description_public, ticket_system,
                 owner_user_id)
             VALUES (?, ?, ?, ?, ?, \'proposed\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $anchor['venue_id'], $anchor['resource_id'] ?: null, $anchor['title'], $slug, $anchor['event_type'],
                $seriesId, $date,
                $anchor['doors_time'], $anchor['show_time'], $anchor['end_time'], $anchor['load_in_time'], (int) $anchor['is_non_music'], $anchor['age_restriction'],
                (float) ($anchor['ticket_price'] ?? 0), $anchor['capacity'] ?: null, (int) $anchor['public_visibility'],
                $anchor['promoter_name'], $anchor['promoter_email'], $anchor['promoter_phone'], $anchor['client_org'],
                $anchor['booker_name'], $anchor['booker_email'], $anchor['booker_phone'],
                $anchor['av_requirements'], $anchor['catering_notes'], $anchor['description_public'], $anchor['ticket_system'],
                $anchor['owner_user_id'] ?: null,
            ]
        );
        $this->assignEventCode($id);
        log_activity($this->db, $id, $this->userId(), 'event created', ['title' => $anchor['title'], 'series_id' => $seriesId]);
        $this->pushToSheet($id);
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
}
