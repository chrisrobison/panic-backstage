<?php
declare(strict_types=1);

namespace Panic;

use Panic\Processes\CenterStage\ProcessBridge;

/**
 * External e-signature provider webhooks.
 *
 *   POST /api/contracts/webhook/{provider}
 *
 * This endpoint is public (no JWT); authenticity is verified by checking
 * the provider's cryptographic signature on the payload before any data is
 * trusted or persisted.
 *
 * Supported providers:
 *   mock         — accepts any JSON; useful for integration testing
 *   dropbox_sign — verifies X-HelloSign-Signature HMAC before processing
 *
 * Event → contract status mapping:
 *   signature_request_viewed     → signer status: viewed
 *   signature_request_signed     → signer status: signed (+ may finalize)
 *   signature_request_all_signed → contract: fully_executed
 *   signature_request_declined   → contract: declined
 *   signature_request_cancelled  → contract: voided
 */
final class ContractWebhooks extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        $provider = (string) ($this->params['provider'] ?? '');

        try {
            $providerImpl = ContractSignatureProviders::make();
        } catch (\Throwable $e) {
            error_log("ContractWebhooks: provider instantiation failed: " . $e->getMessage());
            return Response::json(['error' => 'Provider configuration error'], 500);
        }

        // Collect raw body for signature verification.
        $rawBody = (string) file_get_contents('php://input');
        $headers = $this->allHeaders();

        // Verify webhook authenticity BEFORE trusting any data.
        if (!$providerImpl->verifyWebhook($headers, $rawBody)) {
            ContractAuditLog::appendFromRequest(
                $this->db, 0, 'webhook_verification_failed', null,
                ['provider' => $provider]
            );
            return Response::json(['error' => 'Webhook signature verification failed'], 401);
        }

        // Parse the verified payload.
        $event = $providerImpl->parseWebhook($headers, $rawBody);

        ContractAuditLog::appendFromRequest(
            $this->db, 0, 'webhook_received', null,
            ['provider' => $provider, 'event' => $event['event'] ?? '']
        );

        $this->processEvent($provider, $event, $rawBody);

        // Providers typically expect a 200 with specific bodies.
        // Dropbox Sign requires "Hello API Event Received" in the body.
        if ($provider === 'dropbox_sign') {
            return new Response(
                json_encode(['hash' => 'Hello API Event Received']),
                200,
                ['Content-Type' => 'application/json']
            );
        }

        return Response::json(['ok' => true]);
    }

    // ── Event processing ──────────────────────────────────────────────────────

    private function processEvent(string $provider, array $event, string $rawBody): void
    {
        $envelopeId  = $event['envelope_id']  ?? '';
        $signerEmail = $event['signer_email'] ?? '';
        $eventType   = $event['event']        ?? '';

        if ($envelopeId === '') {
            return;
        }

        // Look up the contract by provider_envelope_id.
        $contract = $this->db->one(
            'SELECT * FROM contracts WHERE provider_envelope_id = ?',
            [$envelopeId]
        );

        if (!$contract) {
            error_log("ContractWebhooks: no contract found for envelope {$envelopeId}");
            return;
        }

        $contractId = (int) $contract['id'];

        match (true) {
            str_contains($eventType, 'signed') && str_contains($eventType, 'all') => $this->handleAllSigned($contractId, $contract),
            str_contains($eventType, 'signed')   => $this->handleSignerSigned($contractId, $signerEmail),
            str_contains($eventType, 'viewed')   => $this->handleSignerViewed($contractId, $signerEmail),
            str_contains($eventType, 'declined') => $this->handleDeclined($contractId, $contract),
            str_contains($eventType, 'cancel')   => $this->handleVoided($contractId),
            default => null,
        };
    }

    private function handleSignerViewed(int $contractId, string $email): void
    {
        $signer = $this->signerByEmail($contractId, $email);
        if ($signer) {
            $this->db->run(
                "UPDATE contract_signers SET status = 'viewed', viewed_at = NOW() WHERE id = ?",
                [(int) $signer['id']]
            );
        }
        $this->db->run(
            "UPDATE contracts SET status = 'viewed' WHERE id = ? AND status = 'sent'",
            [$contractId]
        );
        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'signer_link_opened', $signer ? (int) $signer['id'] : null
        );
    }

    private function handleSignerSigned(int $contractId, string $email): void
    {
        $signer = $this->signerByEmail($contractId, $email);
        $signerId = $signer ? (int) $signer['id'] : null;

        if ($signer) {
            $this->db->run(
                "UPDATE contract_signers SET status = 'signed', signed_at = NOW() WHERE id = ?",
                [$signerId]
            );
        }

        ContractAuditLog::appendFromRequest(
            $this->db, $contractId, 'signer_signed', $signerId,
            ['email' => $email, 'source' => 'webhook']
        );

        // Check if all signed. Exclude 'voided' rows — dead placeholders left
        // by a superseded resend (see Contracts::sendForSignature()) that can
        // never become 'signed' and would otherwise permanently block a
        // resent contract from finalizing.
        $unsigned = $this->db->one(
            "SELECT COUNT(*) AS n FROM contract_signers WHERE contract_id = ? AND status NOT IN ('signed', 'voided')",
            [$contractId]
        );
        if ((int) ($unsigned['n'] ?? 1) === 0) {
            $this->handleAllSigned($contractId, $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]) ?? []);
        } else {
            $this->db->run(
                "UPDATE contracts SET status = 'partially_signed' WHERE id = ? AND status NOT IN ('fully_executed','voided')",
                [$contractId]
            );
        }
    }

    private function handleAllSigned(int $contractId, array $contract): void
    {
        // Attempt to download and store the final signed PDF from the provider.
        try {
            $providerImpl = ContractSignatureProviders::make();
            if ($contract['provider_envelope_id']) {
                $pdfBytes   = $providerImpl->downloadFinalPdf((string) $contract['provider_envelope_id']);
                $pdfService = new ContractPdfService($this->db, $this->root);
                $hash       = $pdfService->hashPdf($pdfBytes);
                $path       = $pdfService->storePdf($contractId, $pdfBytes, 'final');
                $this->db->run(
                    'UPDATE contracts SET final_pdf_path = ?, final_pdf_sha256 = ? WHERE id = ?',
                    [$path, $hash, $contractId]
                );
                ContractAuditLog::appendFromRequest(
                    $this->db, $contractId, 'pdf_hash_created', null, ['sha256' => $hash]
                );
            }
        } catch (\Throwable $e) {
            error_log("ContractWebhooks: PDF download failed for contract {$contractId}: " . $e->getMessage());
            ContractAuditLog::appendFromRequest(
                $this->db, $contractId, 'provider_error', null,
                ['detail' => 'PDF download failed: ' . $e->getMessage()]
            );
        }

        $this->db->run(
            "UPDATE contracts SET status = 'fully_executed', fully_executed_at = NOW() WHERE id = ?",
            [$contractId]
        );

        ContractAuditLog::appendFromRequest($this->db, $contractId, 'contract_fully_executed');

        // Sync event status.
        if (!empty($contract['event_id'])) {
            $eventId = (int) $contract['event_id'];
            $this->db->run(
                "UPDATE events SET status = 'booked' WHERE id = ? AND status IN ('proposed','confirmed')",
                [$eventId]
            );
            log_activity($this->db, $eventId, null, 'contract_signed', ['contract_id' => $contractId]);

            // Resume any Automation process instance waiting on this
            // event's signature (Await Signature). Never let a bug here
            // block the real webhook flow the e-signature provider expects
            // a 200 response from.
            try {
                ProcessBridge::onContractSigned($this->db, $eventId, $contractId);
            } catch (\Throwable $e) {
                error_log("ProcessBridge::onContractSigned failed for contract {$contractId}: " . $e->getMessage());
            }
        }
    }

    private function handleDeclined(int $contractId, array $contract): void
    {
        $this->db->run(
            "UPDATE contracts SET status = 'declined' WHERE id = ?",
            [$contractId]
        );
        ContractAuditLog::appendFromRequest($this->db, $contractId, 'signer_declined');

        if (!empty($contract['event_id'])) {
            log_activity($this->db, (int) $contract['event_id'], null, 'contract_declined', ['contract_id' => $contractId]);
        }
    }

    private function handleVoided(int $contractId): void
    {
        $this->db->run(
            "UPDATE contracts SET status = 'voided', voided_at = NOW() WHERE id = ?",
            [$contractId]
        );
        ContractAuditLog::appendFromRequest($this->db, $contractId, 'contract_voided');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function signerByEmail(int $contractId, string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM contract_signers WHERE contract_id = ? AND email = ? LIMIT 1',
            [$contractId, $email]
        );
    }

    /** Collect all HTTP request headers into an associative array. */
    private function allHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', ucwords(strtolower(substr($key, 5)), '_'));
                $headers[$name] = $val;
            }
        }
        return $headers;
    }
}
