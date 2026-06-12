<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Campaign CRUD — /api/promote/campaigns[/{id}]
 *
 * GET  /api/promote/campaigns          → list all campaigns the user can see
 * POST /api/promote/campaigns          → create campaign (requires event_id in body)
 * GET  /api/promote/campaigns/{id}     → campaign overview (rich payload)
 * PATCH /api/promote/campaigns/{id}    → update campaign fields
 */
final class Promote extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $campaignId = $this->params['campaignId'] ?? null;
        return match ($request->method()) {
            'GET'   => $campaignId ? $this->show((int) $campaignId) : $this->index(),
            'POST'  => $this->create($request),
            'PATCH' => $this->update($request, (int) $campaignId),
            default => Response::methodNotAllowed(),
        };
    }

    // ── Campaign list ────────────────────────────────────────────────────────

    private function index(): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $campaigns = $this->db->all(
            "SELECT pc.*, e.title event_title, e.date event_date, e.status event_status,
                    e.public_visibility, e.ticket_url, e.capacity, e.doors_time, e.show_time,
                    e.age_restriction
             FROM promote_campaigns pc
             JOIN events e ON e.id = pc.event_id
             WHERE $scopeSql
             ORDER BY e.date DESC, pc.id DESC
             LIMIT 200",
            $scopeParams
        );
        return $this->ok(['campaigns' => $campaigns]);
    }

    // ── Campaign overview (rich payload) ─────────────────────────────────────

    private function show(int $campaignId): Response
    {
        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        if ($denied = $this->requireEventCapability((int) $campaign['event_id'], 'read_event')) {
            return $denied;
        }
        return $this->ok($this->buildOverview($campaign));
    }

    // ── Create campaign ──────────────────────────────────────────────────────

    private function create(Request $request): Response
    {
        $body    = $request->body();
        $eventId = (int) ($body['event_id'] ?? 0);
        if (!$eventId) {
            return Response::json(['error' => 'event_id is required'], 422);
        }
        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound('Event not found');
        }
        // Return existing campaign if already created
        $existing = $this->db->one('SELECT * FROM promote_campaigns WHERE event_id = ?', [$eventId]);
        if ($existing) {
            return $this->ok($this->buildOverview($existing));
        }
        $title = ($body['title'] ?? '') !== '' ? (string) $body['title'] : (string) $event['title'];
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

    // ── Update campaign ──────────────────────────────────────────────────────

    private function update(Request $request, int $campaignId): Response
    {
        if (!$campaignId) {
            return $this->notFound('Campaign not found');
        }
        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        if ($denied = $this->requireEventCapability((int) $campaign['event_id'], 'edit_event')) {
            return $denied;
        }
        $body  = $request->body();
        $allowedStatuses = ['draft', 'active', 'paused', 'completed', 'archived'];
        $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true)
            ? $body['status'] : (string) $campaign['status'];
        $title = ($body['title'] ?? '') !== '' ? (string) $body['title'] : (string) $campaign['title'];
        $goalTickets = array_key_exists('goal_tickets', $body)
            ? ($body['goal_tickets'] !== null && $body['goal_tickets'] !== '' ? (int) $body['goal_tickets'] : null)
            : $campaign['goal_tickets'];
        $notes = array_key_exists('notes', $body) ? $body['notes'] : $campaign['notes'];

        $this->db->run(
            'UPDATE promote_campaigns SET title = ?, status = ?, goal_tickets = ?, notes = ? WHERE id = ?',
            [$title, $status, $goalTickets, $notes, $campaignId]
        );
        $updated = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        return $this->ok($this->buildOverview($updated));
    }

    // ── Overview builder (shared by create/show/update) ──────────────────────

    private function buildOverview(array $campaign): array
    {
        $eventId = (int) $campaign['event_id'];
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

        $health   = (new Promote\PromotionHealth($this->db))->compute($campaign, $event, $posts, $assets);
        $analytics = Promote\Analytics::stub();

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
}
