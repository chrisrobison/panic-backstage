<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Twitter / X post adapter.
 *
 * Publishes a tweet via the X API v2 using an OAuth 2.0 User Access Token
 * (tweet.write scope required).  Text-only for now — attaching images
 * requires a separate media upload step (v1.1 endpoint) that needs Elevated
 * or Basic API access; add media upload once access is confirmed.
 *
 * API base:  https://api.twitter.com/2
 * Auth:      Authorization: Bearer {user_access_token}
 * Docs:      https://developer.twitter.com/en/docs/twitter-api/tweets/manage-tweets/api-reference/post-tweets
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Sign in at developer.twitter.com and create a Project + App.
 *   2. Under App Settings → User Authentication Settings:
 *        - Type of App: Web App / Automated App
 *        - App permissions: Read and write
 *        - Redirect URI: {APP_URL}/api/promote/oauth/twitter/callback
 *   3. Under Keys and Tokens → OAuth 2.0 Client ID and Client Secret: put both
 *      in TWITTER_CLIENT_ID / TWITTER_CLIENT_SECRET in .env.
 *   4. In Settings → Promote → Twitter / X, click "Connect X account" — this
 *      runs the OAuth 2.0 PKCE flow in-app (Panic\Promote\TwitterOAuth) and
 *      stores the resulting access_token/refresh_token automatically.
 *
 *   Alternative (no .env changes / no in-app button): generate a User Access
 *   Token manually with tweet.write + offline.access scopes via Postman or
 *   curl with PKCE (Authorize URL: https://twitter.com/i/oauth2/authorize,
 *   Token URL: https://api.twitter.com/2/oauth2/token) and paste the
 *   access_token/refresh_token directly into Settings → Promote → Twitter / X.
 *
 * Rate limits (Free tier, 2024):
 *   - 17 tweet.write calls per 15 minutes per user
 *   - 1 app per Free project; Basic tier raises limits significantly
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TwitterAdapter
{
    private const BASE    = 'https://api.twitter.com/2';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly string $accessToken,
    ) {}

    /**
     * @param  array  $event    DB events row (with venue join)
     * @param  array  $post     DB promote_posts row
     * @param  string $text     Tweet text (≤ 280 chars; truncated by CopyGenerator)
     * @param  string $sendMode 'now' | 'scheduled'
     * @return array{status, external_url, error_message, response_json}
     */
    public function dispatch(
        array  $event,
        array  $post,
        string $text,
        string $sendMode,
    ): array {
        try {
            $data    = $this->apiPost('/tweets', ['text' => $text]);
            $tweetId = $data['data']['id'] ?? null;

            if (!$tweetId) {
                throw new \RuntimeException(
                    'X API did not return a tweet ID — response: ' . json_encode($data)
                );
            }

            return [
                'status'        => $sendMode === 'scheduled' ? 'queued' : 'sent',
                'external_url'  => "https://x.com/i/web/status/{$tweetId}",
                'error_message' => null,
                'response_json' => json_encode(['tweet_id' => $tweetId]) ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => $e->getMessage(),
                'response_json' => null,
            ];
        }
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function apiPost(string $path, array $payload): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("X cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $detail = $data['detail'] ?? $data['title'] ?? "HTTP $status";
            $hint   = $this->translateError($status, $data);
            throw new \RuntimeException(
                "X API error ($status): $detail" . ($hint ? " — $hint" : '')
            );
        }

        return $data;
    }

    private function translateError(int $status, array $data): string
    {
        if ($status === 401) {
            return 'Access token is invalid or expired — re-authenticate via the X Developer Portal.';
        }
        if ($status === 403) {
            return 'App lacks tweet.write permission or the account is restricted from posting.';
        }
        if ($status === 429) {
            return 'Rate limit reached — Free tier allows ~17 tweets per 24 h. Retry later or upgrade to Basic.';
        }
        $type = (string) ($data['type'] ?? '');
        if (str_contains($type, 'DuplicateContent')) {
            return 'X rejected this as a duplicate tweet — edit the text slightly before retrying.';
        }
        return '';
    }
}
