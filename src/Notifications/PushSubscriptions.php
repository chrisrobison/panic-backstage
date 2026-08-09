<?php
declare(strict_types=1);

namespace Panic\Notifications;

use Panic\Database;

/**
 * Data access for `push_subscriptions` — one row per (user, device).
 *
 * Every read here is scoped to a user id supplied by the SERVER (from the
 * authenticated session or from a notifier's recipient list), never by the
 * browser. The registration token itself is write-only from the API's point
 * of view: it goes in on registration and comes back out only inside this
 * process on the way to FCM. listForUser() exists specifically so the
 * Preferences UI can show a user their own devices without the token ever
 * being serialized into a response.
 */
final class PushSubscriptions
{
    /** Columns safe to return to the owning user — note the absent `token`. */
    private const PUBLIC_COLUMNS = 'id, device_label, platform, enabled, created_at, last_seen_at, last_success_at';

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Register or refresh a device, keyed on the token's hash.
     *
     * Idempotent by construction: re-registering the same token (which the
     * browser does routinely — tokens are refreshed, and the client
     * re-registers on every enable) updates the existing row instead of
     * creating a second one.
     *
     * If the token already belongs to a DIFFERENT user, ownership moves to
     * the current user rather than erroring. That is the shared-device case
     * (one browser profile, colleague signs in after you) and moving the row
     * is what keeps the previous user from receiving pushes on a device that
     * is no longer theirs.
     *
     * @return int The subscription id.
     */
    public static function upsert(
        Database $db,
        int $userId,
        string $token,
        ?string $deviceLabel,
        ?string $platform,
        ?string $userAgent
    ): int {
        $db->run(
            'INSERT INTO push_subscriptions
                (user_id, token, token_hash, enabled, device_label, platform, user_agent, last_seen_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                id           = LAST_INSERT_ID(id),
                user_id      = VALUES(user_id),
                token        = VALUES(token),
                enabled      = 1,
                device_label = COALESCE(VALUES(device_label), device_label),
                platform     = COALESCE(VALUES(platform), platform),
                user_agent   = VALUES(user_agent),
                last_seen_at = NOW(),
                last_error   = NULL',
            [$userId, $token, self::hashToken($token), $deviceLabel, $platform, $userAgent]
        );

        return (int) $db->pdo()->lastInsertId();
    }

    /**
     * This user's devices, newest first. Never includes the token.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForUser(Database $db, int $userId): array
    {
        return $db->all(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM push_subscriptions
             WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC',
            [$userId]
        );
    }

    /** One of this user's devices by token, for "is THIS browser registered?". */
    public static function findByToken(Database $db, int $userId, string $token): ?array
    {
        return $db->one(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM push_subscriptions
             WHERE user_id = ? AND token_hash = ? LIMIT 1',
            [$userId, self::hashToken($token)]
        );
    }

    /**
     * Delete one of the CURRENT user's registrations.
     *
     * The user_id predicate is the whole security model here: an id belonging
     * to somebody else matches zero rows and reports not-found, so the
     * endpoint can never be used to enumerate or revoke another user's
     * devices.
     *
     * @return bool True if a row was actually removed.
     */
    public static function deleteForUser(Database $db, int $userId, int $id): bool
    {
        return $db->run(
            'DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /** Delete by token — how a browser unregisters itself without knowing its row id. */
    public static function deleteByTokenForUser(Database $db, int $userId, string $token): bool
    {
        return $db->run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND token_hash = ?',
            [$userId, self::hashToken($token)]
        ) > 0;
    }

    /**
     * Enabled registrations for a set of users, for delivery.
     *
     * @param list<int> $userIds
     * @return list<array{id: int, user_id: int, token: string}>
     */
    public static function enabledForUsers(Database $db, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $db->all(
            "SELECT id, user_id, token FROM push_subscriptions
             WHERE enabled = 1 AND user_id IN ({$placeholders})
             ORDER BY id",
            $userIds
        );

        return array_map(
            static fn (array $row): array => [
                'id'      => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'token'   => (string) $row['token'],
            ],
            $rows
        );
    }

    public static function markSuccess(Database $db, int $id): void
    {
        $db->run(
            'UPDATE push_subscriptions SET last_success_at = NOW(), last_error = NULL WHERE id = ?',
            [$id]
        );
    }

    /**
     * Retire a registration FCM told us is dead.
     *
     * Disabled rather than deleted so the user can see in Preferences that
     * the device stopped working (and simply re-enable it) instead of having
     * it silently vanish. `$reason` is an FCM status string such as
     * UNREGISTERED — never a token.
     */
    public static function disable(Database $db, int $id, string $reason): void
    {
        $db->run(
            'UPDATE push_subscriptions SET enabled = 0, last_error = ? WHERE id = ?',
            [mb_substr($reason, 0, 255), $id]
        );
    }
}
