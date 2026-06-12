<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Health endpoint — computes and returns promotion health for a campaign.
 *
 *   GET /api/promote/campaigns/{id}/health
 */
final class HealthEndpoint extends BaseEndpoint
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
        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        $eventId = (int) $campaign['event_id'];
        if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
            return $denied;
        }
        $event = $this->db->one(
            'SELECT * FROM events WHERE id = ?',
            [$eventId]
        );
        $posts = $this->db->all(
            'SELECT * FROM promote_posts WHERE campaign_id = ?',
            [$campaignId]
        );
        $assets = $this->db->all(
            'SELECT * FROM event_assets WHERE event_id = ?',
            [$eventId]
        );
        $health = (new PromotionHealth($this->db))->compute($campaign, $event, $posts, $assets);
        return $this->ok(['health' => $health]);
    }
}
