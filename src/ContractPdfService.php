<?php
declare(strict_types=1);

namespace Panic;

/**
 * PDF generation for contracts.
 *
 * Wraps the wkhtmltopdf binary (same binary used by the existing Contracts
 * endpoint) and adds:
 *   renderPreviewPdf()    — current contract state, no signatures
 *   renderFinalSignedPdf() — contract body + signature blocks + audit certificate
 *   hashPdf()             — SHA-256 of PDF bytes
 *   storePdf()            — saves to storage/contracts/{id}/{suffix}.pdf
 */
final class ContractPdfService
{
    private const WKHTMLTOPDF = '/usr/bin/wkhtmltopdf';

    private const PDF_CSS = <<<'CSS'
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #111; background: #fff;
               margin: 0; padding: 0; line-height: 1.6; }
        .contract-doc { max-width: none; margin: 0; }
        .contract-doc-head h1 { font-size: 22px; margin: 0 0 4px; }
        .contract-doc-sub { color: #555; margin: 0 0 20px; font-style: italic; }
        .contract-summary { width: 100%; border-collapse: collapse; margin: 0 0 24px; font-size: 13px; }
        .contract-summary caption { text-align: left; font-weight: bold; padding-bottom: 6px; }
        .contract-summary th { text-align: left; width: 42%; padding: 4px 8px; color: #444;
                               font-weight: normal; border-bottom: 1px solid #ddd; }
        .contract-summary td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
        .contract-section { margin: 0 0 20px; }
        .contract-section h2 { font-size: 15px; margin: 0 0 6px; }
        .contract-section-body p { margin: 0 0 8px; text-align: justify; }
        .contract-token-missing { background: #ffe2a8; color: #7a4b00; padding: 0 4px;
                                  border-radius: 3px; font-style: italic; }
        /* Signature blocks */
        .sig-page { margin-top: 48px; border-top: 3px solid #111; padding-top: 24px; }
        .sig-page h2 { font-size: 16px; margin: 0 0 24px; text-transform: uppercase;
                       letter-spacing: .06em; }
        .sig-block { margin: 0 0 40px; }
        .sig-block h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em;
                        color: #555; margin: 0 0 8px; }
        .sig-row { display: flex; gap: 48px; align-items: flex-end; }
        .sig-col { flex: 1; }
        .sig-line { display: block; border-bottom: 1px solid #333; min-height: 48px;
                    font-size: 22px; font-style: italic; padding: 0 4px 4px; }
        .sig-image { max-height: 56px; border-bottom: 1px solid #333;
                     display: block; margin-bottom: 0; }
        .sig-meta { font-size: 11px; color: #444; margin-top: 4px; line-height: 1.4; }
        /* Audit certificate */
        .audit-cert { page-break-before: always; font-size: 12px; }
        .audit-cert h2 { font-size: 18px; margin: 0 0 8px; border-bottom: 2px solid #111;
                         padding-bottom: 8px; }
        .audit-cert .cert-meta { margin: 0 0 16px; line-height: 1.8; }
        .audit-cert .hash { font-family: monospace; font-size: 10px;
                            word-break: break-all; color: #333; }
        .audit-cert table { width: 100%; border-collapse: collapse; font-size: 11px;
                            font-family: monospace; }
        .audit-cert th { text-align: left; padding: 4px 6px; background: #f0f0f0;
                         border: 1px solid #ccc; }
        .audit-cert td { padding: 4px 6px; border: 1px solid #ddd;
                         vertical-align: top; word-break: break-word; }
CSS;

    public function __construct(
        private readonly Database $db,
        private readonly string $root
    ) {}

    /**
     * Render the current contract to PDF bytes (preview quality, no signatures).
     *
     * @throws \RuntimeException if wkhtmltopdf is missing or fails.
     */
    public function renderPreviewPdf(int $contractId): string
    {
        [$contract, $bodyHtml] = $this->buildContractHtml($contractId);
        $title = (string) ($contract['title'] ?? 'Contract');
        $css   = self::PDF_CSS;
        $safe  = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $html  = <<<HTML
<!doctype html><html lang="en"><head>
  <meta charset="utf-8"><title>{$safe}</title>
  <style>{$css}</style>
</head><body>{$bodyHtml}</body></html>
HTML;
        return $this->toPdf($html);
    }

    /**
     * Render the final signed PDF: contract body + signature blocks + audit cert.
     *
     * @throws \RuntimeException if wkhtmltopdf is missing or fails.
     */
    public function renderFinalSignedPdf(int $contractId): string
    {
        [$contract, $bodyHtml] = $this->buildContractHtml($contractId);

        $signers = $this->db->all(
            'SELECT * FROM contract_signers WHERE contract_id = ? ORDER BY id',
            [$contractId]
        );

        $auditRows = $this->db->all(
            'SELECT cal.action, cal.ip_address, cal.created_at,
                    cs.name AS signer_name, cs.email AS signer_email
               FROM contract_audit_log cal
               LEFT JOIN contract_signers cs ON cs.id = cal.signer_id
              WHERE cal.contract_id = ?
              ORDER BY cal.created_at ASC
              LIMIT 100',
            [$contractId]
        );

        $sigHtml   = $this->buildSignatureBlocks($signers);
        $auditHtml = $this->buildAuditCertificate($contract, $auditRows);

        $title = (string) ($contract['title'] ?? 'Contract');
        $css   = self::PDF_CSS;
        $safe  = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!doctype html><html lang="en"><head>
  <meta charset="utf-8"><title>{$safe}</title>
  <style>{$css}</style>
</head><body>
{$bodyHtml}
{$sigHtml}
{$auditHtml}
</body></html>
HTML;
        return $this->toPdf($html);
    }

    /**
     * Return the SHA-256 hex digest of PDF bytes.
     * Store the return value in contracts.final_pdf_sha256.
     */
    public function hashPdf(string $pdfBytes): string
    {
        return hash('sha256', $pdfBytes);
    }

    /**
     * Save PDF bytes to storage/contracts/{contractId}/{suffix}.pdf.
     *
     * @return string  Relative path from project root (suitable for DB storage).
     */
    public function storePdf(int $contractId, string $pdfBytes, string $suffix = 'final'): string
    {
        $dir = $this->root . '/storage/contracts/' . $contractId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = preg_replace('/[^a-z0-9_-]/', '_', strtolower($suffix)) . '.pdf';
        file_put_contents($dir . '/' . $filename, $pdfBytes);
        return 'storage/contracts/' . $contractId . '/' . $filename;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** @return array{0:array,1:string}  [contract_row, rendered_html_body] */
    private function buildContractHtml(int $contractId): array
    {
        $contract = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        if (!$contract) {
            throw new \RuntimeException("Contract {$contractId} not found");
        }

        [$event, $venue] = ContractService::eventVenueFor($this->db, $contract);
        $ctx      = ContractRenderer::context($contract, $event, $venue);
        $sections = $this->db->all(
            'SELECT * FROM contract_sections WHERE contract_id = ? ORDER BY sort_order, id',
            [$contractId]
        );
        $sections = array_map(
            static fn(array $s): array => $s + ['included' => (int) $s['included'] === 1],
            $sections
        );
        $rendered = ContractRenderer::render($contract, $sections, $ctx, $event, $venue);
        return [$contract, $rendered['html']];
    }

    private function buildSignatureBlocks(array $signers): string
    {
        if (empty($signers)) {
            return '';
        }

        $html = '<div class="sig-page"><h2>Signatures</h2>';

        foreach ($signers as $s) {
            $name    = htmlspecialchars((string) ($s['name']   ?? ''), ENT_QUOTES, 'UTF-8');
            $email   = htmlspecialchars((string) ($s['email']  ?? ''), ENT_QUOTES, 'UTF-8');
            $role    = htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($s['role'] ?? ''))), ENT_QUOTES, 'UTF-8');
            $company = $s['company'] ? htmlspecialchars((string) $s['company'], ENT_QUOTES, 'UTF-8') : '';
            $title   = $s['title']   ? htmlspecialchars((string) $s['title'],   ENT_QUOTES, 'UTF-8') : '';

            $signedAt = $s['signed_at']
                ? date('F j, Y \a\t g:i A', strtotime((string) $s['signed_at'])) . ' UTC'
                : '(not yet signed)';

            if (!empty($s['signature_text'])) {
                $sigEl = '<span class="sig-line">'
                       . htmlspecialchars((string) $s['signature_text'], ENT_QUOTES, 'UTF-8')
                       . '</span>';
            } elseif (!empty($s['signature_image_path'])
                && is_file($this->root . '/' . $s['signature_image_path'])) {
                $dataUri = 'data:image/png;base64,'
                         . base64_encode((string) file_get_contents($this->root . '/' . $s['signature_image_path']));
                $sigEl = "<img class=\"sig-image\" src=\"{$dataUri}\" alt=\"Signature\">";
            } else {
                $sigEl = '<span class="sig-line" style="color:#bbb">&nbsp;</span>';
            }

            $metaLines = array_filter([$company, $title, $email]);
            $metaHtml  = implode(' &bull; ', array_map(
                static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'),
                $metaLines
            ));

            $html .= <<<BLOCK
<div class="sig-block">
  <h3>{$role}</h3>
  <div class="sig-row">
    <div class="sig-col">
      {$sigEl}
      <div class="sig-meta">Signature</div>
    </div>
    <div class="sig-col">
      <span class="sig-line">{$name}</span>
      <div class="sig-meta">{$metaHtml}</div>
    </div>
    <div class="sig-col">
      <span class="sig-line">{$signedAt}</span>
      <div class="sig-meta">Date signed</div>
    </div>
  </div>
</div>
BLOCK;
        }

        $html .= '</div>';
        return $html;
    }

    private function buildAuditCertificate(array $contract, array $auditRows): string
    {
        $contractId = (int) $contract['id'];
        $title      = htmlspecialchars((string) ($contract['title'] ?? 'Contract'), ENT_QUOTES, 'UTF-8');
        $hash       = htmlspecialchars((string) ($contract['final_pdf_sha256'] ?? 'pending'), ENT_QUOTES, 'UTF-8');
        $executed   = $contract['fully_executed_at']
            ? date('F j, Y \a\t g:i A T', strtotime((string) $contract['fully_executed_at']))
            : 'Pending';

        $rows = '';
        foreach ($auditRows as $row) {
            $at     = htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
            $action = htmlspecialchars((string) ($row['action'] ?? ''), ENT_QUOTES, 'UTF-8');
            $signer = htmlspecialchars((string) ($row['signer_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $ip     = htmlspecialchars((string) ($row['ip_address']  ?? '—'), ENT_QUOTES, 'UTF-8');
            $rows  .= "<tr><td>{$at}</td><td>{$action}</td><td>{$signer}</td><td>{$ip}</td></tr>";
        }

        return <<<HTML
<div class="audit-cert">
  <h2>Electronic Signature Audit Certificate</h2>
  <div class="cert-meta">
    <strong>Document:</strong> {$title} (Contract #{$contractId})<br>
    <strong>Fully executed:</strong> {$executed}<br>
    <strong>SHA-256 fingerprint:</strong><br>
    <span class="hash">{$hash}</span>
  </div>
  <table>
    <thead><tr><th>Timestamp (UTC)</th><th>Event</th><th>Party</th><th>IP Address</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    /** Invoke wkhtmltopdf and return PDF bytes. @throws \RuntimeException */
    private function toPdf(string $html): string
    {
        if (!is_executable(self::WKHTMLTOPDF)) {
            throw new \RuntimeException('wkhtmltopdf not found at ' . self::WKHTMLTOPDF);
        }

        $args = '--quiet --page-size Letter'
              . ' --margin-top 0.5in --margin-right 0.5in'
              . ' --margin-bottom 0.5in --margin-left 0.5in'
              . ' --encoding utf-8 --disable-smart-shrinking'
              . ' - -';

        $proc = proc_open(self::WKHTMLTOPDF . ' ' . $args, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start wkhtmltopdf');
        }

        fwrite($pipes[0], $html);
        fclose($pipes[0]);

        $pdf    = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0 || $pdf === '') {
            throw new \RuntimeException("wkhtmltopdf exit={$exit}: {$stderr}");
        }

        return $pdf;
    }
}
