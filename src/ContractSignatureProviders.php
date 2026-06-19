<?php
declare(strict_types=1);

namespace Panic;

/**
 * Factory for ContractSignatureProviderInterface implementations.
 *
 * Reads SIGNATURE_PROVIDER from the environment:
 *   internal     — magic-link flow built into Panic Backstage (default)
 *   mock         — dev/test simulator; no network calls
 *   dropbox_sign — Dropbox Sign REST API (placeholder, needs implementation)
 *   docusign     — not yet implemented; throws if selected
 */
final class ContractSignatureProviders
{
    public static function make(): ContractSignatureProviderInterface
    {
        $provider = strtolower(trim((string) (getenv('SIGNATURE_PROVIDER') ?: 'internal')));

        return match ($provider) {
            'mock'         => new ContractSignatureProviders\MockProvider(),
            'dropbox_sign' => new ContractSignatureProviders\DropboxSignProvider(),
            'docusign'     => throw new \RuntimeException('DocuSign provider is not yet implemented. Set SIGNATURE_PROVIDER=internal or dropbox_sign.'),
            default        => new ContractSignatureProviders\InternalProvider(),
        };
    }
}
