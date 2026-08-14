<?php
declare(strict_types=1);

namespace Panic;

use Panic\Events\Report;

/**
 * Client-facing read-only portal — token-gated, no staff login required.
 *
 * Public actions (validated by portal token, not JWT):
 *   GET  /api/portal/view?token=...
 *
 * Staff-only actions (require valid JWT + any authenticated user):
 *   POST /api/portal/{eventId}/create-link
 *   POST /api/portal/{tokenId}/revoke
 *   GET  /api/portal/{eventId}/list-links
 *
 * Route wiring lives in Kernel.php resolve() (segments[0] === 'portal').
 * Portal::class is also listed in isPublic() so the 'view' action is
 * reachable without a JWT; the staff actions perform their own
 * requireAuth() check.
 *
 * Every token has a `kind` (portal_tokens.kind — see migration 089):
 *   - client_portal:     the original use — event/contract/payments/invoice
 *     subset for a promoter or client, rendered by public/portal.html.
 *     Creating one only requires manage_contracts.
 *   - settlement_report: the full P&L/Settlement Report (Events\Report's
 *     buildData(), the same data the printed Settlement Statement uses),
 *     rendered by public/report-share.html. Because that data includes
 *     staffing names, vendor costs, and payout detail, creating one of
 *     these requires view_settlement on the specific event, checked in
 *     createLink() in addition to the generic requireAuth().
 */
final class Portal extends BaseEndpoint
{
    private const KINDS = ['client_portal', 'settlement_report'];

    public function handle(Request $request): Response
    {
        $action = $this->params['action'] ?? '';

        return match ($action) {
            'view'        => $this->view($request),
            'create-link' => $this->createLink($request),
            'revoke'      => $this->revokeLink($request),
            'list-links'  => $this->listLinks($request),
            default       => Response::json(['error' => 'Not found'], 404),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public: view portal by token
    // ─────────────────────────────────────────────────────────────────────────

    private function view(Request $request): Response
    {
        $token = $request->query('token') ?? '';
        if (strlen($token) < 32) {
            return Response::json(['error' => 'Invalid token'], 400);
        }

        $row = $this->db->one(
            'SELECT pt.*, e.title, e.status, e.show_time, e.doors_time, e.venue_id
             FROM portal_tokens pt
             JOIN events e ON e.id = pt.event_id
             WHERE pt.token = ? AND pt.is_revoked = 0 AND pt.expires_at > NOW()',
            [$token]
        );
        if (!$row) {
            return Response::json(['error' => 'Link expired or invalid'], 404);
        }

        $eventId = (int) $row['event_id'];

        // Update usage tracking
        $this->db->run(
            'UPDATE portal_tokens SET last_used_at = NOW(), use_count = use_count + 1 WHERE token = ?',
            [$token]
        );

        if (($row['kind'] ?? 'client_portal') === 'settlement_report') {
            $report = (new Report($this->db, $this->auth, [], $this->root))->buildData($eventId);
            if ($report === null) {
                return Response::json(['error' => 'Link expired or invalid'], 404);
            }
            return $this->ok(['kind' => 'settlement_report'] + $report);
        }

        // Fetch event summary — safe public subset only (no internal notes, no capabilities)
        $event = $this->db->one(
            'SELECT id, title, status, event_type, show_time, doors_time, end_time,
                    age_restriction, public_description, series_id
             FROM events WHERE id = ?',
            [$eventId]
        );

        // Contract status: prefer the most-executed contract. Also matches a
        // series-wide contract (series_id set — see Events\Contracts::create()'s
        // apply_to_series option) so a client viewing any occurrence in a
        // recurring run sees the one shared contract that covers it, not just
        // one generated directly against this specific date.
        $contract = $this->db->one(
            "SELECT id, status, created_at FROM contracts
             WHERE event_id = ? OR (series_id IS NOT NULL AND series_id = ?)
             ORDER BY FIELD(status,'fully_executed','signed','sent','draft') DESC, id DESC
             LIMIT 1",
            [$eventId, !empty($event['series_id']) ? (int) $event['series_id'] : null]
        );

        // Payments: inbound only — what the client has paid or still owes
        // direction='received' = money coming in from client/promoter
        $payments = $this->db->all(
            "SELECT payment_type, amount, currency, status, method, due_date, received_at, notes
             FROM event_payments
             WHERE event_id = ? AND direction = 'received'
             ORDER BY created_at",
            [$eventId]
        );

        // Ledger: revenue and payment lines only — what the venue is invoicing them
        $ledger = $this->db->all(
            "SELECT category, line_type, amount, currency, description
             FROM event_ledger_entries
             WHERE event_id = ? AND line_type IN ('revenue','payment') AND is_void = 0
             ORDER BY created_at",
            [$eventId]
        );

        return $this->ok([
            'kind'     => 'client_portal',
            'event'    => $event,
            'contract' => $contract,
            'payments' => $payments,
            'invoice'  => $ledger,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Staff only: generate a portal link for an event
    // ─────────────────────────────────────────────────────────────────────────

    private function createLink(Request $request): Response
    {
        if ($deny = $this->requireAuth()) return $deny;

        $b       = $request->body();
        $eventId = (int) ($b['event_id'] ?? $this->params['eventId'] ?? 0);
        if (!$eventId) {
            return Response::json(['error' => 'event_id required'], 400);
        }

        // Verify the event exists
        $exists = $this->db->one('SELECT id FROM events WHERE id = ?', [$eventId]);
        if (!$exists) {
            return $this->notFound('Event not found');
        }

        $kind = (string) ($b['kind'] ?? 'client_portal');
        if (!in_array($kind, self::KINDS, true)) {
            return Response::json(['error' => 'Invalid kind'], 422);
        }
        // A settlement-report link hands out staffing names, vendor costs,
        // and payout detail with no login required — gate creating one on
        // the same capability the in-app Report tab and print statement
        // require, not just "any authenticated user" like the client portal.
        if ($kind === 'settlement_report' && ($denied = $this->requireEventCapability($eventId, 'view_settlement'))) {
            return $denied;
        }

        $ttlDays = max(1, min(90, (int) ($b['ttl_days'] ?? 30)));
        $token   = bin2hex(random_bytes(32));   // 64-char hex, 256-bit entropy
        $label   = trim((string) ($b['label'] ?? ''));

        $id = $this->db->insert(
            'INSERT INTO portal_tokens (event_id, kind, token, label, created_by_id, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$eventId, $kind, $token, $label !== '' ? $label : null, $this->userId(), $ttlDays]
        );

        $page      = $kind === 'settlement_report' ? 'report-share.html' : 'portal.html';
        $portalUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/' . $page . '?token=' . $token;

        return $this->ok([
            'id'          => $id,
            'token'       => $token,
            'kind'        => $kind,
            'url'         => $portalUrl,
            'expires_days' => $ttlDays,
            'label'       => $label !== '' ? $label : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Staff only: revoke a portal token by its DB id
    // ─────────────────────────────────────────────────────────────────────────

    private function revokeLink(Request $request): Response
    {
        if ($deny = $this->requireAuth()) return $deny;

        $tokenId = (int) ($this->params['tokenId'] ?? 0);
        if (!$tokenId) {
            return Response::json(['error' => 'tokenId required'], 400);
        }

        $this->db->run('UPDATE portal_tokens SET is_revoked = 1 WHERE id = ?', [$tokenId]);
        return $this->ok(['revoked' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Staff only: list all portal links for an event
    // ─────────────────────────────────────────────────────────────────────────

    private function listLinks(Request $request): Response
    {
        if ($deny = $this->requireAuth()) return $deny;

        $eventId = (int) ($this->params['eventId'] ?? $request->query('event_id') ?? 0);
        if (!$eventId) {
            return Response::json(['error' => 'eventId required'], 400);
        }

        $links = $this->db->all(
            'SELECT pt.id, pt.kind, pt.token, pt.label, pt.expires_at, pt.last_used_at,
                    pt.use_count, pt.is_revoked, pt.created_at
             FROM portal_tokens pt
             WHERE pt.event_id = ?
             ORDER BY pt.created_at DESC',
            [$eventId]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        foreach ($links as &$link) {
            $page = $link['kind'] === 'settlement_report' ? 'report-share.html' : 'portal.html';
            $link['url'] = $appUrl . '/' . $page . '?token=' . $link['token'];
        }
        unset($link);

        return $this->ok(['links' => $links]);
    }
}
