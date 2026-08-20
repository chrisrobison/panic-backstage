<?php
/**
 * Physical-ticket registration: TicketingService::registerPrintedTicket()
 * (backing PublicTickets::registerPrinted() / public/register-ticket.html).
 *
 * A venue prints ticket stock with a number already on it, sells it for cash
 * outside the app (at a table, or through an external box office), then a
 * seller registers the printed number to a buyer afterward. Locks down the
 * properties that matter for that flow:
 *   1. the ticket carries the printed_number the seller typed, distinct from
 *      the normal system-generated `code`;
 *   2. a second registration of the same printed_number on the same event is
 *      rejected (DuplicatePrintedTicketException) and writes nothing;
 *   3. the same printed_number IS allowed again on a *different* event —
 *      printed_number is unique per event, not globally (see migration 107);
 *   4. the buyer lands in Contacts via the same audience-CRM sync path an
 *      online order uses, honoring marketing_opt_in;
 *   5. tickets are born 'issued' (not 'redeemed') — registration is not the
 *      same thing as door admission;
 *   6. Scanner::lookup()'s door-side search matches on printed_number too.
 *
 * DB-backed: creates throwaway events + tiers (+ a contact) and deletes them
 * in a finally (this project shares one production MySQL — see the
 * dev-environment memory).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\DuplicatePrintedTicketException;
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

echo "\n=== Physical ticket registration (DB-backed) ===\n\n";

$db      = new Database();
$service = new TicketingService();
$marker  = bin2hex(random_bytes(4));

$venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
if ($venue === null) {
    echo "  no venue available — cannot run\n";
    exit(1);
}

function makeEvent(Database $db, int $venueId, string $marker, string $suffix): int
{
    return $db->insert(
        "INSERT INTO events (title, slug, date, venue_id, event_type, status, ticketing_mode)
         VALUES (?, ?, '2099-01-01', ?, 'live_music', 'confirmed', 'internal')",
        ["PB TEST — printed ticket {$marker}{$suffix} (safe to delete)", "pb-test-printed-ticket-{$marker}{$suffix}", $venueId]
    );
}

$eventId  = makeEvent($db, (int) $venue['id'], $marker, '');
$eventId2 = makeEvent($db, (int) $venue['id'], $marker, '-b');
$contactId = null;

try {
    $typeId = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
         VALUES (?, 'Printed', 2000, 'USD', 500, 'draft')",
        [$eventId]
    );

    // ── 1. a normal registration ─────────────────────────────────────────────
    $email = "printed-ticket-{$marker}@example.invalid";
    $ticket = $service->registerPrintedTicket(
        $db, $typeId, ' 0142 ', 'Jane Buyer', $email, '555-1212', true
    );

    ok($ticket['printed_number'] === '0142', 'printed_number is trimmed and returned');
    ok((string) $ticket['code'] !== '0142', 'the system-generated code is distinct from the printed number');

    $row = $db->one('SELECT * FROM tickets WHERE id = ?', [$ticket['id']]);
    ok((string) $row['status'] === 'issued', "a registered ticket is born 'issued', not 'redeemed'");
    ok((string) $row['printed_number'] === '0142', 'printed_number is persisted on the ticket row');
    ok((string) $row['holder_name'] === 'Jane Buyer', 'holder_name is stored on the ticket');

    $order = $db->one('SELECT * FROM ticket_orders WHERE id = ?', [$ticket['order_id']]);
    ok((string) $order['status'] === 'fulfilled', 'order is fulfilled immediately (money already changed hands)');
    ok((int) $order['amount_cents'] === 2000, 'order records the tier price for revenue reporting');
    ok((string) $order['buyer_phone'] === '555-1212', 'buyer_phone is captured on the order');
    ok((int) $order['marketing_opt_in'] === 1, 'marketing_opt_in is honored when explicitly true');

    $sold = (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'];
    ok($sold === 1, 'quantity_sold is incremented like any other fulfilled sale');

    // ── 2. audience CRM sync (same path an online order uses) ───────────────
    $contact = $db->one('SELECT * FROM contacts WHERE LOWER(email) = ?', [strtolower($email)]);
    ok($contact !== null, 'the buyer lands in Contacts via the same sync fulfillOrder() uses');
    if ($contact !== null) {
        $contactId = (int) $contact['id'];
        ok((int) $contact['marketing_opted_in'] === 1, 'consent is carried onto the contact record');
    }
    ok($order['contact_id'] !== null && $order['audience_synced_at'] !== null, 'order is stamped synced (idempotency marker)');

    // ── 3. duplicate printed_number on the SAME event is rejected ───────────
    $ordersBefore  = (int) $db->one('SELECT COUNT(*) c FROM ticket_orders WHERE event_id = ?', [$eventId])['c'];
    $ticketsBefore = (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'];

    $threw = false;
    try {
        $service->registerPrintedTicket($db, $typeId, '0142', 'Someone Else', 'someone-else@example.invalid', null, false);
    } catch (DuplicatePrintedTicketException $e) {
        $threw = true;
    }
    ok($threw, 'registering the same printed number twice on one event throws DuplicatePrintedTicketException');
    ok(
        (int) $db->one('SELECT COUNT(*) c FROM ticket_orders WHERE event_id = ?', [$eventId])['c'] === $ordersBefore,
        'a rejected duplicate writes no order row'
    );
    ok(
        (int) $db->one('SELECT COUNT(*) c FROM tickets WHERE event_id = ?', [$eventId])['c'] === $ticketsBefore,
        'a rejected duplicate writes no ticket row'
    );
    $soldAfterDup = (int) $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId])['quantity_sold'];
    ok($soldAfterDup === 1, 'a rejected duplicate does not touch quantity_sold');

    // ── 4. the SAME printed number is fine on a DIFFERENT event ─────────────
    $typeId2 = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
         VALUES (?, 'Printed', 2000, 'USD', 500, 'draft')",
        [$eventId2]
    );
    $threwCross = false;
    try {
        $ticket2 = $service->registerPrintedTicket(
            $db, $typeId2, '0142', 'Reused Number Buyer', "printed-ticket-{$marker}-2@example.invalid", null, false
        );
        ok($ticket2['printed_number'] === '0142', 'the same printed number registers cleanly on a different event');
    } catch (DuplicatePrintedTicketException $e) {
        $threwCross = true;
    }
    ok(!$threwCross, 'printed_number uniqueness is scoped per event, not global');

    // ── 5. blank printed_number is rejected before any write ────────────────
    $threwBlank = false;
    try {
        $service->registerPrintedTicket($db, $typeId, '   ', 'X', 'x@example.invalid', null, false);
    } catch (\InvalidArgumentException $e) {
        $threwBlank = true;
    }
    ok($threwBlank, 'a blank printed number is rejected with InvalidArgumentException');

    // ── 6. door staff can find it by the printed number, not just the code ──
    // Mirrors Scanner::lookup()'s SQL directly (DB-level check; the endpoint
    // itself needs a scanner-token link, out of scope for this test).
    $found = $db->one(
        "SELECT id FROM tickets WHERE event_id = ? AND printed_number LIKE ?",
        [$eventId, '%0142%']
    );
    ok($found !== null && (int) $found['id'] === (int) $ticket['id'], "door lookup by printed_number's LIKE pattern finds the ticket");
} finally {
    // FK ON DELETE CASCADE clears tiers/orders/items/tickets/scans with the events.
    $db->run('DELETE FROM events WHERE id IN (?, ?)', [$eventId, $eventId2]);
    // Contacts are venue-wide, not event-scoped — cascade never reaches them.
    if ($contactId !== null) {
        $db->run('DELETE FROM contact_activity WHERE contact_id = ?', [$contactId]);
        $db->run('DELETE FROM contacts WHERE id = ?', [$contactId]);
    }
    $db->run('DELETE FROM contacts WHERE email LIKE ?', ["printed-ticket-{$marker}%@example.invalid"]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
