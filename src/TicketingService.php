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

    // ─── internals ───────────────────────────────────────────────────────────────

    /**
     * Insert a single issued ticket with a fresh secret token. Retries on the
     * (astronomically unlikely) code/token collision.
     *
     * @return array{id:int,code:string,token:?string,ticket_type_id:int,holder_email:?string,holder_name:?string}
     */
    private function createTicket(
        Database $db,
        int $eventId,
        int $ticketTypeId,
        int $orderId,
        ?string $holderName,
        ?string $holderEmail
    ): array {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $secret = $this->generateToken();
            $code   = $this->generateCode();
            try {
                $id = $db->insert(
                    "INSERT INTO tickets
                        (event_id, ticket_type_id, order_id, code, token_hash, token,
                         holder_name, holder_email, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued')",
                    [$eventId, $ticketTypeId, $orderId, $code, $secret['hash'], $secret['token'], $holderName, $holderEmail]
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
