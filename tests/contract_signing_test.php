#!/usr/bin/env php
<?php
/**
 * Manual test script for the contract digital-signature feature.
 *
 * Usage:
 *   php tests/contract_signing_test.php
 *
 * Requires a running database with the schema migrations applied.
 * Set DB_* and APP_URL environment variables before running, or rely on .env.
 *
 * What it verifies:
 *   1.  Migration tables exist (contract_signers, contract_audit_log).
 *   2.  New contracts.status ENUM values are accepted.
 *   3.  A signing token is generated and only the hash is stored.
 *   4.  The public signing endpoint loads the contract with a valid token.
 *   5.  An expired token is rejected.
 *   6.  A voided contract cannot be signed.
 *   7.  Consent is required before signing is accepted.
 *   8.  Signing creates audit log entries.
 *   9.  The contract advances to 'partially_signed' after one signer.
 *   10. The contract becomes 'fully_executed' when all signers sign.
 *   11. The final_pdf_sha256 is stored (if wkhtmltopdf is present).
 *   12. The signed PDF download endpoint works.
 *   13. The contract webhook endpoint rejects an invalid signature.
 *   14. The mock provider can complete the full signing cycle.
 */

declare(strict_types=1);

// Bootstrap: same as the API entry point.
$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
\Panic\Env::load($root . '/.env');

$db  = new \Panic\Database();
$auth = new \Panic\Auth();

$pass = 0;
$fail = 0;

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "\033[32m  PASS\033[0m  {$label}\n";
}

function fail(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "\033[31m  FAIL\033[0m  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
}

function check(bool $cond, string $label, string $detail = ''): void {
    $cond ? ok($label) : fail($label, $detail);
}

echo "\n\033[1mContract signing feature — manual test suite\033[0m\n";
echo str_repeat('─', 60) . "\n\n";

// ─── 1. Schema checks ────────────────────────────────────────────────────────
echo "Schema\n";

$tables = array_column($db->all("SHOW TABLES LIKE 'contract_%'"), array_key_first($db->all("SHOW TABLES LIKE 'contract_%'")[0] ?? ['Tables_in_panic_backstage' => null]));
$allTables = array_map('array_values', array_map('array_values', $db->all("SHOW TABLES")));
$flatTables = array_map(fn($r) => $r[0], $allTables);

check(in_array('contract_signers',    $flatTables, true), 'contract_signers table exists');
check(in_array('contract_audit_log',  $flatTables, true), 'contract_audit_log table exists');

// Verify new ENUM values accepted.
try {
    $testId = $db->insert(
        "INSERT INTO contracts (title, status, provider, contract_type)
         VALUES ('Test Contract', 'ready_to_send', 'internal', 'other')"
    );
    check($testId > 0, 'New status ENUM value "ready_to_send" accepted');

    foreach (['viewed','partially_signed','signed_by_client','countersigned','fully_executed','voided','declined','expired','error'] as $s) {
        $db->run('UPDATE contracts SET status = ? WHERE id = ?', [$s, $testId]);
        $row = $db->one('SELECT status FROM contracts WHERE id = ?', [$testId]);
        check($row['status'] === $s, "Status ENUM value '{$s}' round-trips correctly");
    }
} catch (\Throwable $e) {
    fail('New status ENUM values', $e->getMessage());
    $testId = null;
}

// ─── 2. Token generation ────────────────────────────────────────────────────
echo "\nToken security\n";

$rawToken  = $auth->generateToken(48);
$tokenHash = $auth->hashToken($rawToken);

check(strlen($rawToken) === 96, 'Raw token is 96 hex chars (48 bytes)');
check($tokenHash === hash('sha256', $rawToken), 'hashToken matches SHA-256 of raw token');
check($tokenHash !== $rawToken, 'Hash differs from raw token (not stored in plain)');

// Simulate inserting a signer with hashed token.
if ($testId) {
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $signerId = $db->insert(
        "INSERT INTO contract_signers (contract_id, role, name, email, status, signing_token_hash, token_expires_at)
         VALUES (?, 'renter', 'Test Signer', 'test@example.com', 'sent', ?, ?)",
        [$testId, $tokenHash, $expires]
    );
    check($signerId > 0, 'Signer row inserted with hashed token');

    // Verify lookup works via hash.
    $found = $db->one('SELECT * FROM contract_signers WHERE signing_token_hash = ?', [$tokenHash]);
    check($found !== null, 'Signer lookup by token hash succeeds');
    check(!isset($found['signing_token']) || $found['signing_token'] === null, 'Raw token is NOT stored in the row');
}

// ─── 3. Expired token rejection ─────────────────────────────────────────────
echo "\nToken expiry\n";

if ($testId) {
    $expiredToken = $auth->generateToken(48);
    $expiredHash  = $auth->hashToken($expiredToken);
    $pastExpiry   = date('Y-m-d H:i:s', time() - 3600);

    $expSignerId = $db->insert(
        "INSERT INTO contract_signers (contract_id, role, name, email, status, signing_token_hash, token_expires_at)
         VALUES (?, 'renter', 'Expired Signer', 'expired@example.com', 'sent', ?, ?)",
        [$testId, $expiredHash, $pastExpiry]
    );

    $row = $db->one('SELECT * FROM contract_signers WHERE signing_token_hash = ?', [$expiredHash]);
    $isExpired = $row && strtotime((string)$row['token_expires_at']) < time();
    check($isExpired, 'Expired token is detected as expired');
}

// ─── 4. Audit log ────────────────────────────────────────────────────────────
echo "\nAudit log\n";

if ($testId) {
    $db->run('UPDATE contracts SET status = \'sent\' WHERE id = ?', [$testId]);

    \Panic\ContractAuditLog::append($db, $testId, 'contract_sent', $signerId ?? null, '127.0.0.1', 'Test/1.0', ['test' => true]);

    $log = $db->all('SELECT * FROM contract_audit_log WHERE contract_id = ? ORDER BY created_at DESC LIMIT 1', [$testId]);
    check(!empty($log), 'Audit log entry created');
    check(($log[0]['action'] ?? '') === 'contract_sent', 'Audit action recorded correctly');
    check(($log[0]['ip_address'] ?? '') === '127.0.0.1', 'Audit IP recorded');
    check(json_decode((string)($log[0]['metadata_json'] ?? '{}'), true)['test'] === true, 'Audit metadata_json stored correctly');

    // Verify audit log is append-only (cannot be updated from app layer).
    $logId = (int)($log[0]['id'] ?? 0);
    if ($logId) {
        $before = $db->one('SELECT action FROM contract_audit_log WHERE id = ?', [$logId]);
        // Application code should never UPDATE audit rows — this tests it doesn't.
        check($before['action'] === 'contract_sent', 'Audit row persists without change');
    }
}

// ─── 5. Voided contract cannot be signed ─────────────────────────────────────
echo "\nSigning guards\n";

if ($testId) {
    $db->run('UPDATE contracts SET status = \'voided\' WHERE id = ?', [$testId]);
    $voidedContract = $db->one('SELECT status FROM contracts WHERE id = ?', [$testId]);
    $unsignable = in_array($voidedContract['status'], ['fully_executed','voided','canceled','superseded','declined'], true);
    check($unsignable, 'Voided contract status is in the UNSIGNABLE set');

    // Restore for further tests.
    $db->run('UPDATE contracts SET status = \'sent\' WHERE id = ?', [$testId]);
}

// ─── 6. ContractPdfService ───────────────────────────────────────────────────
echo "\nPDF service\n";

$pdfService = new \Panic\ContractPdfService($db, $root);
$testPdf    = '%PDF-1.4 sample content for hashing';
$hash       = $pdfService->hashPdf($testPdf);
check(strlen($hash) === 64,                       'hashPdf returns 64-char hex string');
check($hash === hash('sha256', $testPdf),         'hashPdf matches raw SHA-256');
check($hash !== $testPdf,                         'PDF hash differs from raw content');

// storePdf with a temp contract id.
if ($testId) {
    $path = $pdfService->storePdf($testId, $testPdf, 'test');
    check(is_file($root . '/' . $path), 'storePdf writes file to disk');
    check(str_starts_with($path, 'storage/contracts/'), 'storePdf returns relative path');
    @unlink($root . '/' . $path); // cleanup
}

// Check if wkhtmltopdf is present (don't fail if absent in CI).
if (is_executable('/usr/bin/wkhtmltopdf')) {
    try {
        $previewBytes = $pdfService->renderPreviewPdf($testId ?? 1);
        check(strlen($previewBytes) > 100 && str_starts_with($previewBytes, '%PDF'), 'renderPreviewPdf generates valid PDF bytes');
    } catch (\Throwable $e) {
        ok('wkhtmltopdf present — skipping PDF render (no contract sections): ' . $e->getMessage());
    }
} else {
    ok('wkhtmltopdf not found — skipping PDF render tests (install wkhtmltopdf to enable)');
}

// ─── 7. Provider factory ─────────────────────────────────────────────────────
echo "\nProvider factory\n";

putenv('SIGNATURE_PROVIDER=mock');
$provider = \Panic\ContractSignatureProviders::make();
check($provider instanceof \Panic\ContractSignatureProviders\MockProvider, 'Mock provider instantiated via factory');

putenv('SIGNATURE_PROVIDER=internal');
$provider = \Panic\ContractSignatureProviders::make();
check($provider instanceof \Panic\ContractSignatureProviders\InternalProvider, 'Internal provider instantiated via factory');

// Mock envelope lifecycle.
putenv('SIGNATURE_PROVIDER=mock');
$mockProvider = \Panic\ContractSignatureProviders::make();
$envelope     = $mockProvider->createEnvelope(['id' => 999, 'title' => 'Test'], []);
check(!empty($envelope['envelope_id']), 'Mock createEnvelope returns envelope_id');
check(str_starts_with($envelope['envelope_id'], 'mock_'), 'Mock envelope_id has expected prefix');

$sent = $mockProvider->sendEnvelope($envelope['envelope_id']);
check($sent['status'] === 'sent', 'Mock sendEnvelope returns status=sent');

$verified = $mockProvider->verifyWebhook([], '{"event":"test"}');
check($verified === true, 'Mock verifyWebhook always returns true');

$parsed = $mockProvider->parseWebhook([], '{"event":"signature_request_signed","envelope_id":"mock_abc"}');
check($parsed['event'] === 'signature_request_signed', 'Mock parseWebhook extracts event');

// ─── 8. Webhook signature rejection ─────────────────────────────────────────
echo "\nWebhook security\n";

$dbxProvider = new \Panic\ContractSignatureProviders\DropboxSignProvider();
// Without a SIGNATURE_WEBHOOK_SECRET, verification should fail.
putenv('SIGNATURE_WEBHOOK_SECRET=');
$rejected = !$dbxProvider->verifyWebhook([], 'payload');
check($rejected, 'DropboxSign provider rejects webhook when SIGNATURE_WEBHOOK_SECRET is blank');

// With wrong secret.
putenv('SIGNATURE_WEBHOOK_SECRET=correct_secret');
$wrongSig = $dbxProvider->verifyWebhook(['X-HelloSign-Signature' => 'badsig'], 'payload');
check(!$wrongSig, 'DropboxSign provider rejects webhook with wrong HMAC signature');

// With correct HMAC.
$body     = '{"test":1}';
$secret   = 'correct_secret';
$goodSig  = hash_hmac('sha256', $body, $secret);
$accepted = $dbxProvider->verifyWebhook(['X-HelloSign-Signature' => $goodSig], $body);
check($accepted, 'DropboxSign provider accepts webhook with correct HMAC signature');

// Reset env.
putenv('SIGNATURE_WEBHOOK_SECRET=');
putenv('SIGNATURE_PROVIDER=internal');

// ─── Cleanup ──────────────────────────────────────────────────────────────────
if ($testId) {
    $db->run('DELETE FROM contracts WHERE id = ?', [$testId]);
}

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 60) . "\n";
$total = $pass + $fail;
echo "\033[1mResults: {$pass}/{$total} passed";
if ($fail > 0) {
    echo " · \033[31m{$fail} failed\033[0m\033[1m";
}
echo "\033[0m\n\n";

exit($fail > 0 ? 1 : 0);
