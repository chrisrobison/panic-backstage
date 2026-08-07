<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Door-scanning surface: per-event scanner links and ticket redemption.
 *
 * Two distinct authentication models live here, distinguished by the route
 * the Kernel dispatches to (see the params it injects):
 *
 *   (a) MANAGEMENT — JWT + capability 'manage_ticketing'
 *       /api/events/{id}/scanner-links            GET (list)  POST (create)
 *       /api/events/{id}/scanner-links/{linkId}   DELETE (revoke)
 *       Creates/lists/revokes rows in event_scanner_links. On creation the
 *       full scanner URL (carrying the one-time secret) is returned EXACTLY
 *       once; only the sha256 hash + optional password_hash(pin) are stored.
 *
 *   (b) REDEEM — SCANNER TOKEN (no JWT)
 *       POST /api/scan/redeem
 *       body: { scanner_token, pin?, ticket_token }
 *       Validates the scanner link (not expired, not revoked, PIN matches),
 *       then atomically flips the matching ticket issued -> redeemed scoped to
 *       the link's event. Always writes a ticket_scans audit row.
 *
 *       POST /api/scan/tickets  and  POST /api/scan/admit
 *       The same door, for the buyer who has no QR to present — see lookup()
 *       and admit(). Gated on the link's can_lookup bit, because between them
 *       they read customer data and admit by name rather than by token.
 *
 * This endpoint must be registered as PUBLIC in the Kernel so the redeem path
 * works without a JWT. The management methods stay safe regardless: they call
 * requireEventCapability(), which denies access whenever there is no
 * authenticated user (eventAccess() returns null without a userId()).
 */
final class Scanner extends BaseEndpoint
{
    /** Capability gating scanner-link management. */
    private const MANAGE_CAP = 'manage_ticketing';

    /** Crockford-ish base32 alphabet (matches TicketingService token shape). */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function handle(Request $request): Response
    {
        // The scanner-token surfaces are selected by the Kernel via a 'scan' param.
        $scan = $this->params['scan'] ?? null;
        if ($scan !== null) {
            if ($request->method() !== 'POST') {
                return Response::methodNotAllowed();
            }
            return match ($scan) {
                'redeem'  => $this->redeem($request),
                'sell'    => $this->sell($request),
                'context' => $this->context($request),
                'tickets' => $this->lookup($request),
                'admit'   => $this->admit($request),
                default   => $this->notFound('Unknown scanner action'),
            };
        }

        // Otherwise this is the JWT-authenticated scanner-link management surface.
        $eventId = $this->intParam('eventId');
        if ($eventId === null) {
            return $this->notFound('Event not found');
        }
        if ($denied = $this->requireEventCapability($eventId, self::MANAGE_CAP)) {
            return $denied;
        }

        $linkId = $this->intParam('linkId');

        return match ($request->method()) {
            'GET'    => $this->listLinks($eventId),
            'POST'   => $linkId !== null
                ? $this->regenerateLink($eventId, $linkId)
                : $this->createLink($request, $eventId),
            'DELETE' => $this->revokeLink($eventId, $linkId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ─── (a) management ──────────────────────────────────────────────────────────

    /**
     * GET — list this event's scanner links. For links whose plaintext secret
     * is stored (created/regenerated since migration 024) the shareable URL is
     * returned so the link + QR can be re-displayed; older links carry no token
     * (has_token=false) and must be regenerated to reveal a working link.
     */
    private function listLinks(int $eventId): Response
    {
        $rows = $this->db->all(
            'SELECT id, label, token, created_by_user_id, expires_at, revoked_at,
                    last_used_at, created_at, can_sell, can_lookup,
                    (pin_hash IS NOT NULL) AS has_pin
               FROM event_scanner_links
              WHERE event_id = ?
              ORDER BY id DESC',
            [$eventId]
        );

        $links = [];
        foreach ($rows as $r) {
            $token = ($r['token'] ?? null) !== null ? (string) $r['token'] : null;
            $links[] = [
                'id'           => (int) $r['id'],
                'label'        => $r['label'] !== null ? (string) $r['label'] : null,
                'has_pin'      => (bool) (int) $r['has_pin'],
                'can_sell'     => (bool) (int) $r['can_sell'],
                'can_lookup'   => (bool) (int) $r['can_lookup'],
                'has_token'    => $token !== null,
                // Present only when the secret is stored (so the UI can re-show
                // the link/QR). Carries the live secret — admin-gated surface.
                'scanner_url'  => $token !== null ? $this->scannerUrl($token) : null,
                'expires_at'   => $r['expires_at'],
                'revoked_at'   => $r['revoked_at'],
                'last_used_at' => $r['last_used_at'],
                'created_at'   => $r['created_at'],
                'active'       => $r['revoked_at'] === null
                    && ($r['expires_at'] === null || db_timestamp_to_epoch((string) $r['expires_at']) > time()),
            ];
        }

        return $this->ok(['scanner_links' => $links]);
    }

    /**
     * POST — mint a new scanner link. Returns the full scanner URL containing
     * the one-time secret token; it is never recoverable afterward.
     *
     * body: { label?, pin?, expires_at?, can_sell?, can_lookup? }
     */
    private function createLink(Request $request, int $eventId): Response
    {
        $label = trim((string) $request->body('label', ''));
        $pin   = trim((string) $request->body('pin', ''));
        $expRaw = $request->body('expires_at');
        $expires = date_or_null($expRaw);
        // Opt-in only, and only at creation time — there is no endpoint to
        // upgrade an existing scan-only link into a selling or looking-up one,
        // so a link's powers are fixed the moment it is shared.
        $canSell   = self::truthy($request->body('can_sell'));
        $canLookup = self::truthy($request->body('can_lookup'));

        if ($pin !== '' && !ctype_digit($pin)) {
            return Response::json(['error' => 'PIN must be numeric.'], 422);
        }
        if ($pin !== '' && (strlen($pin) < 4 || strlen($pin) > 12)) {
            return Response::json(['error' => 'PIN must be 4–12 digits.'], 422);
        }

        // Secret token: random bytes, base32 for URL/QR friendliness. The
        // sha256 hash drives redeem lookups; the plaintext is also stored so the
        // link/QR can be re-displayed to staff later.
        $token = self::base32Encode(random_bytes(24));
        $hash  = hash('sha256', $token);
        $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;

        $id = $this->db->insert(
            'INSERT INTO event_scanner_links
                (event_id, label, token_hash, token, pin_hash, can_sell, can_lookup, created_by_user_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$eventId, ($label !== '' ? $label : null), $hash, $token, $pinHash, $canSell ? 1 : 0, $canLookup ? 1 : 0, $this->userId(), $expires]
        );

        log_activity($this->db, $eventId, $this->userId(), 'scanner link created', [
            'scanner_link_id' => $id,
            'label'           => $label !== '' ? $label : null,
            'has_pin'         => $pin !== '',
            'can_sell'        => $canSell,
            'can_lookup'      => $canLookup,
        ]);

        return $this->ok([
            'scanner_link' => [
                'id'         => $id,
                'label'      => $label !== '' ? $label : null,
                'has_pin'    => $pin !== '',
                'can_sell'   => $canSell,
                'can_lookup' => $canLookup,
                'expires_at' => $expires,
            ],
            // Returned ONCE — the page URL door staff open on their device.
            'scanner_url' => $this->scannerUrl($token),
            'token'       => $token,
        ]);
    }

    /**
     * POST {linkId} — rotate an existing link's secret and return the fresh
     * URL. Used to reveal a working link/QR for legacy links that predate token
     * storage, or to deliberately rotate a leaked link. Any previously shared
     * copy of this link stops working.
     */
    private function regenerateLink(int $eventId, ?int $linkId): Response
    {
        if ($linkId === null) {
            return $this->notFound('Scanner link not found');
        }
        $link = $this->db->one(
            'SELECT id, revoked_at FROM event_scanner_links WHERE id = ? AND event_id = ?',
            [$linkId, $eventId]
        );
        if ($link === null) {
            return $this->notFound('Scanner link not found');
        }
        if ($link['revoked_at'] !== null) {
            return Response::json(['error' => 'This scanner link is revoked; create a new one.'], 422);
        }

        $token = self::base32Encode(random_bytes(24));
        $hash  = hash('sha256', $token);
        $this->db->run(
            'UPDATE event_scanner_links SET token = ?, token_hash = ? WHERE id = ?',
            [$token, $hash, $linkId]
        );
        log_activity($this->db, $eventId, $this->userId(), 'scanner link regenerated', [
            'scanner_link_id' => $linkId,
        ]);

        return $this->ok([
            'scanner_url' => $this->scannerUrl($token),
            'token'       => $token,
        ]);
    }

    /** DELETE — revoke a scanner link (soft, preserves audit history). */
    private function revokeLink(int $eventId, ?int $linkId): Response
    {
        if ($linkId === null) {
            return $this->notFound('Scanner link not found');
        }
        $link = $this->db->one(
            'SELECT id, revoked_at FROM event_scanner_links WHERE id = ? AND event_id = ?',
            [$linkId, $eventId]
        );
        if ($link === null) {
            return $this->notFound('Scanner link not found');
        }
        if ($link['revoked_at'] === null) {
            $this->db->run(
                'UPDATE event_scanner_links SET revoked_at = NOW() WHERE id = ?',
                [$linkId]
            );
            log_activity($this->db, $eventId, $this->userId(), 'scanner link revoked', [
                'scanner_link_id' => $linkId,
            ]);
        }
        return $this->ok(['ok' => true]);
    }

    // ─── (b) redeem (scanner-token auth) ─────────────────────────────────────────

    /**
     * POST /api/scan/redeem — authenticate by scanner token + optional PIN,
     * then atomically redeem the scanned ticket and always audit the attempt.
     *
     * Response shape (HTTP 200 for every authenticated scan so the scanner UI
     * can render a state; HTTP 401 only when the scanner link itself is bad):
     *   { result, admitted, holder_name, tier, ticket_code, event_id }
     * result ∈ admitted | already_redeemed | void | not_found | wrong_event
     */
    private function redeem(Request $request): Response
    {
        $link = $this->authenticateLink($request);
        if ($link instanceof Response) {
            return $link;
        }

        $eventId     = (int) $link['event_id'];
        $scannerId   = (int) $link['id'];
        $ticketToken = trim((string) $request->body('ticket_token', ''));

        if ($ticketToken === '') {
            return Response::json(['error' => 'No ticket scanned.'], 422);
        }

        // Normalize the scanned token: a QR may encode the bare token or a URL.
        $ticketToken = $this->extractTicketToken($ticketToken);
        $tokenHash   = hash('sha256', $ticketToken);

        return $this->performRedeem($request, $eventId, $scannerId, $tokenHash);
    }

    /**
     * Atomic redemption + audit. The redeem UPDATE is the concurrency guard:
     * exactly one scan can flip issued -> redeemed, so a double-scan races to
     * 'already_redeemed' rather than admitting twice.
     */
    private function performRedeem(Request $request, int $eventId, int $scannerId, string $tokenHash): Response
    {
        $ticket = $this->db->one(
            'SELECT id, event_id, ticket_type_id, status, holder_name FROM tickets WHERE token_hash = ?',
            [$tokenHash]
        );

        $result    = 'not_found';
        $ticketId  = null;
        $holder    = null;
        $tier      = null;
        $code      = null;

        if ($ticket !== null) {
            $ticketId = (int) $ticket['id'];
            $holder   = $ticket['holder_name'] !== null ? (string) $ticket['holder_name'] : null;

            if ((int) $ticket['event_id'] !== $eventId) {
                $result = 'wrong_event';
            } else {
                // Single atomic flip scoped to this event; affected==1 => we won.
                $affected = $this->db->run(
                    "UPDATE tickets
                        SET status = 'redeemed', redeemed_at = NOW(),
                            redeemed_by_user_id = NULL, redeemed_via_scanner_id = :sid
                      WHERE token_hash = :h AND event_id = :eid AND status = 'issued'",
                    [':sid' => $scannerId, ':h' => $tokenHash, ':eid' => $eventId]
                );
                if ($affected === 1) {
                    $result = 'admitted';
                } else {
                    // Re-read to distinguish an already-used ticket from a void one.
                    $status = (string) ($this->db->one('SELECT status FROM tickets WHERE id = ?', [$ticketId])['status'] ?? '');
                    $result = $status === 'void' ? 'void' : 'already_redeemed';
                }

                // Resolve the tier label for display.
                $type = $this->db->one('SELECT name FROM ticket_types WHERE id = ?', [(int) $ticket['ticket_type_id']]);
                $tier = $type !== null ? (string) $type['name'] : null;
            }

            $code = (string) ($this->db->one('SELECT code FROM tickets WHERE id = ?', [$ticketId])['code'] ?? '') ?: null;
        }

        // ALWAYS audit the attempt.
        $this->auditScan($request, $ticketId, $eventId, $result, $scannerId);

        // Human-facing event reference (EVT-N); falls back to the raw id only
        // if the event somehow has no code yet.
        $eventCode = (string) ($this->db->one('SELECT external_id FROM events WHERE id = ?', [$eventId])['external_id'] ?? '') ?: null;

        return $this->ok([
            'result'      => $result,
            'admitted'    => $result === 'admitted',
            'holder_name' => $holder,
            'tier'        => $tier,
            'ticket_code' => $code,
            'event_id'    => $eventId,
            'event_code'  => $eventCode,
        ]);
    }

    // ─── (c) door sales (scanner-token auth) ─────────────────────────────────────

    /**
     * POST /api/scan/context — what this scanner link is allowed to do and what
     * it can sell. The scanner page has no JWT and no event id of its own, so
     * this is how it learns the event label, whether sales are enabled, and the
     * live tier list to render in the picker.
     *
     * Door sales deliberately IGNORE sales_start/sales_end: online sales
     * normally close before doors open, and the whole point of a door sale is
     * that it happens after that window. Tier status is still respected, so a
     * closed/draft/paused tier stays unsellable.
     */
    private function context(Request $request): Response
    {
        $link = $this->authenticateLink($request);
        if ($link instanceof Response) {
            return $link;
        }

        $eventId   = (int) $link['event_id'];
        $canSell   = (bool) (int) ($link['can_sell'] ?? 0);
        $canLookup = (bool) (int) ($link['can_lookup'] ?? 0);

        $event = $this->db->one(
            'SELECT title, external_id, ticketing_mode, ticket_url FROM events WHERE id = ?',
            [$eventId]
        );

        $tiers = [];
        if ($canSell) {
            $service = new TicketingService();
            $rows = $this->db->all(
                "SELECT id, name, price_cents, currency, status
                   FROM ticket_types
                  WHERE event_id = ? AND status IN ('on_sale', 'sold_out')
                  ORDER BY sort_order ASC, id ASC",
                [$eventId]
            );
            foreach ($rows as $r) {
                $typeId = (int) $r['id'];
                $tiers[] = [
                    'id'          => $typeId,
                    'name'        => (string) $r['name'],
                    'price_cents' => (int) $r['price_cents'],
                    'currency'    => (string) ($r['currency'] ?? 'USD'),
                    'available'   => $service->availableQuantity($this->db, $typeId),
                ];
            }
        }

        return $this->ok([
            'event_id'    => $eventId,
            'event_code'  => (string) ($event['external_id'] ?? '') ?: null,
            'event_title' => (string) ($event['title'] ?? '') ?: null,
            'can_sell'    => $canSell,
            'can_lookup'  => $canLookup,
            'ticket_types' => $tiers,
            // Self-serve card purchase: the buyer scans this from the door
            // device's screen and checks out on their own phone.
            'buy_url'     => $this->buyUrl($eventId, $event ?? []),
        ]);
    }

    /**
     * Public "buy a ticket" page for this event, for the door's self-serve QR.
     *
     * An event on external ticketing sends buyers to whatever provider it uses;
     * everything else goes to our own public event page, which is where the
     * in-house checkout (and therefore card payment) lives. Returns null when
     * an external event has no URL configured, so the scanner can hide the
     * option rather than show a QR that goes nowhere.
     */
    private function buyUrl(int $eventId, array $event): ?string
    {
        if ((string) ($event['ticketing_mode'] ?? '') === 'external') {
            $url = trim((string) ($event['ticket_url'] ?? ''));
            return $url !== '' ? $url : null;
        }

        // Same APP_URL/APP_BASE_PATH handling as scannerUrl() — APP_URL usually
        // already carries the base path, so only append when it doesn't.
        $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $basePath = rtrim((string) (getenv('APP_BASE_PATH') ?: ''), '/');
        if ($basePath !== '' && !str_ends_with($base, $basePath)) {
            $base .= $basePath;
        }
        return $base . '/event.html?id=' . rawurlencode((string) $eventId);
    }

    /**
     * POST /api/scan/sell — log a walk-up sale and admit the buyer.
     *
     * body: { scanner_token, pin?, ticket_type_id, quantity?, payment_method,
     *         holder_name?, holder_email? }
     *
     * Mirrors redeem()'s contract: HTTP 200 with a 'result' for anything the
     * door staff can act on (sold / sold_out / unknown_type), 401 only when the
     * scanner link itself is rejected — so scanner.js keeps one error style.
     *
     * result ∈ sold | sold_out | unknown_type | not_permitted
     */
    private function sell(Request $request): Response
    {
        $link = $this->authenticateLink($request);
        if ($link instanceof Response) {
            return $link;
        }

        $eventId   = (int) $link['event_id'];
        $scannerId = (int) $link['id'];

        // Sales are opt-in per link (migration 091). A link minted for scanning
        // only can never ring up money, so a leaked scanner URL stays bounded at
        // the damage it could already do.
        if (!(bool) (int) ($link['can_sell'] ?? 0)) {
            return $this->ok([
                'result'  => 'not_permitted',
                'sold'    => false,
                'message' => 'This scanner link is not allowed to sell tickets.',
            ]);
        }

        $typeId   = (int) $request->body('ticket_type_id', 0);
        $quantity = (int) $request->body('quantity', 1);
        $method   = strtolower(trim((string) $request->body('payment_method', '')));
        $name     = trim((string) $request->body('holder_name', ''));
        $email    = trim((string) $request->body('holder_email', ''));

        if ($quantity < 1 || $quantity > 20) {
            return Response::json(['error' => 'Quantity must be between 1 and 20.'], 422);
        }
        if (!in_array($method, ['cash', 'card', 'other'], true)) {
            return Response::json(['error' => 'Payment method must be cash, card, or other.'], 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'That email address is not valid.'], 422);
        }

        // Scope the tier to this link's event — a scanner link must never be
        // able to sell inventory belonging to some other event.
        $type = $this->db->one(
            'SELECT id, name, price_cents, currency, status
               FROM ticket_types WHERE id = ? AND event_id = ?',
            [$typeId, $eventId]
        );
        if ($type === null || !in_array((string) $type['status'], ['on_sale', 'sold_out'], true)) {
            return $this->ok([
                'result'  => 'unknown_type',
                'sold'    => false,
                'message' => 'That ticket type is not available at the door.',
            ]);
        }

        $service = new TicketingService();
        try {
            $sale = $service->recordDoorSale(
                $this->db,
                $typeId,
                $quantity,
                $method,
                $name !== '' ? $name : null,
                $email !== '' ? $email : null,
                $scannerId,
                null // no JWT user at the door; the scanner link is the actor
            );
        } catch (\RuntimeException $e) {
            // The oversell guard is the only expected failure here, and it is
            // the one door staff genuinely need to see.
            if (stripos($e->getMessage(), 'oversell') !== false) {
                return $this->ok([
                    'result'  => 'sold_out',
                    'sold'    => false,
                    'message' => 'Not enough tickets left in that tier.',
                ]);
            }
            throw $e;
        }

        // A door sale is an admission too — audit it exactly like a scan so the
        // ticket_scans headcount stays true.
        foreach ($sale['tickets'] as $ticket) {
            $this->auditScan($request, (int) $ticket['id'], $eventId, 'admitted', $scannerId);
        }

        log_activity($this->db, $eventId, null, 'door sale recorded', [
            'scanner_link_id' => $scannerId,
            'order_id'        => $sale['order_id'],
            'ticket_type'     => (string) $type['name'],
            'quantity'        => $quantity,
            'amount_cents'    => $sale['amount_cents'],
            'payment_method'  => $method,
        ]);

        // Best-effort receipt when the buyer gave an address. Never let a mail
        // failure cost us the sale that is already committed to the DB.
        if ($email !== '') {
            try {
                $service->emailTickets($this->db, $this->root, $sale['order_id'], $sale['tickets']);
            } catch (\Throwable $e) {
                error_log('Door sale receipt email failed for order ' . $sale['order_id'] . ': ' . $e->getMessage());
            }
        }

        $codes = array_map(static fn(array $t): string => (string) $t['code'], $sale['tickets']);

        return $this->ok([
            'result'         => 'sold',
            'sold'           => true,
            'admitted'       => true,
            'order_id'       => $sale['order_id'],
            'quantity'       => $quantity,
            'tier'           => (string) $type['name'],
            'amount_cents'   => $sale['amount_cents'],
            'currency'       => $sale['currency'],
            'payment_method' => $method,
            'holder_name'    => $name !== '' ? $name : null,
            'emailed'        => $email !== '',
            'ticket_codes'   => $codes,
            'ticket_code'    => $codes[0] ?? null,
            'event_id'       => $eventId,
        ]);
    }

    // ─── (d) no-QR admission (scanner-token auth) ────────────────────────────────

    /**
     * POST /api/scan/tickets — the guest list, for the buyer who cannot produce
     * their QR. Door staff find them by name, check ID, then admit via
     * /api/scan/admit.
     *
     * Deliberately field-stripped: NO token and NO ticket URL, ever. The admin
     * ticket list returns those so staff can re-send a QR, but this surface is
     * authenticated by a bearer URL sitting on a phone at a door — if it handed
     * back tokens, a leaked link could reprint or redeem every ticket for the
     * show. Everything here is what someone standing at the door legitimately
     * needs to match a person to a purchase, and nothing more; the email is
     * masked because it disambiguates two guests with the same name without
     * handing over a mailing list.
     *
     * result ∈ ok | not_permitted
     */
    private function lookup(Request $request): Response
    {
        $link = $this->authenticateLink($request);
        if ($link instanceof Response) {
            return $link;
        }

        if (!(bool) (int) ($link['can_lookup'] ?? 0)) {
            return $this->ok([
                'result'  => 'not_permitted',
                'tickets' => [],
                'message' => 'This scanner link is not allowed to look up tickets.',
            ]);
        }

        $eventId = (int) $link['event_id'];

        // Optional server-side filter. The list is small enough to send whole
        // (a room's worth of tickets), and scanner.js filters as you type, but
        // a query keeps the payload sane for a big show on a phone connection.
        $q = trim((string) $request->body('q', ''));

        $sql = "SELECT t.id, t.code, t.holder_name, t.holder_email, t.status, t.redeemed_at,
                       tt.name AS tier_name, COALESCE(o.is_comp, 0) AS is_comp
                  FROM tickets t
                  JOIN ticket_types tt ON tt.id = t.ticket_type_id
                  LEFT JOIN ticket_orders o ON o.id = t.order_id
                 WHERE t.event_id = ?";
        $args = [$eventId];
        if ($q !== '') {
            $sql .= " AND (t.holder_name LIKE ? OR t.holder_email LIKE ? OR t.code LIKE ?)";
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        // Unredeemed first — those are the ones the door still has to act on —
        // then alphabetically, which is how you scan a list for a name.
        $sql .= " ORDER BY (t.status = 'issued') DESC, t.holder_name IS NULL, t.holder_name ASC, t.id ASC
                  LIMIT 500";

        $rows = $this->db->all($sql, $args);

        $tickets = array_map(fn (array $r): array => [
            'id'           => (int) $r['id'],
            'code'         => (string) $r['code'],
            'tier'         => (string) $r['tier_name'],
            'holder_name'  => $r['holder_name'] !== null ? (string) $r['holder_name'] : null,
            'holder_email' => self::maskEmail($r['holder_email'] !== null ? (string) $r['holder_email'] : null),
            'status'       => (string) $r['status'],
            'is_comp'      => (bool) (int) $r['is_comp'],
            'redeemed_at'  => $r['redeemed_at'],
        ], $rows);

        return $this->ok([
            'result'   => 'ok',
            'event_id' => $eventId,
            'tickets'  => $tickets,
        ]);
    }

    /**
     * POST /api/scan/admit — admit a ticket found by lookup, with no QR in hand.
     *
     * body: { scanner_token, pin?, ticket_id }
     *
     * The state transition is identical to redeem()'s (issued -> redeemed, one
     * atomic UPDATE that exactly one caller can win), so a manually admitted
     * ticket cannot then be walked in again on its QR, and two staff tapping
     * the same row race to 'already_redeemed' rather than admitting twice.
     * What differs is the audit row: 'manual_admit' records that a human
     * accepted an ID here instead of a machine verifying a token.
     *
     * Mirrors redeem()'s response contract so scanner.js renders it with the
     * same code path: HTTP 200 + a 'result' for anything door staff can act on,
     * 401 only when the link itself is rejected.
     *
     * result ∈ admitted | already_redeemed | void | not_found | not_permitted
     */
    private function admit(Request $request): Response
    {
        $link = $this->authenticateLink($request);
        if ($link instanceof Response) {
            return $link;
        }

        if (!(bool) (int) ($link['can_lookup'] ?? 0)) {
            return $this->ok([
                'result'   => 'not_permitted',
                'admitted' => false,
                'message'  => 'This scanner link is not allowed to admit without a QR.',
            ]);
        }

        $eventId   = (int) $link['event_id'];
        $scannerId = (int) $link['id'];
        $ticketId  = (int) $request->body('ticket_id', 0);

        if ($ticketId <= 0) {
            return Response::json(['error' => 'No ticket selected.'], 422);
        }

        // Scoped to this link's event — a scanner link must never admit a
        // ticket belonging to some other show, even if it learns the id.
        $ticket = $this->db->one(
            'SELECT t.id, t.code, t.status, t.holder_name, tt.name AS tier_name
               FROM tickets t
               JOIN ticket_types tt ON tt.id = t.ticket_type_id
              WHERE t.id = ? AND t.event_id = ?',
            [$ticketId, $eventId]
        );

        if ($ticket === null) {
            // Audit the miss too, exactly as a failed scan is audited.
            $this->auditScan($request, null, $eventId, 'not_found', $scannerId);
            return $this->ok([
                'result'   => 'not_found',
                'admitted' => false,
                'message'  => 'That ticket is not for this event.',
            ]);
        }

        $affected = $this->db->run(
            "UPDATE tickets
                SET status = 'redeemed', redeemed_at = NOW(),
                    redeemed_by_user_id = NULL, redeemed_via_scanner_id = :sid
              WHERE id = :id AND event_id = :eid AND status = 'issued'",
            [':sid' => $scannerId, ':id' => $ticketId, ':eid' => $eventId]
        );

        if ($affected === 1) {
            $result = 'admitted';
        } else {
            $status = (string) ($this->db->one('SELECT status FROM tickets WHERE id = ?', [$ticketId])['status'] ?? '');
            $result = $status === 'void' ? 'void' : 'already_redeemed';
        }

        // 'manual_admit' only for an admission that actually happened here; a
        // rejected attempt keeps the same vocabulary a failed scan would use.
        $this->auditScan($request, $ticketId, $eventId, $result === 'admitted' ? 'manual_admit' : $result, $scannerId);

        if ($result === 'admitted') {
            log_activity($this->db, $eventId, null, 'ticket admitted without QR', [
                'scanner_link_id' => $scannerId,
                'ticket_id'       => $ticketId,
                'code'            => (string) $ticket['code'],
            ]);
        }

        return $this->ok([
            'result'      => $result,
            'admitted'    => $result === 'admitted',
            'manual'      => true,
            'holder_name' => $ticket['holder_name'] !== null ? (string) $ticket['holder_name'] : null,
            'tier'        => (string) $ticket['tier_name'],
            'ticket_code' => (string) $ticket['code'],
            'event_id'    => $eventId,
        ]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────────

    /**
     * Write a ticket_scans audit row. Every admission decision made at a door —
     * scanned, sold, or waved through by hand — lands here, because this table
     * is the headcount and the dispute record.
     */
    private function auditScan(Request $request, ?int $ticketId, int $eventId, string $result, int $scannerId): void
    {
        $this->db->run(
            'INSERT INTO ticket_scans
                (ticket_id, event_id, result, scanner_link_id, scanned_by_user_id, ip, user_agent)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [
                $ticketId,
                $eventId,
                $result,
                $scannerId,
                $this->clientIp(),
                substr((string) ($request->header('User-Agent') ?? ''), 0, 255) ?: null,
            ]
        );
    }

    /**
     * Mask an email for the door list: enough to tell two same-named guests
     * apart, not enough to be worth harvesting. j***@example.com.
     */
    private static function maskEmail(?string $email): ?string
    {
        $email = $email !== null ? trim($email) : '';
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', $email, 2);
        $head = $user !== '' ? substr($user, 0, 1) : '';
        return $head . '***@' . $domain;
    }


    /**
     * Validate the scanner-link secret (+ PIN) carried in the request body and
     * return the link row, or a 401 Response describing why it was rejected.
     *
     * Shared by every scanner-token surface so redeem/sell/context can never
     * drift apart on what counts as an authenticated door device. Touches
     * last_used_at on success, matching the original redeem behaviour.
     *
     * @return array<string,mixed>|Response
     */
    private function authenticateLink(Request $request): array|Response
    {
        $scannerToken = trim((string) $request->body('scanner_token', ''));
        $pin          = trim((string) $request->body('pin', ''));

        if ($scannerToken === '') {
            return Response::json(['error' => 'Scanner token required.'], 401);
        }

        $link = $this->db->one(
            'SELECT id, event_id, pin_hash, can_sell, can_lookup, expires_at, revoked_at
               FROM event_scanner_links WHERE token_hash = ?',
            [hash('sha256', $scannerToken)]
        );

        // Invalid / revoked / expired scanner link -> 401, no audit row (we have
        // no trustworthy event scope to attribute the attempt to).
        if ($link === null || $link['revoked_at'] !== null) {
            return Response::json(['error' => 'Invalid or revoked scanner link.'], 401);
        }
        if ($link['expires_at'] !== null && db_timestamp_to_epoch((string) $link['expires_at']) <= time()) {
            return Response::json(['error' => 'This scanner link has expired.'], 401);
        }
        if ($link['pin_hash'] !== null && !password_verify($pin, (string) $link['pin_hash'])) {
            return Response::json(['error' => 'Incorrect PIN.'], 401);
        }

        // Touch last_used_at on any authenticated use of the link.
        $this->db->run(
            'UPDATE event_scanner_links SET last_used_at = NOW() WHERE id = ?',
            [(int) $link['id']]
        );

        return $link;
    }


    /**
     * Accept either a bare token or a full scan/ticket URL and return the bare
     * token. Tokens are uppercase base32; we strip any URL wrapper and keep the
     * token-shaped portion.
     */
    private function extractTicketToken(string $raw): string
    {
        $raw = trim($raw);
        // If it looks like a URL, pull a ?t=, ?token=, or trailing path segment.
        if (str_contains($raw, '://') || str_contains($raw, '?')) {
            $query = parse_url($raw, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $q);
                foreach (['t', 'token', 'ticket', 'tk'] as $k) {
                    if (!empty($q[$k]) && is_string($q[$k])) {
                        return strtoupper(trim((string) $q[$k]));
                    }
                }
            }
            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $seg  = trim($path, '/');
            if ($seg !== '' && str_contains($seg, '/')) {
                $seg = substr($seg, strrpos($seg, '/') + 1);
            }
            if ($seg !== '') {
                return strtoupper($seg);
            }
        }
        return strtoupper($raw);
    }

    /** Build the scanner page URL carrying the one-time link secret. */
    private function scannerUrl(string $token): string
    {
        $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $basePath = rtrim((string) (getenv('APP_BASE_PATH') ?: ''), '/');
        // APP_URL is the canonical public root and usually already includes the
        // base path (e.g. https://host/backstage). Only append APP_BASE_PATH
        // when it isn't already the tail of APP_URL — otherwise we'd produce a
        // doubled /backstage/backstage/scanner.html (every asset 404s).
        if ($basePath !== '' && !str_ends_with($base, $basePath)) {
            $base .= $basePath;
        }
        return $base . '/scanner.html?token=' . rawurlencode($token);
    }

    /** Best-effort client IP for the audit row. */
    private function clientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? substr($ip, 0, 45) : null;
    }

    /** Accept the usual JSON/form spellings of a boolean flag. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function intParam(string $key): ?int
    {
        $value = $this->params[$key] ?? null;
        return ctype_digit((string) $value) ? (int) $value : null;
    }

    /**
     * Mint a scanner link programmatically (no PIN, no expiry) and return its
     * id. Shared by endpoints that seed a default door link — e.g. switching an
     * event to in-house ticketing. The plaintext token is stored, so the
     * link/QR can be re-displayed later from the scanner-links list.
     */
    public static function mintLink(Database $db, int $eventId, ?string $label, ?int $userId): int
    {
        $token = self::base32Encode(random_bytes(24));
        $hash  = hash('sha256', $token);
        return $db->insert(
            'INSERT INTO event_scanner_links
                (event_id, label, token_hash, token, pin_hash, created_by_user_id, expires_at)
             VALUES (?, ?, ?, ?, NULL, ?, NULL)',
            [$eventId, ($label !== null && $label !== '' ? $label : null), $hash, $token, $userId]
        );
    }

    /** RFC 4648 base32 (uppercase, no padding) of arbitrary bytes. */
    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        $len  = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::BASE32[bindec($chunk)];
        }
        return $out;
    }
}
