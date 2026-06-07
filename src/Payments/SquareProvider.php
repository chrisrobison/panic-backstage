<?php
declare(strict_types=1);

namespace Panic\Payments;

use Panic\Env;
use Panic\Request;
use RuntimeException;

/**
 * Square hosted checkout (Payment Links) provider — zero-dependency.
 *
 * Uses the Square REST API directly over cURL (no Square SDK):
 *   - createCheckout(): POST /v2/online-checkout/payment-links with a
 *     quick_pay order. Returns the hosted `url` and the payment link `id`
 *     (stored as the order's provider_ref). Square does not return a payment
 *     id until the buyer pays, so provider_payment_ref is filled from the
 *     webhook (payment.id) on success.
 *   - verifyWebhook(): validates the `x-square-hmacsha256-signature` header.
 *     Square signs HMAC-SHA256 over (notification_url + raw_body), base64'd,
 *     keyed by SQUARE_WEBHOOK_SIGNATURE_KEY. Constant-time compared.
 *   - refund(): POST /v2/refunds with the payment id + idempotency key.
 *
 * Secrets / config (Panic\Env):
 *   SQUARE_ACCESS_TOKEN           — Bearer token (sandbox or production)
 *   SQUARE_LOCATION_ID            — location the order belongs to
 *   SQUARE_WEBHOOK_SIGNATURE_KEY  — per-subscription webhook signing key
 *   SQUARE_ENV                    — 'sandbox' | 'production' (base URL switch)
 *   SQUARE_WEBHOOK_URL            — exact notification URL Square posts to
 *                                  (required for signature verification; falls
 *                                  back to the live request URL if unset)
 */
final class SquareProvider implements PaymentProvider
{
    private const API_VERSION   = '2024-06-04';
    private const BASE_SANDBOX  = 'https://connect.squareupsandbox.com';
    private const BASE_PROD     = 'https://connect.squareup.com';

    private string $accessToken;
    private string $locationId;
    private string $webhookSignatureKey;
    private string $webhookUrl;
    private string $apiBase;

    public function __construct(Env $env)
    {
        $this->accessToken         = (string) $env->get('SQUARE_ACCESS_TOKEN', '');
        $this->locationId          = (string) $env->get('SQUARE_LOCATION_ID', '');
        $this->webhookSignatureKey = (string) $env->get('SQUARE_WEBHOOK_SIGNATURE_KEY', '');
        $this->webhookUrl          = (string) $env->get('SQUARE_WEBHOOK_URL', '');

        $environment   = strtolower((string) $env->get('SQUARE_ENV', 'sandbox'));
        $this->apiBase = $environment === 'production' ? self::BASE_PROD : self::BASE_SANDBOX;
    }

    public function key(): string
    {
        return 'square';
    }

    public function createCheckout(array $order, array $items, string $successUrl, string $cancelUrl): array
    {
        if ($this->accessToken === '' || $this->locationId === '') {
            throw new RuntimeException('Square is not configured (missing SQUARE_ACCESS_TOKEN / SQUARE_LOCATION_ID).');
        }

        $currency = strtoupper((string) ($order['currency'] ?? 'USD'));

        $lineItems = [];
        foreach ($items as $item) {
            $qty  = max(1, (int) ($item['quantity'] ?? 1));
            $unit = max(0, (int) ($item['unit_price_cents'] ?? 0));
            $lineItems[] = [
                'name'             => (string) ($item['name'] ?? 'Ticket'),
                'quantity'         => (string) $qty,
                'base_price_money' => ['amount' => $unit, 'currency' => $currency],
            ];
        }

        if ($lineItems === []) {
            throw new RuntimeException('Cannot create a Square checkout with no line items.');
        }

        $payload = [
            'idempotency_key' => $this->idempotencyKey('link-' . (string) ($order['id'] ?? '') . '-'),
            'order'           => [
                'location_id'   => $this->locationId,
                'reference_id'  => (string) ($order['id'] ?? ''),
                'line_items'    => $lineItems,
            ],
            'checkout_options' => [
                'redirect_url' => $successUrl,
            ],
        ];

        $email = (string) ($order['buyer_email'] ?? '');
        if ($email !== '') {
            $payload['pre_populated_data'] = ['buyer_email' => $email];
        }

        [$code, $body] = $this->http('POST', '/v2/online-checkout/payment-links', $payload);
        $json = json_decode($body, true);

        $link = is_array($json) ? ($json['payment_link'] ?? null) : null;
        if ($code < 200 || $code >= 300 || !is_array($link) || empty($link['url']) || empty($link['id'])) {
            throw new RuntimeException('Square checkout creation failed: ' . $this->errorMessage($json, $code));
        }

        return [
            'checkout_url' => (string) $link['url'],
            'provider_ref' => (string) $link['id'],
        ];
    }

    public function verifyWebhook(Request $request): ?array
    {
        if ($this->webhookSignatureKey === '') {
            return null;
        }

        $signature = $request->header('x-square-hmacsha256-signature');
        if ($signature === null || $signature === '') {
            return null;
        }

        $payload = (string) file_get_contents('php://input');
        if ($payload === '') {
            return null;
        }

        // Square signs HMAC-SHA256 over the concatenation of the exact
        // notification URL it was configured with and the raw request body.
        $url = $this->webhookUrl !== '' ? $this->webhookUrl : $this->requestUrl();
        $expected = base64_encode(hash_hmac('sha256', $url . $payload, $this->webhookSignatureKey, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return null;
        }

        $eventType = (string) ($event['type'] ?? '');
        $payment   = $event['data']['object']['payment'] ?? [];
        if (!is_array($payment)) {
            $payment = [];
        }

        $paymentId  = (string) ($payment['id'] ?? '');
        $orderRef   = (string) ($payment['order_id'] ?? '');
        $amount     = (int) ($payment['amount_money']['amount'] ?? 0);
        $status     = (string) ($payment['status'] ?? '');

        // payment_link id is the order's provider_ref. Square's payment object
        // does not echo it, so the webhook receiver matches on order_id /
        // reference_id; we surface order_id as provider_ref here, and the
        // receiver falls back to its own lookup when needed.
        $providerRef = $orderRef;

        if ($eventType === 'payment.updated' || $eventType === 'payment.created') {
            if ($status === 'COMPLETED' || $status === 'APPROVED' || $status === 'CAPTURED') {
                return [
                    'type'                 => 'payment_succeeded',
                    'provider_ref'         => $providerRef,
                    'provider_payment_ref' => $paymentId,
                    'amount_cents'         => $amount,
                ];
            }
            if ($status === 'FAILED' || $status === 'CANCELED') {
                return [
                    'type'                 => 'payment_failed',
                    'provider_ref'         => $providerRef,
                    'provider_payment_ref' => $paymentId,
                    'amount_cents'         => $amount,
                ];
            }
        }

        return [
            'type'                 => 'other',
            'provider_ref'         => $providerRef,
            'provider_payment_ref' => $paymentId,
            'amount_cents'         => $amount,
        ];
    }

    public function refund(string $providerPaymentRef, int $amountCents): array
    {
        if ($this->accessToken === '') {
            return ['ok' => false, 'refund_ref' => null, 'error' => 'Square is not configured.'];
        }
        if ($providerPaymentRef === '' || $amountCents <= 0) {
            return ['ok' => false, 'refund_ref' => null, 'error' => 'Missing payment reference or amount.'];
        }

        $payload = [
            'idempotency_key' => $this->idempotencyKey('refund-' . $providerPaymentRef . '-'),
            'payment_id'      => $providerPaymentRef,
            'amount_money'    => ['amount' => $amountCents, 'currency' => 'USD'],
        ];

        [$code, $body] = $this->http('POST', '/v2/refunds', $payload);
        $json = json_decode($body, true);

        $refund = is_array($json) ? ($json['refund'] ?? null) : null;
        if ($code >= 200 && $code < 300 && is_array($refund) && !empty($refund['id'])) {
            return ['ok' => true, 'refund_ref' => (string) $refund['id'], 'error' => null];
        }

        return ['ok' => false, 'refund_ref' => null, 'error' => $this->errorMessage($json, $code)];
    }

    /** Deterministic-ish, collision-resistant idempotency key (Square caps 45 chars elsewhere; refunds allow 192). */
    private function idempotencyKey(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(12));
    }

    /** Best-effort reconstruction of the public webhook URL from server globals. */
    private function requestUrl(): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return $scheme . '://' . $host . $uri;
    }

    /** @param mixed $json */
    private function errorMessage($json, int $code): string
    {
        if (is_array($json) && isset($json['errors'][0])) {
            $err = $json['errors'][0];
            $detail = (string) ($err['detail'] ?? ($err['code'] ?? ''));
            if ($detail !== '') {
                return $detail;
            }
        }
        return "HTTP $code";
    }

    /**
     * JSON Square API call with Bearer auth + Square-Version header.
     *
     * @param array<string,mixed> $payload
     * @return array{0:int,1:string} [httpCode, rawBody]
     */
    private function http(string $method, string $path, array $payload): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Square-Version: ' . self::API_VERSION,
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, json_encode(['errors' => [['detail' => "curl: $err"]]]) ?: ''];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }
}
