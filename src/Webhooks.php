<?php
declare(strict_types=1);

namespace Panic;

use Panic\Events\Payments as EventPayments;
use Panic\Payments\PaymentProvider;
use Panic\Payments\PaymentProviders;

/**
 * Public payment webhook receiver.
 *
 *   POST /api/webhooks/stripe
 *   POST /api/webhooks/square
 *
 * Flow:
 *   1. Resolve the provider by the URL segment via PaymentProviders::byKey().
 *   2. provider->verifyWebhook() validates the signature against the raw body
 *      and normalizes the event. A null return means an invalid/unverifiable
 *      signature -> 400 (so the provider retries / flags it), and we never act.
 *   3. On 'payment_succeeded', try to match a ticket_orders row first (by
 *      provider + provider_ref) — if found, capture provider_payment_ref (for
 *      later refunds) and call TicketingService::fulfillOrder(), which is
 *      idempotent, so provider retries never double-issue. Newly-issued
 *      tickets (with their one-time plaintext tokens) are emailed to the
 *      buyer with QR links.
 *      Otherwise, try an event_payments row (deposit/balance-payment checkout
 *      links minted by Events\Payments::mintOrReuseCheckoutLink()) matched
 *      the same way by (checkout_provider, external_ref) — if found, mark it
 *      'received' (idempotent — a retry on an already-received row is a
 *      no-op) and re-sync the event's deposit_status.
 *   4. On 'payment_failed', cancel a still-pending ticket order so its
 *      inventory hold is released. Deposit/payment links have no inventory
 *      hold to release, so a failed/expired checkout is a no-op there — the
 *      next "send link" click simply mints a fresh one once the cached one's
 *      bookkeeping expiry (see mintOrReuseCheckoutLink()) has passed.
 *
 * Always returns 200 once the signature is valid (even for events we ignore)
 * so providers stop retrying a delivered, understood webhook.
 */
final class Webhooks extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        $providerKey = strtolower((string) ($this->params['provider'] ?? ''));
        $env = new Env();
        $provider = PaymentProviders::byKey($providerKey, $env);
        if ($provider === null) {
            return Response::json(['error' => 'Unknown provider'], 404);
        }

        $event = $provider->verifyWebhook($request);
        if ($event === null) {
            // Bad/absent signature — do not act. 400 prompts a retry.
            return Response::json(['error' => 'Invalid signature'], 400);
        }

        $type        = (string) ($event['type'] ?? 'other');
        $providerRef = (string) ($event['provider_ref'] ?? '');
        $paymentRef  = (string) ($event['provider_payment_ref'] ?? '');

        if ($type === 'payment_succeeded') {
            $this->handleSuccess($provider, $providerRef, $paymentRef);
        } elseif ($type === 'payment_failed') {
            $this->handleFailure($provider, $providerRef);
        }

        // Signature was valid: acknowledge so the provider stops retrying.
        return $this->ok(['received' => true]);
    }

    /** Fulfill the matched order (idempotent) and email any freshly-issued tickets. */
    private function handleSuccess(PaymentProvider $provider, string $providerRef, string $paymentRef): void
    {
        $order = $this->matchOrder($provider, $providerRef);
        if ($order === null) {
            $payment = $this->matchEventPayment($provider, $providerRef);
            if ($payment === null) {
                error_log("Webhook {$provider->key()}: no order or payment for provider_ref '{$providerRef}'.");
                return;
            }
            $this->fulfillEventPayment($provider, $payment, $paymentRef);
            return;
        }
        $orderId = (int) $order['id'];

        // Record the payment/charge id (used for refunds) before fulfillment.
        if ($paymentRef !== '') {
            $this->db->run(
                'UPDATE ticket_orders SET provider_payment_ref = ? WHERE id = ?',
                [$paymentRef, $orderId]
            );
        }

        $ticketing = new TicketingService();
        try {
            $tickets = $ticketing->fulfillOrder($this->db, $orderId);
        } catch (\Throwable $e) {
            error_log("Webhook {$provider->key()}: fulfillment failed for order {$orderId}: " . $e->getMessage());
            return;
        }

        // Only the FIRST fulfillment returns plaintext tokens; retries return
        // tickets with token=null. Email only when we have a token to send.
        $deliverable = array_values(array_filter(
            $tickets,
            static fn(array $t): bool => !empty($t['token'])
        ));
        if ($deliverable === []) {
            return;
        }

        $ticketing->emailTickets($this->db, $this->root, $orderId, $deliverable);
    }

    /** Release the inventory hold for a payment that failed/expired. */
    private function handleFailure(PaymentProvider $provider, string $providerRef): void
    {
        $order = $this->matchOrder($provider, $providerRef);
        if ($order === null) {
            return;
        }
        // Only cancel while still pending — never disturb a fulfilled order.
        $this->db->run(
            "UPDATE ticket_orders
                SET status = 'canceled', hold_expires_at = NULL
              WHERE id = ? AND status = 'pending'",
            [(int) $order['id']]
        );
    }

    /**
     * Match an order by the provider that created it + its checkout reference.
     *
     * Primary path: a direct (provider, provider_ref) lookup — this is what
     * succeeds for every order created after provider_ref was aligned with the
     * value the webhook echoes (Stripe: session id; Square: order id).
     *
     * Fallback path: if the direct lookup misses, ask the provider to resolve
     * the webhook ref to our internal order id (Square reads it back from the
     * order's reference_id). This recovers legacy Square orders that stored the
     * payment_link id as provider_ref. On a fallback hit we backfill
     * provider_ref to the webhook ref so retries take the fast path and the row
     * is self-consistent going forward.
     */
    private function matchOrder(PaymentProvider $provider, string $providerRef): ?array
    {
        if ($providerRef === '') {
            return null;
        }
        $providerKey = $provider->key();

        $order = $this->db->one(
            'SELECT * FROM ticket_orders WHERE provider = ? AND provider_ref = ? LIMIT 1',
            [$providerKey, $providerRef]
        );
        if ($order !== null) {
            return $order;
        }

        $internalId = $provider->resolveInternalOrderId($providerRef);
        if ($internalId === null || $internalId <= 0) {
            return null;
        }
        $order = $this->db->one(
            'SELECT * FROM ticket_orders WHERE id = ? AND provider = ? LIMIT 1',
            [$internalId, $providerKey]
        );
        if ($order === null) {
            return null;
        }

        // Self-heal: align provider_ref with the value the webhook carries so
        // subsequent retries match directly without another provider round-trip.
        $this->db->run(
            'UPDATE ticket_orders SET provider_ref = ? WHERE id = ?',
            [$providerRef, (int) $order['id']]
        );
        $order['provider_ref'] = $providerRef;
        return $order;
    }

    /**
     * Match a deposit/payment checkout link by (checkout_provider, external_ref)
     * — set by Events\Payments::mintOrReuseCheckoutLink() at link-creation time,
     * exactly the same convention as ticket_orders.(provider, provider_ref)
     * above. No fallback path is needed here (unlike Square ticket orders):
     * this column pair only ever existed after both were written together, so
     * there's no legacy data whose provider_ref was recorded differently.
     */
    private function matchEventPayment(PaymentProvider $provider, string $providerRef): ?array
    {
        if ($providerRef === '') {
            return null;
        }
        return $this->db->one(
            "SELECT * FROM event_payments WHERE checkout_provider = ? AND external_ref = ? AND status != 'voided' LIMIT 1",
            [$provider->key(), $providerRef]
        );
    }

    /**
     * Mark a matched deposit/payment checkout as received (idempotent — a
     * retry on an already-'received' row is a no-op) and re-sync the parent
     * event's deposit_status. Records provider_payment_ref for any future
     * refund flow, and an audit trail row exactly like a manual "mark
     * received" edit would.
     */
    private function fulfillEventPayment(PaymentProvider $provider, array $payment, string $paymentRef): void
    {
        $paymentId = (int) $payment['id'];
        $eventId   = (int) $payment['event_id'];

        if ($payment['status'] === 'received') {
            return; // Already processed by an earlier delivery of this webhook.
        }

        $this->db->run(
            "UPDATE event_payments
             SET status = 'received', received_at = NOW(), checkout_payment_ref = ?
             WHERE id = ?",
            [$paymentRef !== '' ? $paymentRef : null, $paymentId]
        );
        $this->db->run(
            'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, note)
             VALUES (?, ?, NULL, ?, ?)',
            [$paymentId, $eventId, 'checkout_paid', ucfirst($provider->key()) . ' checkout completed (webhook)']
        );

        if (($payment['payment_type'] ?? '') === 'deposit') {
            EventPayments::syncDepositStatus($this->db, $eventId);
        }

        log_activity($this->db, $eventId, null, ucfirst($provider->key()) . ' payment received (webhook)', [
            'payment_id' => $paymentId,
            'provider'   => $provider->key(),
            'amount'     => $payment['amount'],
        ]);
    }
}
