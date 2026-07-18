<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Contract / Deal Builder endpoint.
 *
 *   GET    /api/contracts                          list (admin: all; else own/event-scoped)
 *   POST   /api/contracts                          create (standalone = admin only)
 *   GET    /api/contracts/{id}                     full contract incl. preview + missing terms
 *   PATCH  /api/contracts/{id}                     update deal terms / counterparty / variables
 *   DELETE /api/contracts/{id}
 *   POST   /api/contracts/{id}/render              render a new immutable version
 *   POST   /api/contracts/{id}/email-pdf           email the current contract PDF as an attachment
 *   GET    /api/contracts/{id}/versions/{vid}      fetch a past version's HTML
 *   POST   /api/contracts/{id}/status              change workflow status
 *   POST   /api/contracts/{id}/apply-template      (re)build sections from a template
 *   POST   /api/contracts/{id}/reevaluate          re-run smart module selection
 *   POST   /api/contracts/{id}/sections            add a section (module or custom)
 *   PATCH  /api/contracts/{id}/sections            bulk update sections (include / order / body)
 *   DELETE /api/contracts/{id}/sections/{sid}      remove a section
 *
 * Digital-signature actions (all require manage access):
 *   POST   /api/contracts/{id}/send                send for signature (creates signers + emails)
 *   POST   /api/contracts/{id}/resend              resend link to unsigned signers
 *   POST   /api/contracts/{id}/void                void the contract
 *   POST   /api/contracts/{id}/countersign         venue countersignature (admin only)
 *   GET    /api/contracts/{id}/audit               audit log
 *   GET    /api/contracts/{id}/download            download final signed PDF
 *   GET    /api/contracts/{id}/signers             list signers
 */
final class Contracts extends BaseEndpoint
{
    private const RISK_LEVELS = ['none', 'low', 'medium', 'high'];

    public function handle(Request $request): Response
    {
        $method = $request->method();
        $id = $this->params['contractId'] ?? null;

        if ($id === null) {
            return match ($method) {
                'GET'  => $this->index(),
                'POST' => $this->create($request),
                default => Response::methodNotAllowed(),
            };
        }

        $contract = $this->db->one('SELECT * FROM contracts WHERE id = ?', [(int) $id]);
        if (!$contract) {
            return $this->notFound('Contract not found');
        }
        $access = $this->accessFor($contract);
        if (!$access['view']) {
            return $this->forbidden();
        }

        $child = $this->params['child'] ?? null;
        if ($child !== null) {
            return match ($child) {
                'render'         => $this->requireManage($access) ?? $this->renderVersion($contract),
                'pdf'            => $this->pdfExport($contract),
                'versions'       => $method === 'GET' ? $this->version($contract, (int) ($this->params['childId'] ?? 0)) : Response::methodNotAllowed(),
                'status'         => $this->requireManage($access) ?? $this->changeStatus($request, $contract, $access),
                'apply-template' => $this->requireManage($access) ?? $this->applyTemplate($request, $contract),
                'reevaluate'     => $this->requireManage($access) ?? $this->reevaluate($contract),
                'sections'       => $this->requireManage($access) ?? $this->sections($request, $contract),
                // ── Digital signature actions ──────────────────────────────────
                'email-pdf'   => $method === 'POST' ? ($this->requireManage($access) ?? $this->emailPdf($request, $contract)) : Response::methodNotAllowed(),
                'send'        => $this->requireManage($access) ?? $this->sendForSignature($request, $contract),
                'resend'      => $this->requireManage($access) ?? $this->resendSigningLinks($contract),
                'void'        => $this->requireManage($access) ?? $this->voidContract($request, $contract),
                'countersign' => $this->requireApprove($access) ?? $this->countersign($request, $contract),
                'audit'       => $method === 'GET' ? ($this->requireManage($access) ?? $this->auditLog($contract)) : Response::methodNotAllowed(),
                'download'    => $method === 'GET' ? ($this->requireManage($access) ?? $this->downloadFinalPdf($contract)) : Response::methodNotAllowed(),
                'signers'     => $method === 'GET' ? ($this->requireManage($access) ?? $this->listSigners($contract)) : Response::methodNotAllowed(),
                default       => $this->notFound(),
            };
        }

        return match ($method) {
            'GET'    => $this->show($contract, $access),
            'PATCH'  => $this->requireManage($access) ?? $this->update($request, $contract),
            'DELETE' => $this->requireManage($access) ?? $this->delete($contract),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── access ────────────────────────────────────────────────────────────

    /** @return array{view:bool,manage:bool,approve:bool} */
    private function accessFor(array $contract): array
    {
        if ($this->isVenueAdmin()) {
            return ['view' => true, 'manage' => true, 'approve' => true];
        }
        if (!empty($contract['event_id'])) {
            $eventId = (int) $contract['event_id'];
            return [
                'view'    => $this->hasEventCapability($eventId, 'view_contracts'),
                'manage'  => $this->hasEventCapability($eventId, 'manage_contracts'),
                'approve' => $this->hasEventCapability($eventId, 'approve_contracts'),
            ];
        }
        $own = $this->userId() && (int) ($contract['created_by_user_id'] ?? 0) === $this->userId();
        return ['view' => $own, 'manage' => $own, 'approve' => false];
    }

    private function requireManage(array $access): ?Response
    {
        return $access['manage'] ? null : $this->forbidden();
    }

    private function requireApprove(array $access): ?Response
    {
        return $access['approve'] ? null : $this->forbidden('Only approvers can countersign contracts.');
    }

    // ── list / create ───────────────────────────────────────────────────────

    private function index(): Response
    {
        $select = 'SELECT c.id, c.title, c.contract_type, c.status, c.event_id, c.counterparty_name, c.updated_at, c.current_version_id,
                          e.title AS event_title, e.date AS event_date, v.name AS venue_name, u.name AS created_by_name
                   FROM contracts c
                   LEFT JOIN events e ON e.id = c.event_id
                   LEFT JOIN venues v ON v.id = c.venue_id
                   LEFT JOIN users u ON u.id = c.created_by_user_id';
        if ($this->hasGlobalCapability('view_all_contracts')) {
            $rows = $this->db->all("$select ORDER BY c.updated_at DESC");
        } else {
            [$scope, $params] = $this->eventScopeSql('e');
            $rows = $this->db->all(
                "$select WHERE c.created_by_user_id = ? OR (c.event_id IS NOT NULL AND ($scope)) ORDER BY c.updated_at DESC",
                array_merge([$this->userId()], $params)
            );
        }
        return $this->ok([
            'contracts' => $rows,
            'templates' => $this->activeTemplates(),
            'types'     => ContractRenderer::CONTRACT_TYPES,
            'statuses'  => ContractRenderer::STATUSES,
            'venues'    => $this->db->all('SELECT id, name FROM venues ORDER BY name'),
            'can_create_standalone' => $this->isVenueAdmin(),
        ]);
    }

    private function create(Request $request): Response
    {
        $b = $request->body();
        $eventId = !empty($b['event_id']) ? (int) $b['event_id'] : null;
        $venueId = !empty($b['venue_id']) ? (int) $b['venue_id'] : null;

        if ($eventId) {
            if ($denied = $this->requireEventCapability($eventId, 'manage_contracts')) {
                return $denied;
            }
            $event = $this->db->one('SELECT venue_id, title FROM events WHERE id = ?', [$eventId]);
            if ($event && !$venueId) {
                $venueId = (int) $event['venue_id'];
            }
        } elseif (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can create venue-level contracts.');
        }

        $id = ContractService::create($this->db, [
            'event_id'           => $eventId,
            'venue_id'           => $venueId,
            'template_id'        => $b['template_id'] ?? null,
            'contract_type'      => $b['contract_type'] ?? 'other',
            'title'              => $b['title'] ?? '',
            'counterparty_name'  => $b['counterparty_name'] ?? null,
            'counterparty_org'   => $b['counterparty_org'] ?? null,
            'counterparty_email' => $b['counterparty_email'] ?? null,
        ], $this->userId());

        if ($eventId) {
            log_activity($this->db, $eventId, $this->userId(), 'contract created', ['contract_id' => $id]);
        }
        return $this->ok(['id' => $id]);
    }

    // ── show ────────────────────────────────────────────────────────────────

    private function show(array $contract, array $access): Response
    {
        [$event, $venue] = ContractService::eventVenueFor($this->db, $contract);
        $ctx = ContractRenderer::context($contract, $event, $venue);
        $sections = $this->loadSections((int) $contract['id']);
        $secForCalc = $this->boolIncluded($sections);

        $preview = ContractRenderer::render($contract, $secForCalc, $ctx, $event, $venue);
        $missing = ContractRenderer::missingFields($secForCalc, $ctx['tokens']);

        return $this->ok([
            'contract'          => $contract,
            'sections'          => $sections,
            'event'             => $event,
            'venue'             => $venue,
            'preview_html'      => $preview['html'],
            'summary'           => $preview['summary'],
            'missing'           => $missing,
            'risk_flags'        => $this->riskFlags($contract, $secForCalc),
            'signers'           => $access['manage']
                ? $this->db->all(
                    'SELECT id, role, name, email, phone, company, title, status, viewed_at, signed_at, declined_at, created_at
                       FROM contract_signers WHERE contract_id = ? ORDER BY id',
                    [(int) $contract['id']]
                )
                : [],
            'versions'          => $this->db->all('SELECT v.id, v.version_number, v.created_at, u.name AS created_by_name FROM contract_versions v LEFT JOIN users u ON u.id = v.created_by_user_id WHERE v.contract_id = ? ORDER BY v.version_number DESC', [(int) $contract['id']]),
            'available_modules' => $this->db->all('SELECT id, module_key, name, category, risk_level, is_locked, required_fields_json FROM contract_modules WHERE is_active = 1 ORDER BY category, sort_order, name'),
            'templates'         => $this->activeTemplates(),
            'types'             => ContractRenderer::CONTRACT_TYPES,
            'statuses'          => ContractRenderer::STATUSES,
            'risk_levels'       => self::RISK_LEVELS,
            'security_paid_by'  => ['venue', 'artist', 'promoter', 'client', 'shared'],
            'capabilities'      => ['manage' => $access['manage'], 'approve' => $access['approve']],
        ]);
    }

    // ── update ────────────────────────────────────────────────────────────────

    private function update(Request $request, array $contract): Response
    {
        $b = $request->body();
        $fields = array_merge(
            ['title', 'contract_type', 'counterparty_name', 'counterparty_org', 'counterparty_email', 'internal_notes', 'venue_id'],
            ContractRenderer::DEAL_COLUMNS
        );
        $set = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $b)) {
                $set[] = "$f = ?";
                $vals[] = $this->normalizeField($f, $b[$f]);
            }
        }
        if (array_key_exists('variables', $b) || array_key_exists('variables_json', $b)) {
            $v = $b['variables'] ?? $b['variables_json'];
            $set[] = 'variables_json = ?';
            $vals[] = is_array($v) ? json_encode($v) : (is_string($v) && $v !== '' ? $v : '{}');
        }
        if ($set) {
            $vals[] = (int) $contract['id'];
            $this->db->run('UPDATE contracts SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
        }

        // Keep smart auto-selected sections honest as the deal terms change.
        $fresh = $this->db->one('SELECT * FROM contracts WHERE id = ?', [(int) $contract['id']]);
        $changed = $fresh ? ContractService::reevaluate($this->db, $fresh) : 0;

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract updated', ['contract_id' => (int) $contract['id']]);
        }
        return $this->ok(['ok' => true, 'reevaluated' => $changed]);
    }

    private function normalizeField(string $field, mixed $value): mixed
    {
        if ($field === 'contract_type') {
            return in_array($value, ContractRenderer::CONTRACT_TYPES, true) ? $value : 'other';
        }
        if (in_array($field, ['sound_tech_included', 'lighting_tech_included'], true)) {
            return ($value === '' || $value === null) ? null : boolish($value);
        }
        if ($value === '' || $value === null) {
            return null;
        }
        return $value;
    }

    private function delete(array $contract): Response
    {
        $this->db->run('DELETE FROM contracts WHERE id = ?', [(int) $contract['id']]);
        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract deleted', ['contract_id' => (int) $contract['id']]);
        }
        return Response::noContent();
    }

    // ── versions / rendering ────────────────────────────────────────────────

    private function renderVersion(array $contract): Response
    {
        [$event, $venue] = ContractService::eventVenueFor($this->db, $contract);
        $ctx = ContractRenderer::context($contract, $event, $venue);
        $sections = $this->boolIncluded($this->loadSections((int) $contract['id']));
        $rendered = ContractRenderer::render($contract, $sections, $ctx, $event, $venue);

        $next = (int) ($this->db->one('SELECT COALESCE(MAX(version_number), 0) + 1 AS n FROM contract_versions WHERE contract_id = ?', [(int) $contract['id']])['n'] ?? 1);
        $vid = $this->db->insert(
            'INSERT INTO contract_versions (contract_id, version_number, rendered_html, rendered_text, variables_snapshot_json, summary_json, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $contract['id'], $next, $rendered['html'], $rendered['text'], json_encode($ctx['tokens']), json_encode($rendered['summary']), $this->userId()]
        );
        $this->db->run('UPDATE contracts SET current_version_id = ? WHERE id = ?', [$vid, (int) $contract['id']]);
        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract version generated', ['version' => $next]);
        }
        return $this->ok(['id' => $vid, 'version_number' => $next, 'html' => $rendered['html']]);
    }

    // ── PDF export via wkhtmltopdf ────────────────────────────────────────────

    /** CSS for the wkhtmltopdf render (no @page needed — margins come from CLI flags). */
    private const PDF_CSS = <<<'CSS'
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #111; background: #fff; margin: 0; padding: 0; line-height: 1.6; }
        .contract-doc { max-width: none; margin: 0; }
        .contract-doc-head h1 { font-size: 22px; margin: 0 0 4px; }
        .contract-doc-sub { color: #555; margin: 0 0 20px; font-style: italic; }
        .contract-summary { width: 100%; border-collapse: collapse; margin: 0 0 24px; font-size: 13px; }
        .contract-summary caption { text-align: left; font-weight: bold; padding-bottom: 6px; }
        .contract-summary th { text-align: left; width: 42%; padding: 4px 8px; color: #444; font-weight: normal; border-bottom: 1px solid #ddd; }
        .contract-summary td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
        .contract-section { margin: 0 0 20px; }
        .contract-section h2 { font-size: 15px; margin: 0 0 6px; }
        .contract-section-body p { margin: 0 0 8px; text-align: justify; }
        .contract-token-missing { background: #ffe2a8; color: #7a4b00; padding: 0 4px; border-radius: 3px; font-style: italic; }
CSS;

    /**
     * Render the current contract preview to a PDF via wkhtmltopdf and stream
     * it back as application/pdf.
     *   GET /api/contracts/{id}/pdf
     */
    private function pdfExport(array $contract): Response
    {
        $pdf = $this->renderContractPdf($contract);
        if ($pdf === null) {
            return Response::json(['error' => 'PDF generation failed'], 500);
        }

        $safe = $this->pdfFilenameStem($contract);

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}.pdf\"",
            'Content-Length'      => (string) strlen($pdf),
            'Cache-Control'       => 'private, no-cache',
        ]);
    }

    /**
     * Render the current contract preview to PDF bytes via wkhtmltopdf.
     * Returns the raw PDF string, or null if rendering failed. Shared by the
     * download (pdfExport) and email-PDF flows.
     */
    private function renderContractPdf(array $contract): ?string
    {
        [$event, $venue] = ContractService::eventVenueFor($this->db, $contract);
        $ctx      = ContractRenderer::context($contract, $event, $venue);
        $sections = $this->boolIncluded($this->loadSections((int) $contract['id']));
        $rendered = ContractRenderer::render($contract, $sections, $ctx, $event, $venue);

        $title   = htmlspecialchars((string) ($contract['title'] ?? 'Contract'), ENT_QUOTES, 'UTF-8');
        $css     = self::PDF_CSS;
        $body    = $rendered['html'];
        $html    = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <style>{$css}</style>
</head>
<body>{$body}</body>
</html>
HTML;

        $bin  = '/usr/bin/wkhtmltopdf';
        $args = '--quiet --page-size Letter'
              . ' --margin-top 0.5in --margin-right 0.5in'
              . ' --margin-bottom 0.5in --margin-left 0.5in'
              . ' --encoding utf-8 --disable-smart-shrinking'
              . ' - -';   // stdin → stdout

        $proc = proc_open("$bin $args", [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            error_log('wkhtmltopdf: proc_open failed (renderer unavailable)');
            return null;
        }

        fwrite($pipes[0], $html);
        fclose($pipes[0]);

        $pdf    = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit   = proc_close($proc);

        if ($exit !== 0 || !$pdf) {
            error_log("wkhtmltopdf exit={$exit}: {$stderr}");
            return null;
        }

        return $pdf;
    }

    /** Filesystem-safe stem for a contract PDF filename (no extension). */
    private function pdfFilenameStem(array $contract): string
    {
        $safe = preg_replace('/[^\w\s-]/', '', (string) ($contract['title'] ?? 'contract'));
        return trim(preg_replace('/\s+/', '-', (string) $safe)) ?: 'contract';
    }

    /**
     * POST /api/contracts/{id}/email-pdf
     *
     * Body: { email: "...", message?: "..." }
     *
     * Renders the current contract to PDF and emails it as an attachment.
     * The recipient defaults to the contract counterparty in the UI but is
     * always taken from the request body here.
     */
    private function emailPdf(Request $request, array $contract): Response
    {
        $contractId = (int) $contract['id'];
        $email      = trim((string) ($request->body('email') ?? ''));
        $note       = trim((string) ($request->body('message') ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid recipient email address is required.'], 422);
        }

        $pdf = $this->renderContractPdf($contract);
        if ($pdf === null) {
            return Response::json(['error' => 'Could not generate the contract PDF.'], 500);
        }

        $title    = (string) ($contract['title'] ?? 'Agreement');
        $filename = $this->pdfFilenameStem($contract) . '.pdf';
        $mailer   = new Mailer($this->root, $this->db);

        try {
            $mailer->sendTemplate(
                $email,
                'Contract: ' . $title,
                'contract-pdf',
                [
                    'contract_title' => $title,
                    'message'        => $note !== '' ? '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' : '',
                    'message_text'   => $note !== '' ? $note . "\n\n" : '',
                    'venue_name'     => (string) (getenv('MAIL_FROM_NAME') ?: 'The Venue'),
                ],
                [],
                [[
                    'filename' => $filename,
                    'mime'     => 'application/pdf',
                    'bytes'    => $pdf,
                ]]
            );
        } catch (\Throwable $e) {
            error_log("Contracts::emailPdf mail failed to {$email}: " . $e->getMessage());
            return Response::json(['error' => 'The contract PDF could not be emailed.'], 500);
        }

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'pdf_emailed', null,
            ['email' => $email, 'sent_by_user_id' => $this->userId()]
        );

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract PDF emailed', ['contract_id' => $contractId, 'to' => $email]);
        }

        return $this->ok(['ok' => true, 'email' => $email]);
    }

    private function version(array $contract, int $versionId): Response
    {
        $row = $this->db->one('SELECT * FROM contract_versions WHERE id = ? AND contract_id = ?', [$versionId, (int) $contract['id']]);
        if (!$row) {
            return $this->notFound('Version not found');
        }
        return $this->ok(['version' => $row]);
    }

    // ── status workflow ───────────────────────────────────────────────────────

    private function changeStatus(Request $request, array $contract, array $access): Response
    {
        $target = (string) $request->body('status', '');
        if (!in_array($target, ContractRenderer::STATUSES, true)) {
            return Response::json(['error' => 'Invalid status'], 422);
        }
        if ($target === 'approved' && !$access['approve']) {
            return $this->forbidden('You do not have permission to approve contracts.');
        }
        if (in_array($target, ['sent', 'signed'], true)) {
            [$event, $venue] = ContractService::eventVenueFor($this->db, $contract);
            $ctx = ContractRenderer::context($contract, $event, $venue);
            $missing = ContractRenderer::missingFields($this->boolIncluded($this->loadSections((int) $contract['id'])), $ctx['tokens']);
            if ($missing) {
                return Response::json(['error' => "Resolve missing required terms before marking $target.", 'missing' => $missing], 422);
            }
            if (!$this->db->one('SELECT id FROM contract_versions WHERE contract_id = ? LIMIT 1', [(int) $contract['id']])) {
                return Response::json(['error' => "Generate a version before marking $target."], 422);
            }
        }

        $set = ['status = ?'];
        $vals = [$target];
        if ($target === 'approved') {
            $set[] = 'approved_by_user_id = ?';
            $vals[] = $this->userId();
        }
        if ($target === 'sent') {
            $set[] = 'sent_at = NOW()';
        }
        if ($target === 'signed') {
            $set[] = 'signed_at = NOW()';
        }
        $vals[] = (int) $contract['id'];
        $this->db->run('UPDATE contracts SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), "contract $target", ['contract_id' => (int) $contract['id']]);
        }
        return $this->ok(['ok' => true, 'status' => $target]);
    }

    // ── template / sections ───────────────────────────────────────────────────

    private function applyTemplate(Request $request, array $contract): Response
    {
        $templateId = (int) $request->body('template_id', 0);
        if (!$templateId || !$this->db->one('SELECT id FROM contract_templates WHERE id = ?', [$templateId])) {
            return $this->notFound('Template not found');
        }
        ContractService::buildSectionsFromTemplate($this->db, (int) $contract['id'], $templateId);
        $tpl = $this->db->one('SELECT contract_type FROM contract_templates WHERE id = ?', [$templateId]);
        if ($tpl) {
            $this->db->run('UPDATE contracts SET contract_type = ? WHERE id = ?', [$tpl['contract_type'], (int) $contract['id']]);
        }
        return $this->ok(['ok' => true]);
    }

    private function reevaluate(array $contract): Response
    {
        $changed = ContractService::reevaluate($this->db, $contract);
        return $this->ok(['ok' => true, 'reevaluated' => $changed]);
    }

    private function sections(Request $request, array $contract): Response
    {
        $method = $request->method();
        $contractId = (int) $contract['id'];

        if ($method === 'POST') {
            $b = $request->body();
            $order = (int) ($this->db->one('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM contract_sections WHERE contract_id = ?', [$contractId])['n'] ?? 0);
            if (!empty($b['module_id'])) {
                $m = $this->db->one('SELECT * FROM contract_modules WHERE id = ?', [(int) $b['module_id']]);
                if (!$m) {
                    return $this->notFound('Module not found');
                }
                $id = $this->db->insert(
                    'INSERT INTO contract_sections (contract_id, module_id, module_key, title, body_template, sort_order, included, is_locked, auto_selected, risk_level, required_fields_json)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?)',
                    [$contractId, $m['id'], $m['module_key'], $m['name'], $m['body_template'], $order, (int) $m['is_locked'], $m['risk_level'], $m['required_fields_json']]
                );
            } else {
                $title = trim((string) ($b['title'] ?? '')) ?: 'Custom Section';
                $id = $this->db->insert(
                    'INSERT INTO contract_sections (contract_id, title, body_template, sort_order, included) VALUES (?, ?, ?, ?, 1)',
                    [$contractId, $title, (string) ($b['body_template'] ?? ''), $order]
                );
            }
            return $this->ok(['id' => $id]);
        }

        if ($method === 'PATCH') {
            $sections = $request->body('sections');
            if (is_array($sections)) {
                $locked = $this->lockedSectionIds($contractId);
                foreach ($sections as $s) {
                    if (empty($s['id'])) {
                        continue;
                    }
                    $sid = (int) $s['id'];
                    $isLocked = isset($locked[$sid]) && !$this->isVenueAdmin();
                    $set = [];
                    $vals = [];
                    foreach (['included', 'sort_order', 'title', 'body_template'] as $f) {
                        if (!array_key_exists($f, $s)) {
                            continue;
                        }
                        // Locked legal clauses: non-admins may not exclude or edit them.
                        if ($isLocked && in_array($f, ['included', 'title', 'body_template'], true)) {
                            continue;
                        }
                        $set[] = "$f = ?";
                        $vals[] = $f === 'included' ? boolish($s[$f]) : $s[$f];
                    }
                    if ($set) {
                        $vals[] = $sid;
                        $vals[] = $contractId;
                        $this->db->run('UPDATE contract_sections SET ' . implode(', ', $set) . ' WHERE id = ? AND contract_id = ?', $vals);
                    }
                }
            }
            return $this->ok(['ok' => true]);
        }

        if ($method === 'DELETE') {
            $sid = (int) ($this->params['childId'] ?? 0);
            if (!$sid) {
                return $this->notFound();
            }
            $sec = $this->db->one('SELECT is_locked FROM contract_sections WHERE id = ? AND contract_id = ?', [$sid, $contractId]);
            if (!$sec) {
                return $this->notFound();
            }
            if ((int) $sec['is_locked'] === 1 && !$this->isVenueAdmin()) {
                return $this->forbidden('Locked sections can only be removed by an admin.');
            }
            $this->db->run('DELETE FROM contract_sections WHERE id = ? AND contract_id = ?', [$sid, $contractId]);
            return Response::noContent();
        }

        return Response::methodNotAllowed();
    }

    // ── digital signatures ────────────────────────────────────────────────────

    /**
     * POST /api/contracts/{id}/send
     *
     * Body: { signers?: [{role, name, email, phone?, company?, title?}] }
     *
     * Creates signer records, generates signing tokens, sends emails,
     * updates contract status to 'sent'.
     *
     * If `signers` is omitted the counterparty on the contract is used.
     */
    private function sendForSignature(Request $request, array $contract): Response
    {
        $contractId = (int) $contract['id'];

        // Block re-sending a fully-executed or voided contract.
        if (in_array($contract['status'], ['fully_executed', 'voided', 'canceled'], true)) {
            return Response::json(['error' => 'This contract cannot be sent for signature in its current state.'], 422);
        }

        // Ensure there is a rendered version.
        if (!$this->db->one('SELECT id FROM contract_versions WHERE contract_id = ? LIMIT 1', [$contractId])) {
            return Response::json(['error' => 'Generate a contract version before sending for signature.'], 422);
        }

        $b       = $request->body();
        $signers = $b['signers'] ?? null;

        // Derive signers from counterparty if not explicitly provided.
        if (empty($signers)) {
            if (empty($contract['counterparty_email'])) {
                return Response::json(['error' => 'No signers provided and no counterparty email on the contract.'], 422);
            }
            $signers = [[
                'role'    => 'renter',
                'name'    => $contract['counterparty_name']  ?? '',
                'email'   => $contract['counterparty_email'] ?? '',
                'company' => $contract['counterparty_org']   ?? null,
            ]];
        }

        $ttlHours = max(1, (int) (getenv('SIGNATURE_TOKEN_TTL_HOURS') ?: 168));
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $mailer   = new Mailer($this->root, $this->db);

        // Void any existing pending signers on this contract (re-send scenario).
        $this->db->run(
            "UPDATE contract_signers SET status = 'voided', signing_token_hash = NULL WHERE contract_id = ? AND status IN ('pending','sent','viewed')",
            [$contractId]
        );

        foreach ($signers as $signerData) {
            $email = trim((string) ($signerData['email'] ?? ''));
            $name  = trim((string) ($signerData['name']  ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Generate token — store only the hash.
            $rawToken     = $this->auth->generateToken(48);
            $tokenHash    = $this->auth->hashToken($rawToken);
            $expiresEpoch = time() + $ttlHours * 3600;
            // Stored as UTC (gmdate) to match db_timestamp_to_epoch(), which reads
            // token_expires_at back assuming UTC. Using date() here (ambient tz is
            // America/Los_Angeles) would write local time and cause the read side
            // to treat it as UTC, silently truncating the token's real TTL.
            $expiresAt    = gmdate('Y-m-d H:i:s', $expiresEpoch);

            $signerId = $this->db->insert(
                'INSERT INTO contract_signers
                    (contract_id, role, name, email, phone, company, title, status,
                     signing_token_hash, token_expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $contractId,
                    $signerData['role']    ?? 'renter',
                    $name,
                    $email,
                    $signerData['phone']   ?? null,
                    $signerData['company'] ?? null,
                    $signerData['title']   ?? null,
                    'sent',
                    $tokenHash,
                    $expiresAt,
                ]
            );

            // Send signing email.
            $signingUrl = $appUrl . '/sign.html?token=' . urlencode($rawToken);
            try {
                $mailer->sendTemplate(
                    $email,
                    'Please sign: ' . ($contract['title'] ?? 'Agreement'),
                    'contract-sign-request',
                    [
                        'signer_name'    => $name,
                        'contract_title' => (string) ($contract['title'] ?? ''),
                        'signing_url'    => $signingUrl,
                        'expires_date'   => date('F j, Y', $expiresEpoch),
                        'venue_name'     => (string) (getenv('MAIL_FROM_NAME') ?: 'The Venue'),
                    ]
                );
            } catch (\Throwable $e) {
                error_log("Contracts::sendForSignature mail failed to {$email}: " . $e->getMessage());
            }

            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'contract_sent', $signerId,
                ['email' => $email, 'role' => $signerData['role'] ?? 'renter']
            );
        }

        // Create provider envelope (for external providers).
        try {
            $provider    = ContractSignatureProviders::make();
            $signerRows  = $this->db->all('SELECT * FROM contract_signers WHERE contract_id = ? AND status = ?', [$contractId, 'sent']);
            $envelope    = $provider->createEnvelope($contract, $signerRows);
            $envelopeId  = $envelope['envelope_id'] ?? null;
            if ($envelopeId) {
                $this->db->run(
                    'UPDATE contracts SET provider_envelope_id = ?, provider_status = ? WHERE id = ?',
                    [$envelopeId, $envelope['status'] ?? 'created', $contractId]
                );
                $provider->sendEnvelope($envelopeId);
            }
        } catch (\Throwable $e) {
            error_log("Contracts::sendForSignature provider error: " . $e->getMessage());
            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'provider_error', null,
                ['detail' => $e->getMessage()]
            );
        }

        // Update contract status.
        $this->db->run(
            "UPDATE contracts SET status = 'sent', sent_at = NOW() WHERE id = ?",
            [$contractId]
        );

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract sent for signature', ['contract_id' => $contractId]);
        }

        // Notify all venue admins.
        $this->notifyAdminsOfSend($contract);

        return $this->ok(['ok' => true]);
    }

    /**
     * POST /api/contracts/{id}/resend
     *
     * Regenerates tokens and resends signing emails to all signers who have
     * not yet signed.
     */
    private function resendSigningLinks(array $contract): Response
    {
        $contractId = (int) $contract['id'];

        if (in_array($contract['status'], ['fully_executed', 'voided', 'canceled', 'draft'], true)) {
            return Response::json(['error' => 'Cannot resend links for a contract in this state.'], 422);
        }

        $pending = $this->db->all(
            "SELECT * FROM contract_signers WHERE contract_id = ? AND status IN ('sent','viewed','pending')",
            [$contractId]
        );

        if (empty($pending)) {
            return Response::json(['error' => 'No unsigned signers to resend to.'], 422);
        }

        $ttlHours = max(1, (int) (getenv('SIGNATURE_TOKEN_TTL_HOURS') ?: 168));
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $mailer   = new Mailer($this->root, $this->db);

        foreach ($pending as $signer) {
            $rawToken     = $this->auth->generateToken(48);
            $tokenHash    = $this->auth->hashToken($rawToken);
            $expiresEpoch = time() + $ttlHours * 3600;
            // See comment in sendForSignature(): must be UTC to match
            // db_timestamp_to_epoch()'s read-side assumption.
            $expiresAt    = gmdate('Y-m-d H:i:s', $expiresEpoch);

            $this->db->run(
                "UPDATE contract_signers SET status = 'sent', signing_token_hash = ?, token_expires_at = ? WHERE id = ?",
                [$tokenHash, $expiresAt, (int) $signer['id']]
            );

            $signingUrl = $appUrl . '/sign.html?token=' . urlencode($rawToken);
            try {
                $mailer->sendTemplate(
                    (string) $signer['email'],
                    'Reminder — please sign: ' . ($contract['title'] ?? 'Agreement'),
                    'contract-sign-request',
                    [
                        'signer_name'    => (string) $signer['name'],
                        'contract_title' => (string) ($contract['title'] ?? ''),
                        'signing_url'    => $signingUrl,
                        'expires_date'   => date('F j, Y', $expiresEpoch),
                        'venue_name'     => (string) (getenv('MAIL_FROM_NAME') ?: 'The Venue'),
                    ]
                );
            } catch (\Throwable $e) {
                error_log("Contracts::resendSigningLinks mail failed: " . $e->getMessage());
            }

            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'contract_resent', (int) $signer['id'],
                ['email' => $signer['email']]
            );
        }

        return $this->ok(['ok' => true, 'resent' => count($pending)]);
    }

    /**
     * POST /api/contracts/{id}/void
     *
     * Body: { reason?: "..." }
     *
     * Voids the contract and invalidates all pending signing tokens.
     */
    private function voidContract(Request $request, array $contract): Response
    {
        $contractId = (int) $contract['id'];

        if (in_array($contract['status'], ['fully_executed', 'voided'], true)) {
            return Response::json(['error' => 'Contract is already ' . $contract['status'] . '.'], 422);
        }

        $reason = trim((string) ($request->body('reason') ?? ''));

        // Invalidate all pending signer tokens.
        $this->db->run(
            "UPDATE contract_signers
             SET status = 'voided', signing_token_hash = NULL, token_expires_at = NULL
             WHERE contract_id = ? AND status IN ('pending','sent','viewed')",
            [$contractId]
        );

        $this->db->run(
            "UPDATE contracts SET status = 'voided', voided_at = NOW() WHERE id = ?",
            [$contractId]
        );

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'contract_voided', null,
            array_filter(['reason' => $reason, 'voided_by' => $this->userId()])
        );

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract voided', ['contract_id' => $contractId]);
        }

        // Notify admins.
        $this->notifyAdminsOfVoid($contract, $reason);

        return $this->ok(['ok' => true]);
    }

    /**
     * POST /api/contracts/{id}/countersign
     *
     * Records the venue's countersignature directly (admin-authenticated action,
     * no magic link needed since the admin is already logged in).
     *
     * Body: { name: "...", title?: "...", signature_text?: "..." }
     *
     * Advances contract from 'signed_by_client' → 'countersigned' → 'fully_executed'.
     */
    private function countersign(Request $request, array $contract): Response
    {
        $contractId = (int) $contract['id'];

        $allowedStatuses = ['sent', 'viewed', 'partially_signed', 'signed_by_client', 'countersigned'];
        if (!in_array($contract['status'], $allowedStatuses, true)) {
            return Response::json([
                'error' => 'Contract cannot be countersigned in its current state (' . $contract['status'] . ').',
            ], 422);
        }

        $b    = $request->body();
        $user = $this->auth->user();
        $name = trim((string) ($b['name'] ?? $user['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'Signer name is required.'], 422);
        }

        // Upsert a venue signer row.
        $existing = $this->db->one(
            "SELECT id FROM contract_signers WHERE contract_id = ? AND role = 'venue' LIMIT 1",
            [$contractId]
        );

        if ($existing) {
            $this->db->run(
                "UPDATE contract_signers
                 SET status = 'signed', signed_at = NOW(), name = ?, title = ?, signature_text = ?,
                     ip_address = ?, user_agent = ?, signing_token_hash = NULL
                 WHERE id = ?",
                [
                    $name,
                    trim((string) ($b['title'] ?? '')),
                    trim((string) ($b['signature_text'] ?? $name)),
                    $this->clientIp(),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
                    (int) $existing['id'],
                ]
            );
            $signerId = (int) $existing['id'];
        } else {
            $signerId = $this->db->insert(
                "INSERT INTO contract_signers
                    (contract_id, role, name, email, title, status, signed_at,
                     signature_text, ip_address, user_agent)
                 VALUES (?, 'venue', ?, ?, ?, 'signed', NOW(), ?, ?, ?)",
                [
                    $contractId,
                    $name,
                    (string) ($user['email'] ?? ''),
                    trim((string) ($b['title'] ?? '')),
                    trim((string) ($b['signature_text'] ?? $name)),
                    $this->clientIp(),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
                ]
            );
        }

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'contract_countersigned', $signerId,
            ['countersigned_by_user_id' => $this->userId()]
        );

        // Check whether all signers are now signed. Exclude 'voided' rows —
        // dead placeholders left by a superseded resend (see
        // sendForSignature()) that can never become 'signed' and would
        // otherwise permanently block a resent contract from finalizing.
        $unsigned = $this->db->one(
            "SELECT COUNT(*) AS n FROM contract_signers WHERE contract_id = ? AND status NOT IN ('signed', 'voided')",
            [$contractId]
        );

        if ((int) ($unsigned['n'] ?? 1) === 0) {
            // All signed → fully executed.
            $this->finalizeCountersigned($contractId, $contract);
        } else {
            $this->db->run(
                "UPDATE contracts SET status = 'countersigned' WHERE id = ?",
                [$contractId]
            );
        }

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract countersigned', ['contract_id' => $contractId]);
        }

        return $this->ok(['ok' => true]);
    }

    private function finalizeCountersigned(int $contractId, array $contract): void
    {
        $this->db->run(
            "UPDATE contracts SET status = 'fully_executed', fully_executed_at = NOW() WHERE id = ?",
            [$contractId]
        );

        ContractAuditLog::appendFromRequest($this->db, $contractId, 'contract_fully_executed');

        try {
            $pdfService = new ContractPdfService($this->db, $this->root);
            $pdfBytes   = $pdfService->renderFinalSignedPdf($contractId);
            $hash       = $pdfService->hashPdf($pdfBytes);
            $path       = $pdfService->storePdf($contractId, $pdfBytes, 'final');

            $this->db->run(
                'UPDATE contracts SET final_pdf_path = ?, final_pdf_sha256 = ? WHERE id = ?',
                [$path, $hash, $contractId]
            );

            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'pdf_generated', null, ['path' => $path]
            );
            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'pdf_hash_created', null, ['sha256' => $hash]
            );
        } catch (\Throwable $e) {
            error_log("Contracts::countersign PDF generation failed: " . $e->getMessage());
        }

        if (!empty($contract['event_id'])) {
            $this->db->run(
                "UPDATE events SET status = 'booked' WHERE id = ? AND status IN ('proposed','confirmed')",
                [(int) $contract['event_id']]
            );
            log_activity($this->db, (int) $contract['event_id'], $this->userId(), 'contract_signed', ['contract_id' => $contractId]);
        }
    }

    /**
     * GET /api/contracts/{id}/audit
     *
     * Returns the immutable audit log for this contract.
     */
    private function auditLog(array $contract): Response
    {
        $rows = $this->db->all(
            'SELECT cal.id, cal.action, cal.ip_address, cal.user_agent, cal.metadata_json,
                    cal.created_at, cs.name AS signer_name, cs.email AS signer_email,
                    cs.role AS signer_role
               FROM contract_audit_log cal
               LEFT JOIN contract_signers cs ON cs.id = cal.signer_id
              WHERE cal.contract_id = ?
              ORDER BY cal.created_at ASC',
            [(int) $contract['id']]
        );

        // Parse metadata_json for each row.
        foreach ($rows as &$row) {
            $row['metadata'] = $row['metadata_json'] ? json_decode($row['metadata_json'], true) : null;
            unset($row['metadata_json'], $row['user_agent']); // UA is stored but not sent to admin UI
        }
        unset($row);

        return $this->ok(['audit_log' => $rows]);
    }

    /**
     * GET /api/contracts/{id}/download
     *
     * Streams the final signed PDF back to the admin.
     */
    private function downloadFinalPdf(array $contract): Response
    {
        $path = (string) ($contract['final_pdf_path'] ?? '');

        if ($path === '') {
            return Response::json(['error' => 'No final PDF is available for this contract yet.'], 404);
        }

        $fullPath = $this->root . '/' . $path;
        if (!is_file($fullPath)) {
            return Response::json(['error' => 'Final PDF file not found on disk.'], 404);
        }

        $pdf  = (string) file_get_contents($fullPath);
        $safe = preg_replace('/[^\w\s-]/', '', (string) ($contract['title'] ?? 'contract'));
        $safe = trim(preg_replace('/\s+/', '-', $safe)) ?: 'contract';

        ContractAuditLog::appendFromRequest(
            $this->db, (int) $contract['id'], 'contract_previewed', null,
            ['downloaded_by_user_id' => $this->userId()]
        );

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}-signed.pdf\"",
            'Content-Length'      => (string) strlen($pdf),
            'Cache-Control'       => 'private, no-cache',
        ]);
    }

    /**
     * GET /api/contracts/{id}/signers
     *
     * Returns all signer records for the contract (without raw token data).
     */
    private function listSigners(array $contract): Response
    {
        $signers = $this->db->all(
            'SELECT id, role, name, email, phone, company, title, status,
                    viewed_at, signed_at, declined_at, ip_address, created_at, updated_at
               FROM contract_signers WHERE contract_id = ? ORDER BY id',
            [(int) $contract['id']]
        );
        return $this->ok(['signers' => $signers]);
    }

    // ── signature notification helpers ────────────────────────────────────────

    private function notifyAdminsOfSend(array $contract): void
    {
        try {
            $admins = $this->db->all("SELECT email, name, notify_contracts FROM users WHERE role = 'venue_admin' AND is_hidden = 0", []);
            $mailer = new Mailer($this->root, $this->db);
            $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::CONTRACTS)) {
                    continue;
                }
                $mailer->sendTemplate(
                    $admin['email'],
                    'Contract sent for signature: ' . ($contract['title'] ?? ''),
                    'contract-signed-admin',
                    [
                        'admin_name'     => $admin['name'],
                        'event'          => 'sent for signature',
                        'contract_title' => (string) ($contract['title'] ?? ''),
                        'signer_name'    => (string) ($contract['counterparty_name'] ?? ''),
                        'signer_email'   => (string) ($contract['counterparty_email'] ?? ''),
                        'detail'         => '',
                        'contract_url'   => $appUrl . '/#contract-' . $contract['id'],
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("Contracts::notifyAdminsOfSend failed: " . $e->getMessage());
        }
    }

    private function notifyAdminsOfVoid(array $contract, string $reason): void
    {
        try {
            $admins = $this->db->all("SELECT email, name, notify_contracts FROM users WHERE role = 'venue_admin' AND is_hidden = 0", []);
            $mailer = new Mailer($this->root, $this->db);
            $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::CONTRACTS)) {
                    continue;
                }
                $mailer->sendTemplate(
                    $admin['email'],
                    'Contract voided: ' . ($contract['title'] ?? ''),
                    'contract-voided',
                    [
                        'admin_name'     => $admin['name'],
                        'contract_title' => (string) ($contract['title'] ?? ''),
                        'reason'         => $reason ?: 'No reason provided',
                        'contract_url'   => $appUrl . '/#contract-' . $contract['id'],
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("Contracts::notifyAdminsOfVoid failed: " . $e->getMessage());
        }
    }

    private function clientIp(): ?string
    {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            return substr(trim(explode(',', $xff)[0]), 0, 45);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function loadSections(int $contractId): array
    {
        return $this->db->all('SELECT * FROM contract_sections WHERE contract_id = ? ORDER BY sort_order, id', [$contractId]);
    }

    /** Cast the tinyint `included` column to a real bool for the renderer. */
    private function boolIncluded(array $sections): array
    {
        return array_map(static fn ($s) => $s + ['included' => (int) $s['included'] === 1], $sections);
    }

    private function lockedSectionIds(int $contractId): array
    {
        $ids = [];
        foreach ($this->db->all('SELECT id FROM contract_sections WHERE contract_id = ? AND is_locked = 1', [$contractId]) as $r) {
            $ids[(int) $r['id']] = true;
        }
        return $ids;
    }

    private function activeTemplates(): array
    {
        return $this->db->all('SELECT id, name, contract_type, description FROM contract_templates WHERE is_active = 1 ORDER BY name');
    }

    /**
     * Lightweight deal-risk hints surfaced in the editor (proposal §9).
     * @return list<array{level:string,message:string}>
     */
    private function riskFlags(array $contract, array $sections): array
    {
        $flags = [];
        $included = [];
        foreach ($sections as $s) {
            if ($s['included']) {
                $included[$s['module_key'] ?? ''] = true;
            }
        }
        $num = static fn ($v) => is_numeric($v) ? (float) $v : 0.0;

        if ($num($contract['deposit_amount']) <= 0 && (isset($included['flat_rental']) || $contract['contract_type'] === 'private_event')) {
            $flags[] = ['level' => 'medium', 'message' => 'No deposit on a rental — venues become nonprofits this way.'];
        }
        if (!isset($included['cancellation'])) {
            $flags[] = ['level' => 'high', 'message' => 'No cancellation clause included.'];
        }
        if (isset($included['all_ages']) && !isset($included['security'])) {
            $flags[] = ['level' => 'high', 'message' => 'All-ages event without a security clause.'];
        }
        if (!isset($included['indemnification'])) {
            $flags[] = ['level' => 'medium', 'message' => 'No indemnification clause included.'];
        }
        if ($num($contract['guarantee_amount']) > 0 && !isset($included['cancellation'])) {
            $flags[] = ['level' => 'high', 'message' => 'Guarantee on the line with no cancellation terms.'];
        }
        return $flags;
    }
}
