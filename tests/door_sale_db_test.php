<?php
/**
 * Walk-up door sales: TicketingService::recordDoorSale().
 *
 * Locks down the four properties that make a door sale safe to run against
 * live inventory while online checkout is also happening:
 *   1. the money lands in the ordinary order pipeline (provider='door',
 *      payment_method set, unit_price_cents = tier price) so existing revenue
 *      reporting picks it up with no special-casing;
 *   2. tickets are born 'redeemed' — the buyer is admitted at purchase, and
 *      the same ticket can never be walked in a second time;
 *   3. the oversell guard is all-or-nothing: a sale that would exceed
 *      quantity_total commits NOTHING (no order, no partial ticket run, no
 *      counter drift);
 *   4. inventory accounting agrees with availableQuantity() afterwards.
 *
 * DB-backed: creates a throwaway event + tier and deletes them in a finally
 * (this project shares one production MySQL — see tests/README and the
 * dev-environment memory).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\TicketingService;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Door sales (DB-backed) ===\n\n";

$db      = new Database();
$service = new TicketingService();
$marker  = bin2hex(random_bytes(4));

$venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
if ($venue === null) {
    echo "  no venue available — cannot run\n";
    exit(1);
}

$eventId = $db->insert(
    "INSERT INTO events (title, slug, date, venue_id, event_type, status)
     VALUES (?, ?, '2099-01-01', ?, 'live_music', 'confirmed')",
    ["PB TEST — door sale {$marker} (safe to delete)", "pb-test-door-sale-{$marker}", (int) $venue['id']]
);

try {
    $typeId = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
         VALUES (?, 'General', 2500, 'USD', 4, 'on_sale')",
        [$eventId]
    );

    // ── 1. a normal sale ─────────────────────────────────────────────────────
    $sale = $service->recordDoorSale($db, $typeId, 2, 'cash', 'Jane Doe', null, null, null);

    ok($sale['amount_cents'] === 5000, 'charges tier price × quantity (2 × $25 = $50)');
    ok(count($sale['tickets']) === 2, 'issues one ticket per unit sold');

    $order = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$sale['order_id']]);
    ok((string) $order['provider'] === 'door', "order provider is 'door'");
    ok((string) $order['payment_method'] === 'cash', 'payment method is recorded for reconciliation');
    ok((string) $order['status'] === 'fulfilled', 'order is fulfilled immediately');
    ok((int) $order['is_comp'] === 0, 'a door sale is not a comp');
    ok($order['paid_at'] !== null, 'paid_at is stamped');

    $item = $db->one('SELECT * FROM ticket_order_items WHERE order_id = ?', [$sale['order_id']]);
    ok((int) $item['unit_price_cents'] === 2500, 'line item carries the price actually charged');

    // Existing revenue reporting derives from SUM(quantity * unit_price_cents);
    // prove door money shows up there without any reporting change.
    $revenue = $db->one(
        'SELECT COALESCE(SUM(oi.quantity * oi.unit_price_cents), 0) AS cents
           FROM ticket_order_items oi
           JOIN ticket_orders o ON o.id = oi.order_id
          WHERE o.event_id = ? AND o.status IN (\'paid\', \'fulfilled\')',
        [$eventId]
    );
    ok((int) $revenue['cents'] === 5000, 'door revenue appears in the standard revenue rollup');

    // ── 2. tickets are born admitted ─────────────────────────────────────────
    $tickets = $db->all('SELECT * FROM tickets WHERE order_id = ?', [$sale['order_id']]);
    $allRedeemed = true;
    foreach ($tickets as $t) {
        if ((string) $t['status'] !== 'redeemed' || $t['redeemed_at'] === null) {
            $allRedeemed = false;
        }
    }
    ok($allRedeemed, "tickets are born 'redeemed' — the buyer is admitted at purchase");

    // The redeem UPDATE only matches status='issued', so a door-sold ticket can
    // never be flipped again by a scan. Assert that directly.
    $reAdmit = $db->run(
        "UPDATE tickets SET status = 'redeemed'
          WHERE id = ? AND event_id = ? AND status = 'issued'",
        [(int) $tickets[0]['id'], $eventId]
    );
    ok($reAdmit === 0, 'a door-sold ticket cannot be admitted a second time by a scan');

    // ── 3. oversell is all-or-nothing ────────────────────────────────────────
    $ordersBefore  = (int) $db->one('SELECT COUNT(*) c FROM ticket_orders WHERE event_id = ?', [$eventId])['c'];
    $ticketsBefore = (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'];

    $threw = false;
    try {
        // Only 2 of 4 remain; asking for 3 must fail outright.
        $service->recordDoorSale($db, $typeId, 3, 'card', null, null, null, null);
    } catch (\RuntimeException $e) {
        $threw = stripos($e->getMessage(), 'oversell') !== false;
    }
    ok($threw, 'selling past remaining inventory is refused');

    $sold = (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'];
    ok($sold === 2, 'a refused sale leaves quantity_sold untouched');
    ok(
        (int) $db->one('SELECT COUNT(*) c FROM ticket_orders WHERE event_id = ?', [$eventId])['c'] === $ordersBefore,
        'a refused sale writes no order row'
    );
    ok(
        (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'] === $ticketsBefore,
        'a refused sale issues no partial tickets'
    );

    // ── 4. availability agrees, and the last two still sell ──────────────────
    ok($service->availableQuantity($db, $typeId) === 2, 'availability reflects door sales');

    $last = $service->recordDoorSale($db, $typeId, 2, 'card', null, null, null, null);
    ok($last['amount_cents'] === 5000, 'the remaining inventory sells');
    ok($service->availableQuantity($db, $typeId) === 0, 'tier is exhausted at capacity, never beyond');

    // Cash/card split — the reason payment_method exists at all.
    // Keyed by method rather than positional: payment_method is an ENUM, so SQL
    // orders it by declaration ordinal, not alphabetically.
    $split = [];
    foreach ($db->all(
        "SELECT payment_method, SUM(amount_cents) cents
           FROM ticket_orders WHERE event_id = ? AND provider = 'door'
          GROUP BY payment_method",
        [$eventId]
    ) as $row) {
        $split[(string) $row['payment_method']] = (int) $row['cents'];
    }
    ok(
        count($split) === 2 && ($split['cash'] ?? 0) === 5000 && ($split['card'] ?? 0) === 5000,
        'cash and card reconcile separately ($50 each)'
    );

    // An unrecognized method is normalized rather than rejected mid-transaction.
    $odd = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, quantity_total, status)
         VALUES (?, 'Odd', 100, 1, 'on_sale')",
        [$eventId]
    );
    $oddSale = $service->recordDoorSale($db, $odd, 1, 'venmo', null, null, null, null);
    $oddOrder = $db->one('SELECT payment_method FROM ticket_orders WHERE id = ?', [$oddSale['order_id']]);
    ok((string) $oddOrder['payment_method'] === 'other', "an unknown payment method falls back to 'other'");
} finally {
    // FK ON DELETE CASCADE clears tiers/orders/items/tickets/scans with the event.
    $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
