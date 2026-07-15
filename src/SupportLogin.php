<?php
declare(strict_types=1);

namespace Panic;

use Panic\Database\Connection;

/**
 * Fallback login path: lets a platform super admin (super_admin_users, in
 * the separate super-admin registry DB) log into any tenant as a full site
 * admin, for customer support. Wired into AuthEndpoint::login() — only
 * consulted AFTER normal tenant-user auth has already failed, so a real
 * tenant user's own email/password is never shadowed or short-circuited.
 * The whole path is gated by the SUPPORT_LOGIN_ENABLED env flag, checked by
 * the caller before this class is touched at all.
 *
 * The identity created on a match is a REAL row in the tenant's own `users`
 * table, found-or-created by `support_super_admin_id` (never by email — see
 * database/migrations/060_support_login.sql for why that matters), so every
 * existing permission/FK/activity-log code path in the app keeps working
 * completely unmodified: the caller just treats the returned row like any
 * other successful login and calls issueTokenPair() on it as usual.
 */
final class SupportLogin
{
    /**
     * @param array<string,mixed> $tenant The current tenant row
     *                                    (TenantContext::current()->tenant) — must
     *                                    include 'id' and 'domain'.
     * @return array<string,mixed>|null   A tenant `users` row on success, or null
     *                                    when $email/$password don't match any
     *                                    super admin, or the fleet-wide rate
     *                                    limit was hit.
     */
    public static function attempt(Database $tenantDb, string $email, string $password, array $tenant, ?string $ip): ?array
    {
        $superDb = new Database(Connection::super());
        $ip = $ip ?? 'unknown';

        // Fleet-wide throttle, checked BEFORE touching super_admin_users, so a
        // tripped limit fails closed without even attempting the password
        // check. This is IN ADDITION to the tenant-scoped limiter that already
        // wraps AuthEndpoint::login() — that one resets independently per
        // tenant domain, so on its own it would let a super-admin credential
        // be guessed 8 times per tenant, then retried on the next tenant,
        // effectively unbounded across the fleet. See
        // database/migrations/super/003_support_login_log.sql.
        if (RateLimiter::tooMany($superDb, 'support-login:ip:' . $ip, 20, 900)
            || RateLimiter::tooMany($superDb, 'support-login:email:' . $email, 8, 900)) {
            return null;
        }

        $superAdmin = $superDb->one(
            'SELECT * FROM super_admin_users WHERE email = ? LIMIT 1',
            [$email]
        );

        $matched = $superAdmin
            && $superAdmin['password_hash']
            && password_verify($password, (string) $superAdmin['password_hash']);

        self::log($superDb, $matched ? (int) $superAdmin['id'] : null, (int) $tenant['id'], $email, $ip, $matched);

        if (!$matched) {
            return null;
        }

        return self::findOrCreateTenantUser($tenantDb, (int) $superAdmin['id'], (string) $tenant['domain']);
    }

    /**
     * Reserved local-part pattern for the synthetic display email this class
     * mints below. Exposed so AuthEndpoint::requestAccess() can reject a
     * prospective tenant user pre-registering an address in this namespace
     * (defense-in-depth: identity here is keyed by support_super_admin_id,
     * never by email, so squatting on the synthetic address can't hijack a
     * support-login session — it could only block one from being createable,
     * which this rejection closes too).
     */
    public const RESERVED_EMAIL_PATTERN = '/^support-login\+\d+@/';

    private static function findOrCreateTenantUser(Database $tenantDb, int $superAdminId, string $tenantDomain): array
    {
        $existing = $tenantDb->one(
            'SELECT * FROM users WHERE support_super_admin_id = ? LIMIT 1',
            [$superAdminId]
        );
        if ($existing) {
            return $existing;
        }

        // Synthetic display email — for audit/UI readability only (also
        // satisfies the users.email UNIQUE constraint). Identity is
        // established solely via support_super_admin_id; this string is
        // never used to look the row up again.
        $syntheticEmail = sprintf('support-login+%d@%s', $superAdminId, $tenantDomain);

        // ON DUPLICATE KEY UPDATE id = id: harmless no-op that just makes a
        // concurrent double-login (e.g. two browser tabs) race-safe instead
        // of throwing on the unique support_super_admin_id key.
        $tenantDb->run(
            "INSERT INTO users (name, email, password_hash, role, access_status, is_hidden, support_super_admin_id)
             VALUES (?, ?, NULL, 'venue_admin', 'active', 1, ?)
             ON DUPLICATE KEY UPDATE id = id",
            ['Support', $syntheticEmail, $superAdminId]
        );

        // Re-select rather than trust insert-id bookkeeping, so the returned
        // row has every column issueTokenPair()/Auth::issueAccessToken() may
        // read, exactly like a normal fetch.
        $row = $tenantDb->one(
            'SELECT * FROM users WHERE support_super_admin_id = ? LIMIT 1',
            [$superAdminId]
        );
        if ($row === null) {
            // Should be unreachable — the INSERT above always leaves a
            // matching row. Fail loudly rather than return a bogus login.
            throw new \RuntimeException('support-login: failed to create tenant user row');
        }
        return $row;
    }

    private static function log(Database $superDb, ?int $superAdminId, int $tenantId, string $email, string $ip, bool $success): void
    {
        $superDb->insert(
            'INSERT INTO support_login_log (super_admin_id, tenant_id, email_used, ip, success)
             VALUES (?, ?, ?, ?, ?)',
            [$superAdminId, $tenantId, $email, $ip, $success ? 1 : 0]
        );
    }
}
