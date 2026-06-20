<?php

/**
 * Per-tenant static file gateway.
 *
 * Serves files from clients/{slug}/{path} via the /files/{path} URL prefix.
 * The clients/ directory lives outside public/ so it is never directly
 * web-accessible; all reads must come through this gateway.
 *
 * Security measures:
 *   - Path component whitelist (no "..", no shell metacharacters)
 *   - realpath() check ensures the resolved path stays inside clients/{slug}/
 *   - In multi-tenant mode the slug is derived from the authoritative
 *     TenantContext (DB-backed hostname lookup), not from user input
 *   - In single-tenant mode falls back to storage/ for backward compatibility
 *
 * Apache routes here via:
 *   RewriteRule ^files/(.+)$ public/files.php?_path=$1 [L,QSA]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$root = dirname(__DIR__);
Panic\Env::load($root . '/.env');

// ── Resolve the client directory ──────────────────────────────────────────────

$superDbName = (string)(getenv('SUPER_DB_NAME') ?: '');
if ($superDbName !== '') {
    // Multi-tenant: resolve tenant from HTTP_HOST; exits with 4xx on failure.
    $ctx       = Panic\Tenant\TenantContext::resolve();
    $clientDir = $root . '/clients/' . $ctx->tenant['slug'];
} else {
    // Single-tenant fallback: serve from storage/ so existing installs keep
    // working if they adopt the /files/ URL prefix.
    $clientDir = $root . '/storage';
}

// ── Validate the requested path ───────────────────────────────────────────────

$path = trim((string)($_GET['_path'] ?? ''), '/');

// Whitelist: only letters, digits, hyphens, underscores, dots, and forward
// slashes. This rejects "..", null bytes, shell metacharacters, etc.
if ($path === '' || !preg_match('#^[A-Za-z0-9._/\-]+$#', $path)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid path']);
    exit;
}

// ── Resolve and confirm the file is inside the client directory ───────────────

$candidatePath = $clientDir . '/' . $path;
$file          = realpath($candidatePath);
$base          = realpath($clientDir);

if ($base === false) {
    // Client directory has not been provisioned yet.
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Directory-traversal guard: the resolved path must start with the client root.
if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

// ── Stream the file ───────────────────────────────────────────────────────────

$mime = mime_content_type($file) ?: 'application/octet-stream';
$size = filesize($file);

// Prevent uploaded HTML/SVG from being executed as a page.
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');

// Cache: private (tenant-specific), revalidate after 1 hour.
header('Cache-Control: private, max-age=3600, must-revalidate');

header('Content-Type: ' . $mime);
if ($size !== false) {
    header('Content-Length: ' . $size);
}

readfile($file);
