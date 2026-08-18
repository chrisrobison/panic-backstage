<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use Panic\Tenant\TenantContext;
use function Panic\ensure_dir;
use function Panic\log_activity;
use function Panic\slugify;

final class Assets extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $assetId = (int) ($this->params['assetId'] ?? 0);
        $capability = match ($request->method()) {
            'GET' => 'read_event',
            'POST' => 'upload_assets',
            default => 'manage_assets',
        };
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->ok(['assets' => $this->db->all('SELECT * FROM event_assets WHERE event_id = ? ORDER BY created_at DESC', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, $assetId),
            'DELETE' => $this->delete($eventId, $assetId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $sourceAssetId = (int) ($request->body('source_asset_id') ?? 0);
        if ($sourceAssetId > 0) {
            return $this->attachExisting($request, $eventId, $sourceAssetId);
        }

        $file = $request->files()['asset'] ?? null;
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!$file || $uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large — check your server upload_max_filesize setting (currently ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_NO_FILE => 'No file was received',
                default => 'Upload failed (PHP error ' . $uploadError . ')',
            };
            return Response::json(['error' => $message], 422);
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            return Response::json(['error' => 'Uploads must be 10MB or smaller'], 422);
        }
        // Detect the type from the file's actual bytes, and derive the stored
        // extension from THAT — never from the user-supplied filename. Trusting
        // the client extension lets a polyglot (valid image bytes + PHP code)
        // be stored as ".php" and executed by the web server. The map below is
        // the only set of extensions we will ever write to disk.
        $mime = mime_content_type($file['tmp_name']) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($allowed[$mime])) {
            return Response::json(['error' => 'Only images and PDFs are accepted (detected: ' . $mime . ')'], 422);
        }
        $ext = $allowed[$mime];
        $base = slugify(pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-' . $base . '.' . $ext;

        // Store outside the document root in both deployment modes. The
        // /files gateway performs row-level authorization before streaming.
        $dir  = TenantContext::clientDir($this->root) . '/assets/events/' . $eventId;
        $path = 'files/assets/events/' . $eventId . '/' . $filename;

        try {
            ensure_dir($dir);
        } catch (\RuntimeException $e) {
            error_log('Assets::upload could not prepare storage for event ' . $eventId . ': ' . $e->getMessage());
            return Response::json(['error' => 'Could not store upload'], 500);
        }
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return Response::json(['error' => 'Could not store upload'], 500);
        }
        $id = $this->db->insert('INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $eventId, $request->body('asset_type', 'other'), $request->body('title') ?: $file['name'], $filename, $file['name'], $path, $this->userId(), 'needs_review', $request->body('notes')
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'asset uploaded', ['asset_id' => $id]);
        return $this->ok(['id' => $id, 'file_path' => $path]);
    }

    /**
     * Attach a copy of an asset already uploaded to some OTHER event — the
     * "browse existing assets" path from the Add Asset modal, mainly for
     * recurring events that reuse the same flyer week after week.
     *
     * event_assets.event_id is NOT NULL and there is no join table, so one
     * physical row can't belong to two events. We also can't have two rows
     * share a single file on disk: delete() unlinks a row's file_path when
     * that row is removed, which would silently break the other row. So this
     * copies the file bytes into the target event's own storage dir under a
     * fresh generated name and inserts a brand new event_assets row — same
     * shape as a direct upload, just sourced from an existing file instead of
     * $_FILES.
     *
     * $sourceAssetId is client-supplied, so it must be re-authorized here:
     * requireEventCapability() above only confirmed the caller can upload
     * INTO $eventId, not that they're allowed to SEE the source asset. We
     * apply the exact same visibility rule as GET /asset-library
     * (eventScopeSql) before touching the source row or its file.
     */
    private function attachExisting(Request $request, int $eventId, int $sourceAssetId): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $source = $this->db->one(
            "SELECT a.* FROM event_assets a JOIN events e ON e.id = a.event_id WHERE a.id = ? AND $scopeSql",
            array_merge([$sourceAssetId], $scopeParams)
        );
        if (!$source) {
            return Response::json(['error' => 'Asset not found'], 404);
        }
        if ((int) $source['event_id'] === $eventId) {
            return Response::json(['error' => 'That asset already belongs to this event'], 422);
        }

        $clientDir = TenantContext::clientDir($this->root);
        $srcPath = (string) $source['file_path'];
        if (!str_starts_with($srcPath, 'files/')) {
            return Response::json(['error' => 'This asset uses a legacy storage path and cannot be reused — re-upload it instead'], 422);
        }
        $base = realpath($clientDir);
        $srcFile = $base ? realpath($clientDir . '/' . substr($srcPath, 6)) : false;
        if (!$srcFile || !$base || !str_starts_with($srcFile, $base . DIRECTORY_SEPARATOR) || !is_file($srcFile)) {
            return Response::json(['error' => 'Source file is missing on disk'], 404);
        }

        $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
        $base2 = slugify(pathinfo((string) $source['original_filename'], PATHINFO_FILENAME));
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-' . $base2 . '.' . $ext;
        $dir = $clientDir . '/assets/events/' . $eventId;
        $newPath = 'files/assets/events/' . $eventId . '/' . $filename;

        try {
            ensure_dir($dir);
        } catch (\RuntimeException $e) {
            error_log('Assets::attachExisting could not prepare storage for event ' . $eventId . ': ' . $e->getMessage());
            return Response::json(['error' => 'Could not store attached asset'], 500);
        }
        if (!copy($srcFile, $dir . '/' . $filename)) {
            return Response::json(['error' => 'Could not copy asset'], 500);
        }

        $title = $request->body('title') ?: $source['title'];
        $assetType = $request->body('asset_type') ?: $source['asset_type'];
        $id = $this->db->insert('INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status, notes, generation_source, generation_prompt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $eventId, $assetType, $title, $filename, $source['original_filename'], $newPath, $this->userId(), 'needs_review', $source['notes'], $source['generation_source'], $source['generation_prompt'],
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'asset attached from library', ['asset_id' => $id, 'source_asset_id' => $sourceAssetId]);
        return $this->ok(['id' => $id, 'file_path' => $newPath]);
    }

    private function update(Request $request, int $eventId, int $assetId): Response
    {
        $status = $request->body('approval_status');
        $this->db->run('UPDATE event_assets SET approval_status=?, notes=COALESCE(?, notes) WHERE id=? AND event_id=?', [$status, $request->body('notes'), $assetId, $eventId]);
        if ($status === 'approved') {
            log_activity($this->db, $eventId, $this->userId(), 'asset approved', ['asset_id' => $assetId]);
        }
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $assetId): Response
    {
        $asset = $this->db->one('SELECT file_path FROM event_assets WHERE id=? AND event_id=?', [$assetId, $eventId]);
        $this->db->run('DELETE FROM event_assets WHERE id=? AND event_id=?', [$assetId, $eventId]);
        if ($asset && !empty($asset['file_path'])) {
            $filePath = (string) $asset['file_path'];
            if (str_starts_with($filePath, 'files/')) {
                $relative  = substr($filePath, 6); // strip 'files/'
                $clientDir = TenantContext::clientDir($this->root);
                $base      = realpath($clientDir);
                $file      = realpath($clientDir . '/' . $relative);
                if ($file && $base && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                    unlink($file);
                }
            } else {
                // Legacy single-tenant path: storage/uploads/... Existing
                // URLs remain valid, but Apache now sends them through the
                // same authorized file gateway.
                $file    = realpath(TenantContext::clientDir($this->root) . '/' . $filePath);
                $uploads = realpath(TenantContext::clientDir($this->root) . '/uploads');
                if ($file && $uploads && str_starts_with($file, $uploads) && is_file($file)) {
                    unlink($file);
                }
            }
        }
        log_activity($this->db, $eventId, $this->userId(), 'asset deleted', ['asset_id' => $assetId]);
        return Response::noContent();
    }
}
