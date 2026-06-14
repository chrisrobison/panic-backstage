<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Action modal endpoints for manual-submission destinations.
 *
 *   GET  /api/promote/events/{id}/posts/{postId}/action/{destKey}
 *        Returns variant content + credential config (non-sensitive fields only)
 *        so the frontend can build the action modal.
 *
 *   POST /api/promote/events/{id}/posts/{postId}/action/{destKey}/send
 *        Sends the channel variant via PHP mail() from events@panicbooking.com.
 *        Accepts optional JSON body { "to": "override@example.com" }.
 */
final class ActionEndpoint extends BaseEndpoint
{
    private const FROM_ADDRESS = 'events@panicbooking.com';
    private const FROM_NAME    = 'Mabuhay Gardens';

    /** Public config fields — safe to expose; no API keys or secrets. */
    private const PUBLIC_CONFIG_FIELDS = [
        'contact_email',
        'submission_url',
        'partner_url',
        'promoter_url',
        'artist_url',
        'artist_page_url',
        'event_platform_url',
    ];

    public function handle(Request $request): Response
    {
        $eventId = (int) ($this->params['eventId'] ?? 0);
        $postId  = (int) ($this->params['postId']  ?? 0);
        $destKey = (string) ($this->params['destKey'] ?? '');
        $subAction = (string) ($this->params['subAction'] ?? '');  // 'send' or ''

        if (!$eventId || !$postId || !$destKey) {
            return $this->notFound('Invalid action parameters');
        }

        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }

        // Verify the post belongs to this event
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }

        if ($request->method() === 'GET' && !$subAction) {
            return $this->getActionInfo($eventId, $postId, $destKey, $post);
        }

        if ($request->method() === 'POST' && $subAction === 'send') {
            return $this->sendEmail($request, $eventId, $postId, $destKey, $post);
        }

        return Response::json(['error' => 'Method not allowed'], 405);
    }

    // ── GET: action info ──────────────────────────────────────────────────────

    private function getActionInfo(
        int    $eventId,
        int    $postId,
        string $destKey,
        array  $post
    ): Response {
        $channel = $this->destKeyToChannel($destKey);

        $variant = $this->db->one(
            'SELECT * FROM promote_post_variants WHERE post_id = ? AND channel = ?',
            [$postId, $channel]
        );

        $dest = $this->db->one(
            'SELECT * FROM promote_destinations WHERE destination_key = ?',
            [$destKey]
        );

        // Load venue_id for credential lookup
        $event   = $this->db->one('SELECT venue_id FROM events WHERE id = ?', [$eventId]);
        $venueId = (int) ($event['venue_id'] ?? 1);

        $config = $this->publicConfig($destKey, $venueId);

        $group  = (string) ($dest['destination_group'] ?? '');
        $status = (string) ($dest['status'] ?? 'manual_submission');

        $canEmail = $this->canEmail($destKey, $config);
        $canForm  = $this->canForm($config);

        return $this->ok([
            'dest_key'    => $destKey,
            'dest_label'  => (string) ($dest['label'] ?? $destKey),
            'dest_group'  => $group,
            'dest_status' => $status,
            'channel'     => $channel,
            'variant'     => $variant ?: null,
            'config'      => $config,
            'can_email'   => $canEmail,
            'can_form'    => $canForm,
        ]);
    }

    // ── POST: send email via server ───────────────────────────────────────────

    private function sendEmail(
        Request $request,
        int     $eventId,
        int     $postId,
        string  $destKey,
        array   $post
    ): Response {
        $body    = $request->json();
        $channel = $this->destKeyToChannel($destKey);

        $variant = $this->db->one(
            'SELECT * FROM promote_post_variants WHERE post_id = ? AND channel = ?',
            [$postId, $channel]
        );

        $subject = trim((string) ($variant['title'] ?? $post['title'] ?? 'Event Announcement'));
        $text    = trim((string) ($variant['body']  ?? $post['master_text'] ?? ''));

        if (!$text) {
            return Response::json([
                'error' => 'No content to send — generate variants for this post first.',
            ], 422);
        }

        // Resolve "to" address: request body overrides stored credential
        $event   = $this->db->one('SELECT venue_id FROM events WHERE id = ?', [$eventId]);
        $venueId = (int) ($event['venue_id'] ?? 1);
        $config  = $this->publicConfig($destKey, $venueId);

        $to = trim((string) ($body['to'] ?? $config['contact_email'] ?? ''));

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return Response::json([
                'error' => 'No valid recipient address. '
                         . 'Add a contact_email in Promote Settings → ' . $destKey
                         . ', or enter one in the "To" field.',
            ], 422);
        }

        $fromHeader = self::FROM_NAME . ' <' . self::FROM_ADDRESS . '>';
        $headers    = implode("\r\n", [
            'From: '         . $fromHeader,
            'Reply-To: '     . $fromHeader,
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
            'X-Mailer: Panic Backstage Promote',
        ]);

        $sent = @mail($to, $subject, $text, $headers);

        if (!$sent) {
            return Response::json([
                'error' => 'Server mail() failed. Check that sendmail/postfix is configured on this host.',
            ], 500);
        }

        log_activity($this->db, $eventId, $this->userId(), 'promote_action_email_sent', [
            'dest_key' => $destKey,
            'post_id'  => $postId,
            'to'       => $to,
            'subject'  => $subject,
        ]);

        return $this->ok([
            'sent'    => true,
            'to'      => $to,
            'subject' => $subject,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Map destination key → channel key used in promote_post_variants.
     * Most destination keys match their channel key 1-to-1.
     */
    private function destKeyToChannel(string $destKey): string
    {
        return match ($destKey) {
            'facebook_page' => 'facebook',
            'email_general' => 'email',
            'email_press'   => 'email',
            default         => $destKey,
        };
    }

    /**
     * Load non-sensitive config fields from promote_credentials for this destination.
     */
    private function publicConfig(string $destKey, int $venueId): array
    {
        $cred = $this->db->one(
            'SELECT config FROM promote_credentials WHERE destination_key = ? AND venue_id = ?',
            [$destKey, $venueId]
        );

        if (!$cred || empty($cred['config'])) {
            return [];
        }

        $raw    = json_decode((string) $cred['config'], true) ?? [];
        $result = [];
        foreach (self::PUBLIC_CONFIG_FIELDS as $field) {
            if (!empty($raw[$field])) {
                $result[$field] = (string) $raw[$field];
            }
        }
        return $result;
    }

    /** True when there is a contact email or the destination is inherently email-based. */
    private function canEmail(string $destKey, array $config): bool
    {
        return !empty($config['contact_email'])
            || in_array($destKey, ['press', 'email_adhoc', 'sf_chronicle', 'sf_station'], true);
    }

    /** True when there is any known form/platform URL configured. */
    private function canForm(array $config): bool
    {
        foreach (['submission_url', 'partner_url', 'promoter_url', 'artist_url', 'artist_page_url', 'event_platform_url'] as $k) {
            if (!empty($config[$k])) {
                return true;
            }
        }
        return false;
    }
}
