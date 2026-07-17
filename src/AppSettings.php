<?php
declare(strict_types=1);

namespace Panic;

/**
 * App shell branding + the small set of venue contact/social fields that are
 * safe to expose in a web form. Backs Admin > App Settings.
 *
 *   GET /api/app-settings   any authenticated user (the shell needs the
 *                           brand name/logo on every page load, same as
 *                           /api/me and /api/nav-items)
 *   PUT /api/app-settings   manage_settings (venue_admin only)
 *
 * Two distinct stores, one form:
 *  - `settings` (brand_name, logo_url) → the app_settings DB singleton. These
 *    have no existing home: brand_name is deliberately separate from
 *    venues.name (a venue can be "Mabuhay Gardens" while wanting the app
 *    itself branded "Mabuhay Backstage"), and there's no logo column on
 *    venues at all.
 *  - `env` (venue_email, manager_name/email/phone, hashtags, tiktok_handle,
 *    press_email) → an explicit allow-listed subset of the venue-identity env
 *    keys, rewritten via Env::updateKeys() (see src/Env.php) — but NOT into
 *    the real root .env. That file is owner-only (holds DB creds, JWT
 *    secret, payment/API tokens) and isn't writable by the PHP-FPM user by
 *    design; this writes to storage/config/app-settings.env instead, a
 *    dedicated overlay file loaded after .env (see Kernel::boot()) so its
 *    values win. That gives this endpoint zero filesystem access to any
 *    actual secret, even in principle — not just "we chose not to expose
 *    it," but "the OS won't let this code path touch that file." Deliberately
 *    excludes VENUE_NAME / VENUE_CITY / VENUE_STATE / VENUE_WEBSITE, which
 *    already have a DB-backed editor at Admin > Venue
 *    (venues.name/city/state/website_url) — duplicating them here would just
 *    give two disagreeing places to edit "the venue name". See the ENV_KEYS
 *    allow-list below, which is the only mapping Env::updateKeys() is ever
 *    called with — no arbitrary key ever reaches the writer.
 */
final class AppSettings extends BaseEndpoint
{
    /** internal field name => real .env KEY. The only keys this endpoint will ever read or write. */
    private const ENV_KEYS = [
        'venue_email'   => 'VENUE_EMAIL',
        'manager_name'  => 'VENUE_MANAGER_NAME',
        'manager_email' => 'VENUE_MANAGER_EMAIL',
        'manager_phone' => 'VENUE_MANAGER_PHONE',
        'hashtags'      => 'VENUE_HASHTAGS',
        'tiktok_handle' => 'VENUE_TIKTOK_HANDLE',
        'press_email'   => 'VENUE_PRESS_EMAIL',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'   => $this->get(),
            'PUT'   => $this->requireGlobalCapability('manage_settings') ?? $this->put($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function get(): Response
    {
        $row = $this->db->one('SELECT brand_name, logo_url FROM app_settings WHERE id = 1');
        $settings = [
            'brand_name' => $row['brand_name'] ?? '',
            'logo_url'   => $row['logo_url'] ?? '',
        ];

        $env = [];
        foreach (self::ENV_KEYS as $field => $envKey) {
            $env[$field] = (string) (getenv($envKey) ?: '');
        }

        return $this->ok(['settings' => $settings, 'env' => $env]);
    }

    private function put(Request $request): Response
    {
        $incomingSettings = $request->body('settings', []);
        $incomingEnv = $request->body('env', []);
        if (!is_array($incomingSettings) || !is_array($incomingEnv)) {
            return Response::json(['error' => 'settings and env must be objects'], 422);
        }

        $brandName = trim((string) ($incomingSettings['brand_name'] ?? ''));
        $logoUrl = trim((string) ($incomingSettings['logo_url'] ?? ''));
        if (mb_strlen($brandName) > 190) {
            return Response::json(['error' => 'Brand name is too long (190 characters max)'], 422);
        }
        if (mb_strlen($logoUrl) > 500) {
            return Response::json(['error' => 'Logo URL is too long (500 characters max)'], 422);
        }

        $envUpdates = [];
        foreach (self::ENV_KEYS as $field => $envKey) {
            if (!array_key_exists($field, $incomingEnv)) {
                continue;
            }
            $value = trim((string) $incomingEnv[$field]);
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                return Response::json(['error' => "$field may not contain a newline"], 422);
            }
            $envUpdates[$envKey] = $value;
        }

        $this->db->run(
            'INSERT INTO app_settings (id, brand_name, logo_url)
             VALUES (1, ?, ?)
             ON DUPLICATE KEY UPDATE brand_name = ?, logo_url = ?',
            [$brandName ?: null, $logoUrl ?: null, $brandName ?: null, $logoUrl ?: null]
        );

        if ($envUpdates) {
            Env::updateKeys($this->root . '/storage/config/app-settings.env', $envUpdates);
            // Refresh $_ENV/getenv() for the rest of THIS request: re-run the
            // same base-then-overlay load order Kernel::boot() uses, so a
            // just-cleared key reads back as the real base .env value
            // immediately instead of whatever was in memory before this
            // write (which could itself have been a now-removed override).
            Env::load($this->root . '/.env');
            Env::load($this->root . '/storage/config/app-settings.env');
        }

        return $this->get();
    }
}
