<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Facebook Page post adapter.
 *
 * Publishes to a Facebook Page via the Graph API.
 * - If a public flyer image URL is supplied → /photos endpoint (image + caption).
 * - If no image → /feed endpoint (text post + optional link).
 *
 * API base:  https://graph.facebook.com/v21.0
 * Auth:      Authorization: Bearer {page_access_token}
 * Docs:      https://developers.facebook.com/docs/pages/publishing
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Create a Facebook Developer App at developers.facebook.com.
 *   2. Add the Pages API product. Get Pages Read Engagement + Pages Manage Posts
 *      permissions approved (requires App Review for public use).
 *   3. Generate a long-lived Page Access Token for the Mabuhay Gardens page.
 *   4. Find the numeric Page ID (visible in Page settings or via Graph Explorer).
 *   5. Save both in Settings → Promote → Facebook Page.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class FacebookAdapter
{
    private const BASE    = 'https://graph.facebook.com/v21.0';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly string $pageToken,
        private readonly string $pageId,
    ) {}

    /**
     * @param  array       $event     DB events row (with venue join)
     * @param  array       $post      DB promote_posts row
     * @param  string      $message   Post body text (from facebook variant)
     * @param  string|null $imageUrl  Absolute public URL of the approved flyer (optional)
     * @param  string      $sendMode  'now' | 'scheduled'
     * @return array{status, external_url, error_message, response_json}
     */
    public function dispatch(
        array   $event,
        array   $post,
        string  $message,
        ?string $imageUrl,
        string  $sendMode,
    ): array {
        try {
            [$postId, $externalUrl] = $imageUrl
                ? $this->photoPost($message, $imageUrl)
                : $this->feedPost($message, $post);

            $status = $sendMode === 'scheduled' ? 'queued' : 'sent';

            return [
                'status'        => $status,
                'external_url'  => $externalUrl,
                'error_message' => null,
                'response_json' => json_encode(['facebook_post_id' => $postId]) ?: null,
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

    // ── Photo post ────────────────────────────────────────────────────────────

    /**
     * POST /{page_id}/photos — creates a photo post with a caption.
     * Response: { "id": "photo_id", "post_id": "page_id_post_id" }
     */
    private function photoPost(string $caption, string $imageUrl): array
    {
        $data = $this->apiPost("/{$this->pageId}/photos", [
            'url'      => $imageUrl,
            'caption'  => $caption,
            'published' => true,
        ]);

        $photoId = $data['id'] ?? null;
        $postId  = $data['post_id'] ?? null;

        if (!$photoId) {
            throw new \RuntimeException(
                'Facebook did not return a photo ID — response: ' . json_encode($data)
            );
        }

        // Build the page post URL from post_id if available
        $url = $postId
            ? $this->buildPostUrl($postId)
            : "https://www.facebook.com/photo.php?fbid={$photoId}";

        return [$postId ?? $photoId, $url];
    }

    // ── Text / link post ──────────────────────────────────────────────────────

    /**
     * POST /{page_id}/feed — creates a text post, optionally with a link.
     * Response: { "id": "page_id_post_id" }
     */
    private function feedPost(string $message, array $post): array
    {
        $payload = ['message' => $message];

        $ticketUrl = (string) ($post['target_url'] ?? '');
        if ($ticketUrl) {
            $payload['link'] = $ticketUrl;
        }

        $data = $this->apiPost("/{$this->pageId}/feed", $payload);

        $compositeId = $data['id'] ?? null;
        if (!$compositeId) {
            throw new \RuntimeException(
                'Facebook did not return a post ID — response: ' . json_encode($data)
            );
        }

        return [$compositeId, $this->buildPostUrl($compositeId)];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a canonical page post URL from the composite "page_post" ID.
     * The Graph API returns IDs in the form "{pageId}_{postId}".
     */
    private function buildPostUrl(string $compositeId): string
    {
        // Composite form: "123456789_987654321" → post portion is "987654321"
        $parts  = explode('_', $compositeId, 2);
        $postId = $parts[1] ?? $compositeId;
        return "https://www.facebook.com/permalink.php?story_fbid={$postId}&id={$this->pageId}";
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
                'Authorization: Bearer ' . $this->pageToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Facebook cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $msg  = $data['error']['message']    ?? "HTTP $status";
            $code = $data['error']['code']        ?? 0;
            $sub  = $data['error']['error_subcode'] ?? 0;
            $hint = $this->translateFbError((int) $code, (int) $sub);
            throw new \RuntimeException(
                "Facebook API error ($status): $msg" . ($hint ? " — $hint" : '')
            );
        }

        return $data;
    }

    /**
     * Map common Graph API error codes to actionable hints.
     * Docs: https://developers.facebook.com/docs/graph-api/guides/error-handling
     */
    private function translateFbError(int $code, int $sub): string
    {
        return match (true) {
            $code === 190              => 'Page Access Token is invalid or expired — regenerate it.',
            $code === 200              => 'Missing Pages Manage Posts permission — check App Review status.',
            $code === 368              => 'Temporary block on posting — wait and retry.',
            $code === 100 && $sub === 33 => 'Page ID not found — verify the numeric Page ID.',
            $code === 10               => 'App does not have permission — request Pages API in App Review.',
            default                    => '',
        };
    }
}
