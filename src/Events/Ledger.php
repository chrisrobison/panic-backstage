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
 *   GET    /api/events/{id}/ledger          list all non-void entries + payee balances
 *   POST   /api/events/{id}/ledger          add an entry (corrections are new entries)
 *   DELETE /api/events/{id}/ledger/{eid}    void an entry (audit trail preserved)
 *   PATCH  /api/events/{id}/ledger          toggle one or more closeout checklist items
 *   GET    /api/events/{id}/ledger/summary  server-calculated P&L summary
 *
 * All financial totals (venue_net, gross, margin) are computed server-side.
 * Client submits individual line-item inputs; server returns computed totals.
 *
 * ── Payee balances ───────────────────────────────────────────────────────
 * A cost entry may carry a payee_name/payee_type (who the venue owes — an
 * artist, a vendor, a promoter, a staffer). A payment entry either links to
 * one specific cost via paid_entry_id, or carries its own payee_name for a
 * looser payee-level payment. calculateBalances() nets committed cost vs.
 * paid per payee so the Closeout tab can show "who's still owed money"
 * directly, instead of staff reconciling separate Costs/Payments lists by
 * eye. finalize() refuses to close out while any payee still shows a
 * positive balance (bypassable the same way as the checklist, via `force`,
 * for whoever already holds finalize_closeout).
 *
 * Capabilities: read_event (GET), manage_ledger (POST/PATCH/DELETE)
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

    // Who a cost/payment entry's payee_type may be — mirrors the payee
    // categories closeout already deals with (artist guarantees, promoter
    // splits, vendor bills, staff payouts) plus 'client'/'other' for
    // anything that doesn't fit those. Echoed back to the client the same
    // way the category lists are, so the add-entry form's dropdown can't
    // drift from what the server actually accepts.
    private const PAYEE_TYPES = ['artist', 'promoter', 'vendor', 'staff', 'client', 'other'];

    // Single source of truth for the closeout checklist's DB columns — shared
    // by updateChecklist() (per-item PATCH toggle) and finalize() (completeness
    // check), so the two can't drift the way they previously did: the closeout
    // panel's checklist (event-closeout.js CHECKLIST_FIELDS) and this list used
    // to name completely different, unrelated columns.
    private const CHECKLIST_ITEMS = [
        'contract_signed', 'deposit_received', 'vendors_confirmed',
        'staffing_confirmed', 'bar_closed', 'cash_reconciled', 'all_invoices_collected',
    ];

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
            'PATCH'  => $this->updateChecklist($request, $eventId),
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

        $balances = $this->calculateBalances($eventId);

        return $this->ok([
            'entries'          => $entries,
            'closeout'         => $closeout,
            'revenue_categories' => self::REVENUE_CATEGORIES,
            'cost_categories'  => self::COST_CATEGORIES,
            'payment_categories' => self::PAYMENT_CATEGORIES,
            'payee_types'      => self::PAYEE_TYPES,
            'balances'         => $balances['balances'],
            'total_still_owed' => $balances['total_still_owed'],
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

        // ── Payee tracking (who this cost is owed to / this payment goes to) ──
        // Only meaningful for cost and payment entries — a revenue line has no
        // payee. Silently dropped rather than rejected for other line types so
        // a client that always sends the field doesn't need to know that.
        $payeeName = null;
        $payeeType = null;
        if (in_array($lineType, ['cost', 'payment'], true)) {
            $payeeName = trim((string) ($b['payee_name'] ?? ''));
            $payeeName = $payeeName !== '' ? mb_substr($payeeName, 0, 255) : null;

            $payeeType = (string) ($b['payee_type'] ?? '');
            if ($payeeType !== '' && !in_array($payeeType, self::PAYEE_TYPES, true)) {
                return Response::json(['error' => 'Invalid payee_type'], 422);
            }
            $payeeType = $payeeType !== '' ? $payeeType : null;
        }

        // A payment entry may reference the exact cost entry it pays down.
        // Validated against this same event and line_type='cost' so a
        // payment can't be wired to another event's ledger or to another
        // payment/revenue row.
        $paidEntryId = null;
        if ($lineType === 'payment' && !empty($b['paid_entry_id'])) {
            $paidEntryId = (int) $b['paid_entry_id'];
            $target = $this->db->one(
                "SELECT id FROM event_ledger_entries
                 WHERE id = ? AND event_id = ? AND line_type = 'cost' AND is_void = 0",
                [$paidEntryId, $eventId]
            );
            if (!$target) {
                return Response::json(['error' => 'paid_entry_id must reference an existing cost entry on this event'], 422);
            }
        }

        $id = $this->db->insert(
            'INSERT INTO event_ledger_entries
             (event_id, category, line_type, amount, currency, description, payee_name,
              payee_type, paid_entry_id, source, source_ref_id, reconciler_id,
              reconciled_at, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventId,
                $category,
                $lineType,
                $amount,
                strtoupper((string) ($b['currency'] ?? 'USD')),
                $b['description']  ?? null,
                $payeeName,
                $payeeType,
                $paidEntryId,
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

        $balances = $this->calculateBalances($eventId);

        return $this->ok([
            'id'               => $id,
            'summary'          => $this->calculateSummary($eventId),
            'balances'         => $balances['balances'],
            'total_still_owed' => $balances['total_still_owed'],
        ]);
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

    // ── Checklist ─────────────────────────────────────────────────────────────

    /**
     * PATCH /api/events/{id}/ledger — toggle one or more closeout checklist
     * items. Body is a partial map of { field: 0|1, ... } restricted to
     * self::CHECKLIST_ITEMS; unknown fields are rejected (422) rather than
     * silently ignored, so a frontend/backend field-name mismatch — the
     * exact bug this endpoint originally shipped without a handler for —
     * fails loudly instead of quietly no-opping.
     */
    private function updateChecklist(Request $request, int $eventId): Response
    {
        $state = $this->ensureCloseoutState($eventId);

        if (($state['status'] ?? '') === 'finalized') {
            if (!$this->hasEventCapability($eventId, 'finalize_closeout')) {
                return Response::json(['error' => 'Closeout is finalized — reopen to change checklist items'], 409);
            }
        }

        $b = $request->body();

        $unknown = array_diff(array_keys($b), self::CHECKLIST_ITEMS);
        if (!empty($unknown)) {
            return Response::json(['error' => 'Unknown checklist field(s): ' . implode(', ', $unknown)], 422);
        }

        // Safe to interpolate: keys are drawn only from the fixed
        // self::CHECKLIST_ITEMS whitelist checked above, never from $b directly.
        $updates = array_intersect_key($b, array_flip(self::CHECKLIST_ITEMS));
        if (empty($updates)) {
            return Response::json(['error' => 'No checklist fields provided'], 422);
        }

        $sets   = [];
        $params = [];
        foreach ($updates as $field => $value) {
            $sets[]   = "$field = ?";
            $params[] = boolish($value);
        }
        $params[] = $eventId;

        $this->db->run(
            'UPDATE event_closeout_state SET ' . implode(', ', $sets) . ' WHERE event_id = ?',
            $params
        );

        log_activity($this->db, $eventId, $this->userId(), 'closeout checklist updated', $updates);

        $closeout = $this->db->one('SELECT * FROM event_closeout_state WHERE event_id = ?', [$eventId]);
        return $this->ok(['ok' => true, 'closeout' => $closeout]);
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

        // Also pull ticketing data if available. Mirrors the ticketing
        // dashboard's definition of a sale: real (non-comp) orders in a
        // paid/fulfilled state. amount is stored in cents, so convert to
        // dollars to match the ledger's DECIMAL totals above.
        $ticketing = $this->db->one(
            "SELECT COALESCE(SUM(oi.quantity), 0) tickets_sold,
                    COALESCE(SUM(oi.quantity * oi.unit_price_cents), 0) gross_ticket_cents
             FROM ticket_order_items oi
             JOIN ticket_orders o ON o.id = oi.order_id
             WHERE o.event_id = ? AND o.is_comp = 0 AND o.status IN ('paid', 'fulfilled')",
            [$eventId]
        );

        $balances = $this->calculateBalances($eventId);

        return [
            'gross_revenue'    => $grossRevenue,
            'total_costs'      => $totalCosts,
            'venue_net'        => $venueNet,
            'margin_pct'       => $marginPct,
            'total_payments'   => $totalPayments,
            'by_category'      => $byCategory,
            'tickets_sold'     => (int) ($ticketing['tickets_sold'] ?? 0),
            'gross_ticket_sales' => ((int) ($ticketing['gross_ticket_cents'] ?? 0)) / 100,
            'total_still_owed'   => $balances['total_still_owed'],
            'payees_unpaid'      => count(array_filter($balances['balances'], fn($b) => $b['status'] === 'unpaid')),
            'payees_partial'     => count(array_filter($balances['balances'], fn($b) => $b['status'] === 'partial')),
        ];
    }

    // ── Payee Balances ────────────────────────────────────────────────────────

    /**
     * Nets, per payee, what's been committed (cost entries) against what's
     * actually gone out the door (payment entries), so the Closeout tab can
     * show "who's still owed money" directly instead of two flat lists staff
     * have to reconcile by eye.
     *
     * A payment nets against a payee two ways, in order of precedence:
     *   1. paid_entry_id — points at one specific (non-void) cost entry.
     *      Precise: nets straight into that cost's payee group.
     *   2. payee_name on the payment itself, with no paid_entry_id — a
     *      looser "paid this payee something" entry (e.g. a deposit paid
     *      before any cost line existed to link it to). Nets into that
     *      payee's group by name+type instead of a specific line item.
     * A payment with neither is pure cash-flow bookkeeping (e.g. a client
     * deposit_received against the venue itself) and doesn't net into any
     * payee balance — see the payment-line-type notes on Report.php for why
     * "money in" and "money owed to someone" are genuinely different things.
     *
     * Grouping key is payee_name (case/whitespace-insensitive) + payee_type,
     * so "Doorwolf Sound Co." typed twice for the same event always nets
     * together even if payee_type was left blank once.
     */
    private function calculateBalances(int $eventId): array
    {
        $entries = $this->db->all(
            "SELECT id, line_type, amount, payee_name, payee_type, paid_entry_id
             FROM event_ledger_entries
             WHERE event_id = ? AND is_void = 0",
            [$eventId]
        );

        $keyFor = static fn(string $name, ?string $type): string =>
            mb_strtolower(trim($name)) . '|' . ($type ?? '');

        $costsById = [];
        $groups    = [];

        foreach ($entries as $e) {
            if ($e['line_type'] !== 'cost' || empty($e['payee_name'])) {
                continue;
            }
            $costsById[(int) $e['id']] = $e;
            $key = $keyFor($e['payee_name'], $e['payee_type']);
            $groups[$key] ??= [
                'payee_name' => $e['payee_name'],
                'payee_type' => $e['payee_type'],
                'committed'  => 0.0,
                'paid'       => 0.0,
            ];
            $groups[$key]['committed'] += (float) $e['amount'];
        }

        foreach ($entries as $e) {
            if ($e['line_type'] !== 'payment') {
                continue;
            }
            $amt = (float) $e['amount'];

            $linkedCost = $e['paid_entry_id'] !== null ? ($costsById[(int) $e['paid_entry_id']] ?? null) : null;
            if ($linkedCost) {
                $key = $keyFor($linkedCost['payee_name'], $linkedCost['payee_type']);
                $groups[$key]['paid'] += $amt;
            } elseif (!empty($e['payee_name'])) {
                $key = $keyFor($e['payee_name'], $e['payee_type']);
                $groups[$key] ??= [
                    'payee_name' => $e['payee_name'],
                    'payee_type' => $e['payee_type'],
                    'committed'  => 0.0,
                    'paid'       => 0.0,
                ];
                $groups[$key]['paid'] += $amt;
            }
            // else: not tied to any payee (e.g. deposit_received from the
            // client) — pure cash flow, excluded from payee balances.
        }

        $balances = [];
        $totalStillOwed = 0.0;
        foreach ($groups as $g) {
            $stillOwed = round($g['committed'] - $g['paid'], 2);
            $status    = $stillOwed <= 0.005 ? 'paid' : ($g['paid'] > 0 ? 'partial' : 'unpaid');
            $balances[] = [
                'payee_name' => $g['payee_name'],
                'payee_type' => $g['payee_type'],
                'committed'  => round($g['committed'], 2),
                'paid'       => round($g['paid'], 2),
                'still_owed' => $stillOwed,
                'status'     => $status,
            ];
            if ($stillOwed > 0) {
                $totalStillOwed += $stillOwed;
            }
        }

        // Unpaid/partial first (what needs action), largest balance first,
        // then alphabetical — matches how the Balances panel renders them.
        usort($balances, static function (array $a, array $b): int {
            $rank = static fn(string $s): int => $s === 'paid' ? 1 : 0;
            if ($rank($a['status']) !== $rank($b['status'])) {
                return $rank($a['status']) <=> $rank($b['status']);
            }
            if ($a['still_owed'] !== $b['still_owed']) {
                return $b['still_owed'] <=> $a['still_owed'];
            }
            return strcasecmp($a['payee_name'], $b['payee_name']);
        });

        return ['balances' => $balances, 'total_still_owed' => round($totalStillOwed, 2)];
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
        $checklist = self::CHECKLIST_ITEMS;

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

        // Money owed to a payee blocks finalize the same way an unchecked
        // checklist item does — a fully-ticked checklist previously said
        // nothing about whether anyone still had money coming. `force`
        // (already gated on finalize_closeout above) overrides this too.
        $balances    = $this->calculateBalances($eventId);
        $unpaid      = array_values(array_filter($balances['balances'], fn($bal) => $bal['status'] !== 'paid'));
        if (!empty($unpaid) && empty($b['force'])) {
            return Response::json([
                'error'           => 'Payees are still owed money',
                'total_still_owed' => $balances['total_still_owed'],
                'unpaid_payees'   => array_map(fn($bal) => [
                    'payee_name' => $bal['payee_name'],
                    'still_owed' => $bal['still_owed'],
                ], $unpaid),
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
