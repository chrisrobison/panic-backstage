<?php
declare(strict_types=1);

/**
 * One-time admin bootstrap.
 *
 *   php scripts/bootstrap-admins.php
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

$admins = [
    ['name' => 'Tom',           'email' => 'tom@themab.org'],
    ['name' => 'Dre',           'email' => 'dre@themab.org'],
    ['name' => 'Bobby Fishkin', 'email' => 'Bobby.fishkin@gmail.com'],
    ['name' => 'Erik Katz',     'email' => 'erik@erikkatz.com'],
    ['name' => 'Sasha Josephs', 'email' => 'sashajosephs@gmail.com'],
];

foreach ($admins as $admin) {
    $email = trim(strtolower($admin['email']));
    $name  = $admin['name'];

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
}

echo "\nDone.\n";
