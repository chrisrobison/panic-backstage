<?php
declare(strict_types=1);

namespace Panic;

/**
 * Single source of truth for per-user email notification preferences.
 *
 * Each key maps 1:1 to a TINYINT(1) column on the `users` table (added in
 * migration 006). A value of 1 means "send me this category of mail", 0 means
 * the user has opted out. Transactional/security mail (login links, email
 * confirmation, access-approved) is intentionally NOT represented here and
 * always sends regardless of preferences.
 */
final class NotificationPreferences
{
    /** event status changes + private-event inquiries. */
    public const EVENT_UPDATES = 'notify_event_updates';

    /** contract sent / signed / voided notifications. */
    public const CONTRACTS = 'notify_contracts';

    /** new access-request alerts to admins. */
    public const ACCESS_REQUESTS = 'notify_access_requests';

    /** All preference keys (also the `users` column names). */
    public const KEYS = [
        self::EVENT_UPDATES,
        self::CONTRACTS,
        self::ACCESS_REQUESTS,
    ];

    /**
     * Whether a recipient row wants the given notification category.
     *
     * Recipients are typically `users` rows that include the preference column.
     * When the column is absent — e.g. an env-configured address such as
     * VENUE_MANAGER_EMAIL that has no user record — we default to TRUE so those
     * non-user recipients always receive mail.
     */
    public static function wants(array $recipient, string $key): bool
    {
        if (!array_key_exists($key, $recipient)) {
            return true;
        }
        return (int) $recipient[$key] === 1;
    }
}
