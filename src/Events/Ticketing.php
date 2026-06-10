<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Env;
use Panic\Mailer;
use Panic\Request;
use Panic\Response;
use Panic\TicketingService;
use Panic\Payments\PaymentProviders;
use function Panic\date_or_null;
use function Panic\log_activity;

/**
 * Admin ticketing surface for an event:
 *   /api/events/{id}/ticketing
 *
 * All routes are JWT-authenticated (kernel-level) and gated by the
 * 'manage_ticketing' event capability (venue_admin + event_owner; promoters
 * are intentionally excluded).
 *
 * Sub-resources are selected with the `child` route param:
 *   (none)        GET  -> dashboard: tiers + live sales summary + event settings
 *                 POST -> create a ticket type (tier)
 *                 PATCH-> update events.ticketing_mode / payment event settings
 *   types/{id}    PATCH-> update a tier   DELETE-> delete a tier
 *   comp          POST -> issue comp tickets (emails QR)
 *   refund        POST -> cancel-event refund: refund + void all fulfilled orders
 *
 * Inventory, fulfillment, comps, voids, and oversell guards live in the shared
 * provider-agnostic TicketingService — this endpoint orchestrates and never
 * reimplements that accounting.
 */
final class Ticketing extends BaseEndpoint
{
    private const TIER_STATUSES = ['draft', 'on_sale', 'paused', 'sold_out', 'closed'];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'manage_ticketing')) {
            return $denied;
        }

        $child = (string) ($this->params['child'] ?? '');
        $childId = $this->params['childId'] ?? null;

        return match ($child) {
            ''       => $this->root($request, $eventId),
            'types'  => $this->types($request, $eventId, $childId ? (int) $childId : null),
            'comp'   => $request->method() === 'POST' ? $this->comp($request, $eventId) : Response::methodNotAllowed(),
            'refund' => $request->method() === 'POST' ? $this->refundCancel($request, $eventId) : Response::methodNotAllowed(),
            default  => $this->notFound(),
        };
    }

    // ─── /ticketing ──────────────────────────────────────────────────────────────

    private function root(Request $request, int $eventId): Response
    {
        return match ($request->method()) {
            'GET'   => $this->dashboard($eventId),
            'POST'  => $this->createType($request, $eventId),
            'PATCH' => $this->updateEventSettings($request, $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    /**
     * Live dashboard: every tier with sold/available/revenue, plus event-level
     * totals and the ticketing/payment settings for the event.
     */
    private function dashboard(int $eventId): Response
    {
        $event = $this->db->one(
            'SELECT id, title, ticketing_mode, ticket_url, ticket_system, capacity FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound('Event not found');
        }

        $service = new TicketingService();
        $types = $this->db->all(
            'SELECT * FROM ticket_types WHERE event_id = ? ORDER BY sort_order ASC, id ASC',
            [$eventId]
        );

        $tiers = [];
        $totalSold = 0;
        $totalAvailable = 0;
        $grossCents = 0;
        foreach ($types as $type) {
            $typeId = (int) $type['id'];
            $sold = (int) $type['quantity_sold'];
            $available = $service->availableQuantity($this->db, $typeId);

            // Revenue from real, paid (non-comp) money for this tier.
            $rev = $this->db->one(
                "SELECT COALESCE(SUM(oi.quantity * oi.unit_price_cents), 0) AS cents
                   FROM ticket_order_items oi
                   JOIN ticket_orders o ON o.id = oi.order_id
                  WHERE oi.ticket_type_id = ?
                    AND o.is_comp = 0
                    AND o.status IN ('paid', 'fulfilled')",
                [$typeId]
            );
            $revenueCents = (int) ($rev['cents'] ?? 0);

            $comped = $this->db->one(
                "SELECT COALESCE(SUM(oi.quantity), 0) AS n
                   FROM ticket_order_items oi
                   JOIN ticket_orders o ON o.id = oi.order_id
                  WHERE oi.ticket_type_id = ? AND o.is_comp = 1
                    AND o.status = 'fulfilled'",
                [$typeId]
            );

            $tiers[] = [
                'id'             => $typeId,
                'name'           => $type['name'],
                'description'    => $type['description'],
                'price_cents'    => (int) $type['price_cents'],
                'currency'       => $type['currency'],
                'quantity_total' => (int) $type['quantity_total'],
                'quantity_sold'  => $sold,
                'quantity_comped'=> (int) ($comped['n'] ?? 0),
                'available'      => $available,
                'revenue_cents'  => $revenueCents,
                'sales_start'    => $type['sales_start'],
                'sales_end'      => $type['sales_end'],
                'status'         => $type['status'],
                'sort_order'     => (int) $type['sort_order'],
            ];

            $totalSold += $sold;
            $totalAvailable += $available;
            $grossCents += $revenueCents;
        }

        $redeemed = $this->db->one(
            "SELECT COUNT(*) AS n FROM tickets WHERE event_id = ? AND status = 'redeemed'",
            [$eventId]
        );
        $issued = $this->db->one(
            "SELECT COUNT(*) AS n FROM tickets WHERE event_id = ? AND status IN ('issued', 'redeemed')",
            [$eventId]
        );

        return $this->ok([
            'event' => [
                'id'             => (int) $event['id'],
                'title'          => $event['title'],
                'ticketing_mode' => $event['ticketing_mode'],
                'ticket_url'     => $event['ticket_url'],
                'ticket_system'  => $event['ticket_system'],
                'capacity'       => $event['capacity'] !== null ? (int) $event['capacity'] : null,
            ],
            'tiers'   => $tiers,
            'summary' => [
                'tiers'                => count($tiers),
                'tickets_sold'         => $totalSold,
                'tickets_available'    => $totalAvailable,
                'tickets_issued'       => (int) ($issued['n'] ?? 0),
                'tickets_redeemed'     => (int) ($redeemed['n'] ?? 0),
                'gross_ticket_cents'   => $grossCents,
                'gross_ticket_sales'   => round($grossCents / 100, 2),
            ],
        ]);
    }

    /**
     * Sync the realized ticketing numbers into event_settlements so the
     * settlement view reflects actual sales (tickets_sold, gross_ticket_sales).
     */
    private function syncSettlement(int $eventId, int $ticketsSold, int $grossCents): void
    {
        $this->db->run(
            'INSERT INTO event_settlements (event_id, gross_ticket_sales, tickets_sold, settled_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gross_ticket_sales = VALUES(gross_ticket_sales), tickets_sold = VALUES(tickets_sold)',
            [$eventId, round($grossCents / 100, 2), $ticketsSold, $this->userId()]
        );
    }

    private function createType(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'Tier name is required'], 422);
        }
        $total = max(0, (int) ($b['quantity_total'] ?? 0));
        $price = max(0, (int) ($b['price_cents'] ?? 0));
        $status = in_array($b['status'] ?? '', self::TIER_STATUSES, true) ? $b['status'] : 'draft';

        $id = $this->db->insert(
            'INSERT INTO ticket_types
                (event_id, name, description, price_cents, currency, quantity_total,
                 sales_start, sales_end, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $name,
                $b['description'] ?? null,
                $price,
                strtoupper((string) ($b['currency'] ?? 'USD')),
                $total,
                date_or_null($b['sales_start'] ?? null),
                date_or_null($b['sales_end'] ?? null),
                $status,
                (int) ($b['sort_order'] ?? 0),
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'ticket tier created', ['ticket_type_id' => $id, 'name' => $name]);
        return $this->ok(['id' => $id]);
    }

    // ─── /ticketing/types/{id} ─────────────────────────────────────────────────────

    private function types(Request $request, int $eventId, ?int $typeId): Response
    {
        if (!$typeId) {
            return $this->notFound('Ticket type id is required');
        }
        $type = $this->db->one('SELECT * FROM ticket_types WHERE id = ? AND event_id = ?', [$typeId, $eventId]);
        if (!$type) {
            return $this->notFound('Ticket type not found');
        }

        return match ($request->method()) {
            'PATCH'  => $this->updateType($request, $eventId, $type),
            'DELETE' => $this->deleteType($eventId, $typeId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function updateType(Request $request, int $eventId, array $type): Response
    {
        $typeId = (int) $type['id'];
        $b = $request->body();

        // quantity_total may not drop below what is already sold.
        $sold = (int) $type['quantity_sold'];
        $total = array_key_exists('quantity_total', $b) ? max(0, (int) $b['quantity_total']) : (int) $type['quantity_total'];
        if ($total < $sold) {
            return Response::json(['error' => "Cannot set quantity below {$sold} already sold/comped."], 422);
        }
        $status = in_array($b['status'] ?? '', self::TIER_STATUSES, true) ? $b['status'] : $type['status'];

        $this->db->run(
            'UPDATE ticket_types
                SET name = ?, description = ?, price_cents = ?, currency = ?,
                    quantity_total = ?, sales_start = ?, sales_end = ?, status = ?, sort_order = ?
              WHERE id = ? AND event_id = ?',
            [
                trim((string) ($b['name'] ?? $type['name'])),
                array_key_exists('description', $b) ? $b['description'] : $type['description'],
                array_key_exists('price_cents', $b) ? max(0, (int) $b['price_cents']) : (int) $type['price_cents'],
                array_key_exists('currency', $b) ? strtoupper((string) $b['currency']) : $type['currency'],
                $total,
                array_key_exists('sales_start', $b) ? date_or_null($b['sales_start']) : $type['sales_start'],
                array_key_exists('sales_end', $b) ? date_or_null($b['sales_end']) : $type['sales_end'],
                $status,
                array_key_exists('sort_order', $b) ? (int) $b['sort_order'] : (int) $type['sort_order'],
                $typeId,
                $eventId,
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'ticket tier updated', ['ticket_type_id' => $typeId]);
        return $this->ok(['ok' => true]);
    }

    private function deleteType(int $eventId, int $typeId): Response
    {
        // Guard: never delete a tier that has issued/redeemed tickets — those
        // would be orphaned. Voided tickets do not block deletion.
        $live = $this->db->one(
            "SELECT COUNT(*) AS n FROM tickets WHERE ticket_type_id = ? AND status IN ('issued', 'redeemed')",
            [$typeId]
        );
        if ((int) ($live['n'] ?? 0) > 0) {
            return Response::json(['error' => 'Tier has issued tickets and cannot be deleted. Set it to closed instead.'], 409);
        }
        $this->db->run('DELETE FROM ticket_types WHERE id = ? AND event_id = ?', [$typeId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'ticket tier deleted', ['ticket_type_id' => $typeId]);
        return $this->ok(['ok' => true]);
    }

    // ─── /ticketing/comp ───────────────────────────────────────────────────────────

    private function comp(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $typeId = (int) ($b['ticket_type_id'] ?? 0);
        $quantity = max(1, (int) ($b['quantity'] ?? 1));
        $holderName = isset($b['holder_name']) ? trim((string) $b['holder_name']) ?: null : null;
        $holderEmail = isset($b['holder_email']) ? trim((string) $b['holder_email']) ?: null : null;

        $type = $this->db->one('SELECT id, name FROM ticket_types WHERE id = ? AND event_id = ?', [$typeId, $eventId]);
        if (!$type) {
            return $this->notFound('Ticket type not found');
        }

        $service = new TicketingService();
        try {
            $tickets = $service->issueComp($this->db, $typeId, $quantity, $holderName, $holderEmail, $this->userId());
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }

        // Deliver QR/links by email when a holder address was supplied. The
        // plaintext token is returned by issueComp exactly once — email it now.
        $emailed = 0;
        if ($holderEmail) {
            $emailed = $this->emailTickets($eventId, $holderEmail, $holderName, $tickets);
        }

        log_activity($this->db, $eventId, $this->userId(), 'comp tickets issued', [
            'ticket_type_id' => $typeId,
            'quantity'       => count($tickets),
            'holder_email'   => $holderEmail,
        ]);

        return $this->ok([
            'issued'  => count($tickets),
            'emailed' => $emailed,
            'tickets' => array_map(static fn (array $t) => ['id' => $t['id'], 'code' => $t['code']], $tickets),
        ]);
    }

    /**
     * Email each issued ticket's redemption link (carrying the one-time secret
     * token). Returns the count delivered. A QR is generated client-side from
     * the link; here we send the scannable link itself.
     */
    private function emailTickets(int $eventId, string $email, ?string $name, array $tickets): int
    {
        $event = $this->db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
        $title = (string) ($event['title'] ?? 'the event');
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

        $lines = [];
        foreach ($tickets as $t) {
            if (empty($t['token'])) {
                continue; // idempotent re-issue: no plaintext token to deliver.
            }
            $link = "{$appUrl}/t/{$t['token']}";
            $lines[] = "  {$t['code']}  ->  {$link}";
        }
        if ($lines === []) {
            return 0;
        }

        $greeting = $name ? "Hi {$name}," : 'Hello,';
        $body = "{$greeting}\n\n"
            . "You have complimentary ticket(s) for {$title}.\n\n"
            . "Present the QR/link below at the door:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Each link admits one person and can be scanned once.\n";

        (new Mailer($this->root))->send($email, "Your comp tickets for {$title}", $body);
        return count($lines);
    }

    // ─── /ticketing/refund (cancel-event refund) ───────────────────────────────────

    /**
     * Cancel-event refund: iterate every fulfilled/paid, non-comp order for the
     * event, refund the captured payment through the SAME provider that
     * processed it (stored on the order — not the currently-active provider),
     * then void its tickets and mark the order refunded. Idempotent per order:
     * already-refunded orders are skipped.
     */
    private function refundCancel(Request $request, int $eventId): Response
    {
        $env = new Env();
        $service = new TicketingService();

        $orders = $this->db->all(
            "SELECT * FROM ticket_orders
              WHERE event_id = ? AND is_comp = 0
                AND status IN ('paid', 'fulfilled')
              ORDER BY id ASC",
            [$eventId]
        );

        $results = [];
        $refundedOrders = 0;
        $refundedCents = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            $amount = (int) $order['amount_cents'];
            $providerKey = (string) ($order['provider'] ?? '');
            $paymentRef = (string) ($order['provider_payment_ref'] ?? '');

            $entry = ['order_id' => $orderId, 'amount_cents' => $amount, 'ok' => false, 'error' => null];

            if ($amount > 0 && $paymentRef !== '') {
                $provider = PaymentProviders::byKey($providerKey, $env);
                if ($provider === null) {
                    $entry['error'] = "Unknown provider '{$providerKey}'";
                    $failed++;
                    $results[] = $entry;
                    continue;
                }
                $refund = $provider->refund($paymentRef, $amount);
                if (!($refund['ok'] ?? false)) {
                    $entry['error'] = $refund['error'] ?? 'Refund failed';
                    $failed++;
                    $results[] = $entry;
                    continue; // leave order intact; do not void tickets on a failed refund.
                }
                $refundedCents += $amount;
            }

            // Void this order's tickets (returns inventory) and mark refunded.
            foreach ($this->db->all('SELECT id FROM tickets WHERE order_id = ?', [$orderId]) as $ticket) {
                $service->voidTicket($this->db, (int) $ticket['id'], $this->userId());
            }
            $this->db->run(
                "UPDATE ticket_orders SET status = 'refunded', refunded_at = NOW() WHERE id = ?",
                [$orderId]
            );

            $entry['ok'] = true;
            $refundedOrders++;
            $results[] = $entry;
        }

        // Settlement now reflects post-refund reality (recompute from live data).
        $this->recomputeSettlement($eventId);

        log_activity($this->db, $eventId, $this->userId(), 'event tickets refunded', [
            'orders_refunded' => $refundedOrders,
            'cents_refunded'  => $refundedCents,
            'failed'          => $failed,
        ]);

        return Response::json([
            'orders_refunded' => $refundedOrders,
            'cents_refunded'  => $refundedCents,
            'failed'          => $failed,
            'results'         => $results,
        ], $failed > 0 ? 207 : 200);
    }

    /** Recompute event_settlements ticket figures from current live data. */
    private function recomputeSettlement(int $eventId): void
    {
        $sold = $this->db->one(
            'SELECT COALESCE(SUM(quantity_sold), 0) AS n FROM ticket_types WHERE event_id = ?',
            [$eventId]
        );
        $gross = $this->db->one(
            "SELECT COALESCE(SUM(oi.quantity * oi.unit_price_cents), 0) AS cents
               FROM ticket_order_items oi
               JOIN ticket_orders o ON o.id = oi.order_id
              WHERE o.event_id = ? AND o.is_comp = 0
                AND o.status IN ('paid', 'fulfilled')",
            [$eventId]
        );
        $this->syncSettlement($eventId, (int) ($sold['n'] ?? 0), (int) ($gross['cents'] ?? 0));
    }

    // ─── PATCH /ticketing (event settings) ─────────────────────────────────────────

    private function updateEventSettings(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $sets = [];
        $params = [];
        $newMode = null;

        if (array_key_exists('ticketing_mode', $b)) {
            $newMode = $b['ticketing_mode'] === 'internal' ? 'internal' : 'external';
            $sets[] = 'ticketing_mode = ?';
            $params[] = $newMode;
        }
        if (array_key_exists('ticket_url', $b)) {
            $sets[] = 'ticket_url = ?';
            $params[] = $b['ticket_url'] !== '' ? (string) $b['ticket_url'] : null;
        }
        if (array_key_exists('ticket_system', $b)) {
            $sets[] = 'ticket_system = ?';
            $params[] = $b['ticket_system'] !== '' ? (string) $b['ticket_system'] : null;
        }

        if ($sets === []) {
            return Response::json(['error' => 'No recognized settings to update'], 422);
        }

        $params[] = $eventId;
        $this->db->run('UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        log_activity($this->db, $eventId, $this->userId(), 'ticketing settings updated', array_intersect_key($b, array_flip(['ticketing_mode', 'ticket_url', 'ticket_system'])));

        // Turning on in-house ticketing for a fresh event seeds a default
        // "General Admission" type so the operator has something to sell.
        $seeded = $newMode === 'internal' ? $this->seedDefaultTicketType($eventId) : false;

        // Re-read for the caller.
        $event = $this->db->one('SELECT ticketing_mode, ticket_url, ticket_system FROM events WHERE id = ?', [$eventId]);
        return $this->ok(['event' => $event, 'seeded_default_type' => $seeded]);
    }

    /**
     * Seed a default "General Admission" ticket type for an event the first
     * time it switches to in-house ticketing. Priced from the event's
     * ticket_price, sized to capacity (fallback 100), on sale from today
     * through the end of the event date. No-op — returns false — if any ticket
     * type already exists, so it never duplicates on re-save.
     */
    private function seedDefaultTicketType(int $eventId): bool
    {
        $existing = $this->db->one('SELECT COUNT(*) AS n FROM ticket_types WHERE event_id = ?', [$eventId]);
        if ((int) ($existing['n'] ?? 0) > 0) {
            return false;
        }

        $event = $this->db->one('SELECT ticket_price, capacity, `date` FROM events WHERE id = ?', [$eventId]);
        if ($event === null) {
            return false;
        }

        $priceCents = (int) round(((float) ($event['ticket_price'] ?? 0)) * 100);
        $capacity   = (int) ($event['capacity'] ?? 0);
        $quantity   = $capacity > 0 ? $capacity : 100;

        // Sales open today and close at the end of the event day.
        $salesStart = date('Y-m-d') . ' 00:00:00';
        $salesEnd   = !empty($event['date']) ? $event['date'] . ' 23:59:59' : null;

        $id = $this->db->insert(
            'INSERT INTO ticket_types
                (event_id, name, description, price_cents, currency, quantity_total,
                 sales_start, sales_end, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$eventId, 'General Admission', null, $priceCents, 'USD', $quantity, $salesStart, $salesEnd, 'on_sale', 0]
        );
        log_activity($this->db, $eventId, $this->userId(), 'default ticket type seeded', [
            'ticket_type_id' => $id,
            'price_cents'    => $priceCents,
            'quantity_total' => $quantity,
        ]);
        return true;
    }
}
