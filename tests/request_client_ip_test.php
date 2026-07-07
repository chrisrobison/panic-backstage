<?php
/**
 * Tests for Request::clientIp() — the TRUST_PROXY-gated X-Forwarded-For
 * handling added when we found ContractSigningEndpoint trusting XFF
 * unconditionally (an attacker-controlled header) for the e-signature
 * audit-log IP. See Request::clientIp() and TenantContext::host(), which
 * established the TRUST_PROXY convention this follows.
 *
 * Hermetic — mutates $_SERVER / the process env directly and restores both
 * afterward so this can run alongside other tests in the same process.
 *
 * Run with: php tests/request_client_ip_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Request;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

// Snapshot so we can restore exactly, regardless of what's already present.
$savedServer = $_SERVER;
$savedTrustProxy = getenv('TRUST_PROXY');

function resetGlobals(): void {
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    putenv('TRUST_PROXY');
}

echo "\n=== Request::clientIp() (TRUST_PROXY gating) ===\n\n";

// ── 1. Default (TRUST_PROXY unset): XFF is attacker-controlled, must be ignored ──
resetGlobals();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
ok(Request::clientIp() === '203.0.113.9', 'TRUST_PROXY unset: REMOTE_ADDR wins, XFF ignored');

// ── 2. TRUST_PROXY=false explicitly: same as unset ──────────────────────────
resetGlobals();
putenv('TRUST_PROXY=false');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
ok(Request::clientIp() === '203.0.113.9', 'TRUST_PROXY=false: REMOTE_ADDR wins, XFF ignored');

// ── 3. TRUST_PROXY=true: leftmost XFF entry is honoured, trimmed ────────────
resetGlobals();
putenv('TRUST_PROXY=true');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // the proxy's own address
$_SERVER['HTTP_X_FORWARDED_FOR'] = ' 1.2.3.4 , 5.6.7.8';
ok(Request::clientIp() === '1.2.3.4', 'TRUST_PROXY=true: first XFF entry used, whitespace trimmed');

// ── 4. TRUST_PROXY=true but no XFF present: falls back to REMOTE_ADDR ───────
resetGlobals();
putenv('TRUST_PROXY=true');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
ok(Request::clientIp() === '10.0.0.1', 'TRUST_PROXY=true with no XFF header: falls back to REMOTE_ADDR');

// ── 5. Neither present: null, not a fatal ────────────────────────────────────
resetGlobals();
unset($_SERVER['REMOTE_ADDR']);
ok(Request::clientIp() === null, 'no REMOTE_ADDR and no trusted XFF: returns null');

// ── Restore ───────────────────────────────────────────────────────────────
$_SERVER = $savedServer;
if ($savedTrustProxy === false) {
    putenv('TRUST_PROXY');
} else {
    putenv('TRUST_PROXY=' . $savedTrustProxy);
}

echo "\nRequest::clientIp(): $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
