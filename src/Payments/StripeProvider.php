<?php
declare(strict_types=1);

namespace Panic\Payments;

use Panic\Env;
use Panic\Request;
use RuntimeException;

/**
 * Stripe Checkout Sessions provider — zero-dependency.
 *
 * Uses the Stripe REST API directly over cURL (no stripe-php SDK):
 *   - createCheckout(): POST /v1/checkout/sessions (mode=payment), returns the
 *     hosted `url` and session `id`. The session id is stored as the order's
 *     provider_ref so the webhook can match it back. The PaymentIntent id is
 *     captured on the success webhook as provider_payment_ref for refunds.
 *   - verifyWebhook(): validates the `Stripe-Signature` header per Stripe's
 *     scheme (t=timestamp,v1=HMAC-SHA256 of "<t>.<raw body>" keyed by the
 *     endpoint's signing secret), with a constant-time compare.
 *   - refund(): POST /v1/refunds against the captured PaymentIntent.
 *
 * Secrets (Panic\Env):
 *   STRIPE_SECRET_KEY      — sk_live_… / sk_test_… (Bearer auth)
 *   STRIPE_WEBHOOK_SECRET  — whsec_… (webhook signature key)
 */
final class StripeProvider implements PaymentProvider
{
    private const API_BASE = 'https://api.stripe.com';

    /** Reject webhook timestamps older than this (replay protection), seconds. */
    private const SIGNATURE_TOLERANCE = 300;

    private string $secretKey;
    private string $webhookSecret;

    public function __construct(Env $env)
    {
        $this->secretKey     = (string) $env->get('STRIPE_SECRET_KEY', '');
        $this->webhookSecret = (string) $env->get('STRIPE_WEBHOOK_SECRET', '');
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function createCheckout(array $order, array $items, string $successUrl, string $cancelUrl): array
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Stripe is not configured (missing STRIPE_SECRET_KEY).');
        }

        $currency = strtolower((string) ($order['currency'] ?? 'usd'));

        // Stripe wants form-encoded, deeply-nested params. Build line_items[n][...].
        $form = [
            'mode'                 => 'payment',
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'client_reference_id'  => (string) ($order['id'] ?? ''),
            // Carry the internal order id through to the webhook regardless of
            // which object Stripe hands us back.
            'metadata[order_id]'   => (string) ($order['id'] ?? ''),
            'payment_intent_data[metadata][order_id]' => (string) ($order['id'] ?? ''),
        ];

        $email = (string) ($order['buyer_email'] ?? '');
        if ($email !== '') {
            $form['customer_email'] = $email;
        }

        $i = 0;
        foreach ($items as $item) {
            $qty  = max(1, (int) ($item['quantity'] ?? 1));
            $unit = max(0, (int) ($item['unit_price_cents'] ?? 0));
            $name = (string) ($item['name'] ?? 'Ticket');
            $form["line_items[$i][quantity]"] = (string) $qty;
            $form["line_items[$i][price_data][currency]"] = $currency;
            $form["line_items[$i][price_data][unit_amount]"] = (string) $unit;
            $form["line_items[$i][price_data][product_data][name]"] = $name;
            $i++;
        }

        if ($i === 0) {
            throw new RuntimeException('Cannot create a Stripe checkout with no line items.');
        }

        [$code, $body] = $this->http('POST', '/v1/checkout/sessions', $form);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['url']) || empty($json['id'])) {
            $msg = is_array($json) && isset($json['error']['message'])
                ? (string) $json['error']['message']
                : "HTTP $code";
            throw new RuntimeException('Stripe checkout creation failed: ' . $msg);
        }

        return [
            'checkout_url' => (string) $json['url'],
            'provider_ref' => (string) $json['id'],
        ];
    }

    public function verifyWebhook(Request $request): ?array
    {
        if ($this->webhookSecret === '') {
            return null;
        }

        $sigHeader = $request->header('Stripe-Signature');
        if ($sigHeader === null || $sigHeader === '') {
            return null;
        }

        // Stripe reads the EXACT raw request body for signing. Request only
        // exposes the parsed body, so read the raw input stream here.
        $payload = (string) file_get_contents('php://input');
        if ($payload === '') {
            return null;
        }

        if (!$this->verifyStripeSignature($payload, $sigHeader)) {
            return null;
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['type'])) {
            return null;
        }

        $object = $event['data']['object'] ?? [];
        if (!is_array($object)) {
            $object = [];
        }

        $type = (string) $event['type'];

        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            $paid = ($object['payment_status'] ?? '') === 'paid'
                || ($object['status'] ?? '') === 'complete';
            return [
                'type'                 => $paid ? 'payment_succeeded' : 'other',
                'provider_ref'         => (string) ($object['id'] ?? ''),
                'provider_payment_ref' => (string) ($object['payment_intent'] ?? ''),
                'amount_cents'         => (int) ($object['amount_total'] ?? 0),
            ];
        }

        if ($type === 'checkout.session.async_payment_failed' || $type === 'checkout.session.expired') {
            return [
                'type'                 => 'payment_failed',
                'provider_ref'         => (string) ($object['id'] ?? ''),
                'provider_payment_ref' => (string) ($object['payment_intent'] ?? ''),
                'amount_cents'         => (int) ($object['amount_total'] ?? 0),
            ];
        }

        return [
            'type'                 => 'other',
            'provider_ref'         => (string) ($object['id'] ?? ''),
            'provider_payment_ref' => (string) ($object['payment_intent'] ?? ''),
            'amount_cents'         => (int) ($object['amount_total'] ?? 0),
        ];
    }

    public function refund(string $providerPaymentRef, int $amountCents): array
    {
        if ($this->secretKey === '') {
            return ['ok' => false, 'refund_ref' => null, 'error' => 'Stripe is not configured.'];
        }
        if ($providerPaymentRef === '') {
            return ['ok' => false, 'refund_ref' => null, 'error' => 'Missing payment reference.'];
        }

        $form = ['payment_intent' => $providerPaymentRef];
        if ($amountCents > 0) {
            $form['amount'] = (string) $amountCents;
        }

        [$code, $body] = $this->http('POST', '/v1/refunds', $form);
        $json = json_decode($body, true);

        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['id'])) {
            return ['ok' => true, 'refund_ref' => (string) $json['id'], 'error' => null];
        }

        $msg = is_array($json) && isset($json['error']['message'])
            ? (string) $json['error']['message']
            : "HTTP $code";
        return ['ok' => false, 'refund_ref' => null, 'error' => $msg];
    }

    /**
     * Verify a Stripe-Signature header.
     *
     * Header form: "t=1492774577,v1=5257a8...,v1=...". We HMAC-SHA256 the
     * signed payload "<t>.<body>" with the webhook secret and constant-time
     * compare against each provided v1 scheme signature.
     */
    private function verifyStripeSignature(string $payload, string $header): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$k, $v] = $kv;
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || $signatures === [] || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Form-encoded Stripe API call with Bearer auth.
     *
     * @param array<string,string> $form
     * @return array{0:int,1:string} [httpCode, rawBody]
     */
    private function http(string $method, string $path, array $form): array
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Stripe-Version: 2024-06-20',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, json_encode(['error' => ['message' => "curl: $err"]]) ?: ''];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }
}
