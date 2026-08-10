<?php
declare(strict_types=1);

namespace Panic;

use Throwable;

/**
 * Provider-agnostic ticketing core: token generation, idempotent order
 * fulfillment, live availability accounting, comps, and voids.
 *
 * Security model: the secret per-ticket token is generated from
 * random_bytes(16), base32-encoded for QR/URL friendliness, and returned to
 * the caller exactly ONCE (for email delivery). Only its sha256 hash is
 * persisted (tickets.token_hash) — a leaked DB never exposes scannable tokens.
 *
 * Inventory model (per the contract):
 *   - quantity_sold is the source of truth for fulfilled + comped tickets and
 *     is only ever incremented at fulfillment/comp time.
 *   - A pending order whose hold_expires_at is in the future additionally
 *     reserves inventory; expired pending orders do not.
 *   - availableQuantity = quantity_total - quantity_sold - (active pending holds).
 *   - Oversell guard: the quantity_sold increment is a conditional UPDATE that
 *     only succeeds while it would not exceed quantity_total (checked via
 *     affected rows), so concurrent fulfillments cannot oversell.
 */
final class TicketingService
{
    /** Crockford-ish base32 alphabet (RFC 4648, uppercase, no padding). */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a fresh secret ticket token and its storage hash.
     *
     * @return array{token:string,hash:string} token is plaintext (deliver once);
     *         hash is the sha256 hex stored in tickets.token_hash.
     */
    public function generateToken(): array
    {
        $raw   = random_bytes(16);
        $token = $this->base32Encode($raw);
        $hash  = hash('sha256', $token);
        return ['token' => $token, 'hash' => $hash];
    }

    /**
     * Live availability for a ticket type:
     *   quantity_total - quantity_sold - (qty held by active, non-expired
     *   pending orders).
     *
     * Never returns negative.
     */
    public function availableQuantity(Database $db, int $ticketTypeId): int
    {
        $type = $db->one(
            'SELECT quantity_total, quantity_sold FROM ticket_types WHERE id = ?',
            [$ticketTypeId]
        );
        if ($type === null) {
            return 0;
        }

        $held = $db->one(
            'SELECT COALESCE(SUM(oi.quantity), 0) AS held
               FROM ticket_order_items oi
               JOIN ticket_orders o ON o.id = oi.order_id
              WHERE oi.ticket_type_id = ?
                AND o.status = \'pending\'
                AND o.hold_expires_at IS NOT NULL
                AND o.hold_expires_at > NOW()',
            [$ticketTypeId]
        );

        $available = (int) $type['quantity_total']
            - (int) $type['quantity_sold']
            - (int) ($held['held'] ?? 0);

        return max(0, $available);
    }

    /**
     * Idempotently fulfill a paid order: mark it paid/fulfilled, issue one
     * ticket row per unit across its line items, and atomically increment
     * each ticket type's quantity_sold with an oversell guard.
     *
     * Safe to call multiple times (webhook retries): if the order is already
     * fulfilled, no new tickets are issued and the previously-issued tickets
     * are returned WITHOUT plaintext tokens (those are unrecoverable by
     * design — only the first call can email them).
     *
     * @return array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}>
     *         issued tickets; 'token' is the plaintext secret on first
     *         fulfillment only, null on subsequent (idempotent) calls.
     *
     * @throws \RuntimeException on oversell or missing order.
     */
    public function fulfillOrder(Database $db, int $orderId): array
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            // Lock the order row for the duration of fulfillment so concurrent
            // webhook retries serialize on it.
            $order = $db->one('SELECT * FROM ticket_orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null) {
                $pdo->rollBack();
                throw new \RuntimeException("Order {$orderId} not found.");
            }

            // Already fulfilled -> idempotent no-op: return existing tickets.
            // IMPORTANT: return token=null on this retry path so the webhook
            // handler does NOT re-send the confirmation email.  The plaintext
            // token is still stored in the DB and available to the admin resend
            // path (Ticketing::resendTicket), which reads it directly.
            if ((string) $order['status'] === 'fulfilled') {
                $existing = $this->existingTickets($db, $orderId);
                $pdo->commit();
                return array_map(
                    static fn(array $t): array => array_merge($t, ['token' => null]),
                    $existing
                );
            }

            $items = $db->all(
                'SELECT * FROM ticket_order_items WHERE order_id = ?',
                [$orderId]
            );

            $issued = [];
            $eventId = (int) $order['event_id'];

            foreach ($items as $item) {
                $typeId = (int) $item['ticket_type_id'];
                $qty    = (int) $item['quantity'];
                if ($qty < 1) {
                    continue;
                }

                // Oversell guard: only increment while it stays within total.
                $affected = $db->run(
                    'UPDATE ticket_types
                        SET quantity_sold = quantity_sold + :n
                      WHERE id = :id
                        AND quantity_sold + :n2 <= quantity_total',
                    [':n' => $qty, ':id' => $typeId, ':n2' => $qty]
                );
                if ($affected !== 1) {
                    $pdo->rollBack();
                    throw new \RuntimeException(
                        "Oversell: ticket type {$typeId} lacks {$qty} unit(s) for order {$orderId}."
                    );
                }

                for ($k = 0; $k < $qty; $k++) {
                    $issued[] = $this->createTicket(
                        $db,
                        $eventId,
                        $typeId,
                        $orderId,
                        (string) ($order['buyer_name'] ?? '') ?: null,
                        (string) ($order['buyer_email'] ?? '') ?: null
                    );
                }
            }

            // Feed fulfilled public registrations into the audience CRM in
            // this same transaction. The order-level timestamp makes this
            // idempotent under payment-provider webhook retries.
            $this->syncAudienceContact($db, $order, count($issued));

            $db->run(
                "UPDATE ticket_orders
                    SET status = 'fulfilled',
                        paid_at = COALESCE(paid_at, NOW())
                  WHERE id = ?",
                [$orderId]
            );

            $pdo->commit();
            return $issued;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Capture a fulfilled public buyer in Contacts exactly once per order.
     * Complimentary/admin-issued batches stay out because they are not proof
     * that the holder supplied their own details or marketing consent.
     */
    private function syncAudienceContact(Database $db, array $order, int $ticketCount): void
    {
        if ((int) ($order['is_comp'] ?? 0) === 1 || !empty($order['audience_synced_at'])) {
            return;
        }

        $email = strtolower(trim((string) ($order['buyer_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        [$firstName, $lastName] = self::splitBuyerName((string) ($order['buyer_name'] ?? ''));
        $phone = trim((string) ($order['buyer_phone'] ?? '')) ?: null;
        $optedIn = (int) ($order['marketing_opt_in'] ?? 0) === 1;
        $eventId = (int) $order['event_id'];

        $contact = $db->one(
            'SELECT id, marketing_opted_in FROM contacts WHERE LOWER(email) = ? ORDER BY id LIMIT 1 FOR UPDATE',
            [$email]
        );

        if ($contact === null) {
            $contactId = $db->insert(
                'INSERT INTO contacts
                    (source, first_name, last_name, email, phone, events_count, tickets_count,
                     usd_spend, last_interaction, marketing_opted_in, opt_in_date)
                 VALUES (\'ticketing\', ?, ?, ?, ?, 1, ?, ?, NOW(), ?, ?)',
                [
                    $firstName, $lastName, $email, $phone, $ticketCount,
                    ((int) ($order['amount_cents'] ?? 0)) / 100,
                    $optedIn ? 1 : 0,
                    $optedIn ? date('Y-m-d') : null,
                ]
            );
            log_contact_activity(
                $db,
                $contactId,
                null,
                'ticket_registration',
                'Contact captured from ticket registration',
                ['event_id' => $eventId, 'order_id' => (int) $order['id'], 'tickets' => $ticketCount]
            );
        } else {
            $contactId = (int) $contact['id'];
            $priorEvent = $db->one(
                'SELECT 1 FROM ticket_orders
                  WHERE contact_id = ? AND event_id = ? AND audience_synced_at IS NOT NULL
                  LIMIT 1',
                [$contactId, $eventId]
            );
            $db->run(
                'UPDATE contacts
                    SET first_name = COALESCE(NULLIF(first_name, \'\'), ?),
                        last_name = COALESCE(NULLIF(last_name, \'\'), ?),
                        phone = COALESCE(NULLIF(phone, \'\'), ?),
                        events_count = events_count + ?,
                        tickets_count = tickets_count + ?,
                        usd_spend = usd_spend + ?,
                        last_interaction = NOW(),
                        opt_in_date = IF(? = 1, COALESCE(opt_in_date, CURDATE()), opt_in_date),
                        marketing_opted_in = IF(marketing_opted_in = 1 OR ? = 1, 1, 0)
                  WHERE id = ?',
                [
                    $firstName, $lastName, $phone, $priorEvent === null ? 1 : 0,
                    $ticketCount, ((int) ($order['amount_cents'] ?? 0)) / 100,
                    $optedIn ? 1 : 0, $optedIn ? 1 : 0, $contactId,
                ]
            );
            log_contact_activity(
                $db,
                $contactId,
                null,
                'ticket_registration',
                'Ticket registration added to contact',
                ['event_id' => $eventId, 'order_id' => (int) $order['id'], 'tickets' => $ticketCount]
            );
        }

        $db->run(
            'UPDATE ticket_orders SET contact_id = ?, audience_synced_at = NOW() WHERE id = ?',
            [$contactId, (int) $order['id']]
        );
    }

    /** @return array{0:?string,1:?string} */
    private static function splitBuyerName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return [null, null];
        }
        if (count($parts) === 1) {
            return [$parts[0], null];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    /**
     * Issue one or more complimentary tickets for a type (no payment).
     *
     * Creates a comp ticket_orders batch (is_comp=1, status=fulfilled,
     * provider='comp'), increments quantity_sold under the same oversell guard,
     * and issues the ticket rows. Returns the issued tickets WITH plaintext
     * tokens for delivery.
     *
     * @return array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}>
     *
     * @throws \RuntimeException on oversell or unknown ticket type.
     */
    public function issueComp(
        Database $db,
        int $ticketTypeId,
        int $quantity,
        ?string $holderName = null,
        ?string $holderEmail = null,
        ?int $issuedByUserId = null
    ): array {
        $quantity = max(1, $quantity);

        $type = $db->one('SELECT id, event_id, currency FROM ticket_types WHERE id = ?', [$ticketTypeId]);
        if ($type === null) {
            throw new \RuntimeException("Ticket type {$ticketTypeId} not found.");
        }
        $eventId  = (int) $type['event_id'];
        $currency = (string) ($type['currency'] ?? 'USD');

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $affected = $db->run(
                'UPDATE ticket_types
                    SET quantity_sold = quantity_sold + :n
                  WHERE id = :id
                    AND quantity_sold + :n2 <= quantity_total',
                [':n' => $quantity, ':id' => $ticketTypeId, ':n2' => $quantity]
            );
            if ($affected !== 1) {
                $pdo->rollBack();
                throw new \RuntimeException(
                    "Oversell: ticket type {$ticketTypeId} lacks {$quantity} comp unit(s)."
                );
            }

            $orderId = $db->insert(
                "INSERT INTO ticket_orders
                    (event_id, buyer_user_id, buyer_name, buyer_email, provider,
                     amount_cents, currency, status, is_comp, paid_at)
                 VALUES (?, ?, ?, ?, 'comp', 0, ?, 'fulfilled', 1, NOW())",
                [$eventId, $issuedByUserId, $holderName, $holderEmail, $currency]
            );

            $db->run(
                'INSERT INTO ticket_order_items (order_id, ticket_type_id, quantity, unit_price_cents)
                 VALUES (?, ?, ?, 0)',
                [$orderId, $ticketTypeId, $quantity]
            );

            $issued = [];
            for ($k = 0; $k < $quantity; $k++) {
                $issued[] = $this->createTicket(
                    $db,
                    $eventId,
                    $ticketTypeId,
                    $orderId,
                    $holderName,
                    $holderEmail
                );
            }

            $pdo->commit();
            return $issued;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record a walk-up sale taken at the door and admit the buyer in one step.
     *
     * Same transaction shape and oversell guard as issueComp(), with three
     * deliberate differences:
     *   - the order is real money: provider='door', is_comp=0,
     *     amount_cents = unit price * quantity, payment_method = cash|card|other;
     *   - the tickets are born 'redeemed' rather than 'issued', because the
     *     buyer is physically at the door — there is no second scan coming, and
     *     leaving them 'issued' would let the same paper ticket walk in again;
     *   - redeemed_via_scanner_id attributes the admission to the door device,
     *     matching what Scanner::performRedeem() writes for a scanned ticket.
     *
     * Plaintext tokens come back so the caller can email a receipt/QR when the
     * buyer supplied an address (a redeemed ticket's QR is still useful as
     * proof of purchase and for re-entry policies).
     *
     * @return array{order_id:int,amount_cents:int,currency:string,
     *               tickets:array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}>}
     *
     * @throws \RuntimeException on oversell or unknown ticket type.
     */
    public function recordDoorSale(
        Database $db,
        int $ticketTypeId,
        int $quantity,
        string $paymentMethod,
        ?string $holderName = null,
        ?string $holderEmail = null,
        ?int $scannerLinkId = null,
        ?int $soldByUserId = null
    ): array {
        $quantity = max(1, $quantity);
        if (!in_array($paymentMethod, ['cash', 'card', 'other'], true)) {
            $paymentMethod = 'other';
        }

        $type = $db->one(
            'SELECT id, event_id, currency, price_cents FROM ticket_types WHERE id = ?',
            [$ticketTypeId]
        );
        if ($type === null) {
            throw new \RuntimeException("Ticket type {$ticketTypeId} not found.");
        }
        $eventId   = (int) $type['event_id'];
        $currency  = (string) ($type['currency'] ?? 'USD');
        $unitPrice = (int) $type['price_cents'];
        $total     = $unitPrice * $quantity;

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            // Identical guard to fulfillOrder()/issueComp(): the increment only
            // lands while it stays within quantity_total, so a door sale racing
            // online checkout cannot oversell the room.
            $affected = $db->run(
                'UPDATE ticket_types
                    SET quantity_sold = quantity_sold + :n
                  WHERE id = :id
                    AND quantity_sold + :n2 <= quantity_total',
                [':n' => $quantity, ':id' => $ticketTypeId, ':n2' => $quantity]
            );
            if ($affected !== 1) {
                $pdo->rollBack();
                throw new \RuntimeException(
                    "Oversell: ticket type {$ticketTypeId} lacks {$quantity} unit(s) for a door sale."
                );
            }

            $orderId = $db->insert(
                "INSERT INTO ticket_orders
                    (event_id, buyer_user_id, buyer_name, buyer_email, provider,
                     payment_method, amount_cents, currency, status, is_comp, paid_at)
                 VALUES (?, ?, ?, ?, 'door', ?, ?, ?, 'fulfilled', 0, NOW())",
                [$eventId, $soldByUserId, $holderName, $holderEmail, $paymentMethod, $total, $currency]
            );

            $db->run(
                'INSERT INTO ticket_order_items (order_id, ticket_type_id, quantity, unit_price_cents)
                 VALUES (?, ?, ?, ?)',
                [$orderId, $ticketTypeId, $quantity, $unitPrice]
            );

            $issued = [];
            for ($k = 0; $k < $quantity; $k++) {
                $issued[] = $this->createTicket(
                    $db,
                    $eventId,
                    $ticketTypeId,
                    $orderId,
                    $holderName,
                    $holderEmail,
                    'redeemed',
                    $scannerLinkId
                );
            }

            $pdo->commit();
            return [
                'order_id'     => $orderId,
                'amount_cents' => $total,
                'currency'     => $currency,
                'tickets'      => $issued,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Void an issued ticket and return its unit to inventory (decrement
     * quantity_sold, floored at zero). Idempotent: voiding an already-void
     * ticket is a no-op that returns true. Returns false if the ticket does
     * not exist.
     */
    public function voidTicket(Database $db, int $ticketId, ?int $byUserId = null): bool
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $ticket = $db->one('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if ($ticket === null) {
                $pdo->rollBack();
                return false;
            }

            if ((string) $ticket['status'] === 'void') {
                $pdo->commit();
                return true;
            }

            $db->run(
                "UPDATE tickets SET status = 'void', voided_at = NOW() WHERE id = ?",
                [$ticketId]
            );

            // Return the unit to inventory without underflowing the counter.
            $db->run(
                'UPDATE ticket_types
                    SET quantity_sold = GREATEST(quantity_sold - 1, 0)
                  WHERE id = ?',
                [(int) $ticket['ticket_type_id']]
            );

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Email the buyer their tickets. Each ticket links to its public view page
     * (/t/{token}) and embeds the self-rendering QR SVG pointed at the bare
     * token so it scans reliably at the door.
     *
     * Shared by the payment webhook (post-fulfillment) and the $0/free-order
     * checkout path (PublicTickets), both of which fulfill an order and then
     * need to deliver the same confirmation email. Idempotent via the
     * emailed_at claim below, so it's safe even if both paths could somehow
     * race for the same order.
     *
     * @param array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}> $tickets
     */
    public function emailTickets(Database $db, string $root, int $orderId, array $tickets): void
    {
        // ── Atomic deduplication ──────────────────────────────────────────────
        // Only the first caller that wins this UPDATE proceeds to send. Any
        // concurrent or subsequent attempt gets 0 rows and returns immediately.
        // This is a second line of defence on top of the token-nulling in
        // fulfillOrder() above.
        $claimed = $db->run(
            'UPDATE ticket_orders SET emailed_at = NOW() WHERE id = ? AND emailed_at IS NULL',
            [$orderId]
        );
        if ($claimed === 0) {
            return; // Already emailed — do not send again.
        }

        $order = $db->one(
            'SELECT o.buyer_name, o.buyer_email, e.title AS event_title,
                    v.name AS venue_name, v.address AS venue_address, v.city AS venue_city, v.state AS venue_state,
                    r.address AS room_address
               FROM ticket_orders o
               JOIN events e ON e.id = o.event_id
               LEFT JOIN venues v ON v.id = e.venue_id
               LEFT JOIN resources r ON r.id = e.resource_id
              WHERE o.id = ?',
            [$orderId]
        );
        if ($order === null) {
            return;
        }

        $to = (string) ($order['buyer_email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $title     = (string) ($order['event_title'] ?? 'your event');
        $buyerName = (string) ($order['buyer_name']  ?? '');
        $appUrl    = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $venueLine = Address::line(
            $order['venue_name'] ?? null,
            $order['room_address'] ?? null,
            $order['venue_address'] ?? null,
            $order['venue_city'] ?? null,
            $order['venue_state'] ?? null
        );

        $textLines = [];
        $htmlItems = [];
        $inline    = [];   // Content-ID => raw PNG bytes for MIME multipart/related
        $n = 0;
        foreach ($tickets as $ticket) {
            $token = (string) ($ticket['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $n++;
            $viewUrl = $appUrl . '/t/' . rawurlencode($token);
            $code    = htmlspecialchars((string) $ticket['code'], ENT_QUOTES, 'UTF-8');
            $safeView = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');

            // Generate QR PNG bytes directly (no HTTP round-trip) and embed as a
            // MIME CID attachment so the image is always present regardless of
            // whether the recipient's email client loads remote images.
            $cid     = 'qr-' . $n . '-' . bin2hex(random_bytes(6)) . '@' . (getenv('APP_HOST') ?: 'localhost');
            $pngBytes = QrCode::generatePng($token, 300);
            if ($pngBytes !== '') {
                $inline[$cid] = $pngBytes;
                $qrSrc = 'cid:' . $cid;
            } else {
                // Fallback: external URL (e.g. if GD unavailable).
                $qrSrc = htmlspecialchars(
                    $appUrl . '/assets/qr.png?text=' . rawurlencode($token) . '&size=300',
                    ENT_QUOTES, 'UTF-8'
                );
            }

            $textLines[] = 'Ticket ' . $n . '  (' . (string) $ticket['code'] . ')';
            $textLines[] = '  View ticket + QR: ' . $viewUrl;
            $textLines[] = '';

            // Wrap the QR image in a link so tapping it opens the ticket page
            // even when images are blocked.  Add a plain "View your ticket"
            // link below for all clients.
            $htmlItems[] = '<div style="padding:16px 0;border-bottom:1px solid #2e2929;">'
                . '<div style="font-size:13px;color:#a9a097;letter-spacing:1px;text-transform:uppercase;">Ticket ' . $n . '</div>'
                . '<div style="margin-top:4px;font-size:16px;font-weight:bold;color:#fff;">' . $code . '</div>'
                . '<div style="margin-top:14px;text-align:center;">'
                . '<a href="' . $safeView . '" style="display:inline-block;line-height:0;border:2px solid #3a3434;border-radius:4px;">'
                . '<img src="' . $qrSrc . '" alt="QR code — tap to open your ticket" width="200" height="200"'
                . ' style="display:block;background:#ffffff;padding:10px;">'
                . '</a>'
                . '</div>'
                . '<div style="margin-top:8px;font-size:13px;color:#b5aba2;text-align:center;">'
                . 'Screenshot or save this QR &mdash; show it at the door to get in.'
                . '</div>'
                . '<div style="margin-top:10px;font-size:13px;">'
                . '<a href="' . $safeView . '" style="color:#c9b27e;font-weight:bold;">View your ticket &amp; QR &rarr;</a>'
                . '</div></div>';
        }

        if ($n === 0) {
            return;
        }

        $greeting = $buyerName !== ''
            ? 'Hi <strong style="color:#fff;">' . htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8') . '</strong>,'
            : 'Hello,';

        (new Mailer($root, $db))->sendTemplate(
            $to,
            'Your tickets for ' . $title,
            'ticket-purchase',
            [
                'event_title'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'greeting'     => $greeting,
                'tickets_html' => implode('', $htmlItems),
                'tickets_text' => implode("\n", $textLines),
                'venue_line'   => htmlspecialchars($venueLine, ENT_QUOTES, 'UTF-8'),
            ],
            $inline
        );
    }

    // ─── internals ───────────────────────────────────────────────────────────────

    /**
     * Insert a single ticket with a fresh secret token. Retries on the
     * (astronomically unlikely) code/token collision.
     *
     * $status is 'issued' for every ticket that still has to be scanned in.
     * Door sales pass 'redeemed' (with the selling $scannerLinkId) because the
     * buyer is admitted at the moment of purchase; that stamps redeemed_at and
     * redeemed_via_scanner_id up front so the row is indistinguishable from one
     * the scanner flipped, and no second admission is possible.
     *
     * @param 'issued'|'redeemed' $status
     *
     * @return array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}
     */
    private function createTicket(
        Database $db,
        int $eventId,
        int $ticketTypeId,
        int $orderId,
        ?string $holderName,
        ?string $holderEmail,
        string $status = 'issued',
        ?int $scannerLinkId = null
    ): array {
        $redeemed = $status === 'redeemed';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $secret = $this->generateToken();
            $code   = $this->generateCode();
            try {
                $id = $db->insert(
                    "INSERT INTO tickets
                        (event_id, ticket_type_id, order_id, code, token_hash, token,
                         holder_name, holder_email, status, redeemed_at, redeemed_via_scanner_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($redeemed ? 'NOW()' : 'NULL') . ", ?)",
                    [
                        $eventId, $ticketTypeId, $orderId, $code, $secret['hash'], $secret['token'],
                        $holderName, $holderEmail,
                        $redeemed ? 'redeemed' : 'issued',
                        $redeemed ? $scannerLinkId : null,
                    ]
                );
            } catch (Throwable $e) {
                // Unique collision on code/token_hash — regenerate and retry.
                if (str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'duplicate') !== false) {
                    continue;
                }
                throw $e;
            }

            return [
                'id'             => $id,
                'code'           => $code,
                'token'          => $secret['token'],
                'ticket_type_id' => $ticketTypeId,
                'holder_email'   => $holderEmail,
                'holder_name'    => $holderName,
            ];
        }

        throw new \RuntimeException('Failed to issue a unique ticket after several attempts.');
    }

    /**
     * Previously-issued tickets for an order (idempotent return path). The
     * stored plaintext token is included so callers can re-display or resend the
     * QR; it is null only for legacy tickets issued before tokens were stored.
     *
     * @return array<int,array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}>
     */
    private function existingTickets(Database $db, int $orderId): array
    {
        $rows = $db->all(
            'SELECT id, code, token, ticket_type_id, holder_email, holder_name
               FROM tickets WHERE order_id = ? ORDER BY id ASC',
            [$orderId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'code'           => (string) $r['code'],
                'token'          => $r['token'] !== null ? (string) $r['token'] : null,
                'ticket_type_id' => (int) $r['ticket_type_id'],
                'holder_email'   => $r['holder_email'] !== null ? (string) $r['holder_email'] : null,
                'holder_name'    => $r['holder_name'] !== null ? (string) $r['holder_name'] : null,
            ];
        }
        return $out;
    }

    /** Short, human-facing reference (NOT the secret), e.g. "TKT-7F3K9Q2B". */
    private function generateCode(): string
    {
        return 'TKT-' . $this->base32Encode(random_bytes(5));
    }

    /** RFC 4648 base32 (uppercase, no padding) of arbitrary bytes. */
    private function base32Encode(string $bytes): string
    {
        $bits = '';
        $len  = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::BASE32[bindec($chunk)];
        }
        return $out;
    }
}
