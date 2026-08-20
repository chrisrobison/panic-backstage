<?php

declare(strict_types=1);

namespace Panic;

use Throwable;

/**
 * Creates a physical ticket print batch: one `physical_ticket_batches` row
 * plus one `tickets` row per unit, each with its own unique sequential
 * printed_number and its own cryptographically random token — the actual
 * DB-record-creation half of the physical-ticket-batch feature (see
 * PhysicalTicketPdfGenerator for the read-only PDF-rendering half, which
 * never touches these rows).
 *
 * Deliberately reuses TicketingService's existing token/code generators
 * rather than duplicating them (this batch's tickets are ordinary rows in
 * the same `tickets` table — see migration 108's docblock for why there is
 * no separate "physical tickets" table).
 *
 * Inventory accounting: printing a batch increments ticket_types.quantity_sold
 * by the full batch quantity AT CREATION TIME, under the exact same
 * conditional-UPDATE oversell guard every other ticket-issuing method in
 * TicketingService uses. This is a deliberate choice, not an oversight: a
 * printed stub is capable of admitting someone (once activated/sold) the
 * instant it exists, so leaving it out of quantity_sold would let online
 * sales and a physical print run independently oversell the same room. It
 * mirrors exactly how issueComp() already treats a comp ticket as "sold" the
 * moment it's issued, before anyone has actually redeemed it.
 *
 * Atomicity: batch creation is all-or-nothing in one transaction — the
 * requested printed_number range is validated against existing tickets in a
 * single query BEFORE any row is written, and the oversell guard runs before
 * the insert loop, so a rejected batch never partially writes tickets. There
 * is no HTTP-207-style partial-success mode here; a batch that can't be
 * fully created is entirely rejected.
 */
final class PhysicalTicketBatchService
{
    /**
     * @param array{
     *   ticket_type_id:int, quantity:int, first_ticket_number:int,
     *   number_pad_width?:int, name?:?string, seller_label?:?string,
     *   ticket_width_in?:float, ticket_height_in?:float, bleed_in?:float,
     *   crop_marks?:bool, artwork_path?:?string
     * } $params
     *
     * @return array{batch_id:int,event_id:int,ticket_type_id:int,quantity:int,
     *               first_ticket_number:int,last_ticket_number:int,
     *               ticket_ids:array<int,int>}
     *
     * @throws \InvalidArgumentException on malformed input
     * @throws PhysicalTicketRangeCollisionException if the requested printed_number
     *         range overlaps tickets this event already has
     * @throws PhysicalTicketOversellException if the tier lacks enough remaining allocation
     * @throws \RuntimeException on unknown ticket type / event not in-house ticketing
     */
    public function createBatch(Database $db, array $params, ?int $createdByUserId): array
    {
        $ticketTypeId = (int) ($params['ticket_type_id'] ?? 0);
        $quantity     = (int) ($params['quantity'] ?? 0);
        $firstNumber  = (int) ($params['first_ticket_number'] ?? 1);
        $padWidth     = max(1, min(20, (int) ($params['number_pad_width'] ?? 6)));
        $name         = isset($params['name']) ? (trim((string) $params['name']) ?: null) : null;
        $sellerLabel  = isset($params['seller_label']) ? (trim((string) $params['seller_label']) ?: null) : null;
        $widthIn      = isset($params['ticket_width_in']) ? (float) $params['ticket_width_in'] : 2.0;
        $heightIn     = isset($params['ticket_height_in']) ? (float) $params['ticket_height_in'] : 5.5;
        $bleedIn      = isset($params['bleed_in']) ? (float) $params['bleed_in'] : 0.125;
        $cropMarks    = !empty($params['crop_marks']);
        $artworkPath  = isset($params['artwork_path']) ? (string) $params['artwork_path'] : null;

        if ($ticketTypeId <= 0) {
            throw new \InvalidArgumentException('A ticket type is required.');
        }
        if ($quantity < 1 || $quantity > 5000) {
            throw new \InvalidArgumentException('Quantity must be between 1 and 5000.');
        }
        if ($firstNumber < 0) {
            throw new \InvalidArgumentException('Starting ticket number cannot be negative.');
        }
        if ($widthIn <= 0 || $heightIn <= 0 || $widthIn > 20 || $heightIn > 20) {
            throw new \InvalidArgumentException('Ticket dimensions must be a plausible size (0–20 inches).');
        }
        if ($bleedIn < 0 || $bleedIn > 1) {
            throw new \InvalidArgumentException('Bleed must be between 0 and 1 inch.');
        }

        $type = $db->one(
            'SELECT tt.id, tt.event_id, tt.name, tt.currency, e.ticketing_mode
               FROM ticket_types tt
               JOIN events e ON e.id = tt.event_id
              WHERE tt.id = ?',
            [$ticketTypeId]
        );
        if ($type === null) {
            throw new \RuntimeException("Ticket type {$ticketTypeId} not found.");
        }
        if ((string) $type['ticketing_mode'] !== 'internal') {
            throw new \RuntimeException('This event is not set up for in-house ticketing.');
        }
        $eventId = (int) $type['event_id'];
        $lastNumber = $firstNumber + $quantity - 1;

        $ticketingService = new TicketingService();

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            // Lock the tier row for the duration so a concurrent batch/comp/
            // sale can't race the oversell check below.
            $db->one('SELECT id FROM ticket_types WHERE id = ? FOR UPDATE', [$ticketTypeId]);

            // Pre-check the ENTIRE requested printed_number range in one query
            // before writing anything — a collision must reject the whole
            // batch up front, never surface mid-loop after some rows landed.
            $numbers = [];
            for ($n = $firstNumber; $n <= $lastNumber; $n++) {
                $numbers[] = self::padNumber($n, $padWidth);
            }
            $placeholders = implode(',', array_fill(0, count($numbers), '?'));
            $collision = $db->one(
                "SELECT printed_number FROM tickets
                  WHERE event_id = ? AND printed_number IN ({$placeholders}) LIMIT 1",
                array_merge([$eventId], $numbers)
            );
            if ($collision !== null) {
                $pdo->rollBack();
                throw new PhysicalTicketRangeCollisionException(
                    "Ticket #{$firstNumber}\xE2\x80\x93#{$lastNumber} collides with an existing ticket "
                    . "(#{$collision['printed_number']} is already in use for this event)."
                );
            }

            // Same oversell guard as every other ticket-issuing method in
            // TicketingService — see this file's docblock for why printing
            // commits inventory immediately.
            $affected = $db->run(
                'UPDATE ticket_types
                    SET quantity_sold = quantity_sold + :n
                  WHERE id = :id
                    AND quantity_sold + :n2 <= quantity_total',
                [':n' => $quantity, ':id' => $ticketTypeId, ':n2' => $quantity]
            );
            if ($affected !== 1) {
                $pdo->rollBack();
                throw new PhysicalTicketOversellException(
                    "Ticket type {$ticketTypeId} lacks {$quantity} remaining unit(s) for this batch."
                );
            }

            $batchId = $db->insert(
                'INSERT INTO physical_ticket_batches
                    (event_id, ticket_type_id, name, quantity, first_ticket_number, last_ticket_number,
                     number_pad_width, seller_label, ticket_width_in, ticket_height_in, bleed_in,
                     crop_marks, artwork_path, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $eventId, $ticketTypeId, $name, $quantity, $firstNumber, $lastNumber,
                    $padWidth, $sellerLabel, $widthIn, $heightIn, $bleedIn,
                    $cropMarks ? 1 : 0, $artworkPath, $createdByUserId,
                ]
            );

            $physicalStatus = $sellerLabel !== null ? 'allocated' : 'printed';
            $ticketIds = [];
            for ($n = $firstNumber; $n <= $lastNumber; $n++) {
                $ticketIds[] = $this->insertOneTicket(
                    $db, $ticketingService, $eventId, $ticketTypeId, $batchId,
                    self::padNumber($n, $padWidth), $physicalStatus
                );
            }

            $pdo->commit();

            return [
                'batch_id'            => $batchId,
                'event_id'            => $eventId,
                'ticket_type_id'      => $ticketTypeId,
                'quantity'            => $quantity,
                'first_ticket_number' => $firstNumber,
                'last_ticket_number'  => $lastNumber,
                'ticket_ids'          => $ticketIds,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Insert a single physical ticket row. Retries on the (astronomically
     * unlikely) token/code collision, mirroring TicketingService::createTicket()'s
     * own retry loop — the printed_number is never regenerated on retry
     * (it's the caller's fixed sequential number, already collision-checked
     * up front), only the random token/code are.
     */
    private function insertOneTicket(
        Database $db,
        TicketingService $ticketingService,
        int $eventId,
        int $ticketTypeId,
        int $batchId,
        string $printedNumber,
        string $physicalStatus
    ): int {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $secret = $ticketingService->generateToken();
            $code   = $ticketingService->generateCode();
            try {
                return $db->insert(
                    "INSERT INTO tickets
                        (event_id, ticket_type_id, order_id, physical_batch_id, delivery_method,
                         physical_status, code, printed_number, token_hash, token, status)
                     VALUES (?, ?, NULL, ?, 'physical', ?, ?, ?, ?, ?, 'issued')",
                    [
                        $eventId, $ticketTypeId, $batchId, $physicalStatus,
                        $code, $printedNumber, $secret['hash'], $secret['token'],
                    ]
                );
            } catch (Throwable $e) {
                $isDuplicate = str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'duplicate') !== false;
                if ($isDuplicate && str_contains($e->getMessage(), 'uq_tickets_event_printed_number')) {
                    // The range was pre-checked, so this can only mean a
                    // concurrent writer landed the same number between our
                    // check and this insert — surface it distinctly rather
                    // than silently retrying with the same doomed number.
                    throw new PhysicalTicketRangeCollisionException(
                        "Ticket #{$printedNumber} was just claimed by a concurrent request."
                    );
                }
                if ($isDuplicate) {
                    continue; // code/token collision — regenerate and retry.
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Failed to issue a unique physical ticket after several attempts.');
    }

    private static function padNumber(int $n, int $padWidth): string
    {
        return str_pad((string) $n, $padWidth, '0', STR_PAD_LEFT);
    }
}

/** The requested printed_number range overlaps a ticket this event already has. */
final class PhysicalTicketRangeCollisionException extends \RuntimeException
{
}

/** The ticket type does not have enough remaining allocation for this batch. */
final class PhysicalTicketOversellException extends \RuntimeException
{
}
