<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * Single source of truth for per-user PUSH notification preferences.
 *
 * Deliberately a sibling of — not an extension of — \Panic\NotificationPreferences,
 * which owns the `notify_*` EMAIL columns. The two sets are chosen
 * independently on purpose: agreeing to receive email about event updates is
 * not consent to be interrupted on a phone. Each key maps 1:1 to a TINYINT(1)
 * column on `users` (migration 094).
 *
 * A preference only gates whether a *category* may be pushed. Nothing is ever
 * delivered until the user has also explicitly registered a device from
 * Preferences (see push_subscriptions), which is its own permission-gated
 * opt-in — so "default 1" here is not an opt-out-style default.
 */
final class PushPreferences
{
    /** A new booking inquiry needs attention in the Booking Inbox. */
    public const BOOKING_UPDATES = 'push_booking_updates';

    /** A contract was signed or declined. */
    public const CONTRACTS = 'push_contracts';

    /** An inquiry or task was assigned to me by somebody else. */
    public const TASK_ASSIGNMENTS = 'push_task_assignments';

    /** Day-of-show schedule changes and blockers (reserved for a later release). */
    public const DAY_OF_SHOW = 'push_day_of_show';

    /** All preference keys (also the `users` column names). */
    public const KEYS = [
        self::BOOKING_UPDATES,
        self::CONTRACTS,
        self::TASK_ASSIGNMENTS,
        self::DAY_OF_SHOW,
    ];

    public static function isKey(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * Whether a `users` row wants the given push category.
     *
     * Unlike the email equivalent, a MISSING column defaults to FALSE. Email
     * has non-user recipients (env-configured addresses with no `users` row)
     * that must still be mailed; push has no such thing — every registration
     * belongs to a real user row — so an absent/unreadable preference means
     * "we do not know that they opted in", and we stay quiet.
     *
     * @param array<string,mixed> $recipient
     */
    public static function wants(array $recipient, string $key): bool
    {
        if (!array_key_exists($key, $recipient)) {
            return false;
        }
        return (int) $recipient[$key] === 1;
    }
}
