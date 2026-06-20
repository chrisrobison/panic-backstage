<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use Panic\Tenant\TenantContext;
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

        // Multi-tenant: store under clients/{slug}/assets/events/{id}/
        //               and expose as /files/assets/events/{id}/{file}.
        // Single-tenant fallback: public/uploads/events/{id}/ (unchanged behaviour).
        $ctx = TenantContext::current();
        if ($ctx !== null) {
            $dir  = $this->root . '/clients/' . $ctx->tenant['slug'] . '/assets/events/' . $eventId;
            $path = 'files/assets/events/' . $eventId . '/' . $filename;
        } else {
            $dir  = $this->root . '/public/uploads/events/' . $eventId;
            $path = 'uploads/events/' . $eventId . '/' . $filename;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
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
                // Multi-tenant path: clients/{slug}/{relative}
                $ctx = TenantContext::current();
                if ($ctx !== null) {
                    $relative  = substr($filePath, 6); // strip 'files/'
                    $clientDir = $this->root . '/clients/' . $ctx->tenant['slug'];
                    $base      = realpath($clientDir);
                    $file      = realpath($clientDir . '/' . $relative);
                    if ($file && $base && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                        unlink($file);
                    }
                }
            } else {
                // Legacy single-tenant path: public/uploads/...
                $file    = realpath($this->root . '/public/' . $filePath);
                $uploads = realpath($this->root . '/public/uploads');
                if ($file && $uploads && str_starts_with($file, $uploads) && is_file($file)) {
                    unlink($file);
                }
            }
        }
        log_activity($this->db, $eventId, $this->userId(), 'asset deleted', ['asset_id' => $assetId]);
        return Response::noContent();
    }
}
