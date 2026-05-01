<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;
use function Panic\slugify;

final class Assets extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $assetId = (int) ($this->params['assetId'] ?? 0);
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
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'Upload failed'], 422);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $base = slugify(pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-' . $base . ($ext ? ".$ext" : '');
        $dir = $this->root . '/storage/uploads/events/' . $eventId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return Response::json(['error' => 'Could not store upload'], 500);
        }
        $path = '/uploads/events/' . $eventId . '/' . $filename;
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
        $this->db->run('DELETE FROM event_assets WHERE id=? AND event_id=?', [$assetId, $eventId]);
        return Response::noContent();
    }
}
