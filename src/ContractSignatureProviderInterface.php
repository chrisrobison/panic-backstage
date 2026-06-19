<?php
declare(strict_types=1);

namespace Panic;

/**
 * Adapter interface for e-signature providers.
 *
 * Implementations live in src/ContractSignatureProviders/:
 *   InternalProvider   — magic-link flow built into Panic Backstage (default)
 *   MockProvider       — simulates the workflow for local dev/tests; no HTTP calls
 *   DropboxSignProvider — placeholder wired for Dropbox Sign REST API
 *
 * The active implementation is resolved by ContractSignatureProviders::make()
 * using the SIGNATURE_PROVIDER environment variable.
 */
interface ContractSignatureProviderInterface
{
    /**
     * Create a signing "envelope" with the provider (contract + signer list).
     *
     * @param array $contract  Full contract row from the database.
     * @param array $signers   Rows from contract_signers for this contract.
     * @return array{envelope_id:string,status:string}
     */
    public function createEnvelope(array $contract, array $signers): array;

    /**
     * Activate / send the envelope so signers are notified by the provider.
     * For providers that send on creation this is a no-op.
     *
     * @return array{status:string,sent_at:string}
     */
    public function sendEnvelope(string $envelopeId): array;

    /**
     * Poll the current status of an envelope.
     *
     * @return array{status:string,signers:array}
     */
    public function getEnvelopeStatus(string $envelopeId): array;

    /**
     * Download the final, fully-signed PDF bytes from the provider.
     *
     * @throws \RuntimeException if download fails.
     */
    public function downloadFinalPdf(string $envelopeId): string;

    /**
     * Verify that an inbound webhook payload originated from the provider.
     *
     * @param array  $headers  All HTTP request headers (key => value).
     * @param string $rawBody  The raw (un-decoded) request body.
     */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    /**
     * Parse a verified webhook payload into a normalized structure.
     *
     * @return array{event:string,envelope_id:string,...}
     */
    public function parseWebhook(array $headers, string $rawBody): array;
}
