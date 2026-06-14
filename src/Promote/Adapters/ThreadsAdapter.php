<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Meta Threads post adapter.
 *
 * Publishes to Threads via the Threads API (graph.threads.net).
 * Supports text-only posts and image posts (when an approved flyer is available).
 *
 * Publishing follows the same two-step pattern as Instagram:
 *   1. Create a media container  → POST /{user-id}/threads
 *   2. Publish the container     → POST /{user-id}/threads_publish
 *
 * API base:  https://graph.threads.net/v1.0
 * Auth:      Authorization: Bearer {threads_access_token}
 * Docs:      https://developers.facebook.com/docs/threads
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. In the Meta Developer Portal (developers.facebook.com), create an App
 *      of type "Business" and add the "Threads API" product.
 *   2. Under Permissions, request:
 *        threads_basic          (read profile / user ID)
 *        threads_content_publish (create posts)
 *   3. Complete App Review for both scopes (required for public use).
 *   4. Generate a User Access Token for the Threads account you want to post
 *      from.  Long-lived tokens last 60 days; use the token refresh endpoint
 *      to extend.  Exchange a short-lived token via:
 *        GET https://graph.threads.net/access_token
 *              ?grant_type=th_exchange_token
 *              &client_id={app-id}
 *              &client_secret={app-secret}
 *              &access_token={short-lived-token}
 *   5. Fetch your Threads user ID:
 *        GET https://graph.threads.net/v1.0/me?fields=id&access_token={token}
 *   6. Save both in Settings → Promote → Threads.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ThreadsAdapter
{
    private const BASE    = 'https://graph.threads.net/v1.0';
    private const TIMEOUT = 20;

    public function __construct(
        private readonly string $accessToken,
        private readonly string $threadsUserId,
    ) {}

    /**
     * @param  array       $event    DB events row (with venue join)
     * @param  array       $post     DB promote_posts row
     * @param  string      $text     Post text body (from threads variant)
     * @param  string|null $imageUrl Absolute public URL of approved flyer (optional)
     * @param  string      $sendMode 'now' | 'scheduled'
     * @return array{status, external_url, error_message, response_json}
     */
    public function dispatch(
        array   $event,
        array   $post,
        string  $text,
        ?string $imageUrl,
        string  $sendMode,
    ): array {
        try {
            $containerId = $imageUrl
                ? $this->createImageContainer($text, $imageUrl)
                : $this->createTextContainer($text);

            $mediaId   = $this->publishContainer($containerId);
            $permalink = $this->fetchPermalink($mediaId);

            return [
                'status'        => $sendMode === 'scheduled' ? 'queued' : 'sent',
                'external_url'  => $permalink,
                'error_message' => null,
                'response_json' => json_encode(['threads_media_id' => $mediaId]) ?: null,
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

    // ── Step 1a: text-only container ──────────────────────────────────────────

    private function createTextContainer(string $text): string
    {
        $data = $this->apiPost("/{$this->threadsUserId}/threads", [
            'media_type' => 'TEXT',
            'text'       => $text,
        ]);
        return $this->extractContainerId($data);
    }

    // ── Step 1b: image container ──────────────────────────────────────────────

    private function createImageContainer(string $text, string $imageUrl): string
    {
        $data = $this->apiPost("/{$this->threadsUserId}/threads", [
            'media_type' => 'IMAGE',
            'image_url'  => $imageUrl,
            'text'       => $text,
        ]);
        return $this->extractContainerId($data);
    }

    private function extractContainerId(array $data): string
    {
        $id = $data['id'] ?? null;
        if (!$id) {
            throw new \RuntimeException(
                'Threads API did not return a container ID — response: ' . json_encode($data)
            );
        }
        return (string) $id;
    }

    // ── Step 2: publish container ─────────────────────────────────────────────

    private function publishContainer(string $containerId): string
    {
        // Threads sometimes needs a brief moment before the container is ready
        usleep(500_000); // 0.5 s

        $data = $this->apiPost("/{$this->threadsUserId}/threads_publish", [
            'creation_id' => $containerId,
        ]);

        $mediaId = $data['id'] ?? null;
        if (!$mediaId) {
            throw new \RuntimeException(
                'Threads publish returned no media ID — response: ' . json_encode($data)
            );
        }

        return (string) $mediaId;
    }

    // ── Step 3: fetch permalink ───────────────────────────────────────────────

    private function fetchPermalink(string $mediaId): ?string
    {
        try {
            $data = $this->apiGet("/{$mediaId}", ['fields' => 'permalink']);
            return isset($data['permalink']) ? (string) $data['permalink'] : null;
        } catch (\Throwable) {
            return null; // permalink is nice-to-have; don't fail dispatch
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function apiPost(string $path, array $payload): array
    {
        $url = self::BASE . $path . '?access_token=' . urlencode($this->accessToken);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        return $this->handleResponse($ch, $path);
    }

    private function apiGet(string $path, array $params = []): array
    {
        $params['access_token'] = $this->accessToken;
        $url = self::BASE . $path . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        return $this->handleResponse($ch, $path);
    }

    private function handleResponse(\CurlHandle $ch, string $path): array
    {
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Threads cURL error on $path: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $msg  = $data['error']['message']       ?? "HTTP $status";
            $code = (int) ($data['error']['code']   ?? 0);
            $hint = $this->translateError($code);
            throw new \RuntimeException(
                "Threads API error ($status) on $path: $msg" . ($hint ? " — $hint" : '')
            );
        }

        return $data;
    }

    private function translateError(int $code): string
    {
        return match ($code) {
            190 => 'Access token is invalid or expired — regenerate or refresh it.',
            200 => 'Missing threads_content_publish permission — complete Meta App Review.',
            10  => 'App lacks Threads API access — add the Threads API product in the Meta Developer Portal.',
            default => '',
        };
    }
}
