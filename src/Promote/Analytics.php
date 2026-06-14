<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Database;
use Panic\Request;
use Panic\Response;

/**
 * Analytics endpoint.
 *
 *   GET /api/promote/events/{id}/analytics
 */
final class Analytics extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        $eventId = (int) ($this->params['eventId'] ?? 0);
        if (!$eventId) {
            return $this->notFound('Event not found');
        }
        if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
            return $denied;
        }
        return $this->ok(['analytics' => self::compute($this->db, $eventId)]);
    }

    // ── Public computation ────────────────────────────────────────────────────

    /**
     * Compute real analytics from broadcast results stored in the DB.
     */
    public static function compute(Database $db, int $eventId): array
    {
        $rows = $db->all(
            'SELECT r.destination_key, r.destination_group, r.status,
                    r.external_url, r.error_message, r.updated_at, r.id
             FROM promote_broadcast_results r
             JOIN promote_broadcasts b ON b.id = r.broadcast_id
             WHERE b.event_id = ?
             ORDER BY r.id DESC',
            [$eventId]
        );

        // Deduplicate: keep the latest result per destination_key
        $latestByDest = [];
        foreach ($rows as $r) {
            $key = (string) $r['destination_key'];
            if (!isset($latestByDest[$key])) {
                $latestByDest[$key] = $r;
            }
        }

        $sent          = 0;
        $queued        = 0;
        $manualPending = 0;
        $needsSetup    = 0;
        $failed        = 0;
        $liveListings  = 0;

        foreach ($latestByDest as $r) {
            switch ($r['status']) {
                case 'sent':            $sent++;          break;
                case 'queued':          $queued++;        break;
                case 'manual_required': $manualPending++; break;
                case 'needs_auth':      $needsSetup++;    break;
                case 'failed':          $failed++;        break;
            }
            if (!empty($r['external_url'])) {
                $liveListings++;
            }
        }

        $destinationsReached = $sent + $queued;

        $broadcastCount = (int) ($db->one(
            'SELECT COUNT(DISTINCT id) cnt FROM promote_broadcasts WHERE event_id = ?',
            [$eventId]
        )['cnt'] ?? 0);

        $destResults = array_values($latestByDest);
        usort($destResults, fn ($a, $b) =>
            strcmp((string) $a['destination_group'], (string) $b['destination_group']) ?:
            strcmp((string) $a['destination_key'],   (string) $b['destination_key'])
        );
        $destResults = array_map(function (array $r): array {
            unset($r['id']);
            return $r;
        }, $destResults);

        return [
            'broadcast_count'      => $broadcastCount,
            'destinations_reached' => $destinationsReached,
            'listings_live'        => $liveListings,
            'manual_pending'       => $manualPending,
            'needs_setup'          => $needsSetup,
            'failed_count'         => $failed,
            'ticket_sales'         => null,
            'luma_rsvps'           => null,
            'email_opens'          => null,
            'email_clicks'         => null,
            'status_counts' => [
                'sent'            => $sent,
                'queued'          => $queued,
                'manual_required' => $manualPending,
                'needs_auth'      => $needsSetup,
                'failed'          => $failed,
            ],
            'destination_results' => $destResults,
            'website_clicks'      => $destinationsReached,
            'rsvps'               => 0,
            'ticket_conversions'  => 0,
            'email_opens_legacy'  => 0,
        ];
    }

    /** Stub — used when no broadcasts exist yet, or on error. */
    public static function stub(): array
    {
        return [
            'broadcast_count'      => 0,
            'destinations_reached' => 0,
            'listings_live'        => 0,
            'manual_pending'       => 0,
            'needs_setup'          => 0,
            'failed_count'         => 0,
            'ticket_sales'         => null,
            'luma_rsvps'           => null,
            'email_opens'          => null,
            'email_clicks'         => null,
            'status_counts'        => [
                'sent' => 0, 'queued' => 0, 'manual_required' => 0,
                'needs_auth' => 0, 'failed' => 0,
            ],
            'destination_results'  => [],
            'website_clicks'       => 0,
            'rsvps'                => 0,
            'ticket_conversions'   => 0,
            'email_opens_legacy'   => 0,
        ];
    }
}
