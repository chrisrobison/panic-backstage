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
 *   POST /api/public/tickets/{eventId}/checkout
 *        body: { buyer_name, buyer_email, buyer_phone?,
 *                items: [ { ticket_type_id, quantity }, ... ] }
 *        -> creates a pending ticket_orders row + ticket_order_items with a
 *           15-minute hold (reserving inventory), starts a hosted checkout with
 *           the active payment provider, persists the provider + provider_ref on
 *           the order, and returns { checkout_url, order_id }.
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
        $email = trim((string) $request->body('buyer_email', ''));
        $phone = trim((string) $request->body('buyer_phone', ''));

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

        // Resolve each requested type against currently-buyable inventory.
        // Lock nothing yet — availabilityQuantity already excludes active holds.
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

        $currency = $currency ?: 'USD';

        // Create the pending order + items inside a transaction so a partially
        // written hold can never reserve phantom inventory.
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $orderId = $this->db->insert(
                "INSERT INTO ticket_orders
                    (event_id, buyer_name, buyer_email, buyer_phone,
                     amount_cents, currency, status, hold_expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? MINUTE))",
                [$eventId, $name, $email, ($phone !== '' ? $phone : null), $amount, $currency, self::HOLD_MINUTES]
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

        // Start a hosted checkout with whichever provider is currently active.
        $env      = new Env();
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $eventUrl = $appUrl . '/' . event_public_path($event);
        $success  = $eventUrl . '&order=' . $orderId . '&checkout=success';
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
