<?php
declare(strict_types=1);

namespace Panic;

use Panic\Events\Payments as EventPayments;
use Panic\Payments\FinancialReconciler;
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
        // Match order matters. Both direct lookups compare against an id the
        // PROVIDER issued and we stored verbatim, so they are exact and can
        // never match the wrong row. resolveInternalOrderId() is a heuristic
        // — it reads back an id WE chose (Square's order reference_id) — so it
        // must run last, after every exact possibility is exhausted.
        //
        // Getting this order wrong caused a real incident on 2026-08-09: a
        // client's event payment (event_payments #8) resolved through the
        // fallback to the unrelated ticket_orders #8, which was then
        // "self-healed", cancelled and fulfilled — issuing tickets — while her
        // actual payment was never recorded. See
        // scripts/repair-square-ref-collision.php.
        $order = $this->matchOrderByRef($provider, $providerRef);

        if ($order === null) {
            $payment = $this->matchEventPayment($provider, $providerRef);
            if ($payment !== null) {
                $this->fulfillEventPayment($provider, $payment, $paymentRef);
                return;
            }
            $order = $this->matchOrderByReferenceId($provider, $providerRef);
        }

        if ($order === null) {
            error_log("Webhook {$provider->key()}: no order or payment for provider_ref '{$providerRef}'.");
            return;
        }
        $orderId = (int) $order['id'];

        // Record the payment/charge id (used for refunds) before fulfillment.
        if ($paymentRef !== '') {
            $this->db->run(
                'UPDATE ticket_orders SET provider_payment_ref = ? WHERE id = ?',
                [$paymentRef, $orderId]
            );
            $order['provider_payment_ref'] = $paymentRef;
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
        if ($deliverable !== []) {
            $ticketing->emailTickets($this->db, $this->root, $orderId, $deliverable);
        }

        // Do not put provider reporting ahead of ticket issuance/delivery.
        // A provider may publish fees a moment later, so retries fill them in.
        (new FinancialReconciler($this->db))->reconcileTicketOrder($provider, $order);
    }

    /** Release the inventory hold for a payment that failed/expired. */
    private function handleFailure(PaymentProvider $provider, string $providerRef): void
    {
        // Same ordering rule as handleSuccess, and for the same reason: a
        // failed/cancelled EVENT PAYMENT must never be resolved onto a ticket
        // order through the reference_id heuristic and cancel it. That is
        // exactly how ticket_orders #8 got cancelled on 2026-08-09. Event
        // payments hold no inventory, so there is nothing to release for them
        // — recognizing the ref as one is enough to stop here.
        $order = $this->matchOrderByRef($provider, $providerRef);
        if ($order === null) {
            if ($this->matchEventPayment($provider, $providerRef) !== null) {
                return;
            }
            $order = $this->matchOrderByReferenceId($provider, $providerRef);
        }
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

    /** Exact match on the reference the provider itself issued. Never ambiguous. */
    private function matchOrderByRef(PaymentProvider $provider, string $providerRef): ?array
    {
        if ($providerRef === '') {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM ticket_orders WHERE provider = ? AND provider_ref = ? LIMIT 1',
            [$provider->key(), $providerRef]
        );
    }

    /**
     * Heuristic recovery for legacy Square ticket orders that stored the
     * payment_link id as provider_ref: ask the provider to read our own id
     * back out of the order's reference_id.
     *
     * MUST be called only after every exact match has missed — the value it
     * reads is one WE wrote, and historically both ticket orders and event
     * payments wrote a bare integer there from two different tables, so a
     * number alone does not identify which table it came from. New event
     * payment links are namespaced ("payment:8"), which resolveInternalOrderId
     * rejects outright, but links minted before that change are still live.
     */
    private function matchOrderByReferenceId(PaymentProvider $provider, string $providerRef): ?array
    {
        if ($providerRef === '') {
            return null;
        }
        $internalId = $provider->resolveInternalOrderId($providerRef);
        if ($internalId === null || $internalId <= 0) {
            return null;
        }

        // A bare legacy reference can name rows in both tables. If an active
        // event payment occupies this id, the value is ambiguous and must not
        // be interpreted as a ticket order. New event-payment checkouts use a
        // namespaced reference and never reach this fallback.
        $paymentCollision = $this->db->one(
            "SELECT id FROM event_payments
             WHERE id = ? AND checkout_provider = ? AND status != 'voided' LIMIT 1",
            [$internalId, $provider->key()]
        );
        if ($paymentCollision !== null) {
            error_log(sprintf(
                'Webhook %s: bare reference_id %d is ambiguous with event_payments; refusing ticket-order fallback.',
                $provider->key(),
                $internalId
            ));
            return null;
        }

        $order = $this->db->one(
            'SELECT * FROM ticket_orders WHERE id = ? AND provider = ? LIMIT 1',
            [$internalId, $provider->key()]
        );
        if ($order === null) {
            return null;
        }

        // Safe after the cross-table ambiguity check: align legacy orders
        // that stored Square's payment-link id with the webhook's order id.
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
        $newlyReceived = false;

        if (($payment['status'] ?? '') !== 'received') {
            $changed = $this->db->run(
                "UPDATE event_payments
                 SET status = 'received', received_at = NOW(), checkout_payment_ref = ?, method = ?
                 WHERE id = ? AND status != 'received'",
                [$paymentRef !== '' ? $paymentRef : null, $provider->key(), $paymentId]
            );
            $newlyReceived = $changed === 1;
        }

        if ($paymentRef !== '') {
            // Repair an older/partial webhook delivery that marked the row
            // received before its charge id was persisted. Never replace an
            // existing id; that is the refund/reconciliation authority.
            $this->db->run(
                "UPDATE event_payments SET checkout_payment_ref = ?
                  WHERE id = ? AND (checkout_payment_ref IS NULL OR checkout_payment_ref = '')",
                [$paymentRef, $paymentId]
            );
            $payment['checkout_payment_ref'] = $paymentRef;
        }
        if ($newlyReceived) {
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

        // Receipt delivery is deliberately retryable independently of payment
        // fulfillment. If the MTA was down on the first webhook, a provider
        // retry sees status=received but receipt_emailed_at=NULL and tries again.
        $receipt = new EventPaymentReceiptService($this->db, $this->root);
        try {
            $token = $receipt->ensureToken($paymentId);
            $data = $receipt->load($paymentId, $token);
            if ($data !== null && empty($data['receipt_emailed_at']) && $receipt->email($data)) {
                $this->db->run(
                    'UPDATE event_payments SET receipt_emailed_at = NOW() WHERE id = ? AND receipt_emailed_at IS NULL',
                    [$paymentId]
                );
                $this->db->run(
                    'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, note)
                     VALUES (?, ?, NULL, ?, ?)',
                    [$paymentId, $eventId, 'receipt_emailed', 'Payment receipt emailed to client']
                );
            }
        } catch (\Throwable $e) {
            error_log("Webhook {$provider->key()}: receipt delivery failed for payment {$paymentId}: " . $e->getMessage());
        }

        // Payment state and receipt delivery take priority over the secondary
        // provider lookup; webhook retries safely update the same ledger rows.
        (new FinancialReconciler($this->db))->reconcileEventPayment($provider, $payment);
    }
}
