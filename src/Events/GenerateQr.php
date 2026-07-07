<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\QrCode;
use Panic\Request;
use Panic\Response;
use Panic\Tenant\TenantContext;
use function Panic\event_public_path;
use function Panic\log_activity;

/**
 * GET  /api/events/{id}/assets/generate-qr → preview the public event URL a QR would encode
 * POST /api/events/{id}/assets/generate-qr → render a QR PNG for that URL and store it as
 *                                              this event's single `qr_code` asset, so staff
 *                                              can download/print it like any other asset.
 *
 * Re-running POST regenerates in place (one qr_code asset per event) rather than piling up
 * duplicates — the encoded URL is keyed by the event's stable id (see event_public_path()),
 * so it never changes even if the title/date (and therefore the slug) does — there's nothing
 * to version like the AI flyer prompt.
 *
 * Requires the `upload_assets` capability (same gate as flyer generation).
 */
final class GenerateQr extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'upload_assets')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'   => $this->preview($eventId),
            'POST'  => $this->generate($eventId),
            default => Response::methodNotAllowed(),
        };
    }

    /** Return the URL that would be encoded, so the UI can show it before generating. */
    private function preview(int $eventId): Response
    {
        $event = $this->db->one('SELECT id FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound();
        }
        return $this->ok(['url' => $this->publicUrl($event)]);
    }

    private function generate(int $eventId): Response
    {
        $event = $this->db->one('SELECT id FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound();
        }

        $url = $this->publicUrl($event);
        $png = QrCode::generatePng($url, 600);
        if ($png === '') {
            return Response::json(['error' => 'Could not generate QR code'], 500);
        }

        // Permanent storage: mirror the pattern used in Assets.php / GenerateFlyer.php.
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-qr-code.png';
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
        if (file_put_contents($dir . '/' . $filename, $png) === false) {
            return Response::json(['error' => 'Could not store QR code image'], 500);
        }

        $existing = $this->db->one(
            "SELECT id, file_path FROM event_assets WHERE event_id = ? AND asset_type = 'qr_code'",
            [$eventId]
        );
        if ($existing) {
            $this->deleteAssetFile((string) $existing['file_path']);
            $this->db->run(
                'UPDATE event_assets SET filename=?, original_filename=?, file_path=?, uploaded_by_user_id=?, approval_status=?, generation_source=?, generation_prompt=? WHERE id=?',
                [$filename, 'qr-code.png', $path, $this->userId(), 'approved', 'qr_generator', $url, $existing['id']]
            );
            $assetId = (int) $existing['id'];
            log_activity($this->db, $eventId, $this->userId(), 'QR code asset regenerated', ['asset_id' => $assetId]);
        } else {
            $assetId = $this->db->insert(
                'INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status, generation_source, generation_prompt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$eventId, 'qr_code', 'QR Code — Public Page', $filename, 'qr-code.png', $path, $this->userId(), 'approved', 'qr_generator', $url]
            );
            log_activity($this->db, $eventId, $this->userId(), 'QR code asset generated', ['asset_id' => $assetId]);
        }

        return $this->ok(['id' => $assetId, 'file_path' => $path, 'url' => $url]);
    }

    private function publicUrl(array $event): string
    {
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        return $appUrl . '/' . event_public_path($event);
    }

    /** Mirrors Assets::delete()'s traversal-safe unlink so a regenerate doesn't orphan the old file. */
    private function deleteAssetFile(string $filePath): void
    {
        if ($filePath === '') {
            return;
        }
        if (str_starts_with($filePath, 'files/')) {
            $ctx = TenantContext::current();
            if ($ctx !== null) {
                $relative  = substr($filePath, 6);
                $clientDir = $this->root . '/clients/' . $ctx->tenant['slug'];
                $base      = realpath($clientDir);
                $file      = realpath($clientDir . '/' . $relative);
                if ($file && $base && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                    unlink($file);
                }
            }
        } else {
            $file    = realpath($this->root . '/public/' . $filePath);
            $uploads = realpath($this->root . '/public/uploads');
            if ($file && $uploads && str_starts_with($file, $uploads) && is_file($file)) {
                unlink($file);
            }
        }
    }
}
