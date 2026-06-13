<?php
declare(strict_types=1);

namespace Panic;

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
 *   3. On 'payment_succeeded', match the order by (provider, provider_ref),
 *      capture provider_payment_ref (for later refunds), then call
 *      TicketingService::fulfillOrder() — which is idempotent, so provider
 *      retries never double-issue. Newly-issued tickets (with their one-time
 *      plaintext tokens) are emailed to the buyer with QR links.
 *   4. On 'payment_failed', cancel the still-pending order so its inventory
 *      hold is released.
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
            error_log("Webhook {$provider->key()}: no order for provider_ref '{$providerRef}'.");
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

        try {
            $tickets = (new TicketingService())->fulfillOrder($this->db, $orderId);
        } catch (\Throwable $e) {
            error_log("Webhook {$providerKey}: fulfillment failed for order {$orderId}: " . $e->getMessage());
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

        $this->emailTickets($orderId, $deliverable);
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
     * Email the buyer their tickets. Each ticket links to its public view page
     * (/t/{token}) and embeds the self-rendering QR SVG pointed at the bare
     * token so it scans reliably at the door.
     *
     * @param array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}> $tickets
     */
    private function emailTickets(int $orderId, array $tickets): void
    {
        $order = $this->db->one(
            'SELECT o.buyer_name, o.buyer_email, e.title AS event_title
               FROM ticket_orders o
               JOIN events e ON e.id = o.event_id
              WHERE o.id = ?',
            [$orderId]
        );
        if ($order === null) {
            return;
        }

        $to = (string) ($order['buyer_email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $title     = (string) ($order['event_title'] ?? 'your event');
        $buyerName = (string) ($order['buyer_name']  ?? '');
        $appUrl    = rtrim((string) (getenv('APP_URL') ?: ''), '/');

        $textLines = [];
        $htmlItems = [];
        $n = 0;
        foreach ($tickets as $ticket) {
            $token = (string) ($ticket['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $n++;
            $viewUrl  = $appUrl . '/t/' . rawurlencode($token);
            $code     = htmlspecialchars((string) $ticket['code'], ENT_QUOTES, 'UTF-8');
            $safeView = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');

            $textLines[] = 'Ticket ' . $n . '  (' . (string) $ticket['code'] . ')';
            $textLines[] = '  View / QR: ' . $viewUrl;
            $textLines[] = '';

            $htmlItems[] = '<div style="padding:12px 0;border-bottom:1px solid #2e2929;">'
                . '<div style="font-size:13px;color:#a9a097;letter-spacing:1px;text-transform:uppercase;">Ticket ' . $n . '</div>'
                . '<div style="margin-top:4px;font-size:16px;font-weight:bold;color:#fff;">' . $code . '</div>'
                . '<div style="margin-top:6px;">'
                . '<a href="' . $safeView . '" style="color:#c9b27e;font-size:14px;word-break:break-all;">'
                . $safeView . '</a></div></div>';
        }

        if ($n === 0) {
            return;
        }

        $greeting     = $buyerName !== ''
            ? 'Hi <strong style="color:#fff;">' . htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8') . '</strong>,'
            : 'Hello,';

        (new Mailer($this->root))->sendTemplate(
            $to,
            'Your tickets for ' . $title,
            'ticket-purchase',
            [
                'event_title'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'greeting'     => $greeting,
                'tickets_html' => implode('', $htmlItems),
                'tickets_text' => implode("\n", $textLines),
            ]
        );
    }
}
