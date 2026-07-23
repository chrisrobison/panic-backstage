<?php
declare(strict_types=1);

namespace Panic;

/**
 * Booking Inbox cross-lead endpoints that aren't about one specific lead:
 *
 *   GET  /api/inbox/changes?since=<Y-m-d H:i:s|epoch>   realtime-by-polling feed
 *   GET  /api/inbox/counts                              left-nav badge counts
 *
 * No SSE/WebSocket precedent exists anywhere in this app (see
 * docs/booking-inbox.md) — the Inbox UI polls `changes` every few seconds
 * while open and republishes what comes back onto the existing core.js
 * pub/sub bus, same "child reacts to a bubbling event" pattern the Tasks
 * app already uses for same-tab updates.
 */
final class Inbox extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_booking_inbox')) {
            return $denied;
        }
        $action = $this->params['action'] ?? '';
        return match ($action) {
            'changes' => $this->changes($request),
            'counts' => $this->counts(),
            default => Response::json(['error' => 'Unknown Booking Inbox endpoint'], 404),
        };
    }

    private function changes(Request $request): Response
    {
        $since = (string) $request->query('since', '1970-01-01 00:00:00');
        if (ctype_digit($since)) {
            $since = gmdate('Y-m-d H:i:s', (int) $since);
        }

        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');

        $leads = $this->db->all(
            "SELECT l.id, l.inquiry_number, l.status, l.assigned_to_user_id, l.claimed_by_user_id,
                    l.owner_user_id, l.claim_expires_at, l.sla_claim_due_at, l.updated_at
             FROM leads l WHERE $scopeSql AND l.updated_at >= ? ORDER BY l.updated_at DESC LIMIT 200",
            [...$scopeParams, $since]
        );

        $messages = $this->db->all(
            "SELECT m.id, m.lead_id, m.direction, m.created_at FROM lead_messages m
             JOIN leads l ON l.id = m.lead_id
             WHERE $scopeSql AND m.created_at >= ? ORDER BY m.created_at DESC LIMIT 200",
            [...$scopeParams, $since]
        );

        return $this->ok([
            'leads' => $leads,
            'messages' => $messages,
            'server_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function counts(): Response
    {
        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');
        $me = (int) $this->userId();

        $row = $this->db->one(
            "SELECT
                SUM(CASE WHEN l.assigned_to_user_id = ? AND l.status NOT IN ('onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled') THEN 1 ELSE 0 END) mine,
                SUM(CASE WHEN l.assigned_to_user_id IS NULL AND l.claimed_by_user_id IS NULL AND l.status IN ('new','classified') THEN 1 ELSE 0 END) unassigned,
                SUM(CASE WHEN l.status NOT IN ('onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled') THEN 1 ELSE 0 END) all_open,
                SUM(CASE WHEN l.status = 'awaiting_customer' THEN 1 ELSE 0 END) follow_up,
                SUM(CASE WHEN l.status = 'archived' THEN 1 ELSE 0 END) archived
             FROM leads l WHERE $scopeSql",
            [$me, ...$scopeParams]
        );

        return $this->ok(['counts' => [
            'mine' => (int) ($row['mine'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'all' => (int) ($row['all_open'] ?? 0),
            'follow_up' => (int) ($row['follow_up'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ]]);
    }
}
