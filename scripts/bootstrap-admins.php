<?php
declare(strict_types=1);

/**
 * Admin bootstrap.
 *
 * Usage:
 *   php scripts/bootstrap-admins.php                    # process the seed list below
 *   php scripts/bootstrap-admins.php "Alan" foo@bar.com # process just one admin
 *
 * For each (name, email) tuple:
 *   1. Insert the user with role = venue_admin, or upgrade an existing
 *      account to venue_admin if it already exists.
 *   2. Mint a magic-link token with a 7-day expiry (longer than the
 *      default 15-minute TTL because this is a one-shot setup email).
 *   3. Send a friendly welcome email with the login link via Mailer.
 *
 * Safe to re-run: it will refresh the token and re-send the email.
 * The link expires (or is consumed on first click) so re-runs are fine.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Mailer;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$db     = new Database();
$auth   = new Auth();
$mailer = new Mailer($root);
$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

if ($appUrl === '') {
    fwrite(STDERR, "APP_URL is not configured in the environment.\n");
    exit(1);
}

$seedAdmins = [
    ['name' => 'Tom',           'email' => 'tom@themab.org'],
    ['name' => 'Dre',           'email' => 'dre@themab.org'],
    ['name' => 'Bobby Fishkin', 'email' => 'bobby@reframeit.com'],
    ['name' => 'Erik Katz',     'email' => 'erik@erikkatz.com'],
    ['name' => 'Sasha Josephs', 'email' => 'sashajosephs@gmail.com'],
    ['name' => 'Alan',          'email' => 'alfonzo10@gmail.com'],
];

/**
 * Bootstrap a single admin: create/upgrade in DB, mint magic-link, send email.
 */
$bootstrap = function (string $name, string $email) use ($db, $auth, $mailer, $appUrl): void {
    $email = trim(strtolower($email));
    $name  = trim($name);

    $existing = $db->one('SELECT id, name, role FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($existing) {
        // Promote to venue_admin if needed; leave name alone if already set
        if ($existing['role'] !== 'venue_admin') {
            $db->run('UPDATE users SET role = ? WHERE id = ?', ['venue_admin', $existing['id']]);
            printf("• %-30s  upgraded to venue_admin (id=%d)\n", $email, $existing['id']);
        } else {
            printf("• %-30s  already venue_admin (id=%d)\n", $email, $existing['id']);
        }
    } else {
        $id = $db->insert(
            'INSERT INTO users (name, email, role) VALUES (?, ?, ?)',
            [$name, $email, 'venue_admin']
        );
        printf("• %-30s  created as venue_admin (id=%d)\n", $email, $id);
    }

    // Mint a 7-day magic-link token
    $token = $auth->generateToken(24);
    $hash  = $auth->hashToken($token);

    $db->run(
        'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))',
        [$email, $hash]
    );

    $link = "{$appUrl}/login.html?token={$token}";

    $body = "Hi {$name},\n\n"
          . "You've been set up as an administrator on the Mabuhay Gardens Backstage system.\n\n"
          . "Click the link below to log in. The link is good for 7 days — once you click it, "
          . "you'll be signed in and can set a password or add a passkey from your account menu so "
          . "you don't need a magic link next time.\n\n"
          . "  {$link}\n\n"
          . "If you weren't expecting this, you can safely ignore this email.\n\n"
          . "— Backstage\n";

    $mailer->send($email, 'Welcome to Backstage — your admin login link', $body);
    printf("  ↳ emailed login link (7-day TTL) to %s\n", $email);
};

// ---- CLI arg parsing ------------------------------------------------------
// Usage:
//   php scripts/bootstrap-admins.php                    # process the seed list
//   php scripts/bootstrap-admins.php "Alan" foo@bar.com # process one admin only
$args = array_slice($argv, 1);

if (count($args) === 0) {
    $targets = $seedAdmins;
} elseif (count($args) === 2) {
    [$argName, $argEmail] = $args;
    if (!filter_var($argEmail, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Invalid email address: {$argEmail}\n");
        exit(1);
    }
    if (trim($argName) === '') {
        fwrite(STDERR, "Name cannot be empty.\n");
        exit(1);
    }
    $targets = [['name' => $argName, 'email' => $argEmail]];
} else {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/bootstrap-admins.php                       # process the seed list\n");
    fwrite(STDERR, "  php scripts/bootstrap-admins.php \"<name>\" <email>      # process one admin only\n");
    exit(1);
}

foreach ($targets as $admin) {
    $bootstrap($admin['name'], $admin['email']);
}

echo "\nDone.\n";
