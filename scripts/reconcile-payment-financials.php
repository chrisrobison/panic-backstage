<?php
declare(strict_types=1);

/**
 * Backfill or retry provider-reported processor fees and taxes.
 *
 * Usage:
 *   php scripts/reconcile-payment-financials.php [--event-id=N] [--limit=N] [--pending-only]
 *
 * The command is safe to repeat: each payment owns one processing-fee ledger
 * row and one tax ledger row, updated in place from provider-reported values.
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Payments\FinancialReconciler;
use Panic\Payments\PaymentProviders;

Env::load($root . '/.env');

$eventId = 0;
$limit = 500;
$pendingOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--event-id=')) {
        $eventId = max(0, (int) substr($arg, strlen('--event-id=')));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(5000, (int) substr($arg, strlen('--limit='))));
    } elseif ($arg === '--pending-only') {
        $pendingOnly = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

try {
    $db = new Database();
    $env = new Env();
    $reconciler = new FinancialReconciler($db);
} catch (Throwable $e) {
    fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$eventWhere = $eventId > 0 ? ' AND event_id = ?' : '';
$statusWhere = $pendingOnly ? " AND financials_status != 'reported'" : '';
$params = $eventId > 0 ? [$eventId] : [];
$orders = $db->all(
    "SELECT * FROM ticket_orders
      WHERE provider IN ('square','stripe')
        AND provider_payment_ref IS NOT NULL AND provider_payment_ref != ''
        AND status IN ('paid','fulfilled','refunded'){$eventWhere}{$statusWhere}
      ORDER BY id ASC LIMIT {$limit}",
    $params
);
$payments = $db->all(
    "SELECT * FROM event_payments
      WHERE checkout_provider IN ('square','stripe')
        AND checkout_payment_ref IS NOT NULL AND checkout_payment_ref != ''
        AND status IN ('received','refunded'){$eventWhere}{$statusWhere}
      ORDER BY id ASC LIMIT {$limit}",
    $params
);

$providers = [];
$counts = ['reported' => 0, 'partial' => 0, 'pending' => 0, 'unavailable' => 0];
$run = static function (string $kind, array $row) use (
    &$providers,
    &$counts,
    $env,
    $reconciler
): void {
    $key = (string) ($kind === 'order' ? $row['provider'] : $row['checkout_provider']);
    $providers[$key] ??= PaymentProviders::byKey($key, $env);
    $provider = $providers[$key];
    if ($provider === null) {
        printf("%-13s #%d  unavailable (unknown provider %s)\n", $kind, (int) $row['id'], $key);
        $counts['unavailable']++;
        return;
    }

    $result = $kind === 'order'
        ? $reconciler->reconcileTicketOrder($provider, $row)
        : $reconciler->reconcileEventPayment($provider, $row);
    $status = $result['status'];
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    $fee = $result['processing_fee_cents'];
    $tax = $result['tax_cents'];
    printf(
        "%-13s #%d  %-11s fee=%s tax=%s\n",
        $kind,
        (int) $row['id'],
        $status,
        $fee === null ? 'waiting' : '$' . number_format($fee / 100, 2),
        $tax === null ? 'waiting' : '$' . number_format($tax / 100, 2)
    );
};

foreach ($orders as $order) {
    $run('order', $order);
}
foreach ($payments as $payment) {
    $run('event payment', $payment);
}

$total = count($orders) + count($payments);
printf(
    "Reconciled %d payment(s): %d reported, %d partial, %d pending, %d unavailable.\n",
    $total,
    $counts['reported'],
    $counts['partial'],
    $counts['pending'],
    $counts['unavailable']
);
