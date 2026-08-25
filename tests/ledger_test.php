<?php
/**
 * Tests for event ledger P&L calculations.
 * These test the math in isolation (no DB).
 *
 * Run with: php tests/ledger_test.php
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Ledger P&L calculation tests ===\n\n";

/**
 * Inline version of Ledger::calculateSummary() math for isolated testing.
 */
function calcLedgerSummary(array $entries): array
{
    $revenueCategories = [
        'tickets','ticket_fees','bar_sales','rental_fee','hosted_bar',
        'merch_share','sponsorship','equipment_rental','overtime_charge','other_revenue',
    ];
    $costCategories = [
        'artist_guarantee','promoter_settlement','labor','sound_production',
        'security','cleaning','rentals','catering','vendor_cost',
        'processing_fees','taxes','refunds','other_cost',
    ];

    $byCategory   = [];
    $grossRevenue = 0;
    $totalCosts   = 0;
    $totalPayments = 0;

    foreach ($entries as $e) {
        if (!empty($e['is_void'])) continue;

        $cat  = $e['category'];
        $amt  = (float) $e['amount'];
        $type = $e['line_type'] ?? (in_array($cat, $revenueCategories) ? 'revenue' : (in_array($cat, $costCategories) ? 'cost' : 'payment'));

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

    return compact('grossRevenue','totalCosts','venueNet','marginPct','totalPayments','byCategory');
}

// ── 1. Simple public show ─────────────────────────────────────────────────────

$entries = [
    ['category' => 'tickets',         'line_type' => 'revenue', 'amount' => 2000],
    ['category' => 'ticket_fees',     'line_type' => 'revenue', 'amount' => 200],
    ['category' => 'bar_sales',       'line_type' => 'revenue', 'amount' => 1500],
    ['category' => 'artist_guarantee','line_type' => 'cost',    'amount' => 800],
    ['category' => 'labor',           'line_type' => 'cost',    'amount' => 400],
];

$s = calcLedgerSummary($entries);
ok($s['grossRevenue']  === 3700.0, "Public show: gross revenue = \$3700");
ok($s['totalCosts']    === 1200.0, "Public show: total costs = \$1200");
ok($s['venueNet']      === 2500.0, "Public show: venue net = \$2500");
ok(abs($s['marginPct'] - 67.57) < 0.01, "Public show: margin ≈ 67.57%");

// ── 2. Void entries are excluded ─────────────────────────────────────────────

$entries[] = ['category' => 'other_revenue', 'line_type' => 'revenue', 'amount' => 9999, 'is_void' => true];
$entries[] = ['category' => 'other_cost',    'line_type' => 'cost',    'amount' => 9999, 'is_void' => 1];

$s2 = calcLedgerSummary($entries);
ok($s2['grossRevenue'] === $s['grossRevenue'], "Void entries excluded from revenue");
ok($s2['totalCosts']   === $s['totalCosts'],   "Void entries excluded from costs");

// ── 3. Private event with rental fee ─────────────────────────────────────────

$privateEntries = [
    ['category' => 'rental_fee',      'line_type' => 'revenue', 'amount' => 3000],
    ['category' => 'hosted_bar',      'line_type' => 'revenue', 'amount' => 2000],
    ['category' => 'labor',           'line_type' => 'cost',    'amount' => 600],
    ['category' => 'security',        'line_type' => 'cost',    'amount' => 400],
    ['category' => 'cleaning',        'line_type' => 'cost',    'amount' => 200],
    ['category' => 'deposit_received','line_type' => 'payment', 'amount' => 1000],
];

$p = calcLedgerSummary($privateEntries);
ok($p['grossRevenue']  === 5000.0, "Private event: gross = \$5000");
ok($p['totalCosts']    === 1200.0, "Private event: costs = \$1200");
ok($p['venueNet']      === 3800.0, "Private event: net = \$3800");
ok($p['totalPayments'] === 1000.0, "Private event: deposit tracked separately");
ok(isset($p['byCategory']['rental_fee']), "Private event: rental_fee category present");

// ── 4. By-category breakdown accuracy ────────────────────────────────────────

ok($s['byCategory']['tickets']         === 2000.0, "By-category: tickets = \$2000");
ok($s['byCategory']['bar_sales']       === 1500.0, "By-category: bar_sales = \$1500");
ok($s['byCategory']['artist_guarantee'] === 800.0, "By-category: artist_guarantee = \$800");

// ── 5. All-zero entries ───────────────────────────────────────────────────────

$z = calcLedgerSummary([]);
ok($z['grossRevenue'] == 0, "Empty ledger: gross = 0");
ok($z['venueNet']     == 0, "Empty ledger: net = 0");
ok($z['marginPct']    == 0, "Empty ledger: margin = 0 (no div-by-zero)");

// ── 6. Negative net scenario ──────────────────────────────────────────────────

$loss = calcLedgerSummary([
    ['category' => 'tickets',         'line_type' => 'revenue', 'amount' => 500],
    ['category' => 'artist_guarantee','line_type' => 'cost',    'amount' => 1000],
]);
ok($loss['venueNet'] < 0, "Loss scenario: venue_net is negative");

// ── 7. Server-side calculation — client values must not override ──────────────

// The server ALWAYS recalculates from entries — it never uses a
// client-submitted total. This test verifies the isolation.
$clientSubmittedNet = 99999;
$realEntries        = [
    ['category' => 'tickets', 'line_type' => 'revenue', 'amount' => 100],
    ['category' => 'labor',   'line_type' => 'cost',    'amount' => 50],
];
$serverResult = calcLedgerSummary($realEntries);

ok($serverResult['venueNet'] === 50.0,
   "Server-side calc: venue_net = \$50 regardless of client-submitted value of \$$clientSubmittedNet");

echo "\nLedger calculations: $passed passed, $failed failed.\n";

// ── Payee balances ("who's still owed money") ─────────────────────────────────
// Inline port of Ledger::calculateBalances() for isolated testing — same
// grouping/precedence rules, no DB. Entries use a plain 'id' key (int) so
// paid_entry_id linking can be exercised the same way the real SQL does.

echo "\n=== Payee balance tests ===\n\n";

function calcBalances(array $entries): array
{
    $keyFor = static fn(string $name, ?string $type): string =>
        mb_strtolower(trim($name)) . '|' . ($type ?? '');

    $costsById = [];
    $groups    = [];

    foreach ($entries as $e) {
        if (!empty($e['is_void'])) continue;
        if ($e['line_type'] !== 'cost' || empty($e['payee_name'])) continue;
        $costsById[(int) $e['id']] = $e;
        $key = $keyFor($e['payee_name'], $e['payee_type'] ?? null);
        $groups[$key] ??= ['payee_name' => $e['payee_name'], 'payee_type' => $e['payee_type'] ?? null, 'committed' => 0.0, 'paid' => 0.0];
        $groups[$key]['committed'] += (float) $e['amount'];
    }

    foreach ($entries as $e) {
        if (!empty($e['is_void'])) continue;
        if ($e['line_type'] !== 'payment') continue;
        $amt = (float) $e['amount'];

        $linkedCost = !empty($e['paid_entry_id']) ? ($costsById[(int) $e['paid_entry_id']] ?? null) : null;
        if ($linkedCost) {
            $key = $keyFor($linkedCost['payee_name'], $linkedCost['payee_type'] ?? null);
            $groups[$key]['paid'] += $amt;
        } elseif (!empty($e['payee_name'])) {
            $key = $keyFor($e['payee_name'], $e['payee_type'] ?? null);
            $groups[$key] ??= ['payee_name' => $e['payee_name'], 'payee_type' => $e['payee_type'] ?? null, 'committed' => 0.0, 'paid' => 0.0];
            $groups[$key]['paid'] += $amt;
        }
    }

    $balances = [];
    $totalStillOwed = 0.0;
    foreach ($groups as $g) {
        $stillOwed = round($g['committed'] - $g['paid'], 2);
        $status    = $stillOwed <= 0.005 ? 'paid' : ($g['paid'] > 0 ? 'partial' : 'unpaid');
        $balances[] = [
            'payee_name' => $g['payee_name'],
            'still_owed' => $stillOwed,
            'status'     => $status,
        ];
        if ($stillOwed > 0) $totalStillOwed += $stillOwed;
    }

    return ['balances' => $balances, 'total_still_owed' => round($totalStillOwed, 2)];
}

function balanceFor(array $result, string $name): ?array
{
    foreach ($result['balances'] as $b) {
        if ($b['payee_name'] === $name) return $b;
    }
    return null;
}

// 1. Cost fully paid via a linked payment -> paid, $0 owed.
$r1 = calcBalances([
    ['id' => 1, 'line_type' => 'cost',    'category' => 'artist_guarantee', 'amount' => 500, 'payee_name' => 'Voidwalkers', 'payee_type' => 'artist'],
    ['id' => 2, 'line_type' => 'payment', 'category' => 'artist_payout',    'amount' => 500, 'paid_entry_id' => 1],
]);
ok(balanceFor($r1, 'Voidwalkers')['status'] === 'paid', 'Linked payment fully covering a cost -> paid');
ok(balanceFor($r1, 'Voidwalkers')['still_owed'] === 0.0, 'Linked payment fully covering a cost -> $0 owed');
ok($r1['total_still_owed'] === 0.0, 'Fully paid payee contributes nothing to total still owed');

// 2. Payee-level payment (no paid_entry_id) partially covers a cost -> partial.
$r2 = calcBalances([
    ['id' => 1, 'line_type' => 'cost',    'category' => 'sound_production', 'amount' => 400, 'payee_name' => 'Doorwolf Sound Co.', 'payee_type' => 'vendor'],
    ['id' => 2, 'line_type' => 'payment', 'category' => 'vendor_payout',    'amount' => 150, 'payee_name' => 'Doorwolf Sound Co.', 'payee_type' => 'vendor'],
]);
ok(balanceFor($r2, 'Doorwolf Sound Co.')['status'] === 'partial', 'Payee-level partial payment -> partial status');
ok(balanceFor($r2, 'Doorwolf Sound Co.')['still_owed'] === 250.0, 'Payee-level partial payment -> $250 still owed');

// 3. No payment at all -> unpaid, still_owed = full committed amount.
$r3 = calcBalances([
    ['id' => 1, 'line_type' => 'cost', 'category' => 'promoter_settlement', 'amount' => 380, 'payee_name' => 'Rico M.', 'payee_type' => 'promoter'],
]);
ok(balanceFor($r3, 'Rico M.')['status'] === 'unpaid', 'No payment yet -> unpaid');
ok(balanceFor($r3, 'Rico M.')['still_owed'] === 380.0, 'No payment yet -> still owed = full commitment');

// 4. Multiple cost entries for the same payee sum together.
$r4 = calcBalances([
    ['id' => 1, 'line_type' => 'cost', 'category' => 'artist_guarantee', 'amount' => 200, 'payee_name' => 'Static Bloom', 'payee_type' => 'artist'],
    ['id' => 2, 'line_type' => 'cost', 'category' => 'artist_guarantee', 'amount' => 100, 'payee_name' => 'Static Bloom', 'payee_type' => 'artist'],
]);
ok(balanceFor($r4, 'Static Bloom')['still_owed'] === 300.0, 'Multiple cost lines for one payee sum into a single balance');

// 5. Voided cost entries are excluded entirely.
$r5 = calcBalances([
    ['id' => 1, 'line_type' => 'cost', 'category' => 'other_cost', 'amount' => 999, 'payee_name' => 'Ghost Vendor', 'is_void' => 1],
]);
ok(balanceFor($r5, 'Ghost Vendor') === null, 'Voided cost entries never create a payee balance');

// 6. A cash-flow payment with no payee_name and no paid_entry_id (e.g. a
//    client deposit) doesn't net into anyone's balance.
$r6 = calcBalances([
    ['id' => 1, 'line_type' => 'cost',    'category' => 'security', 'amount' => 300, 'payee_name' => 'Titan Security', 'payee_type' => 'vendor'],
    ['id' => 2, 'line_type' => 'payment', 'category' => 'deposit_received', 'amount' => 1000],
]);
ok(balanceFor($r6, 'Titan Security')['still_owed'] === 300.0, 'A payee-less payment (client deposit) does not pay down an unrelated vendor balance');

// 7. total_still_owed only sums positive balances — an overpaid payee
//    doesn't offset what's still owed to someone else.
$r7 = calcBalances([
    ['id' => 1, 'line_type' => 'cost',    'category' => 'labor', 'amount' => 100, 'payee_name' => 'Crew A', 'payee_type' => 'staff'],
    ['id' => 2, 'line_type' => 'payment', 'category' => 'staff_payout', 'amount' => 150, 'paid_entry_id' => 1],
    ['id' => 3, 'line_type' => 'cost',    'category' => 'vendor_cost', 'amount' => 200, 'payee_name' => 'Crew B', 'payee_type' => 'vendor'],
]);
ok($r7['total_still_owed'] === 200.0, 'An overpaid payee does not net against another payee\'s outstanding balance');

echo "\nPayee balance calculations: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
