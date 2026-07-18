<?php
declare(strict_types=1);

namespace Panic;

/**
 * Public signing endpoint — no JWT auth required.
 *
 * These routes are accessible only with a valid, unexpired, single-use
 * signing token issued when the contract was sent for signatures.
 *
 *   GET  /api/signing/{token}          load contract for the signing page
 *   POST /api/signing/{token}/viewed   record that the signer opened the link
 *   POST /api/signing/{token}/sign     submit electronic signature
 *   POST /api/signing/{token}/decline  decline to sign
 *   GET  /api/signing/{token}/download download the final signed PDF once fully executed
 *
 * Security:
 *   - Only the sha256 hash of the token is stored in the DB.
 *   - The raw token is compared via hash_equals(sha256(input), stored_hash).
 *   - Tokens expire after SIGNATURE_TOKEN_TTL_HOURS (default 168 h = 7 days).
 *   - A token is invalidated (nulled) after signing or declining.
 *   - Voided and fully-executed contracts cannot be signed.
 *   - Signer consent must be confirmed before a signature is accepted.
 *   - The download action uses a *separate* token (contract_signers.
 *     download_token_hash) minted once per signer when the contract reaches
 *     fully_executed — the signing token above is single-use and already
 *     nulled out by the time a signer's own signature completes it, so it
 *     can't double as the link for the "your signed agreement is ready"
 *     email. See finalizeContract() / notifySignersOfExecution().
 */
final class ContractSigningEndpoint extends BaseEndpoint
{
    /** Contract statuses that cannot be signed (immutable or terminal). */
    private const UNSIGNABLE = ['fully_executed', 'voided', 'canceled', 'superseded', 'declined'];

    public function handle(Request $request): Response
    {
        $token  = (string) ($this->params['token'] ?? '');
        $action = (string) ($this->params['action'] ?? '');

        if ($token === '') {
            return Response::json(['error' => 'Invalid signing link'], 400);
        }

        return match ($action) {
            ''         => $this->loadForSigning($token, $request),
            'viewed'   => $this->markViewed($token, $request),
            'sign'     => $this->sign($token, $request),
            'decline'  => $this->decline($token, $request),
            'download' => $this->downloadFinal($token, $request),
            default    => Response::json(['error' => 'Unknown action'], 404),
        };
    }

    // ── Load contract for signing page ────────────────────────────────────────

    /**
     * GET /api/signing/{token}
     *
     * Validates the token, returns the contract HTML + signer info.
     * Deliberately excludes admin-only fields.
     */
    private function loadForSigning(string $token, Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        [$signer, $contract, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        // Build the rendered HTML from the current (or latest) version snapshot.
        $html = $this->contractHtmlForSigner((int) $contract['id']);

        ContractAuditLog::appendFromRequest(
            $this->db,
            (int) $contract['id'],
            'signer_link_opened',
            (int) $signer['id'],
            ['email' => $signer['email']]
        );

        return $this->ok([
            'contract' => [
                'id'    => (int) $contract['id'],
                'title' => $contract['title'],
            ],
            'html'    => $html,
            'signer'  => [
                'id'      => (int) $signer['id'],
                'role'    => $signer['role'],
                'name'    => $signer['name'],
                'email'   => $signer['email'],
                'company' => $signer['company'],
                'status'  => $signer['status'],
            ],
            'expires_at' => $signer['token_expires_at'],
        ]);
    }

    // ── Mark viewed ───────────────────────────────────────────────────────────

    /**
     * POST /api/signing/{token}/viewed
     *
     * Records that the signer has opened and viewed the contract.
     * Idempotent — safe to call multiple times.
     */
    private function markViewed(string $token, Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        [$signer, $contract, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        $contractId = (int) $contract['id'];
        $signerId   = (int) $signer['id'];

        // Only update viewed_at once.
        if (!$signer['viewed_at']) {
            $this->db->run(
                "UPDATE contract_signers SET status = 'viewed', viewed_at = NOW() WHERE id = ?",
                [$signerId]
            );

            // If contract is still in 'sent' state, advance it to 'viewed'.
            if ($contract['status'] === 'sent') {
                $this->db->run(
                    "UPDATE contracts SET status = 'viewed' WHERE id = ? AND status = 'sent'",
                    [$contractId]
                );
            }

            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'signer_consented', $signerId
            );
        }

        return $this->ok(['ok' => true]);
    }

    // ── Sign ──────────────────────────────────────────────────────────────────

    /**
     * POST /api/signing/{token}/sign
     *
     * Body: { consent: true, signature_text: "Jane Doe", signature_image?: "<base64 PNG>" }
     *
     * Records the signature, advances contract status, generates final PDF when all
     * required signers have signed.
     */
    private function sign(string $token, Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        [$signer, $contract, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        $signerId   = (int) $signer['id'];
        $contractId = (int) $contract['id'];

        // Already signed?
        if ($signer['status'] === 'signed') {
            return Response::json(['error' => 'This contract has already been signed.'], 409);
        }

        $body = $request->body();

        // Require explicit consent.
        if (empty($body['consent'])) {
            return Response::json([
                'error' => 'You must agree to use electronic records and signatures before signing.',
            ], 422);
        }

        // Require at least a typed name or drawn image.
        $sigText  = trim((string) ($body['signature_text'] ?? ''));
        $sigImage = trim((string) ($body['signature_image'] ?? ''));

        if ($sigText === '' && $sigImage === '') {
            return Response::json(['error' => 'A signature (typed name or drawn image) is required.'], 422);
        }

        // Save drawn signature image if provided (base64 PNG data URI).
        $imagePath = null;
        if ($sigImage !== '') {
            $imagePath = $this->saveSigImage($contractId, $signerId, $sigImage);
        }

        // Record the signature.
        $ip = ContractAuditLog::class; // just referencing for side-effect; get IP separately
        $ip = $this->clientIp();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $this->db->run(
            "UPDATE contract_signers
             SET status = 'signed', signed_at = NOW(), signature_text = ?,
                 signature_image_path = ?, ip_address = ?, user_agent = ?,
                 signing_token_hash = NULL, token_expires_at = NULL
             WHERE id = ?",
            [$sigText ?: null, $imagePath, $ip, $ua, $signerId]
        );

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'signer_signed', $signerId,
            ['role' => $signer['role'], 'email' => $signer['email']]
        );

        // Advance contract status.
        $this->advanceContractStatus($contractId, $signer['role']);

        return $this->ok(['ok' => true, 'message' => 'Thank you — your signature has been recorded.']);
    }

    // ── Decline ───────────────────────────────────────────────────────────────

    /**
     * POST /api/signing/{token}/decline
     *
     * Body: { reason?: "..." }
     */
    private function decline(string $token, Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        [$signer, $contract, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        $signerId   = (int) $signer['id'];
        $contractId = (int) $contract['id'];

        if ($signer['status'] === 'signed') {
            return Response::json(['error' => 'Contract is already signed; it cannot be declined.'], 409);
        }

        $reason = trim((string) ($request->body('reason') ?? ''));

        $this->db->run(
            "UPDATE contract_signers
             SET status = 'declined', declined_at = NOW(),
                 signing_token_hash = NULL, token_expires_at = NULL
             WHERE id = ?",
            [$signerId]
        );

        $this->db->run(
            "UPDATE contracts SET status = 'declined' WHERE id = ?",
            [$contractId]
        );

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'signer_declined', $signerId,
            array_filter(['reason' => $reason, 'email' => $signer['email']])
        );

        // Notify admins.
        $this->notifyAdmins($contract, $signer, 'declined', $reason);

        return $this->ok(['ok' => true]);
    }

    // ── Download final signed PDF ──────────────────────────────────────────────

    /**
     * GET /api/signing/{token}/download
     *
     * Public counterpart to Contracts::downloadFinalPdf() (which requires a
     * JWT + manage_contracts). Gated by a dedicated download token minted
     * once per signer in notifySignersOfExecution() — deliberately *not* the
     * signing_token_hash above, which is single-use and already nulled out
     * by the time this signer's own signature completed the contract.
     */
    private function downloadFinal(string $token, Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        if (strlen($token) < 32) {
            return Response::json(['error' => 'Invalid download link'], 400);
        }

        $hash   = hash('sha256', $token);
        $signer = $this->db->one(
            'SELECT cs.id, cs.contract_id, cs.download_token_expires_at
             FROM contract_signers cs WHERE cs.download_token_hash = ? LIMIT 1',
            [$hash]
        );
        if (!$signer) {
            return Response::json(['error' => 'This download link is invalid.'], 404);
        }
        if ($signer['download_token_expires_at'] && db_timestamp_to_epoch((string) $signer['download_token_expires_at']) < time()) {
            return Response::json(['error' => 'This download link has expired. Please contact the venue for a copy.'], 410);
        }

        $contract = $this->db->one('SELECT id, title, final_pdf_path FROM contracts WHERE id = ?', [(int) $signer['contract_id']]);
        $path     = (string) ($contract['final_pdf_path'] ?? '');
        if ($path === '' || !is_file($this->root . '/' . $path)) {
            return Response::json(['error' => 'The final PDF is not available yet.'], 404);
        }

        $pdf  = (string) file_get_contents($this->root . '/' . $path);
        $safe = preg_replace('/[^\w\s-]/', '', (string) ($contract['title'] ?? 'contract'));
        $safe = trim(preg_replace('/\s+/', '-', (string) $safe)) ?: 'contract';

        ContractAuditLog::appendFromRequest(
            $this->db, (int) $contract['id'], 'contract_previewed', (int) $signer['id'],
            ['downloaded_via' => 'signer_email_link']
        );

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}-signed.pdf\"",
            'Content-Length'      => (string) strlen($pdf),
            'Cache-Control'       => 'private, no-cache',
        ]);
    }

    // ── Token resolution ──────────────────────────────────────────────────────

    /**
     * Hash the raw token, look up the signer, validate expiry + status.
     *
     * @return array{0:array|null,1:array|null,2:string|null}
     *         [signer_row, contract_row, error_message]
     */
    private function resolveToken(string $token): array
    {
        if (strlen($token) < 32) {
            return [null, null, 'Invalid signing link.'];
        }

        $hash   = hash('sha256', $token);
        $signer = $this->db->one(
            'SELECT * FROM contract_signers WHERE signing_token_hash = ? LIMIT 1',
            [$hash]
        );

        if (!$signer) {
            return [null, null, 'This signing link is invalid or has already been used.'];
        }

        // Check expiry.
        if ($signer['token_expires_at'] && db_timestamp_to_epoch((string) $signer['token_expires_at']) < time()) {
            $this->db->run(
                "UPDATE contract_signers SET status = 'expired' WHERE id = ?",
                [(int) $signer['id']]
            );
            return [null, null, 'This signing link has expired. Please contact the venue to request a new link.'];
        }

        // Signer terminal states.
        if (in_array($signer['status'], ['signed', 'declined', 'voided', 'expired'], true)) {
            return [null, null, 'This signing link is no longer active.'];
        }

        $contract = $this->db->one(
            'SELECT id, title, status, event_id FROM contracts WHERE id = ?',
            [(int) $signer['contract_id']]
        );

        if (!$contract) {
            return [null, null, 'Contract not found.'];
        }

        if (in_array($contract['status'], self::UNSIGNABLE, true)) {
            return [null, null, 'This contract is no longer open for signatures.'];
        }

        return [$signer, $contract, null];
    }

    // ── Status advancement ────────────────────────────────────────────────────

    private function advanceContractStatus(int $contractId, string $signerRole): void
    {
        // Exclude 'voided' rows: those are dead placeholders left behind by a
        // prior "send for signature" that got superseded by a resend (see
        // Contracts::sendForSignature(), which voids the old pending signer
        // row and inserts a fresh one). A voided row can never become
        // 'signed', so counting it here would permanently block any
        // resent contract from ever reaching 'fully_executed' even after
        // every real, active signer has signed.
        $allSigners = $this->db->all(
            "SELECT id, role, status FROM contract_signers WHERE contract_id = ? AND status != 'voided'",
            [$contractId]
        );

        $total    = count($allSigners);
        $signed   = 0;
        $hasVenue = false;

        foreach ($allSigners as $s) {
            if ($s['status'] === 'signed') {
                $signed++;
            }
            if ($s['role'] === 'venue' && $s['status'] === 'signed') {
                $hasVenue = true;
            }
        }

        $clientSigners = array_filter($allSigners, static fn($s) => $s['role'] !== 'venue');
        $clientSigned  = array_filter($clientSigners, static fn($s) => $s['status'] === 'signed');

        $venueSigners  = array_filter($allSigners, static fn($s) => $s['role'] === 'venue');
        $venueSigned   = array_filter($venueSigners, static fn($s) => $s['status'] === 'signed');

        if ($signed === $total) {
            // All signed → fully executed.
            $this->finalizeContract($contractId);
            return;
        }

        // Determine interim status.
        if (count($clientSigned) === count($clientSigners) && count($clientSigners) > 0 && count($venueSigners) > 0) {
            // All client signers done, venue still pending.
            $newStatus = 'signed_by_client';
        } elseif ($signed > 0) {
            $newStatus = 'partially_signed';
        } else {
            return; // shouldn't happen
        }

        $this->db->run(
            'UPDATE contracts SET status = ? WHERE id = ?',
            [$newStatus, $contractId]
        );

        // Log event activity.
        $contract = $this->db->one('SELECT event_id FROM contracts WHERE id = ?', [$contractId]);
        if ($contract && $contract['event_id']) {
            log_activity($this->db, (int) $contract['event_id'], null, "contract {$newStatus}", ['contract_id' => $contractId]);
        }

        // Notify admins of partial signing.
        $this->notifyAdminsOfStatus($contractId, $newStatus);
    }

    private function finalizeContract(int $contractId): void
    {
        $this->db->run(
            "UPDATE contracts SET status = 'fully_executed', fully_executed_at = NOW() WHERE id = ?",
            [$contractId]
        );

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'contract_fully_executed'
        );

        // Generate + store the final signed PDF.
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
            error_log("ContractSigningEndpoint: final PDF generation failed for contract {$contractId}: " . $e->getMessage());
            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'provider_error', null,
                ['detail' => 'PDF generation failed: ' . $e->getMessage()]
            );
        }

        // Update linked event status.
        $contract = $this->db->one('SELECT event_id, title FROM contracts WHERE id = ?', [$contractId]);
        if ($contract && $contract['event_id']) {
            $eventId = (int) $contract['event_id'];
            $this->db->run(
                "UPDATE events SET status = 'booked' WHERE id = ? AND status IN ('proposed','confirmed')",
                [$eventId]
            );
            log_activity($this->db, $eventId, null, 'contract_signed', ['contract_id' => $contractId]);
        }

        // Notify admins + all signers of fully executed contract.
        $this->notifyAdminsOfStatus($contractId, 'fully_executed');
        $this->notifySignersOfExecution($contractId);
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    private function notifyAdmins(array $contract, array $signer, string $event, string $detail = ''): void
    {
        try {
            $admins = $this->db->all(
                "SELECT email, name, notify_contracts FROM users WHERE role = 'venue_admin' AND is_hidden = 0",
                []
            );
            $mailer  = new Mailer($this->root, $this->db);
            $appUrl  = rtrim((string) (getenv('APP_URL') ?: ''), '/');

            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::CONTRACTS)) {
                    continue;
                }
                $mailer->sendTemplate(
                    $admin['email'],
                    "Contract {$event}: " . ($contract['title'] ?? 'Contract'),
                    'contract-signed-admin',
                    [
                        'admin_name'    => $admin['name'],
                        'event'         => $event,
                        'contract_title'=> (string) ($contract['title'] ?? ''),
                        'signer_name'   => (string) ($signer['name']  ?? ''),
                        'signer_email'  => (string) ($signer['email'] ?? ''),
                        'detail'        => $detail,
                        'contract_url'  => $appUrl . '/#contract-' . $contract['id'],
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("ContractSigningEndpoint::notifyAdmins failed: " . $e->getMessage());
        }
    }

    private function notifyAdminsOfStatus(int $contractId, string $status): void
    {
        $contract = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        if (!$contract) {
            return;
        }
        $signers = $this->db->all(
            "SELECT * FROM contract_signers WHERE contract_id = ? AND status = 'signed' ORDER BY signed_at DESC LIMIT 1",
            [$contractId]
        );
        $lastSigner = $signers[0] ?? ['name' => 'Unknown', 'email' => ''];
        $this->notifyAdmins($contract, $lastSigner, $status);
    }

    private function notifySignersOfExecution(int $contractId): void
    {
        try {
            $contract = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
            $signers  = $this->db->all(
                'SELECT * FROM contract_signers WHERE contract_id = ?',
                [$contractId]
            );
            $mailer  = new Mailer($this->root, $this->db);
            $appUrl  = rtrim((string) (getenv('APP_URL') ?: ''), '/');
            $ttlDays = max(1, (int) (getenv('CONTRACT_DOWNLOAD_TOKEN_TTL_DAYS') ?: 365));

            foreach ($signers as $signer) {
                if (empty($signer['email'])) {
                    continue;
                }

                // Signers have no backstage account, so this can't link
                // straight at the JWT-protected /api/contracts/{id}/download
                // admin endpoint (that just shows them a bare "Authentication
                // required" JSON error). Mint a dedicated, longer-lived
                // download token instead — the signing token this signer used
                // is already nulled out from the moment they signed.
                $rawToken  = $this->auth->generateToken(48);
                $tokenHash = $this->auth->hashToken($rawToken);
                $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400);
                $this->db->run(
                    'UPDATE contract_signers SET download_token_hash = ?, download_token_expires_at = ? WHERE id = ?',
                    [$tokenHash, $expiresAt, $signer['id']]
                );

                $mailer->sendTemplate(
                    $signer['email'],
                    'Your signed agreement is ready: ' . ($contract['title'] ?? 'Contract'),
                    'contract-fully-executed',
                    [
                        'signer_name'   => (string) ($signer['name'] ?? ''),
                        'contract_title'=> (string) ($contract['title'] ?? ''),
                        'signed_date'   => date('F j, Y'),
                        'download_url'  => $appUrl . '/api/signing/' . $rawToken . '/download',
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("ContractSigningEndpoint::notifySignersOfExecution failed: " . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Return the HTML of the contract's latest rendered version (no admin fields). */
    private function contractHtmlForSigner(int $contractId): string
    {
        // Prefer the latest rendered version snapshot.
        $version = $this->db->one(
            'SELECT rendered_html FROM contract_versions WHERE contract_id = ? ORDER BY version_number DESC LIMIT 1',
            [$contractId]
        );
        if ($version && !empty($version['rendered_html'])) {
            return (string) $version['rendered_html'];
        }

        // Fall back to live render if no version has been generated yet.
        $contract = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        if (!$contract) {
            return '';
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
        return ContractRenderer::render($contract, $sections, $ctx, $event, $venue)['html'];
    }

    /** Save a base64 PNG signature image and return the relative storage path. */
    private function saveSigImage(int $contractId, int $signerId, string $base64): ?string
    {
        // Strip the data URI prefix: "data:image/png;base64,..."
        $raw = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $base64);
        $bytes = base64_decode($raw, true);
        if ($bytes === false || strlen($bytes) < 8) {
            return null;
        }

        $dir = $this->root . '/storage/contracts/' . $contractId . '/signatures';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'sig_' . $signerId . '_' . time() . '.png';
        file_put_contents($dir . '/' . $filename, $bytes);
        return 'storage/contracts/' . $contractId . '/signatures/' . $filename;
    }

    /** Best-effort signer IP for the e-signature audit trail. See Request::clientIp(). */
    private function clientIp(): ?string
    {
        return Request::clientIp();
    }
}
