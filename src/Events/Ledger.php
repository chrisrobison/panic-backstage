<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;
use function Panic\boolish;

/**
 * Append-only event financial ledger.
 *
 *   GET    /api/events/{id}/ledger          list all non-void entries
 *   POST   /api/events/{id}/ledger          add an entry (corrections are new entries)
 *   DELETE /api/events/{id}/ledger/{eid}    void an entry (audit trail preserved)
 *   GET    /api/events/{id}/ledger/summary  server-calculated P&L summary
 *
 * All financial totals (venue_net, gross, margin) are computed server-side.
 * Client submits individual line-item inputs; server returns computed totals.
 *
 * Capabilities: read_event (GET), manage_ledger (POST/DELETE)
 *               finalize_closeout to finalize/reopen
 */
final class Ledger extends BaseEndpoint
{
    private const REVENUE_CATEGORIES = [
        'tickets','ticket_fees','bar_sales','rental_fee','hosted_bar',
        'merch_share','sponsorship','equipment_rental','overtime_charge','other_revenue',
    ];

    private const COST_CATEGORIES = [
        'artist_guarantee','promoter_settlement','labor','sound_production',
        'security','cleaning','rentals','catering','vendor_cost',
        'processing_fees','taxes','refunds','other_cost',
    ];

    private const PAYMENT_CATEGORIES = [
        'deposit_received','invoice_payment','credit','outstanding_balance',
        'artist_payout','promoter_payout','vendor_payout','staff_payout','adjustment',
    ];

    private const ALL_CATEGORIES = [
        ...self::REVENUE_CATEGORIES,
        ...self::COST_CATEGORIES,
        ...self::PAYMENT_CATEGORIES,
    ];

    private const SOURCES = ['manual','ticketing_sync','pos_import','vendor_link',
                              'staffing_link','payment_link','change_order_link','system'];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $entryId = $this->params['entryId'] ?? null;
        $action  = $this->params['action']  ?? null;

        if ($action === 'summary' && $request->method() === 'GET') {
            if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
                return $denied;
            }
            return $this->summary($eventId);
        }

        if ($action === 'finalize' && $request->method() === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'finalize_closeout')) {
                return $denied;
            }
            return $this->finalize($request, $eventId);
        }

        if ($action === 'reopen' && $request->method() === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'finalize_closeout')) {
                return $denied;
            }
            return $this->reopen($request, $eventId);
        }

        $cap = $request->method() === 'GET' ? 'read_event' : 'manage_ledger';
        if ($denied = $this->requireEventCapability($eventId, $cap)) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'    => $this->index($eventId),
            'POST'   => $this->addEntry($request, $eventId),
            'DELETE' => $this->voidEntry($request, $eventId, (int) $entryId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── List ──────────────────────────────────────────────────────────────────

    private function index(int $eventId): Response
    {
        $entries = $this->db->all(
            "SELECT e.*, u.name created_by_name
             FROM event_ledger_entries e
             LEFT JOIN users u ON u.id = e.created_by_id
             WHERE e.event_id = ? AND e.is_void = 0
             ORDER BY FIELD(e.line_type,'revenue','cost','payment','receivable'), e.category, e.created_at",
            [$eventId]
        );

        $closeout = $this->db->one(
            'SELECT * FROM event_closeout_state WHERE event_id = ?',
            [$eventId]
        );

        return $this->ok([
            'entries'          => $entries,
            'closeout'         => $closeout,
            'revenue_categories' => self::REVENUE_CATEGORIES,
            'cost_categories'  => self::COST_CATEGORIES,
            'payment_categories' => self::PAYMENT_CATEGORIES,
        ]);
    }

    // ── Add Entry ─────────────────────────────────────────────────────────────

    private function addEntry(Request $request, int $eventId): Response
    {
        // Cannot add entries to a finalized closeout
        $state = $this->db->one(
            'SELECT status FROM event_closeout_state WHERE event_id = ?',
            [$eventId]
        );
        if (($state['status'] ?? '') === 'finalized') {
            if (!$this->hasEventCapability($eventId, 'finalize_closeout')) {
                return Response::json(['error' => 'Closeout is finalized — reopen to add entries'], 409);
            }
        }

        $b = $request->body();

        $category = (string) ($b['category'] ?? '');
        if (!in_array($category, self::ALL_CATEGORIES, true)) {
            return Response::json(['error' => 'Invalid category'], 422);
        }

        $amount = (float) ($b['amount'] ?? 0);
        if ($amount == 0) {
            return Response::json(['error' => 'amount must be non-zero'], 422);
        }

        // Derive line_type from category
        $lineType = match(true) {
            in_array($category, self::REVENUE_CATEGORIES, true)  => 'revenue',
            in_array($category, self::COST_CATEGORIES, true)     => 'cost',
            in_array($category, self::PAYMENT_CATEGORIES, true)  => 'payment',
            default => 'revenue',
        };
        // Override if explicitly provided
        if (in_array($b['line_type'] ?? '', ['revenue','cost','payment','receivable'], true)) {
            $lineType = $b['line_type'];
        }

        $source = (string) ($b['source'] ?? 'manual');
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'manual';
        }

        $id = $this->db->insert(
            'INSERT INTO event_ledger_entries
             (event_id, category, line_type, amount, currency, description, source,
              source_ref_id, reconciler_id, reconciled_at, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventId,
                $category,
                $lineType,
                $amount,
                strtoupper((string) ($b['currency'] ?? 'USD')),
                $b['description']  ?? null,
                $source,
                isset($b['source_ref_id']) ? (int) $b['source_ref_id'] : null,
                isset($b['reconciler_id']) ? (int) $b['reconciler_id'] : null,
                $b['reconciled_at'] ?? null,
                $this->userId(),
            ]
        );

        log_activity($this->db, $eventId, $this->userId(), "ledger entry added: $category \$$amount", [
            'entry_id' => $id,
            'category' => $category,
            'amount'   => $amount,
        ]);

        // Ensure closeout state row exists
        $this->ensureCloseoutState($eventId);

        return $this->ok(['id' => $id, 'summary' => $this->calculateSummary($eventId)]);
    }

    // ── Void Entry ────────────────────────────────────────────────────────────

    private function voidEntry(Request $request, int $eventId, int $entryId): Response
    {
        $entry = $this->db->one(
            'SELECT * FROM event_ledger_entries WHERE id = ? AND event_id = ?',
            [$entryId, $eventId]
        );
        if (!$entry) {
            return $this->notFound('Ledger entry not found');
        }

        $b      = $request->body();
        $reason = $b['void_reason'] ?? 'voided by ' . ($this->userId() ?? 'unknown');

        $this->db->run(
            'UPDATE event_ledger_entries SET is_void = 1, void_reason = ? WHERE id = ?',
            [$reason, $entryId]
        );

        log_activity($this->db, $eventId, $this->userId(), 'ledger entry voided', [
            'entry_id' => $entryId,
            'reason'   => $reason,
        ]);

        return $this->ok(['ok' => true, 'summary' => $this->calculateSummary($eventId)]);
    }

    // ── P&L Summary ───────────────────────────────────────────────────────────

    private function summary(int $eventId): Response
    {
        return $this->ok(['summary' => $this->calculateSummary($eventId)]);
    }

    /**
     * Server-side P&L calculation.  All totals are computed here — never from
     * client-submitted totals.
     */
    public function calculateSummary(int $eventId): array
    {
        $entries = $this->db->all(
            "SELECT category, line_type, amount FROM event_ledger_entries
             WHERE event_id = ? AND is_void = 0",
            [$eventId]
        );

        $byCategory  = [];
        $grossRevenue = 0;
        $totalCosts   = 0;
        $totalPayments = 0;

        foreach ($entries as $e) {
            $cat  = $e['category'];
            $amt  = (float) $e['amount'];
            $type = $e['line_type'];

            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + $amt;

            match ($type) {
                'revenue'    => $grossRevenue  += $amt,
                'cost'       => $totalCosts    += $amt,
                'payment'    => $totalPayments += $amt,
                'receivable' => null,
                default      => null,
            };
        }

        $venueNet  = $grossRevenue - $totalCosts;
        $marginPct = $grossRevenue > 0 ? round(($venueNet / $grossRevenue) * 100, 2) : 0;

        // Also pull ticketing data if available
        $ticketing = $this->db->one(
            "SELECT COUNT(*) tickets_sold,
                    COALESCE(SUM(amount_total), 0) gross_ticket_sales
             FROM ticket_orders WHERE event_id = ? AND status = 'completed'",
            [$eventId]
        );

        return [
            'gross_revenue'    => $grossRevenue,
            'total_costs'      => $totalCosts,
            'venue_net'        => $venueNet,
            'margin_pct'       => $marginPct,
            'total_payments'   => $totalPayments,
            'by_category'      => $byCategory,
            'tickets_sold'     => (int) ($ticketing['tickets_sold'] ?? 0),
            'gross_ticket_sales' => (float) ($ticketing['gross_ticket_sales'] ?? 0),
        ];
    }

    // ── Finalize / Reopen ─────────────────────────────────────────────────────

    private function finalize(Request $request, int $eventId): Response
    {
        $state = $this->ensureCloseoutState($eventId);

        if (($state['status'] ?? '') === 'finalized') {
            return Response::json(['error' => 'Already finalized'], 409);
        }

        $b = $request->body();

        // Check all checklist items are done
        $checklist = [
            'actual_hours_confirmed', 'bar_revenue_reconciled', 'ticket_revenue_reconciled',
            'vendor_costs_entered', 'incidents_reviewed', 'final_invoice_prepared',
            'payment_obligations_recorded',
        ];

        $missing = [];
        foreach ($checklist as $item) {
            if (empty($b[$item]) && empty($state[$item])) {
                $missing[] = $item;
            }
        }

        if (!empty($missing) && empty($b['force'])) {
            return Response::json([
                'error'   => 'Closeout checklist incomplete',
                'missing' => $missing,
            ], 422);
        }

        // Update checklist fields and finalize
        $sets = ['status = ?', 'finalized_by_id = ?', 'finalized_at = NOW()'];
        $params = ['finalized', $this->userId()];

        foreach ($checklist as $item) {
            $sets[]   = "$item = ?";
            $params[] = 1;
        }

        if (!empty($b['notes'])) {
            $sets[]   = 'notes = ?';
            $params[] = $b['notes'];
        }

        $params[] = $eventId;
        $this->db->run(
            'UPDATE event_closeout_state SET ' . implode(', ', $sets) . ' WHERE event_id = ?',
            $params
        );

        // Mark event as settled
        $this->db->run(
            "UPDATE events SET status = 'settled' WHERE id = ? AND status = 'completed'",
            [$eventId]
        );

        log_activity($this->db, $eventId, $this->userId(), 'closeout finalized', []);

        // Trigger accounting sync if a provider is configured and enabled.
        (new \Panic\Accounting($this->db, $this->root))->onCloseoutFinalized($eventId);

        return $this->ok(['ok' => true, 'status' => 'finalized']);
    }

    private function reopen(Request $request, int $eventId): Response
    {
        $state = $this->db->one(
            'SELECT * FROM event_closeout_state WHERE event_id = ?',
            [$eventId]
        );
        if (!$state || ($state['status'] ?? '') !== 'finalized') {
            return Response::json(['error' => 'Closeout is not finalized'], 409);
        }

        $b      = $request->body();
        $reason = trim((string) ($b['reason'] ?? ''));
        if ($reason === '') {
            return Response::json(['error' => 'A reason is required to reopen a finalized closeout'], 422);
        }

        $this->db->run(
            "UPDATE event_closeout_state
             SET status = 'reopened', reopen_reason = ?, reopened_by_id = ?, reopened_at = NOW()
             WHERE event_id = ?",
            [$reason, $this->userId(), $eventId]
        );

        log_activity($this->db, $eventId, $this->userId(), 'closeout reopened', [
            'reason' => $reason,
        ]);

        return $this->ok(['ok' => true, 'status' => 'reopened']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function ensureCloseoutState(int $eventId): array
    {
        $state = $this->db->one(
            'SELECT * FROM event_closeout_state WHERE event_id = ?',
            [$eventId]
        );
        if (!$state) {
            $this->db->run(
                'INSERT INTO event_closeout_state (event_id, status) VALUES (?,?)',
                [$eventId, 'open']
            );
            $state = $this->db->one(
                'SELECT * FROM event_closeout_state WHERE event_id = ?',
                [$eventId]
            );
        }
        return $state ?? [];
    }
}
