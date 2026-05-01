<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$strippedBasePath = false;
$basePath = rtrim((string) getenv('APP_BASE_PATH'), '/');
if ($basePath !== '' && $basePath !== '/' && $path === $basePath) {
    header('Location: ' . $basePath . '/');
    return true;
}
if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath . '/')) {
    $path = substr($path, strlen($basePath)) ?: '/';
    $strippedBasePath = true;
}
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    if ($strippedBasePath) {
        header('Content-Type: ' . content_type($file));
        readfile($file);
        return true;
    }
    return false;
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.html';

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
