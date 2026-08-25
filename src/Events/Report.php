<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Read-only, printable P&L / settlement report for a single event.
 *
 *   GET /api/events/{id}/report
 *
 * buildData() (the reusable core, split out from handle()) also backs the
 * token-gated public share link at GET /api/portal/view?token=... when the
 * token's kind is 'settlement_report' — see src/Portal.php. Same data,
 * same numbers, two entry points: one needs a staff login + view_settlement,
 * the other just needs the link.
 *
 * Combines Ledger::calculateSummary() (the same server-computed P&L used by
 * the Closeout tab — never recomputed differently here) with the cost detail
 * that usually explains *why* the ledger total looks the way it does: the
 * vendor bill, the staffing labor cost, the lineup payout terms, a
 * ticket-type sales breakdown, the raw ledger line items (so a printed
 * statement can reproduce Closeout's Revenue/Costs/Payments grouping
 * exactly), a payments-received/disbursed split, a payout-obligations net
 * (committed promoter_settlement/artist_guarantee cost entries minus
 * whatever's already been disbursed), and a fallback to the
 * manually-entered door-sales figures (event_settlements — now a collapsed
 * fallback section on the Closeout tab itself, not a separate Settlement
 * tab) for shows where tickets sold outside this app's own ticketing
 * module. Nothing here is editable — it's a reporting view over data owned
 * by Ledger, Vendors, Staffing, Lineup, Ticketing and Settlement.
 *
 * ── The "bottom line" ──────────────────────────────────────────────────────
 * A door/revenue-split deal (no room rental billed to the client) and a
 * flat-fee rental deal (client invoiced for the room) settle in opposite
 * directions, and a single "Gross Revenue − Payments Received" figure
 * conflates them: on a split deal the venue collects the door itself
 * (tickets/bar/etc. are never "billed" to anyone), so that formula reads
 * the whole door take as an unpaid client receivable even when ticket
 * sales fully covered staffing and the only real money movement left is a
 * payout *to* the promoter/artist. `bottomLineAmount`/`bottomLineType` fix
 * that by netting three genuinely distinct things:
 *   - clientReceivable: rental_fee/hosted_bar/equipment_rental/
 *     overtime_charge/other_revenue billed to the client, minus payments
 *     received — an actual invoice/deposit balance.
 *   - doorShortfall: non-split costs (staffing, security, production, etc.)
 *     exceeding door-collected revenue (tickets/ticket_fees/bar_sales/
 *     merch_share/sponsorship) — what the client owes to cover staffing
 *     when the draw was weak, net of ticket revenue. Zero whenever the door
 *     covered costs.
 *   - payoutStillOwed: the existing payout-obligations net (see below) —
 *     money the venue owes out to the promoter/artist.
 * Netting gives one sign-off number: positive means the client still owes
 * the venue (rental balance and/or staffing shortfall), negative means the
 * venue owes the promoter/artist their split, zero means settled.
 *
 * Capability: view_settlement (same gate as the Settlement + Closeout tabs).
 */
final class Report extends BaseEndpoint
{
    // Revenue the venue collects itself at the point of sale (box office,
    // bar) — never billed to the client/promoter, so it's not part of any
    // client receivable. Distinct from CLIENT_BILLED_REVENUE_CATEGORIES
    // below. See the "bottom line" doc block above.
    private const DOOR_REVENUE_CATEGORIES = [
        'tickets', 'ticket_fees', 'bar_sales', 'merch_share', 'sponsorship',
    ];

    // Revenue categories that represent a charge to the client/promoter
    // (room rental, a hosted-bar buyout, equipment, overtime, etc.) — these
    // are the only revenue lines that can leave a genuine invoice balance.
    private const CLIENT_BILLED_REVENUE_CATEGORIES = [
        'rental_fee', 'hosted_bar', 'equipment_rental', 'overtime_charge', 'other_revenue',
    ];

    // Cost categories that compete with door revenue for coverage — i.e.
    // everything except the revenue-split payout categories, which are
    // computed *from* what's left over rather than owed regardless of draw.
    private const NON_SPLIT_COST_CATEGORIES = [
        'labor', 'sound_production', 'security', 'cleaning', 'rentals',
        'catering', 'vendor_cost', 'processing_fees', 'taxes', 'refunds', 'other_cost',
    ];


    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'view_settlement')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $data = $this->buildData($eventId);
        if ($data === null) {
            return $this->notFound('Event not found');
        }

        return $this->ok($data);
    }

    /**
     * Builds the full report payload for one event — everything this
     * endpoint's handle() returns, minus the HTTP/capability plumbing.
     * Pulled out so Portal::view() can hand the exact same data to a
     * token-gated public "share this report" link (see src/Portal.php)
     * without duplicating any of this SQL/derivation logic. Returns null
     * if the event doesn't exist; callers own their own auth/capability
     * check before calling this (this method does not check any).
     */
    public function buildData(int $eventId): ?array
    {
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        if (!$event) {
            return null;
        }

        $summary  = (new Ledger($this->db, $this->auth, [], $this->root))->calculateSummary($eventId);
        $closeout = $this->db->one('SELECT * FROM event_closeout_state WHERE event_id = ?', [$eventId]);

        // event_settlements is a hand-typed door sheet — gross ticket sales
        // and ticket count (plus a few now-unused legacy columns from before
        // the Closeout ledger tracked costs/payouts properly) — kept for
        // shows where tickets sold through an outside service or at the
        // door rather than this app's own ticketing module. Entered on the
        // Closeout tab's "door sales" fallback section (there is no
        // standalone Settlement tab anymore). Surfaced here so the printed
        // statement can fall back to it (see tickets-sold below) and so
        // staff can see it was recorded at all.
        $manualSettlement = $this->db->one('SELECT * FROM event_settlements WHERE event_id = ?', [$eventId]);

        // Raw ledger line items (not just Ledger::calculateSummary()'s
        // per-category sums) so the printed report can reproduce the exact
        // same Revenue / Costs / Payments line-item breakdown the Closeout
        // tab shows, instead of a collapsed one-line-per-category total.
        $ledgerEntries = $this->db->all(
            "SELECT category, line_type, description, amount, created_at
             FROM event_ledger_entries
             WHERE event_id = ? AND is_void = 0
             ORDER BY created_at ASC, id ASC",
            [$eventId]
        );

        // ── Effective ticket count ────────────────────────────────────────
        // Ledger::calculateSummary()'s tickets_sold/gross_ticket_sales only
        // count in-house ticket_order_items rows, so a show sold entirely
        // through an outside ticketing service or at the door reads as 0
        // tickets sold here even though people clearly paid to get in. Fall
        // back to the manually-entered Settlement figure whenever the
        // in-house count is zero, and record which source won so the report
        // can label it rather than silently presenting a guess as fact.
        $ticketsSoldInHouse       = (int) ($summary['tickets_sold'] ?? 0);
        $grossTicketSalesInHouse  = (float) ($summary['gross_ticket_sales'] ?? 0);
        $ticketsSoldManual        = (int) ($manualSettlement['tickets_sold'] ?? 0);
        $grossTicketSalesManual   = (float) ($manualSettlement['gross_ticket_sales'] ?? 0);
        $ticketsSoldEffective      = $ticketsSoldInHouse > 0 ? $ticketsSoldInHouse : $ticketsSoldManual;
        $grossTicketSalesEffective = $grossTicketSalesInHouse > 0 ? $grossTicketSalesInHouse : $grossTicketSalesManual;
        $ticketSalesSource = $ticketsSoldInHouse > 0 ? 'box_office' : ($ticketsSoldManual > 0 ? 'door_or_manual' : null);

        // ── Payments & balance ─────────────────────────────────────────────
        // The ledger's "payment" line items are cash-movement entries,
        // distinct from the accrual revenue/cost lines above — they mix
        // money actually collected from the client (deposit_received,
        // invoice_payment, credit) with money already disbursed out the door
        // (artist_payout, promoter_payout, vendor_payout, staff_payout).
        // Splitting them lets the report show a real "balance due" instead
        // of one ambiguous "Payments Received" total.
        $incomingPaymentCategories = ['deposit_received', 'invoice_payment', 'credit'];
        $paymentsReceived = 0.0;
        $disbursed        = 0.0;
        $outstandingNoted = 0.0;
        foreach ($ledgerEntries as $entry) {
            if ($entry['line_type'] !== 'payment') {
                continue;
            }
            $amt = (float) $entry['amount'];
            if (in_array($entry['category'], $incomingPaymentCategories, true)) {
                $paymentsReceived += $amt;
            } elseif ($entry['category'] === 'outstanding_balance') {
                $outstandingNoted += $amt;
            } else {
                $disbursed += $amt;
            }
        }
        $balanceDue = (float) $summary['gross_revenue'] - $paymentsReceived;

        // ── Payout obligations (revenue-split / guarantee sign-off) ───────────
        // Separate from the client-billing balance above: a venue running a
        // door-split deal records the computed split as a Cost-type entry
        // (promoter_settlement / artist_guarantee — "what we've committed to
        // pay out"), then later records the actual disbursement as a
        // Payment-type entry (promoter_payout / artist_payout) once it's been
        // approved and paid. "Still owed" nets the two, so a report generated
        // *before* the payout entry exists shows the full committed amount —
        // the number that needs sign-off before the money goes out the door.
        $payoutPairs = [
            'promoter_settlement' => ['label' => 'Promoter', 'payout_category' => 'promoter_payout'],
            'artist_guarantee'    => ['label' => 'Artist',    'payout_category' => 'artist_payout'],
        ];
        $payoutObligations = [];
        foreach ($payoutPairs as $costCategory => $meta) {
            $committed = 0.0;
            $paidOut   = 0.0;
            foreach ($ledgerEntries as $entry) {
                if ($entry['line_type'] === 'cost' && $entry['category'] === $costCategory) {
                    $committed += (float) $entry['amount'];
                } elseif ($entry['line_type'] === 'payment' && $entry['category'] === $meta['payout_category']) {
                    $paidOut += (float) $entry['amount'];
                }
            }
            if ($committed > 0 || $paidOut > 0) {
                $payoutObligations[] = [
                    'label'      => $meta['label'],
                    'committed'  => $committed,
                    'disbursed'  => $paidOut,
                    'still_owed' => $committed - $paidOut,
                ];
            }
        }

        // ── Bottom line ────────────────────────────────────────────────────
        // See the class doc block for why "Gross Revenue − Payments Received"
        // alone is the wrong number for a door/revenue-split deal. Netting
        // these three below gives one sign-off figure that's correct for
        // both deal shapes.
        $doorRevenue         = 0.0;
        $clientBilledRevenue = 0.0;
        $nonSplitCosts       = 0.0;
        foreach ($ledgerEntries as $entry) {
            $amt = (float) $entry['amount'];
            if ($entry['line_type'] === 'revenue' && in_array($entry['category'], self::DOOR_REVENUE_CATEGORIES, true)) {
                $doorRevenue += $amt;
            } elseif ($entry['line_type'] === 'revenue' && in_array($entry['category'], self::CLIENT_BILLED_REVENUE_CATEGORIES, true)) {
                $clientBilledRevenue += $amt;
            } elseif ($entry['line_type'] === 'cost' && in_array($entry['category'], self::NON_SPLIT_COST_CATEGORIES, true)) {
                $nonSplitCosts += $amt;
            }
        }
        $clientReceivable   = max(0.0, $clientBilledRevenue - $paymentsReceived);
        $doorShortfall      = max(0.0, $nonSplitCosts - $doorRevenue);
        $payoutStillOwed    = array_sum(array_column($payoutObligations, 'still_owed'));
        $bottomLineAmount   = $clientReceivable + $doorShortfall - $payoutStillOwed;
        $bottomLineType     = match (true) {
            $bottomLineAmount > 0.005  => 'due_from_client',
            $bottomLineAmount < -0.005 => 'due_to_promoter',
            default                    => 'settled',
        };

        $vendors = $this->db->all(
            "SELECT service_category, company_name,
                    COALESCE(actual_amount, approved_amount, quote_amount, 0) amount,
                    payment_status
             FROM event_vendors
             WHERE event_id = ?
             ORDER BY amount DESC",
            [$eventId]
        );
        $vendorTotal = array_sum(array_map(static fn ($v) => (float) $v['amount'], $vendors));

        $staffing = $this->db->all(
            "SELECT s.role, sm.name staff_name,
                    COALESCE(s.actual_hours, s.estimated_hours, 0) hours,
                    s.hourly_rate,
                    COALESCE(s.actual_hours, s.estimated_hours, 0) * COALESCE(s.hourly_rate, 0) cost
             FROM event_staffing s
             LEFT JOIN staff_members sm ON sm.id = s.staff_member_id
             WHERE s.event_id = ? AND s.status <> 'canceled'
             ORDER BY cost DESC",
            [$eventId]
        );
        $staffingTotal = array_sum(array_map(static fn ($s) => (float) $s['cost'], $staffing));

        $lineup = $this->db->all(
            "SELECT display_name, payout_terms, status, billing_order
             FROM event_lineup
             WHERE event_id = ?
             ORDER BY billing_order",
            [$eventId]
        );

        $ticketTypes = $this->db->all(
            "SELECT tt.id, tt.name, tt.price_cents, tt.quantity_total, tt.quantity_sold,
                    COALESCE(SUM(CASE WHEN o.is_comp = 0 AND o.status IN ('paid','fulfilled') THEN oi.quantity ELSE 0 END), 0) sold,
                    COALESCE(SUM(CASE WHEN o.is_comp = 0 AND o.status IN ('paid','fulfilled') THEN oi.quantity * oi.unit_price_cents ELSE 0 END), 0) gross_cents
             FROM ticket_types tt
             LEFT JOIN ticket_order_items oi ON oi.ticket_type_id = tt.id
             LEFT JOIN ticket_orders o ON o.id = oi.order_id
             WHERE tt.event_id = ?
             GROUP BY tt.id, tt.name, tt.price_cents, tt.quantity_total, tt.quantity_sold
             ORDER BY tt.sort_order",
            [$eventId]
        );
        $ticketTypes = array_map(static function ($t) {
            $t['price']       = ((int) $t['price_cents']) / 100;
            $t['gross_sales'] = ((int) $t['gross_cents']) / 100;
            unset($t['price_cents'], $t['gross_cents']);
            return $t;
        }, $ticketTypes);

        return [
            'event' => [
                'id'         => (int) $event['id'],
                'title'      => $event['title'],
                'date'       => $event['date'],
                'end_date'   => $event['end_date'],
                'status'     => $event['status'],
                'venue_name' => $event['venue_name'],
                'event_type' => $event['event_type'],
            ],
            'summary'      => $summary,
            'closeout'     => $closeout,
            'vendors'      => $vendors,
            'vendor_total' => $vendorTotal,
            'staffing'     => $staffing,
            'staffing_total' => $staffingTotal,
            'lineup'       => $lineup,
            'ticket_types' => $ticketTypes,
            'manual_settlement' => $manualSettlement,
            'ledger_entries' => $ledgerEntries,
            'tickets_sold_effective'      => $ticketsSoldEffective,
            'gross_ticket_sales_effective' => $grossTicketSalesEffective,
            'ticket_sales_source'         => $ticketSalesSource,
            'payments_received' => $paymentsReceived,
            'disbursed'         => $disbursed,
            'outstanding_noted' => $outstandingNoted,
            'balance_due'       => $balanceDue,
            'payout_obligations' => $payoutObligations,
            'client_receivable'  => $clientReceivable,
            'door_shortfall'     => $doorShortfall,
            'payout_still_owed'  => $payoutStillOwed,
            'bottom_line_amount' => abs($bottomLineAmount),
            'bottom_line_type'   => $bottomLineType,
        ];
    }
}
