<?php
declare(strict_types=1);

namespace Panic\ContractSignatureProviders;

use Panic\ContractSignatureProviderInterface;

/**
 * Dropbox Sign (formerly HelloSign) e-signature provider — PLACEHOLDER.
 *
 * This class is wired for the Dropbox Sign REST API but the individual
 * method bodies are marked TODO.  To activate:
 *
 *   1. Set in .env:
 *        SIGNATURE_PROVIDER=dropbox_sign
 *        SIGNATURE_PROVIDER_API_KEY=<your Dropbox Sign API key>
 *        SIGNATURE_WEBHOOK_SECRET=<webhook signing secret from the Dropbox Sign dashboard>
 *
 *   2. Register a webhook endpoint in the Dropbox Sign developer console:
 *        POST https://your-domain.com/api/contracts/webhook/dropbox_sign
 *
 *   3. Implement the TODO methods below using the Dropbox Sign REST API.
 *        API reference: https://developers.hellosign.com/api/reference/
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Key API calls to implement:
 *
 *   createEnvelope  → POST /signature_request/send
 *                     Body: signers[] = [{name, email_address}], files[] = PDF bytes
 *
 *   getEnvelopeStatus → GET /signature_request/{id}
 *
 *   downloadFinalPdf  → GET /signature_request/files/{id}?file_type=pdf
 *
 *   verifyWebhook     → Compare X-HelloSign-Signature header against
 *                       hash_hmac('sha256', $rawBody, $webhookSecret)
 *
 *   parseWebhook      → JSON body: { "event": { "event_type": "..." }, "signature_request": {...} }
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DropboxSignProvider implements ContractSignatureProviderInterface
{
    private string $apiKey;
    private string $webhookSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) (getenv('SIGNATURE_PROVIDER_API_KEY')  ?: '');
        $this->baseUrl = rtrim(
            (string) (getenv('SIGNATURE_PROVIDER_BASE_URL') ?: 'https://api.hellosign.com/v3'),
            '/'
        );
        // $webhookSecret is read lazily in verifyWebhook() so it picks up
        // any env-var changes (e.g. secret rotation) without restarting the process.
        $this->webhookSecret = '';
    }

    public function createEnvelope(array $contract, array $signers): array
    {
        // TODO: Build a multipart/form-data POST to /signature_request/send.
        //
        // Required fields:
        //   title              → $contract['title']
        //   subject            → "Please sign: {$contract['title']}"
        //   message            → "Please review and sign the attached agreement."
        //   signers[0][name]   → $signers[0]['name']
        //   signers[0][email_address] → $signers[0]['email']
        //   signers[0][order]  → 0
        //   files[]            → rendered contract HTML or pre-rendered PDF bytes
        //   test_mode          → 1  (set to 0 in production)
        //
        // Response on success:
        //   { "signature_request": { "signature_request_id": "...", "signatures": [...] } }
        //
        // Return:
        //   ['envelope_id' => $response['signature_request']['signature_request_id'], 'status' => 'created']

        throw new \RuntimeException(
            'DropboxSignProvider::createEnvelope is not yet implemented. '
            . 'See the TODO block in src/ContractSignatureProviders/DropboxSignProvider.php.'
        );
    }

    public function sendEnvelope(string $envelopeId): array
    {
        // Dropbox Sign sends the request to signers when created, so this is a no-op.
        return ['status' => 'sent', 'sent_at' => date('c')];
    }

    public function getEnvelopeStatus(string $envelopeId): array
    {
        // TODO: GET {$this->baseUrl}/signature_request/{$envelopeId}
        //
        // Normalize the response:
        //   status  → map Dropbox Sign "signing_complete" → 'fully_executed', etc.
        //   signers → array of [email, status, signed_at]

        throw new \RuntimeException(
            'DropboxSignProvider::getEnvelopeStatus is not yet implemented.'
        );
    }

    public function downloadFinalPdf(string $envelopeId): string
    {
        // TODO: GET {$this->baseUrl}/signature_request/files/{$envelopeId}?file_type=pdf
        //   → returns raw PDF bytes (Content-Type: application/pdf)
        //
        // Use $this->apiRequest('GET', "/signature_request/files/{$envelopeId}?file_type=pdf")

        throw new \RuntimeException(
            'DropboxSignProvider::downloadFinalPdf is not yet implemented.'
        );
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        // Read the secret lazily so secret-rotation picks up without a restart.
        $secret = (string) (getenv('SIGNATURE_WEBHOOK_SECRET') ?: '');

        if ($secret === '') {
            error_log('DropboxSignProvider: SIGNATURE_WEBHOOK_SECRET is not set');
            return false;
        }

        // Dropbox Sign sends HMAC-SHA256 in X-HelloSign-Signature.
        $signature = $headers['X-HelloSign-Signature']
                  ?? $headers['x-hellosign-signature']
                  ?? '';

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(array $headers, string $rawBody): array
    {
        // Dropbox Sign wraps events in:
        //   { "event": { "event_type": "signature_request_signed", "event_time": "...", ... },
        //     "signature_request": { "signature_request_id": "...", "signatures": [...] } }
        $data = json_decode($rawBody, true) ?? [];
        return [
            'event'        => (string) ($data['event']['event_type']                         ?? 'unknown'),
            'envelope_id'  => (string) ($data['signature_request']['signature_request_id']   ?? ''),
            'signer_email' => (string) ($data['signature_request']['signatures'][0]['signer_email_address'] ?? ''),
            'raw'          => $data,
        ];
    }

    // ─── HTTP helper ──────────────────────────────────────────────────────────

    /**
     * Make an authenticated HTTP request to the Dropbox Sign API.
     * Uses HTTP Basic auth: username = $apiKey, password = '' (empty).
     *
     * TODO: Implement using curl or stream contexts.
     *
     * @return array Decoded JSON response body.
     * @throws \RuntimeException on HTTP or parse error.
     */
    private function apiRequest(string $method, string $path, array $data = []): array
    {
        // Example curl implementation (uncomment and adjust when ready):
        //
        // $ch = curl_init($this->baseUrl . $path);
        // curl_setopt_array($ch, [
        //     CURLOPT_USERPWD        => $this->apiKey . ':',
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        // ]);
        // if ($method === 'POST') {
        //     curl_setopt($ch, CURLOPT_POST, true);
        //     curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        // }
        // $body = curl_exec($ch);
        // $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch);
        // if ($body === false || $code >= 400) {
        //     throw new \RuntimeException("Dropbox Sign API error {$code}: {$body}");
        // }
        // return json_decode($body, true) ?? [];

        throw new \RuntimeException('DropboxSignProvider::apiRequest is not yet implemented.');
    }
}
