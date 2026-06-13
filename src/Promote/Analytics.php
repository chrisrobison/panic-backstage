<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Database;
use Panic\Request;
use Panic\Response;

/**
 * Analytics endpoint — returns real broadcast metrics from the DB, with
 * null placeholders for platform-specific data (Eventbrite ticket sales,
 * email opens, Luma RSVPs) that require external API calls.
 *
 *   GET /api/promote/campaigns/{id}/analytics
 */
final class Analytics extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        $campaignId = (int) ($this->params['campaignId'] ?? 0);
        if (!$campaignId) {
            return $this->notFound('Campaign not found');
        }
        $campaign = $this->db->one('SELECT event_id FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        if ($denied = $this->requireEventCapability((int) $campaign['event_id'], 'read_event')) {
            return $denied;
        }
        return $this->ok(['analytics' => self::compute($this->db, $campaignId)]);
    }

    // ── Public computation ────────────────────────────────────────────────────

    /**
     * Compute real analytics from broadcast results stored in the DB.
     * Also returns null placeholders for platform metrics that need
     * external API calls (ticket_sales, email_opens, luma_rsvps) so the UI
     * can display "—  via Eventbrite" rather than a meaningless zero.
     *
     * The destination_results array contains one row per destination key,
     * using the most-recent broadcast result (highest id) for each.
     */
    public static function compute(Database $db, int $campaignId): array
    {
        // All broadcast results for this campaign, ordered newest first
        $rows = $db->all(
            'SELECT r.destination_key, r.destination_group, r.status,
                    r.external_url, r.error_message, r.updated_at, r.id
             FROM promote_broadcast_results r
             JOIN promote_broadcasts b ON b.id = r.broadcast_id
             WHERE b.campaign_id = ?
             ORDER BY r.id DESC',
            [$campaignId]
        );

        // Deduplicate: keep the latest result per destination_key
        $latestByDest = [];
        foreach ($rows as $r) {
            $key = (string) $r['destination_key'];
            if (!isset($latestByDest[$key])) {
                $latestByDest[$key] = $r;
            }
        }

        // Aggregate counters
        $sent         = 0;
        $queued       = 0;
        $manualPending = 0;
        $needsSetup   = 0;
        $failed       = 0;
        $liveListings = 0;

        foreach ($latestByDest as $r) {
            switch ($r['status']) {
                case 'sent':             $sent++;          break;
                case 'queued':           $queued++;        break;
                case 'manual_required':  $manualPending++; break;
                case 'needs_auth':       $needsSetup++;    break;
                case 'failed':           $failed++;        break;
            }
            if (!empty($r['external_url'])) {
                $liveListings++;
            }
        }

        $destinationsReached = $sent + $queued;
        $totalBroadcasts     = count($rows);   // total result rows (one per dest per broadcast)

        // Broadcast count (distinct broadcast IDs)
        $broadcastCount = (int) ($db->one(
            'SELECT COUNT(DISTINCT id) cnt FROM promote_broadcasts WHERE campaign_id = ?',
            [$campaignId]
        )['cnt'] ?? 0);

        // Sort destination_results by group then key for consistent display
        $destResults = array_values($latestByDest);
        usort($destResults, fn ($a, $b) =>
            strcmp((string) $a['destination_group'], (string) $b['destination_group']) ?:
            strcmp((string) $a['destination_key'],   (string) $b['destination_key'])
        );

        // Strip the internal id field from the response
        $destResults = array_map(function (array $r): array {
            unset($r['id']);
            return $r;
        }, $destResults);

        return [
            // ── Core broadcast metrics (always real) ──────────────────────────
            'broadcast_count'      => $broadcastCount,
            'destinations_reached' => $destinationsReached,
            'listings_live'        => $liveListings,
            'manual_pending'       => $manualPending,
            'needs_setup'          => $needsSetup,
            'failed_count'         => $failed,

            // ── Platform-specific metrics (null = not yet connected / queried) ─
            // These will be populated in a future update once the platform
            // adapters gain read-back / webhook integrations.
            'ticket_sales'         => null,   // Eventbrite orders API
            'luma_rsvps'           => null,   // Luma /v1/events/guests/list
            'email_opens'          => null,   // Mailchimp/SendGrid campaign reports
            'email_clicks'         => null,   // Mailchimp/SendGrid campaign reports

            // ── Status breakdown (for progress bar / tooltip) ─────────────────
            'status_counts' => [
                'sent'             => $sent,
                'queued'           => $queued,
                'manual_required'  => $manualPending,
                'needs_auth'       => $needsSetup,
                'failed'           => $failed,
            ],

            // ── Per-destination latest result ─────────────────────────────────
            'destination_results'  => $destResults,

            // ── Legacy keys kept for UI backwards-compat ──────────────────────
            'website_clicks'       => $destinationsReached,
            'rsvps'                => 0,
            'ticket_conversions'   => 0,
            'email_opens_legacy'   => 0,
        ];
    }

    /** Stub — used for campaigns with no broadcasts yet, or on error. */
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
            // Legacy
            'website_clicks'       => 0,
            'rsvps'                => 0,
            'ticket_conversions'   => 0,
            'email_opens_legacy'   => 0,
        ];
    }
}
