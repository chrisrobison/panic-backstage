<?php
declare(strict_types=1);

namespace Panic;

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
 * Route wiring needed in Kernel.php resolve() — see KERNEL ROUTE note below.
 *
 * KERNEL ROUTE TO ADD MANUALLY:
 * In resolve(), add before the default fallback at the bottom:
 *
 *   'portal' => match($action) {
 *       'view'        => [Portal::class, ['action' => 'view']],
 *       'create-link' => [Portal::class, ['action' => 'create-link', 'eventId' => (int)($segments[2] ?? 0)]],
 *       'revoke'      => [Portal::class, ['action' => 'revoke',      'tokenId' => (int)($segments[2] ?? 0)]],
 *       'list-links'  => [Portal::class, ['action' => 'list-links',  'eventId' => (int)($segments[2] ?? 0)]],
 *       default       => [Portal::class, ['action' => '']],
 *   },
 *
 * Also add Portal::class to isPublic() so the 'view' action is reachable
 * without a JWT.  The staff actions perform their own requireAuth() check.
 */
final class Portal extends BaseEndpoint
{
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

        // Fetch event summary — safe public subset only (no internal notes, no capabilities)
        $event = $this->db->one(
            'SELECT id, title, status, event_type, show_time, doors_time, end_time,
                    age_restriction, public_description
             FROM events WHERE id = ?',
            [$eventId]
        );

        // Contract status: prefer the most-executed contract
        $contract = $this->db->one(
            "SELECT id, status, created_at FROM contracts
             WHERE event_id = ?
             ORDER BY FIELD(status,'fully_executed','signed','sent','draft') DESC, id DESC
             LIMIT 1",
            [$eventId]
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

        $ttlDays = max(1, min(90, (int) ($b['ttl_days'] ?? 30)));
        $token   = bin2hex(random_bytes(32));   // 64-char hex, 256-bit entropy
        $label   = trim((string) ($b['label'] ?? ''));

        $id = $this->db->insert(
            'INSERT INTO portal_tokens (event_id, token, label, created_by_id, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$eventId, $token, $label !== '' ? $label : null, $this->userId(), $ttlDays]
        );

        $portalUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/portal.html?token=' . $token;

        return $this->ok([
            'id'          => $id,
            'token'       => $token,
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
            'SELECT pt.id, pt.token, pt.label, pt.expires_at, pt.last_used_at,
                    pt.use_count, pt.is_revoked, pt.created_at
             FROM portal_tokens pt
             WHERE pt.event_id = ?
             ORDER BY pt.created_at DESC',
            [$eventId]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        foreach ($links as &$link) {
            $link['url'] = $appUrl . '/portal.html?token=' . $link['token'];
        }
        unset($link);

        return $this->ok(['links' => $links]);
    }
}
