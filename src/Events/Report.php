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
 * Combines Ledger::calculateSummary() (the same server-computed P&L used by
 * the Closeout tab — never recomputed differently here) with the cost detail
 * that usually explains *why* the ledger total looks the way it does: the
 * vendor bill, the staffing labor cost, the lineup payout terms, a
 * ticket-type sales breakdown, the raw ledger line items (so a printed
 * statement can reproduce Closeout's Revenue/Costs/Payments grouping
 * exactly), a payments-received/disbursed/balance-due split, a
 * payout-obligations net (committed promoter_settlement/artist_guarantee
 * cost entries minus whatever's already been disbursed — the sign-off
 * number for a revenue-split payout, distinct from the client-billing
 * balance due), and a fallback to the manually-entered Settlement tab
 * figures for shows where tickets sold outside this app's own ticketing
 * module. Nothing here is editable — it's a reporting view over data owned
 * by Ledger, Vendors, Staffing, Lineup, Ticketing and Settlement.
 *
 * Capability: view_settlement (same gate as the Settlement + Closeout tabs).
 */
final class Report extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'view_settlement')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $event = $this->db->one(
            'SELECT e.*, v.name venue_name FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound('Event not found');
        }

        $summary  = (new Ledger($this->db, $this->auth, [], $this->root))->calculateSummary($eventId);
        $closeout = $this->db->one('SELECT * FROM event_closeout_state WHERE event_id = ?', [$eventId]);

        // The old "Settlement" tab (event_settlements) is a hand-typed door
        // sheet — gross ticket sales, ticket count, bar sales, expenses,
        // payouts — kept for shows where tickets sold through an outside
        // service or at the door rather than this app's own ticketing
        // module. Surfaced here so the printed statement can fall back to it
        // (see tickets-sold below) and so staff can see it was recorded at all.
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

        return $this->ok([
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
        ]);
    }
}
