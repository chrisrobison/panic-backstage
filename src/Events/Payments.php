<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Database;
use Panic\Env;
use Panic\Payments\PaymentProviders;
use Panic\Request;
use Panic\Response;
use RuntimeException;
use function Panic\log_activity;
use function Panic\date_or_null;
use function Panic\boolish;
use function Panic\event_public_path;

/**
 * Event payment records — deposits, balance payments, refunds, etc.
 *
 *   GET    /api/events/{id}/payments                    list payments + deposit summary
 *   POST   /api/events/{id}/payments                    record a payment
 *   PATCH  /api/events/{id}/payments/{pid}              update a payment
 *   DELETE /api/events/{id}/payments/{pid}              void/delete a payment
 *   POST   /api/events/{id}/payments/0/send-link        checkout link+QR for an existing payment record
 *   POST   /api/events/{id}/payments/0/deposit-link     find-or-create the deposit record, then the above
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
    private const STATUSES      = ['pending','invoiced','received','failed','refunded','voided'];

    public function handle(Request $request): Response
    {
        $eventId   = $this->requireEventId();
        $paymentId = $this->params['paymentId'] ?? null;
        $action    = $this->params['action']    ?? null;

        // Waive deposit — high-privilege action
        if ($action === 'waive-deposit' && $request->method() === 'POST') {
            return $this->waiveDeposit($request, $eventId);
        }

        // Checkout link + QR (Stripe or Square, whichever is active) for a
        // pending/invoiced payment record.
        if ($action === 'send-link' && $request->method() === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'manage_payments')) {
                return $denied;
            }
            return $this->sendPaymentLink($eventId, $request, $paymentId);
        }

        // Convenience wrapper for the Booking Inbox (and anywhere else with no
        // Payments-tab payment record already on screen): find this event's
        // outstanding deposit payment, creating the record first if the event
        // has a deposit configured but nobody has added it yet, then mint/reuse
        // its checkout link exactly like send-link above.
        if ($action === 'deposit-link' && $request->method() === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'manage_payments')) {
                return $denied;
            }
            return $this->depositLink($eventId);
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
            self::syncDepositStatus($this->db, $eventId);
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
            self::syncDepositStatus($this->db, $eventId);
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
            self::syncDepositStatus($this->db, $eventId);
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
     *
     * Static (takes $db explicitly) so src/Webhooks.php can call it directly
     * after auto-confirming a checkout payment without instantiating a full
     * BaseEndpoint (which needs a Request/Auth this call site doesn't have).
     */
    public static function syncDepositStatus(Database $db, int $eventId): void
    {
        $event = $db->one(
            'SELECT deposit_amount, deposit_status FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return;
        }

        // Don't overwrite a deliberately-set waived/refunded state. `not_required`
        // is *not* included here even though it's also a deliberate state in some
        // cases — it's the deposit_status column's DB default, so every event
        // that has never had a deposit payment recorded sits at `not_required`
        // whether or not a deposit is actually required. Treating it as sticky
        // like `waived` would mean a real deposit's first payment could never
        // move deposit_status off that default. Instead, "no deposit required"
        // is derived straight from deposit_amount below.
        if (in_array($event['deposit_status'], ['waived', 'refunded'], true)) {
            return;
        }
        if ((float) ($event['deposit_amount'] ?? 0) <= 0) {
            return;
        }

        $depositPayments = $db->all(
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

        $db->run(
            'UPDATE events SET deposit_status = ? WHERE id = ?',
            [$status, $eventId]
        );
    }

    // ── Checkout link + QR (Stripe/Square, whichever is active) ─────────────────

    /**
     * Mints (or reuses) a checkout link + QR for an existing payment record.
     *
     * Endpoint: POST /api/events/{id}/payments/{pid}/send-link
     *
     * @param int      $eventId   Event context (from URL)
     * @param Request  $request   Incoming request (body: payment_id fallback)
     * @param int|null $paymentId Payment ID from URL segment; falls back to body
     */
    private function sendPaymentLink(int $eventId, Request $request, ?int $paymentId): Response
    {
        $b = $request->body();

        // Resolve payment ID: prefer URL param, fall back to request body.
        $resolvedId = $paymentId > 0 ? $paymentId : (int) ($b['payment_id'] ?? 0);
        if ($resolvedId <= 0) {
            return Response::json(['error' => 'payment_id is required'], 422);
        }

        $payment = $this->db->one(
            'SELECT * FROM event_payments WHERE id = ? AND event_id = ?',
            [$resolvedId, $eventId]
        );
        if (!$payment) {
            return $this->notFound('Payment record not found');
        }

        try {
            $result = $this->mintOrReuseCheckoutLink($eventId, $payment);
        } catch (RuntimeException $e) {
            // mintOrReuseCheckoutLink() codes provider-side failures 502,
            // leaving validation throws at the default 0 (-> 422 here).
            return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }

        return $this->ok($result);
    }

    /**
     * Convenience wrapper for callers with no payment record already on
     * screen — the Booking Inbox action bar in particular, reachable as soon
     * as a lead is onboarded but before anyone has opened the event's
     * Payments tab. Finds this event's outstanding deposit payment,
     * creating the record first (for the outstanding balance, not
     * necessarily the full deposit_amount if something was already received)
     * if nobody has added one yet, then mints/reuses its checkout link.
     *
     * Endpoint: POST /api/events/{id}/payments/0/deposit-link
     */
    private function depositLink(int $eventId): Response
    {
        $event = $this->db->one(
            'SELECT id, title, deposit_amount, deposit_status, booker_email, promoter_email
             FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound('Event not found');
        }
        if ((float) ($event['deposit_amount'] ?? 0) <= 0) {
            return Response::json(['error' => 'This event has no deposit configured.'], 422);
        }
        if (($event['deposit_status'] ?? '') === 'waived') {
            return Response::json(['error' => "This event's deposit has been waived — no payment is needed."], 422);
        }

        $payment = $this->db->one(
            "SELECT * FROM event_payments
             WHERE event_id = ? AND payment_type = 'deposit' AND status IN ('pending','invoiced')
             ORDER BY id ASC LIMIT 1",
            [$eventId]
        );

        if (!$payment) {
            $receivedRow = $this->db->one(
                "SELECT COALESCE(SUM(amount), 0) total FROM event_payments
                 WHERE event_id = ? AND payment_type = 'deposit' AND status = 'received'",
                [$eventId]
            );
            $outstanding = round((float) $event['deposit_amount'] - (float) ($receivedRow['total'] ?? 0), 2);
            if ($outstanding <= 0) {
                return Response::json(['error' => "This event's deposit has already been fully received."], 422);
            }

            $id = $this->db->insert(
                'INSERT INTO event_payments
                 (event_id, payment_type, direction, amount, currency, status, created_by_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$eventId, 'deposit', 'received', $outstanding, 'USD', 'pending', $this->userId()]
            );
            $this->db->run(
                'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, new_value_json)
                 VALUES (?,?,?,?,?)',
                [$id, $eventId, $this->userId(), 'created',
                 json_encode(['amount' => $outstanding, 'type' => 'deposit', 'status' => 'pending', 'source' => 'deposit-link'])]
            );
            $payment = $this->db->one('SELECT * FROM event_payments WHERE id = ?', [$id]);
        }

        try {
            $result = $this->mintOrReuseCheckoutLink($eventId, $payment);
        } catch (RuntimeException $e) {
            // mintOrReuseCheckoutLink() codes provider-side failures 502,
            // leaving validation throws at the default 0 (-> 422 here).
            return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }

        return $this->ok($result + ['payment_id' => (int) $payment['id']]);
    }

    /**
     * Core checkout-link logic shared by sendPaymentLink() and depositLink():
     * reuse an unexpired, not-yet-paid cached link if one exists, otherwise
     * mint a fresh one through whichever provider is currently active
     * (Panic\Payments\PaymentProviders — the exact same provider-agnostic
     * checkout abstraction PublicTickets.php uses for ticket purchases, so a
     * deposit link always routes to whichever of Stripe/Square is actually
     * configured instead of assuming Stripe). Persists the link and marks the
     * payment 'invoiced'; src/Webhooks.php auto-confirms it to 'received'
     * once the provider's payment_succeeded webhook arrives.
     *
     * @return array{payment_link:string,provider:string}
     * @throws RuntimeException on a validation or provider failure — callers
     *         turn this into a 422 JSON response.
     */
    private function mintOrReuseCheckoutLink(int $eventId, array $payment): array
    {
        if ((float) $payment['amount'] <= 0) {
            throw new RuntimeException('Payment amount must be greater than 0.');
        }
        if (in_array($payment['status'], ['received', 'voided'], true)) {
            throw new RuntimeException('This payment is already ' . $payment['status'] . ' — no link needed.');
        }

        // Reuse the cached link while it's still good — this is what makes
        // clicking "send link" again idempotent instead of minting a brand
        // new Stripe/Square checkout (and orphaning the old one) every time.
        $expiresAt = $payment['checkout_expires_at'] ?? null;
        if (!empty($payment['checkout_url']) && !empty($payment['checkout_provider'])
            && ($expiresAt === null || strtotime($expiresAt . ' UTC') > time())
        ) {
            return [
                'payment_link' => (string) $payment['checkout_url'],
                'provider'     => (string) $payment['checkout_provider'],
            ];
        }

        $event = $this->db->one(
            'SELECT title, booker_email, promoter_email FROM events WHERE id = ?',
            [$eventId]
        );

        $amountCents = (int) round((float) $payment['amount'] * 100);
        $currency    = strtoupper((string) ($payment['currency'] ?: 'USD'));
        $label       = ucfirst(str_replace('_', ' ', (string) $payment['payment_type'])) . ' — ' . ($event['title'] ?? 'Event');
        $email       = (string) ($event['booker_email'] ?? '') ?: (string) ($event['promoter_email'] ?? '');

        // Bounce back to the event's public page — plain ?deposit=paid/canceled
        // query flags, deliberately NOT the ?checkout=success&order=... shape
        // tickets-public.js reads (that component polls a real ticket_orders
        // row by that order id + a receipt token; a payment id there would 404).
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $eventUrl = $appUrl . '/' . event_public_path(['id' => $eventId]);
        $success  = $eventUrl . '&deposit=paid';
        $cancel   = $eventUrl . '&deposit=canceled';

        $provider = PaymentProviders::active($this->db, new Env());

        $order = [
            'id'          => (int) $payment['id'],
            'currency'    => $currency,
            'buyer_email' => $email,
        ];
        $items = [[
            'name'             => $label,
            'quantity'         => 1,
            'unit_price_cents' => $amountCents,
        ]];

        // Code 502 on any provider-side failure (misconfigured, rejected the
        // request, bad response) — distinct from the 0/422-coded validation
        // throws above. Callers map $e->getCode() ?: 422 to the response status.
        try {
            $result = $provider->createCheckout($order, $items, $success, $cancel);
        } catch (\Throwable $e) {
            throw new RuntimeException('Payment provider error: ' . $e->getMessage(), 502);
        }
        $checkoutUrl = (string) ($result['checkout_url'] ?? '');
        $providerRef = (string) ($result['provider_ref'] ?? '');
        if ($checkoutUrl === '' || $providerRef === '') {
            throw new RuntimeException('Payment provider did not return a checkout link.', 502);
        }

        // Our own bookkeeping expiry for reuse decisions above — not
        // necessarily the provider's own session TTL (Stripe Checkout
        // Sessions default to ~24h; Square payment links don't expire on
        // their own by default). Past this, the next click mints a fresh one.
        $expiresAtNew = date('Y-m-d H:i:s', time() + 24 * 3600);

        $this->db->run(
            "UPDATE event_payments
             SET status = 'invoiced', external_ref = ?, checkout_provider = ?,
                 checkout_url = ?, checkout_expires_at = ?,
                 notes = CONCAT(COALESCE(notes, ''), ' | Payment link sent: ', ?)
             WHERE id = ?",
            [$providerRef, $provider->key(), $checkoutUrl, $expiresAtNew, date('Y-m-d'), (int) $payment['id']]
        );

        $this->db->run(
            'INSERT INTO event_payment_audit
             (payment_id, event_id, user_id, action, note)
             VALUES (?, ?, ?, ?, ?)',
            [(int) $payment['id'], $eventId, $this->userId(), 'invoice_link_sent',
             ucfirst($provider->key()) . ' payment link: ' . $checkoutUrl]
        );

        log_activity($this->db, $eventId, $this->userId(), ucfirst($provider->key()) . ' payment link sent', [
            'payment_id'   => (int) $payment['id'],
            'provider'     => $provider->key(),
            'provider_ref' => $providerRef,
            'amount'       => $payment['amount'],
        ]);

        return ['payment_link' => $checkoutUrl, 'provider' => $provider->key()];
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
