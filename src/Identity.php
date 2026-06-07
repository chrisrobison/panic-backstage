<?php
declare(strict_types=1);

namespace Panic;

/**
 * Single source of truth for resolving a login email to a user account and
 * for guarding email uniqueness across the primary `users.email` column and
 * every user's verified/unverified `alt_emails` JSON array.
 *
 * alt_emails JSON shape (array of objects):
 *   [ { "email": "<trim+lowercased>", "verified_at": "<ISO8601 or null>",
 *       "added_at": "<ISO8601>" }, ... ]
 * Only entries with verified_at != null may be used to authenticate.
 *
 * IMPORTANT: resolveUserByEmail() tries the exact primary `users.email` FIRST,
 * so logging in with a user's primary email is byte-for-byte unchanged. A
 * VERIFIED alias is an ADDED fallback only — never a replacement.
 */
final class Identity
{
    /**
     * Resolve a raw login email to the owning user row, or null.
     *
     * 1. Lowercase+trim the input.
     * 2. Exact primary match on users.email (UNCHANGED current behavior).
     * 3. Else a user whose alt_emails contains the address with a non-null
     *    verified_at. Uses the multi-valued index, then re-confirms verified_at
     *    in PHP before accepting.
     *
     * Never returns two users (uniqueness guaranteed by the index + app guards).
     */
    public static function resolveUserByEmail(Database $db, string $rawEmail): ?array
    {
        $e = strtolower(trim($rawEmail));
        if ($e === '') {
            return null;
        }

        // 2. Exact primary match — identical to the legacy lookup.
        $user = $db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$e]);
        if ($user) {
            return $user;
        }

        // 3. Verified-alias fallback. The multi-valued index narrows candidates;
        //    PHP confirms the matching entry is actually verified.
        $candidate = $db->one(
            "SELECT * FROM users WHERE ? MEMBER OF (alt_emails->'$[*].email') LIMIT 1",
            [$e]
        );
        if (!$candidate) {
            return null;
        }

        foreach (self::altEmails($candidate) as $entry) {
            if (($entry['email'] ?? null) === $e && !empty($entry['verified_at'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * True if $e is already in use as ANY users.email OR appears in ANY user's
     * alt_emails (verified or not), excluding $exceptUserId. Use before
     * add-alias / verify-alias / set-primary to keep addresses globally unique.
     */
    public static function emailIsTaken(Database $db, string $e, ?int $exceptUserId): bool
    {
        $e = strtolower(trim($e));
        if ($e === '') {
            return false;
        }

        // Primary email collision on another user.
        $primary = $db->one(
            'SELECT id FROM users WHERE email = ?' . ($exceptUserId !== null ? ' AND id != ?' : '') . ' LIMIT 1',
            $exceptUserId !== null ? [$e, $exceptUserId] : [$e]
        );
        if ($primary) {
            return true;
        }

        // Alias collision on another user (any verified_at state).
        $aliasOwner = $db->one(
            "SELECT id FROM users WHERE ? MEMBER OF (alt_emails->'$[*].email')"
            . ($exceptUserId !== null ? ' AND id != ?' : '') . ' LIMIT 1',
            $exceptUserId !== null ? [$e, $exceptUserId] : [$e]
        );

        return (bool) $aliasOwner;
    }

    /**
     * Canonicalize an address for DUPLICATE DETECTION ONLY — never for login
     * matching. Lowercases; for gmail.com / googlemail.com strips dots in the
     * local part and drops any +suffix.
     */
    public static function canonical(string $rawEmail): string
    {
        $e = strtolower(trim($rawEmail));
        $at = strrpos($e, '@');
        if ($at === false) {
            return $e;
        }
        $local  = substr($e, 0, $at);
        $domain = substr($e, $at + 1);

        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $plus = strpos($local, '+');
            if ($plus !== false) {
                $local = substr($local, 0, $plus);
            }
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }

    /**
     * Decode a user row's alt_emails JSON into a normalized array of entries.
     * Always returns a list of associative arrays; tolerant of null/garbage.
     *
     * @return array<int,array{email?:string,verified_at?:?string,added_at?:?string}>
     */
    public static function altEmails(array $userRow): array
    {
        $raw = $userRow['alt_emails'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['email'])) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
