<?php

declare(strict_types=1);

namespace Panic\Tenant;

use Panic\Database\Connection;
use PDO;

/**
 * Resolves the current HTTP request to a tenant row and its database connection.
 *
 * Multi-tenant mode is active only when SUPER_DB_NAME is set in the environment.
 * When SUPER_DB_NAME is absent, resolve() returns null and the caller falls through
 * to the existing single-tenant path (zero behaviour change).
 *
 * Lookup flow:
 *   1. Read HTTP_HOST (or HTTP_X_FORWARDED_HOST when TRUST_PROXY=true)
 *   2. Validate against ALLOWED_HOSTS (supports *.panicbackstage.com wildcards)
 *   3. Query super DB: tenant_domains JOIN tenants WHERE domain = ? AND status = 'active'
 *   4. Return TenantContext { $tenant (row array), $db (PDO to tenant DB) }
 *
 * On any failure (unrecognised host, no tenant row) the method sends an HTTP
 * response and exits — it never returns null in multi-tenant mode on failure.
 */
final class TenantContext
{
    /** @param array<string,mixed> $tenant Row from the super tenants table. */
    public function __construct(
        public readonly array $tenant,
        public readonly PDO $db
    ) {}

    /**
     * Resolve the current request to a TenantContext.
     *
     * Returns null only when SUPER_DB_NAME is not set (single-tenant mode).
     * In multi-tenant mode this method either returns a valid TenantContext
     * or terminates the request with an appropriate error response.
     */
    public static function resolve(): ?self
    {
        // Multi-tenant mode is opt-in: SUPER_DB_NAME must be present.
        $superDbName = (string)(getenv('SUPER_DB_NAME') ?: '');
        if ($superDbName === '') {
            return null;
        }

        $host = self::host();

        if ($host === '' || !self::isAllowedHost($host)) {
            self::respondNotConfigured($host, 'unrecognized', 400);
        }

        $stmt = Connection::super()->prepare(
            'SELECT t.*, d.domain
             FROM tenant_domains d
             JOIN tenants t ON t.id = d.tenant_id
             WHERE d.domain = ? AND t.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([$host]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            self::respondNotConfigured($host, 'unknown', 404);
        }

        return new self(
            (array) $tenant,
            Connection::tenant((string) $tenant['database_name'])
        );
    }

    /**
     * Extract and normalise the request hostname.
     * Honours TRUST_PROXY=true → reads HTTP_X_FORWARDED_HOST first.
     */
    public static function host(): string
    {
        if ((string)(getenv('TRUST_PROXY') ?: '') === 'true') {
            $header = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        } else {
            $header = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        // Take only the first value when the header is a comma-separated list.
        $host = strtolower(trim(explode(',', $header)[0]));
        // IPv6 literal: strip brackets, ignore port.
        if (str_starts_with($host, '[')) {
            return substr($host, 1, (strpos($host, ']') ?: 1) - 1);
        }
        // Strip port suffix (e.g. "example.com:8080" → "example.com").
        return explode(':', $host)[0];
    }

    /**
     * Check whether $host appears in the ALLOWED_HOSTS list.
     * Supports exact matches and wildcard prefixes (*.panicbackstage.com).
     * Default list when ALLOWED_HOSTS is unset: localhost, 127.0.0.1.
     *
     * Exposed as a public static so it can be unit-tested without triggering
     * the full resolve() flow (which sends HTTP responses).
     */
    public static function isAllowedHost(string $host): bool
    {
        $raw = (string)(getenv('ALLOWED_HOSTS') ?: 'localhost,127.0.0.1');
        foreach (array_map('trim', explode(',', $raw)) as $allowed) {
            if ($allowed === '') {
                continue;
            }
            // Wildcard: *.panicbackstage.com matches any subdomain.
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".panicbackstage.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send a "nothing here" response and exit.
     *
     * Browser clients get a friendly branded HTML page; API clients (anything
     * that does not accept text/html) receive a JSON error body.
     *
     * @param string $reason  'unrecognized' (host not in ALLOWED_HOSTS) |
     *                        'unknown' (host allowed but no active tenant row)
     * @return never
     */
    private static function respondNotConfigured(string $host, string $reason, int $status): never
    {
        $accept    = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $wantsHtml = str_contains($accept, 'text/html');

        if (!$wantsHtml) {
            $error = $reason === 'unrecognized'
                ? 'Unrecognized host'
                : 'No active tenant found for this hostname';
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode(['error' => $error]);
            exit;
        }

        $safeHost = htmlspecialchars($host !== '' ? $host : 'this address', ENT_QUOTES, 'UTF-8');

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panic Backstage — nothing here yet</title>
<style>
  :root { color-scheme: light dark; }
  body  { font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
          margin: 0; min-height: 100vh; display: grid; place-items: center;
          background: linear-gradient(135deg, #1a1d3a 0%, #2d1b4e 100%);
          color: #f5f5fa; padding: 2rem; }
  main  { max-width: 30rem; text-align: center; }
  h1    { font-size: 2rem; margin: 0 0 1rem; letter-spacing: -0.02em; }
  p     { line-height: 1.5; margin: 0.75rem 0; opacity: 0.85; }
  code  { background: rgba(255,255,255,.1); padding: .1em .4em;
          border-radius: .25em; font-family: ui-monospace, monospace; }
</style>
</head>
<body>
<main>
  <h1>Nothing here yet</h1>
  <p>No Panic Backstage account is configured at <code>{$safeHost}</code>.</p>
  <p>If you received this link from a venue, double-check the URL.
     If you're setting up a new account, contact your administrator.</p>
</main>
</body>
</html>
HTML;
        exit;
    }
}
