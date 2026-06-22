<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * TikTok photo post adapter.
 *
 * Publishes a photo post to a TikTok account via the Content Posting API v2.
 * Uses PULL_FROM_URL so TikTok fetches the flyer image directly from our server.
 *
 * Flow:
 *   1. POST /v2/post/publish/content/init/  → returns publish_id
 *   2. Poll /v2/post/publish/status/fetch/  → wait for PUBLISH_COMPLETE
 *   3. Extract item_id → build profile URL
 *
 * TikTok does NOT support text-only posts via the API — a photo is required.
 * If no flyer image is available the adapter returns 'needs_auth' with clear
 * instructions instead of 'failed', since the fix is to upload a flyer.
 *
 * API base:  https://open.tiktokapis.com/v2
 * Auth:      Authorization: Bearer {access_token}
 * Docs:      https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Create a TikTok developer account at developers.tiktok.com.
 *   2. Create an app and add the "Content Posting API" product.
 *   3. Request scopes: video.upload, video.publish (or user.info.basic + content posting).
 *   4. Complete App Review — TikTok requires review before live user tokens work.
 *   5. Implement the OAuth 2.0 flow to get a user access token for the
 *      Mabuhay Gardens TikTok account.
 *   6. Paste the access token in Settings → Promote → TikTok.
 *   Note: TikTok access tokens expire; implement refresh via the refresh endpoint
 *         or re-authenticate periodically.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TikTokAdapter
{
    private const BASE    = 'https://open.tiktokapis.com/v2';
    private const TIMEOUT = 20;

    /** Valid TikTok privacy level values. */
    private const PRIVACY_LEVELS = [
        'PUBLIC_TO_EVERYONE',
        'MUTUAL_FOLLOW_FRIENDS',
        'FOLLOWER_OF_CREATOR',
        'SELF_ONLY',
    ];

    public function __construct(
        private readonly string $accessToken,
        private readonly string $privacyLevel = 'PUBLIC_TO_EVERYONE',
        private readonly string $handle = '',
    ) {}

    /**
     * @param  array       $event     DB events row (with venue join)
     * @param  array       $post      DB promote_posts row
     * @param  string      $caption   Post caption (from tiktok variant — short + hashtags)
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
        if (!$imageUrl) {
            return [
                'status'        => 'needs_auth',
                'external_url'  => null,
                'error_message' => 'TikTok requires a flyer image. Upload and approve a flyer in the event '
                                 . 'workspace, then attach it to this post before broadcasting.',
                'response_json' => null,
            ];
        }

        try {
            $publishId = $this->initPost($caption, $imageUrl);
            [$status, $itemId] = $this->pollStatus($publishId, $sendMode);

            $externalUrl = $itemId
                ? ('https://www.tiktok.com/' . ($this->handle ? "@{$this->handle}/" : '') . "video/{$itemId}")
                : null;

            return [
                'status'        => $status,
                'external_url'  => $externalUrl,
                'error_message' => null,
                'response_json' => json_encode([
                    'tiktok_publish_id' => $publishId,
                    'tiktok_item_id'    => $itemId,
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

    // ── Step 1: initialise the post ───────────────────────────────────────────

    private function initPost(string $caption, string $imageUrl): string
    {
        $privacy = in_array($this->privacyLevel, self::PRIVACY_LEVELS, true)
            ? $this->privacyLevel
            : 'PUBLIC_TO_EVERYONE';

        $payload = [
            'post_info'   => [
                'title'           => $caption,
                'privacy_level'   => $privacy,
                'disable_duet'    => false,
                'disable_comment' => false,
                'disable_stitch'  => false,
            ],
            'source_info' => [
                'source'             => 'PULL_FROM_URL',
                'photo_images'       => [$imageUrl],
                'photo_cover_index'  => 0,
            ],
            'post_mode'  => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ];

        $data = $this->apiRequest('POST', '/post/publish/content/init/', $payload);

        $publishId = $data['data']['publish_id'] ?? null;
        if (!$publishId) {
            throw new \RuntimeException(
                'TikTok did not return a publish_id — response: ' . json_encode($data)
            );
        }

        return (string) $publishId;
    }

    // ── Step 2: poll for completion ───────────────────────────────────────────

    /**
     * Poll the publish status up to 6 times with exponential back-off.
     * Returns ['sent'|'queued', item_id|null].
     *
     * If TikTok hasn't finished within the poll window we return 'queued' so
     * the broadcast result stays readable — the publish_id is stored in
     * response_json so the operator can verify manually.
     */
    private function pollStatus(string $publishId, string $sendMode): array
    {
        $maxTries = 6;
        $delay    = 1;  // seconds

        for ($i = 0; $i < $maxTries; $i++) {
            if ($i > 0) {
                sleep($delay);
                $delay = min($delay * 2, 16);
            }

            $data   = $this->apiRequest('POST', '/post/publish/status/fetch/', [
                'publish_id' => $publishId,
            ]);
            $status = $data['data']['status']                             ?? '';
            $itemId = $data['data']['published_element']['item_id'] ?? null;

            if ($status === 'PUBLISH_COMPLETE') {
                return ['sent', $itemId ? (string) $itemId : null];
            }

            if ($status === 'FAILED') {
                $failReason = $data['data']['fail_reason'] ?? 'Unknown failure';
                throw new \RuntimeException("TikTok publish failed: $failReason");
            }

            // IN_PROGRESS or PROCESSING_UPLOAD → keep polling
        }

        // Timed out — post is likely still processing; treat as queued
        return [$sendMode === 'scheduled' ? 'queued' : 'queued', null];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function apiRequest(string $method, string $path, array $payload): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);

        $body   = (string) curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("TikTok cURL error: $err");
        }

        $data = json_decode($body, true) ?? [];

        // TikTok returns HTTP 200 even for errors; check error.code field
        $errorCode = $data['error']['code'] ?? 'ok';
        if ($errorCode !== 'ok' && $errorCode !== '') {
            $msg    = $data['error']['message'] ?? "Error code: $errorCode";
            $logId  = $data['error']['log_id']  ?? '';
            $hint   = $this->translateTikTokError($errorCode);
            throw new \RuntimeException(
                "TikTok API error: $msg" .
                ($hint  ? " — $hint"         : '') .
                ($logId ? " (log: $logId)"   : '')
            );
        }

        if ($code >= 400) {
            throw new \RuntimeException("TikTok HTTP error $code: $body");
        }

        return $data;
    }

    private function translateTikTokError(string $code): string
    {
        return match ($code) {
            'access_token_invalid'       => 'Access token is invalid or expired — re-authenticate in Promote Settings.',
            'access_token_expired'       => 'Access token has expired — re-authenticate in Promote Settings.',
            'scope_not_authorized'       => 'App is missing content posting scope — check TikTok App Review status.',
            'spam_risk_too_many_posts'   => 'Daily posting limit reached — try again tomorrow.',
            'spam_risk_user_banned_from_posting' => 'Account is temporarily banned from posting on TikTok.',
            'privacy_level_option_mismatch' => 'Privacy level not available for this account type.',
            'url_ownership_unverified'   => 'Image URL domain is not verified in TikTok Developer settings.',
            default                      => '',
        };
    }
}
