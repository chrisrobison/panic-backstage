<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Instagram Business post adapter.
 *
 * Publishes an image post to an Instagram Business / Creator account via the
 * Instagram Graph API (which runs on the Facebook Graph API base URL).
 *
 * Publishing requires a two-step process:
 *   1. Create a media container → returns container_id
 *   2. Publish the container    → returns media_id
 *   3. Fetch the permalink       → returns the public post URL
 *
 * Instagram does NOT support text-only posts via the API — an image is required.
 * If no flyer image URL is available this adapter returns 'needs_auth' with a
 * clear instruction rather than 'failed', since the fix is to upload a flyer, not
 * to change credentials.
 *
 * API base:  https://graph.facebook.com/v21.0
 * Auth:      Authorization: Bearer {user_access_token}
 * Docs:      https://developers.facebook.com/docs/instagram-api/guides/content-publishing
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Create a Facebook Developer App at developers.facebook.com.
 *   2. Add the Instagram Graph API product.
 *   3. Connect your Instagram Business/Creator account to a Facebook Page.
 *   4. Request instagram_content_publish + instagram_basic permissions.
 *      (instagram_content_publish requires App Review for live use.)
 *   5. Generate a long-lived User Access Token with those scopes.
 *   6. Find your numeric Instagram Business Account ID via Graph Explorer:
 *        GET /me/accounts → find your page → GET /{page-id}?fields=instagram_business_account
 *   7. Save both in Settings → Promote → Instagram.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InstagramAdapter
{
    private const BASE    = 'https://graph.facebook.com/v21.0';
    private const TIMEOUT = 20;

    public function __construct(
        private readonly string $accessToken,
        private readonly string $igAccountId,
    ) {}

    /**
     * @param  array       $event     DB events row (with venue join)
     * @param  array       $post      DB promote_posts row
     * @param  string      $caption   Post caption (from instagram variant — includes hashtags)
     * @param  string|null $imageUrl  Absolute public URL of the approved flyer
     * @param  string      $sendMode  'now' | 'scheduled'
     * @return array{status, external_url, error_message, response_json}
     */
    public function dispatch(
        array   $event,
        array   $post,
        string  $caption,
        ?string $imageUrl,
        string  $sendMode,
    ): array {
        // Instagram Business API requires an image — text-only posts are not supported
        if (!$imageUrl) {
            return [
                'status'        => 'needs_auth',
                'external_url'  => null,
                'error_message' => 'Instagram requires a flyer image. Upload and approve a flyer in the event '
                                 . 'workspace, then attach it to this post before broadcasting.',
                'response_json' => null,
            ];
        }

        try {
            $mediaId     = $this->createContainer($caption, $imageUrl);
            $publishedId = $this->publishContainer($mediaId);
            $permalink   = $this->fetchPermalink($publishedId);

            $status = $sendMode === 'scheduled' ? 'queued' : 'sent';

            return [
                'status'        => $status,
                'external_url'  => $permalink,
                'error_message' => null,
                'response_json' => json_encode([
                    'ig_media_id'  => $publishedId,
                    'permalink'    => $permalink,
                ]) ?: null,
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

    // ── Step 1: create media container ───────────────────────────────────────

    private function createContainer(string $caption, string $imageUrl): string
    {
        $data = $this->apiPost("/{$this->igAccountId}/media", [
            'image_url'  => $imageUrl,
            'caption'    => $caption,
            'media_type' => 'IMAGE',
        ]);

        $containerId = $data['id'] ?? null;
        if (!$containerId) {
            throw new \RuntimeException(
                'Instagram did not return a container ID — response: ' . json_encode($data)
            );
        }

        // Poll until the container is FINISHED (usually immediate but can take a few seconds)
        return $this->waitForContainer((string) $containerId);
    }

    /**
     * Poll the container status until it's FINISHED or times out.
     * Instagram sometimes needs a moment to process the image before publish.
     */
    private function waitForContainer(string $containerId): string
    {
        $maxTries = 8;
        $delay    = 1;  // seconds

        for ($i = 0; $i < $maxTries; $i++) {
            if ($i > 0) {
                sleep($delay);
                $delay = min($delay * 2, 8);  // exponential back-off, max 8 s
            }

            $status = $this->apiGet("/{$containerId}", ['fields' => 'status_code,status']);
            $code   = $status['status_code'] ?? '';

            if ($code === 'FINISHED') {
                return $containerId;
            }
            if ($code === 'ERROR') {
                $msg = $status['status'] ?? 'Unknown container error';
                throw new \RuntimeException("Instagram media container failed: $msg");
            }
            // IN_PROGRESS or PUBLISHED → keep polling
        }

        throw new \RuntimeException("Instagram media container did not finish processing within the timeout.");
    }

    // ── Step 2: publish container ─────────────────────────────────────────────

    private function publishContainer(string $containerId): string
    {
        $data = $this->apiPost("/{$this->igAccountId}/media_publish", [
            'creation_id' => $containerId,
        ]);

        $mediaId = $data['id'] ?? null;
        if (!$mediaId) {
            throw new \RuntimeException(
                'Instagram media_publish returned no ID — response: ' . json_encode($data)
            );
        }

        return (string) $mediaId;
    }

    // ── Step 3: fetch permalink ───────────────────────────────────────────────

    private function fetchPermalink(string $mediaId): ?string
    {
        try {
            $data = $this->apiGet("/{$mediaId}", ['fields' => 'permalink']);
            return $data['permalink'] ?? null;
        } catch (\Throwable) {
            // Permalink is nice-to-have; don't fail the whole dispatch if this step errors
            return null;
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

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

        return $this->handleResponse($ch);
    }

    private function apiGet(string $path, array $params = []): array
    {
        $url = self::BASE . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ],
        ]);

        return $this->handleResponse($ch);
    }

    private function handleResponse(\CurlHandle $ch): array
    {
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Instagram cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $msg  = $data['error']['message']       ?? "HTTP $status";
            $code = $data['error']['code']           ?? 0;
            $hint = $this->translateIgError((int) $code);
            throw new \RuntimeException(
                "Instagram API error ($status): $msg" . ($hint ? " — $hint" : '')
            );
        }

        return $data;
    }

    private function translateIgError(int $code): string
    {
        return match ($code) {
            190     => 'Access Token is invalid or expired — regenerate it.',
            200     => 'Missing instagram_content_publish permission — check App Review status.',
            10      => 'App does not have Instagram Graph API permission — request it in the Facebook Developer App.',
            24      => 'Album creation is disabled for this account — enable it in Instagram settings.',
            9007    => 'Image URL is not publicly accessible — ensure the flyer is publicly reachable.',
            default => '',
        };
    }
}
