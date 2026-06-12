<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Destination list for a campaign.
 *
 *   GET /api/promote/campaigns/{id}/destinations
 */
final class Destinations extends BaseEndpoint
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
        $destinations = $this->db->all(
            "SELECT * FROM promote_destinations WHERE status != 'disabled' ORDER BY destination_group, label"
        );
        return $this->ok(['destinations' => $destinations]);
    }
}
