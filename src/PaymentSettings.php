<?php
declare(strict_types=1);

namespace Panic;

use Panic\Payments\PaymentProviders;

/**
 * Global payment configuration:
 *   GET   /api/payment-settings   -> active provider, currency, and which
 *                                    providers have their secret keys configured
 *   PATCH /api/payment-settings   -> switch the active provider / default currency
 *
 * Venue-admin only (global capability: manage_users — the admin role gate).
 *
 * Secret keys NEVER leave .env: this endpoint reports only *which* provider is
 * active and a boolean per provider indicating whether its required Env keys
 * are present, so the admin UI can warn before switching to an unconfigured
 * provider. It does not read, return, or accept secret values.
 */
final class PaymentSettings extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        // Admin-only surface. manage_users is the venue_admin global gate used
        // across the other admin endpoints.
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'   => $this->show(),
            'PATCH' => $this->update($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(): Response
    {
        $row = $this->db->one('SELECT active_provider, currency, settings_json, updated_at FROM payment_settings ORDER BY id ASC LIMIT 1');
        $active = $row['active_provider'] ?? 'square';
        $currency = $row['currency'] ?? 'USD';

        return $this->ok([
            'active_provider' => $active,
            'currency'        => $currency,
            'updated_at'      => $row['updated_at'] ?? null,
            'providers'       => $this->providerStatus(),
        ]);
    }

    private function update(Request $request): Response
    {
        $b = $request->body();

        $available = PaymentProviders::available();
        $active = $b['active_provider'] ?? null;
        if ($active !== null && !in_array($active, $available, true)) {
            return Response::json(['error' => 'Unknown payment provider'], 422);
        }

        $currency = $b['currency'] ?? null;
        if ($currency !== null) {
            $currency = strtoupper(trim((string) $currency));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                return Response::json(['error' => 'Currency must be a 3-letter ISO code'], 422);
            }
        }

        $existing = $this->db->one('SELECT id, active_provider, currency FROM payment_settings ORDER BY id ASC LIMIT 1');
        $newActive = $active ?? ($existing['active_provider'] ?? 'square');
        $newCurrency = $currency ?? ($existing['currency'] ?? 'USD');

        if ($existing) {
            $this->db->run(
                'UPDATE payment_settings SET active_provider = ?, currency = ?, updated_by_user_id = ? WHERE id = ?',
                [$newActive, $newCurrency, $this->userId(), (int) $existing['id']]
            );
        } else {
            $this->db->run(
                'INSERT INTO payment_settings (active_provider, currency, updated_by_user_id) VALUES (?, ?, ?)',
                [$newActive, $newCurrency, $this->userId()]
            );
        }

        return $this->ok([
            'active_provider' => $newActive,
            'currency'        => $newCurrency,
            'providers'       => $this->providerStatus(),
        ]);
    }

    /**
     * Per-provider config status. Reports only whether the Env keys each
     * provider needs are present — never the values themselves.
     *
     * @return array<int,array{key:string,label:string,configured:bool}>
     */
    private function providerStatus(): array
    {
        $env = new Env();
        $has = static fn (string $key): bool => trim((string) $env->get($key, '')) !== '';

        return [
            [
                'key'        => 'stripe',
                'label'      => 'Stripe',
                'configured' => $has('STRIPE_SECRET_KEY') && $has('STRIPE_WEBHOOK_SECRET'),
            ],
            [
                'key'        => 'square',
                'label'      => 'Square',
                'configured' => $has('SQUARE_ACCESS_TOKEN') && $has('SQUARE_LOCATION_ID') && $has('SQUARE_WEBHOOK_SIGNATURE_KEY'),
            ],
        ];
    }
}
