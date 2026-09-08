<?php
declare(strict_types=1);

/**
 * Generate a magic-link login URL for an EXISTING user and print it to
 * stdout — for out-of-band delivery (e.g. SMS) when the user can't receive
 * email. Unlike the normal /api/auth/magic-link flow this does NOT send mail,
 * and unlike bootstrap-admins.php it does NOT change the user's role.
 *
 * Single-tenant usage — existing behaviour unchanged:
 *   php scripts/login-link.php <email> [ttl-hours]
 *
 * Multi-tenant SaaS usage — mint the link against a tenant DB instead of the
 * single-tenant DB (requires SUPER_DB_NAME to be configured):
 *   php scripts/login-link.php <email> --tenant=<domain-or-slug> [ttl-hours]
 *
 *   <email>       Must already exist in the target users table (the
 *                 single-tenant DB, or the resolved tenant DB when
 *                 --tenant is given). The script refuses to mint a link
 *                 for an unknown address so a typo can't silently create
 *                 a `viewer` account on first verify.
 *   --tenant=<x>  Tenant hostname (e.g. oec.panicbackstage.com) or slug
 *                 (e.g. oec). Resolved against the super registry
 *                 (tenant_domains/tenants); the minted link uses that
 *                 tenant's domain — the one matched, or the tenant's
 *                 primary domain when resolved by slug.
 *   [ttl-hours]   Optional link lifetime in hours. Default 24, max 168 (7d).
 *
 * The link is single-use and expires after the TTL. Anyone holding it can
 * sign in AS this user, so only send it over a channel you trust and that
 * you've confirmed belongs to the account owner.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Database\Connection;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$args  = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn ($a) => str_starts_with($a, '--')));
$pos   = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));

$tenantArg = null;
foreach ($flags as $flag) {
    if (str_starts_with($flag, '--tenant=')) {
        $tenantArg = substr($flag, strlen('--tenant='));
    } else {
        fwrite(STDERR, "Unknown flag: {$flag}\n");
        exit(1);
    }
}

if (count($pos) < 1 || count($pos) > 2) {
    fwrite(STDERR, "Usage: php scripts/login-link.php <email> [ttl-hours] [--tenant=<domain-or-slug>]\n");
    exit(1);
}

$email = trim(strtolower($pos[0]));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address: {$pos[0]}\n");
    exit(1);
}

$ttlHours = 24;
if (isset($pos[1])) {
    $ttlHours = (int) $pos[1];
    if ($ttlHours < 1 || $ttlHours > 168) {
        fwrite(STDERR, "ttl-hours must be between 1 and 168 (7 days).\n");
        exit(1);
    }
}

$auth = new Auth();

// ── Multi-tenant SaaS path ──────────────────────────────────────────────────

if ($tenantArg !== null) {
    $superDbName = (string) (getenv('SUPER_DB_NAME') ?: '');
    if ($superDbName === '') {
        fwrite(STDERR, "--tenant was given but SUPER_DB_NAME is not configured (multi-tenant mode is off).\n");
        exit(1);
    }

    $tenant = resolveTenant($tenantArg);
    if (!$tenant) {
        fwrite(STDERR, "No active tenant matches --tenant={$tenantArg}\n");
        exit(1);
    }

    $tenantDb = Connection::tenant((string) $tenant['database_name']);

    $stmt = $tenantDb->prepare('SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "No user found with email {$email} in tenant '{$tenant['slug']}'. Refusing to mint a link for an unknown address.\n");
        exit(1);
    }

    $token = $auth->generateToken(24);
    $hash  = $auth->hashToken($token);

    $insert = $tenantDb->prepare(
        'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
    );
    $insert->execute([$email, $hash, $ttlHours]);

    $link = "https://{$tenant['domain']}/login.html?token={$token}";

    printf("Tenant:  %s (%s)\n", $tenant['name'], $tenant['slug']);
    printf("User:    %s (id=%d, role=%s)\n", $user['email'], $user['id'], $user['role']);
    printf("Expires: in %d hour%s, single use\n", $ttlHours, $ttlHours === 1 ? '' : 's');
    printf("Link:    %s\n", $link);
    exit(0);
}

// ── Single-tenant path (existing behaviour, unchanged) ─────────────────────

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
if ($appUrl === '') {
    fwrite(STDERR, "APP_URL is not configured in the environment.\n");
    exit(1);
}

$db = new Database();

$user = $db->one('SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1', [$email]);
if (!$user) {
    fwrite(STDERR, "No user found with email {$email}. Refusing to mint a link for an unknown address.\n");
    exit(1);
}

$token = $auth->generateToken(24);
$hash  = $auth->hashToken($token);

$db->run(
    'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
    [$email, $hash, $ttlHours]
);

$link = "{$appUrl}/login.html?token={$token}";

printf("User:    %s (id=%d, role=%s)\n", $user['email'], $user['id'], $user['role']);
printf("Expires: in %d hour%s, single use\n", $ttlHours, $ttlHours === 1 ? '' : 's');
printf("Link:    %s\n", $link);

/**
 * Resolve --tenant=<arg> against the super registry. Tries an exact
 * tenant_domains.domain match first (so a full hostname always works);
 * falls back to matching tenants.slug and using that tenant's primary
 * domain for the link.
 *
 * @return array<string,mixed>|null
 */
function resolveTenant(string $arg): ?array
{
    $arg = strtolower($arg);

    $stmt = Connection::super()->prepare(
        'SELECT t.*, d.domain
         FROM tenant_domains d
         JOIN tenants t ON t.id = d.tenant_id
         WHERE d.domain = ? AND t.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$arg]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = Connection::super()->prepare(
        'SELECT t.*, d.domain
         FROM tenants t
         JOIN tenant_domains d ON d.tenant_id = t.id
         WHERE t.slug = ? AND t.status = "active"
         ORDER BY d.is_primary DESC, d.id ASC
         LIMIT 1'
    );
    $stmt->execute([$arg]);
    $row = $stmt->fetch();
    return $row ?: null;
}
