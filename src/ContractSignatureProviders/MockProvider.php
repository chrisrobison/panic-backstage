<?php
declare(strict_types=1);

namespace Panic\ContractSignatureProviders;

use Panic\ContractSignatureProviderInterface;

/**
 * Mock / dev signature provider.
 *
 * Simulates the full signing lifecycle without any external API calls or
 * outbound network traffic.  Suitable for local development and automated tests.
 *
 * Enable with: SIGNATURE_PROVIDER=mock in .env
 *
 * All operations succeed immediately.  Webhook payloads can be sent manually
 * (e.g. via curl) to the contract webhook endpoint to simulate provider events.
 */
final class MockProvider implements ContractSignatureProviderInterface
{
    public function createEnvelope(array $contract, array $signers): array
    {
        return [
            'envelope_id' => 'mock_' . bin2hex(random_bytes(8)),
            'status'      => 'created',
        ];
    }

    public function sendEnvelope(string $envelopeId): array
    {
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
        // Return a minimal placeholder PDF; the real signed PDF is generated locally.
        return '%PDF-1.4 mock-signed-contract ' . $envelopeId;
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        // Mock provider accepts all webhooks in dev — no signature verification.
        return true;
    }

    public function parseWebhook(array $headers, string $rawBody): array
    {
        $data = json_decode($rawBody, true) ?? [];
        return [
            'event'       => (string) ($data['event']       ?? 'unknown'),
            'envelope_id' => (string) ($data['envelope_id'] ?? ''),
            'raw'         => $data,
        ];
    }
}
