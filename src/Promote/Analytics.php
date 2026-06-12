<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Analytics endpoint — returns stub zeros for MVP.
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
        return $this->ok(['analytics' => self::stub()]);
    }

    /** Stub analytics payload — zeros until real integrations exist. */
    public static function stub(): array
    {
        return [
            'website_clicks'     => 0,
            'rsvps'              => 0,
            'ticket_conversions' => 0,
            'email_opens'        => 0,
            'email_clicks'       => 0,
            'reach_estimate'     => 0,
            'note'               => 'Analytics are stubs — real platform integrations coming soon.',
        ];
    }
}
