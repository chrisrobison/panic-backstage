<?php
/** DB-backed regression coverage for provider fee/tax ledger reconciliation. */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Payments\FinancialReconciler;
use Panic\Payments\PaymentProvider;
use Panic\Request;

Env::load(dirname(__DIR__) . '/.env');

final class FinancialsFakeProvider implements PaymentProvider
{
    /** @var array{processing_fee_cents:?int,tax_cents:?int,status:string} */
    public array $result = ['processing_fee_cents' => 320, 'tax_cents' => 725, 'status' => 'reported'];
    public function key(): string { return 'square'; }
    public function createCheckout(array $order, array $items, string $successUrl, string $cancelUrl): array { return []; }
    public function verifyWebhook(Request $request): ?array { return null; }
    public function financials(string $providerPaymentRef, string $providerRef): array { return $this->result; }
    public function refund(string $providerPaymentRef, int $amountCents): array { return ['ok' => true, 'refund_ref' => null, 'error' => null]; }
    public function resolveInternalOrderId(string $providerRef): ?int { return null; }
}

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Payment financial reconciliation (DB-backed) ===\n\n";
$db = new Database();
$provider = new FinancialsFakeProvider();
$reconciler = new FinancialReconciler($db);
$venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
if ($venue === null) {
    echo "  no venue available — cannot run\n";
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$eventId = $db->insert(
    "INSERT INTO events (title, slug, date, venue_id, event_type, status)
     VALUES (?, ?, '2099-01-01', ?, 'live_music', 'confirmed')",
    ["PB TEST — financials {$marker}", "pb-test-financials-{$marker}", (int) $venue['id']]
);

try {
    $orderId = $db->insert(
        "INSERT INTO ticket_orders
            (event_id, provider, provider_ref, provider_payment_ref, amount_cents, currency, status)
         VALUES (?, 'square', 'order-ref', 'payment-ref', 10000, 'USD', 'fulfilled')",
        [$eventId]
    );
    $order = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$orderId]);
    $reconciler->reconcileTicketOrder($provider, $order);

    $stored = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$orderId]);
    ok((int) $stored['processor_fee_cents'] === 320, 'stores the exact provider processing fee');
    ok((int) $stored['tax_cents'] === 725, 'stores the exact provider tax');
    ok($stored['financials_status'] === 'reported', 'marks complete provider figures reported');

    $entries = $db->all(
        "SELECT * FROM event_ledger_entries WHERE event_id = ? AND source = 'ticketing_sync' ORDER BY category",
        [$eventId]
    );
    ok(count($entries) === 2, 'creates exactly one fee row and one tax row');
    $byCategory = [];
    foreach ($entries as $entry) {
        $byCategory[$entry['category']] = $entry;
    }
    ok((float) $byCategory['processing_fees']['amount'] === 3.20, 'fee ledger row is expressed in dollars');
    ok((float) $byCategory['taxes']['amount'] === 7.25, 'tax ledger row is expressed in dollars');

    $provider->result = ['processing_fee_cents' => 350, 'tax_cents' => 0, 'status' => 'reported'];
    $reconciler->reconcileTicketOrder($provider, $order);
    $entries = $db->all(
        "SELECT * FROM event_ledger_entries WHERE event_id = ? AND source = 'ticketing_sync' ORDER BY category",
        [$eventId]
    );
    ok(count($entries) === 2, 'retry updates existing rows without duplication');
    foreach ($entries as $entry) {
        $byCategory[$entry['category']] = $entry;
    }
    ok((float) $byCategory['processing_fees']['amount'] === 3.50, 'provider correction updates the fee row');
    ok((int) $byCategory['taxes']['is_void'] === 1, 'an explicit zero voids the obsolete tax row');

    $provider->result = ['processing_fee_cents' => null, 'tax_cents' => null, 'status' => 'unavailable'];
    $reconciler->reconcileTicketOrder($provider, $order);
    $stored = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$orderId]);
    ok((int) $stored['processor_fee_cents'] === 350, 'temporary provider failure does not erase a captured fee');
    ok((int) $stored['tax_cents'] === 0, 'temporary provider failure does not erase captured tax');

    $paymentId = $db->insert(
        "INSERT INTO event_payments
            (event_id, amount, currency, status, checkout_provider, external_ref, checkout_payment_ref)
         VALUES (?, 250.00, 'USD', 'received', 'square', 'event-order-ref', 'event-payment-ref')",
        [$eventId]
    );
    $provider->result = ['processing_fee_cents' => 755, 'tax_cents' => 1800, 'status' => 'reported'];
    $payment = $db->one('SELECT * FROM event_payments WHERE id = ?', [$paymentId]);
    $reconciler->reconcileEventPayment($provider, $payment);
    $storedPayment = $db->one('SELECT * FROM event_payments WHERE id = ?', [$paymentId]);
    ok((int) $storedPayment['processor_fee_cents'] === 755, 'event-payment checkout fee is reconciled too');
    ok((int) $storedPayment['tax_cents'] === 1800, 'event-payment checkout tax is reconciled too');
    ok(
        (int) $db->one("SELECT COUNT(*) c FROM event_ledger_entries WHERE event_id = ? AND source = 'ticketing_sync'", [$eventId])['c'] === 4,
        'ticket orders and event payments own distinct ledger rows'
    );
} finally {
    $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
