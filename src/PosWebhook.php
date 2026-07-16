<?php
declare(strict_types=1);

namespace Panic;

/**
 * Square POS webhook handler.
 *
 * Receives payment.completed / payment.updated events from a Square POS
 * (bar or merch terminal), matches the sale to an in-progress event by venue
 * and date, then writes a bar_sales / merch_share ledger entry.
 *
 *   POST /api/webhooks/square-pos
 *
 * This is intentionally separate from Webhooks.php, which handles Square
 * *online checkout* (ticket purchases via Payment Links). POS uses different
 * event types, a different webhook subscription, and a different signing key.
 *
 * Square signature verification (identical algorithm to SquareProvider):
 *   HMAC-SHA256(webhookUrl . rawBody, secret) → base64 → compare with
 *   x-square-hmacsha256-signature header using constant-time hash_equals().
 *
 * Idempotency: the Square payment ID is stored in source_ref_str; a second
 * delivery of the same payment is detected and silently dropped.
 *
 * Always returns HTTP 200 once the signature is valid (even for event types
 * we do not act on) so Square stops retrying the delivery.
 */
final class PosWebhook extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        // Raw body must be read from php://input so the HMAC covers the exact
        // bytes Square signed. This mirrors SquareProvider::verifyWebhook(),
        // which also reads php://input directly (the Request body is already
        // decoded JSON and cannot be re-serialised to the identical byte string).
        $rawBody   = (string) (file_get_contents('php://input') ?: '');
        $signature = (string) ($request->header('x-square-hmacsha256-signature') ?? '');

        // Prefer the POS-specific signing key; fall back to the ticketing key
        // so a venue that shares one key for both subscriptions still works.
        $secret = (string) (getenv('SQUARE_POS_WEBHOOK_SECRET')
            ?: getenv('SQUARE_WEBHOOK_SIGNATURE_KEY')
            ?: '');

        if ($secret === '' || !$this->verifySquareSignature($rawBody, $signature, $secret)) {
            error_log('PosWebhook: invalid or unconfigured HMAC signature — ignoring delivery');
            return Response::json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        $type = (string) ($event['type'] ?? '');

        // payment.updated is the canonical "sale completed" event from Square POS.
        // payment.completed appears in some Square documentation variants.
        if (in_array($type, ['payment.updated', 'payment.completed'], true)) {
            $this->handlePayment($event);
        }
        // All other event types (e.g. order.fulfillment.updated, refund.*)
        // are acknowledged and silently ignored — Square stops retrying.

        return $this->ok(['received' => true]);
    }

    // ── Core logic ────────────────────────────────────────────────────────────

    private function handlePayment(array $event): void
    {
        $payment     = $event['data']['object']['payment'] ?? [];
        if (!is_array($payment)) {
            return;
        }

        $paymentId   = (string) ($payment['id'] ?? '');
        $locationId  = (string) ($payment['location_id'] ?? '');
        $status      = (string) ($payment['status'] ?? '');
        $totalMoney  = $payment['total_money'] ?? [];
        $amountCents = (int) ($totalMoney['amount'] ?? 0);
        $currency    = strtoupper((string) ($totalMoney['currency'] ?? 'USD'));

        if ($status !== 'COMPLETED' || $amountCents <= 0 || $paymentId === '' || $locationId === '') {
            return;
        }

        $amount = $amountCents / 100.0;

        // ── Resolve venue + default category from the POS location ────────────
        $mapping = $this->db->one(
            'SELECT venue_id, default_category FROM pos_location_map
              WHERE location_id = ? AND pos_provider = ? AND is_active = 1
              LIMIT 1',
            [$locationId, 'square']
        );
        if ($mapping === null) {
            error_log("PosWebhook: no active pos_location_map row for location_id={$locationId}");
            return;
        }

        $venueId         = (int) $mapping['venue_id'];
        $category        = (string) $mapping['default_category'];
        $activeEventId   = isset($mapping['active_event_id']) ? (int) $mapping['active_event_id'] : null;

        // ── Idempotency: skip if this payment is already in the ledger ────────
        $existing = $this->db->one(
            "SELECT id FROM event_ledger_entries
              WHERE source = 'pos_import' AND source_ref_str = ?
              LIMIT 1",
            [$paymentId]
        );
        if ($existing !== null) {
            // Already processed — Square is retrying a delivery we already handled.
            return;
        }

        // ── Resolve event: explicit override wins, date-match is last resort ────
        $matchedEvent = $this->matchEvent($venueId, $activeEventId);
        if ($matchedEvent === null) {
            error_log(
                "PosWebhook: no active event for venue_id={$venueId} on " . date('Y-m-d')
                . " (payment_id={$paymentId})"
                . ($activeEventId ? ", active_event_id={$activeEventId} not found or wrong status" : '')
            );
            return;
        }

        $eventId = (int) $matchedEvent['id'];

        // ── Create ledger entry ───────────────────────────────────────────────
        $entryId = $this->db->insert(
            "INSERT INTO event_ledger_entries
             (event_id, category, line_type, amount, currency, description,
              source, source_ref_str, created_by_id)
             VALUES (?, ?, 'revenue', ?, ?, ?, 'pos_import', ?, NULL)",
            [
                $eventId,
                $category,
                $amount,
                $currency,
                'Square POS sale (auto-imported)',
                $paymentId,
            ]
        );

        error_log(sprintf(
            'PosWebhook: created ledger entry id=%d for event_id=%d, venue_id=%d, amount=%.2f %s, category=%s, payment_id=%s',
            $entryId, $eventId, $venueId, $amount, $currency, $category, $paymentId
        ));
    }

    // ── Event matching ────────────────────────────────────────────────────────

    /**
     * Resolve which event POS payments should be posted to.
     *
     * Priority:
     *  1. active_event_id set on the pos_location_map row — explicit staff override,
     *     set via the "Set as POS Event" button in the event workspace. This is the
     *     reliable path: staff click once when doors open, all sales go to that event.
     *  2. Date-match fallback — finds an event at the venue today by status. Used only
     *     when no override is set (e.g. first-time setup or override was cleared).
     *     Unreliable when multiple events run on the same day.
     */
    private function matchEvent(int $venueId, ?int $activeEventId): ?array
    {
        // ── 1. Explicit override ──────────────────────────────────────────────
        if ($activeEventId !== null && $activeEventId > 0) {
            return $this->db->one(
                "SELECT id, title FROM events
                  WHERE id = ?
                    AND venue_id = ?
                    AND status IN ('booked', 'advanced', 'published', 'completed')
                  LIMIT 1",
                [$activeEventId, $venueId]
            );
        }

        // ── 2. Date-match fallback ────────────────────────────────────────────
        // Pre-existing bug fixed here: this previously referenced a column
        // (`event_date`) that doesn't exist on `events` (the date column is
        // just `date`) — the fallback query errored every time it ran, so it
        // never actually matched anything unless the explicit override (#1
        // above) was set. Also now multi-day-aware, matching the same
        // COALESCE(end_date, date) pattern used elsewhere for "is today
        // within this event's date range."
        return $this->db->one(
            "SELECT id, title FROM events
              WHERE venue_id = ?
                AND date <= CURDATE() AND COALESCE(end_date, date) >= CURDATE()
                AND status IN ('booked', 'advanced', 'published', 'completed')
              ORDER BY show_time DESC
              LIMIT 1",
            [$venueId]
        );
    }

    // ── Signature verification ────────────────────────────────────────────────

    /**
     * Verify a Square webhook HMAC-SHA256 signature.
     *
     * Algorithm: base64( hmac_sha256( webhookUrl . rawBody , secret ) )
     * This is identical to the logic in Payments/SquareProvider::verifyWebhook().
     * Constant-time comparison via hash_equals() prevents timing attacks.
     *
     * The webhook URL used as the signing input must exactly match the URL
     * configured in the Square webhook subscription. Set SQUARE_POS_WEBHOOK_URL
     * in .env to the canonical URL; falls back to the live request URL.
     */
    private function verifySquareSignature(string $body, string $signature, string $secret): bool
    {
        if ($signature === '') {
            return false;
        }

        $webhookUrl = (string) (getenv('SQUARE_POS_WEBHOOK_URL') ?: $this->requestUrl());
        $expected   = base64_encode(hash_hmac('sha256', $webhookUrl . $body, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Best-effort reconstruction of the public request URL from server globals.
     * Used as a fallback when SQUARE_POS_WEBHOOK_URL is not set in .env.
     * Mirrors the same helper in SquareProvider.
     */
    private function requestUrl(): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return $scheme . '://' . $host . $uri;
    }
}
