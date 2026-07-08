<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\db_timestamp_to_epoch;

/**
 * In-app OAuth 2.0 PKCE flow for the "Connect X account" button
 * (Settings → Promote → X). Produces the same access_token/refresh_token
 * pair the TwitterAdapter posts tweets with — CredentialSettings::storeCredential()
 * writes them into promote_credentials exactly as a manually-pasted token would.
 *
 *   POST /api/promote/oauth/twitter/start     (authenticated; venue_admin only)
 *     Body: { "venue_id": 1 }
 *     → generates a PKCE code_verifier + CSRF state, stores them server-side
 *       (promote_oauth_states), and returns { "authorize_url": "..." } for
 *       the browser to navigate to.
 *
 *   GET /api/promote/oauth/twitter/callback   (public)
 *     X redirects the top-level browser window here with ?code=&state=
 *     (or ?error=) — this is a full navigation, not a fetch(), so it cannot
 *     carry an Authorization header. It authenticates itself by consuming the
 *     single-use `state` row instead, exchanges the code for tokens, stores
 *     them, and redirects back into the SPA at #promote-settings.
 *
 * Setup (see also .env.example):
 *   1. developer.twitter.com → create a Project + App.
 *   2. App Settings → User Authentication Settings: Web App, "Read and write",
 *      Redirect URI = {APP_URL}/api/promote/oauth/twitter/callback.
 *   3. Keys and Tokens → OAuth 2.0 Client ID/Secret → TWITTER_CLIENT_ID / TWITTER_CLIENT_SECRET.
 */
final class TwitterOAuth extends BaseEndpoint
{
    private const AUTHORIZE_URL     = 'https://twitter.com/i/oauth2/authorize';
    private const TOKEN_URL         = 'https://api.twitter.com/2/oauth2/token';
    private const SCOPES            = 'tweet.write users.read offline.access';
    private const DEST_KEY          = 'twitter';
    private const STATE_TTL_SECONDS = 600; // 10 minutes to complete the X consent screen

    public function handle(Request $request): Response
    {
        $action = (string) ($this->params['action'] ?? '');

        return match ($action) {
            'start'    => $this->start($request),
            'callback' => $this->callback($request),
            default    => $this->notFound(),
        };
    }

    // ── Step 1: initiate (authenticated) ────────────────────────────────────

    private function start(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        // This class is registered public (the callback below has no JWT to check),
        // so this action must gate itself — mirrors Scanner::class's pattern.
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        $clientId = (string) (getenv('TWITTER_CLIENT_ID') ?: '');
        if (!$clientId) {
            return Response::json(['error' => 'TWITTER_CLIENT_ID is not set in .env — see .env.example for setup steps'], 503);
        }

        $venueId = (int) ($request->body('venue_id') ?? 1);

        $codeVerifier  = self::base64UrlEncode(random_bytes(64));
        $codeChallenge = self::base64UrlEncode(hash('sha256', $codeVerifier, true));
        $state         = bin2hex(random_bytes(32));

        // Prune abandoned attempts opportunistically (no cron needed for a low-volume table).
        $this->db->run(
            'DELETE FROM promote_oauth_states WHERE created_at < (NOW() - INTERVAL ' . self::STATE_TTL_SECONDS . ' SECOND)'
        );
        $this->db->run(
            'INSERT INTO promote_oauth_states (state, venue_id, destination_key, user_id, code_verifier)
             VALUES (?, ?, ?, ?, ?)',
            [$state, $venueId, self::DEST_KEY, $this->userId(), $codeVerifier]
        );

        $authorizeUrl = self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->ok(['authorize_url' => $authorizeUrl]);
    }

    // ── Step 2: callback (public — reached by browser redirect, no JWT) ────

    private function callback(Request $request): Response
    {
        $error = (string) ($request->query('error') ?? '');
        if ($error !== '') {
            return $this->finish('error', 'X authorization was ' . $error);
        }

        $code  = (string) ($request->query('code') ?? '');
        $state = (string) ($request->query('state') ?? '');
        if ($code === '' || $state === '') {
            return $this->finish('error', 'Missing code or state from X');
        }

        $row = $this->db->one('SELECT * FROM promote_oauth_states WHERE state = ?', [$state]);
        // Single-use: consume immediately regardless of outcome to prevent replay.
        $this->db->run('DELETE FROM promote_oauth_states WHERE state = ?', [$state]);

        if (!$row || db_timestamp_to_epoch((string) $row['created_at']) < time() - self::STATE_TTL_SECONDS) {
            return $this->finish('error', 'This authorization link expired or was already used — try connecting again');
        }

        $clientId     = (string) (getenv('TWITTER_CLIENT_ID') ?: '');
        $clientSecret = (string) (getenv('TWITTER_CLIENT_SECRET') ?: '');
        if (!$clientId || !$clientSecret) {
            return $this->finish('error', 'TWITTER_CLIENT_ID/TWITTER_CLIENT_SECRET are not set in .env');
        }

        $tokenResponse = $this->exchangeCode($code, (string) $row['code_verifier'], $clientId, $clientSecret);
        if (!isset($tokenResponse['access_token'])) {
            $detail = (string) ($tokenResponse['error_description'] ?? $tokenResponse['error'] ?? 'unknown error');
            return $this->finish('error', 'X token exchange failed: ' . $detail);
        }

        CredentialSettings::storeCredential(
            $this->db,
            (int) $row['venue_id'],
            (string) $row['destination_key'],
            (string) $tokenResponse['access_token'],
            isset($tokenResponse['refresh_token']) ? (string) $tokenResponse['refresh_token'] : null,
            null
        );

        return $this->finish('connected');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function exchangeCode(string $code, string $codeVerifier, string $clientId, string $clientSecret): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri(),
                'code_verifier' => $codeVerifier,
            ]),
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return json_decode($body, true) ?? [];
    }

    private function redirectUri(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/api/promote/oauth/twitter/callback';
    }

    /** Send the browser back into the SPA's Promote Settings page with a result to toast. */
    private function finish(string $status, ?string $message = null): Response
    {
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $query  = http_build_query(array_filter([
            'promote_oauth' => self::DEST_KEY,
            'oauth_status'  => $status,
            'oauth_message' => $message,
        ], static fn ($v) => $v !== null && $v !== ''));

        return Response::redirect($appUrl . '/?' . $query . '#promote-settings');
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
