<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Post CRUD + variant sub-routes.
 *
 *   GET    /api/promote/campaigns/{id}/posts
 *   POST   /api/promote/campaigns/{id}/posts
 *   GET    /api/promote/campaigns/{id}/posts/{postId}
 *   PATCH  /api/promote/campaigns/{id}/posts/{postId}
 *   DELETE /api/promote/campaigns/{id}/posts/{postId}
 *
 *   POST   /api/promote/campaigns/{id}/posts/{postId}/variants/generate
 *   PATCH  /api/promote/campaigns/{id}/posts/{postId}/variants/{variantId}
 */
final class Posts extends BaseEndpoint
{
    private const STATUSES = ['draft', 'approved', 'scheduled', 'sent', 'archived'];

    public function handle(Request $request): Response
    {
        $campaignId = (int) ($this->params['campaignId'] ?? 0);
        $postId     = (int) ($this->params['postId'] ?? 0);
        $sub        = $this->params['sub'] ?? null;   // 'variants' or null
        $subId      = (int) ($this->params['subId'] ?? 0);

        if (!$campaignId) {
            return $this->notFound('Campaign not found');
        }

        $campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }

        $eventId    = (int) $campaign['event_id'];
        $capability = $request->method() === 'GET' ? 'read_event' : 'edit_event';
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }

        // Variant sub-routes
        if ($sub === 'variants') {
            return $this->handleVariants($request, $campaignId, $postId, $subId, $eventId);
        }

        return match ($request->method()) {
            'GET'    => $postId ? $this->show($campaignId, $postId) : $this->index($campaignId),
            'POST'   => $this->create($request, $campaignId, $eventId),
            'PATCH'  => $this->update($request, $campaignId, $postId),
            'DELETE' => $this->delete($campaignId, $postId, $eventId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── Post list ────────────────────────────────────────────────────────────

    private function index(int $campaignId): Response
    {
        $posts = $this->db->all(
            'SELECT p.*, u.name created_by_name
             FROM promote_posts p LEFT JOIN users u ON u.id = p.created_by_user_id
             WHERE p.campaign_id = ? ORDER BY p.created_at DESC',
            [$campaignId]
        );
        foreach ($posts as &$post) {
            $post['variants'] = $this->db->all(
                'SELECT * FROM promote_post_variants WHERE post_id = ? ORDER BY channel',
                [(int) $post['id']]
            );
        }
        unset($post);
        return $this->ok(['posts' => $posts]);
    }

    // ── Post detail ──────────────────────────────────────────────────────────

    private function show(int $campaignId, int $postId): Response
    {
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND campaign_id = ?',
            [$postId, $campaignId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }
        $post['variants'] = $this->db->all(
            'SELECT * FROM promote_post_variants WHERE post_id = ? ORDER BY channel',
            [$postId]
        );
        return $this->ok(['post' => $post]);
    }

    // ── Create post ──────────────────────────────────────────────────────────

    private function create(Request $request, int $campaignId, int $eventId): Response
    {
        $body = $request->body();
        if (empty($body['title'])) {
            return Response::json(['error' => 'title is required'], 422);
        }
        $status = (string) ($body['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            return Response::json(['error' => 'Invalid status'], 422);
        }
        if (!empty($body['asset_id'])) {
            $asset = $this->db->one(
                'SELECT id FROM event_assets WHERE id = ? AND event_id = ?',
                [(int) $body['asset_id'], $eventId]
            );
            if (!$asset) {
                return Response::json(['error' => 'Asset does not belong to this event'], 422);
            }
        }
        $id = $this->db->insert(
            'INSERT INTO promote_posts (campaign_id, asset_id, title, master_text, target_url, status, scheduled_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $campaignId,
                !empty($body['asset_id']) ? (int) $body['asset_id'] : null,
                (string) $body['title'],
                $body['master_text'] ?? null,
                $body['target_url'] ?? null,
                $status,
                !empty($body['scheduled_at']) ? $body['scheduled_at'] : null,
                $this->userId(),
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'promote post created', ['post_id' => $id, 'campaign_id' => $campaignId]);
        $post = $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$id]);
        $post['variants'] = [];
        return $this->ok(['post' => $post]);
    }

    // ── Update post ──────────────────────────────────────────────────────────

    private function update(Request $request, int $campaignId, int $postId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND campaign_id = ?',
            [$postId, $campaignId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }
        $body    = $request->body();
        $status  = isset($body['status']) && in_array($body['status'], self::STATUSES, true)
            ? $body['status'] : (string) $post['status'];
        $title   = ($body['title'] ?? '') !== '' ? (string) $body['title'] : (string) $post['title'];
        $assetId = array_key_exists('asset_id', $body)
            ? ($body['asset_id'] !== null && $body['asset_id'] !== '' ? (int) $body['asset_id'] : null)
            : $post['asset_id'];

        $this->db->run(
            'UPDATE promote_posts SET title = ?, master_text = ?, target_url = ?, status = ?, asset_id = ?, scheduled_at = ? WHERE id = ? AND campaign_id = ?',
            [
                $title,
                array_key_exists('master_text', $body) ? $body['master_text'] : $post['master_text'],
                array_key_exists('target_url', $body) ? $body['target_url'] : $post['target_url'],
                $status,
                $assetId,
                array_key_exists('scheduled_at', $body) ? ($body['scheduled_at'] ?: null) : $post['scheduled_at'],
                $postId,
                $campaignId,
            ]
        );
        $updated = $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$postId]);
        $updated['variants'] = $this->db->all(
            'SELECT * FROM promote_post_variants WHERE post_id = ? ORDER BY channel',
            [$postId]
        );
        return $this->ok(['post' => $updated]);
    }

    // ── Delete post ──────────────────────────────────────────────────────────

    private function delete(int $campaignId, int $postId, int $eventId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT id FROM promote_posts WHERE id = ? AND campaign_id = ?',
            [$postId, $campaignId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }
        $this->db->run('DELETE FROM promote_posts WHERE id = ? AND campaign_id = ?', [$postId, $campaignId]);
        log_activity($this->db, $eventId, $this->userId(), 'promote post deleted', ['post_id' => $postId]);
        return Response::noContent();
    }

    // ── Variant sub-routes ────────────────────────────────────────────────────

    private function handleVariants(Request $request, int $campaignId, int $postId, int $variantId, int $eventId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND campaign_id = ?',
            [$postId, $campaignId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }

        // POST .../variants/generate — "generate" is passed as $variantId (non-int → 0) via segments
        // Actually: segments[5] = 'generate', so $sub='variants' and $subId=0
        // We detect generate by checking the raw segment
        $rawSub = $this->params['rawVariantAction'] ?? null;

        // Check if this is the generate endpoint: URL is .../variants/generate
        // The Kernel stores segments[5] in $params['sub'] when it's 'variants',
        // and segments[6] in $params['subId']. So 'generate' would be segments[5]
        // but since sub='variants', segments[5] IS the subId slot.
        // We detect generate by $subId==0 AND method==POST
        if ($request->method() === 'POST' && $variantId === 0) {
            // generate action
            return $this->generateVariants($post, $eventId);
        }

        // PATCH .../variants/{variantId}
        if ($request->method() === 'PATCH' && $variantId > 0) {
            return $this->updateVariant($request, $postId, $variantId);
        }

        return Response::methodNotAllowed();
    }

    private function generateVariants(array $post, int $eventId): Response
    {
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        $generator = new CopyGenerator();
        $variants  = $generator->generate($post, $event ?? []);

        foreach ($variants as $variant) {
            $this->db->run(
                'INSERT INTO promote_post_variants (post_id, channel, title, body, status, warnings_json)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                   warnings_json = VALUES(warnings_json), status = VALUES(status)',
                [
                    (int) $post['id'],
                    $variant['channel'],
                    $variant['title'] ?? null,
                    $variant['body'] ?? '',
                    'draft',
                    json_encode($variant['warnings'] ?? []),
                ]
            );
        }

        $saved = $this->db->all(
            'SELECT * FROM promote_post_variants WHERE post_id = ? ORDER BY channel',
            [(int) $post['id']]
        );
        return $this->ok(['variants' => $saved]);
    }

    private function updateVariant(Request $request, int $postId, int $variantId): Response
    {
        $variant = $this->db->one(
            'SELECT * FROM promote_post_variants WHERE id = ? AND post_id = ?',
            [$variantId, $postId]
        );
        if (!$variant) {
            return $this->notFound('Variant not found');
        }
        $body           = $request->body();
        $allowedStatuses = ['draft', 'ready', 'needs_review', 'approved'];
        $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true)
            ? $body['status'] : (string) $variant['status'];

        $this->db->run(
            'UPDATE promote_post_variants SET title = ?, body = ?, status = ? WHERE id = ? AND post_id = ?',
            [
                array_key_exists('title', $body) ? $body['title'] : $variant['title'],
                array_key_exists('body', $body)  ? $body['body']  : $variant['body'],
                $status,
                $variantId,
                $postId,
            ]
        );
        $updated = $this->db->one('SELECT * FROM promote_post_variants WHERE id = ?', [$variantId]);
        return $this->ok(['variant' => $updated]);
    }
}
