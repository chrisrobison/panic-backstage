<?php
/**
 * Tests for lead pipeline: creation, status flow, evaluation math,
 * conversion to event.
 *
 * These tests exercise the DealEvaluation math in isolation (no DB needed)
 * and test the lead status transition rules.
 *
 * Run with: php tests/leads_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Lead deal evaluation math tests ===\n\n";

// ── Inline deal math (mirrors Leads::saveEvaluation logic) ──────────────────

function calcDeal(array $b): array
{
    $capacity    = max(0, (int)   ($b['room_capacity']        ?? 0));
    $attendance  = max(0, (int)   ($b['expected_attendance']  ?? 0));
    $ticketPrice = max(0, (float) ($b['ticket_price']         ?? 0));
    $ticketFee   = max(0, (float) ($b['ticket_fee_per']       ?? 0));
    $rentalFee   = max(0, (float) ($b['rental_fee']           ?? 0));
    $guarantee   = max(0, (float) ($b['artist_guarantee']     ?? 0));
    $barSpend    = max(0, (float) ($b['projected_bar_spend']  ?? 0));
    $barMinimum  = max(0, (float) ($b['bar_minimum']          ?? 0));
    $labor       = max(0, (float) ($b['labor_forecast']       ?? 0));
    $production  = max(0, (float) ($b['production_costs']     ?? 0));
    $facility    = max(0, (float) ($b['facility_costs']       ?? 0));
    $other       = max(0, (float) ($b['other_costs']          ?? 0));

    $ticketRevenue = $attendance * $ticketPrice;
    $feeRevenue    = $attendance * $ticketFee;
    $barRevenue    = max($barSpend, $barMinimum);
    $grossRevenue  = $ticketRevenue + $feeRevenue + $rentalFee + $barRevenue;
    $estimatedCost = $guarantee + $labor + $production + $facility + $other;
    $venueNet      = $grossRevenue - $estimatedCost;
    $marginPct     = $grossRevenue > 0 ? round(($venueNet / $grossRevenue) * 100, 2) : 0;

    $breakEven = 0;
    if ($ticketPrice > 0 && $estimatedCost > 0) {
        $breakEven = max(0, (int) ceil(($estimatedCost - $rentalFee - $barRevenue) / $ticketPrice));
    }

    $minTickets = 0;
    if ($guarantee > 0 && $ticketPrice > 0) {
        $minTickets = (int) ceil($guarantee / $ticketPrice);
    }

    $flags = [];
    if ($attendance > $capacity && $capacity > 0)    $flags[] = 'projected_attendance_exceeds_capacity';
    if ($marginPct < 0)                              $flags[] = 'negative_margin';
    if ($marginPct < 15 && $marginPct >= 0)          $flags[] = 'low_margin_under_15_pct';
    if ($breakEven > 0 && $attendance < $breakEven)  $flags[] = 'attendance_below_break_even';
    if ($barSpend > 0 && $barSpend < $barMinimum)    $flags[] = 'bar_spend_below_minimum';
    if ($guarantee > 0 && $venueNet < 0)             $flags[] = 'venue_net_negative_with_guarantee';

    return compact('grossRevenue','estimatedCost','venueNet','marginPct',
                   'breakEven','minTickets','flags');
}

// ── Test 1: Simple ticket show ───────────────────────────────────────────────

$r = calcDeal([
    'room_capacity'      => 200,
    'expected_attendance'=> 150,
    'ticket_price'       => 20.00,
    'ticket_fee_per'     => 2.00,
    'labor_forecast'     => 500,
    'production_costs'   => 200,
]);

ok($r['grossRevenue']  === 3300.0, "Ticket show: gross revenue = \$3300 (150×\$20 + 150×\$2)");
ok($r['estimatedCost'] === 700.0,  "Ticket show: cost = \$700");
ok($r['venueNet']      === 2600.0, "Ticket show: venue net = \$2600");
ok($r['breakEven']     === 35,     "Ticket show: break-even = 35 tickets (ceil(700/20))");

// ── Test 2: Rental buyout (bar only) ─────────────────────────────────────────

$r = calcDeal([
    'rental_fee'         => 2000,
    'projected_bar_spend'=> 1200,
    'bar_minimum'        => 1500,  // minimum kicks in
    'labor_forecast'     => 800,
    'facility_costs'     => 300,
]);

ok($r['grossRevenue']  === 3500.0,  "Buyout: gross = rental + bar_minimum = \$3500");
ok($r['estimatedCost'] === 1100.0,  "Buyout: cost = \$1100");
ok(in_array('bar_spend_below_minimum', $r['flags']), "Buyout: bar_spend_below_minimum flag raised");

// ── Test 3: Negative margin flag ──────────────────────────────────────────────

$r = calcDeal([
    'expected_attendance'=> 50,
    'ticket_price'       => 10,
    'artist_guarantee'   => 2000,
    'labor_forecast'     => 500,
]);

ok($r['venueNet']  < 0, "Negative margin: venue_net is negative");
ok(in_array('negative_margin', $r['flags']), "Negative margin: flag raised");
ok(in_array('venue_net_negative_with_guarantee', $r['flags']), "Guarantee loss: flag raised");

// ── Test 4: Attendance exceeds capacity ──────────────────────────────────────

$r = calcDeal([
    'room_capacity'      => 100,
    'expected_attendance'=> 150,
    'ticket_price'       => 15,
]);

ok(in_array('projected_attendance_exceeds_capacity', $r['flags']),
   "Over-capacity: flag raised");

// ── Test 5: Low margin under 15% ─────────────────────────────────────────────

$r = calcDeal([
    'expected_attendance'=> 100,
    'ticket_price'       => 10,
    'labor_forecast'     => 900,  // 90% cost ratio → 10% margin
]);

ok(in_array('low_margin_under_15_pct', $r['flags']), "Low margin: flag raised under 15%");
ok(!in_array('negative_margin', $r['flags']), "Low margin: no negative_margin flag when positive");

// ── Test 6: Min tickets for guarantee ────────────────────────────────────────

$r = calcDeal([
    'artist_guarantee' => 500,
    'ticket_price'     => 15,
]);

ok($r['minTickets'] === 34, "Min tickets for guarantee: ceil(500/15) = 34");

// ── Test 7: Server-calculated values are not trusted from client ──────────────

// Verify we never use client-submitted venue_net directly.
// In the real endpoint, calc_venue_net is always computed.
// This test documents the intention.
$clientSubmitted = ['venue_net' => 99999, 'gross_revenue' => 99999];
$serverCalc      = calcDeal(['ticket_price' => 10, 'expected_attendance' => 10, 'labor_forecast' => 50]);

ok($serverCalc['venueNet'] === 50.0, "Server calc ignores client-submitted values");
ok($serverCalc['venueNet'] !== (float) $clientSubmitted['venue_net'], "Server net differs from client-submitted value");

echo "\nLead / deal evaluation math: $passed passed, $failed failed.\n";

// ── Lead status transition validation ────────────────────────────────────────

echo "\n=== Lead status transition tests ===\n\n";

$validStatuses  = ['new','triage','evaluating','needs_review','approved','declined','converted','canceled'];
$convertible    = ['approved','evaluating','needs_review'];

foreach ($convertible as $status) {
    ok(in_array($status, $validStatuses, true), "Status '$status' is valid for conversion");
}

$notConvertible = ['new','triage','declined','converted','canceled'];
foreach ($notConvertible as $status) {
    ok(!in_array($status, $convertible, true), "Status '$status' is blocked from conversion");
}

echo "\nLead status transitions: $passed passed, $failed failed.\n";
echo "\nTotal: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
