<?php
declare(strict_types=1);

namespace Panic\Events;

/**
 * Shared helpers for anything that inserts/validates `events` rows outside of
 * the main `Events::create()` flow — currently `Events` itself and
 * `Events\Series` (recurring-event siblings). Extracted verbatim from
 * `Events.php` so both call sites share one code-assignment and
 * room-conflict implementation instead of drifting apart.
 */
trait EventRowHelpers
{
    /**
     * Assign the next sequential human-facing code (EVT-N). Retried so the
     * unique-index race between concurrent creates can't collide silently.
     * Used by the blank-create, create-from-template, and series-create paths.
     */
    private function assignEventCode(int $id): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row  = $this->db->one("SELECT COALESCE(MAX(CAST(SUBSTRING(external_id, 5) AS UNSIGNED)), 0) AS m FROM events WHERE external_id LIKE 'EVT-%'");
            $code = 'EVT-' . (((int) ($row['m'] ?? 0)) + 1);
            try {
                $this->db->run('UPDATE events SET external_id = ? WHERE id = ?', [$code, $id]);
                return;
            } catch (\Throwable $e) {
                if ($attempt === 4) {
                    @error_log('event code assignment failed for event ' . $id . ': ' . $e->getMessage());
                }
            }
        }
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $root = \Panic\slugify($base);
        $slug = $root;
        $i = 2;
        while ($this->db->one('SELECT id FROM events WHERE slug = ? AND (? IS NULL OR id != ?) LIMIT 1', [$slug, $ignoreId, $ignoreId])) {
            $slug = "$root-" . $i++;
        }
        return $slug;
    }

    /**
     * Check whether the given venue/room + date + occupancy window conflicts with
     * any existing booking (with a 30-minute buffer between events). Returns
     * a 409 Response describing the conflict, or null if the slot is clear.
     *
     * $resourceId is the room (see `resources` table) the event is booked
     * into, if any. When set, conflicts are scoped to that room plus any
     * `zone='both'` (whole-building) room in the same venue — a 'both'
     * booking blocks every other room, and a single-room booking is blocked
     * by any 'both' booking. When $resourceId is null (a venue with no rooms
     * defined, or a legacy event that predates this), conflicts fall back to
     * the whole venue, matching the original single-space behavior.
     *
     * $blockingStatuses narrows *which existing events count as occupying the
     * room*. Null (the default) means every live booking blocks — the
     * original behavior, still what a confirmed-or-later event gets. Callers
     * placing a Hold pass the confirmed-and-beyond list instead, so a Hold is
     * blocked by a real booking but two speculative Holds may still compete
     * for the same date (see Events::conflictBlockersFor()).
     */
    private function checkRoomConflict(int $venueId, string $date, ?string $occupancyStart, ?string $occupancyEnd, ?int $excludeId = null, ?string $endDate = null, ?int $resourceId = null, ?array $blockingStatuses = null): ?\Panic\Response
    {
        $conflict = $this->findRoomConflict($venueId, $date, $occupancyStart, $occupancyEnd, $excludeId, $endDate, $resourceId, $blockingStatuses);
        if (!$conflict) {
            return null;
        }
        $message = $conflict['multi_day']
            ? "Room conflict: \"{$conflict['title']}\" is already booked at this venue on {$conflict['conflict_date']}."
            : "Room conflict: \"{$conflict['title']}\" occupies this room during the requested load-in to load-out window on {$date}. Events must be at least 30 minutes apart.";
        return \Panic\Response::json([
            'error' => $message,
            'conflict_event_id' => $conflict['event_id'],
        ], 409);
    }

    /**
     * Same lookup as checkRoomConflict(), but returns the raw conflicting-row
     * data (event id/title/status/date) instead of a wrapped 409 Response, so
     * callers building a resolution UI (see Events\Series's conflict preflight)
     * can render their own message instead of parsing one back out of a
     * Response. checkRoomConflict() is now a thin wrapper around this for the
     * existing "just tell me yes/no, with a ready-made error" call sites.
     *
     * @return array{event_id: int, title: string, status: string, conflict_date: string, multi_day: bool}|null
     */
    private function findRoomConflict(int $venueId, string $date, ?string $occupancyStart, ?string $occupancyEnd, ?int $excludeId = null, ?string $endDate = null, ?int $resourceId = null, ?array $blockingStatuses = null): ?array
    {
        if ($resourceId !== null) {
            $resource = $this->db->one('SELECT zone FROM resources WHERE id = ? AND venue_id = ? LIMIT 1', [$resourceId, $venueId]);
            $zone     = $resource['zone'] ?? null;
            $ids      = [$resourceId];
            if ($zone === 'both') {
                $others = $this->db->all(
                    "SELECT id FROM resources WHERE venue_id = ? AND id != ? AND active = 1 AND zone != 'both'",
                    [$venueId, $resourceId]
                );
                foreach ($others as $r) { $ids[] = (int) $r['id']; }
            } else {
                $both = $this->db->all(
                    "SELECT id FROM resources WHERE venue_id = ? AND id != ? AND active = 1 AND zone = 'both'",
                    [$venueId, $resourceId]
                );
                foreach ($both as $r) { $ids[] = (int) $r['id']; }
            }
            $col  = 'resource_id';
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $args = array_values(array_map('intval', $ids));
        } else {
            // No room specified — fall back to conflict-checking the whole venue.
            $col  = 'venue_id';
            $ph   = '?';
            $args = [$venueId];
        }
        // Find events whose date range overlaps [date, endDate].
        // COALESCE(end_date, date) treats single-day events as a range of one day.
        $rangeEnd = $endDate ?: $date;
        $args[] = $rangeEnd; // existing.date <= rangeEnd
        $args[] = $date;     // COALESCE(existing.end_date, existing.date) >= date
        $excl    = $excludeId ? ' AND id != ?' : '';
        if ($excludeId) $args[] = $excludeId;
        // Status filter goes last so its placeholders never interleave with the
        // room-id / date / exclude args built above.
        if ($blockingStatuses === null) {
            $statusClause = "status NOT IN ('canceled','empty')";
        } elseif ($blockingStatuses === []) {
            return null; // nothing can block this event
        } else {
            $statusClause = 'status IN (' . implode(',', array_fill(0, count($blockingStatuses), '?')) . ')';
            foreach ($blockingStatuses as $status) { $args[] = $status; }
        }
        $rows = $this->db->all(
            "SELECT id, title, status, date, end_date, load_in_time, doors_time, show_time, end_time, load_out_time FROM events WHERE $col IN ($ph) AND date <= ? AND COALESCE(end_date, date) >= ?$excl AND $statusClause",
            $args
        );
        $isMultiDayNew = $endDate && $endDate !== $date;
        foreach ($rows as $row) {
            $isMultiDayExisting = !empty($row['end_date']) && $row['end_date'] !== $row['date'];
            // Multi-day events block the entire date range — no time check needed.
            if ($isMultiDayNew || $isMultiDayExisting) {
                $conflictDate = $isMultiDayExisting
                    ? "{$row['date']}–{$row['end_date']}"
                    : $row['date'];
                return [
                    'event_id' => (int) $row['id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'conflict_date' => $conflictDate,
                    'multi_day' => true,
                ];
            }
            // Both events are single-day: check the 30-minute time buffer.
            // The room is occupied from Load In through Load Out. Legacy or
            // incomplete rows fall back to Doors/Start and End respectively.
            $rowStart = $row['load_in_time'] ?: $row['doors_time'] ?: $row['show_time'];
            $rowEnd = $row['load_out_time'] ?: $row['end_time'];
            if ($this->timesOverlap($occupancyStart, $occupancyEnd, $rowStart, $rowEnd)) {
                return [
                    'event_id' => (int) $row['id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'conflict_date' => $row['date'],
                    'multi_day' => false,
                ];
            }
        }
        return null;
    }

    /**
     * Every active room at $venueId that is NOT conflicted for the given
     * date/occupancy window — used to offer "move to a free room" choices
     * when resolving a room conflict. Looped per-room (rather than one batch
     * query) so each candidate goes through the same zone='both' cross-
     * blocking logic as findRoomConflict()/checkRoomConflict() instead of
     * reimplementing it. A room with no rooms defined at all yields [].
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableRoomsFor(int $venueId, string $date, ?string $occupancyStart, ?string $occupancyEnd, ?string $endDate = null): array
    {
        $rooms = $this->db->all(
            'SELECT id, name FROM resources WHERE venue_id = ? AND active = 1 ORDER BY sort_order, name',
            [$venueId]
        );
        $available = [];
        foreach ($rooms as $room) {
            $conflict = $this->findRoomConflict($venueId, $date, $occupancyStart, $occupancyEnd, null, $endDate, (int) $room['id'], null);
            if ($conflict === null) {
                $available[] = ['id' => (int) $room['id'], 'name' => $room['name']];
            }
        }
        return $available;
    }

    /**
     * Two-way sync: enqueue this event for write-back to the Google Sheet and
     * attempt an immediate push. Best-effort and non-blocking — any failure is
     * recorded as a pending row in sheet_sync_queue for the cron to retry, and
     * never affects the HTTP response (mirrors the Mailer's never-throw rule).
     */
    private function pushToSheet(int $id): void
    {
        // Master kill switch (SHEET_SYNC_ENABLED=0). Checked before the enqueue,
        // not just before the push: a disabled sync must not quietly accumulate
        // pending outbox rows that nothing will ever drain.
        if (!\Panic\GoogleSheets::syncEnabled()) {
            return;
        }

        try {
            // Full identity + app-owned field set so an unlinked event can be
            // appended as a complete Tracker row (not just updated in place).
            $cols = implode(', ', array_keys(\Panic\GoogleSheets::APPEND_COLUMN));
            $ev = $this->db->one("SELECT {$cols} FROM events WHERE id = ? LIMIT 1", [$id]);
            if (!$ev) {
                return;
            }

            // Only NAMED events belong in the sheet. A nameless (untitled) event
            // is treated as an in-progress draft: keep it app-only and don't even
            // enqueue it, so it never appears in the Tracker until it's named.
            if (trim((string) ($ev['title'] ?? '')) === '') {
                return;
            }

            // One pending outbox row per event; repeated edits collapse into it.
            $this->db->run(
                'INSERT INTO sheet_sync_queue (event_id, status, attempts)
                 VALUES (?, \'pending\', 0)
                 ON DUPLICATE KEY UPDATE status = \'pending\', updated_at = NOW()',
                [$id]
            );

            $sheets = new \Panic\GoogleSheets($this->root);
            if (!$sheets->isConfigured()) {
                return; // not set up yet — the cron sweep retries once the key lands
            }

            // Update the linked row, link+update a legacy EVT-N row, or append a
            // brand-new row for an app-created event with no sheet presence.
            $res = $sheets->syncEventRow($id, $ev);
            if ($res['ok']) {
                $this->db->run(
                    'UPDATE sheet_sync_queue
                     SET status = \'done\', attempts = attempts + 1, last_error = NULL, pushed_at = NOW()
                     WHERE event_id = ?',
                    [$id]
                );
            } else {
                $this->db->run(
                    'UPDATE sheet_sync_queue SET attempts = attempts + 1 WHERE event_id = ?',
                    [$id]
                );
            }
        } catch (\Throwable $e) {
            @error_log('sheet push failed for event ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Mirror this event onto the shared Google Calendar right now, so a save is
     * visible to staff in seconds instead of waiting for the nightly sweep.
     *
     * Best-effort and never-throw, exactly like pushToSheet(). Note there is
     * deliberately NO outbox table here: scripts/sync-google-calendar.php is a
     * stateless reconcile sweep, so the retry mechanism is simply *not* setting
     * gcal_synced_at — tonight's run recomputes and fixes anything missed. That
     * is the whole payoff of the sweep design (see docs/google-calendar-sync.md).
     *
     * Only event saves route through here. A hard DELETE /api/events/{id}
     * cascades the row away and is cleaned up by the sweep's orphan reconcile.
     */
    private function pushToCalendar(int $id): void
    {
        if (!\Panic\GoogleCalendar::syncEnabled()) {
            return;
        }

        try {
            $cal = new \Panic\GoogleCalendar($this->root);
            if (!$cal->isConfigured()) {
                return; // not set up — nothing to do, and the sweep is inert too
            }

            $ev = $this->db->one(
                'SELECT e.id, e.title, e.status, e.event_type, e.date, e.end_date,
                        e.doors_time, e.show_time, e.end_time, e.load_in_time, e.load_out_time,
                        e.room, e.capacity, e.promoter_name, e.booker_name,
                        e.gcal_event_id,
                        v.name AS venue_name, v.address AS venue_address, v.timezone AS venue_timezone
                 FROM   events e
                 JOIN   venues v ON v.id = e.venue_id
                 WHERE  e.id = ? LIMIT 1',
                [$id]
            );
            if (!$ev) {
                return;
            }

            $status = (string) ($ev['status'] ?? '');
            $gcalId = $ev['gcal_event_id'] ?: null;

            // Canceled (and other removable statuses) come off the calendar so
            // the night reads as free. Unlink on success — and on 404/410, which
            // means it was already gone.
            if (\Panic\GoogleCalendar::shouldRemove($status)) {
                if ($gcalId === null) {
                    return;
                }
                $code = $cal->deleteEvent($gcalId);
                if ($code === 204 || $code === 404 || $code === 410) {
                    $this->db->run('UPDATE events SET gcal_event_id = NULL, gcal_synced_at = NULL WHERE id = ?', [$id]);
                }
                return;
            }

            // Placeholder shells and leaked test fixtures never reach the
            // calendar — same exclusions the sweep applies.
            if (in_array($status, \Panic\GoogleCalendar::SKIP_STATUSES, true)) {
                return;
            }
            if (str_starts_with(trim((string) ($ev['title'] ?? '')), 'PB UI TEST')) {
                return;
            }

            $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
            $body   = \Panic\GoogleCalendar::eventBody($ev, $appUrl);

            if ($gcalId !== null) {
                $code = $cal->patchEvent($gcalId, $body);
                if ($code >= 200 && $code < 300) {
                    $this->db->run('UPDATE events SET gcal_synced_at = NOW() WHERE id = ?', [$id]);
                    return;
                }
                if ($code !== 404 && $code !== 410) {
                    return; // transient — leave gcal_synced_at stale for the sweep
                }
                $gcalId = null; // hand-deleted in Google; fall through and re-create
            }

            $newId = $cal->createEvent($body);
            if ($newId !== null) {
                $this->db->run(
                    'UPDATE events SET gcal_event_id = ?, gcal_synced_at = NOW() WHERE id = ?',
                    [$newId, $id]
                );
            }
        } catch (\Throwable $e) {
            @error_log('calendar push failed for event ' . $id . ': ' . $e->getMessage());
        }
    }

    /** True if two event time windows overlap, accounting for a 30-minute buffer. */
    private function timesOverlap(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        // No times on either side → treat as full-day → always conflict
        if ((!$startA && !$endA) || (!$startB && !$endB)) return true;
        $mins = static function (?string $t): int {
            if (!$t) return 0;
            [$h, $m] = array_pad(explode(':', (string) $t), 2, '0');
            return (int) $h * 60 + (int) $m;
        };
        $buffer = 30;
        $sA = $mins($startA);
        $eA = $endA ? $mins($endA) : $sA + 300; // fallback 5 h show
        $sB = $mins($startB);
        $eB = $endB ? $mins($endB) : $sB + 300;
        if ($eA <= $sA) $eA += 1440; // past-midnight wrap
        if ($eB <= $sB) $eB += 1440;
        // Conflict if NOT (endA+buffer ≤ startB OR endB+buffer ≤ startA)
        return !($eA + $buffer <= $sB || $eB + $buffer <= $sA);
    }
}
