<?php
declare(strict_types=1);

namespace Panic;

/**
 * Lightweight, DB-backed fixed-window rate limiter.
 *
 * Backs the auth endpoints that had no throttle at all (login, magic-link
 * request) against unlimited password guessing / mailbox spam. This is a
 * fixed window, not a sliding one, so a burst can straddle a window
 * boundary — that's an accepted trade-off for staying dependency-free
 * (no Redis) and a single cheap query per check; it still bounds sustained
 * abuse to a small, fixed multiple of the configured rate.
 *
 * A bucket is a caller-chosen key, typically "{action}:ip:{ip}" or
 * "{action}:email:{email}" — callers should check *both* per request so a
 * single IP can't be used to lock out one victim account by keying only on
 * IP, while a botnet spread across many IPs is still bounded per source.
 */
final class RateLimiter
{
    /**
     * Record one attempt against $bucket and report whether it should be
     * rejected because $maxAttempts was already reached within the last
     * $windowSeconds.
     */
    public static function tooMany(Database $db, string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $bucket = substr($bucket, 0, 191);

        $row = $db->one('SELECT count, window_started_at FROM rate_limits WHERE bucket = ?', [$bucket]);

        if ($row === null) {
            // First attempt in this bucket. ON DUPLICATE KEY UPDATE handles
            // the (rare) race against a concurrent first attempt landing
            // between our SELECT and this INSERT — losing that race just
            // means we don't double-count it, which is harmless here.
            $db->run(
                'INSERT INTO rate_limits (bucket, count, window_started_at) VALUES (?, 1, NOW(6))
                 ON DUPLICATE KEY UPDATE count = count',
                [$bucket]
            );
            return false;
        }

        $windowStarted = strtotime((string) $row['window_started_at']) ?: 0;
        if ($windowStarted < time() - $windowSeconds) {
            $db->run(
                'UPDATE rate_limits SET count = 1, window_started_at = NOW(6) WHERE bucket = ?',
                [$bucket]
            );
            return false;
        }

        if ((int) $row['count'] >= $maxAttempts) {
            return true;
        }

        $db->run('UPDATE rate_limits SET count = count + 1 WHERE bucket = ?', [$bucket]);
        return false;
    }
}
