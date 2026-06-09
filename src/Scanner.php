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
        // The redeem surface is selected by the Kernel via a 'scan' param.
        if (($this->params['scan'] ?? null) === 'redeem') {
            return match ($request->method()) {
                'POST'  => $this->redeem($request),
                default => Response::methodNotAllowed(),
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
            'POST'   => $this->createLink($request, $eventId),
            'DELETE' => $this->revokeLink($eventId, $linkId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ─── (a) management ──────────────────────────────────────────────────────────

    /** GET — list this event's scanner links (never exposes secrets). */
    private function listLinks(int $eventId): Response
    {
        $rows = $this->db->all(
            'SELECT id, label, created_by_user_id, expires_at, revoked_at,
                    last_used_at, created_at,
                    (pin_hash IS NOT NULL) AS has_pin
               FROM event_scanner_links
              WHERE event_id = ?
              ORDER BY id DESC',
            [$eventId]
        );

        $links = [];
        foreach ($rows as $r) {
            $links[] = [
                'id'           => (int) $r['id'],
                'label'        => $r['label'] !== null ? (string) $r['label'] : null,
                'has_pin'      => (bool) (int) $r['has_pin'],
                'expires_at'   => $r['expires_at'],
                'revoked_at'   => $r['revoked_at'],
                'last_used_at' => $r['last_used_at'],
                'created_at'   => $r['created_at'],
                'active'       => $r['revoked_at'] === null
                    && ($r['expires_at'] === null || strtotime((string) $r['expires_at']) > time()),
            ];
        }

        return $this->ok(['scanner_links' => $links]);
    }

    /**
     * POST — mint a new scanner link. Returns the full scanner URL containing
     * the one-time secret token; it is never recoverable afterward.
     *
     * body: { label?, pin?, expires_at? }
     */
    private function createLink(Request $request, int $eventId): Response
    {
        $label = trim((string) $request->body('label', ''));
        $pin   = trim((string) $request->body('pin', ''));
        $expRaw = $request->body('expires_at');
        $expires = date_or_null($expRaw);

        if ($pin !== '' && !ctype_digit($pin)) {
            return Response::json(['error' => 'PIN must be numeric.'], 422);
        }
        if ($pin !== '' && (strlen($pin) < 4 || strlen($pin) > 12)) {
            return Response::json(['error' => 'PIN must be 4–12 digits.'], 422);
        }

        // Secret token: random bytes, base32 for URL/QR friendliness; only the
        // sha256 hash is persisted.
        $token = $this->base32Encode(random_bytes(24));
        $hash  = hash('sha256', $token);
        $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;

        $id = $this->db->insert(
            'INSERT INTO event_scanner_links
                (event_id, label, token_hash, pin_hash, created_by_user_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, ($label !== '' ? $label : null), $hash, $pinHash, $this->userId(), $expires]
        );

        log_activity($this->db, $eventId, $this->userId(), 'scanner link created', [
            'scanner_link_id' => $id,
            'label'           => $label !== '' ? $label : null,
            'has_pin'         => $pin !== '',
        ]);

        return $this->ok([
            'scanner_link' => [
                'id'         => $id,
                'label'      => $label !== '' ? $label : null,
                'has_pin'    => $pin !== '',
                'expires_at' => $expires,
            ],
            // Returned ONCE — the page URL door staff open on their device.
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
        $scannerToken = trim((string) $request->body('scanner_token', ''));
        $pin          = trim((string) $request->body('pin', ''));
        $ticketToken  = trim((string) $request->body('ticket_token', ''));

        if ($scannerToken === '') {
            return Response::json(['error' => 'Scanner token required.'], 401);
        }

        $link = $this->db->one(
            'SELECT id, event_id, pin_hash, expires_at, revoked_at
               FROM event_scanner_links WHERE token_hash = ?',
            [hash('sha256', $scannerToken)]
        );

        // Invalid / revoked / expired scanner link -> 401, no audit row (we have
        // no trustworthy event scope to attribute the attempt to).
        if ($link === null || $link['revoked_at'] !== null) {
            return Response::json(['error' => 'Invalid or revoked scanner link.'], 401);
        }
        if ($link['expires_at'] !== null && strtotime((string) $link['expires_at']) <= time()) {
            return Response::json(['error' => 'This scanner link has expired.'], 401);
        }
        if ($link['pin_hash'] !== null && !password_verify($pin, (string) $link['pin_hash'])) {
            return Response::json(['error' => 'Incorrect PIN.'], 401);
        }

        $eventId   = (int) $link['event_id'];
        $scannerId = (int) $link['id'];

        // Touch last_used_at on any authenticated scan attempt.
        $this->db->run(
            'UPDATE event_scanner_links SET last_used_at = NOW() WHERE id = ?',
            [$scannerId]
        );

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

        return $this->ok([
            'result'      => $result,
            'admitted'    => $result === 'admitted',
            'holder_name' => $holder,
            'tier'        => $tier,
            'ticket_code' => $code,
            'event_id'    => $eventId,
        ]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────────

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

    private function intParam(string $key): ?int
    {
        $value = $this->params[$key] ?? null;
        return ctype_digit((string) $value) ? (int) $value : null;
    }

    /** RFC 4648 base32 (uppercase, no padding) of arbitrary bytes. */
    private function base32Encode(string $bytes): string
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
