<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\Database;
use Panic\Promote\Adapters\BlueSkyAdapter;
use Panic\Promote\Adapters\EmailAdapter;
use Panic\Promote\Adapters\EventbriteAdapter;
use Panic\Promote\Adapters\FacebookAdapter;
use Panic\Promote\Adapters\InstagramAdapter;
use Panic\Promote\Adapters\LumaAdapter;
use Panic\Promote\Adapters\ThreadsAdapter;
use Panic\Promote\Adapters\TikTokAdapter;
use Panic\Promote\Adapters\TwitterAdapter;

/**
 * Broadcast adapter dispatcher.
 *
 * Routes each (destination_key, event, post) tuple to the appropriate
 * platform adapter (real API) or falls back to the status-stub for
 * manual / unconfigured / disabled destinations.
 *
 * Credentials are loaded from promote_credentials (per-venue DB table),
 * with a fallback to .env vars for backwards compatibility.
 *
 * Adding a new real adapter:
 *   1. Create  src/Promote/Adapters/FooAdapter.php  implementing ::dispatch()
 *   2. Add a match case for the destination_key in dispatch() below
 *   3. Add a private loader method that reads from DB (+ env fallback)
 */
final class BroadcastAdapters
{
    public function __construct(private readonly Database $db) {}

    /**
     * Attempt a single broadcast result for one destination.
     *
     * @param  string $destKey    DB destination_key (e.g. 'eventbrite', 'facebook_page')
     * @param  string $destStatus DB destination status ('connected', 'needs_auth', 'manual_submission', 'disabled')
     * @param  string $sendMode   'now' | 'scheduled'
     * @param  array  $event      Full DB events row (joined with venues: venue_name, venue_city, etc.)
     * @param  array  $post       DB promote_posts row
     * @return array{status: string, external_url: string|null, error_message: string|null, response_json: string|null}
     */
    public function dispatch(
        string $destKey,
        string $destStatus,
        string $sendMode,
        array  $event,
        array  $post,
    ): array {
        return match ($destKey) {
            'eventbrite'                    => $this->eventbrite($sendMode, $event, $post),
            'luma'                          => $this->luma($sendMode, $event, $post),
            'facebook_page'                 => $this->facebook($sendMode, $event, $post),
            'instagram'                     => $this->instagram($sendMode, $event, $post),
            'tiktok'                        => $this->tiktok($sendMode, $event, $post),
            'twitter'                       => $this->twitter($sendMode, $event, $post),
            'threads'                       => $this->threads($sendMode, $event, $post),
            'bluesky'                       => $this->bluesky($sendMode, $event, $post),
            'email_general', 'email_press'  => $this->email($destKey, $sendMode, $event, $post),
            default                         => $this->stub($destStatus, $sendMode),
        };
    }

    // ── Real adapters ─────────────────────────────────────────────────────────

    private function eventbrite(string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential('eventbrite', (int) ($event['venue_id'] ?? 1));
        $apiKey = $cred['access_token'] ?? (string) (getenv('EVENTBRITE_API_KEY') ?: '');
        $config = $cred['config'] ?? [];
        $orgId  = (string) ($config['org_id'] ?? getenv('EVENTBRITE_ORG_ID') ?: '');
        $ebVid  = (string) ($config['eb_venue_id'] ?? getenv('EVENTBRITE_VENUE_ID') ?: '');

        if (!$apiKey) {
            return $this->noCredential('Eventbrite', '#promote-settings');
        }

        if (!$orgId) {
            return [
                'status'        => 'needs_auth',
                'external_url'  => null,
                'error_message' => 'Eventbrite Org ID not configured. '
                                 . 'Log in to eventbrite.com, create an Organizer, '
                                 . 'then save it in Promote › Settings.',
                'response_json' => null,
            ];
        }

        return (new EventbriteAdapter($apiKey, $orgId, $ebVid))->dispatch($event, $post, $sendMode);
    }

    private function luma(string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential('luma', (int) ($event['venue_id'] ?? 1));
        $apiKey = $cred['access_token'] ?? '';

        if (!$apiKey) {
            return $this->noCredential('Luma', '#promote-settings');
        }

        return (new LumaAdapter($apiKey))->dispatch($event, $post, $sendMode);
    }

    private function facebook(string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential('facebook_page', (int) ($event['venue_id'] ?? 1));
        $token  = $cred['access_token'] ?? '';
        $config = $cred['config'] ?? [];
        $pageId = (string) ($config['page_id'] ?? '');

        if (!$token) {
            return $this->noCredential('Facebook Page', '#promote-settings');
        }
        if (!$pageId) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Facebook Page ID not configured. Add it in Promote Settings → Facebook Page.',
                'response_json' => null,
            ];
        }

        $variant  = $this->fetchVariant((int) $post['id'], 'facebook');
        $message  = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');
        $imageUrl = $this->resolveImageUrl($post, (int) ($event['id'] ?? 0));

        return (new FacebookAdapter($token, $pageId))
            ->dispatch($event, $post, $message, $imageUrl, $sendMode);
    }

    private function instagram(string $sendMode, array $event, array $post): array
    {
        $cred      = $this->loadCredential('instagram', (int) ($event['venue_id'] ?? 1));
        $token     = $cred['access_token'] ?? '';
        $config    = $cred['config'] ?? [];
        $igAcctId  = (string) ($config['ig_account_id'] ?? '');

        if (!$token) {
            return $this->noCredential('Instagram', '#promote-settings');
        }
        if (!$igAcctId) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Instagram Business Account ID not configured. Add it in Promote Settings → Instagram.',
                'response_json' => null,
            ];
        }

        $variant  = $this->fetchVariant((int) $post['id'], 'instagram');
        $caption  = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');
        $imageUrl = $this->resolveImageUrl($post, (int) ($event['id'] ?? 0));

        return (new InstagramAdapter($token, $igAcctId))
            ->dispatch($event, $post, $caption, $imageUrl, $sendMode);
    }

    private function tiktok(string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential('tiktok', (int) ($event['venue_id'] ?? 1));
        $token  = $cred['access_token'] ?? '';
        $config = $cred['config'] ?? [];

        if (!$token) {
            return $this->noCredential('TikTok', '#promote-settings');
        }

        $privacyLevel = (string) ($config['privacy_level'] ?? 'PUBLIC_TO_EVERYONE');
        $handle       = (string) ($config['handle']        ?? getenv('VENUE_TIKTOK_HANDLE') ?: '');
        $variant      = $this->fetchVariant((int) $post['id'], 'tiktok');
        $caption      = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');
        $imageUrl     = $this->resolveImageUrl($post, (int) ($event['id'] ?? 0));

        return (new TikTokAdapter($token, $privacyLevel, $handle))
            ->dispatch($event, $post, $caption, $imageUrl, $sendMode);
    }

    private function twitter(string $sendMode, array $event, array $post): array
    {
        $cred  = $this->loadCredential('twitter', (int) ($event['venue_id'] ?? 1));
        $token = $cred['access_token'] ?? '';

        if (!$token) {
            return $this->noCredential('Twitter / X', '#promote-settings');
        }

        $variant = $this->fetchVariant((int) $post['id'], 'twitter');
        $text    = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');

        // Enforce 280-char hard limit (CopyGenerator already trims, but guard here too)
        if (mb_strlen($text) > 280) {
            $text = mb_substr($text, 0, 277) . '…';
        }

        return (new TwitterAdapter($token))->dispatch($event, $post, $text, $sendMode);
    }

    private function threads(string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential('threads', (int) ($event['venue_id'] ?? 1));
        $token  = $cred['access_token'] ?? '';
        $config = $cred['config'] ?? [];
        $userId = (string) ($config['threads_user_id'] ?? '');

        if (!$token) {
            return $this->noCredential('Threads', '#promote-settings');
        }
        if (!$userId) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Threads User ID not configured. Add it in Promote Settings → Threads.',
                'response_json' => null,
            ];
        }

        $variant  = $this->fetchVariant((int) $post['id'], 'threads');
        $text     = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');
        $imageUrl = $this->resolveImageUrl($post, (int) ($event['id'] ?? 0));

        return (new ThreadsAdapter($token, $userId))->dispatch($event, $post, $text, $imageUrl, $sendMode);
    }

    private function bluesky(string $sendMode, array $event, array $post): array
    {
        $cred       = $this->loadCredential('bluesky', (int) ($event['venue_id'] ?? 1));
        $appPassword = $cred['access_token'] ?? '';   // stored in access_token field
        $config     = $cred['config'] ?? [];
        $identifier = (string) ($config['identifier'] ?? '');

        if (!$appPassword) {
            return $this->noCredential('Bluesky', '#promote-settings');
        }
        if (!$identifier) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Bluesky handle not configured. Add it in Promote Settings → Bluesky.',
                'response_json' => null,
            ];
        }

        $variant = $this->fetchVariant((int) $post['id'], 'bluesky');
        $text    = $variant['body'] ?? (string) ($post['master_text'] ?? $post['title'] ?? '');

        // Enforce 300-char hard limit
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 297) . '…';
        }

        return (new BlueSkyAdapter($identifier, $appPassword))->dispatch($event, $post, $text, $sendMode);
    }

    private function email(string $destKey, string $sendMode, array $event, array $post): array
    {
        $cred   = $this->loadCredential($destKey, (int) ($event['venue_id'] ?? 1));
        $apiKey = $cred['access_token'] ?? '';
        $config = $cred['config'] ?? [];

        if (!$apiKey) {
            return $this->noCredential(
                $destKey === 'email_press' ? 'Press Email' : 'General Email',
                '#promote-settings'
            );
        }

        $provider   = (string) ($config['provider'] ?? '');
        $listId     = (string) ($config['list_id'] ?? '');
        $fromName   = (string) ($config['from_name'] ?? getenv('VENUE_NAME') ?: getenv('MAIL_FROM_NAME') ?: 'Venue');
        $fromEmail  = (string) ($config['from_email'] ?? getenv('VENUE_EMAIL') ?: getenv('MAIL_FROM_ADDRESS') ?: 'noreply@localhost');
        $senderId   = (int)    ($config['sender_id'] ?? 0);

        if (!$provider) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Email provider not configured. Set provider to "mailchimp" or "sendgrid" in Promote Settings.',
                'response_json' => null,
            ];
        }
        if (!$listId) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => 'Email list/audience ID not configured. Add it in Promote Settings.',
                'response_json' => null,
            ];
        }

        // Fetch the email variant for subject + body (channel = 'email')
        $variant = $this->fetchVariant((int) $post['id'], 'email');

        $subject  = $variant['title'] ?? (string) ($post['title'] ?? '');
        $bodyText = $variant['body']  ?? (string) ($post['master_text'] ?? '');

        if (!$subject) {
            $subject = 'Upcoming event at ' . (getenv('VENUE_NAME') ?: 'Our Venue');
        }

        $scheduledAt = ($sendMode === 'scheduled') ? ($post['scheduled_at'] ?? null) : null;

        return (new EmailAdapter($provider, $apiKey, $listId, $fromName, $fromEmail, $senderId))
            ->dispatch($event, $post, $subject, $bodyText, $sendMode, $scheduledAt);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Fetch a post variant row for the given channel.
     * Returns the DB row (with title + body) or null.
     */
    private function fetchVariant(int $postId, string $channel): ?array
    {
        return $this->db->one(
            'SELECT title, body FROM promote_post_variants WHERE post_id = ? AND channel = ?',
            [$postId, $channel]
        );
    }

    /**
     * Resolve the public HTTPS URL of the post's attached approved asset (flyer).
     *
     * The asset's file_path is stored relative to /public/, e.g.
     *   "uploads/events/42/flyer-abc123.jpg"
     * Combined with APP_URL this becomes the public image URL that external
     * platforms (Instagram, Facebook) can fetch.
     *
     * Returns null if no approved asset is attached to the post.
     */
    private function resolveImageUrl(array $post, int $eventId): ?string
    {
        $assetId = (int) ($post['asset_id'] ?? 0);

        // Try post's directly attached asset first
        if ($assetId) {
            $asset = $this->db->one(
                "SELECT file_path FROM event_assets WHERE id = ? AND approval_status = 'approved'",
                [$assetId]
            );
            if ($asset && !empty($asset['file_path'])) {
                return $this->filePathToUrl((string) $asset['file_path']);
            }
        }

        // Fall back to any approved flyer for the event
        if ($eventId > 0) {
            $asset = $this->db->one(
                "SELECT file_path FROM event_assets
                 WHERE event_id = ? AND asset_type = 'flyer' AND approval_status = 'approved'
                 ORDER BY created_at DESC LIMIT 1",
                [$eventId]
            );
            if ($asset && !empty($asset['file_path'])) {
                return $this->filePathToUrl((string) $asset['file_path']);
            }
        }

        return null;
    }

    private function filePathToUrl(string $filePath): string
    {
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        return $appUrl . '/' . ltrim($filePath, '/');
    }

    // ── Credential loader ─────────────────────────────────────────────────────

    /**
     * Load a promote_credentials row for the given destination + venue.
     * Returns an array with 'access_token', 'config' (decoded), etc., or [].
     */
    private function loadCredential(string $destKey, int $venueId): array
    {
        $row = $this->db->one(
            'SELECT access_token, refresh_token, token_expires_at, config, status
             FROM promote_credentials
             WHERE destination_key = ? AND venue_id = ? AND status = ?',
            [$destKey, $venueId, 'connected']
        );

        if (!$row) {
            return [];
        }

        // Decode the JSON config field
        if (!empty($row['config'])) {
            $row['config'] = json_decode((string) $row['config'], true) ?? [];
        } else {
            $row['config'] = [];
        }

        return $row;
    }

    // ── Stub fallback ─────────────────────────────────────────────────────────

    /**
     * Status-only stub for unimplemented / manual destinations.
     * No external API calls are made.
     */
    public function stub(string $destinationStatus, string $sendMode): array
    {
        $status = match ($destinationStatus) {
            'connected'         => $sendMode === 'scheduled' ? 'queued' : 'sent',
            'needs_auth'        => 'needs_auth',
            'manual_submission' => 'manual_required',
            'disabled'          => 'skipped',
            default             => 'manual_required',
        };

        return [
            'status'        => $status,
            'external_url'  => null,
            'error_message' => null,
            'response_json' => null,
        ];
    }

    private function noCredential(string $label, string $settingsPath): array
    {
        return [
            'status'        => 'needs_auth',
            'external_url'  => null,
            'error_message' => "$label credentials not configured. Visit $settingsPath to connect.",
            'response_json' => null,
        ];
    }
}
