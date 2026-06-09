<?php
declare(strict_types=1);

/**
 * End-to-end SANDBOX smoke test for the in-house event ticketing feature.
 *
 * SAFETY:
 *   - Reads DB_* from .env (production DB; user-scoped privileges only).
 *   - Reads Square SANDBOX creds from ENVIRONMENT VARIABLES ONLY. No secret is
 *     hardcoded here. The orchestrator injects them, or you may export them
 *     before running:
 *         SQUARE_ENV, SQUARE_ACCESS_TOKEN, SQUARE_LOCATION_ID,
 *         SQUARE_API_VERSION, SQUARE_WEBHOOK_SIGNATURE_KEY, SQUARE_WEBHOOK_URL
 *   - Creates exactly ONE test event (ZZZ_TIX_SANDBOX_TEST) and tears it down
 *     via DELETE FROM events WHERE id = <id> (ON DELETE CASCADE removes all
 *     child ticketing rows). Never touches any other row.
 *   - Never triggers a real card charge; "payment confirmed" is simulated by
 *     calling TicketingService::fulfillOrder() directly (what the webhook does).
 *
 * Usage:  php scripts/ticketing-sandbox-smoke.php
 */

const TEST_TITLE = 'ZZZ_TIX_SANDBOX_TEST';

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Mailer;
use Panic\Payments\PaymentProviders;
use Panic\Payments\SquareProvider;
use Panic\QrCode;
use Panic\TicketingService;

// ── Load .env (DB creds + app config), WITHOUT modifying the file. ───────────
Env::load($root . '/.env');

// ── Inject Square SANDBOX creds from the process environment, in-process. ────
// Env::get() reads $_ENV ?? getenv() at call time, so this overrides .env
// for the payment provider without changing any file. We only forward keys
// that are actually present in the environment (never hardcoded here).
foreach ([
    'SQUARE_ENV',
    'SQUARE_ACCESS_TOKEN',
    'SQUARE_LOCATION_ID',
    'SQUARE_API_VERSION',
    'SQUARE_WEBHOOK_SIGNATURE_KEY',
    'SQUARE_WEBHOOK_URL',
] as $k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') {
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}

$results = [];
$pass = static function (string $step, bool $ok, string $detail = '') use (&$results): void {
    $results[$step] = [$ok ? 'PASS' : 'FAIL', $detail];
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $step, $detail !== '' ? "  — $detail" : '');
};

$db  = new Database();
$env = new Env();
$svc = new TicketingService();

$eventId = null;
$createdMailFiles = [];

try {
    // ── Guardrail: refuse to run if the test event already exists. ───────────
    $existing = $db->one('SELECT id FROM events WHERE title = ?', [TEST_TITLE]);
    if ($existing !== null) {
        fwrite(STDERR, "STOP: test event '" . TEST_TITLE . "' already exists (id={$existing['id']}). Aborting.\n");
        exit(2);
    }

    // ── A. Create the test event (internal ticketing mode). ──────────────────
    $venue = $db->one('SELECT id FROM venues ORDER BY id ASC LIMIT 1');
    $venueId = (int) ($venue['id'] ?? 0);
    $slug = 'zzz-tix-sandbox-test-' . bin2hex(random_bytes(4));
    $eventId = $db->insert(
        "INSERT INTO events
            (venue_id, title, slug, event_type, status, date, ticketing_mode, public_visibility)
         VALUES (?, ?, ?, 'special_event', 'confirmed', CURDATE(), 'internal', 0)",
        [$venueId, TEST_TITLE, $slug]
    );
    $pass('A. create event (internal)', $eventId > 0, "event_id={$eventId} venue_id={$venueId}");

    // ── B. Create a GA ticket type, on sale now. ─────────────────────────────
    $typeId = $db->insert(
        "INSERT INTO ticket_types
            (event_id, name, price_cents, currency, quantity_total, status, sales_start, sales_end)
         VALUES (?, 'GA', 1000, 'USD', 5, 'on_sale', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 30 DAY))",
        [$eventId]
    );
    $pass('B. create ticket_type GA', $typeId > 0, "type_id={$typeId} price=1000 qty=5");

    // ── C. availableQuantity == 5. ───────────────────────────────────────────
    $avail = $svc->availableQuantity($db, $typeId);
    $pass('C. availableQuantity == 5', $avail === 5, "got {$avail}");

    // ── D. Sandbox Square checkout (proves token + version + sandbox API). ───
    $checkoutUrl = null;
    try {
        $provider = PaymentProviders::byKey('square', $env);
        if (!$provider instanceof SquareProvider) {
            throw new RuntimeException('byKey did not return a SquareProvider.');
        }
        // NOTE: no buyer_email here — this Square sandbox account rejects
        // common test domains (example.com) as INVALID_EMAIL_ADDRESS on
        // pre_populated_data.buyer_email. Omitting it exercises the same
        // create-payment-link path; buyer email is optional for checkout.
        $order = [
            'id'       => 'smoke-' . bin2hex(random_bytes(4)),
            'currency' => 'USD',
        ];
        $items = [[
            'name'             => 'GA',
            'quantity'         => 2,
            'unit_price_cents' => 1000,
        ]];
        $checkout = $provider->createCheckout(
            $order,
            $items,
            'https://panicbooking.com/backstage/checkout/success',
            'https://panicbooking.com/backstage/checkout/cancel'
        );
        $checkoutUrl = $checkout['checkout_url'] ?? null;
        $okD = is_string($checkoutUrl) && $checkoutUrl !== '' && !empty($checkout['provider_ref']);
        $pass('D. Square sandbox checkout', $okD, "url={$checkoutUrl} ref=" . ($checkout['provider_ref'] ?? ''));
    } catch (Throwable $e) {
        $pass('D. Square sandbox checkout', false, $e->getMessage());
    }

    // ── E. Simulate purchase: pending order + items, then fulfillOrder(). ────
    $orderId = $db->insert(
        "INSERT INTO ticket_orders
            (event_id, buyer_name, buyer_email, provider, provider_ref, provider_payment_ref,
             amount_cents, currency, status, hold_expires_at)
         VALUES (?, 'Smoke Buyer', 'sandbox-buyer@example.test', 'square', ?, ?, 2000, 'USD', 'pending',
                 DATE_ADD(NOW(), INTERVAL 15 MINUTE))",
        [$eventId, 'fake-link-' . bin2hex(random_bytes(6)), 'fake-pay-' . bin2hex(random_bytes(6))]
    );
    $db->run(
        'INSERT INTO ticket_order_items (order_id, ticket_type_id, quantity, unit_price_cents)
         VALUES (?, ?, 2, 1000)',
        [$orderId, $typeId]
    );

    $issued = $svc->fulfillOrder($db, $orderId);
    $tokens = array_values(array_filter(array_map(static fn ($t) => $t['token'] ?? null, $issued)));
    $uniqueHashes = $db->one('SELECT COUNT(DISTINCT token_hash) AS c FROM tickets WHERE order_id = ?', [$orderId]);
    $orderRow = $db->one('SELECT status, paid_at FROM ticket_orders WHERE id = ?', [$orderId]);
    $typeRow  = $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId]);
    $availAfter = $svc->availableQuantity($db, $typeId);
    $okE = count($issued) === 2
        && count($tokens) === 2
        && (int) $uniqueHashes['c'] === 2
        && $orderRow['status'] === 'fulfilled'
        && $orderRow['paid_at'] !== null
        && (int) $typeRow['quantity_sold'] === 2
        && $availAfter === 3;
    $pass(
        'E. fulfillOrder issues 2 tickets',
        $okE,
        "issued=" . count($issued) . " tokens=" . count($tokens) .
        " uniq_hash={$uniqueHashes['c']} status={$orderRow['status']} sold={$typeRow['quantity_sold']} avail={$availAfter}"
    );

    // ── F. Idempotency: fulfill again must not create/double-count. ──────────
    $issued2 = $svc->fulfillOrder($db, $orderId);
    $ticketCount = $db->one('SELECT COUNT(*) AS c FROM tickets WHERE order_id = ?', [$orderId]);
    $typeRow2 = $db->one('SELECT quantity_sold FROM ticket_types WHERE id = ?', [$typeId]);
    $okF = (int) $ticketCount['c'] === 2
        && (int) $typeRow2['quantity_sold'] === 2
        && count(array_filter(array_map(static fn ($t) => $t['token'] ?? null, $issued2))) === 0;
    $pass('F. fulfillOrder idempotent', $okF, "tickets={$ticketCount['c']} sold={$typeRow2['quantity_sold']} (no new tokens)");

    // ── G. QR: render a non-empty SVG for one issued token. ──────────────────
    $token0 = $tokens[0] ?? '';
    $qrSvg = '';
    try {
        $ref = new ReflectionClass(QrCode::class);
        $qr  = $ref->newInstanceWithoutConstructor();
        $encode = $ref->getMethod('encode');
        $encode->setAccessible(true);
        $matrix = $encode->invoke($qr, $token0);
        $render = $ref->getMethod('renderSvg');
        $render->setAccessible(true);
        $qrSvg = (string) $render->invoke($qr, $matrix, 240, 4);
        $okG = $token0 !== '' && is_array($matrix) && count($matrix) > 0
            && str_contains($qrSvg, '<svg') && str_contains($qrSvg, '<path');
        $pass('G. QR renders SVG for token', $okG, "matrix=" . count($matrix) . "x" . count($matrix) . " svg_len=" . strlen($qrSvg));
    } catch (Throwable $e) {
        $pass('G. QR renders SVG for token', false, $e->getMessage());
    }

    // ── H. Scan redeem — replicate Scanner's atomic SQL + audit insert. ──────
    $ticketRows = $db->all('SELECT id FROM tickets WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    $ticket1Id = (int) $ticketRows[0]['id'];
    $token1Hash = hash('sha256', $token0);

    // First redeem -> admitted (affected 1).
    $aff1 = $db->run(
        "UPDATE tickets
            SET status = 'redeemed', redeemed_at = NOW(),
                redeemed_by_user_id = NULL, redeemed_via_scanner_id = NULL
          WHERE token_hash = :h AND event_id = :eid AND status = 'issued'",
        [':h' => $token1Hash, ':eid' => $eventId]
    );
    $db->run(
        'INSERT INTO ticket_scans (ticket_id, event_id, result, scanner_link_id, scanned_by_user_id, ip, user_agent)
         VALUES (?, ?, ?, NULL, NULL, ?, ?)',
        [$ticket1Id, $eventId, $aff1 === 1 ? 'admitted' : 'already_redeemed', '127.0.0.1', 'smoke-test']
    );

    // Second redeem -> already_redeemed (affected 0).
    $aff2 = $db->run(
        "UPDATE tickets
            SET status = 'redeemed', redeemed_at = NOW(),
                redeemed_by_user_id = NULL, redeemed_via_scanner_id = NULL
          WHERE token_hash = :h AND event_id = :eid AND status = 'issued'",
        [':h' => $token1Hash, ':eid' => $eventId]
    );
    $db->run(
        'INSERT INTO ticket_scans (ticket_id, event_id, result, scanner_link_id, scanned_by_user_id, ip, user_agent)
         VALUES (?, ?, ?, NULL, NULL, ?, ?)',
        [$ticket1Id, $eventId, $aff2 === 1 ? 'admitted' : 'already_redeemed', '127.0.0.1', 'smoke-test']
    );

    $scanCount = $db->one('SELECT COUNT(*) AS c FROM ticket_scans WHERE ticket_id = ?', [$ticket1Id]);
    $admittedCount = $db->one("SELECT COUNT(*) AS c FROM ticket_scans WHERE ticket_id = ? AND result = 'admitted'", [$ticket1Id]);
    $okH = $aff1 === 1 && $aff2 === 0 && (int) $scanCount['c'] === 2 && (int) $admittedCount['c'] === 1;
    $pass('H. scan redeem (atomic)', $okH, "aff1={$aff1} aff2={$aff2} scans={$scanCount['c']} admitted={$admittedCount['c']}");

    // ── I. Comp: issue 1 GA comp; verify ticket row + mail file written. ─────
    // We send to an obviously-local, non-routable test mailbox to avoid any
    // real delivery. The Mailer ALWAYS writes a copy to storage/mail/ first.
    $compHolderEmail = 'zzz-tix-sandbox-test@localhost';
    $mailDir = $root . '/storage/mail';
    $before = is_dir($mailDir) ? glob($mailDir . '/*.eml') : [];
    $beforeSet = array_flip($before ?: []);

    $comp = $svc->issueComp($db, $typeId, 1, 'Comp Holder', $compHolderEmail, null);
    $compToken = $comp[0]['token'] ?? '';
    // Replicate the endpoint's email step (Events/Ticketing::emailTickets).
    $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    $link = "{$appUrl}/t/{$compToken}";
    $body = "Hi Comp Holder,\n\nComplimentary ticket for " . TEST_TITLE . ".\n\n  {$comp[0]['code']}  ->  {$link}\n";
    (new Mailer($root))->send($compHolderEmail, 'Your comp tickets for ' . TEST_TITLE, $body);

    $after = is_dir($mailDir) ? (glob($mailDir . '/*.eml') ?: []) : [];
    foreach ($after as $f) {
        if (!isset($beforeSet[$f])) {
            $createdMailFiles[] = $f;
        }
    }
    $compRow = $db->one(
        "SELECT t.id, t.holder_email, o.is_comp, o.provider
           FROM tickets t JOIN ticket_orders o ON o.id = t.order_id
          WHERE t.id = ?",
        [(int) ($comp[0]['id'] ?? 0)]
    );
    $okI = $compRow !== null
        && (int) $compRow['is_comp'] === 1
        && $compRow['provider'] === 'comp'
        && $compRow['holder_email'] === $compHolderEmail
        && count($createdMailFiles) >= 1;
    $pass('I. issueComp + mail file', $okI,
        "comp_ticket_id=" . ($comp[0]['id'] ?? 'n/a') . " mail_files=" . count($createdMailFiles));

    // ── J. Refund routing (no live call with fake ref). ──────────────────────
    $oRow = $db->one('SELECT provider FROM ticket_orders WHERE id = ?', [$orderId]);
    $refundProvider = PaymentProviders::byKey((string) $oRow['provider'], $env);
    $okJ = $refundProvider instanceof SquareProvider;
    $pass('J. refund routes via byKey(provider)', $okJ,
        "order.provider={$oRow['provider']} -> " . ($okJ ? 'SquareProvider (refund() NOT called — fake ref)' : 'unexpected'));

    // ── Summary table ────────────────────────────────────────────────────────
    echo "\n=== SUMMARY ===\n";
    foreach ($results as $step => [$verdict, $detail]) {
        printf("%-4s %s%s\n", $verdict, $step, $detail !== '' ? "  ($detail)" : '');
    }
    if ($checkoutUrl) {
        echo "\nSANDBOX CHECKOUT URL: {$checkoutUrl}\n";
    }
    echo "\nPLAINTEXT TOKENS (test only): " . implode(', ', $tokens) . "\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\nFATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    // ── STEP 3: CLEANUP. Delete the single test event; CASCADE handles children.
    if ($eventId !== null && $eventId > 0) {
        echo "\n=== CLEANUP ===\n";
        // Sanity: confirm this id maps to OUR test title before deleting.
        $check = $db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
        if ($check !== null && $check['title'] === TEST_TITLE) {
            // NOTE: ticket_scans has NO foreign key to events (schema gap in
            // migration 020), so ON DELETE CASCADE does NOT reach it. Delete
            // its rows explicitly, scoped to this one test event id, BEFORE
            // dropping the event so we leave zero residue.
            $scanDel = $db->run('DELETE FROM ticket_scans WHERE event_id = ?', [$eventId]);
            echo "Deleted ticket_scans rows (no FK cascade): {$scanDel}\n";

            $del = $db->run('DELETE FROM events WHERE id = ?', [$eventId]);
            echo "Deleted events rows: {$del} (event_id={$eventId})\n";

            foreach ([
                'ticket_types'  => 'event_id',
                'ticket_orders' => 'event_id',
                'tickets'       => 'event_id',
                'ticket_scans'  => 'event_id',
                'event_scanner_links' => 'event_id',
            ] as $table => $col) {
                $row = $db->one("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} = ?", [$eventId]);
                printf("  residue %-20s = %d\n", $table, (int) $row['c']);
            }
        } else {
            fwrite(STDERR, "STOP: event id {$eventId} no longer maps to test title; NOT deleting.\n");
        }
    }

    // Remove mail files this test created (clearly ours: local test mailbox).
    foreach ($createdMailFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
            echo "Removed test mail file: " . basename($f) . "\n";
        }
    }
}
