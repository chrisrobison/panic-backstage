<?php
/**
 * Tests for Panic\TicketDiscounts — the discount-code money math behind
 * private coupon codes for in-house ticketing (see src/TicketDiscounts.php).
 *
 * What matters here is that a discounted cart is exact to the cent. The
 * discount is folded into ticket_order_items.unit_price_cents, which is the
 * same number the payment provider charges AND the number every revenue report
 * sums (SUM(oi.quantity * oi.unit_price_cents)). A rounding bug would not just
 * mis-charge a buyer, it would silently desync the books from the till — so
 * the central invariant below is asserted directly and then fuzzed over a few
 * thousand random carts.
 *
 * Pure — no DB, no bootstrap beyond the autoloader. Picked up automatically by
 * tests/run-php-tests.sh.
 *
 * Run with: php tests/ticket_discounts_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\TicketDiscounts;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

/** Build a line item the way PublicTickets::createOrder does. */
function line(int $typeId, int $qty, int $unitCents, string $name = 'Tier'): array {
    return [
        'ticket_type_id'   => $typeId,
        'name'             => $name,
        'quantity'         => $qty,
        'unit_price_cents' => $unitCents,
    ];
}

function percentCode(int $pct): array {
    return ['id' => 1, 'kind' => 'percent', 'percent_off' => $pct, 'amount_off_cents' => 0];
}

function fixedCode(int $cents): array {
    return ['id' => 1, 'kind' => 'fixed', 'percent_off' => 0, 'amount_off_cents' => $cents];
}

/** Total of a set of line items. */
function total(array $lines): int {
    $sum = 0;
    foreach ($lines as $l) { $sum += (int) $l['quantity'] * (int) $l['unit_price_cents']; }
    return $sum;
}

/** Units issued per tier — must be preserved even when a line is split. */
function unitsByType(array $lines): array {
    $out = [];
    foreach ($lines as $l) {
        $id = (int) $l['ticket_type_id'];
        $out[$id] = ($out[$id] ?? 0) + (int) $l['quantity'];
    }
    ksort($out);
    return $out;
}

echo "\n=== TicketDiscounts ===\n\n";

// ── normalizeCode ───────────────────────────────────────────────────────────
echo "-- normalizeCode --\n";
ok(TicketDiscounts::normalizeCode('  eastbay30 ') === 'EASTBAY30', 'trims and uppercases');
ok(TicketDiscounts::normalizeCode('east bay 30') === 'EASTBAY30', 'strips internal whitespace');
ok(TicketDiscounts::normalizeCode("east\tbay\n30") === 'EASTBAY30', 'strips tabs/newlines');
ok(TicketDiscounts::normalizeCode('') === '', 'empty stays empty');
ok(strlen(TicketDiscounts::normalizeCode(str_repeat('A', 80))) === 40, 'clamps to column width');

// ── percent ─────────────────────────────────────────────────────────────────
echo "\n-- percent --\n";
$r = TicketDiscounts::apply(percentCode(30), [line(1, 2, 2500)], []);
ok($r['discount_cents'] === 1500, '30% off 2 x $25.00 discounts $15.00');
ok(total($r['lines']) === 3500, '  ... leaving $35.00 charged');
ok(count($r['lines']) === 1, '  ... without splitting the line');

$r = TicketDiscounts::apply(percentCode(33), [line(1, 1, 999)], []);
ok($r['discount_cents'] === 330, '33% off $9.99 rounds to $3.30');
ok(total($r['lines']) === 669, '  ... charging $6.69');

$r = TicketDiscounts::apply(percentCode(100), [line(1, 3, 2500)], []);
ok($r['discount_cents'] === 7500 && total($r['lines']) === 0, '100% off zeroes the cart');

$r = TicketDiscounts::apply(percentCode(0), [line(1, 3, 2500)], []);
ok($r['discount_cents'] === 0 && total($r['lines']) === 7500, '0% off changes nothing');

// ── fixed ───────────────────────────────────────────────────────────────────
echo "\n-- fixed --\n";
$r = TicketDiscounts::apply(fixedCode(1000), [line(1, 1, 2500)], []);
ok($r['discount_cents'] === 1000 && total($r['lines']) === 1500, '$10 off a single $25 ticket');

// 1000 across 3 units: 333 each with 1 cent left over -> one unit a cent cheaper.
$r = TicketDiscounts::apply(fixedCode(1000), [line(1, 3, 2500)], []);
ok($r['discount_cents'] === 1000, '$10 off 3 x $25 discounts exactly $10.00');
ok(total($r['lines']) === 6500, '  ... charging exactly $65.00 (no rounding drift)');
ok(count($r['lines']) === 2, '  ... by splitting the line in two');
ok(unitsByType($r['lines']) === [1 => 3], '  ... still issuing 3 tickets');

// Clamped: never let a cart go negative.
$r = TicketDiscounts::apply(fixedCode(10000), [line(1, 1, 2500)], []);
ok($r['discount_cents'] === 2500 && total($r['lines']) === 0, '$100 off a $25 cart clamps to $25');

// Proportional across two tiers of different value.
$r = TicketDiscounts::apply(fixedCode(1000), [line(1, 1, 2000), line(2, 1, 3000)], []);
ok($r['discount_cents'] === 1000, '$10 across two tiers discounts exactly $10.00');
ok(total($r['lines']) === 4000, '  ... charging $40.00 of a $50.00 cart');

// ── tier scoping ────────────────────────────────────────────────────────────
echo "\n-- tier scoping --\n";
$lines = [line(1, 1, 2500, 'Advance'), line(2, 1, 3000, 'Door')];
$r = TicketDiscounts::apply(percentCode(50), $lines, [1]);
ok($r['discount_cents'] === 1250, 'scoped percent only discounts the named tier');
ok(total($r['lines']) === 4250, '  ... leaving the other tier at full price');
ok($r['eligible_subtotal_cents'] === 2500, '  ... and reports only the eligible subtotal');

$r = TicketDiscounts::apply(fixedCode(5000), $lines, [2]);
ok($r['discount_cents'] === 3000, 'scoped fixed clamps to the eligible tier, not the cart');
ok(total($r['lines']) === 2500, '  ... charging the untouched tier in full');

// A cart containing none of the scoped tiers is a no-op (the endpoint turns
// this into a "not valid for the selected tickets" message).
$r = TicketDiscounts::apply(percentCode(50), [line(9, 2, 2500)], [1]);
ok($r['discount_cents'] === 0 && total($r['lines']) === 5000, 'out-of-scope cart is untouched');
ok($r['eligible_subtotal_cents'] === 0, '  ... and reports a zero eligible subtotal');

// ── free tiers ──────────────────────────────────────────────────────────────
echo "\n-- free tiers --\n";
$r = TicketDiscounts::apply(fixedCode(1000), [line(1, 1, 0), line(2, 1, 2500)], []);
ok($r['discount_cents'] === 1000 && total($r['lines']) === 1500,
   'a $0 tier soaks up none of a fixed discount');
$r = TicketDiscounts::apply(percentCode(50), [line(1, 2, 0)], []);
ok($r['discount_cents'] === 0 && total($r['lines']) === 0, 'an all-free cart discounts nothing');

// ── the invariant, fuzzed ───────────────────────────────────────────────────
//
// For ANY cart and ANY code: the charged total is exactly the face total minus
// the reported discount, no unit price is negative, and the number of tickets
// issued per tier is unchanged by line splitting.
echo "\n-- invariant fuzz (3000 random carts) --\n";
mt_srand(20260803);
$violations = 0;
$splitsSeen = 0;
for ($i = 0; $i < 3000; $i++) {
    $lines = [];
    $tiers = mt_rand(1, 4);
    for ($t = 1; $t <= $tiers; $t++) {
        // Include the occasional free tier and the occasional odd price.
        $unit = mt_rand(0, 20) === 0 ? 0 : mt_rand(1, 12345);
        $lines[] = line($t, mt_rand(1, 17), $unit);
    }

    $code = mt_rand(0, 1) === 0
        ? percentCode(mt_rand(0, 100))
        : fixedCode(mt_rand(1, 40000));

    // Sometimes scope to a random subset of tiers.
    $scope = [];
    if (mt_rand(0, 2) === 0) {
        for ($t = 1; $t <= $tiers; $t++) {
            if (mt_rand(0, 1) === 1) { $scope[] = $t; }
        }
    }

    $face = total($lines);
    $res  = TicketDiscounts::apply($code, $lines, $scope);

    if (total($res['lines']) !== $face - $res['discount_cents']) { $violations++; continue; }
    if ($res['discount_cents'] < 0 || $res['discount_cents'] > $face) { $violations++; continue; }
    if (unitsByType($res['lines']) !== unitsByType($lines)) { $violations++; continue; }
    foreach ($res['lines'] as $l) {
        if ((int) $l['unit_price_cents'] < 0 || (int) $l['quantity'] < 1) { $violations++; continue 2; }
    }
    if (count($res['lines']) > count($lines)) { $splitsSeen++; }
}
ok($violations === 0, "3000 random carts hold the invariant (violations: $violations)");
ok($splitsSeen > 0, "  ... and the line-splitting path was actually exercised ($splitsSeen carts)");

echo "\n$passed passed, $failed failed\n\n";
exit($failed === 0 ? 0 : 1);
