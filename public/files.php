<?php
declare(strict_types=1);

/**
 * Authorized tenant file gateway.
 *
 * Public event artwork is available only after approval on an explicitly
 * public event. Every other file requires a valid Backstage session plus
 * event/lead row access. No arbitrary path inside storage/ or clients/ can
 * be fetched merely by knowing its name.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Capabilities;
use Panic\Database;
use Panic\Env;
use Panic\Request;
use Panic\Tenant\TenantContext;

$root = dirname(__DIR__);
Env::load($root . '/.env');
set_exception_handler(static function (\Throwable $error): never {
    $errorId = bin2hex(random_bytes(8));
    error_log("[file_error_id={$errorId}] " . (string) $error);
    file_error(500, 'Server error', $errorId);
});

$ctx = null;
if ((string) (getenv('SUPER_DB_NAME') ?: '') !== '') {
    $ctx = TenantContext::resolve();
    TenantContext::setCurrent($ctx);
}
$db = new Database($ctx?->db);

$path = trim((string) ($_GET['_path'] ?? ''), '/');
if ($path === ''
    || !preg_match('#^[A-Za-z0-9._/\-]+$#', $path)
    || str_contains($path, '..')
) {
    file_error(400, 'Invalid path');
}
// New private paths are stored with a `files/` URL prefix. Preserve old
// single-tenant `uploads/...` event-asset rows while still forcing their
// requests through this gateway.
$storedPath = str_starts_with($path, 'uploads/') ? $path : 'files/' . $path;

$auth = new Auth();
$request = Request::fromGlobals();
$auth->authenticate($request);
if ($user = $auth->user()) {
    $row = $db->one('SELECT token_version FROM users WHERE id = ?', [$user['id']]);
    if ($row === null || (int) $row['token_version'] !== (int) ($user['token_version'] ?? 0)) {
        $auth->clearUser();
    }
}

$authorized = false;
$public = false;

$asset = $db->one(
    'SELECT a.event_id, a.asset_type, a.approval_status,
            e.public_visibility, e.status
     FROM event_assets a
     JOIN events e ON e.id = a.event_id
     WHERE a.file_path = ?
     LIMIT 1',
    [$storedPath]
);
if ($asset !== null) {
    $publicTypes = [
        'flyer', 'poster', 'band_photo', 'logo', 'social_square',
        'social_story', 'press_photo', 'qr_code',
    ];
    $public = (int) ($asset['public_visibility'] ?? 0) === 1
        && $asset['approval_status'] === 'approved'
        && !in_array($asset['status'], ['empty', 'canceled'], true)
        && in_array($asset['asset_type'], $publicTypes, true);
    $authorized = $public || (
        $auth->user() !== null
        && Capabilities::hasEvent(
            $db,
            (int) $asset['event_id'],
            (int) $auth->user()['id'],
            (string) $auth->user()['role'],
            'read_event'
        )
    );
} else {
    $attachment = $db->one(
        'SELECT a.lead_id, l.assigned_to_user_id, l.owner_user_id,
                l.claimed_by_user_id, l.point_person_id
         FROM lead_attachments a
         JOIN leads l ON l.id = a.lead_id
         WHERE a.storage_path = ?
         LIMIT 1',
        [$storedPath]
    );
    if ($attachment !== null && $auth->user() !== null) {
        $userId = (int) $auth->user()['id'];
        $role = (string) $auth->user()['role'];
        $authorized = $role === 'venue_admin'
            || $role === 'global_viewer'
            || Capabilities::hasGlobal($role, 'manage_booking_inbox')
            || (int) ($attachment['assigned_to_user_id'] ?? 0) === $userId
            || (int) ($attachment['owner_user_id'] ?? 0) === $userId
            || (int) ($attachment['claimed_by_user_id'] ?? 0) === $userId
            || (int) ($attachment['point_person_id'] ?? 0) === $userId
            || $db->one(
                'SELECT id FROM lead_watchers WHERE lead_id = ? AND user_id = ?',
                [$attachment['lead_id'], $userId]
            ) !== null;
    }
}

if (!$authorized) {
    file_error($auth->user() === null ? 401 : 403, $auth->user() === null ? 'Authentication required' : 'Forbidden');
}

$base = realpath(TenantContext::clientDir($root));
$file = realpath(TenantContext::clientDir($root) . '/' . $path);
if ($base === false
    || $file === false
    || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)
    || !is_file($file)
) {
    file_error(404, 'Not found');
}

$mime = mime_content_type($file) ?: 'application/octet-stream';
$size = filesize($file);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
header($public
    ? 'Cache-Control: public, max-age=3600, must-revalidate'
    : 'Cache-Control: private, no-store');
header('Content-Type: ' . $mime);
if ($size !== false) {
    header('Content-Length: ' . $size);
}
readfile($file);

/** @return never */
function file_error(int $status, string $message, ?string $errorId = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $body = ['error' => $message];
    if ($errorId !== null) {
        $body['error_id'] = $errorId;
    }
    echo json_encode($body);
    exit;
}
