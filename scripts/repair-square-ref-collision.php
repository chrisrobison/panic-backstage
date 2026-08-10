<?php
declare(strict_types=1);

/**
 * One-off repair for the Square `reference_id` namespace collision
 * (2026-08-09, event 670491 / Lydia Breen).
 *
 * What happened: Events\Payments::mintOrReuseCheckoutLink() and
 * PublicTickets both write a BARE NUMERIC `reference_id` into the Square
 * order — the first from `event_payments.id`, the second from
 * `ticket_orders.id`. Webhooks::matchOrder()'s fallback,
 * SquareProvider::resolveInternalOrderId(), reads that field back and assumes
 * it always names a ticket order. Client payment #8 therefore resolved to
 * ticket_orders #8 (an unrelated abandoned June test order), which the
 * receiver then "self-healed" by overwriting its provider_ref with the
 * client's Square order id, cancelled, and finally fulfilled — issuing two
 * real tickets — while the client's actual payment row was never marked
 * received.
 *
 * Steps, each independently idempotent and each verifying its own
 * preconditions before writing (so a re-run after a partial failure is safe):
 *
 *   step0  Disarm the refund hazard: ticket_orders #8 still carries the
 *          CLIENT'S payment id in provider_payment_ref, so a refund issued
 *          against that ticket order would refund her $1,951.25. Restores the
 *          original values recovered from db_history #45469.
 *   step1  Record the client's confirmed Square payment on event_payments #8.
 *   step2  Void the two tickets created by the collision and return the
 *          unrelated, expired ticket order to a canceled/unpaid state.
 *   receipt Generate the client's PDF receipt; add --email to deliver it.
 *
 * Usage:
 *   php scripts/repair-square-ref-collision.php step0 --dry-run
 *   php scripts/repair-square-ref-collision.php step0 --commit
 *   php scripts/repair-square-ref-collision.php step1 --commit
 *   php scripts/repair-square-ref-collision.php step2 --commit
 *   php scripts/repair-square-ref-collision.php receipt
 *   php scripts/repair-square-ref-collision.php receipt --email
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;

Env::load($root . '/.env');

$step   = $argv[1] ?? 'report';
$commit = in_array('--commit', $argv, true);
$email  = in_array('--email', $argv, true);

$db = new Database();
$db->setActor('cli:repair-square-ref-collision');

// Values recovered from db_history id 45469 (the UPDATE that clobbered them).
const TICKET_ORDER_ID       = 8;
const ORIGINAL_PROVIDER_REF = 'R7SFQXADE35V2DD4';
const CLIENT_SQUARE_ORDER   = 'DHkxdAK2L0KQSrahBw1BcRDkcVDZY';
const CLIENT_SQUARE_PAYMENT = 'hIgIzaHqjVMTKYyL5YNErgASWq8YY';
const EVENT_ID                = 670491;
const EVENT_PAYMENT_ID        = 8;
const PAID_AT_UTC             = '2026-08-09 19:10:11';
const COLLISION_TICKET_IDS    = [426, 427];

function show(string $label, $value): void
{
    printf("  %-24s %s\n", $label, is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value));
}

$order = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [TICKET_ORDER_ID]);
if ($order === null) {
    fwrite(STDERR, "ticket_orders #" . TICKET_ORDER_ID . " not found — nothing to repair.\n");
    exit(1);
}

echo "\nticket_orders #" . TICKET_ORDER_ID . " (current)\n";
show('event_id', $order['event_id']);
show('status', $order['status']);
show('provider_ref', $order['provider_ref']);
show('provider_payment_ref', $order['provider_payment_ref']);
show('buyer_email', $order['buyer_email']);

if ($step === 'step0') {
    // Only act on the exact contaminated state. If provider_ref is already the
    // original, this has run before and there is nothing left to do.
    if ((string) $order['provider_ref'] !== CLIENT_SQUARE_ORDER
        && (string) $order['provider_payment_ref'] !== CLIENT_SQUARE_PAYMENT) {
        echo "\nAlready repaired (no client references on this order). Nothing to do.\n";
        exit(0);
    }

    echo "\nWould restore:\n";
    show('provider_ref ->', ORIGINAL_PROVIDER_REF);
    show('provider_payment_ref ->', null);
    echo "\nRationale: those two columns currently point at the CLIENT'S Square\n"
       . "order/payment, so a refund on this ticket order would refund her.\n";

    if (!$commit) {
        echo "\n[dry run] Re-run with --commit to apply.\n";
        exit(0);
    }

    $changed = $db->run(
        'UPDATE ticket_orders
            SET provider_ref = ?, provider_payment_ref = NULL
          WHERE id = ? AND provider_ref = ?',
        [ORIGINAL_PROVIDER_REF, TICKET_ORDER_ID, CLIENT_SQUARE_ORDER]
    );
    // The guarded WHERE means 0 rows = someone changed it underneath us.
    if ($changed !== 1) {
        // Fall back to clearing only the payment ref, which is the actual hazard.
        $changed = $db->run(
            'UPDATE ticket_orders SET provider_payment_ref = NULL WHERE id = ? AND provider_payment_ref = ?',
            [TICKET_ORDER_ID, CLIENT_SQUARE_PAYMENT]
        );
    }

    $after = $db->one('SELECT provider_ref, provider_payment_ref FROM ticket_orders WHERE id = ?', [TICKET_ORDER_ID]);
    echo "\nAfter:\n";
    show('provider_ref', $after['provider_ref'] ?? null);
    show('provider_payment_ref', $after['provider_payment_ref'] ?? null);
    echo "\nRefund hazard cleared.\n";
    exit(0);
}

if ($step === 'step1') {
    $payment = $db->one('SELECT * FROM event_payments WHERE id = ? AND event_id = ?', [EVENT_PAYMENT_ID, EVENT_ID]);
    if ($payment === null
        || (string) $payment['external_ref'] !== CLIENT_SQUARE_ORDER
        || (string) $payment['checkout_provider'] !== 'square'
        || abs((float) $payment['amount'] - 1951.25) > 0.001
    ) {
        fwrite(STDERR, "event_payments #8 no longer has the exact verified incident values — refusing to write.\n");
        exit(1);
    }
    echo "\nevent_payments #8\n";
    show('status', $payment['status']);
    show('amount', $payment['amount']);
    show('provider', $payment['checkout_provider']);
    show('payment ref', $payment['checkout_payment_ref']);
    echo "\nWould record Square COMPLETED at " . PAID_AT_UTC . " UTC and mint its receipt token.\n";
    if (!$commit) {
        echo "\n[dry run] Re-run with --commit to apply.\n";
        exit(0);
    }

    $receipt = new Panic\EventPaymentReceiptService($db, $root);
    $token = $receipt->ensureToken(EVENT_PAYMENT_ID);
    $db->run(
        "UPDATE event_payments
         SET status = 'received', method = 'square', received_at = ?, checkout_payment_ref = ?
         WHERE id = ? AND event_id = ?",
        [PAID_AT_UTC, CLIENT_SQUARE_PAYMENT, EVENT_PAYMENT_ID, EVENT_ID]
    );
    $alreadyAudited = $db->one(
        "SELECT id FROM event_payment_audit WHERE payment_id = ? AND action = 'checkout_paid' LIMIT 1",
        [EVENT_PAYMENT_ID]
    );
    if ($alreadyAudited === null) {
        $db->run(
            'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, note)
             VALUES (?, ?, NULL, ?, ?)',
            [EVENT_PAYMENT_ID, EVENT_ID, 'checkout_paid', 'Square checkout completed (incident repair; provider verified)']
        );
        Panic\log_activity($db, EVENT_ID, null, 'Square payment received (incident repair)', [
            'payment_id' => EVENT_PAYMENT_ID,
            'provider' => 'square',
            'amount' => 1951.25,
        ]);
    }
    echo "\nPayment repaired.\n";
    show('receipt token', $token);
    show('receipt URL', $receipt->receiptUrl(EVENT_PAYMENT_ID, $token));
    exit(0);
}

if ($step === 'step2') {
    $tickets = $db->all(
        'SELECT id, order_id, status, created_at FROM tickets WHERE order_id = ? ORDER BY id',
        [TICKET_ORDER_ID]
    );
    $ids = array_map(static fn(array $ticket): int => (int) $ticket['id'], $tickets);
    sort($ids);
    $expected = COLLISION_TICKET_IDS;
    sort($expected);
    if ($ids !== [] && $ids !== $expected) {
        fwrite(STDERR, 'ticket order #8 has an unexpected ticket set (' . implode(', ', $ids) . ") — refusing to write.\n");
        exit(1);
    }
    echo "\nCollision-issued tickets: " . ($ids === [] ? 'none' : implode(', ', $ids)) . "\n";
    echo "Would void those tickets and leave ticket order #8 canceled/unpaid with its original provider ref.\n";
    if (!$commit) {
        echo "\n[dry run] Re-run with --commit to apply.\n";
        exit(0);
    }
    if ($ids !== []) {
        $db->run(
            "UPDATE tickets SET status = 'void', voided_at = COALESCE(voided_at, NOW())
             WHERE order_id = ? AND id IN (?, ?)",
            [TICKET_ORDER_ID, COLLISION_TICKET_IDS[0], COLLISION_TICKET_IDS[1]]
        );
    }
    $db->run(
        "UPDATE ticket_orders
         SET status = 'canceled', provider_ref = ?, provider_payment_ref = NULL,
             hold_expires_at = NULL, paid_at = NULL, emailed_at = NULL
         WHERE id = ?",
        [ORIGINAL_PROVIDER_REF, TICKET_ORDER_ID]
    );
    echo "\nCollision ticket state cleaned up.\n";
    exit(0);
}

if ($step === 'receipt') {
    $receipt = new Panic\EventPaymentReceiptService($db, $root);
    $token = $receipt->ensureToken(EVENT_PAYMENT_ID);
    $data = $receipt->load(EVENT_PAYMENT_ID, $token);
    if ($data === null || (string) $data['payment_status'] !== 'received') {
        fwrite(STDERR, "Payment #8 is not in a receipt-ready state. Run step1 --commit first.\n");
        exit(1);
    }
    $pdf = $receipt->renderPdf($data);
    $dir = $root . '/storage/receipts/event-' . EVENT_ID;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create receipt directory {$dir}");
    }
    $path = $dir . '/' . strtolower((string) $data['receipt_number']) . '.pdf';
    if (file_put_contents($path, $pdf, LOCK_EX) === false) {
        throw new RuntimeException("Could not write receipt PDF {$path}");
    }
    echo "\nReceipt ready:\n";
    show('PDF', $path);
    show('URL', $receipt->receiptUrl(EVENT_PAYMENT_ID, $token));
    show('bytes', strlen($pdf));

    if (!$email) {
        echo "\nNot emailed. Re-run with --email after reviewing the PDF.\n";
        exit(0);
    }
    if (!empty($data['receipt_emailed_at'])) {
        echo "\nReceipt was already emailed at {$data['receipt_emailed_at']} UTC; not sending again.\n";
        exit(0);
    }
    if (!$receipt->email($data)) {
        fwrite(STDERR, "Receipt email was not accepted by the local MTA.\n");
        exit(1);
    }
    $db->run('UPDATE event_payments SET receipt_emailed_at = NOW() WHERE id = ?', [EVENT_PAYMENT_ID]);
    $db->run(
        'INSERT INTO event_payment_audit (payment_id, event_id, user_id, action, note)
         VALUES (?, ?, NULL, ?, ?)',
        [EVENT_PAYMENT_ID, EVENT_ID, 'receipt_emailed', 'Payment receipt emailed to client (incident repair)']
    );
    echo "\nReceipt email accepted for delivery.\n";
    exit(0);
}

echo "\n(no step selected — use step0, step1, step2, or receipt; see file header)\n";
