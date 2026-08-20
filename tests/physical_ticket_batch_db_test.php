<?php
/**
 * Physical ticket batch printing: PhysicalTicketBatchService (batch/ticket
 * creation) + PhysicalTicketPdfGenerator (read-only PDF rendering) +
 * Scanner's unsold-physical-ticket admission gate (migration 108).
 *
 * Follows the throwaway-row template used by printed_ticket_registration_db_test.php
 * (this project shares one production MySQL — see the dev-environment memory):
 * create real rows against the live DB, marker-namespaced, deleted in a finally.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\PhysicalTicketBatchService;
use Panic\PhysicalTicketOversellException;
use Panic\PhysicalTicketPdfGenerator;
use Panic\PhysicalTicketRangeCollisionException;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  \xE2\x9C\x93 " : "  \xE2\x9C\x97 FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Physical ticket batch printing (DB-backed) ===\n\n";

$db = new Database();
$service = new PhysicalTicketBatchService();
$generator = new PhysicalTicketPdfGenerator();
$marker = bin2hex(random_bytes(4));

$venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
if ($venue === null) {
    echo "  no venue available \xE2\x80\x94 cannot run\n";
    exit(1);
}

$eventId = $db->insert(
    "INSERT INTO events (title, slug, date, doors_time, show_time, venue_id, event_type, status, ticketing_mode)
     VALUES (?, ?, '2099-01-01', '19:00:00', '20:00:00', ?, 'live_music', 'confirmed', 'internal')",
    ["PB TEST \xE2\x80\x94 physical batch {$marker} (safe to delete)", "pb-test-physical-batch-{$marker}", (int) $venue['id']]
);
$typeId = $db->insert(
    "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
     VALUES (?, 'General Admission', 1000, 'USD', 10, 'on_sale')",
    [$eventId]
);

try {
    // ── 1. batch creation ────────────────────────────────────────────────────
    $result = $service->createBatch($db, [
        'ticket_type_id'      => $typeId,
        'quantity'            => 5,
        'first_ticket_number' => 1,
        'number_pad_width'    => 6,
        'name'                => 'Test Batch',
        'seller_label'        => null,
    ], null);

    ok($result['quantity'] === 5, 'batch reports the requested quantity');
    ok($result['first_ticket_number'] === 1 && $result['last_ticket_number'] === 5, 'batch reports the correct number range');
    ok(count($result['ticket_ids']) === 5, 'batch returns 5 ticket ids');

    $rows = $db->all('SELECT * FROM tickets WHERE physical_batch_id = ? ORDER BY printed_number ASC', [$result['batch_id']]);
    ok(count($rows) === 5, 'exactly 5 ticket rows were created');

    $numbers = array_map(static fn(array $r): string => (string) $r['printed_number'], $rows);
    ok($numbers === ['000001', '000002', '000003', '000004', '000005'], 'printed_number is sequential and zero-padded');
    ok(count(array_unique($numbers)) === 5, 'every printed_number in the batch is unique');

    $tokens = array_map(static fn(array $r): string => (string) $r['token'], $rows);
    ok(count(array_unique($tokens)) === 5, 'every ticket has a unique QR token');
    ok(!in_array(null, $tokens, true) && !in_array('', $tokens, true), 'every ticket has a non-empty token');

    foreach ($rows as $r) {
        ok((string) $r['delivery_method'] === 'physical', "ticket #{$r['printed_number']} has delivery_method=physical");
        ok((string) $r['physical_status'] === 'printed', "ticket #{$r['printed_number']} starts physical_status=printed (no seller given)");
        ok((string) $r['status'] === 'issued', "ticket #{$r['printed_number']} starts status=issued");
        ok($r['order_id'] === null, "ticket #{$r['printed_number']} has no order_id until actually sold");
    }

    // ── 2. inventory accounting ──────────────────────────────────────────────
    $sold = (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'];
    ok($sold === 5, 'quantity_sold is incremented by the batch quantity at print time (deliberate — see service docblock)');

    // ── 3. seller_label sets physical_status=allocated instead of printed ───
    $result2 = $service->createBatch($db, [
        'ticket_type_id' => $typeId, 'quantity' => 1, 'first_ticket_number' => 100,
        'seller_label' => 'Amoeba Records',
    ], null);
    $allocated = $db->one('SELECT physical_status FROM tickets WHERE physical_batch_id = ?', [$result2['batch_id']]);
    ok((string) $allocated['physical_status'] === 'allocated', 'a batch created with a seller_label starts physical_status=allocated');

    // ── 4. range collision is rejected atomically, before any write ─────────
    $soldBefore = (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'];
    $ticketsBefore = (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'];
    $threw = false;
    try {
        $service->createBatch($db, ['ticket_type_id' => $typeId, 'quantity' => 3, 'first_ticket_number' => 3], null);
    } catch (PhysicalTicketRangeCollisionException $e) {
        $threw = true;
    }
    ok($threw, 'a batch range overlapping existing tickets throws PhysicalTicketRangeCollisionException');
    ok(
        (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'] === $soldBefore,
        'a rejected range collision does not touch quantity_sold'
    );
    ok(
        (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'] === $ticketsBefore,
        'a rejected range collision writes no ticket rows (all-or-nothing)'
    );
    ok(
        $db->one('SELECT id FROM physical_ticket_batches WHERE event_id = ? AND first_ticket_number = 3', [$eventId]) === null,
        'a rejected range collision writes no batch row either'
    );

    // ── 5. oversell guard (tier only has 10 total, 6 already committed) ─────
    $threwOversell = false;
    try {
        $service->createBatch($db, ['ticket_type_id' => $typeId, 'quantity' => 10, 'first_ticket_number' => 200], null);
    } catch (PhysicalTicketOversellException $e) {
        $threwOversell = true;
    }
    ok($threwOversell, 'a batch exceeding remaining tier inventory throws PhysicalTicketOversellException');
    ok(
        (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'] === $soldBefore,
        'a rejected oversell does not touch quantity_sold'
    );

    // ── 6. PDF generation is read-only and deterministic ────────────────────
    $countBeforePdf = (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE physical_batch_id = ?', [$result['batch_id']])['c'];
    $out1 = $generator->generate($db, $eventId, $result['batch_id'], 'individual', 'letter', 'https://panicbooking.com');
    $out2 = $generator->generate($db, $eventId, $result['batch_id'], 'individual', 'letter', 'https://panicbooking.com');
    $countAfterPdf = (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE physical_batch_id = ?', [$result['batch_id']])['c'];

    ok(str_starts_with($out1['bytes'], '%PDF-'), 'generated PDF starts with a valid %PDF header');
    ok($out1['ticket_count'] === 5, 'individual-mode PDF reports 5 tickets rendered');
    ok(hash('sha256', $out1['bytes']) === hash('sha256', $out2['bytes']), 'regenerating the PDF produces byte-identical output (same tokens/numbers)');
    ok($countAfterPdf === $countBeforePdf, 'generating (twice) the PDF creates no duplicate ticket records');

    $imposed = $generator->generate($db, $eventId, $result['batch_id'], 'imposed', 'letter', 'https://panicbooking.com');
    ok(str_starts_with($imposed['bytes'], '%PDF-'), 'imposed-mode PDF also starts with a valid %PDF header');
    ok($imposed['ticket_count'] === 5, 'imposed-mode PDF reports the same 5 tickets');

    // ── 7. QR uniqueness across the whole batch (manifest-level check) ──────
    $manifest = $generator->manifest($db, $eventId, $result['batch_id'], 'https://panicbooking.com');
    $urls = array_map(static fn(array $m): string => $m['url'], $manifest);
    ok(count($urls) === 5, 'manifest lists all 5 tickets');
    ok(count(array_unique($urls)) === 5, 'every ticket has a DIFFERENT QR target URL');
    foreach ($manifest as $m) {
        ok(
            $m['url'] === "https://panicbooking.com/t/{$m['token']}",
            "ticket #{$m['ticket_number']}'s QR URL is exactly https://panicbooking.com/t/{token}, not price/id/type"
        );
    }
    // Scan #37-equivalent (#000003) and #000004 resolve to different, specific tickets.
    $byNumber = [];
    foreach ($manifest as $m) {
        $byNumber[$m['ticket_number']] = $m['url'];
    }
    ok(
        isset($byNumber['000003'], $byNumber['000004']) && $byNumber['000003'] !== $byNumber['000004'],
        'ticket #000003 and #000004 resolve to two different, specific URLs'
    );

    // ── 8. Scanner gate: an unsold physical ticket must NOT admit ───────────
    // Exercises the exact SQL Scanner::performRedeem() runs, rather than the
    // full HTTP/JWT/scanner-link stack (out of scope for a DB-backed test —
    // see printed_ticket_registration_db_test.php's own note on this).
    $printedTicket = $db->one('SELECT id, token_hash FROM tickets WHERE physical_batch_id = ? LIMIT 1', [$result['batch_id']]);
    $affectedUnsold = $db->run(
        "UPDATE tickets
            SET status = 'redeemed', redeemed_at = NOW(), physical_status = IF(delivery_method = 'physical', 'checked_in', physical_status)
          WHERE token_hash = ? AND event_id = ? AND status = 'issued'
            AND NOT (delivery_method = 'physical' AND physical_status NOT IN ('sold', 'checked_in'))",
        [$printedTicket['token_hash'], $eventId]
    );
    ok($affectedUnsold === 0, 'the redeem UPDATE (as gated) does not admit a printed-but-unsold physical ticket');
    $stillIssued = $db->one('SELECT status, physical_status FROM tickets WHERE id = ?', [$printedTicket['id']]);
    ok((string) $stillIssued['status'] === 'issued', 'an unsold physical ticket stays status=issued after a rejected scan attempt');

    // Now mark it sold (simulating a future seller-activation flow) and
    // confirm the SAME gated UPDATE admits it.
    $db->run("UPDATE tickets SET physical_status = 'sold' WHERE id = ?", [$printedTicket['id']]);
    $affectedSold = $db->run(
        "UPDATE tickets
            SET status = 'redeemed', redeemed_at = NOW(), physical_status = IF(delivery_method = 'physical', 'checked_in', physical_status)
          WHERE token_hash = ? AND event_id = ? AND status = 'issued'
            AND NOT (delivery_method = 'physical' AND physical_status NOT IN ('sold', 'checked_in'))",
        [$printedTicket['token_hash'], $eventId]
    );
    ok($affectedSold === 1, 'once physical_status=sold, the exact same gated UPDATE admits the ticket');
    $nowRedeemed = $db->one('SELECT status, physical_status FROM tickets WHERE id = ?', [$printedTicket['id']]);
    ok((string) $nowRedeemed['status'] === 'redeemed', 'a sold physical ticket transitions to status=redeemed on admit');
    ok((string) $nowRedeemed['physical_status'] === 'checked_in', 'admitting a physical ticket also stamps physical_status=checked_in');

} finally {
    // FK ON DELETE CASCADE clears ticket_types/tickets/physical_ticket_batches with the event.
    $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
