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
 * vendor bill, the staffing labor cost, the lineup payout terms, and a
 * ticket-type sales breakdown. Nothing here is editable — it's a reporting
 * view over data owned by Ledger, Vendors, Staffing, Lineup and Ticketing.
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
        ]);
    }
}
