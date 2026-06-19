<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$strippedBasePath = false;
$basePath = rtrim((string) (getenv('APP_BASE_PATH') ?: env_value('APP_BASE_PATH')), '/');
if ($basePath === '') {
    $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    $candidatePath = count($parts) > 1 ? '/' . implode('/', array_slice($parts, 1)) : '/';
    if (($parts[0] ?? '') !== '' && ($candidatePath === '/' || str_starts_with($candidatePath, '/api/') || is_file(__DIR__ . $candidatePath))) {
        $basePath = '/' . $parts[0];
    }
}
if ($basePath !== '') {
    $_SERVER['APP_BASE_PATH'] = $basePath;
}
if ($basePath !== '' && $basePath !== '/' && $path === $basePath) {
    header('Location: ' . $basePath . '/');
    return true;
}
if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath . '/')) {
    $path = substr($path, strlen($basePath)) ?: '/';
    $strippedBasePath = true;
}
$file = __DIR__ . $path;

// Uploaded files are untrusted user content. The PHP built-in server (php -S)
// will execute any .php it is allowed to serve, so we must stream anything
// under /uploads ourselves as inert static content and never fall through to
// the runtime. We resolve the real path and confirm it stays inside the
// uploads directory to block traversal (php -S's own guard is bypassed once we
// readfile directly). Production Apache is covered by storage/uploads/.htaccess.
if (str_starts_with($path, '/uploads/')) {
    $real = realpath($file);
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if ($real !== false && $uploadsRoot !== false && str_starts_with($real, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($real)) {
        header('Content-Type: ' . content_type($real));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        readfile($real);
    } else {
        http_response_code(404);
    }
    return true;
}

if ($path !== '/' && is_file($file)) {
    sensitive_html_headers($path);
    if ($strippedBasePath) {
        header('Content-Type: ' . content_type($file));
        readfile($file);
        return true;
    }
    return false;
}

if (str_starts_with($path, '/api/') || $path === '/t' || str_starts_with($path, '/t/') || $path === '/assets/qr.svg' || $path === '/assets/qr.png') {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.html';

/**
 * For HTML pages that may carry a one-shot token in the URL (login,
 * invite acceptance) prevent caches, mirrors, and search crawlers from
 * retaining the URL. Apache's mod_headers picks the same set up via
 * .htaccess in production; this is the dev-server (`router.php`) path.
 */
function sensitive_html_headers(string $path): void
{
    $name = strtolower(basename($path));
    if (!in_array($name, ['login.html', 'invite.html', 'scanner.html', 'sign.html'], true)) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Referrer-Policy: no-referrer');
}

function content_type(string $file): string
{
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => mime_content_type($file) ?: 'application/octet-stream',
    };
}

function env_value(string $key): string
{
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) {
        return '';
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === $key) {
            return trim($value, "\"'");
        }
    }
    return '';
}
