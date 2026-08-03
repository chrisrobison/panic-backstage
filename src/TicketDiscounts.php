<?php
declare(strict_types=1);

namespace Panic;

/**
 * Private discount / coupon codes for in-house ticketing.
 *
 * A code is scoped to one event, is never listed on the public ticket page,
 * and only does anything for a buyer who types it (or arrives on a `?code=`
 * link). This is the middle ground that previously didn't exist between
 * "drop the tier price for everybody" and "issue a 100%-off comp".
 *
 * ── How the money is applied ────────────────────────────────────────────────
 * The discount is folded into the *line item unit prices* rather than carried
 * as a separate order-level adjustment. That single decision buys a lot:
 *
 *   - Provider-agnostic. PaymentProvider::createCheckout() builds Stripe's and
 *     Square's line items straight from unit_price_cents, so a discounted cart
 *     just... costs less, on both providers, with zero provider-specific code
 *     and no Stripe Coupon objects to keep in sync.
 *   - Self-consistent reporting. Every revenue number in the app is
 *     SUM(oi.quantity * oi.unit_price_cents) — tier revenue, the settlement
 *     sync, Reports.php gross ticket sales. Discounted money nets out of all
 *     of them automatically, and the amount charged can never drift from the
 *     amount recorded.
 *
 * ticket_orders.discount_cents is kept purely as a memo of what was given
 * away, so the admin can see what a code cost. It is never added back into
 * revenue anywhere.
 *
 * ── Exact-cents allocation ──────────────────────────────────────────────────
 * A line item stores ONE unit price for N units, so a discount that doesn't
 * divide evenly across a line can't be expressed in a single row.
 *
 *   - percent: no problem — every unit of a tier discounts to the same price,
 *     so each line stays one row.
 *   - fixed: the flat amount is allocated across eligible lines in proportion
 *     to line value (largest-remainder, so the cents add up exactly), then a
 *     line whose share doesn't divide by its quantity is SPLIT into two rows
 *     — r units one cent cheaper, the rest at the base discounted price.
 *     TicketingService::fulfillOrder() iterates order items and issues per
 *     row, so a split line issues exactly the same tickets.
 *
 * The result is that sum(quantity * unit_price_cents) equals the intended
 * total to the cent, with no rounding drift and no negative prices.
 *
 * ── Redemption counting ─────────────────────────────────────────────────────
 * Usage is derived, never a counter column: it's a live COUNT over orders that
 * are paid/fulfilled or still holding inventory (pending with an unexpired
 * hold). That mirrors TicketingService::availableQuantity()'s treatment of
 * holds, so an abandoned checkout frees its claim on a limited code when the
 * hold lapses — no bookkeeping to get wrong, no counter to leak.
 */
final class TicketDiscounts
{
    public const KIND_PERCENT = 'percent';
    public const KIND_FIXED   = 'fixed';

    /** Codes are matched exactly, so both sides get normalized identically. */
    public static function normalizeCode(string $raw): string
    {
        $code = strtoupper(trim($raw));
        // Collapse any internal whitespace so "EAST BAY" and "EASTBAY" can't
        // become two different codes a booker has to remember the spacing of.
        $code = (string) preg_replace('/\s+/', '', $code);
        return substr($code, 0, 40);
    }

    /** Resolve a code within an event. Returns the row or null. */
    public static function find(Database $db, int $eventId, string $rawCode): ?array
    {
        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            return null;
        }
        return $db->one(
            'SELECT * FROM ticket_discount_codes WHERE event_id = ? AND code = ?',
            [$eventId, $code]
        );
    }

    /**
     * Ticket type ids this code is limited to. Empty array = every tier.
     *
     * @return array<int,int>
     */
    public static function scopedTypeIds(Database $db, int $codeId): array
    {
        $rows = $db->all(
            'SELECT ticket_type_id FROM ticket_discount_code_types WHERE discount_code_id = ?',
            [$codeId]
        );
        return array_map(static fn(array $r): int => (int) $r['ticket_type_id'], $rows);
    }

    /**
     * Live redemption count: paid/fulfilled orders plus unexpired holds.
     *
     * @param string|null $buyerEmail when given, counts only that buyer's
     *                                redemptions (for the once_per_email check).
     */
    public static function redemptionCount(Database $db, int $codeId, ?string $buyerEmail = null): int
    {
        $sql = "SELECT COUNT(*) AS n
                  FROM ticket_orders
                 WHERE discount_code_id = ?
                   AND (
                         status IN ('paid', 'fulfilled')
                      OR (status = 'pending' AND hold_expires_at IS NOT NULL AND hold_expires_at > NOW())
                       )";
        $params = [$codeId];

        if ($buyerEmail !== null) {
            $sql .= ' AND LOWER(buyer_email) = ?';
            $params[] = strtolower(trim($buyerEmail));
        }

        $row = $db->one($sql, $params);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Check a resolved code against its own limits.
     *
     * Returns a buyer-facing error message, or null when the code is usable.
     * Messages are deliberately specific ("this code has expired") rather than
     * a uniform "invalid": these are marketing codes handed out on purpose, so
     * a confused recipient is a far more likely caller than an attacker, and
     * the public endpoint is rate limited against code-guessing regardless.
     */
    public static function checkUsable(Database $db, array $code, ?string $buyerEmail = null): ?string
    {
        if ((string) $code['status'] !== 'active') {
            return 'That code is no longer active.';
        }

        $now = time();
        $startsAt = self::timestamp($code['starts_at'] ?? null);
        if ($startsAt !== null && $startsAt > $now) {
            return 'That code is not active yet.';
        }
        $expiresAt = self::timestamp($code['expires_at'] ?? null);
        if ($expiresAt !== null && $expiresAt < $now) {
            return 'That code has expired.';
        }

        $codeId  = (int) $code['id'];
        $maxUses = $code['max_uses'] !== null ? (int) $code['max_uses'] : null;
        if ($maxUses !== null && $maxUses > 0 && self::redemptionCount($db, $codeId) >= $maxUses) {
            return 'That code has already been fully redeemed.';
        }

        if (!empty($code['once_per_email'])
            && $buyerEmail !== null
            && trim($buyerEmail) !== ''
            && self::redemptionCount($db, $codeId, $buyerEmail) > 0
        ) {
            return 'That code has already been used with this email address.';
        }

        return null;
    }

    /**
     * Apply a code to a cart.
     *
     * Pure: no DB, no clock. Given the code row, the resolved line items and
     * the code's tier scoping, returns the rewritten line items (possibly with
     * one line split in two — see the class docblock) plus the exact total
     * discount in cents.
     *
     * @param array $code          a ticket_discount_codes row
     * @param array $lines         list of ['ticket_type_id','name','quantity','unit_price_cents']
     * @param array $scopedTypeIds tier ids the code is limited to; [] = all tiers
     *
     * @return array{lines:array<int,array>,discount_cents:int,eligible_subtotal_cents:int}
     */
    public static function apply(array $code, array $lines, array $scopedTypeIds): array
    {
        $isEligible = static function (array $line) use ($scopedTypeIds): bool {
            // A zero-priced tier has nothing to discount; leaving it out also
            // keeps it from soaking up part of a fixed-amount allocation.
            if ((int) $line['unit_price_cents'] <= 0 || (int) $line['quantity'] <= 0) {
                return false;
            }
            return $scopedTypeIds === []
                || in_array((int) $line['ticket_type_id'], $scopedTypeIds, true);
        };

        $eligibleSubtotal = 0;
        foreach ($lines as $line) {
            if ($isEligible($line)) {
                $eligibleSubtotal += (int) $line['unit_price_cents'] * (int) $line['quantity'];
            }
        }

        if ($eligibleSubtotal <= 0) {
            return [
                'lines'                   => array_values($lines),
                'discount_cents'          => 0,
                'eligible_subtotal_cents' => 0,
            ];
        }

        return (string) $code['kind'] === self::KIND_FIXED
            ? self::applyFixed($code, $lines, $isEligible, $eligibleSubtotal)
            : self::applyPercent($code, $lines, $isEligible, $eligibleSubtotal);
    }

    /**
     * Percent off: every unit of an eligible tier drops to the same price, so
     * each line stays a single row and no remainder can arise.
     */
    private static function applyPercent(
        array $code,
        array $lines,
        callable $isEligible,
        int $eligibleSubtotal
    ): array {
        $pct = max(0, min(100, (int) $code['percent_off']));

        $out      = [];
        $discount = 0;
        foreach ($lines as $line) {
            if (!$isEligible($line)) {
                $out[] = $line;
                continue;
            }
            $unit    = (int) $line['unit_price_cents'];
            $qty     = (int) $line['quantity'];
            $newUnit = (int) max(0, $unit - (int) round($unit * $pct / 100));

            $discount += ($unit - $newUnit) * $qty;
            $line['unit_price_cents'] = $newUnit;
            $out[] = $line;
        }

        return [
            'lines'                   => $out,
            'discount_cents'          => $discount,
            'eligible_subtotal_cents' => $eligibleSubtotal,
        ];
    }

    /**
     * Flat amount off the order, clamped to the eligible subtotal so a cart can
     * never go negative, allocated across lines by value (largest-remainder so
     * the cents sum exactly), then split within a line when the share doesn't
     * divide evenly by quantity.
     */
    private static function applyFixed(
        array $code,
        array $lines,
        callable $isEligible,
        int $eligibleSubtotal
    ): array {
        $total = min(max(0, (int) $code['amount_off_cents']), $eligibleSubtotal);
        if ($total <= 0) {
            return [
                'lines'                   => array_values($lines),
                'discount_cents'          => 0,
                'eligible_subtotal_cents' => $eligibleSubtotal,
            ];
        }

        // Floor-allocate proportionally, tracking each line's value so the
        // leftover cents can be handed out without overshooting a line.
        $alloc     = [];
        $lineValue = [];
        $allocated = 0;
        foreach ($lines as $i => $line) {
            if (!$isEligible($line)) {
                continue;
            }
            $value         = (int) $line['unit_price_cents'] * (int) $line['quantity'];
            $lineValue[$i] = $value;
            $alloc[$i]     = intdiv($total * $value, $eligibleSubtotal);
            $allocated    += $alloc[$i];
        }

        // Distribute the floor remainder, biggest lines first, never pushing a
        // line past its own value.
        $remainder = $total - $allocated;
        if ($remainder > 0) {
            $order = array_keys($lineValue);
            usort($order, static fn(int $a, int $b): int => $lineValue[$b] <=> $lineValue[$a]);
            foreach ($order as $i) {
                if ($remainder <= 0) {
                    break;
                }
                if ($alloc[$i] < $lineValue[$i]) {
                    $alloc[$i]++;
                    $remainder--;
                }
            }
        }

        $out      = [];
        $discount = 0;
        foreach ($lines as $i => $line) {
            $share = $alloc[$i] ?? 0;
            if ($share <= 0) {
                $out[] = $line;
                continue;
            }

            $unit = (int) $line['unit_price_cents'];
            $qty  = (int) $line['quantity'];

            $perUnit = intdiv($share, $qty);
            $extra   = $share % $qty;   // units that need one extra cent off

            if ($extra === 0) {
                $line['unit_price_cents'] = $unit - $perUnit;
                $out[] = $line;
                $discount += $share;
                continue;
            }

            // $extra > 0 implies $perUnit < $unit (a share of exactly the line
            // value divides evenly), so neither price below can go negative.
            $cheaper                        = $line;
            $cheaper['quantity']            = $extra;
            $cheaper['unit_price_cents']    = $unit - $perUnit - 1;
            $out[]                          = $cheaper;

            $rest                     = $line;
            $rest['quantity']         = $qty - $extra;
            $rest['unit_price_cents'] = $unit - $perUnit;
            $out[]                    = $rest;

            $discount += $share;
        }

        return [
            'lines'                   => $out,
            'discount_cents'          => $discount,
            'eligible_subtotal_cents' => $eligibleSubtotal,
        ];
    }

    /**
     * Human summary of what a code does ("30% off", "USD 10.00 off"), for the
     * admin list and the buyer-facing "code applied" confirmation.
     */
    public static function describe(array $code, string $currency = 'USD'): string
    {
        if ((string) $code['kind'] === self::KIND_FIXED) {
            return sprintf('%s %s off', $currency, number_format(((int) $code['amount_off_cents']) / 100, 2));
        }
        return ((int) $code['percent_off']) . '% off';
    }

    /** Parse a DB datetime to an epoch, tolerating NULL / empty / zero dates. */
    private static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_string($value)) {
            return null;
        }
        if (str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }
}
