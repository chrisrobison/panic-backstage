<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\CredentialEncryption;
use Panic\Request;
use Panic\Response;

/**
 * CRUD for per-venue Promote platform credentials.
 *
 *   GET    /api/promote/credentials?venue_id=1   → list all creds (tokens redacted)
 *   PUT    /api/promote/credentials/{destKey}    → upsert credential for a platform
 *   DELETE /api/promote/credentials/{destKey}    → disconnect / clear a platform
 *
 * Only venue_admins may read or write credentials.
 *
 * PUT body (all fields optional — only provided fields are updated):
 *   {
 *     "venue_id":      1,
 *     "access_token":  "...",       // API key / OAuth access token
 *     "refresh_token": "...",       // OAuth refresh token (if applicable)
 *     "config":        { ... }      // Platform-specific JSON (org IDs, page IDs, etc.)
 *   }
 *
 * Tokens are never returned in GET responses — only status, config, and metadata.
 */
final class CredentialSettings extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        $destKey = $this->params['destKey'] ?? null;

        return match ($request->method()) {
            'GET'    => $this->index($request),
            'PUT'    => $this->upsert($request, (string) $destKey),
            'DELETE' => $this->disconnect((string) $destKey),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── List ─────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        $venueId = (int) ($request->query()['venue_id'] ?? 1);

        $venues = $this->db->all('SELECT id, name FROM venues ORDER BY id');

        // Merge destinations with their credential rows
        $destinations = $this->db->all(
            "SELECT * FROM promote_destinations WHERE status != 'disabled' ORDER BY destination_group, label"
        );

        $creds = $this->db->all(
            'SELECT destination_key, status, config, error_message, connected_at, updated_at,
                    (enc_access_token IS NOT NULL OR access_token IS NOT NULL) has_token
             FROM promote_credentials WHERE venue_id = ?',
            [$venueId]
        );
        $credMap = [];
        foreach ($creds as $c) {
            $credMap[(string) $c['destination_key']] = $c;
        }

        $result = [];
        foreach ($destinations as $dest) {
            $key  = (string) $dest['destination_key'];
            $cred = $credMap[$key] ?? null;
            $result[] = [
                'destination_key'   => $key,
                'destination_group' => $dest['destination_group'],
                'label'             => $dest['label'],
                'dest_status'       => $dest['status'],   // DB-level: connected/needs_auth/manual_submission
                'cred_status'       => $cred ? $cred['status'] : 'needs_auth',
                'config'            => $cred && $cred['config'] ? json_decode((string) $cred['config'], true) : null,
                'error_message'     => $cred ? $cred['error_message'] : null,
                'connected_at'      => $cred ? $cred['connected_at'] : null,
                'has_token'         => $cred ? (bool) $cred['has_token'] : false,
                // Secrets are NEVER returned — has_token is the presence indicator only.
            ];
        }

        return $this->ok([
            'venue_id'     => $venueId,
            'venues'       => $venues,
            'credentials'  => $result,
        ]);
    }

    // ── Upsert ───────────────────────────────────────────────────────────────

    private function upsert(Request $request, string $destKey): Response
    {
        if (!$destKey) {
            return Response::json(['error' => 'destination_key is required'], 422);
        }

        $dest = $this->db->one(
            'SELECT * FROM promote_destinations WHERE destination_key = ?',
            [$destKey]
        );
        if (!$dest) {
            return $this->notFound("Unknown destination: $destKey");
        }

        $body    = $request->body();
        $venueId = (int) ($body['venue_id'] ?? 1);

        $accessToken  = isset($body['access_token'])  ? (string) $body['access_token']  : null;
        $refreshToken = isset($body['refresh_token']) ? (string) $body['refresh_token'] : null;
        $config       = isset($body['config'])        ? json_encode($body['config'])     : null;

        // Validate config is valid JSON-serialisable
        if (isset($body['config']) && !is_array($body['config']) && !is_object($body['config'])) {
            return Response::json(['error' => 'config must be a JSON object'], 422);
        }

        // Encrypt tokens before storage.
        // Falls back to storing plaintext when encryption is not configured
        // (dev environments without CREDENTIAL_ENCRYPTION_KEY set).
        $encAccessToken  = null;
        $encRefreshToken = null;
        if (CredentialEncryption::isConfigured()) {
            if ($accessToken !== null) {
                $encAccessToken = CredentialEncryption::encrypt($accessToken);
                $accessToken    = null;  // do not store plaintext when encrypted
            }
            if ($refreshToken !== null) {
                $encRefreshToken = CredentialEncryption::encrypt($refreshToken);
                $refreshToken    = null;
            }
        }

        // Determine new status
        $hasToken    = ($encAccessToken !== null) || ($accessToken !== null);
        $status      = $hasToken ? 'connected' : 'needs_auth';
        $connectedAt = $hasToken ? date('Y-m-d H:i:s') : null;

        // Upsert
        $existing = $this->db->one(
            'SELECT id FROM promote_credentials WHERE venue_id = ? AND destination_key = ?',
            [$venueId, $destKey]
        );

        if ($existing) {
            $sets   = ['status = ?', 'error_message = NULL', 'updated_at = NOW()'];
            $params = [$status];

            if ($encAccessToken !== null) {
                $sets[]   = 'enc_access_token = ?';
                $params[] = $encAccessToken;
                $sets[]   = 'access_token = NULL';   // clear plaintext
                $sets[]   = 'enc_key_version = 1';
                $sets[]   = 'connected_at = ?';
                $params[] = $connectedAt;
            } elseif ($accessToken !== null) {
                // No encryption configured — store plaintext temporarily.
                $sets[]   = 'access_token = ?';
                $params[] = $accessToken;
                $sets[]   = 'connected_at = ?';
                $params[] = $connectedAt;
            }
            if ($encRefreshToken !== null) {
                $sets[]   = 'enc_refresh_token = ?';
                $params[] = $encRefreshToken;
                $sets[]   = 'refresh_token = NULL';
            } elseif ($refreshToken !== null) {
                $sets[]   = 'refresh_token = ?';
                $params[] = $refreshToken;
            }
            if ($config !== null) {
                $sets[]   = 'config = ?';
                $params[] = $config;
            }

            $params[] = (int) $existing['id'];
            $this->db->run(
                'UPDATE promote_credentials SET ' . implode(', ', $sets) . ' WHERE id = ?',
                $params
            );
        } else {
            $this->db->insert(
                'INSERT INTO promote_credentials
                    (venue_id, destination_key, access_token, refresh_token,
                     enc_access_token, enc_refresh_token, enc_key_version,
                     config, status, connected_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $venueId, $destKey,
                    $accessToken, $refreshToken,          // plaintext (NULL if encrypted)
                    $encAccessToken, $encRefreshToken,    // ciphertext (NULL if no key)
                    CredentialEncryption::isConfigured() ? 1 : 0,
                    $config, $status, $connectedAt,
                ]
            );
        }

        // Also sync promote_destinations.status so the broadcast modal reflects reality
        if ($accessToken) {
            $this->db->run(
                "UPDATE promote_destinations SET status = 'connected' WHERE destination_key = ?",
                [$destKey]
            );
        }

        return $this->ok(['saved' => true, 'status' => $status]);
    }

    // ── Disconnect ────────────────────────────────────────────────────────────

    private function disconnect(string $destKey): Response
    {
        if (!$destKey) {
            return Response::json(['error' => 'destination_key is required'], 422);
        }

        $this->db->run(
            "UPDATE promote_credentials
             SET access_token = NULL, refresh_token = NULL, token_expires_at = NULL,
                 enc_access_token = NULL, enc_refresh_token = NULL,
                 status = 'needs_auth', error_message = NULL, connected_at = NULL
             WHERE destination_key = ?",
            [$destKey]
        );

        $this->db->run(
            "UPDATE promote_destinations SET status = 'needs_auth' WHERE destination_key = ?",
            [$destKey]
        );

        return $this->ok(['disconnected' => true]);
    }
}
