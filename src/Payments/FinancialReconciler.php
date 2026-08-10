<?php
declare(strict_types=1);

namespace Panic\Payments;

use Panic\Database;

/**
 * Copies provider-reported fee/tax amounts onto a payment and into the event
 * ledger. The provider is the source of truth: this service never estimates.
 * Reconciliation is idempotent, so webhooks and CLI backfills can safely retry.
 */
final class FinancialReconciler
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{processing_fee_cents:?int,tax_cents:?int,status:string} */
    public function reconcileTicketOrder(PaymentProvider $provider, array $order): array
    {
        return $this->reconcile(
            $provider,
            'ticket_orders',
            'ticket order',
            (int) $order['id'],
            (int) $order['event_id'],
            (string) ($order['provider_payment_ref'] ?? ''),
            (string) ($order['provider_ref'] ?? ''),
            (string) ($order['currency'] ?? 'USD')
        );
    }

    /** @return array{processing_fee_cents:?int,tax_cents:?int,status:string} */
    public function reconcileEventPayment(PaymentProvider $provider, array $payment): array
    {
        return $this->reconcile(
            $provider,
            'event_payments',
            'event payment',
            (int) $payment['id'],
            (int) $payment['event_id'],
            (string) ($payment['checkout_payment_ref'] ?? ''),
            (string) ($payment['external_ref'] ?? ''),
            (string) ($payment['currency'] ?? 'USD')
        );
    }

    /** @return array{processing_fee_cents:?int,tax_cents:?int,status:string} */
    private function reconcile(
        PaymentProvider $provider,
        string $table,
        string $kind,
        int $rowId,
        int $eventId,
        string $paymentRef,
        string $providerRef,
        string $currency
    ): array {
        if ($paymentRef === '') {
            $result = ['processing_fee_cents' => null, 'tax_cents' => null, 'status' => 'pending'];
            $this->persistResult($table, $rowId, $result);
            return $result;
        }

        try {
            $raw = $provider->financials($paymentRef, $providerRef);
            $fee = $this->amountOrNull($raw['processing_fee_cents'] ?? null);
            $tax = $this->amountOrNull($raw['tax_cents'] ?? null);
            $status = $this->normalizeStatus((string) ($raw['status'] ?? ''), $fee, $tax);
            $result = ['processing_fee_cents' => $fee, 'tax_cents' => $tax, 'status' => $status];
        } catch (\Throwable $e) {
            error_log(sprintf(
                'Payment financial reconciliation failed for %s #%d (%s): %s',
                $kind,
                $rowId,
                $provider->key(),
                $e->getMessage()
            ));
            $result = ['processing_fee_cents' => null, 'tax_cents' => null, 'status' => 'unavailable'];
        }

        $this->persistResult($table, $rowId, $result);
        $currency = strtoupper(substr(trim($currency), 0, 3)) ?: 'USD';
        $sourceRef = str_replace(' ', '_', $kind) . ':' . $rowId;

        if ($result['processing_fee_cents'] !== null) {
            $this->syncLedgerAmount(
                $eventId,
                $rowId,
                $sourceRef,
                'processing_fees',
                $result['processing_fee_cents'],
                $currency,
                ucfirst($provider->key()) . " processing fee for {$kind} #{$rowId}"
            );
        }
        if ($result['tax_cents'] !== null) {
            $this->syncLedgerAmount(
                $eventId,
                $rowId,
                $sourceRef,
                'taxes',
                $result['tax_cents'],
                $currency,
                ucfirst($provider->key()) . " sales tax for {$kind} #{$rowId}"
            );
        }

        return $result;
    }

    /**
     * Null provider values do not erase amounts captured by an earlier run.
     * This matters when one provider endpoint is temporarily unavailable.
     */
    private function persistResult(string $table, int $rowId, array $result): void
    {
        $sets = ['financials_status = ?', 'financials_updated_at = NOW()'];
        $params = [$result['status']];
        if ($result['processing_fee_cents'] !== null) {
            $sets[] = 'processor_fee_cents = ?';
            $params[] = $result['processing_fee_cents'];
        }
        if ($result['tax_cents'] !== null) {
            $sets[] = 'tax_cents = ?';
            $params[] = $result['tax_cents'];
        }
        $params[] = $rowId;
        $this->db->run("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    private function syncLedgerAmount(
        int $eventId,
        int $sourceId,
        string $sourceRef,
        string $category,
        int $cents,
        string $currency,
        string $description
    ): void {
        if ($cents === 0) {
            $this->db->run(
                "UPDATE event_ledger_entries
                    SET is_void = 1, void_reason = 'Provider reports no charge', reconciled_at = NOW()
                  WHERE event_id = ? AND source = 'ticketing_sync'
                    AND source_ref_str = ? AND category = ? AND is_void = 0",
                [$eventId, $sourceRef, $category]
            );
            return;
        }

        $this->db->run(
            "INSERT INTO event_ledger_entries
                (event_id, category, line_type, amount, currency, description, source,
                 source_ref_id, source_ref_str, reconciled_at, is_void, void_reason)
             VALUES (?, ?, 'cost', ?, ?, ?, 'ticketing_sync', ?, ?, NOW(), 0, NULL)
             ON DUPLICATE KEY UPDATE
                 amount = VALUES(amount), currency = VALUES(currency),
                 description = VALUES(description), reconciled_at = NOW(),
                 is_void = 0, void_reason = NULL",
            [$eventId, $category, $cents / 100, $currency, $description, $sourceId, $sourceRef]
        );
    }

    private function amountOrNull(mixed $amount): ?int
    {
        if ($amount === null || !is_numeric($amount)) {
            return null;
        }
        return max(0, (int) $amount);
    }

    private function normalizeStatus(string $status, ?int $fee, ?int $tax): string
    {
        if ($fee !== null && $tax !== null) {
            return 'reported';
        }
        if ($fee !== null || $tax !== null) {
            return 'partial';
        }
        return in_array($status, ['pending', 'unavailable'], true) ? $status : 'unavailable';
    }
}
