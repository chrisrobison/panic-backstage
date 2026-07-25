<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Post CRUD + variant sub-routes. Also the Social Queue's workflow engine —
 * see the class-level Social Queue note further down and
 * database/migrations/082_add_social_queue.sql.
 *
 *   GET    /api/promote/events/{id}/posts
 *   POST   /api/promote/events/{id}/posts
 *   GET    /api/promote/events/{id}/posts/{postId}
 *   PATCH  /api/promote/events/{id}/posts/{postId}
 *   DELETE /api/promote/events/{id}/posts/{postId}
 *   POST   /api/promote/events/{id}/posts/{postId}/approve         approves the CURRENT revision
 *   POST   /api/promote/events/{id}/posts/{postId}/mark-published  records the public URL, status -> published
 *
 *   POST   /api/promote/events/{id}/posts/{postId}/variants/generate
 *   PATCH  /api/promote/events/{id}/posts/{postId}/variants/{variantId}
 *
 * Social Queue workflow (spec): Draft -> Needs Assets -> Ready for Review ->
 * Changes Requested -> Approved -> Scheduled -> Awaiting Manual Publish ->
 * Published -> Verified -> Archived. Approval is tied to a specific content
 * revision (approved_content_hash) — update() recomputes the content hash
 * on every save and automatically drops an 'approved'/'scheduled'/
 * 'awaiting_manual_publish' post back to 'changes_requested' the moment the
 * content actually changes, rather than leaving a stale approval in place.
 * Entering 'awaiting_manual_publish' auto-creates a Tasks-app task (see
 * ensureManualPublishTask()) carrying the approved caption/media/link, for
 * platforms with no publish API — reusing the existing Tasks app rather
 * than a parallel checklist, same convention as Leads\Onboarding.
 */
final class Posts extends BaseEndpoint
{
    private const STATUSES = [
        'draft', 'needs_assets', 'ready_for_review', 'changes_requested', 'approved',
        'scheduled', 'awaiting_manual_publish', 'sent', 'published', 'verified', 'archived',
    ];

    /** Statuses whose approval is invalidated by a content-changing edit. */
    private const APPROVAL_LOCKED_STATUSES = ['approved', 'scheduled', 'awaiting_manual_publish'];

    public function handle(Request $request): Response
    {
        $eventId = (int) ($this->params['eventId'] ?? 0);
        $postId  = (int) ($this->params['postId'] ?? 0);
        $sub     = $this->params['sub'] ?? null;   // 'variants', 'approve', 'mark-published', or null
        $subId   = (int) ($this->params['subId'] ?? 0);

        if (!$eventId) {
            return $this->notFound('Event not found');
        }

        $capability = $request->method() === 'GET' ? 'read_event' : 'edit_event';
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }

        if ($sub === 'variants') {
            return $this->handleVariants($request, $eventId, $postId, $subId);
        }
        if ($sub === 'approve' && $request->method() === 'POST') {
            return $this->approve($eventId, $postId);
        }
        if ($sub === 'mark-published' && $request->method() === 'POST') {
            return $this->markPublished($request, $eventId, $postId);
        }

        return match ($request->method()) {
            'GET'    => $postId ? $this->show($eventId, $postId) : $this->index($eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, $postId),
            'DELETE' => $this->delete($eventId, $postId),
            default  => Response::methodNotAllowed(),
        };
    }

    /** Hash of the fields that make up "the content" a review actually approved. */
    private function contentHash(string $title, ?string $masterText, ?string $targetUrl, ?int $assetId): string
    {
        return hash('sha256', $title . '|' . ($masterText ?? '') . '|' . ($targetUrl ?? '') . '|' . ($assetId ?? ''));
    }

    // ── Post list ────────────────────────────────────────────────────────────

    private function index(int $eventId): Response
    {
        $posts = $this->db->all(
            'SELECT p.*, u.name created_by_name
             FROM promote_posts p LEFT JOIN users u ON u.id = p.created_by_user_id
             WHERE p.event_id = ? ORDER BY p.created_at DESC',
            [$eventId]
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

    private function show(int $eventId, int $postId): Response
    {
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
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

    private function create(Request $request, int $eventId): Response
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
        $assetId = !empty($body['asset_id']) ? (int) $body['asset_id'] : null;
        $approvalTier = in_array($body['approval_tier'] ?? '', ['routine', 'manager'], true) ? $body['approval_tier'] : 'routine';
        $hash = $this->contentHash((string) $body['title'], $body['master_text'] ?? null, $body['target_url'] ?? null, $assetId);

        $id = $this->db->insert(
            'INSERT INTO promote_posts (event_id, asset_id, title, master_text, target_url, status, approval_tier, content_hash, scheduled_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $assetId,
                (string) $body['title'],
                $body['master_text'] ?? null,
                $body['target_url'] ?? null,
                $status,
                $approvalTier,
                $hash,
                !empty($body['scheduled_at']) ? $body['scheduled_at'] : null,
                $this->userId(),
            ]
        );
        log_activity($this->db, $eventId, $this->userId(), 'promote post created', ['post_id' => $id]);
        $post = $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$id]);
        $post['variants'] = [];
        return $this->ok(['post' => $post]);
    }

    // ── Update post ──────────────────────────────────────────────────────────

    private function update(Request $request, int $eventId, int $postId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
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
        $masterText = array_key_exists('master_text', $body) ? $body['master_text'] : $post['master_text'];
        $targetUrl  = array_key_exists('target_url', $body) ? $body['target_url'] : $post['target_url'];

        // Approval applies to a specific revision (spec) — a content-
        // changing edit to a post whose approval already covers an earlier
        // revision invalidates it, dropping it back to 'changes_requested'
        // instead of whatever status was requested. Deliberately keyed off
        // "$status came out the same as the post's current status" rather
        // than "the caller omitted status" — the existing Promote editor
        // form (promote.js) always submits the dropdown's current value,
        // which equals the post's existing status whenever the user edited
        // content without touching that dropdown, so this still catches it.
        // A caller that explicitly picks a genuinely different status (or
        // calls approve(), which sets approved_content_hash directly and
        // doesn't go through update() at all) is respected as-is.
        $newHash = $this->contentHash($title, $masterText, $targetUrl, $assetId);
        $contentChanged = $newHash !== (string) ($post['content_hash'] ?? '');
        $approvalInvalidated = false;
        if ($contentChanged && in_array((string) $post['status'], self::APPROVAL_LOCKED_STATUSES, true)
            && $status === (string) $post['status']
        ) {
            $status = 'changes_requested';
            $approvalInvalidated = true;
        }

        $this->db->run(
            'UPDATE promote_posts
             SET title = ?, master_text = ?, target_url = ?, status = ?, asset_id = ?, scheduled_at = ?, content_hash = ?
             WHERE id = ? AND event_id = ?',
            [
                $title, $masterText, $targetUrl, $status, $assetId,
                array_key_exists('scheduled_at', $body) ? ($body['scheduled_at'] ?: null) : $post['scheduled_at'],
                $newHash, $postId, $eventId,
            ]
        );
        if ($approvalInvalidated) {
            log_activity($this->db, $eventId, $this->userId(), 'promote post approval invalidated by edit', ['post_id' => $postId]);
        }

        if ($status === 'awaiting_manual_publish' && (string) $post['status'] !== 'awaiting_manual_publish') {
            $this->ensureManualPublishTask($eventId, $postId);
        }

        $updated = $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$postId]);
        $updated['variants'] = $this->db->all(
            'SELECT * FROM promote_post_variants WHERE post_id = ? ORDER BY channel',
            [$postId]
        );
        return $this->ok(['post' => $updated]);
    }

    // ── Delete post ──────────────────────────────────────────────────────────

    private function delete(int $eventId, int $postId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT id FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }
        $this->db->run('DELETE FROM promote_posts WHERE id = ? AND event_id = ?', [$postId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'promote post deleted', ['post_id' => $postId]);
        return Response::noContent();
    }

    // ── Approve / publish ────────────────────────────────────────────────────

    /**
     * Approves the CURRENT revision (content_hash) — not a generic "set
     * status=approved". Routine posts need one approval; manager-tier posts
     * (paid ads, policy statements, cancellations, controversial
     * announcements — spec) additionally require manage_campaigns, a stand-
     * in for "manager approval" until a dedicated approval-tier capability
     * is worth adding on its own.
     */
    private function approve(int $eventId, int $postId): Response
    {
        $post = $this->db->one('SELECT * FROM promote_posts WHERE id = ? AND event_id = ?', [$postId, $eventId]);
        if (!$post) {
            return $this->notFound('Post not found');
        }
        if ((string) $post['approval_tier'] === 'manager' && !$this->hasGlobalCapability('manage_campaigns')) {
            return $this->forbidden('This post requires manager approval');
        }
        $this->db->run(
            "UPDATE promote_posts SET status = 'approved', approved_content_hash = content_hash,
                                       approved_by_user_id = ?, approved_at = NOW()
             WHERE id = ?",
            [$this->userId(), $postId]
        );
        log_activity($this->db, $eventId, $this->userId(), 'promote post approved', ['post_id' => $postId]);
        return $this->ok(['post' => $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$postId])]);
    }

    /**
     * Records the public URL for a post published on a manual (no-API)
     * platform (or confirms an API-published one) and moves it to
     * 'published' (or 'verified' if $request->body('verified') is truthy —
     * i.e. staff have actually confirmed the post is live).
     */
    private function markPublished(Request $request, int $eventId, int $postId): Response
    {
        $post = $this->db->one('SELECT * FROM promote_posts WHERE id = ? AND event_id = ?', [$postId, $eventId]);
        if (!$post) {
            return $this->notFound('Post not found');
        }
        $url = trim((string) $request->body('public_post_url', ''));
        $verified = (bool) $request->body('verified', false);
        $this->db->run(
            'UPDATE promote_posts SET status = ?, public_post_url = ? WHERE id = ?',
            [$verified ? 'verified' : 'published', $url ?: $post['public_post_url'], $postId]
        );
        if ($post['related_task_id']) {
            $this->db->run("UPDATE tasks SET status = 'done', completed_at = NOW() WHERE id = ?", [$post['related_task_id']]);
        }
        log_activity($this->db, $eventId, $this->userId(), 'promote post published', ['post_id' => $postId, 'url' => $url]);
        return $this->ok(['post' => $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$postId])]);
    }

    /**
     * Reuses the standalone Tasks app (task_documents/tasks, migration
     * 069_add_tasks_app.sql) for the "someone has to actually click publish"
     * step on a platform with no API — same "don't build a parallel
     * checklist" reasoning as Leads\Onboarding::applyTaskTemplate(). Finds
     * or creates one shared "Social Publishing" task document (created once,
     * reused for every manual-publish post from then on) and files one task
     * per post into it, carrying the approved caption so whoever publishes
     * doesn't have to go dig it out of the post record.
     */
    private function ensureManualPublishTask(int $eventId, int $postId): void
    {
        $post = $this->db->one('SELECT * FROM promote_posts WHERE id = ?', [$postId]);
        if (!$post || $post['related_task_id']) {
            return; // already has one
        }

        $doc = $this->db->one("SELECT id FROM task_documents WHERE name = 'Social Publishing' LIMIT 1");
        $docId = $doc['id'] ?? null;
        if (!$docId) {
            $docId = $this->db->insert(
                "INSERT INTO task_documents (name, icon, color) VALUES ('Social Publishing', 'fa-solid fa-share-nodes', '#7c3aed')"
            );
        }

        $event = $this->db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
        $variants = $this->db->all('SELECT channel, body FROM promote_post_variants WHERE post_id = ?', [$postId]);
        $captionSummary = implode("\n\n", array_map(
            static fn($v) => "[{$v['channel']}]\n{$v['body']}",
            $variants
        ));
        $description = trim(
            "Publish \"{$post['title']}\" for " . ($event['title'] ?? "event #$eventId") . ".\n"
            . ($post['public_post_url'] ? "Link: {$post['public_post_url']}\n" : '')
            . ($captionSummary !== '' ? "\nApproved captions:\n$captionSummary" : '')
        );

        $taskId = $this->db->insert(
            'INSERT INTO tasks (document_id, related_promote_post_id, title, description, priority, due_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $docId, $postId, 'Publish: ' . $post['title'], $description, 'high',
                $post['scheduled_at'] ? substr((string) $post['scheduled_at'], 0, 10) : null,
            ]
        );
        $this->db->run('UPDATE promote_posts SET related_task_id = ? WHERE id = ?', [$taskId, $postId]);
    }

    // ── Variant sub-routes ────────────────────────────────────────────────────

    private function handleVariants(Request $request, int $eventId, int $postId, int $variantId): Response
    {
        if (!$postId) {
            return $this->notFound('Post not found');
        }
        $post = $this->db->one(
            'SELECT * FROM promote_posts WHERE id = ? AND event_id = ?',
            [$postId, $eventId]
        );
        if (!$post) {
            return $this->notFound('Post not found');
        }

        // POST .../variants/generate — $variantId == 0 when segment is 'generate'
        if ($request->method() === 'POST' && $variantId === 0) {
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
        $event     = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
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
        $body            = $request->body();
        $allowedStatuses = ['draft', 'ready', 'needs_review', 'changes_requested', 'approved'];
        $status = isset($body['status']) && in_array($body['status'], $allowedStatuses, true)
            ? $body['status'] : (string) $variant['status'];

        $this->db->run(
            'UPDATE promote_post_variants SET title = ?, body = ?, status = ? WHERE id = ? AND post_id = ?',
            [
                array_key_exists('title', $body) ? $body['title'] : $variant['title'],
                array_key_exists('body',  $body) ? $body['body']  : $variant['body'],
                $status,
                $variantId,
                $postId,
            ]
        );
        $updated = $this->db->one('SELECT * FROM promote_post_variants WHERE id = ?', [$variantId]);
        return $this->ok(['variant' => $updated]);
    }
}
