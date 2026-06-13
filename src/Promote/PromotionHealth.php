<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\Database;

/**
 * Computes a deterministic promotion health score for a campaign.
 *
 * Returns:
 *   {score, complete, total, items[{key, label, status, severity, detail}]}
 */
final class PromotionHealth
{
    public function __construct(private readonly Database $db) {}

    public function compute(array $campaign, ?array $event, array $posts, array $assets): array
    {
        $items  = [];
        $event  = $event ?? [];

        // 1. Panic event page published
        $pagePublished = (bool) (int) ($event['public_visibility'] ?? 0);
        $items[] = [
            'key'      => 'panic_page_published',
            'label'    => 'Panic event page published',
            'status'   => $pagePublished ? 'done' : 'missing',
            'severity' => $pagePublished ? 'success' : 'error',
            'detail'   => $pagePublished ? 'Public page is live' : 'Event page is not yet public',
        ];

        // 2. Approved flyer exists
        $approvedFlyers = array_filter($assets, fn ($a) => $a['asset_type'] === 'flyer' && $a['approval_status'] === 'approved');
        $hasFlyer       = !empty($approvedFlyers);
        $items[] = [
            'key'      => 'approved_flyer',
            'label'    => 'Approved flyer exists',
            'status'   => $hasFlyer ? 'done' : 'missing',
            'severity' => $hasFlyer ? 'success' : 'warning',
            'detail'   => $hasFlyer ? 'Flyer approved' : 'No approved flyer uploaded',
        ];

        // 3. Instagram post approved
        $igApproved = $this->variantApproved($posts, 'instagram');
        $items[] = [
            'key'      => 'instagram_approved',
            'label'    => 'Instagram announcement post approved',
            'status'   => $igApproved ? 'done' : 'missing',
            'severity' => $igApproved ? 'success' : 'warning',
            'detail'   => $igApproved ? 'Instagram variant approved' : 'No approved Instagram variant',
        ];

        // 4. Facebook post approved
        $fbApproved = $this->variantApproved($posts, 'facebook');
        $items[] = [
            'key'      => 'facebook_approved',
            'label'    => 'Facebook event/promo post approved',
            'status'   => $fbApproved ? 'done' : 'missing',
            'severity' => $fbApproved ? 'success' : 'warning',
            'detail'   => $fbApproved ? 'Facebook variant approved' : 'No approved Facebook variant',
        ];

        // 5. Eventbrite listing prepared
        $ebDone = $this->broadcastResultExists((int) $campaign['id'], 'eventbrite');
        $items[] = [
            'key'      => 'eventbrite_listing',
            'label'    => 'Eventbrite listing prepared',
            'status'   => $ebDone ? 'done' : 'missing',
            'severity' => $ebDone ? 'success' : 'info',
            'detail'   => $ebDone ? 'Broadcast sent/queued' : 'No Eventbrite broadcast yet',
        ];

        // 6. Luma listing prepared
        $lumaDone = $this->broadcastResultExists((int) $campaign['id'], 'luma');
        $items[] = [
            'key'      => 'luma_listing',
            'label'    => 'Luma listing prepared',
            'status'   => $lumaDone ? 'done' : 'missing',
            'severity' => $lumaDone ? 'success' : 'info',
            'detail'   => $lumaDone ? 'Broadcast sent/queued' : 'No Luma broadcast yet',
        ];

        // 7. Funcheap submitted
        $funcheapDone = $this->broadcastResultExists((int) $campaign['id'], 'funcheap');
        $items[] = [
            'key'      => 'funcheap_submitted',
            'label'    => 'Funcheap submitted',
            'status'   => $funcheapDone ? 'done' : 'missing',
            'severity' => $funcheapDone ? 'success' : 'info',
            'detail'   => $funcheapDone ? 'Broadcast created' : 'Funcheap not yet submitted',
        ];

        // 8. Foopee submitted
        $foopeeDone = $this->broadcastResultExists((int) $campaign['id'], 'foopee');
        $items[] = [
            'key'      => 'foopee_submitted',
            'label'    => 'Foopee submitted',
            'status'   => $foopeeDone ? 'done' : 'missing',
            'severity' => $foopeeDone ? 'success' : 'info',
            'detail'   => $foopeeDone ? 'Broadcast created' : 'Foopee not yet submitted',
        ];

        // 9. Press email prepared
        $pressApproved = $this->variantApproved($posts, 'press');
        $items[] = [
            'key'      => 'press_email_prepared',
            'label'    => 'Press email prepared',
            'status'   => $pressApproved ? 'done' : 'missing',
            'severity' => $pressApproved ? 'success' : 'info',
            'detail'   => $pressApproved ? 'Press variant approved' : 'No approved press variant',
        ];

        // 10. Email blast scheduled or sent
        $emailDone = $this->broadcastResultExists((int) $campaign['id'], 'email_general');
        $items[] = [
            'key'      => 'email_blast',
            'label'    => 'Email blast scheduled',
            'status'   => $emailDone ? 'done' : 'missing',
            'severity' => $emailDone ? 'success' : 'warning',
            'detail'   => $emailDone ? 'Email broadcast created' : 'No email blast created yet',
        ];

        // 11. At least one post created
        $hasPosts = count($posts) > 0;
        $items[] = [
            'key'      => 'posts_created',
            'label'    => 'At least one marketing post created',
            'status'   => $hasPosts ? 'done' : 'missing',
            'severity' => $hasPosts ? 'success' : 'warning',
            'detail'   => $hasPosts ? count($posts) . ' post(s) created' : 'No posts yet',
        ];

        // 12. Campaign has goal set
        $hasGoal = (int) ($campaign['goal_tickets'] ?? 0) > 0;
        $items[] = [
            'key'      => 'goal_set',
            'label'    => 'Ticket goal set',
            'status'   => $hasGoal ? 'done' : 'missing',
            'severity' => $hasGoal ? 'success' : 'info',
            'detail'   => $hasGoal ? 'Goal: ' . $campaign['goal_tickets'] . ' tickets' : 'No ticket goal configured',
        ];

        // 13. SF Chronicle pitch sent
        $chronicleDone = $this->broadcastResultExists((int) $campaign['id'], 'sf_chronicle');
        $items[] = [
            'key'      => 'sf_chronicle_submitted',
            'label'    => 'SF Chronicle pitch sent',
            'status'   => $chronicleDone ? 'done' : 'missing',
            'severity' => $chronicleDone ? 'success' : 'info',
            'detail'   => $chronicleDone ? 'Broadcast created' : 'SF Chronicle not yet pitched',
        ];

        // 14. SF Station submitted
        $sfStationDone = $this->broadcastResultExists((int) $campaign['id'], 'sf_station');
        $items[] = [
            'key'      => 'sf_station_submitted',
            'label'    => 'SF Station listing submitted',
            'status'   => $sfStationDone ? 'done' : 'missing',
            'severity' => $sfStationDone ? 'success' : 'info',
            'detail'   => $sfStationDone ? 'Broadcast created' : 'SF Station not yet submitted',
        ];

        // 15. DoTheBay submitted
        $dothebayDone = $this->broadcastResultExists((int) $campaign['id'], 'dothebay');
        $items[] = [
            'key'      => 'dothebay_submitted',
            'label'    => 'DoTheBay listing submitted',
            'status'   => $dothebayDone ? 'done' : 'missing',
            'severity' => $dothebayDone ? 'success' : 'info',
            'detail'   => $dothebayDone ? 'Broadcast created' : 'DoTheBay not yet submitted',
        ];

        // 16. SongKick submitted
        $songkickDone = $this->broadcastResultExists((int) $campaign['id'], 'songkick');
        $items[] = [
            'key'      => 'songkick_submitted',
            'label'    => 'SongKick listing submitted',
            'status'   => $songkickDone ? 'done' : 'missing',
            'severity' => $songkickDone ? 'success' : 'info',
            'detail'   => $songkickDone ? 'Broadcast created' : 'SongKick not yet submitted',
        ];

        // 17. JamBase submitted
        $jambaseDone = $this->broadcastResultExists((int) $campaign['id'], 'jambase');
        $items[] = [
            'key'      => 'jambase_submitted',
            'label'    => 'JamBase listing submitted',
            'status'   => $jambaseDone ? 'done' : 'missing',
            'severity' => $jambaseDone ? 'success' : 'info',
            'detail'   => $jambaseDone ? 'Broadcast created' : 'JamBase not yet submitted',
        ];

        // 18. Ad-hoc email sent
        $adhocDone = $this->broadcastResultExists((int) $campaign['id'], 'email_adhoc');
        $items[] = [
            'key'      => 'email_adhoc_sent',
            'label'    => 'Ad-hoc press / VIP emails sent',
            'status'   => $adhocDone ? 'done' : 'missing',
            'severity' => $adhocDone ? 'success' : 'info',
            'detail'   => $adhocDone ? 'Ad-hoc broadcast created' : 'No ad-hoc email broadcast yet',
        ];

        // 19. Day-before reminder scheduled
        $eventDate    = !empty($event['date']) ? $event['date'] : null;
        $reminderDone = false;
        if ($eventDate) {
            // Look for any broadcast scheduled within the 36-hour window before the show date
            $windowStart = date('Y-m-d H:i:s', strtotime($eventDate . ' -36 hours'));
            $windowEnd   = date('Y-m-d H:i:s', strtotime($eventDate . ' 23:59:59'));
            $row = $this->db->one(
                "SELECT b.id FROM promote_broadcasts b
                 WHERE b.campaign_id = ? AND b.send_mode = 'scheduled'
                   AND b.scheduled_at BETWEEN ? AND ?
                 LIMIT 1",
                [(int) $campaign['id'], $windowStart, $windowEnd]
            );
            $reminderDone = $row !== null;
        }
        $items[] = [
            'key'      => 'day_before_reminder',
            'label'    => 'Day-before reminder scheduled',
            'status'   => $reminderDone ? 'done' : 'missing',
            'severity' => $reminderDone ? 'success' : 'info',
            'detail'   => $reminderDone
                ? 'Scheduled broadcast found within 36 h of show date'
                : 'No day-before reminder broadcast scheduled yet',
        ];

        // 20. Band assets collected
        $bandAssets  = array_filter(
            $assets,
            fn ($a) => in_array($a['asset_type'], ['band_photo', 'logo'], true)
                    && $a['approval_status'] === 'approved'
        );
        $hasBandAssets = !empty($bandAssets);
        $items[] = [
            'key'      => 'band_assets_collected',
            'label'    => 'Band assets collected',
            'status'   => $hasBandAssets ? 'done' : 'missing',
            'severity' => $hasBandAssets ? 'success' : 'info',
            'detail'   => $hasBandAssets
                ? count($bandAssets) . ' approved band photo(s)/logo(s) on file'
                : 'No approved band photos or logos uploaded',
        ];

        $total    = count($items);
        $complete = count(array_filter($items, fn ($i) => $i['status'] === 'done'));
        $score    = $total > 0 ? (int) round(($complete / $total) * 100) : 0;

        return [
            'score'    => $score,
            'complete' => $complete,
            'total'    => $total,
            'items'    => $items,
        ];
    }

    /** True if any post has an approved variant for the given channel. */
    private function variantApproved(array $posts, string $channel): bool
    {
        if (empty($posts)) {
            return false;
        }
        $postIds      = array_map(fn ($p) => (int) $p['id'], $posts);
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $params       = array_values($postIds);
        $params[]     = $channel;
        $row = $this->db->one(
            "SELECT id FROM promote_post_variants
             WHERE post_id IN ($placeholders) AND channel = ? AND status = 'approved'
             LIMIT 1",
            $params
        );
        return $row !== null;
    }

    /** True if the campaign has at least one broadcast result for the given destination key. */
    private function broadcastResultExists(int $campaignId, string $destKey): bool
    {
        $row = $this->db->one(
            'SELECT r.id FROM promote_broadcast_results r
             JOIN promote_broadcasts b ON b.id = r.broadcast_id
             WHERE b.campaign_id = ? AND r.destination_key = ? LIMIT 1',
            [$campaignId, $destKey]
        );
        return $row !== null;
    }
}
