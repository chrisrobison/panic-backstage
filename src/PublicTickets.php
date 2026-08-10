<?php
declare(strict_types=1);

namespace Panic;

use Panic\Payments\PaymentProviders;

use function Panic\event_public_path;

/**
 * Public (no-JWT) ticket purchase surface for an event's public page.
 *
 *   GET  /api/public/tickets/{eventId}
 *        -> ticket types that are currently buyable (status=on_sale and within
 *           any sales window), each with live availability from
 *           TicketingService::availableQuantity().
 *
 *   POST /api/public/tickets/{eventId}/discount
 *        body: { code, items?: [...], buyer_email? }
 *        -> price a private discount code against the current cart WITHOUT
 *           committing to anything, so the ticket page can show "EASTBAY30
 *           applied — you save $15.00" before the buyer commits. Codes are
 *           never listed by the GET above; they only exist for someone who was
 *           given one. Rate limited per IP because this is the one public
 *           surface where guessing at codes would otherwise be free.
 *
 *   POST /api/public/tickets/{eventId}/checkout
 *        body: { buyer_name, buyer_email, buyer_phone?, marketing_opt_in?,
 *                items: [ { ticket_type_id, quantity }, ... ],
 *                discount_code? }

 *        -> creates a pending ticket_orders row + ticket_order_items with a
 *           15-minute hold (reserving inventory). If the order total is > 0,
 *           starts a hosted checkout with the active payment provider,
 *           persists the provider + provider_ref on the order, and returns
 *           { checkout_url, order_id }. A valid discount_code is folded into
 *           the stored line-item unit prices (see Panic\TicketDiscounts), so
 *           the discounted amount is what the provider charges AND what every
 *           revenue report sums — the two can't drift. If the order total is
 *           exactly 0 (every selected ticket type is free, or a code took the
 *           cart to zero), skips payment entirely: fulfills
 *           the order immediately (TicketingService::fulfillOrder), emails
 *           the tickets, and returns { order_id, receipt_token, free: true }
 *           instead of a checkout_url — there is no hosted checkout to bounce
 *           through, so no webhook will ever arrive to trigger fulfillment.
 *

 *   GET  /api/public/tickets/{eventId}/orders/{orderId}?receipt=<token>
 *        -> lets the public event page poll "how did my purchase turn out?"
 *           after the provider bounces the buyer back from hosted checkout.
 *           Requires the opaque receipt_token minted at checkout time (NOT
 *           the sequential order id, which is guessable) so a stranger can't
 *           enumerate other buyers' orders. Once the order is fulfilled,
 *           returns each issued ticket's holder/type plus same-origin QR and
 *           `/t/{token}` links so the ticket can be shown immediately instead
 *           of waiting on the confirmation email.
 *
 * No authentication: the event must have ticketing_mode='internal' and be
 * publicly visible. Inventory is verified against live availability (which
 * already accounts for other active holds) before the hold is written.
 */
final class PublicTickets extends BaseEndpoint
{
    /** How long a pending order reserves inventory while payment is in flight. */
    private const HOLD_MINUTES = 15;

    /** Defensive cap so a single order cannot vacuum a whole allocation. */
    private const MAX_PER_TYPE = 20;

    public function handle(Request $request): Response
    {
        $eventId = $this->intParam('eventId');
        if ($eventId === null) {
            return $this->notFound('Event not found');
        }

        $event = $this->saleableEvent($eventId);
        if ($event === null) {
            return $this->notFound('Tickets are not available for this event');
        }

        if (($this->params['action'] ?? null) === 'orders') {
            return $request->method() === 'GET'
                ? $this->orderStatus($request, $event)
                : Response::methodNotAllowed();
        }

        if (($this->params['action'] ?? null) === 'discount') {
            return $request->method() === 'POST'
                ? $this->previewDiscount($request, $event)
                : Response::methodNotAllowed();
        }

        return match ($request->method()) {
            'GET'  => $this->listTypes($event),
            'POST' => $this->createOrder($request, $event),
            default => Response::methodNotAllowed(),
        };
    }

    /** GET — buyable ticket types with live availability. */
    private function listTypes(array $event): Response
    {
        $rows = $this->db->all(
            "SELECT id, name, description, price_cents, currency, status,
                    sales_start, sales_end, sort_order
               FROM ticket_types
              WHERE event_id = ?
                AND status = 'on_sale'
                AND (sales_start IS NULL OR sales_start <= NOW())
                AND (sales_end   IS NULL OR sales_end   >= NOW())
              ORDER BY sort_order ASC, id ASC",
            [(int) $event['id']]
        );

        $ticketing = new TicketingService();
        $types = [];
        foreach ($rows as $row) {
            $available = $ticketing->availableQuantity($this->db, (int) $row['id']);
            $types[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'description'  => $row['description'] !== null ? (string) $row['description'] : null,
                'price_cents'  => (int) $row['price_cents'],
                'currency'     => (string) $row['currency'],
                'available'    => $available,
                'sold_out'     => $available <= 0,
            ];
        }

        return $this->ok([
            'event' => [
                'id'    => (int) $event['id'],
                'title' => (string) $event['title'],
                'slug'  => (string) $event['slug'],
            ],
            'ticket_types' => $types,
        ]);
    }

    /** POST — create a held order and start hosted checkout. */
    private function createOrder(Request $request, array $event): Response
    {
        $eventId = (int) $event['id'];

        $name  = trim((string) $request->body('buyer_name', ''));
        $email = strtolower(trim((string) $request->body('buyer_email', '')));
        $phone = trim((string) $request->body('buyer_phone', ''));
        $marketingOptIn = boolish($request->body('marketing_opt_in', 0));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email address is required.'], 422);
        }
        if ($name === '') {
            return Response::json(['error' => 'Your name is required.'], 422);
        }

        $requested = $this->normalizeItems($request->body('items'));
        if ($requested === []) {
            return Response::json(['error' => 'Select at least one ticket.'], 422);
        }

        $ticketing = new TicketingService();

        $resolved = $this->resolveLineItems($eventId, $requested, $ticketing);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $lineItems = $resolved['lines'];
        $currency  = $resolved['currency'];
        $amount    = $resolved['amount_cents'];

        // Optional private discount code. The discount is folded into the line
        // items' unit prices, so from here down the rest of checkout — the hold,
        // the provider's line items, the stored order — is simply a cheaper
        // cart, with no separate adjustment for anything downstream to forget.
        $discountCodeId = null;
        $discountCents  = 0;
        $rawCode        = trim((string) $request->body('discount_code', ''));
        if ($rawCode !== '') {
            $applied = $this->applyDiscount($request, $eventId, $rawCode, $email, $lineItems, $currency);
            if ($applied instanceof Response) {
                return $applied;
            }
            $lineItems      = $applied['lines'];
            $discountCents  = $applied['discount_cents'];
            $discountCodeId = $applied['code_id'];
            $amount         = self::linesTotal($lineItems);
        }

        // Opaque credential (distinct from any ticket's secret token) that lets
        // the buyer's own browser poll this order's status after being bounced
        // back from hosted checkout — see orderStatus(). Not derived from the
        // order id, which is sequential and guessable.
        $receiptToken = bin2hex(random_bytes(20));

        // Create the pending order + items inside a transaction so a partially
        // written hold can never reserve phantom inventory.
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $orderId = $this->db->insert(
                "INSERT INTO ticket_orders
                    (event_id, buyer_name, buyer_email, buyer_phone,
                     marketing_opt_in, amount_cents, currency, status, hold_expires_at, receipt_token,
                     discount_code_id, discount_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?, ?)",
                [
                    $eventId, $name, $email, ($phone !== '' ? $phone : null),
                    $marketingOptIn, $amount, $currency, self::HOLD_MINUTES, $receiptToken,
                    $discountCodeId, $discountCents,
                ]
            );

            foreach ($lineItems as $li) {
                $this->db->run(
                    'INSERT INTO ticket_order_items (order_id, ticket_type_id, quantity, unit_price_cents)
                     VALUES (?, ?, ?, ?)',
                    [$orderId, $li['ticket_type_id'], $li['quantity'], $li['unit_price_cents']]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('PublicTickets order create failed: ' . $e->getMessage());
            return Response::json(['error' => 'Could not start checkout. Please try again.'], 500);
        }

        // A fully-free order (every selected ticket type priced at 0, or a
        // discount code that took the cart all the way to zero) has
        // nothing for a payment provider to collect — Stripe/Square checkout
        // sessions require a positive total and would just reject it. Fulfill
        // immediately instead of ever starting hosted checkout, and email the
        // tickets right away since there's no webhook coming to trigger it.
        if ($amount === 0) {
            try {
                $tickets = $ticketing->fulfillOrder($this->db, $orderId);
            } catch (\Throwable $e) {
                error_log('PublicTickets free-order fulfillment failed: ' . $e->getMessage());
                return Response::json(['error' => 'Could not issue your ticket(s). Please try again.'], 500);
            }

            $this->db->run(
                "UPDATE ticket_orders SET provider = 'free' WHERE id = ?",
                [$orderId]
            );

            $deliverable = array_values(array_filter(
                $tickets,
                static fn(array $t): bool => !empty($t['token'])
            ));
            if ($deliverable !== []) {
                $ticketing->emailTickets($this->db, $this->root, $orderId, $deliverable);
            }

            return $this->ok([
                'order_id'      => $orderId,
                'receipt_token' => $receiptToken,
                'free'          => true,
            ]);
        }

        // Start a hosted checkout with whichever provider is currently active.
        $env      = new Env();
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $eventUrl = $appUrl . '/' . event_public_path($event);
        $success  = $eventUrl . '&order=' . $orderId . '&checkout=success&receipt=' . rawurlencode($receiptToken);
        $cancel   = $eventUrl . '&order=' . $orderId . '&checkout=cancel';

        try {
            $provider = PaymentProviders::active($this->db, $env);
            $orderRow = [
                'id'          => $orderId,
                'currency'    => $currency,
                'buyer_email' => $email,
                'buyer_name'  => $name,
            ];
            $result = $provider->createCheckout($orderRow, $lineItems, $success, $cancel);
        } catch (\Throwable $e) {
            // Release the hold immediately so inventory is not stranded.
            $this->db->run(
                "UPDATE ticket_orders SET status = 'canceled', hold_expires_at = NULL WHERE id = ? AND status = 'pending'",
                [$orderId]
            );
            error_log('PublicTickets checkout failed: ' . $e->getMessage());
            return Response::json(['error' => 'Payment could not be started. Please try again.'], 502);
        }

        $checkoutUrl = (string) ($result['checkout_url'] ?? '');
        $providerRef = (string) ($result['provider_ref'] ?? '');
        if ($checkoutUrl === '') {
            $this->db->run(
                "UPDATE ticket_orders SET status = 'canceled', hold_expires_at = NULL WHERE id = ? AND status = 'pending'",
                [$orderId]
            );
            return Response::json(['error' => 'Payment could not be started. Please try again.'], 502);
        }

        // Persist the provider that handled this order (so webhook/refund flows
        // resolve the right provider even after the active one is switched) and
        // its checkout reference (so the webhook can match the order back).
        $this->db->run(
            'UPDATE ticket_orders SET provider = ?, provider_ref = ? WHERE id = ?',
            [$provider->key(), $providerRef, $orderId]
        );

        return $this->ok([
            'order_id'     => $orderId,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /**
     * POST /discount — price a code against the current cart, committing to
     * nothing. Lets the ticket page confirm "EASTBAY30 applied — you save
     * $15.00" before the buyer hands over their details.
     */
    private function previewDiscount(Request $request, array $event): Response
    {
        $eventId = (int) $event['id'];

        $rawCode = trim((string) $request->body('code', ''));
        if ($rawCode === '') {
            return Response::json(['error' => 'Enter a discount code.'], 422);
        }

        $requested = $this->normalizeItems($request->body('items'));
        if ($requested === []) {
            return Response::json(['error' => 'Select at least one ticket first.'], 422);
        }

        $resolved = $this->resolveLineItems($eventId, $requested, new TicketingService());
        if ($resolved instanceof Response) {
            return $resolved;
        }

        // The buyer's email may not be typed yet at preview time; when it is,
        // pass it so a once-per-email code fails here rather than at checkout.
        $email   = trim((string) $request->body('buyer_email', ''));
        $applied = $this->applyDiscount(
            $request,
            $eventId,
            $rawCode,
            ($email !== '' ? $email : null),
            $resolved['lines'],
            $resolved['currency']
        );
        if ($applied instanceof Response) {
            return $applied;
        }

        return $this->ok([
            'code'           => $applied['code'],
            'description'    => $applied['description'],
            'discount_cents' => $applied['discount_cents'],
            'subtotal_cents' => $resolved['amount_cents'],
            'total_cents'    => self::linesTotal($applied['lines']),
            'currency'       => $resolved['currency'],
        ]);
    }

    /**
     * Resolve requested {type => qty} against currently-buyable inventory.
     *
     * Shared by checkout and the discount preview so a code is always priced
     * against exactly the cart checkout would build — a preview can never
     * promise a discount the real purchase then declines to give.
     *
     * @param array<int,int> $requested
     *
     * @return array{lines:array<int,array>,currency:string,amount_cents:int}|Response
     */
    private function resolveLineItems(int $eventId, array $requested, TicketingService $ticketing): array|Response
    {
        // Lock nothing yet — availableQuantity already excludes active holds.
        $lineItems = [];
        $currency  = null;
        $amount    = 0;

        foreach ($requested as $typeId => $qty) {
            $type = $this->db->one(
                "SELECT id, name, price_cents, currency
                   FROM ticket_types
                  WHERE id = ? AND event_id = ? AND status = 'on_sale'
                    AND (sales_start IS NULL OR sales_start <= NOW())
                    AND (sales_end   IS NULL OR sales_end   >= NOW())",
                [$typeId, $eventId]
            );
            if ($type === null) {
                return Response::json(['error' => 'A selected ticket type is not on sale.'], 422);
            }

            $available = $ticketing->availableQuantity($this->db, $typeId);
            if ($qty > $available) {
                return Response::json([
                    'error'          => sprintf('Only %d ticket(s) left for "%s".', $available, (string) $type['name']),
                    'ticket_type_id' => $typeId,
                    'available'      => $available,
                ], 409);
            }

            $typeCurrency = (string) $type['currency'];
            if ($currency === null) {
                $currency = $typeCurrency;
            } elseif ($currency !== $typeCurrency) {
                return Response::json(['error' => 'Cannot mix ticket currencies in one order.'], 422);
            }

            $unit    = (int) $type['price_cents'];
            $amount += $unit * $qty;
            $lineItems[] = [
                'ticket_type_id'  => $typeId,
                'name'            => (string) $type['name'],
                'quantity'        => $qty,
                'unit_price_cents'=> $unit,
            ];
        }

        return [
            'lines'        => $lineItems,
            'currency'     => $currency ?: 'USD',
            'amount_cents' => $amount,
        ];
    }

    /**
     * Resolve, validate and apply a discount code to a cart.
     *
     * Returns a Response carrying a buyer-facing reason on every rejection, or
     * the rewritten line items plus the exact discount on success.
     *
     * @param array<int,array> $lines
     *
     * @return array{lines:array<int,array>,discount_cents:int,code_id:int,code:string,description:string}|Response
     */
    private function applyDiscount(
        Request $request,
        int $eventId,
        string $rawCode,
        ?string $buyerEmail,
        array $lines,
        string $currency
    ): array|Response {
        // Codes are secret by design and this endpoint is public and
        // unauthenticated, so code-guessing is the one real abuse vector here.
        // Throttle per IP — generously enough that a buyer mistyping the code
        // they were emailed is never the one who gets locked out.
        $ip = Request::clientIp() ?? 'unknown';
        if (RateLimiter::tooMany($this->db, 'ticket-discount:ip:' . $ip, 30, 900)) {
            return Response::json(['error' => 'Too many code attempts. Please try again shortly.'], 429);
        }

        $code = TicketDiscounts::find($this->db, $eventId, $rawCode);
        if ($code === null) {
            return Response::json(['error' => "That code isn't valid for this event."], 422);
        }

        if ($problem = TicketDiscounts::checkUsable($this->db, $code, $buyerEmail)) {
            return Response::json(['error' => $problem], 422);
        }

        $scoped = TicketDiscounts::scopedTypeIds($this->db, (int) $code['id']);
        $result = TicketDiscounts::apply($code, $lines, $scoped);

        // Valid code, wrong cart: tier-scoped to tickets they haven't picked,
        // or a cart that's already free. Say so rather than silently charging
        // full price and leaving them to wonder whether it worked.
        if ($result['discount_cents'] <= 0) {
            return Response::json([
                'error' => "That code doesn't apply to the tickets you selected.",
            ], 422);
        }

        return [
            'lines'          => $result['lines'],
            'discount_cents' => $result['discount_cents'],
            'code_id'        => (int) $code['id'],
            'code'           => (string) $code['code'],
            'description'    => TicketDiscounts::describe($code, $currency),
        ];
    }

    /** Charged total of a set of line items, in cents. */
    private static function linesTotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += (int) $line['quantity'] * (int) $line['unit_price_cents'];
        }
        return $total;
    }

    /**
     * GET /orders/{orderId}?receipt=<token> — poll a just-completed checkout.
     *
     * Gated by the opaque receipt_token minted at order creation, not the
     * (guessable) sequential order id, so a stranger can't enumerate other
     * buyers' purchases. While the order is still `pending`/`paid` (webhook
     * hasn't fulfilled it yet) the caller is expected to poll again shortly;
     * once `fulfilled`, every issued ticket's holder/type plus same-origin
     * QR and `/t/{token}` links are returned so the buyer can be shown their
     * ticket immediately instead of waiting on the confirmation email.
     */
    private function orderStatus(Request $request, array $event): Response
    {
        $orderId = $this->intParam('orderId');
        $receipt = trim((string) $request->query('receipt', ''));
        if ($orderId === null || $receipt === '') {
            return $this->notFound('Order not found');
        }

        $order = $this->db->one(
            'SELECT id, status, receipt_token FROM ticket_orders WHERE id = ? AND event_id = ?',
            [$orderId, (int) $event['id']]
        );
        if ($order === null
            || $order['receipt_token'] === null
            || !hash_equals((string) $order['receipt_token'], $receipt)
        ) {
            return $this->notFound('Order not found');
        }

        $status = (string) $order['status'];
        $tickets = [];

        if ($status === 'fulfilled') {
            $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
            $rows = $this->db->all(
                "SELECT t.code, t.token, t.holder_name, tt.name AS ticket_type_name
                   FROM tickets t
                   JOIN ticket_types tt ON tt.id = t.ticket_type_id
                  WHERE t.order_id = ? AND t.status != 'void'
                  ORDER BY t.id ASC",
                [$orderId]
            );
            foreach ($rows as $row) {
                if ($row['token'] === null) {
                    continue; // legacy row from before plaintext tokens were retained
                }
                $token = (string) $row['token'];
                $tickets[] = [
                    'code'             => (string) $row['code'],
                    'ticket_type_name' => (string) $row['ticket_type_name'],
                    'holder_name'      => $row['holder_name'] !== null ? (string) $row['holder_name'] : null,
                    'ticket_url'       => $appUrl . '/t/' . rawurlencode($token),
                    'qr_url'           => $appUrl . '/assets/qr.svg?text=' . rawurlencode($token) . '&size=320',
                ];
            }
        }

        return $this->ok([
            'order_id' => $orderId,
            'status'   => $status,
            'tickets'  => $tickets,
        ]);
    }

    /**
     * Collapse the requested items into a map of ticket_type_id => quantity,
     * dropping anything malformed and clamping per-type quantity to a sane cap.
     *
     * @return array<int,int>
     */
    private function normalizeItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $typeId = (int) ($item['ticket_type_id'] ?? 0);
            $qty    = (int) ($item['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0) {
                continue;
            }
            $qty = min($qty, self::MAX_PER_TYPE);
            $out[$typeId] = ($out[$typeId] ?? 0) + $qty;
        }
        foreach ($out as $id => $qty) {
            $out[$id] = min($qty, self::MAX_PER_TYPE);
        }
        return $out;
    }

    /**
     * Fetch the event only if it is publicly visible AND using internal
     * ticketing. Returns null otherwise so the surface stays invisible for
     * events that don't sell tickets here.
     */
    private function saleableEvent(int $eventId): ?array
    {
        return $this->db->one(
            "SELECT id, title, slug
               FROM events
              WHERE id = ? AND public_visibility = 1 AND ticketing_mode = 'internal'",
            [$eventId]
        );
    }

    private function intParam(string $key): ?int
    {
        $value = $this->params[$key] ?? null;
        return ctype_digit((string) $value) ? (int) $value : null;
    }
}
