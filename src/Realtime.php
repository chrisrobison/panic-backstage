<?php
declare(strict_types=1);

namespace Panic;

/**
 * Authenticated realtime invalidation stream — the server half of the
 * Phase 2 architecture in docs/realtime-data.md:
 *
 *   GET /api/realtime/stream
 *
 * Polls `db_history` (see scripts/generate-audit-triggers.php) for rows
 * newer than the client's resume point, translates each into a safe
 * {entity, id, revision} invalidation via RealtimeInvalidationMapper, and
 * pushes it down as a Server-Sent Event. It is deliberately NOT a general
 * data API: the browser never receives row contents (old_row/new_row never
 * leave this file), only "this entity may be stale, go re-fetch it through
 * the normal JSON API" — see the architecture doc for why HTTP stays
 * authoritative and this exists only to say "something changed."
 *
 * Authentication: standard Kernel bearer/cookie auth (this class is not in
 * Kernel::isPublic()). Browsers cannot attach custom headers to an
 * EventSource request, so in practice this is reached via the HttpOnly
 * `backstage_access` session cookie (see SessionCookies::issue(), set on
 * every login) rather than a Bearer header — same-origin, so the cookie is
 * sent automatically. A request with no valid session gets Kernel's normal
 * 401 JSON response before this class is ever instantiated.
 *
 * Multi-tenant: $this->db is already the tenant-scoped Database Kernel
 * resolved before constructing this endpoint (see public/api/index.php),
 * exactly like every other endpoint — db_history is therefore already
 * scoped to the requesting tenant's own database, and one tenant's changes
 * can never reach another tenant's stream.
 *
 * Permissions: entity-specific invalidations are re-checked against the
 * requesting user's capabilities on every row (view_booking_inbox for
 * 'lead', per-event read_event for 'event') so a user who can't see a lead
 * or event doesn't learn even the bare fact that it changed. 'global'
 * invalidations carry no identifying information at all — no table name,
 * no id — so they're safe to send to any authenticated user.
 */
final class Realtime extends BaseEndpoint
{
    private const DEFAULT_TTL_SECONDS = 55;
    private const DEFAULT_POLL_INTERVAL_MS = 1000;
    private const DEFAULT_HEARTBEAT_SECONDS = 15;
    private const BATCH_LIMIT = 200;

    public function handle(Request $request): Response
    {
        if (($this->params['action'] ?? null) !== 'stream') {
            return $this->notFound('Unknown realtime endpoint');
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        if (!self::enabled()) {
            // Feature-flag off: behave like the endpoint doesn't exist.
            // EventSource does not auto-retry a "fail the connection"
            // response like this 404 (per the SSE spec), but the worker
            // (data-worker.js) applies its own capped exponential backoff
            // (up to 30s) and keeps trying while realtime.start is in
            // effect — so re-enabling this flag is picked up by an already-
            // open tab without a page reload. See docs/realtime-data.md's
            // rollout/fallback section.
            return $this->notFound('Realtime is disabled');
        }

        $since = $this->resolveSince($request);
        $userId = $this->userId();
        $role = $this->role();

        // Must happen before any output is sent — see docs/realtime-data.md's
        // proxy/PHP-FPM configuration notes on why gzip and this stream
        // don't mix.
        ini_set('zlib.output_compression', '0');
        set_time_limit(self::ttlSeconds() + 15);

        return Response::stream(function () use ($since, $userId, $role): void {
            $this->streamLoop($since, $userId, $role);
        });
    }

    // ─── Config (env-injectable so tests can use short TTL/poll values) ──────

    private static function enabled(): bool
    {
        $flag = strtolower(trim((string) (getenv('REALTIME_ENABLED') ?: 'true')));
        return $flag !== 'false' && $flag !== '0' && $flag !== '';
    }

    private static function ttlSeconds(): int
    {
        $configured = (int) (getenv('REALTIME_STREAM_TTL_SECONDS') ?: self::DEFAULT_TTL_SECONDS);
        return max(5, min(300, $configured));
    }

    private static function pollIntervalMicroseconds(): int
    {
        $configured = (int) (getenv('REALTIME_POLL_INTERVAL_MS') ?: self::DEFAULT_POLL_INTERVAL_MS);
        return max(100, min(5000, $configured)) * 1000;
    }

    private static function heartbeatSeconds(): int
    {
        $configured = (int) (getenv('REALTIME_HEARTBEAT_SECONDS') ?: self::DEFAULT_HEARTBEAT_SECONDS);
        return max(5, min(60, $configured));
    }

    // ─── Resume point ──────────────────────────────────────────────────────

    /**
     * Resume point, in db_history.id terms. `Last-Event-ID` (sent
     * automatically by EventSource on reconnect) takes priority over an
     * explicit `?since=` query param (mainly for tests/tooling), which
     * takes priority over "start from now" — a brand-new connection
     * doesn't replay the entire history table, only changes from here on.
     */
    private function resolveSince(Request $request): int
    {
        $lastEventId = $request->header('Last-Event-ID');
        if ($lastEventId !== null && ctype_digit(trim($lastEventId))) {
            return (int) trim($lastEventId);
        }
        $sinceParam = $request->query('since');
        if ($sinceParam !== null && ctype_digit((string) $sinceParam)) {
            return (int) $sinceParam;
        }
        $row = $this->db->one('SELECT MAX(id) AS max_id FROM db_history');
        return (int) ($row['max_id'] ?? 0);
    }

    // ─── Stream loop ───────────────────────────────────────────────────────

    /**
     * Polls db_history in a bounded loop, writing one SSE frame per visible
     * invalidation. Returns (ending the request, letting EventSource
     * reconnect with Last-Event-ID) on client disconnect or once the TTL
     * elapses — never loops forever, so this can't outlive PHP-FPM/Apache/
     * nginx request-duration limits as long as those are configured to
     * allow at least ttlSeconds() (see docs/realtime-data.md).
     */
    private function streamLoop(int $since, ?int $userId, string $role): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $this->writeRaw("retry: 2000\n\n");

        $deadline = microtime(true) + self::ttlSeconds();
        $pollIntervalUs = self::pollIntervalMicroseconds();
        $heartbeatSeconds = self::heartbeatSeconds();
        $lastWrite = microtime(true);

        // Per-connection memo of per-event read access so a burst of
        // child-table rows from one editor's transaction (event + tasks +
        // blockers all touched together) costs one permission query per
        // event id, not one per row.
        $eventAccessCache = [];

        while (true) {
            if (connection_aborted()) {
                return;
            }
            if (microtime(true) >= $deadline) {
                return;
            }

            try {
                $rows = $this->db->all(
                    'SELECT id, table_name, old_row, new_row FROM db_history WHERE id > ? ORDER BY id ASC LIMIT ?',
                    [$since, self::BATCH_LIMIT]
                );
            } catch (\Throwable $e) {
                error_log('[realtime] db_history poll failed: ' . $e->getMessage());
                return;
            }

            // Collapse a batch down to one frame per (entity,id) — a single
            // transaction that touches several child tables of the same
            // event/lead would otherwise emit several identical
            // invalidations back to back for no client benefit.
            $toEmit = [];
            foreach ($rows as $row) {
                $since = (int) $row['id'];
                $invalidation = RealtimeInvalidationMapper::map($row);
                if ($invalidation === null || !$this->visibleTo($invalidation, $userId, $role, $eventAccessCache)) {
                    continue;
                }
                $key = $invalidation['entity'] . ':' . ($invalidation['id'] ?? '');
                $toEmit[$key] = ['revision' => (int) $row['id'], 'invalidation' => $invalidation];
            }

            if ($toEmit) {
                // Emit in revision order (dedup above can reorder entries —
                // a later row for a key already seen earlier in the batch
                // moves that key's position via array key reuse) so the
                // last SSE `id:` written is always this batch's highest
                // revision. Last-Event-ID on reconnect must land on a real
                // "everything up to here is accounted for" point; emitting
                // out of order would let it land short of $since, causing a
                // harmless but pointless re-delivery of an already-sent
                // invalidation on the client's next reconnect.
                usort($toEmit, static fn (array $a, array $b): int => $a['revision'] <=> $b['revision']);
                foreach ($toEmit as $entry) {
                    $this->writeRaw(self::buildFrame($entry['revision'], $entry['invalidation']));
                }
                $lastWrite = microtime(true);
            } elseif (microtime(true) - $lastWrite >= $heartbeatSeconds) {
                // Comment line — invisible to EventSource's message/event
                // handling, purely a keep-alive so intermediary proxies
                // don't treat an idle connection as dead.
                $this->writeRaw(": heartbeat\n\n");
                $lastWrite = microtime(true);
            }

            usleep($pollIntervalUs);
        }
    }

    /**
     * @param array{entity:string,id?:int} $invalidation
     * @param array<int,bool> $eventAccessCache
     */
    private function visibleTo(array $invalidation, ?int $userId, string $role, array &$eventAccessCache): bool
    {
        if ($invalidation['entity'] === 'event') {
            $eventId = (int) ($invalidation['id'] ?? 0);
            if ($eventId <= 0) {
                return false;
            }
            if (!array_key_exists($eventId, $eventAccessCache)) {
                $eventAccessCache[$eventId] = Capabilities::hasEvent($this->db, $eventId, $userId, $role, 'read_event');
            }
            return $eventAccessCache[$eventId];
        }
        if ($invalidation['entity'] === 'lead') {
            return Capabilities::hasGlobal($role, 'view_booking_inbox');
        }
        // 'global' carries no identifying information — safe for any
        // authenticated user.
        return true;
    }

    /**
     * Builds one SSE `id:`/`event:`/`data:` frame. Pure/static and public
     * so it can be unit-tested (see tests/realtime_stream_frame_test.php)
     * without booting a Database or running the streaming loop.
     *
     * @param array{entity:string,id?:int} $invalidation
     */
    public static function buildFrame(int $revision, array $invalidation): string
    {
        $data = ['entity' => $invalidation['entity']];
        if (isset($invalidation['id'])) {
            $data['id'] = $invalidation['id'];
        }
        $data['revision'] = $revision;
        return "id: {$revision}\nevent: invalidate\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    private function writeRaw(string $chunk): void
    {
        echo $chunk;
        @flush();
    }
}
