<?php
declare(strict_types=1);

namespace Panic\ContractSignatureProviders;

use Panic\ContractSignatureProviderInterface;

/**
 * Internal signature provider (default).
 *
 * Manages the signing workflow entirely within Panic Backstage using secure
 * one-time magic links.  No external service calls.
 *
 * The "envelope" maps 1-to-1 with the contract; the envelope ID is the string
 * "internal_{contract_id}".  Actual signing happens via the public
 * /api/signing/{token} endpoint, not through this class.
 *
 * Enable with: SIGNATURE_PROVIDER=internal  (or leave SIGNATURE_PROVIDER unset)
 *
 * Suitable for:
 *   - Internal testing
 *   - Lightweight venue rental agreements
 *   - Situations where a third-party e-sign provider is not required
 *
 * Not a substitute for legal advice.  For enterprise e-sign compliance
 * (ESIGN Act, eIDAS, 21 CFR Part 11, etc.) consider a certified provider.
 */
final class InternalProvider implements ContractSignatureProviderInterface
{
    public function createEnvelope(array $contract, array $signers): array
    {
        return [
            'envelope_id' => 'internal_' . $contract['id'],
            'status'      => 'created',
        ];
    }

    public function sendEnvelope(string $envelopeId): array
    {
        // Signing emails are sent by ContractSigningService, not by this provider.
        return [
            'status'  => 'sent',
            'sent_at' => date('c'),
        ];
    }

    public function getEnvelopeStatus(string $envelopeId): array
    {
        return [
            'status'  => 'sent',
            'signers' => [],
        ];
    }

    public function downloadFinalPdf(string $envelopeId): string
    {
        // PDF is generated locally by ContractPdfService.
        // This method is not used by the internal provider.
        return '';
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        // Internal provider has no external webhooks.
        return false;
    }

    public function parseWebhook(array $headers, string $rawBody): array
    {
        return [];
    }
}
