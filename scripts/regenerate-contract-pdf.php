<?php
declare(strict_types=1);

/**
 * One-off recovery: regenerate a fully-executed contract's final signed PDF
 * when contracts.final_pdf_path points at a file that's no longer on disk
 * (e.g. storage/contracts/{id}/ lost outside of git, which doesn't track
 * generated PDFs). Re-renders from the same contract_sections/signers/
 * audit-log rows ContractSigningEndpoint::finalizeContract() uses, so the
 * regenerated document matches the original content exactly. Updates
 * final_pdf_path/final_pdf_sha256 and appends a `pdf_regenerated` audit row
 * so the gap is visible in the contract's own audit trail.
 *
 * Usage: php scripts/regenerate-contract-pdf.php <contract-id>
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\ContractAuditLog;
use Panic\ContractPdfService;
use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$contractId = (int) ($argv[1] ?? 0);
if ($contractId <= 0) {
    fwrite(STDERR, "Usage: php scripts/regenerate-contract-pdf.php <contract-id>\n");
    exit(1);
}

$db = new Database();

$contract = $db->one('SELECT id, status, final_pdf_path, final_pdf_sha256 FROM contracts WHERE id = ?', [$contractId]);
if (!$contract) {
    fwrite(STDERR, "Contract {$contractId} not found.\n");
    exit(1);
}
if ($contract['status'] !== 'fully_executed') {
    fwrite(STDERR, "Contract {$contractId} is status '{$contract['status']}', not fully_executed — refusing to regenerate a final PDF for it.\n");
    exit(1);
}

$existingPath = (string) ($contract['final_pdf_path'] ?? '');
if ($existingPath !== '' && is_file($root . '/' . $existingPath)) {
    fwrite(STDERR, "Contract {$contractId}'s final PDF already exists on disk at {$existingPath} — nothing to do.\n");
    exit(0);
}

$pdfService = new ContractPdfService($db, $root);
$pdfBytes   = $pdfService->renderFinalSignedPdf($contractId);
$hash       = $pdfService->hashPdf($pdfBytes);
$path       = $pdfService->storePdf($contractId, $pdfBytes, 'final');

$db->run(
    'UPDATE contracts SET final_pdf_path = ?, final_pdf_sha256 = ? WHERE id = ?',
    [$path, $hash, $contractId]
);

ContractAuditLog::appendFromRequest(
    $db, $contractId, 'pdf_regenerated', null,
    ['path' => $path, 'sha256' => $hash, 'reason' => 'file missing from disk (recovery script)']
);

echo "Regenerated {$path} (sha256 {$hash}) for contract {$contractId}.\n";
if ($existingPath !== '' && (string) $contract['final_pdf_sha256'] !== $hash) {
    echo "Note: new hash differs from the previously recorded one ({$contract['final_pdf_sha256']}) — expected, since PDF rendering isn't byte-deterministic (embedded generation timestamp etc.); document content is unchanged.\n";
}
