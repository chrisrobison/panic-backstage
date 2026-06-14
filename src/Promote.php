<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Promote overview — /api/promote/events[/{eventId}]
 *
 * GET  /api/promote/events          → list events with promote activity (upcoming + recent)
 * GET  /api/promote/events/{id}     → full overview: settings, posts, health, analytics, destinations
 * PATCH /api/promote/events/{id}    → update promote settings (goal_tickets, notes, status)
 */
final class Promote extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->params['eventId'] ?? null;
        return match ($request->method()) {
            'GET'   => $eventId ? $this->show((int) $eventId) : $this->index(),
            'PATCH' => $this->update($request, (int) $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    // ── Event list ───────────────────────────────────────────────────────────

    private function index(): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $events = $this->db->all(
            "SELECT e.id event_id, e.title event_title, e.date event_date,
                    e.status event_status, e.public_visibility,
                    e.ticket_url, e.capacity,
                    ps.status promote_status, ps.goal_tickets,
                    (SELECT COUNT(*) FROM promote_posts pp WHERE pp.event_id = e.id) post_count,
                    (SELECT COUNT(*) FROM promote_broadcasts pb WHERE pb.event_id = e.id) broadcast_count
             FROM events e
             LEFT JOIN promote_settings ps ON ps.event_id = e.id
             WHERE $scopeSql
             ORDER BY e.date DESC, e.id DESC
             LIMIT 200",
            $scopeParams
        );
        return $this->ok(['events' => $events]);
    }

    // ── Event overview ───────────────────────────────────────────────────────

    private function show(int $eventId): Response
    {
        if (!$eventId) {
            return $this->notFound('Event not found');
        }
        if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
            return $denied;
        }
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound('Event not found');
        }
        return $this->ok($this->buildOverview($eventId, $event));
    }

    // ── Update promote settings ──────────────────────────────────────────────

    private function update(Request $request, int $eventId): Response
    {
        if (!$eventId) {
            return $this->notFound('Event not found');
        }
        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }
        $body    = $request->body();
        $allowed = ['draft', 'active', 'paused', 'completed', 'archived'];
        $current = $this->db->one('SELECT * FROM promote_settings WHERE event_id = ?', [$eventId]);

        $status = isset($body['status']) && in_array($body['status'], $allowed, true)
            ? $body['status']
            : (string) ($current['status'] ?? 'draft');
        $goalTickets = array_key_exists('goal_tickets', $body)
            ? ($body['goal_tickets'] !== null && $body['goal_tickets'] !== '' ? (int) $body['goal_tickets'] : null)
            : ($current['goal_tickets'] ?? null);
        $notes = array_key_exists('notes', $body) ? $body['notes'] : ($current['notes'] ?? null);

        $this->db->run(
            'INSERT INTO promote_settings (event_id, status, goal_tickets, notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), goal_tickets = VALUES(goal_tickets),
               notes = VALUES(notes)',
            [$eventId, $status, $goalTickets, $notes, $this->userId()]
        );

        log_activity($this->db, $eventId, $this->userId(), 'promote settings updated', []);

        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
             FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        return $this->ok($this->buildOverview($eventId, $event));
    }

    // ── Overview builder (shared by show/update) ─────────────────────────────

    public function buildOverview(int $eventId, array $event): array
    {
        $posts = $this->db->all(
            'SELECT p.*, u.name created_by_name
             FROM promote_posts p LEFT JOIN users u ON u.id = p.created_by_user_id
             WHERE p.event_id = ? ORDER BY p.created_at DESC',
            [$eventId]
        );
        $assets = $this->db->all(
            'SELECT * FROM event_assets WHERE event_id = ? ORDER BY created_at DESC',
            [$eventId]
        );
        $destinations = $this->db->all(
            "SELECT * FROM promote_destinations WHERE status != 'disabled' ORDER BY destination_group, label"
        );
        $settings = $this->db->one('SELECT * FROM promote_settings WHERE event_id = ?', [$eventId])
            ?? ['status' => 'draft', 'goal_tickets' => null, 'notes' => null];

        $health    = (new Promote\PromotionHealth($this->db))->compute($settings, $event, $posts, $assets, $eventId);
        $analytics = Promote\Analytics::compute($this->db, $eventId);

        return [
            'settings'     => $settings,
            'event'        => $event,
            'posts'        => $posts,
            'assets'       => $assets,
            'destinations' => $destinations,
            'health'       => $health,
            'analytics'    => $analytics,
        ];
    }
}
