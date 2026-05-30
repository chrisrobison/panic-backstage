<?php
declare(strict_types=1);

/**
 * Generate a magic-link login URL for an EXISTING user and print it to
 * stdout — for out-of-band delivery (e.g. SMS) when the user can't receive
 * email. Unlike the normal /api/auth/magic-link flow this does NOT send mail,
 * and unlike bootstrap-admins.php it does NOT change the user's role.
 *
 * Usage:
 *   php scripts/login-link.php <email> [ttl-hours]
 *
 *   <email>      Must already exist in the users table. The script refuses
 *                to mint a link for an unknown address so a typo can't
 *                silently create a `viewer` account on first verify.
 *   [ttl-hours]  Optional link lifetime in hours. Default 24, max 168 (7d).
 *
 * The link is single-use and expires after the TTL. Anyone holding it can
 * sign in AS this user, so only send it over a channel you trust and that
 * you've confirmed belongs to the account owner.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
if ($appUrl === '') {
    fwrite(STDERR, "APP_URL is not configured in the environment.\n");
    exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 1 || count($args) > 2) {
    fwrite(STDERR, "Usage: php scripts/login-link.php <email> [ttl-hours]\n");
    exit(1);
}

$email = trim(strtolower($args[0]));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address: {$args[0]}\n");
    exit(1);
}

$ttlHours = 24;
if (isset($args[1])) {
    $ttlHours = (int) $args[1];
    if ($ttlHours < 1 || $ttlHours > 168) {
        fwrite(STDERR, "ttl-hours must be between 1 and 168 (7 days).\n");
        exit(1);
    }
}

$db   = new Database();
$auth = new Auth();

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
