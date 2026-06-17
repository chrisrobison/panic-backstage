<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$root = dirname(__DIR__, 2);

// Load .env early so we can inspect SUPER_DB_NAME before anything else runs.
// Kernel::boot() calls this again internally — that's fine, it's idempotent.
Panic\Env::load($root . '/.env');

// ── Multi-tenant SaaS mode ────────────────────────────────────────────────────
// Activated only when SUPER_DB_NAME is present and non-empty in .env.
// Without it every request falls through to the existing single-tenant path
// below — zero behaviour change for stand-alone installs.
$superDbName = (string)(getenv('SUPER_DB_NAME') ?: '');
if ($superDbName !== '') {
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Super-admin routes: served before tenant resolution so they work from
    // any hostname (or a dedicated SUPER_HOST) without a tenant DB.
    if (str_starts_with($path, '/api/super') || str_starts_with($path, '/super')) {
        Panic\Http\SuperController::dispatch($path, $method);
        // dispatch() always exits; execution never reaches here.
    }

    // Health check: respond without tenant resolution.
    if ($path === '/health' && $method === 'GET') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // All other routes: resolve tenant from HTTP_HOST.
    // TenantContext::resolve() either returns a valid context or exits with
    // an appropriate error response (400 for unrecognised host, 404 for
    // no active tenant row). It never returns null in SaaS mode.
    $ctx = Panic\Tenant\TenantContext::resolve();

    // Inject the tenant PDO into Database so all endpoint code is unchanged.
    Panic\Kernel::boot($root, new Panic\Database($ctx->db))->handle()->send();
    exit;
}

// ── Single-tenant mode (existing behaviour, zero changes) ─────────────────────
// No SUPER_DB_NAME → connect using DB_* env vars exactly as before.
Panic\Kernel::boot($root)->handle()->send();
