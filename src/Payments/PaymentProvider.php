<?php
declare(strict_types=1);

namespace Panic\Payments;

use Panic\Request;

/**
 * Pluggable hosted-checkout payment provider.
 *
 * Implementations are intentionally thin and dependency-free: raw cURL for the
 * provider HTTP API and native PHP crypto (hash_hmac) for webhook signature
 * verification. No vendor SDKs. Secrets are read from Panic\Env at construction.
 *
 * All concrete providers (Stripe, Square) MUST conform to these exact
 * signatures — the checkout endpoint, webhook receiver, and refund flow are
 * written against this interface and select the implementation at runtime via
 * PaymentProviders.
 */
interface PaymentProvider
{
    /** Stable key persisted on ticket_orders.provider ('stripe' | 'square'). */
    public function key(): string;

    /**
     * Create a hosted checkout session for an order.
     *
     * @param array $order ticket_orders row (id, amount_cents, currency,
     *                     buyer_email, buyer_name, ...).
     * @param array $items list of line items, each:
     *                     ['ticket_type_id'=>int,'name'=>string,
     *                      'quantity'=>int,'unit_price_cents'=>int].
     * @param string $successUrl redirect after successful payment.
     * @param string $cancelUrl  redirect if the buyer abandons checkout.
     *
     * @return array{checkout_url:string,provider_ref:string}
     *
     * @throws \RuntimeException if the provider rejects the request or is
     *         misconfigured (missing keys, HTTP error, malformed response).
     */
    public function createCheckout(array $order, array $items, string $successUrl, string $cancelUrl): array;

    /**
     * Verify and normalize an incoming provider webhook.
     *
     * Validates the request signature against the configured webhook secret.
     * Returns null when the signature is missing or invalid (caller should
     * respond 400 and NOT trust the payload). On success returns a normalized
     * event:
     *   [
     *     'type'                 => 'payment_succeeded'|'payment_failed'|'other',
     *     'provider_ref'         => string,  // checkout session id (matches order)
     *     'provider_payment_ref' => string,  // payment/charge id (used for refunds)
     *     'amount_cents'         => int,
     *   ]
     *
     * @return array{type:string,provider_ref:string,provider_payment_ref:string,amount_cents:int}|null
     */
    public function verifyWebhook(Request $request): ?array;

    /**
     * Refund a captured payment (full or partial).
     *
     * @param string $providerPaymentRef ticket_orders.provider_payment_ref.
     * @param int    $amountCents         amount to refund.
     *
     * @return array{ok:bool,refund_ref:?string,error:?string}
     */
    public function refund(string $providerPaymentRef, int $amountCents): array;

    /**
     * Fallback order resolution for webhooks.
     *
     * Called only when the webhook receiver's direct (provider, provider_ref)
     * lookup fails. Given the provider_ref carried by a verified webhook,
     * resolve our internal ticket_orders.id when the provider can — e.g. Square
     * stores the internal id as the order's reference_id, so it can fetch the
     * order and read it back. Returns null when unsupported or unresolvable.
     *
     * Implementations MUST NOT throw; on any error they return null so the
     * receiver simply logs the unmatched webhook.
     */
    public function resolveInternalOrderId(string $providerRef): ?int;
}
