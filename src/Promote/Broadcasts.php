<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Database;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Broadcast CRUD.
 *
 *   GET  /api/promote/events/{id}/broadcasts
 *   POST /api/promote/events/{id}/broadcasts
 *   GET  /api/promote/events/{id}/broadcasts/{broadcastId}
 */
final class Broadcasts extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId     = (int) ($this->params['eventId'] ?? 0);
        $broadcastId = (int) ($this->params['broadcastId'] ?? 0);

        if (!$eventId) {
            return $this->notFound('Event not found');
        }
        $capability = $request->method() === 'GET' ? 'read_event' : 'edit_event';
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'  => $broadcastId ? $this->show($eventId, $broadcastId) : $this->index($eventId),
            'POST' => $this->create($request, $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    // ── Broadcast list ────────────────────────────────────────────────────────

    private function index(int $eventId): Response
    {
        $broadcasts = $this->db->all(
            'SELECT b.*, u.name created_by_name
             FROM promote_broadcasts b LEFT JOIN users u ON u.id = b.created_by_user_id
             WHERE b.event_id = ? ORDER BY b.created_at DESC',
            [$eventId]
        );
        foreach ($broadcasts as &$broadcast) {
            $broadcast['results'] = $this->db->all(
                'SELECT * FROM promote_broadcast_results WHERE broadcast_id = ? ORDER BY id',
                [(int) $broadcast['id']]
            );
        }
        unset($broadcast);
        return $this->ok(['broadcasts' => $broadcasts]);
    }

    // ── Broadcast detail ──────────────────────────────────────────────────────

    private function show(int $eventId, int $broadcastId): Response
    {
        $broadcast = $this->db->one(
            'SELECT * FROM promote_broadcasts WHERE id = ? AND event_id = ?',
            [$broadcastId, $eventId]
        );
        if (!$broadcast) {
            return $this->notFound('Broadcast not found');
        }
        $broadcast['results'] = $this->db->all(
            'SELECT * FROM promote_broadcast_results WHERE broadcast_id = ? ORDER BY id',
            [$broadcastId]
        );
        return $this->ok(['broadcast' => $broadcast]);
    }

    // ── Create broadcast ──────────────────────────────────────────────────────

    private function create(Request $request, int $eventId): Response
    {
        $body   = $request->body();
        $postId = (int) ($body['post_id'] ?? 0);
        if (!$postId) {
            return Response::json(['error' => 'post_id is required'], 422);
        }

        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
        );
        if (!$post) {
            return Response::json(['error' => 'Post not found for this event'], 422);
        }

        $destinations = $body['destinations'] ?? [];
        if (!is_array($destinations) || empty($destinations)) {
            return Response::json(['error' => 'destinations array is required'], 422);
        }

        $sendMode    = ($body['send_mode'] ?? 'now') === 'scheduled' ? 'scheduled' : 'now';
        $scheduledAt = ($sendMode === 'scheduled' && !empty($body['scheduled_at'])) ? $body['scheduled_at'] : null;

        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        ) ?? [];

        $dispatch = self::dispatch($this->db, $eventId, $event, $postId, $post, $destinations, $sendMode, $scheduledAt, $this->userId());

        log_activity($this->db, $eventId, $this->userId(), 'promote broadcast created', [
            'broadcast_id' => $dispatch['broadcast_id'],
            'post_id'      => $postId,
            'destinations' => $destinations,
        ]);

        $broadcast = $this->db->one('SELECT * FROM promote_broadcasts WHERE id = ?', [$dispatch['broadcast_id']]);
        $broadcast['results'] = $dispatch['results'];
        return $this->ok(['broadcast' => $broadcast]);
    }

    // ── Shared dispatch core ────────────────────────────────────────────────────

    /**
     * Insert a promote_broadcasts row, dispatch it to each destination via
     * BroadcastAdapters, record per-destination results, and settle the
     * broadcast's overall status. Shared by the HTTP create() endpoint above
     * and Events::maybeAutoPublish() so the two paths can't drift out of sync
     * — auto-publish used to carry its own near-identical copy of this logic.
     *
     * @param array<string,mixed> $event Event row (with venue joined), as adapters expect.
     * @param array<string,mixed> $post  Row from promote_posts.
     * @param list<string> $destinations Destination keys to dispatch to.
     * @return array{broadcast_id:int, status:string, results:list<array<string,mixed>>}
     */
    public static function dispatch(
        Database $db,
        int $eventId,
        array $event,
        int $postId,
        array $post,
        array $destinations,
        string $sendMode,
        ?string $scheduledAt,
        ?int $createdByUserId
    ): array {
        $placeholders = implode(',', array_fill(0, count($destinations), '?'));
        $destRecords  = $db->all(
            "SELECT * FROM promote_destinations WHERE destination_key IN ($placeholders)",
            array_values($destinations)
        );
        $destMap = [];
        foreach ($destRecords as $d) {
            $destMap[(string) $d['destination_key']] = $d;
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $broadcastId = $db->insert(
                'INSERT INTO promote_broadcasts (event_id, post_id, created_by_user_id, send_mode, scheduled_at, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$eventId, $postId, $createdByUserId, $sendMode, $scheduledAt, 'queued']
            );

            $adapter = new BroadcastAdapters($db);
            $results = [];
            foreach ($destinations as $destKey) {
                $dest       = $destMap[$destKey] ?? null;
                $destGroup  = $dest ? (string) $dest['destination_group'] : 'unknown';
                $destStatus = $dest ? (string) $dest['status'] : 'manual_submission';

                $dispatched = $adapter->dispatch($destKey, $destStatus, $sendMode, $event, $post);

                $resultId = $db->insert(
                    'INSERT INTO promote_broadcast_results
                        (broadcast_id, destination_key, destination_group, status, external_url, error_message, response_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $broadcastId,
                        $destKey,
                        $destGroup,
                        $dispatched['status'],
                        $dispatched['external_url'],
                        $dispatched['error_message'],
                        $dispatched['response_json'],
                    ]
                );
                $results[] = [
                    'id'                => $resultId,
                    'broadcast_id'      => $broadcastId,
                    'destination_key'   => $destKey,
                    'destination_group' => $destGroup,
                    'status'            => $dispatched['status'],
                    'external_url'      => $dispatched['external_url'],
                    'error_message'     => $dispatched['error_message'],
                ];
            }

            $statuses  = array_column($results, 'status');
            $anyFailed = in_array('failed', $statuses, true);
            $allFailed = count($statuses) > 0
                && count(array_filter($statuses, fn ($s) => $s === 'failed')) === count($statuses);

            $broadcastStatus = match (true) {
                $allFailed  => 'failed',
                $anyFailed  => 'partial_failure',
                default     => 'completed',
            };

            $db->run(
                'UPDATE promote_broadcasts SET status = ? WHERE id = ?',
                [$broadcastStatus, $broadcastId]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['broadcast_id' => $broadcastId, 'status' => $broadcastStatus, 'results' => $results];
    }
}
