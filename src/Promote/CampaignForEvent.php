<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Promote;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Routes for event-scoped campaign access:
 *
 *   GET  /api/promote/events/{eventId}           → overview for event, or event payload with campaign = null
 *   POST /api/promote/events/{eventId}/campaign  → create or return existing campaign
 */
final class CampaignForEvent extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = (int) ($this->params['eventId'] ?? 0);
        if (!$eventId) {
            return $this->notFound('Event not found');
        }

        // POST .../campaign — create (or fetch existing) campaign for an event
        if ($request->method() === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
                return $denied;
            }
            return $this->createOrFetch($request, $eventId);
        }

        // GET .../events/{eventId} — fetch campaign overview
        if ($request->method() === 'GET') {
            if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
                return $denied;
            }
            $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE event_id = ?', [$eventId]);
            if (!$campaign) {
                return $this->ok($this->buildNoCampaignPayload($eventId));
            }
            return $this->ok($this->buildOverview($campaign));
        }

        return Response::methodNotAllowed();
    }

    private function createOrFetch(Request $request, int $eventId): Response
    {
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }
        $existing = $this->db->one('SELECT * FROM promote_campaigns WHERE event_id = ?', [$eventId]);
        if ($existing) {
            return $this->ok($this->buildOverview($existing));
        }

        $body        = $request->body();
        $title       = ($body['title'] ?? '') !== '' ? (string) $body['title'] : (string) $event['title'];
        $goalTickets = isset($body['goal_tickets']) && $body['goal_tickets'] !== ''
            ? (int) $body['goal_tickets']
            : ($event['capacity'] ? (int) $event['capacity'] : null);

        $id = $this->db->insert(
            'INSERT INTO promote_campaigns (event_id, title, status, goal_tickets, notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, $title, 'draft', $goalTickets, $body['notes'] ?? null, $this->userId()]
        );
        log_activity($this->db, $eventId, $this->userId(), 'promote campaign created', ['campaign_id' => $id]);
        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$id]);
        return $this->ok($this->buildOverview($campaign));
    }

    private function buildOverview(array $campaign): array
    {
        $eventId    = (int) $campaign['event_id'];
        $campaignId = (int) $campaign['id'];

        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        $posts = $this->db->all(
            'SELECT p.*, u.name created_by_name
             FROM promote_posts p LEFT JOIN users u ON u.id = p.created_by_user_id
             WHERE p.campaign_id = ? ORDER BY p.created_at DESC',
            [$campaignId]
        );
        $assets = $this->db->all(
            'SELECT * FROM event_assets WHERE event_id = ? ORDER BY created_at DESC',
            [$eventId]
        );
        $destinations = $this->db->all(
            'SELECT * FROM promote_destinations WHERE status != ? ORDER BY destination_group, label',
            ['disabled']
        );

        $health    = (new PromotionHealth($this->db))->compute($campaign, $event, $posts, $assets);
        $analytics = Analytics::compute($this->db, $campaignId);

        return [
            'campaign'     => $campaign,
            'event'        => $event,
            'posts'        => $posts,
            'assets'       => $assets,
            'destinations' => $destinations,
            'health'       => $health,
            'analytics'    => $analytics,
        ];
    }

    private function buildNoCampaignPayload(int $eventId): array
    {
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        if (!$event) {
            return [
                'campaign'     => null,
                'event'        => null,
                'posts'        => [],
                'assets'       => [],
                'destinations' => [],
                'health'       => null,
                'analytics'    => Analytics::stub(),
            ];
        }

        return [
            'campaign'     => null,
            'event'        => $event,
            'posts'        => [],
            'assets'       => [],
            'destinations' => [],
            'health'       => null,
            'analytics'    => Analytics::stub(),
        ];
    }
}
