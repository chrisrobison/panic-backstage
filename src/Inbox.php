<?php
declare(strict_types=1);

namespace Panic;

/**
 * Booking Inbox cross-lead endpoints that aren't about one specific lead:
 *
 *   GET  /api/inbox/list?view=...&q=...                 the inquiry queue (saved views)
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
            'list' => $this->list($request),
            'changes' => $this->changes($request),
            'counts' => $this->counts(),
            default => Response::json(['error' => 'Unknown Booking Inbox endpoint'], 404),
        };
    }

    private const OPEN_STATUSES_EXCLUDE = "'onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled'";

    /**
     * The saved views the spec calls out by name. `view` maps 1:1 to the
     * nav_items children (migration 077) for the five primary ones, plus a
     * handful more surfaced as an in-page dropdown (see incoming-ui.png's
     * "Unassigned ▾" view switcher) rather than separate nav entries.
     */
    private function list(Request $request): Response
    {
        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');
        $me = (int) $this->userId();
        $view = (string) $request->query('view', 'all');
        $q = trim((string) $request->query('q', ''));

        $where = [$scopeSql];
        $params = $scopeParams;

        switch ($view) {
            case 'mine':
                $where[] = "(l.assigned_to_user_id = ? OR l.claimed_by_user_id = ? OR l.owner_user_id = ?)";
                array_push($params, $me, $me, $me);
                $where[] = 'l.status NOT IN (' . self::OPEN_STATUSES_EXCLUDE . ')';
                break;
            case 'unassigned':
                $where[] = 'l.assigned_to_user_id IS NULL AND l.claimed_by_user_id IS NULL';
                $where[] = "l.status IN ('new','classified')";
                break;
            case 'follow_up':
                $where[] = "l.status = 'awaiting_customer'";
                break;
            case 'archived':
                $where[] = "l.status = 'archived'";
                break;
            case 'awaiting_first_response':
                $where[] = 'l.first_response_at IS NULL';
                $where[] = "l.status IN ('assigned','claimed','acknowledged')";
                break;
            case 'claims_expiring':
                $where[] = "l.status = 'claimed' AND l.claim_expires_at IS NOT NULL AND l.claim_expires_at <= (NOW() + INTERVAL 1 HOUR)";
                break;
            case 'follow_up_overdue':
                $where[] = "l.status = 'awaiting_customer' AND l.updated_at <= (NOW() - INTERVAL 3 DAY)";
                break;
            case 'high_value':
                $where[] = 'l.budget IS NOT NULL AND l.budget >= 5000';
                break;
            case 'recently_onboarded':
                $where[] = "l.status = 'onboarded' AND l.updated_at >= (NOW() - INTERVAL 30 DAY)";
                break;
            case 'declined':
                $where[] = "l.status IN ('declined','lost')";
                break;
            case 'all':
            default:
                $where[] = 'l.status NOT IN (' . self::OPEN_STATUSES_EXCLUDE . ')';
                break;
        }

        if ($q !== '') {
            $where[] = '(l.contact_name LIKE ? OR l.contact_org LIKE ? OR l.contact_email LIKE ? OR l.event_name LIKE ? OR l.inquiry_number LIKE ?)';
            $needle = '%' . $q . '%';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }

        $leads = $this->db->all(
            "SELECT l.*, au.name assigned_to_name, cu.name claimed_by_name, ou.name owner_name
             FROM leads l
             LEFT JOIN users au ON au.id = l.assigned_to_user_id
             LEFT JOIN users cu ON cu.id = l.claimed_by_user_id
             LEFT JOIN users ou ON ou.id = l.owner_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY l.created_at DESC LIMIT 200",
            $params
        );

        return $this->ok(['leads' => $leads, 'view' => $view]);
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
