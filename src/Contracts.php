<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Contract / Deal Builder endpoint.
 *
 *   GET    /api/contracts                       list (admin: all; else own/event-scoped)
 *   POST   /api/contracts                       create (standalone = admin only)
 *   GET    /api/contracts/{id}                  full contract incl. preview + missing terms
 *   PATCH  /api/contracts/{id}                  update deal terms / counterparty / variables
 *   DELETE /api/contracts/{id}
 *   POST   /api/contracts/{id}/render           render a new immutable version
 *   GET    /api/contracts/{id}/versions/{vid}   fetch a past version's HTML
 *   POST   /api/contracts/{id}/status           change workflow status
 *   POST   /api/contracts/{id}/apply-template   (re)build sections from a template
 *   POST   /api/contracts/{id}/reevaluate       re-run smart module selection
 *   POST   /api/contracts/{id}/sections         add a section (module or custom)
 *   PATCH  /api/contracts/{id}/sections         bulk update sections (include / order / body)
 *   DELETE /api/contracts/{id}/sections/{sid}   remove a section
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
                default          => $this->notFound(),
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
              . ' --margin-top 0.75in --margin-right 0.75in'
              . ' --margin-bottom 0.75in --margin-left 0.75in'
              . ' --encoding utf-8 --disable-smart-shrinking'
              . ' - -';   // stdin → stdout

        $proc = proc_open("$bin $args", [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            return Response::json(['error' => 'PDF renderer unavailable'], 503);
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
            return Response::json(['error' => 'PDF generation failed'], 500);
        }

        $safe = preg_replace('/[^\w\s-]/', '', (string) ($contract['title'] ?? 'contract'));
        $safe = trim(preg_replace('/\s+/', '-', $safe)) ?: 'contract';

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}.pdf\"",
            'Content-Length'      => (string) strlen($pdf),
            'Cache-Control'       => 'private, no-cache',
        ]);
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
