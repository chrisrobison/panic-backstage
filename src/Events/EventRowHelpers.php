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
     * Check whether the given venue + date + time window conflicts with any
     * existing booking (with a 30-minute buffer between events). Returns a
     * 409 Response describing the conflict, or null if the slot is clear.
     */
    private function checkRoomConflict(int $venueId, string $date, ?string $doorsTime, ?string $endTime, ?int $excludeId = null, ?string $endDate = null): ?\Panic\Response
    {
        $venue = $this->db->one('SELECT zone, venue_group FROM venues WHERE id = ? LIMIT 1', [$venueId]);
        $zone  = $venue['zone']        ?? null;
        $group = $venue['venue_group'] ?? null;
        $ids   = [$venueId];
        // Generic group conflict: a 'both' (whole-building) booking conflicts
        // with every specific room in the same group, and a room booking also
        // conflicts with any whole-building event on the same day.
        // venue_group and zone are set in the DB (see migration 020_resources.sql).
        if ($group !== null) {
            if ($zone === 'both') {
                $others = $this->db->all(
                    "SELECT id FROM venues WHERE venue_group = ? AND zone != 'both'",
                    [$group]
                );
                foreach ($others as $r) { $ids[] = (int) $r['id']; }
            } else {
                $both = $this->db->one(
                    "SELECT id FROM venues WHERE venue_group = ? AND zone = 'both' LIMIT 1",
                    [$group]
                );
                if ($both) { $ids[] = (int) $both['id']; }
            }
        }
        $ph      = implode(',', array_fill(0, count($ids), '?'));
        $args    = array_values(array_map('intval', $ids));
        // Find events whose date range overlaps [date, endDate].
        // COALESCE(end_date, date) treats single-day events as a range of one day.
        $rangeEnd = $endDate ?: $date;
        $args[] = $rangeEnd; // existing.date <= rangeEnd
        $args[] = $date;     // COALESCE(existing.end_date, existing.date) >= date
        $excl    = $excludeId ? ' AND id != ?' : '';
        if ($excludeId) $args[] = $excludeId;
        $rows = $this->db->all(
            "SELECT id, title, date, end_date, doors_time, end_time FROM events WHERE venue_id IN ($ph) AND date <= ? AND COALESCE(end_date, date) >= ? AND status NOT IN ('canceled','empty')$excl",
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
                return \Panic\Response::json([
                    'error' => "Room conflict: \"{$row['title']}\" is already booked at this venue on {$conflictDate}.",
                    'conflict_event_id' => (int) $row['id'],
                ], 409);
            }
            // Both events are single-day: check the 30-minute time buffer.
            if ($this->timesOverlap($doorsTime, $endTime, $row['doors_time'], $row['end_time'])) {
                return \Panic\Response::json([
                    'error' => "Room conflict: \"{$row['title']}\" is already booked at this venue on {$date}. Events must be at least 30 minutes apart.",
                    'conflict_event_id' => (int) $row['id'],
                ], 409);
            }
        }
        return null;
    }

    /**
     * Two-way sync: enqueue this event for write-back to the Google Sheet and
     * attempt an immediate push. Best-effort and non-blocking — any failure is
     * recorded as a pending row in sheet_sync_queue for the cron to retry, and
     * never affects the HTTP response (mirrors the Mailer's never-throw rule).
     */
    private function pushToSheet(int $id): void
    {
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
