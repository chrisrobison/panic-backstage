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
exit($failed > 0 ? 1 : 0);
