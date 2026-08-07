<?php
/**
 * No-QR admission: Scanner::lookup() and Scanner::admit().
 *
 * The door needs a way to admit someone who genuinely bought a ticket but
 * cannot produce it. That path skips the one thing the scanner normally relies
 * on — possession of a cryptographic token — so the properties worth locking
 * down are the ones that keep it from becoming a hole:
 *
 *   1. can_lookup gates BOTH surfaces; a scan-only link (every link that
 *      existed before migration 092) can neither read the guest list nor admit
 *      by name;
 *   2. the lookup response never leaks a ticket token or QR URL, so a leaked
 *      bearer link cannot be turned into working tickets, and emails are
 *      masked;
 *   3. admission is scoped to the link's own event, and is the same atomic
 *      issued -> redeemed transition the scanner performs, so it cannot
 *      double-admit or admit a void ticket;
 *   4. every outcome writes a ticket_scans row, and a manual admission is
 *      recorded as 'manual_admit' — distinguishable from a real scan while
 *      still counting as an admission.
 *
 * DB-backed: creates a throwaway event and deletes it in a finally (this
 * project shares one production MySQL — see the dev-environment memory).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Request;
use Panic\Response;
use Panic\Scanner;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

/** Read the JSON payload back out of a Response (body is private readonly). */
function payload(Response $r): array
{
    $p = new ReflectionProperty(Response::class, 'body');
    $p->setAccessible(true);
    $body = $p->getValue($r);
    return is_array($body) ? $body : [];
}

function statusOf(Response $r): int
{
    $p = new ReflectionProperty(Response::class, 'status');
    $p->setAccessible(true);
    return (int) $p->getValue($r);
}

/** POST a scanner-token request at Scanner, as the Kernel would. */
function scan(Database $db, string $action, array $body): Response
{
    $endpoint = new Scanner($db, new Auth(), ['scan' => $action], dirname(__DIR__));
    return $endpoint->handle(new Request('POST', "/api/scan/{$action}", [], $body, [], []));
}

echo "\n=== No-QR admission (DB-backed) ===\n\n";

$db     = new Database();
$marker = bin2hex(random_bytes(4));

$venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
if ($venue === null) {
    echo "  no venue available — cannot run\n";
    exit(1);
}

$mkEvent = static function (Database $db, int $venueId, string $suffix) use ($marker): int {
    return $db->insert(
        "INSERT INTO events (title, slug, date, venue_id, event_type, status)
         VALUES (?, ?, '2099-01-01', ?, 'live_music', 'confirmed')",
        [
            "PB TEST — manual admit {$marker}{$suffix} (safe to delete)",
            "pb-test-manual-admit-{$marker}{$suffix}",
            $venueId,
        ]
    );
};

$eventId = $mkEvent($db, (int) $venue['id'], '');
$otherEventId = $mkEvent($db, (int) $venue['id'], '-other');

try {
    $typeId = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, currency, quantity_total, status)
         VALUES (?, 'General', 2500, 'USD', 50, 'on_sale')",
        [$eventId]
    );

    // Three tickets: one to admit, one to leave alone, one void.
    $mkTicket = static function (Database $db, int $eventId, int $typeId, string $name, string $email, string $status) use ($marker): int {
        $token = strtoupper(bin2hex(random_bytes(8)));
        return $db->insert(
            'INSERT INTO tickets (event_id, ticket_type_id, code, token_hash, token, holder_name, holder_email, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $typeId,
                'PBT-' . $marker . '-' . substr($token, 0, 6),
                hash('sha256', $token),
                $token,
                $name,
                $email,
                $status,
            ]
        );
    };

    $lostId  = $mkTicket($db, $eventId, $typeId, 'Alice Lostphone', 'alice@example.com', 'issued');
    $otherTk = $mkTicket($db, $eventId, $typeId, 'Bob Hasqr', 'bob@example.com', 'issued');
    $voidId  = $mkTicket($db, $eventId, $typeId, 'Carol Refunded', 'carol@example.com', 'void');

    // A tier + ticket belonging to a DIFFERENT event, to prove scoping.
    $otherType = $db->insert(
        "INSERT INTO ticket_types (event_id, name, price_cents, quantity_total, status)
         VALUES (?, 'General', 1000, 10, 'on_sale')",
        [$otherEventId]
    );
    $foreignTicket = $mkTicket($db, $otherEventId, $otherType, 'Dave Elsewhere', 'dave@example.com', 'issued');

    // Two links on the same event: one scan-only (the pre-092 default), one
    // granted lookup.
    $mkLink = static function (Database $db, int $eventId, int $canLookup): string {
        $token = strtoupper(bin2hex(random_bytes(12)));
        $db->insert(
            'INSERT INTO event_scanner_links (event_id, label, token_hash, token, can_lookup)
             VALUES (?, ?, ?, ?, ?)',
            [$eventId, 'test', hash('sha256', $token), $token, $canLookup]
        );
        return $token;
    };

    $scanOnly = $mkLink($db, $eventId, 0);
    $lookupOk = $mkLink($db, $eventId, 1);

    // ── 1. the capability gate ───────────────────────────────────────────────
    $res = payload(scan($db, 'tickets', ['scanner_token' => $scanOnly]));
    ok($res['result'] === 'not_permitted', 'a scan-only link cannot list tickets');
    ok(($res['tickets'] ?? []) === [], 'a refused lookup returns no ticket data at all');

    $res = payload(scan($db, 'admit', ['scanner_token' => $scanOnly, 'ticket_id' => $lostId]));
    ok($res['result'] === 'not_permitted', 'a scan-only link cannot admit without a QR');
    ok(
        (string) $db->one('SELECT status FROM tickets WHERE id = ?', [$lostId])['status'] === 'issued',
        'a refused admit does not change the ticket',
    );

    // An invalid scanner token is still a 401, not a soft result.
    ok(statusOf(scan($db, 'tickets', ['scanner_token' => 'NOPE'])) === 401, 'a bogus scanner link is rejected 401');

    // ── 2. the lookup response is field-stripped ─────────────────────────────
    $res = payload(scan($db, 'tickets', ['scanner_token' => $lookupOk]));
    ok($res['result'] === 'ok', 'a lookup-enabled link lists tickets');
    ok(count($res['tickets']) === 3, 'lists exactly this event\'s tickets');

    $serialized = json_encode($res);
    ok(!str_contains($serialized, 'Dave Elsewhere'), 'another event\'s tickets never appear');

    $row = null;
    foreach ($res['tickets'] as $t) {
        if ((int) $t['id'] === $lostId) {
            $row = $t;
        }
    }
    ok($row !== null, 'the ticket we are looking for is findable');
    ok(!array_key_exists('token', $row) && !array_key_exists('url', $row), 'no ticket token or QR URL is ever returned');
    ok($row['holder_email'] === 'a***@example.com', 'holder email is masked');
    ok($row['holder_name'] === 'Alice Lostphone', 'holder name is returned in full (it is what gets checked against ID)');
    ok($row['tier'] === 'General', 'tier is returned so staff can see what was bought');

    // Unredeemed first — the ones the door still has to act on.
    ok((string) $res['tickets'][0]['status'] === 'issued', 'unredeemed tickets sort first');

    // Server-side filter.
    $filtered = payload(scan($db, 'tickets', ['scanner_token' => $lookupOk, 'q' => 'Lostphone']));
    ok(count($filtered['tickets']) === 1 && (int) $filtered['tickets'][0]['id'] === $lostId, 'the query filter narrows by holder name');

    // ── 3. admitting ─────────────────────────────────────────────────────────
    $scansBefore = (int) $db->one('SELECT COUNT(*) c FROM ticket_scans WHERE event_id = ?', [$eventId])['c'];

    $res = payload(scan($db, 'admit', ['scanner_token' => $lookupOk, 'ticket_id' => $lostId]));
    ok($res['result'] === 'admitted' && $res['admitted'] === true, 'a lookup-enabled link admits the ticket');
    ok($res['holder_name'] === 'Alice Lostphone', 'the response names the person admitted');
    ok(
        (string) $db->one('SELECT status FROM tickets WHERE id = ?', [$lostId])['status'] === 'redeemed',
        'the ticket is now redeemed',
    );

    $ticketRow = $db->one('SELECT redeemed_at, redeemed_via_scanner_id FROM tickets WHERE id = ?', [$lostId]);
    ok($ticketRow['redeemed_at'] !== null, 'redeemed_at is stamped');
    ok($ticketRow['redeemed_via_scanner_id'] !== null, 'the admitting link is recorded on the ticket');

    // Second tap = already used, not a second admission.
    $res = payload(scan($db, 'admit', ['scanner_token' => $lookupOk, 'ticket_id' => $lostId]));
    ok($res['result'] === 'already_redeemed' && $res['admitted'] === false, 'the same ticket cannot be admitted twice');

    // And its QR can no longer walk in either — the transition already happened.
    $reAdmit = $db->run(
        "UPDATE tickets SET status = 'redeemed' WHERE id = ? AND event_id = ? AND status = 'issued'",
        [$lostId, $eventId]
    );
    ok($reAdmit === 0, 'a manually admitted ticket cannot then be walked in on its QR');

    // Void tickets are refused.
    $res = payload(scan($db, 'admit', ['scanner_token' => $lookupOk, 'ticket_id' => $voidId]));
    ok($res['result'] === 'void' && $res['admitted'] === false, 'a void ticket is never admitted');

    // Cross-event scoping: a link cannot admit another show's ticket.
    $res = payload(scan($db, 'admit', ['scanner_token' => $lookupOk, 'ticket_id' => $foreignTicket]));
    ok($res['result'] === 'not_found' && $res['admitted'] === false, 'a link cannot admit another event\'s ticket');
    ok(
        (string) $db->one('SELECT status FROM tickets WHERE id = ?', [$foreignTicket])['status'] === 'issued',
        'the other event\'s ticket is untouched',
    );

    // The untouched third ticket stays untouched throughout.
    ok(
        (string) $db->one('SELECT status FROM tickets WHERE id = ?', [$otherTk])['status'] === 'issued',
        'admitting one ticket does not disturb the others',
    );

    // ── 4. the audit trail ───────────────────────────────────────────────────
    $scans = $db->all(
        'SELECT result, ticket_id FROM ticket_scans WHERE event_id = ? ORDER BY id ASC',
        [$eventId]
    );
    // Four attempts were made through admit(): the real one, the duplicate, the
    // void ticket, and the foreign ticket. All four are audited against this
    // link's event, and the refused-by-capability attempts earlier wrote none
    // (there is nothing to attribute when the link may not act at all).
    ok(count($scans) - $scansBefore === 4, 'every admission attempt is audited, and only those (4)');

    $results = array_map(static fn (array $r): string => (string) $r['result'], $scans);
    ok(in_array('manual_admit', $results, true), "a no-QR admission is recorded as 'manual_admit'");
    ok(
        count(array_filter($results, static fn (string $r): bool => $r === 'manual_admit')) === 1,
        'exactly one manual_admit is written for one real admission',
    );
    ok(in_array('already_redeemed', $results, true), 'the duplicate attempt is audited with the ordinary scan vocabulary');
    ok(in_array('void', $results, true), 'the void attempt is audited');

    // The headcount question this table exists to answer.
    $admitted = (int) $db->one(
        "SELECT COUNT(*) c FROM ticket_scans
          WHERE event_id = ? AND result IN ('admitted', 'manual_admit')",
        [$eventId]
    )['c'];
    ok($admitted === 1, 'manual admissions count toward the headcount alongside scans');
} finally {
    // FK ON DELETE CASCADE clears tiers/tickets/scans/links with the event.
    $db->run('DELETE FROM events WHERE id IN (?, ?)', [$eventId, $otherEventId]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
