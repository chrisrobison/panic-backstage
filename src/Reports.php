<?php
declare(strict_types=1);

namespace Panic;

/**
 * Venue-wide financial reporting: a P&L overview, revenue/cost trend,
 * category breakdown, and a settlement report across every event.
 *
 * Every figure here is derived from event_ledger_entries — the same
 * append-only source of truth Events\Ledger::calculateSummary() reads for a
 * single event's closeout — so venue-wide totals can never drift from what
 * an individual event's P&L shows. Restricted to the `view_reports` global
 * capability (venue_admin + global_viewer — see BaseEndpoint).
 *
 *   GET /api/reports/overview     -> KPI cards, monthly trend, category
 *                                     breakdown, best/worst performing events
 *   GET /api/reports/settlements  -> one row per event with computed P&L +
 *                                     closeout status (add &format=csv to
 *                                     download instead of returning JSON)
 *
 * Both accept ?from=YYYY-MM-DD&to=YYYY-MM-DD&status=<event status> to scope
 * the report; from/to default to the trailing 12 months.
 */
final class Reports extends BaseEndpoint
{
    // Kept in sync with the `events.status` enum (see database/schema.sql +
    // migrations) and core.js's `statuses` array — the client's status
    // filter <select> is built from that same list.
    private const EVENT_STATUSES = [
        'empty', 'proposed', 'confirmed', 'booked', 'needs_assets', 'assets_approved',
        'ready_to_announce', 'published', 'advanced', 'completed', 'settled', 'canceled',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_reports')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        return match ($this->params['action'] ?? '') {
            'settlements'    => $this->settlements($request),
            'booking-inbox'  => $this->bookingInbox($request),
            default          => $this->overview($request),
        };
    }

    // ── Booking Inbox ─────────────────────────────────────────────────────────

    /**
     * GET /api/reports/booking-inbox?from=&to=
     *
     * Kept in this same Reports endpoint (same view_reports gate, same
     * /api/reports/* URL family, same date-range convention) rather than a
     * separate reporting surface — see the spec's reporting list under the
     * Booking Inbox module; every figure here is derived straight from
     * `leads`/`lead_status_history`/`lead_claims`/`lead_assignments`/
     * `lead_classifications`/`lead_audit_log`, no separate rollup tables.
     */
    private function bookingInbox(Request $request): Response
    {
        $from = (string) $request->query('from', '');
        $to   = (string) $request->query('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-3 months'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];

        $totals = $this->db->one(
            "SELECT
                COUNT(*) new_inquiries,
                SUM(status = 'onboarded') onboarded,
                SUM(status IN ('lost','declined')) lost,
                SUM(status IN ('spam')) spam,
                SUM(first_response_at IS NULL AND status NOT IN ('onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled')) unanswered,
                AVG(CASE WHEN claimed_at IS NOT NULL AND assigned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, assigned_at, claimed_at) END) avg_claim_minutes,
                AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END) avg_response_minutes,
                SUM(budget) budget_total
             FROM leads WHERE created_at BETWEEN ? AND ?",
            $range
        );

        $bySource = $this->db->all(
            "SELECT source, COUNT(*) n FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC",
            $range
        );
        $byCategory = $this->db->all(
            "SELECT COALESCE(event_category, event_type, 'unclassified') category, COUNT(*) n
             FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY category ORDER BY n DESC",
            $range
        );

        $expiredClaims = (int) ($this->db->one(
            "SELECT COUNT(*) n FROM lead_claims WHERE status = 'expired' AND claimed_at BETWEEN ? AND ?", $range
        )['n'] ?? 0);

        $toursScheduled = (int) ($this->db->one(
            "SELECT COUNT(DISTINCT lead_id) n FROM lead_status_history WHERE to_status = 'tour_scheduled' AND created_at BETWEEN ? AND ?", $range
        )['n'] ?? 0);
        $proposalsSent = (int) ($this->db->one(
            "SELECT COUNT(DISTINCT lead_id) n FROM lead_status_history WHERE to_status = 'proposal_sent' AND created_at BETWEEN ? AND ?", $range
        )['n'] ?? 0);

        $declineReasons = $this->db->all(
            "SELECT reason, COUNT(*) n FROM lead_status_history
             WHERE to_status IN ('declined','lost') AND reason IS NOT NULL AND reason != '' AND created_at BETWEEN ? AND ?
             GROUP BY reason ORDER BY n DESC LIMIT 10",
            $range
        );

        $activityByBooker = $this->db->all(
            "SELECT u.id, u.name,
                SUM(l.assigned_to_user_id = u.id) assigned_count,
                SUM(l.owner_user_id = u.id) owned_count,
                SUM(l.owner_user_id = u.id AND l.status = 'onboarded') onboarded_count
             FROM users u
             JOIN leads l ON (l.assigned_to_user_id = u.id OR l.owner_user_id = u.id) AND l.created_at BETWEEN ? AND ?
             GROUP BY u.id, u.name HAVING assigned_count > 0 OR owned_count > 0 ORDER BY owned_count DESC LIMIT 20",
            $range
        );

        $routingPerformance = $this->db->all(
            "SELECT rr.name rule_name, COUNT(*) assignments,
                SUM(l.status = 'onboarded') onboarded
             FROM lead_assignments la
             JOIN routing_rule_versions rv ON rv.id = la.routing_rule_version_id
             JOIN routing_rules rr ON rr.id = rv.routing_rule_id
             JOIN leads l ON l.id = la.lead_id
             WHERE la.created_at BETWEEN ? AND ?
             GROUP BY rr.id, rr.name ORDER BY assignments DESC",
            $range
        );

        $classification = $this->db->one(
            "SELECT
                SUM(c.source = 'ai') ai_count,
                SUM(c.source = 'human_correction') corrected_count
             FROM lead_classifications c
             JOIN leads l ON l.id = c.lead_id
             WHERE l.created_at BETWEEN ? AND ?",
            $range
        );
        $aiCount = (int) ($classification['ai_count'] ?? 0);
        $correctedCount = (int) ($classification['corrected_count'] ?? 0);

        $manualCorrections = (int) ($this->db->one(
            "SELECT COUNT(*) n FROM lead_audit_log WHERE action IN ('reassigned','manually_assigned') AND created_at BETWEEN ? AND ?", $range
        )['n'] ?? 0);

        $newInquiries = (int) ($totals['new_inquiries'] ?? 0);
        $onboarded = (int) ($totals['onboarded'] ?? 0);

        return $this->ok(['report' => [
            'range' => ['from' => $from, 'to' => $to],
            'totals' => [
                'new_inquiries' => $newInquiries,
                'onboarded' => $onboarded,
                'lost' => (int) ($totals['lost'] ?? 0),
                'spam' => (int) ($totals['spam'] ?? 0),
                'unanswered' => (int) ($totals['unanswered'] ?? 0),
                'expired_claims' => $expiredClaims,
                'tours_scheduled' => $toursScheduled,
                'proposals_sent' => $proposalsSent,
                'avg_claim_minutes' => round((float) ($totals['avg_claim_minutes'] ?? 0), 1),
                'avg_response_minutes' => round((float) ($totals['avg_response_minutes'] ?? 0), 1),
                'conversion_rate_pct' => $newInquiries > 0 ? round(($onboarded / $newInquiries) * 100, 1) : 0.0,
                'booked_budget_total' => (float) ($totals['budget_total'] ?? 0),
            ],
            'by_source' => $bySource,
            'by_category' => $byCategory,
            'decline_reasons' => $declineReasons,
            'activity_by_booker' => $activityByBooker,
            'routing_rule_performance' => $routingPerformance,
            'classifier' => [
                'classified_count' => $aiCount,
                'human_corrections' => $correctedCount,
                'accuracy_pct' => $aiCount > 0 ? round((1 - $correctedCount / $aiCount) * 100, 1) : null,
            ],
            'manual_routing_corrections' => $manualCorrections,
            // Informational only — see Leads\AnomalyScanner's doc comment.
            // Restricted to venue admins even though the rest of this report
            // is visible to global_viewer too, since these name individual
            // bookers.
            'anomalies' => $this->isVenueAdmin() ? \Panic\Leads\AnomalyScanner::scan($this->db) : [],
        ]]);
    }

    // ── Shared filter parsing ─────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:?string} [from, to, status] */
    private function filters(Request $request): array
    {
        $from = (string) $request->query('from', '');
        $to   = (string) $request->query('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-12 months'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $status = (string) $request->query('status', '');
        if (!in_array($status, self::EVENT_STATUSES, true)) {
            $status = null;
        }
        return [$from, $to, $status];
    }

    /**
     * @return array{0:string,1:array} [whereSql, params] — always includes the
     * date range; appends a status equality when one was requested.
     */
    private function rangeClause(string $from, string $to, ?string $status): array
    {
        $sql    = 'e.date BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($status !== null) {
            $sql      .= ' AND e.status = ?';
            $params[]  = $status;
        }
        return [$sql, $params];
    }

    // ── Overview ──────────────────────────────────────────────────────────────

    private function overview(Request $request): Response
    {
        [$from, $to, $status] = $this->filters($request);
        [$whereSql, $params]  = $this->rangeClause($from, $to, $status);

        // One row per event: gross revenue / total costs from non-void ledger
        // entries. LEFT JOIN so events with no ledger activity yet still show
        // up as zeroes rather than disappearing from the range entirely.
        $rows = $this->db->all(
            "SELECT e.id, e.title, e.date, e.status,
                    COALESCE(SUM(CASE WHEN l.line_type='revenue' AND l.is_void=0 THEN l.amount ELSE 0 END), 0) gross_revenue,
                    COALESCE(SUM(CASE WHEN l.line_type='cost'    AND l.is_void=0 THEN l.amount ELSE 0 END), 0) total_costs
             FROM events e
             LEFT JOIN event_ledger_entries l ON l.event_id = e.id
             WHERE $whereSql
             GROUP BY e.id, e.title, e.date, e.status
             ORDER BY e.date",
            $params
        );

        $eventsCount  = count($rows);
        $grossRevenue = 0.0;
        $totalCosts   = 0.0;
        $withNet      = [];
        foreach ($rows as $r) {
            $gross = (float) $r['gross_revenue'];
            $costs = (float) $r['total_costs'];
            $grossRevenue += $gross;
            $totalCosts   += $costs;
            $withNet[] = [
                'id'            => (int) $r['id'],
                'title'         => $r['title'],
                'date'          => $r['date'],
                'status'        => $r['status'],
                'gross_revenue' => $gross,
                'total_costs'   => $costs,
                'venue_net'     => $gross - $costs,
                'margin_pct'    => $gross > 0 ? round((($gross - $costs) / $gross) * 100, 2) : 0.0,
            ];
        }
        $venueNet  = $grossRevenue - $totalCosts;
        $marginPct = $grossRevenue > 0 ? round(($venueNet / $grossRevenue) * 100, 2) : 0.0;

        // Category breakdown, split by revenue vs. cost so the frontend can
        // render two single-hue bar lists rather than a >20-way categorical
        // palette (see the dataviz guidance: sequential = one hue, magnitude
        // only — never a rainbow of categories).
        [$catWhereSql, $catParams] = $this->rangeClause($from, $to, $status);
        $categories = $this->db->all(
            "SELECT l.category, l.line_type, SUM(l.amount) total
             FROM event_ledger_entries l
             JOIN events e ON e.id = l.event_id
             WHERE l.is_void = 0 AND $catWhereSql
             GROUP BY l.category, l.line_type
             ORDER BY total DESC",
            $catParams
        );
        $revenueByCategory = [];
        $costByCategory    = [];
        foreach ($categories as $c) {
            $entry = ['category' => $c['category'], 'total' => (float) $c['total']];
            if ($c['line_type'] === 'revenue') {
                $revenueByCategory[] = $entry;
            } elseif ($c['line_type'] === 'cost') {
                $costByCategory[] = $entry;
            }
        }

        // Monthly trend — one axis (dollars), two series (revenue/costs) plus
        // the derived net, all on the same scale.
        [$trendWhereSql, $trendParams] = $this->rangeClause($from, $to, $status);
        $trend = $this->db->all(
            "SELECT DATE_FORMAT(e.date, '%Y-%m') ym,
                    COALESCE(SUM(CASE WHEN l.line_type='revenue' AND l.is_void=0 THEN l.amount ELSE 0 END), 0) revenue,
                    COALESCE(SUM(CASE WHEN l.line_type='cost'    AND l.is_void=0 THEN l.amount ELSE 0 END), 0) costs
             FROM events e
             LEFT JOIN event_ledger_entries l ON l.event_id = e.id
             WHERE $trendWhereSql
             GROUP BY ym
             ORDER BY ym",
            $trendParams
        );
        $trend = array_map(static function ($t) {
            $revenue = (float) $t['revenue'];
            $costs   = (float) $t['costs'];
            return ['ym' => $t['ym'], 'revenue' => $revenue, 'costs' => $costs, 'net' => $revenue - $costs];
        }, $trend);

        // Ticketing totals — mirrors Events\Ledger::calculateSummary()'s
        // definition of a sale (real, non-comp orders in a paid/fulfilled state).
        [$ticketWhereSql, $ticketParams] = $this->rangeClause($from, $to, $status);
        $ticketing = $this->db->one(
            "SELECT COALESCE(SUM(oi.quantity), 0) tickets_sold,
                    COALESCE(SUM(oi.quantity * oi.unit_price_cents), 0) gross_ticket_cents
             FROM ticket_order_items oi
             JOIN ticket_orders o ON o.id = oi.order_id
             JOIN events e ON e.id = o.event_id
             WHERE o.is_comp = 0 AND o.status IN ('paid', 'fulfilled') AND $ticketWhereSql",
            $ticketParams
        );

        [$unsettledWhereSql, $unsettledParams] = $this->rangeClause($from, $to, $status);
        $unsettledCount = (int) ($this->db->one(
            "SELECT COUNT(*) c
             FROM events e
             LEFT JOIN event_closeout_state cs ON cs.event_id = e.id
             WHERE e.status IN ('completed', 'settled') AND (cs.status IS NULL OR cs.status <> 'finalized') AND $unsettledWhereSql",
            $unsettledParams
        )['c'] ?? 0);

        usort($withNet, static fn ($a, $b) => $b['venue_net'] <=> $a['venue_net']);
        $topEvents    = array_slice($withNet, 0, 5);
        $bottomEvents = array_slice(array_reverse($withNet), 0, 5);

        return $this->ok([
            'range' => ['from' => $from, 'to' => $to, 'status' => $status],
            'totals' => [
                'events_count'       => $eventsCount,
                'gross_revenue'      => $grossRevenue,
                'total_costs'        => $totalCosts,
                'venue_net'          => $venueNet,
                'margin_pct'         => $marginPct,
                'avg_net_per_event'  => $eventsCount > 0 ? round($venueNet / $eventsCount, 2) : 0.0,
                'tickets_sold'       => (int) ($ticketing['tickets_sold'] ?? 0),
                'gross_ticket_sales' => ((int) ($ticketing['gross_ticket_cents'] ?? 0)) / 100,
                'unsettled_count'    => $unsettledCount,
            ],
            'revenue_by_category' => $revenueByCategory,
            'cost_by_category'    => $costByCategory,
            'trend'               => $trend,
            'top_events'          => $topEvents,
            'bottom_events'       => $bottomEvents,
        ]);
    }

    // ── Settlement report (one row per event) ──────────────────────────────────

    private function settlements(Request $request): Response
    {
        [$from, $to, $status] = $this->filters($request);
        [$whereSql, $params]  = $this->rangeClause($from, $to, $status);

        $rows = $this->db->all(
            "SELECT e.id, e.external_id, e.title, e.date, e.status, e.event_type,
                    COALESCE(SUM(CASE WHEN l.line_type='revenue' AND l.is_void=0 THEN l.amount ELSE 0 END), 0) gross_revenue,
                    COALESCE(SUM(CASE WHEN l.line_type='cost'    AND l.is_void=0 THEN l.amount ELSE 0 END), 0) total_costs,
                    cs.status closeout_status, cs.finalized_at
             FROM events e
             LEFT JOIN event_ledger_entries l ON l.event_id = e.id
             LEFT JOIN event_closeout_state cs ON cs.event_id = e.id
             WHERE $whereSql
             GROUP BY e.id, e.external_id, e.title, e.date, e.status, e.event_type, cs.status, cs.finalized_at
             ORDER BY e.date DESC",
            $params
        );

        $settlements = array_map(static function ($r) {
            $gross = (float) $r['gross_revenue'];
            $costs = (float) $r['total_costs'];
            $net   = $gross - $costs;
            return [
                'id'              => (int) $r['id'],
                'external_id'     => $r['external_id'],
                'title'           => $r['title'],
                'date'            => $r['date'],
                'status'          => $r['status'],
                'event_type'      => $r['event_type'],
                'gross_revenue'   => $gross,
                'total_costs'     => $costs,
                'venue_net'       => $net,
                'margin_pct'      => $gross > 0 ? round($net / $gross * 100, 2) : 0.0,
                'closeout_status' => $r['closeout_status'] ?? 'open',
                'finalized_at'    => $r['finalized_at'],
            ];
        }, $rows);

        if ((string) $request->query('format', '') === 'csv') {
            return $this->settlementsCsv($settlements, $from, $to);
        }

        return $this->ok([
            'range'       => ['from' => $from, 'to' => $to, 'status' => $status],
            'settlements' => $settlements,
        ]);
    }

    private function settlementsCsv(array $rows, string $from, string $to): Response
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [
            'Event ID', 'External ID', 'Title', 'Date', 'Status',
            'Gross Revenue', 'Total Costs', 'Venue Net', 'Margin %',
            'Closeout Status', 'Finalized At',
        ]);
        foreach ($rows as $r) {
            fputcsv($stream, [
                $r['id'], $r['external_id'], $r['title'], $r['date'], $r['status'],
                number_format($r['gross_revenue'], 2, '.', ''),
                number_format($r['total_costs'], 2, '.', ''),
                number_format($r['venue_net'], 2, '.', ''),
                $r['margin_pct'],
                $r['closeout_status'], $r['finalized_at'],
            ]);
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return Response::csv($csv, "settlement-report_{$from}_to_{$to}.csv");
    }
}
