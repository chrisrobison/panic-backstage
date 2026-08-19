<?php
/**
 * Regression tests for two contract-generation bugs:
 *
 *  1. Contract titles could come out as a literal "undefined — <deal type>"
 *     (the event wizard built the title from a POST /events response that
 *     only ever contains {id}). ContractService::composeTitle() is now the
 *     single place a title gets derived — from counterparty name + org +
 *     the event's show type — and it must never leak "undefined"/"null".
 *
 *  2. A contract's venue name always came from the parent venue, even when
 *     the event was booked into a room with its own address override (e.g.
 *     Mabuhay Gardens' upstairs room, which does business as "Broadway
 *     Studios" at 435 Broadway while the main venue is at 443 Broadway) —
 *     so the contract read "Mabuhay Gardens" next to the room's own
 *     address. ContractRenderer::context() must prefer the room's name the
 *     same way it already prefers the room's address.
 *
 * Hermetic — no DB, no server.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\ContractRenderer;
use Panic\ContractService;

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}
function eq(mixed $actual, mixed $expected, string $label): void
{
    ok($actual === $expected, "$label (got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . ')');
}

echo "\n=== ContractService::composeTitle() ===\n\n";

eq(
    ContractService::composeTitle('Jane Doe', 'Sunset Talent Agency', 'concert'),
    'Jane Doe — Sunset Talent Agency — Concert',
    'name + org + show type join with em dashes, show type title-cased'
);

eq(
    ContractService::composeTitle(null, null, 'private_event', null),
    'Private Event',
    'no counterparty falls back to the show type alone'
);

eq(
    ContractService::composeTitle(null, null, null, 'Friday Night Live'),
    'Friday Night Live',
    'nothing derivable falls back to the linked event title'
);

eq(
    ContractService::composeTitle(null, null, null, null),
    'Untitled Contract',
    'nothing at all falls back to a generic default — never blank'
);

// The actual bug: a JS `${undefined}` interpolation leaking the literal
// string "undefined" (or "null") into what should be a name/org must be
// treated as absent, not stored verbatim.
eq(
    ContractService::composeTitle('undefined', 'undefined', 'concert', 'Some Event'),
    'Concert',
    'literal "undefined" name/org is ignored, never appears in the title'
);
eq(
    ContractService::composeTitle('null', '  ', 'concert'),
    'Concert',
    'literal "null" and blank/whitespace-only values are ignored too'
);
ok(
    !str_contains(ContractService::composeTitle('undefined', null, null, null), 'undefined'),
    'composeTitle output never contains the literal string "undefined" in this scenario'
);

echo "\n=== ContractRenderer::context() — room name/address override ===\n\n";

$contract = ['title' => 'x', 'variables_json' => null];
$event    = ['venue_id' => 1, 'resource_id' => 3, 'title' => 'Test Show', 'date' => '2026-09-01'];

// Simulates what ContractService::eventVenueFor() stashes on $venue when
// the event's room has its own contract_name/address (migrations 088, 106).
$venueWithRoomOverride = [
    'name' => 'Mabuhay Gardens',
    'address' => '443 Broadway',
    'city' => 'San Francisco',
    'state' => 'CA',
    'room_name' => 'Broadway Studios',
    'room_address' => '435 Broadway',
];
$tokens = ContractRenderer::context($contract, $event, $venueWithRoomOverride)['tokens'];
eq($tokens['venue_name'], 'Broadway Studios', "a room's own contract_name overrides the parent venue's name");
eq($tokens['venue_address'], '435 Broadway', "a room's own address overrides the parent venue's address");

$venueNoOverride = [
    'name' => 'Mabuhay Gardens',
    'address' => '443 Broadway',
    'city' => 'San Francisco',
    'state' => 'CA',
    'room_name' => null,
    'room_address' => null,
];
$tokens2 = ContractRenderer::context($contract, $event, $venueNoOverride)['tokens'];
eq($tokens2['venue_name'], 'Mabuhay Gardens', 'a room with no contract_name falls back to the venue name');
eq($tokens2['venue_address'], '443 Broadway', 'a room with no address falls back to the venue address');

echo "\n" . ($failed === 0 ? "All $passed checks passed.\n" : "$failed of " . ($passed + $failed) . " checks FAILED.\n");
exit($failed === 0 ? 0 : 1);
