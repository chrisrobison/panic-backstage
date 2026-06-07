<?php
declare(strict_types=1);

namespace Panic\Payments;

use Panic\Database;
use Panic\Env;

/**
 * Registry / factory for payment providers.
 *
 * - active() resolves the provider currently selected in payment_settings.
 *   Used by the checkout flow when starting a new purchase.
 * - byKey() resolves a provider by its stored key. Used by the webhook and
 *   refund flows, where the relevant provider is whatever processed the order
 *   (ticket_orders.provider) — NOT necessarily the currently-active one, so
 *   switching providers never breaks in-flight or historical orders.
 */
final class PaymentProviders
{
    /** Default when payment_settings has no row yet (schema default is 'square'). */
    private const DEFAULT_PROVIDER = 'square';

    /**
     * The provider selected in payment_settings.active_provider.
     *
     * Falls back to the schema default if the table is empty. Throws if the
     * configured provider key is unknown (misconfiguration should be loud at
     * checkout time, not silently wrong).
     */
    public static function active(Database $db, Env $env): PaymentProvider
    {
        $row = $db->one('SELECT active_provider FROM payment_settings ORDER BY id ASC LIMIT 1');
        $key = is_array($row) ? (string) ($row['active_provider'] ?? '') : '';
        if ($key === '') {
            $key = self::DEFAULT_PROVIDER;
        }

        $provider = self::byKey($key, $env);
        if ($provider === null) {
            throw new \RuntimeException("Unknown active payment provider: '{$key}'.");
        }
        return $provider;
    }

    /**
     * Resolve a provider by key. Returns null for unknown keys so webhook and
     * refund callers can decide how to handle an order with an unrecognized
     * provider, rather than fataling.
     */
    public static function byKey(string $key, Env $env): ?PaymentProvider
    {
        return match (strtolower(trim($key))) {
            'stripe' => new StripeProvider($env),
            'square' => new SquareProvider($env),
            default  => null,
        };
    }

    /** Provider keys this build supports (for settings UIs / validation). */
    public static function available(): array
    {
        return ['stripe', 'square'];
    }
}
