<?php
/**
 * Tests for the JWT token_version revocation claim added alongside the
 * PosWebhook/rate-limiting security fixes (see Auth::issueAccessToken(),
 * Auth::authenticate(), Kernel::handle()).
 *
 * Hermetic — no DB, no network. Exercises Auth's public surface only
 * (issueAccessToken() + authenticate()), not the private JWT internals.
 *
 * Run with: php tests/auth_token_version_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Request;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

putenv('JWT_SECRET=test-secret-' . bin2hex(random_bytes(16)));

function authFor(string $token): ?array {
    $auth = new Auth();
    $req  = new Request('GET', '/', [], [], [], ['Authorization' => 'Bearer ' . $token]);
    $auth->authenticate($req);
    return $auth->user();
}

echo "\n=== Auth token_version (tv claim) ===\n\n";

// ── 1. tv claim round-trips through issue -> authenticate ───────────────────
$auth  = new Auth();
$token = $auth->issueAccessToken([
    'id' => 7, 'name' => 'Test User', 'email' => 't@example.com',
    'role' => 'viewer', 'token_version' => 3,
]);
$user = authFor($token);
ok($user !== null, 'token with tv=3 authenticates');
ok(($user['token_version'] ?? null) === 3, 'authenticated user carries tv=3 from the token');
ok($user['id'] === 7 && $user['role'] === 'viewer', 'sub/role still decode correctly alongside tv');

// ── 2. Missing token_version on the issuing $user defaults to 0 ─────────────
$token2 = $auth->issueAccessToken([
    'id' => 8, 'name' => 'No TV', 'email' => 'notv@example.com', 'role' => 'viewer',
]);
$user2 = authFor($token2);
ok($user2 !== null, 'token issued without token_version still authenticates');
ok(($user2['token_version'] ?? null) === 0, 'tv defaults to 0 when the issuing user array omits it');

// ── 3. clearUser() discards the populated user (Kernel's revocation path) ───
$auth3 = new Auth();
$req3  = new Request('GET', '/', [], [], [], ['Authorization' => 'Bearer ' . $token]);
$auth3->authenticate($req3);
ok($auth3->user() !== null, 'sanity: user populated before clearUser()');
$auth3->clearUser();
ok($auth3->user() === null, 'clearUser() drops the authenticated user (used on tv mismatch)');

// ── 4. A tampered signature still fails closed regardless of tv ─────────────
$tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
ok(authFor($tampered) === null, 'tampered signature is rejected (tv claim does not weaken sig check)');

echo "\nAuth token_version: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
