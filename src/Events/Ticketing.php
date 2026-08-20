<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\Address;
use Panic\BaseEndpoint;
use Panic\Env;
use Panic\Mailer;
use Panic\PhysicalTicketBatchService;
use Panic\PhysicalTicketOversellException;
use Panic\PhysicalTicketPdfGenerator;
use Panic\PhysicalTicketRangeCollisionException;
use Panic\PhysicalTicketValidationException;
use Panic\Request;
use Panic\Response;
use Panic\TicketDiscounts;
use Panic\TicketingService;
use Panic\Payments\PaymentProviders;
use function Panic\date_or_null;
use function Panic\event_public_path;
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
 *   discounts     GET  -> list private discount codes (+ live redemption stats)
 *                 POST -> create a code
 *   discounts/{id} PATCH-> update a code  DELETE-> delete an unredeemed code
 *
 * Inventory, fulfillment, comps, voids, and oversell guards live in the shared
 * provider-agnostic TicketingService — this endpoint orchestrates and never
 * reimplements that accounting.
 */
final class Ticketing extends BaseEndpoint
{
    private const TIER_STATUSES = ['draft', 'on_sale', 'paused', 'sold_out', 'closed'];

    /** House comp allocation reserved out of capacity on first in-house setup. */
    private const DEFAULT_COMP_RESERVE = 20;

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'manage_ticketing')) {
            return $denied;
        }

        $child = (string) ($this->params['child'] ?? '');
        $childId = $this->params['childId'] ?? null;

        return match ($child) {
            ''        => $this->root($request, $eventId),
            'types'   => $this->types($request, $eventId, $childId ? (int) $childId : null),
            'tickets' => $this->tickets($request, $eventId, $childId ? (int) $childId : null),
            'comp'    => $request->method() === 'POST' ? $this->comp($request, $eventId) : Response::methodNotAllowed(),
            'refund'  => $request->method() === 'POST' ? $this->refundCancel($request, $eventId) : Response::methodNotAllowed(),
            'discounts' => $this->discounts($request, $eventId, $childId ? (int) $childId : null),
            'print-batches' => $this->printBatches($request, $eventId, $childId ? (int) $childId : null),
            default   => $this->notFound(),
        };
    }

    // ─── /ticketing/tickets — individual issued tickets ────────────────────────────

    /**
     *   GET    /ticketing/tickets        list every issued ticket (paid + comp)
     *   POST   /ticketing/tickets/{id}   action: resend (default) | redeem
     *   DELETE /ticketing/tickets/{id}   void (invalidate) the ticket
     *
     * POST dispatches on an 'action' body param that defaults to 'resend', so
     * every existing caller keeps working unchanged.
     */
    private function tickets(Request $request, int $eventId, ?int $ticketId): Response
    {
        if ($ticketId === null) {
            return $request->method() === 'GET' ? $this->listTickets($eventId) : Response::methodNotAllowed();
        }
        $ticket = $this->db->one('SELECT * FROM tickets WHERE id = ? AND event_id = ?', [$ticketId, $eventId]);
        if (!$ticket) {
            return $this->notFound('Ticket not found');
        }
        if ($request->method() === 'POST') {
            $action = strtolower(trim((string) $request->body('action', 'resend')));
            return match ($action) {
                'resend' => $this->resendTicket($eventId, $ticket),
                'redeem' => $this->manualRedeem($eventId, $ticket),
                default  => Response::json(['error' => 'Unknown ticket action.'], 422),
            };
        }
        return match ($request->method()) {
            'DELETE' => $this->voidIssuedTicket($eventId, $ticket),
            default  => Response::methodNotAllowed(),
        };
    }

    /**
     * Mark a ticket used from the admin UI — the office-side counterpart to the
     * door scanner's no-QR admission, for the guest standing in front of someone
     * who has the full app open rather than the scanner page.
     *
     * Writes the same ticket_scans 'manual_admit' audit row the door writes, so
     * the scan log stays a complete headcount no matter which surface admitted
     * the person; unlike the door, the actor here is a known user, so
     * redeemed_by_user_id is set and redeemed_via_scanner_id stays NULL.
     */
    private function manualRedeem(int $eventId, array $ticket): Response
    {
        $ticketId = (int) $ticket['id'];

        if ((string) $ticket['status'] === 'void') {
            return Response::json(['error' => 'This ticket is void and cannot be admitted.'], 422);
        }

        // Same rule the door scanner enforces (see Scanner::isUnsoldPhysicalTicket()):
        // a physical batch ticket that was printed/allocated but never sold
        // must not be marked used here either, just because staff have the
        // full admin app open instead of the scanner page.
        if ((string) $ticket['delivery_method'] === 'physical'
            && !in_array((string) ($ticket['physical_status'] ?? ''), ['sold', 'checked_in'], true)
        ) {
            return Response::json(['error' => 'This physical ticket has not been sold/activated yet.'], 422);
        }

        // Same atomic guard the scanner uses: only a transition out of 'issued'
        // counts, so admitting twice (or racing the door) can't double-admit.
        $affected = $this->db->run(
            "UPDATE tickets
                SET status = 'redeemed', redeemed_at = NOW(),
                    redeemed_by_user_id = :uid, redeemed_via_scanner_id = NULL,
                    physical_status = IF(delivery_method = 'physical', 'checked_in', physical_status)
              WHERE id = :id AND event_id = :eid AND status = 'issued'",
            [':uid' => $this->userId(), ':id' => $ticketId, ':eid' => $eventId]
        );

        if ($affected !== 1) {
            return Response::json(['error' => 'This ticket was already scanned in.'], 409);
        }

        $this->db->run(
            'INSERT INTO ticket_scans
                (ticket_id, event_id, result, scanner_link_id, scanned_by_user_id, ip, user_agent)
             VALUES (?, ?, ?, NULL, ?, ?, ?)',
            [
                $ticketId,
                $eventId,
                'manual_admit',
                $this->userId(),
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]
        );

        log_activity($this->db, $eventId, $this->userId(), 'ticket marked used', [
            'ticket_id' => $ticketId,
            'code'      => (string) $ticket['code'],
        ]);

        return $this->ok(['ok' => true, 'status' => 'redeemed']);
    }

    private function listTickets(int $eventId): Response
    {
        $rows = $this->db->all(
            "SELECT t.id, t.code, t.token, t.holder_name, t.holder_email, t.status, t.redeemed_at,
                    tt.name AS tier_name, COALESCE(o.is_comp, 0) AS is_comp
               FROM tickets t
               JOIN ticket_types tt ON tt.id = t.ticket_type_id
               LEFT JOIN ticket_orders o ON o.id = t.order_id
              WHERE t.event_id = ?
              ORDER BY t.id DESC",
            [$eventId]
        );
        $tickets = array_map(fn (array $r) => [
            'id'           => (int) $r['id'],
            'code'         => (string) $r['code'],
            'tier'         => (string) $r['tier_name'],
            'holder_name'  => $r['holder_name'],
            'holder_email' => $r['holder_email'],
            'status'       => (string) $r['status'],
            'is_comp'      => (bool) (int) $r['is_comp'],
            'redeemed_at'  => $r['redeemed_at'],
            'url'          => $r['token'] !== null ? $this->ticketUrl((string) $r['token']) : null,
        ], $rows);
        return $this->ok(['tickets' => $tickets]);
    }

    private function resendTicket(int $eventId, array $ticket): Response
    {
        $email = trim((string) ($ticket['holder_email'] ?? ''));
        if ($email === '') {
            return Response::json(['error' => 'This ticket has no holder email to send to.'], 422);
        }
        if (empty($ticket['token'])) {
            return Response::json(['error' => 'This ticket predates token storage and cannot be resent.'], 409);
        }
        $emailed = $this->emailTickets($eventId, $email, $ticket['holder_name'], [[
            'id'    => (int) $ticket['id'],
            'code'  => (string) $ticket['code'],
            'token' => (string) $ticket['token'],
        ]]);
        log_activity($this->db, $eventId, $this->userId(), 'ticket resent', ['ticket_id' => (int) $ticket['id']]);
        return $this->ok(['emailed' => $emailed]);
    }

    private function voidIssuedTicket(int $eventId, array $ticket): Response
    {
        if ((string) $ticket['status'] === 'void') {
            return $this->ok(['ok' => true]);
        }
        (new TicketingService())->voidTicket($this->db, (int) $ticket['id'], $this->userId());
        log_activity($this->db, $eventId, $this->userId(), 'ticket voided', [
            'ticket_id' => (int) $ticket['id'],
            'code'      => (string) $ticket['code'],
        ]);
        return $this->ok(['ok' => true]);
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
            'SELECT id, title, ticketing_mode, ticket_url, ticket_system, capacity, public_slug FROM events WHERE id = ?',
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
                // Relative path to the public ticket page, so the admin UI can
                // build discount-code share links without hardcoding the URL
                // shape (see event_public_path()).
                'public_page'    => event_public_path($event),
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

    // ─── /ticketing/discounts — private discount codes ─────────────────────────────

    /**
     *   GET    /ticketing/discounts        list codes with live redemption stats
     *   POST   /ticketing/discounts        create a code
     *   PATCH  /ticketing/discounts/{id}   update a code
     *   DELETE /ticketing/discounts/{id}   delete a code (only if never redeemed)
     *
     * Codes are private by construction: nothing here is echoed by the public
     * ticket surface, which only ever confirms a code someone already typed.
     * The money math itself lives in the shared Panic\TicketDiscounts service.
     */
    private function discounts(Request $request, int $eventId, ?int $codeId): Response
    {
        if ($codeId === null) {
            return match ($request->method()) {
                'GET'   => $this->listDiscounts($eventId),
                'POST'  => $this->createDiscount($request, $eventId),
                default => Response::methodNotAllowed(),
            };
        }

        $code = $this->db->one(
            'SELECT * FROM ticket_discount_codes WHERE id = ? AND event_id = ?',
            [$codeId, $eventId]
        );
        if (!$code) {
            return $this->notFound('Discount code not found');
        }

        return match ($request->method()) {
            'PATCH'  => $this->updateDiscount($request, $eventId, $code),
            'DELETE' => $this->deleteDiscount($eventId, $code),
            default  => Response::methodNotAllowed(),
        };
    }

    private function listDiscounts(int $eventId): Response
    {
        $rows = $this->db->all(
            'SELECT * FROM ticket_discount_codes WHERE event_id = ? ORDER BY created_at DESC, id DESC',
            [$eventId]
        );

        $codes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];

            // What this code has actually cost, in real money. Only settled
            // orders count — a pending hold hasn't given anything away yet.
            $realized = $this->db->one(
                "SELECT COUNT(*) AS orders,
                        COALESCE(SUM(discount_cents), 0) AS given_cents
                   FROM ticket_orders
                  WHERE discount_code_id = ? AND status IN ('paid', 'fulfilled')",
                [$id]
            );

            $codes[] = [
                'id'               => $id,
                'code'             => (string) $row['code'],
                'label'            => $row['label'],
                'kind'             => (string) $row['kind'],
                'percent_off'      => (int) $row['percent_off'],
                'amount_off_cents' => (int) $row['amount_off_cents'],
                'description'      => TicketDiscounts::describe($row),
                'max_uses'         => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
                'once_per_email'   => (bool) (int) $row['once_per_email'],
                'starts_at'        => $row['starts_at'],
                'expires_at'       => $row['expires_at'],
                'status'           => (string) $row['status'],
                'ticket_type_ids'  => TicketDiscounts::scopedTypeIds($this->db, $id),
                // Live count including unexpired holds — the same number the
                // public surface enforces max_uses against, so the admin sees
                // exactly what a buyer would hit.
                'uses'             => TicketDiscounts::redemptionCount($this->db, $id),
                'orders'           => (int) ($realized['orders'] ?? 0),
                'given_cents'      => (int) ($realized['given_cents'] ?? 0),
            ];
        }

        return $this->ok(['discount_codes' => $codes]);
    }

    private function createDiscount(Request $request, int $eventId): Response
    {
        $b = $request->body();

        $code = TicketDiscounts::normalizeCode((string) ($b['code'] ?? ''));
        if ($code === '') {
            return Response::json(['error' => 'A code is required.'], 422);
        }
        if (!preg_match('/^[A-Z0-9._-]+$/', $code)) {
            return Response::json([
                'error' => 'Codes may use letters, numbers, dot, dash and underscore only.',
            ], 422);
        }

        $exists = $this->db->one(
            'SELECT id FROM ticket_discount_codes WHERE event_id = ? AND code = ?',
            [$eventId, $code]
        );
        if ($exists) {
            return Response::json(['error' => "The code {$code} already exists for this event."], 409);
        }

        if ($invalid = $this->validateDiscountAmount($b)) {
            return $invalid;
        }

        $kind = ((string) ($b['kind'] ?? 'percent')) === TicketDiscounts::KIND_FIXED
            ? TicketDiscounts::KIND_FIXED
            : TicketDiscounts::KIND_PERCENT;

        $id = $this->db->insert(
            'INSERT INTO ticket_discount_codes
                (event_id, code, label, kind, percent_off, amount_off_cents,
                 max_uses, once_per_email, starts_at, expires_at, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $code,
                trim((string) ($b['label'] ?? '')) ?: null,
                $kind,
                max(0, min(100, (int) ($b['percent_off'] ?? 0))),
                max(0, (int) ($b['amount_off_cents'] ?? 0)),
                $this->nullableMaxUses($b['max_uses'] ?? null),
                !empty($b['once_per_email']) ? 1 : 0,
                date_or_null($b['starts_at'] ?? null),
                date_or_null($b['expires_at'] ?? null),
                ((string) ($b['status'] ?? 'active')) === 'disabled' ? 'disabled' : 'active',
                $this->userId(),
            ]
        );

        $this->syncDiscountTiers($eventId, $id, $b['ticket_type_ids'] ?? null);

        log_activity($this->db, $eventId, $this->userId(), 'discount code created', [
            'discount_code_id' => $id,
            'code'             => $code,
        ]);
        return $this->ok(['id' => $id, 'code' => $code]);
    }

    private function updateDiscount(Request $request, int $eventId, array $existing): Response
    {
        $codeId = (int) $existing['id'];
        $b = $request->body();

        // The code string itself is deliberately immutable: it has already been
        // emailed to people, and renaming it would silently break every link
        // and message already out in the world. Disable it and make a new one.
        if ($invalid = $this->validateDiscountAmount($b, $existing)) {
            return $invalid;
        }

        $kind = array_key_exists('kind', $b)
            ? (((string) $b['kind']) === TicketDiscounts::KIND_FIXED
                ? TicketDiscounts::KIND_FIXED
                : TicketDiscounts::KIND_PERCENT)
            : (string) $existing['kind'];

        $this->db->run(
            'UPDATE ticket_discount_codes
                SET label = ?, kind = ?, percent_off = ?, amount_off_cents = ?,
                    max_uses = ?, once_per_email = ?, starts_at = ?, expires_at = ?, status = ?
              WHERE id = ? AND event_id = ?',
            [
                array_key_exists('label', $b) ? (trim((string) $b['label']) ?: null) : $existing['label'],
                $kind,
                array_key_exists('percent_off', $b)
                    ? max(0, min(100, (int) $b['percent_off']))
                    : (int) $existing['percent_off'],
                array_key_exists('amount_off_cents', $b)
                    ? max(0, (int) $b['amount_off_cents'])
                    : (int) $existing['amount_off_cents'],
                array_key_exists('max_uses', $b)
                    ? $this->nullableMaxUses($b['max_uses'])
                    : ($existing['max_uses'] !== null ? (int) $existing['max_uses'] : null),
                array_key_exists('once_per_email', $b)
                    ? (!empty($b['once_per_email']) ? 1 : 0)
                    : (int) $existing['once_per_email'],
                array_key_exists('starts_at', $b) ? date_or_null($b['starts_at']) : $existing['starts_at'],
                array_key_exists('expires_at', $b) ? date_or_null($b['expires_at']) : $existing['expires_at'],
                array_key_exists('status', $b)
                    ? (((string) $b['status']) === 'disabled' ? 'disabled' : 'active')
                    : (string) $existing['status'],
                $codeId,
                $eventId,
            ]
        );

        if (array_key_exists('ticket_type_ids', $b)) {
            $this->syncDiscountTiers($eventId, $codeId, $b['ticket_type_ids']);
        }

        log_activity($this->db, $eventId, $this->userId(), 'discount code updated', [
            'discount_code_id' => $codeId,
            'code'             => (string) $existing['code'],
        ]);
        return $this->ok(['ok' => true]);
    }

    private function deleteDiscount(int $eventId, array $existing): Response
    {
        $codeId = (int) $existing['id'];

        // Once a code has been redeemed it is part of the financial record:
        // deleting it would null it off those orders and lose the answer to
        // "why was this order cheaper?". Disabling stops all future use and
        // costs nothing, so steer there instead.
        $used = $this->db->one(
            "SELECT COUNT(*) AS n FROM ticket_orders
              WHERE discount_code_id = ? AND status IN ('paid', 'fulfilled', 'refunded')",
            [$codeId]
        );
        if ((int) ($used['n'] ?? 0) > 0) {
            return Response::json([
                'error' => 'This code has been redeemed and is part of the sales record. Disable it instead.',
            ], 409);
        }

        $this->db->run('DELETE FROM ticket_discount_codes WHERE id = ? AND event_id = ?', [$codeId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'discount code deleted', [
            'discount_code_id' => $codeId,
            'code'             => (string) $existing['code'],
        ]);
        return $this->ok(['ok' => true]);
    }

    /**
     * Reject a code that would discount nothing — a 0% or $0 code silently
     * "works" at checkout while giving the buyer no reason to have typed it.
     */
    private function validateDiscountAmount(array $b, ?array $existing = null): ?Response
    {
        $kind = array_key_exists('kind', $b)
            ? (string) $b['kind']
            : (string) ($existing['kind'] ?? 'percent');

        if ($kind === TicketDiscounts::KIND_FIXED) {
            $cents = array_key_exists('amount_off_cents', $b)
                ? (int) $b['amount_off_cents']
                : (int) ($existing['amount_off_cents'] ?? 0);
            if ($cents <= 0) {
                return Response::json(['error' => 'Enter an amount greater than zero.'], 422);
            }
            return null;
        }

        $pct = array_key_exists('percent_off', $b)
            ? (int) $b['percent_off']
            : (int) ($existing['percent_off'] ?? 0);
        if ($pct < 1 || $pct > 100) {
            return Response::json(['error' => 'Enter a percentage between 1 and 100.'], 422);
        }
        return null;
    }

    /** max_uses: null/empty/0 all mean "unlimited". */
    private function nullableMaxUses(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * Replace a code's tier scoping. An empty/absent list means "every tier",
     * which is stored as zero rows rather than a row per tier so that a tier
     * added later is automatically covered.
     */
    private function syncDiscountTiers(int $eventId, int $codeId, mixed $rawIds): void
    {
        $this->db->run('DELETE FROM ticket_discount_code_types WHERE discount_code_id = ?', [$codeId]);

        if (!is_array($rawIds) || $rawIds === []) {
            return;
        }

        foreach (array_unique(array_map('intval', $rawIds)) as $typeId) {
            if ($typeId <= 0) {
                continue;
            }
            // Confirm the tier belongs to this event before linking it, so a
            // crafted payload can't scope a code onto somebody else's event.
            $owns = $this->db->one(
                'SELECT id FROM ticket_types WHERE id = ? AND event_id = ?',
                [$typeId, $eventId]
            );
            if (!$owns) {
                continue;
            }
            $this->db->run(
                'INSERT IGNORE INTO ticket_discount_code_types (discount_code_id, ticket_type_id) VALUES (?, ?)',
                [$codeId, $typeId]
            );
        }
    }

    // ─── /ticketing/comp ───────────────────────────────────────────────────────────

    private function comp(Request $request, int $eventId): Response
    {
        $b = $request->body();

        // Guest-list-driven comp: issue (or resend) comps for a guest entry.
        $guestId = (int) ($b['guest_list_id'] ?? 0);
        if ($guestId > 0) {
            return $this->compGuest($eventId, $guestId, !empty($b['resend']));
        }

        // Direct comp from the admin form: explicit tier + quantity.
        $typeId = (int) ($b['ticket_type_id'] ?? 0);
        $quantity = max(1, (int) ($b['quantity'] ?? 1));
        $holderName = isset($b['holder_name']) ? trim((string) $b['holder_name']) ?: null : null;
        $holderEmail = isset($b['holder_email']) ? trim((string) $b['holder_email']) ?: null : null;

        $type = $this->db->one('SELECT id, name FROM ticket_types WHERE id = ? AND event_id = ?', [$typeId, $eventId]);
        if (!$type) {
            return $this->notFound('Ticket type not found');
        }

        try {
            $tickets = (new TicketingService())->issueComp($this->db, $typeId, $quantity, $holderName, $holderEmail, $this->userId());
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }
        $emailed = $holderEmail ? $this->emailTickets($eventId, $holderEmail, $holderName, $tickets) : 0;

        log_activity($this->db, $eventId, $this->userId(), 'comp tickets issued', [
            'ticket_type_id' => $typeId,
            'quantity'       => count($tickets),
            'holder_email'   => $holderEmail,
        ]);

        return $this->ok([
            'issued'  => count($tickets),
            'emailed' => $emailed,
            'tickets' => $this->ticketSummaries($tickets),
        ]);
    }

    /**
     * Issue (or resend) complimentary tickets for a guest-list entry. Sized to
     * the guest's party_size, drawn from the event's comp tier, mailed to the
     * guest's email, and linked back to the guest row via comp_order_id so the
     * QR can be re-viewed and resent later.
     */
    private function compGuest(int $eventId, int $guestId, bool $resend): Response
    {
        $guest = $this->db->one(
            'SELECT id, name, email, party_size, comp_order_id FROM event_guest_list WHERE id = ? AND event_id = ?',
            [$guestId, $eventId]
        );
        if (!$guest) {
            return $this->notFound('Guest not found');
        }
        $email = trim((string) ($guest['email'] ?? ''));
        if ($email === '') {
            return Response::json(['error' => 'Add an email to this guest before comping.'], 422);
        }
        $name = (string) ($guest['name'] ?? '') ?: null;

        // Already comped (or explicit resend): re-email the existing tickets.
        if ($resend || !empty($guest['comp_order_id'])) {
            $orderId = (int) ($guest['comp_order_id'] ?? 0);
            if ($orderId <= 0) {
                return Response::json(['error' => 'This guest has not been comped yet.'], 422);
            }
            $tickets = $this->ticketsForOrder($orderId);
            $emailed = $this->emailTickets($eventId, $email, $name, $tickets);
            log_activity($this->db, $eventId, $this->userId(), 'guest comp resent', ['guest_list_id' => $guestId, 'count' => count($tickets)]);
            return $this->ok(['issued' => 0, 'emailed' => $emailed, 'resent' => true, 'tickets' => $this->ticketSummaries($tickets)]);
        }

        $typeId = $this->compTierId($eventId);
        if ($typeId === null) {
            return Response::json(['error' => 'No ticket type to comp from — turn on in-house ticketing first.'], 409);
        }
        $quantity = max(1, (int) ($guest['party_size'] ?? 1));

        try {
            $tickets = (new TicketingService())->issueComp($this->db, $typeId, $quantity, $name, $email, $this->userId());
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }

        $orderId = (int) ($this->db->one('SELECT order_id FROM tickets WHERE id = ?', [$tickets[0]['id']])['order_id'] ?? 0);
        $this->db->run('UPDATE event_guest_list SET comp_order_id = ? WHERE id = ? AND event_id = ?', [$orderId, $guestId, $eventId]);
        $emailed = $this->emailTickets($eventId, $email, $name, $tickets);

        log_activity($this->db, $eventId, $this->userId(), 'guest comped', [
            'guest_list_id' => $guestId,
            'quantity'      => count($tickets),
        ]);

        return $this->ok(['issued' => count($tickets), 'emailed' => $emailed, 'tickets' => $this->ticketSummaries($tickets)]);
    }

    /** Preferred comp source: the seeded "Comps" tier, else any tier. */
    private function compTierId(int $eventId): ?int
    {
        $row = $this->db->one(
            "SELECT id FROM ticket_types WHERE event_id = ?
             ORDER BY (name = 'Comps') DESC, sort_order ASC, id ASC LIMIT 1",
            [$eventId]
        );
        return $row ? (int) $row['id'] : null;
    }

    /** Non-void tickets for an order, with stored tokens for resend/view. */
    private function ticketsForOrder(int $orderId): array
    {
        $rows = $this->db->all(
            "SELECT id, code, token, holder_email, holder_name
               FROM tickets WHERE order_id = ? AND status <> 'void' ORDER BY id ASC",
            [$orderId]
        );
        return array_map(static fn (array $r) => [
            'id'           => (int) $r['id'],
            'code'         => (string) $r['code'],
            'token'        => $r['token'] !== null ? (string) $r['token'] : null,
            'holder_email' => $r['holder_email'],
            'holder_name'  => $r['holder_name'],
        ], $rows);
    }

    /** Shape issued/looked-up tickets for the client, with a viewable QR URL. */
    private function ticketSummaries(array $tickets): array
    {
        return array_map(fn (array $t) => [
            'id'   => $t['id'],
            'code' => $t['code'],
            'url'  => !empty($t['token']) ? $this->ticketUrl((string) $t['token']) : null,
        ], $tickets);
    }

    /** Public ticket-view URL (carries the scannable token) for a ticket. */
    private function ticketUrl(string $token): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/t/' . rawurlencode($token);
    }

    /**
     * Email each issued ticket's redemption link (carrying the one-time secret
     * token). Returns the count delivered. A QR is generated client-side from
     * the link; here we send the scannable link itself.
     */
    private function emailTickets(int $eventId, string $email, ?string $name, array $tickets): int
    {
        $event  = $this->db->one(
            'SELECT e.title, v.name AS venue_name, v.address AS venue_address, v.city AS venue_city,
                    v.state AS venue_state, r.address AS room_address
               FROM events e
               LEFT JOIN venues v ON v.id = e.venue_id
               LEFT JOIN resources r ON r.id = e.resource_id
              WHERE e.id = ?',
            [$eventId]
        );
        $title     = (string) ($event['title'] ?? 'the event');
        $appUrl    = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $venueLine = Address::line(
            $event['venue_name'] ?? null,
            $event['room_address'] ?? null,
            $event['venue_address'] ?? null,
            $event['venue_city'] ?? null,
            $event['venue_state'] ?? null
        );

        $textLines = [];
        $htmlItems = [];
        $inline    = [];   // Content-ID => raw PNG bytes for MIME multipart/related
        $n         = 0;
        foreach ($tickets as $t) {
            if (empty($t['token'])) {
                continue; // idempotent re-issue: no plaintext token to deliver.
            }
            $n++;
            $link     = "{$appUrl}/t/{$t['token']}";
            $code     = htmlspecialchars((string) $t['code'], ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

            // Generate QR PNG bytes directly (no HTTP round-trip) and embed as a
            // MIME CID attachment so the image is always present regardless of
            // whether the recipient's email client loads remote images.
            $cid      = 'qr-' . $n . '-' . bin2hex(random_bytes(6)) . '@' . (getenv('APP_HOST') ?: 'localhost');
            $pngBytes = \Panic\QrCode::generatePng((string) $t['token'], 300);
            if ($pngBytes !== '') {
                $inline[$cid] = $pngBytes;
                $qrSrc = 'cid:' . $cid;
            } else {
                // Fallback: external URL (e.g. if GD unavailable).
                $qrSrc = htmlspecialchars(
                    "{$appUrl}/assets/qr.png?text=" . rawurlencode((string) $t['token']) . '&size=300',
                    ENT_QUOTES, 'UTF-8'
                );
            }

            $textLines[] = "  {$t['code']}  View ticket + QR:  {$link}";
            $htmlItems[] = '<div style="padding:16px 0;border-bottom:1px solid #2e2929;">'
                . '<div style="font-size:13px;color:#a9a097;letter-spacing:1px;text-transform:uppercase;">Ticket</div>'
                . '<div style="margin-top:4px;font-size:16px;font-weight:bold;color:#fff;">' . $code . '</div>'
                . '<div style="margin-top:14px;text-align:center;">'
                . '<a href="' . $safeLink . '" style="display:inline-block;line-height:0;border:2px solid #3a3434;border-radius:4px;">'
                . '<img src="' . $qrSrc . '" alt="QR code — tap to open your ticket" width="200" height="200"'
                . ' style="display:block;background:#ffffff;padding:10px;">'
                . '</a>'
                . '</div>'
                . '<div style="margin-top:8px;font-size:13px;color:#b5aba2;text-align:center;">'
                . 'Screenshot or save this QR &mdash; show it at the door to get in.'
                . '</div>'
                . '<div style="margin-top:10px;font-size:13px;">'
                . '<a href="' . $safeLink . '" style="color:#c9b27e;font-weight:bold;">View your ticket &amp; QR &rarr;</a>'
                . '</div></div>';
        }
        if ($textLines === []) {
            return 0;
        }

        $greeting     = $name ? "Hi {$name}," : 'Hello,';
        $greetingHtml = $name
            ? 'Hi <strong style="color:#fff;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,'
            : 'Hello,';

        (new Mailer($this->root, $this->db))->sendTemplate(
            $email,
            "Your comp tickets for {$title}",
            'comp-tickets',
            [
                'event_title'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'greeting'     => $greetingHtml,
                'tickets_html' => implode('', $htmlItems),
                'tickets_text' => implode("\n", $textLines) . "\n",
                'venue_line'   => htmlspecialchars($venueLine, ENT_QUOTES, 'UTF-8'),
            ],
            $inline
        );
        return count($textLines);
    }

    // ─── /ticketing/print-batches — physical ticket batch printing ─────────────────
    //
    // Batch CREATION (real `tickets` rows, unique tokens/numbers) is fully
    // separate from PDF RENDERING: PhysicalTicketBatchService does the
    // former inside one transaction; PhysicalTicketPdfGenerator does the
    // latter, purely by reading whatever rows already exist — it never
    // writes to the database. That split is what makes "Regenerate PDF" and
    // the original "Download PDF" the exact same request (mode/sheet query
    // params only — no separate regenerate action) and what makes retrying
    // PDF generation incapable of ever creating a duplicate ticket.
    //
    //   GET  /ticketing/print-batches                 list this event's batches (+ live counts)
    //   POST /ticketing/print-batches                 create a batch (real ticket rows)
    //   GET  /ticketing/print-batches/{id}             batch detail (+ live counts)
    //   GET  /ticketing/print-batches/{id}?action=pdf&mode=individual|imposed&sheet=letter|11x17|12x18
    //                                                  download/regenerate the print-ready PDF
    //   GET  /ticketing/print-batches/{id}?action=manifest
    //                                                  admin-only debug manifest ({ticket_number,token,url})

    private function printBatches(Request $request, int $eventId, ?int $batchId): Response
    {
        if ($batchId === null) {
            return match ($request->method()) {
                'GET'   => $this->listPrintBatches($eventId),
                'POST'  => $this->createPrintBatch($request, $eventId),
                default => Response::methodNotAllowed(),
            };
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        return match (strtolower(trim((string) $request->query('action', '')))) {
            'pdf'      => $this->downloadPrintBatchPdf($request, $eventId, $batchId),
            'manifest' => $this->printBatchManifest($eventId, $batchId),
            default    => $this->printBatchDetail($eventId, $batchId),
        };
    }

    private function listPrintBatches(int $eventId): Response
    {
        $rows = $this->db->all(
            'SELECT b.*, tt.name AS ticket_type_name
               FROM physical_ticket_batches b
               JOIN ticket_types tt ON tt.id = b.ticket_type_id
              WHERE b.event_id = ?
              ORDER BY b.id DESC',
            [$eventId]
        );
        return $this->ok(['batches' => array_map(fn(array $b) => $this->batchSummary($b), $rows)]);
    }

    private function printBatchDetail(int $eventId, int $batchId): Response
    {
        $row = $this->batchRow($eventId, $batchId);
        if ($row === null) {
            return $this->notFound('Physical ticket batch not found');
        }
        return $this->ok(['batch' => $this->batchSummary($row)]);
    }

    private function batchRow(int $eventId, int $batchId): ?array
    {
        return $this->db->one(
            'SELECT b.*, tt.name AS ticket_type_name
               FROM physical_ticket_batches b
               JOIN ticket_types tt ON tt.id = b.ticket_type_id
              WHERE b.id = ? AND b.event_id = ?',
            [$batchId, $eventId]
        );
    }

    /**
     * Shape one batch row for the client, with live issued/sold/checked_in/
     * remaining counts computed from `tickets` (not cached anywhere — a
     * batch's counts change as tickets get sold/checked in over time).
     */
    private function batchSummary(array $b): array
    {
        $batchId = (int) $b['id'];
        $counts = $this->db->one(
            "SELECT COUNT(*) AS issued,
                    COALESCE(SUM(physical_status IN ('sold', 'checked_in')), 0) AS sold,
                    COALESCE(SUM(physical_status = 'checked_in'), 0) AS checked_in
               FROM tickets WHERE physical_batch_id = ?",
            [$batchId]
        );
        $issued = (int) ($counts['issued'] ?? 0);
        $sold   = (int) ($counts['sold'] ?? 0);

        return [
            'id'                  => $batchId,
            'name'                => $b['name'],
            'ticket_type_id'      => (int) $b['ticket_type_id'],
            'ticket_type_name'    => (string) $b['ticket_type_name'],
            'quantity'            => (int) $b['quantity'],
            'first_ticket_number' => (int) $b['first_ticket_number'],
            'last_ticket_number'  => (int) $b['last_ticket_number'],
            'number_pad_width'    => (int) $b['number_pad_width'],
            'seller_label'        => $b['seller_label'],
            'ticket_width_in'     => (float) $b['ticket_width_in'],
            'ticket_height_in'    => (float) $b['ticket_height_in'],
            'bleed_in'            => (float) $b['bleed_in'],
            'crop_marks'          => (bool) (int) $b['crop_marks'],
            'has_artwork'         => !empty($b['artwork_path']),
            'created_at'          => $b['created_at'],
            'status'              => (string) $b['status'],
            'issued'              => $issued,
            'sold'                => $sold,
            'remaining'           => max(0, $issued - $sold),
            'checked_in'          => (int) ($counts['checked_in'] ?? 0),
        ];
    }

    private function createPrintBatch(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $typeId = (int) ($b['ticket_type_id'] ?? 0);
        $type = $this->db->one('SELECT id FROM ticket_types WHERE id = ? AND event_id = ?', [$typeId, $eventId]);
        if (!$type) {
            return $this->notFound('Ticket type not found');
        }

        $service = new PhysicalTicketBatchService();
        try {
            $result = $service->createBatch($this->db, [
                'ticket_type_id'      => $typeId,
                'quantity'            => (int) ($b['quantity'] ?? 100),
                'first_ticket_number' => (int) ($b['first_ticket_number'] ?? 1),
                'number_pad_width'    => (int) ($b['number_pad_width'] ?? 6),
                'name'                => $b['name'] ?? null,
                'seller_label'        => $b['seller_label'] ?? null,
                'ticket_width_in'     => isset($b['ticket_width_in']) ? (float) $b['ticket_width_in'] : 2.0,
                'ticket_height_in'    => isset($b['ticket_height_in']) ? (float) $b['ticket_height_in'] : 5.5,
                'bleed_in'            => isset($b['bleed_in']) ? (float) $b['bleed_in'] : 0.125,
                'crop_marks'          => !empty($b['crop_marks']),
            ], $this->userId());
        } catch (PhysicalTicketRangeCollisionException | PhysicalTicketOversellException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }

        log_activity($this->db, $eventId, $this->userId(), 'physical ticket batch created', [
            'batch_id'            => $result['batch_id'],
            'ticket_type_id'      => $typeId,
            'quantity'            => $result['quantity'],
            'first_ticket_number' => $result['first_ticket_number'],
            'last_ticket_number'  => $result['last_ticket_number'],
        ]);

        $row = $this->batchRow($eventId, $result['batch_id']);
        return $this->ok(['batch' => $row !== null ? $this->batchSummary($row) : null]);
    }

    private function downloadPrintBatchPdf(Request $request, int $eventId, int $batchId): Response
    {
        $mode  = strtolower(trim((string) $request->query('mode', 'individual')));
        $sheet = strtolower(trim((string) $request->query('sheet', 'letter')));
        $appUrl = (string) (getenv('APP_URL') ?: '');

        $generator = new PhysicalTicketPdfGenerator();
        try {
            $out = $generator->generate($this->db, $eventId, $batchId, $mode, $sheet, $appUrl);
        } catch (PhysicalTicketValidationException $e) {
            // A print-safety assertion failed (see the generator's validate())
            // — never hand out a PDF that didn't pass. Logged for follow-up;
            // this should not normally happen for a batch this endpoint itself
            // created, since layout is deterministic from fixed ticket dims.
            error_log("Physical ticket batch {$batchId} PDF failed print validation: " . $e->getMessage());
            return Response::json(['error' => 'Could not generate a print-safe PDF: ' . $e->getMessage()], 500);
        } catch (\RuntimeException $e) {
            return $this->notFound($e->getMessage());
        }

        log_activity($this->db, $eventId, $this->userId(), 'physical ticket batch pdf downloaded', [
            'batch_id' => $batchId,
            'mode'     => $mode,
            'sheet'    => $sheet,
        ]);

        // A batch PDF carries every ticket's live QR credential — same
        // no-store/no-referrer treatment as the payment-receipt PDF download.
        return Response::download($out['bytes'], $out['filename'], 'application/pdf')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Admin-only debug manifest ({ticket_number, token, url} per ticket).
     * Gated by the same manage_ticketing capability as every other action on
     * this endpoint — this app has no separate APP_DEBUG/env-flag convention
     * to additionally gate behind (see PhysicalTicketPdfGenerator::manifest()'s
     * docblock), and admin auth is already sufficient: the plaintext token is
     * equally visible to this same role via the existing ticket list/resend
     * surfaces above. Never bundled into the print PDF itself.
     */
    private function printBatchManifest(int $eventId, int $batchId): Response
    {
        $appUrl = (string) (getenv('APP_URL') ?: '');
        $generator = new PhysicalTicketPdfGenerator();
        try {
            $manifest = $generator->manifest($this->db, $eventId, $batchId, $appUrl);
        } catch (\RuntimeException $e) {
            return $this->notFound($e->getMessage());
        }
        return $this->ok(['manifest' => $manifest]);
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
            $provider = null;

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

            // Some processors adjust fees after a refund. Refresh the exact
            // provider figures now; the reconciliation command can retry later.
            if ($provider !== null && $paymentRef !== '') {
                (new \Panic\Payments\FinancialReconciler($this->db))->reconcileTicketOrder($provider, $order);
            }

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

        // Turning on in-house ticketing for a fresh event seeds the default
        // setup so the operator can sell + scan immediately: a paid General
        // Admission tier (capacity − comp reserve), a held-back Comps allocation,
        // and a "Door" scanner link. Only on a genuinely fresh event (no tiers).
        $seeded = $newMode === 'internal' ? $this->seedDefaultTicketType($eventId) : false;
        $seededLink = $seeded ? $this->seedDefaultScannerLink($eventId) : false;

        // Re-read for the caller.
        $event = $this->db->one('SELECT ticketing_mode, ticket_url, ticket_system FROM events WHERE id = ?', [$eventId]);
        return $this->ok([
            'event' => $event,
            'seeded_default_type' => $seeded,
            'seeded_scanner_link' => $seededLink,
        ]);
    }

    /**
     * Seed the default ticket types for an event the first time it switches to
     * in-house ticketing: two paid tiers — "Advance" and "Door" (both priced
     * from the event's ticket_price, splitting capacity minus the comp reserve
     * evenly) — plus a held-back "Comps" allocation of {@see DEFAULT_COMP_RESERVE}
     * free, off-sale tickets. The paid tiers open for sale yesterday and stay
     * open through the day after the event, so they are unambiguously live the
     * moment ticketing is enabled and leave a buffer for late sales. No-op —
     * returns false — if any ticket type already exists, so it never duplicates
     * on re-save.
     */
    private function seedDefaultTicketType(int $eventId): bool
    {
        $existing = $this->db->one('SELECT COUNT(*) AS n FROM ticket_types WHERE event_id = ?', [$eventId]);
        if ((int) ($existing['n'] ?? 0) > 0) {
            return false;
        }

        $event = $this->db->one(
            'SELECT e.ticket_price, e.capacity, e.`date`, v.timezone AS venue_timezone
               FROM events e LEFT JOIN venues v ON v.id = e.venue_id
              WHERE e.id = ?',
            [$eventId]
        );
        if ($event === null) {
            return false;
        }

        $priceCents = (int) round(((float) ($event['ticket_price'] ?? 0)) * 100);
        $capacity   = (int) ($event['capacity'] ?? 0);

        // Split the room into two paid tiers (Advance + Door) plus a held-back
        // house-comp reserve: Comps = 20 (capped at a tiny capacity), the rest
        // split evenly between Advance and Door. With no capacity set, fall back
        // to 100 sellable seats (50/50) so there is still something to sell.
        $compQty    = $capacity > 0 ? min(self::DEFAULT_COMP_RESERVE, $capacity) : self::DEFAULT_COMP_RESERVE;
        $sellable   = $capacity > 0 ? max(0, $capacity - $compQty) : 100;
        $advanceQty = intdiv($sellable, 2) + ($sellable % 2); // odd seat goes to Advance
        $doorQty    = intdiv($sellable, 2);

        // sales_start/sales_end are stored as true UTC instants (the DB session
        // is pinned to UTC, per Database.php), so every wall-clock boundary here
        // must be built in the venue's local timezone and converted to UTC
        // before formatting for insertion -- mirrors Feed::eventBounds().
        try {
            $tz = new \DateTimeZone((string) ($event['venue_timezone'] ?: 'America/Los_Angeles'));
        } catch (\Exception) {
            $tz = new \DateTimeZone('America/Los_Angeles');
        }
        $utc = new \DateTimeZone('UTC');
        $toUtc = fn(string $localDateTime): string =>
            (new \DateTime($localDateTime, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');

        // Sales open yesterday (so the tier is unambiguously live the moment the
        // operator flips ticketing on, regardless of timezone) and stay open
        // until the day after the event, leaving a buffer for at-the-door sales.
        $salesStart = $toUtc(date('Y-m-d', strtotime('yesterday')) . ' 00:00:00');
        $salesEnd   = !empty($event['date'])
            ? $toUtc(date('Y-m-d', strtotime($event['date'] . ' +1 day')) . ' 23:59:59')
            : null;
        // Door sales default to opening the day of the event (fall back to the
        // shared start if the event has no date yet).
        $doorSalesStart = !empty($event['date'])
            ? $toUtc(date('Y-m-d', strtotime($event['date'])) . ' 00:00:00')
            : $salesStart;

        $insertType = fn(string $name, ?string $desc, int $cents, int $qty, ?string $start, ?string $end, string $status, int $sort): int =>
            $this->db->insert(
                'INSERT INTO ticket_types
                    (event_id, name, description, price_cents, currency, quantity_total,
                     sales_start, sales_end, status, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$eventId, $name, $desc, $cents, 'USD', $qty, $start, $end, $status, $sort]
            );

        $advanceId = $insertType('Advance', 'Advance online sales', $priceCents, $advanceQty, $salesStart, $salesEnd, 'on_sale', 0);
        $doorId    = $insertType('Door', 'At-the-door sales', $priceCents, $doorQty, $doorSalesStart, $salesEnd, 'on_sale', 1);
        // Comps: free and kept in 'draft' so they never appear on the public
        // sale page, but are still issuable from the comp flow.
        $compId    = $insertType('Comps', 'House complimentary allocation', 0, $compQty, null, null, 'draft', 2);

        log_activity($this->db, $eventId, $this->userId(), 'default ticket types seeded', [
            'advance_id'    => $advanceId,
            'advance_qty'   => $advanceQty,
            'door_id'       => $doorId,
            'door_qty'      => $doorQty,
            'comp_id'       => $compId,
            'comp_quantity' => $compQty,
            'price_cents'   => $priceCents,
        ]);
        return true;
    }

    /**
     * Seed a default "Door" scanner link so the operator has a working door-scan
     * URL/QR the moment in-house ticketing is enabled. No-op — returns false —
     * if any active (non-revoked) scanner link already exists.
     */
    private function seedDefaultScannerLink(int $eventId): bool
    {
        $existing = $this->db->one(
            'SELECT COUNT(*) AS n FROM event_scanner_links WHERE event_id = ? AND revoked_at IS NULL',
            [$eventId]
        );
        if ((int) ($existing['n'] ?? 0) > 0) {
            return false;
        }
        $id = \Panic\Scanner::mintLink($this->db, $eventId, 'Door', $this->userId());
        log_activity($this->db, $eventId, $this->userId(), 'default scanner link seeded', [
            'scanner_link_id' => $id,
            'label'           => 'Door',
        ]);
        return true;
    }
}
