<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Broadcast CRUD.
 *
 *   GET  /api/promote/campaigns/{id}/broadcasts
 *   POST /api/promote/campaigns/{id}/broadcasts
 *   GET  /api/promote/campaigns/{id}/broadcasts/{broadcastId}
 */
final class Broadcasts extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $campaignId  = (int) ($this->params['campaignId'] ?? 0);
        $broadcastId = (int) ($this->params['broadcastId'] ?? 0);

        if (!$campaignId) {
            return $this->notFound('Campaign not found');
        }
        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        $eventId    = (int) $campaign['event_id'];
        $capability = $request->method() === 'GET' ? 'read_event' : 'edit_event';
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'  => $broadcastId ? $this->show($campaignId, $broadcastId) : $this->index($campaignId),
            'POST' => $this->create($request, $campaignId, $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    // ── Broadcast list ────────────────────────────────────────────────────────

    private function index(int $campaignId): Response
    {
        $broadcasts = $this->db->all(
            'SELECT b.*, u.name created_by_name
             FROM promote_broadcasts b LEFT JOIN users u ON u.id = b.created_by_user_id
             WHERE b.campaign_id = ? ORDER BY b.created_at DESC',
            [$campaignId]
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

    private function show(int $campaignId, int $broadcastId): Response
    {
        $broadcast = $this->db->one(
            'SELECT * FROM promote_broadcasts WHERE id = ? AND campaign_id = ?',
            [$broadcastId, $campaignId]
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

    private function create(Request $request, int $campaignId, int $eventId): Response
    {
        $body   = $request->body();
        $postId = (int) ($body['post_id'] ?? 0);
        if (!$postId) {
            return Response::json(['error' => 'post_id is required'], 422);
        }

        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND campaign_id = ?',
            [$postId, $campaignId]
        );
        if (!$post) {
            return Response::json(['error' => 'Post not found in this campaign'], 422);
        }

        $destinations = $body['destinations'] ?? [];
        if (!is_array($destinations) || empty($destinations)) {
            return Response::json(['error' => 'destinations array is required'], 422);
        }

        $sendMode    = ($body['send_mode'] ?? 'now') === 'scheduled' ? 'scheduled' : 'now';
        $scheduledAt = ($sendMode === 'scheduled' && !empty($body['scheduled_at'])) ? $body['scheduled_at'] : null;

        // Fetch the full event row (with venue join) so adapters have all the data they need
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        ) ?? [];

        // Fetch destination records for the requested keys
        $placeholders = implode(',', array_fill(0, count($destinations), '?'));
        $destRecords  = $this->db->all(
            "SELECT * FROM promote_destinations WHERE destination_key IN ($placeholders)",
            array_values($destinations)
        );
        $destMap = [];
        foreach ($destRecords as $d) {
            $destMap[(string) $d['destination_key']] = $d;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $broadcastId = $this->db->insert(
                'INSERT INTO promote_broadcasts (campaign_id, post_id, created_by_user_id, send_mode, scheduled_at, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$campaignId, $postId, $this->userId(), $sendMode, $scheduledAt, 'queued']
            );

            $adapter = new BroadcastAdapters($this->db);
            $results = [];
            foreach ($destinations as $destKey) {
                $dest       = $destMap[$destKey] ?? null;
                $destGroup  = $dest ? (string) $dest['destination_group'] : 'unknown';
                $destStatus = $dest ? (string) $dest['status'] : 'manual_submission';

                $dispatched = $adapter->dispatch($destKey, $destStatus, $sendMode, $event, $post);

                $resultId = $this->db->insert(
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

            // Determine overall broadcast status
            $statuses   = array_column($results, 'status');
            $allSent    = array_unique($statuses) === ['sent'] || array_unique($statuses) === ['queued'];
            $anyFailed  = in_array('failed', $statuses, true);
            $allFailed  = count($statuses) > 0 && count(array_filter($statuses, fn ($s) => $s === 'failed')) === count($statuses);

            $broadcastStatus = match (true) {
                $allFailed  => 'failed',
                $anyFailed  => 'partial_failure',
                default     => 'completed',
            };

            $this->db->run(
                'UPDATE promote_broadcasts SET status = ? WHERE id = ?',
                [$broadcastStatus, $broadcastId]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        log_activity($this->db, $eventId, $this->userId(), 'promote broadcast created', [
            'broadcast_id' => $broadcastId,
            'post_id'      => $postId,
            'destinations' => $destinations,
        ]);

        $broadcast = $this->db->one('SELECT * FROM promote_broadcasts WHERE id = ?', [$broadcastId]);
        $broadcast['results'] = $results;
        return $this->ok(['broadcast' => $broadcast]);
    }
}
