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
