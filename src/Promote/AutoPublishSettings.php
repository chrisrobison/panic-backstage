<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Global auto-publish settings.
 *
 *   GET   /api/promote/auto-publish  → return current settings
 *   PATCH /api/promote/auto-publish  → update settings
 *
 * Body fields (PATCH):
 *   auto_publish_enabled      bool   — 1/0, enable or disable
 *   auto_publish_destinations array  — list of destination_key strings to broadcast to
 */
final class AutoPublishSettings extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'   => $this->show(),
            'PATCH' => $this->update($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(): Response
    {
        $settings     = $this->loadSettings();
        $destinations = $this->db->all(
            "SELECT destination_key, label, destination_group, status
             FROM promote_destinations WHERE status != 'disabled' ORDER BY destination_group, label"
        );
        return $this->ok([
            'settings'     => $settings,
            'destinations' => $destinations,
        ]);
    }

    private function update(Request $request): Response
    {
        $body = $request->body();

        $enabled      = isset($body['auto_publish_enabled']) ? ((int) $body['auto_publish_enabled'] ? 1 : 0) : null;
        $destinations = array_key_exists('auto_publish_destinations', $body)
            ? (is_array($body['auto_publish_destinations']) ? $body['auto_publish_destinations'] : [])
            : null;

        $current = $this->loadSettings();

        $newEnabled = $enabled ?? (int) ($current['auto_publish_enabled'] ?? 0);
        $newDests   = $destinations !== null ? $destinations : ($current['auto_publish_destinations_array'] ?? []);

        $this->db->run(
            'INSERT INTO promote_auto_publish_settings (id, auto_publish_enabled, auto_publish_destinations)
             VALUES (1, ?, ?)
             ON DUPLICATE KEY UPDATE
               auto_publish_enabled      = VALUES(auto_publish_enabled),
               auto_publish_destinations = VALUES(auto_publish_destinations)',
            [$newEnabled, $newDests ? json_encode($newDests) : null]
        );

        return $this->ok(['settings' => $this->loadSettings()]);
    }

    private function loadSettings(): array
    {
        $row = $this->db->one('SELECT * FROM promote_auto_publish_settings LIMIT 1');
        if (!$row) {
            return [
                'auto_publish_enabled'       => 0,
                'auto_publish_destinations'  => null,
                'auto_publish_destinations_array' => [],
            ];
        }
        $arr = $row['auto_publish_destinations']
            ? (json_decode((string) $row['auto_publish_destinations'], true) ?? [])
            : [];
        return array_merge($row, ['auto_publish_destinations_array' => $arr]);
    }
}
