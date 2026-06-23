<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;
use function Panic\date_or_null;
use function Panic\boolish;

/**
 * Event payment records — deposits, balance payments, refunds, etc.
 *
 *   GET    /api/events/{id}/payments               list payments + deposit summary
 *   POST   /api/events/{id}/payments               record a payment
 *   PATCH  /api/events/{id}/payments/{pid}         update a payment
 *   DELETE /api/events/{id}/payments/{pid}         void/delete a payment
 *
 * The event cannot enter Booked/Confirmed status unless:
 *   1. A required contract is fully executed (status = 'signed' or 'fully_executed')
 *   2. The deposit is in status 'received' or 'waived'
 *
 * Waiving a deposit requires the `waive_deposit` capability.
 *
 * Capabilities: read_event (GET), manage_payments (POST/PATCH/DELETE)
 */
final class Payments extends BaseEndpoint
{
    private const PAYMENT_TYPES = ['deposit','balance_payment','refund','credit','adjustment',
                                    'promoter_payment','client_payment','other'];
    private const METHODS       = ['cash','check','ach','wire','credit_card','stripe','square',
                                    'venmo','zelle','other'];
    private const STATUSES      = ['pending','received','failed','refunded','voided'];

    public function handle(Request $request): Response
    {
        $eventId   = $this->requireEventId();
        $paymentId = $this->params['paymentId'] ?? null;
        $action    = $this->params['action']    ?? null;

        // Waive deposit — high-privilege action
        if ($action === 'waive-deposit' && $request->method() === 'POST') {
            return $this->waiveDeposit($request, $eventId);
        }

        $cap = $request->method() === 'GET' ? 'read_event' : 'manage_payments';
        if ($denied = $this->requireEventCapability($eventId, $cap)) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'    => $this->index($eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, (int) $paymentId),
            'DELETE' => $this->voidPayment($eventId, (int) $paymentId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── List + Summary ────────────────────────────────────────────────────────

    private function index(int $eventId): Response
    {
        $payments = $this->db->all(
            "SELECT p.*, u.name created_by_name
             FROM event_payments p
             LEFT JOIN users u ON u.id = p.created_by_id
             WHERE p.event_id = ? AND p.status != 'voided'
             ORDER BY p.created_at ASC",
            [$eventId]
        );

        $event = $this->db->one(
            'SELECT deposit_amount, deposit_status, deposit_waived_by_id, deposit_waived_reason FROM events WHERE id = ?',
            [$eventId]
        );

        $summary = $this->buildSummary($payments, $event);

        return $this->ok([
            'payments'     => $payments,
            'summary'      => $summary,
            'deposit_status' => $event['deposit_status'] ?? 'not_required',
            'payment_types' => self::PAYMENT_TYPES,
            'methods'       => self::METHODS,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    private function create(Request $request, int $eventId): Response
    {
        $b = $request->body();

        $type = (string) ($b['payment_type'] ?? 'other');
        if (!in_array($type, self::PAYMENT_TYPES, true)) {
            return Response::json(['error' => 'Invalid payment_type'], 422);
        }

        $method = $b['method'] ?? null;
        if ($method && !in_array($method, self::METHODS, true)) {
            return Response::json(['error' => 'Invalid method'], 422);
        }

        $amount = (float) ($b['amount'] ?? 0);
        if ($amount <= 0) {
            return Response::json(['error' => 'amount must be greater than 0'], 422);
        }

        $status = (string) ($b['status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }

        $receivedAt = null;
        if ($status === 'received') {
            $receivedAt = date('Y-m-d H:i:s');
        } elseif (!empty($b['received_at'])) {
            $receivedAt = (string) $b['received_at'];
        }

        $id = $this->db->insert(
            'INSERT INTO event_payments
             (event_id, payment_type, direction, amount, currency, status, method,
              processor_reference, invoice_reference, due_date, received_at, notes, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventId,
                $type,
                (string) ($b['direction'] ?? 'received'),
                $amount,
                strtoupper((string) ($b['currency'] ?? 'USD')),
                $status,
                $method,
                $b['processor_reference'] ?? null,
                $b['invoice_reference']   ?? null,
                date_or_null($b['due_date'] ?? null),
                $receivedAt,
                $b['notes'] ?? null,
                $this->userId(),
            ]
        );

        // Write audit record
        $this->db->run(
            'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, new_value_json)
             VALUES (?,?,?,?,?)',
            [$id, $eventId, $this->userId(), 'created', json_encode(['amount' => $amount, 'type' => $type, 'status' => $status])]
        );

        // Update event deposit_status if this is a deposit payment
        if ($type === 'deposit') {
            $this->syncDepositStatus($eventId);
        }

        log_activity($this->db, $eventId, $this->userId(), "payment recorded: $type \$$amount", [
            'payment_id' => $id,
            'type'       => $type,
            'amount'     => $amount,
            'status'     => $status,
        ]);

        return $this->ok(['id' => $id]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    private function update(Request $request, int $eventId, int $paymentId): Response
    {
        $payment = $this->db->one(
            'SELECT * FROM event_payments WHERE id = ? AND event_id = ?',
            [$paymentId, $eventId]
        );
        if (!$payment) {
            return $this->notFound('Payment not found');
        }

        $b = $request->body();

        $sets   = [];
        $params = [];

        $updatable = ['status','method','processor_reference','invoice_reference',
                      'due_date','received_at','notes','amount'];

        foreach ($updatable as $field) {
            if (!array_key_exists($field, $b)) continue;
            if ($field === 'due_date') {
                $b[$field] = date_or_null($b[$field]);
            } elseif ($field === 'amount') {
                $b[$field] = max(0, (float) $b[$field]);
            }
            $sets[]   = "$field = ?";
            $params[] = $b[$field];
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        // Set received_at automatically when status becomes 'received'
        if (($b['status'] ?? '') === 'received' && $payment['received_at'] === null) {
            $sets[]   = 'received_at = NOW()';
        }

        $params[] = $paymentId;
        $this->db->run('UPDATE event_payments SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        // Write audit record
        $this->db->run(
            'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, old_value_json, new_value_json)
             VALUES (?,?,?,?,?,?)',
            [$paymentId, $eventId, $this->userId(), 'updated',
             json_encode(['status' => $payment['status'], 'amount' => $payment['amount']]),
             json_encode(array_intersect_key($b, array_flip($updatable)))]
        );

        if (($payment['payment_type'] ?? '') === 'deposit') {
            $this->syncDepositStatus($eventId);
        }

        log_activity($this->db, $eventId, $this->userId(), 'payment updated', ['payment_id' => $paymentId]);

        return $this->ok(['ok' => true]);
    }

    // ── Void ─────────────────────────────────────────────────────────────────

    private function voidPayment(int $eventId, int $paymentId): Response
    {
        $payment = $this->db->one(
            'SELECT * FROM event_payments WHERE id = ? AND event_id = ?',
            [$paymentId, $eventId]
        );
        if (!$payment) {
            return $this->notFound('Payment not found');
        }

        $this->db->run(
            "UPDATE event_payments SET status = 'voided' WHERE id = ?",
            [$paymentId]
        );
        $this->db->run(
            'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, old_value_json) VALUES (?,?,?,?,?)',
            [$paymentId, $eventId, $this->userId(), 'voided', json_encode(['status' => $payment['status']])]
        );

        if (($payment['payment_type'] ?? '') === 'deposit') {
            $this->syncDepositStatus($eventId);
        }

        return Response::noContent();
    }

    // ── Waive deposit ─────────────────────────────────────────────────────────

    private function waiveDeposit(Request $request, int $eventId): Response
    {
        if ($denied = $this->requireEventCapability($eventId, 'waive_deposit')) {
            return $denied;
        }

        $b      = $request->body();
        $reason = trim((string) ($b['reason'] ?? ''));
        if ($reason === '') {
            return Response::json(['error' => 'A reason is required to waive the deposit'], 422);
        }

        $this->db->run(
            "UPDATE events SET deposit_status = 'waived', deposit_waived_by_id = ?, deposit_waived_reason = ? WHERE id = ?",
            [$this->userId(), $reason, $eventId]
        );

        log_activity($this->db, $eventId, $this->userId(), 'deposit waived', [
            'reason'    => $reason,
            'waived_by' => $this->userId(),
        ]);

        return $this->ok(['ok' => true, 'deposit_status' => 'waived']);
    }

    // ── Deposit status sync ───────────────────────────────────────────────────

    /**
     * Re-derive deposit_status from the current payment records and update
     * the events table.  Called after any deposit payment change.
     */
    public function syncDepositStatus(int $eventId): void
    {
        $event = $this->db->one(
            'SELECT deposit_amount, deposit_status FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return;
        }

        // Don't overwrite waived/refunded/not_required if set externally.
        if (in_array($event['deposit_status'], ['waived', 'not_required'], true)) {
            return;
        }

        $depositPayments = $this->db->all(
            "SELECT amount FROM event_payments
             WHERE event_id = ? AND payment_type = 'deposit' AND status = 'received'",
            [$eventId]
        );

        $received = array_sum(array_column($depositPayments, 'amount'));
        $required = (float) ($event['deposit_amount'] ?? 0);

        $status = 'requested';
        if ($received <= 0) {
            $status = 'requested';
        } elseif ($required > 0 && $received < $required) {
            $status = 'partially_received';
        } else {
            $status = 'received';
        }

        $this->db->run(
            'UPDATE events SET deposit_status = ? WHERE id = ?',
            [$status, $eventId]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildSummary(array $payments, ?array $event): array
    {
        $totalReceived  = 0;
        $totalDeposits  = 0;
        $totalBalance   = 0;
        $totalRefunds   = 0;
        $depositReceived = 0;

        foreach ($payments as $p) {
            $amount    = (float) $p['amount'];
            $type      = $p['payment_type'];
            $direction = $p['direction'];

            if ($direction === 'received') {
                $totalReceived += $amount;
                if ($type === 'deposit') {
                    $totalDeposits  += $amount;
                    $depositReceived += $amount;
                } elseif ($type === 'balance_payment') {
                    $totalBalance += $amount;
                }
            } elseif ($direction === 'paid_out') {
                // outgoing not counted as received
            }
            if ($type === 'refund' || $type === 'credit') {
                $totalRefunds += $amount;
            }
        }

        $depositRequired = (float) ($event['deposit_amount'] ?? 0);
        $depositOutstanding = max(0, $depositRequired - $depositReceived);

        return [
            'total_received'       => $totalReceived,
            'total_deposits'       => $totalDeposits,
            'total_balance'        => $totalBalance,
            'total_refunds'        => $totalRefunds,
            'deposit_required'     => $depositRequired,
            'deposit_received'     => $depositReceived,
            'deposit_outstanding'  => $depositOutstanding,
            'deposit_status'       => $event['deposit_status'] ?? 'not_required',
        ];
    }
}
