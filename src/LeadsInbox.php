<?php
declare(strict_types=1);

namespace Panic;

use Panic\Leads\ClaimService;
use Panic\Leads\Classifier;
use Panic\Leads\OutboundIdentity;
use Panic\Leads\RoutingEngine;
use Panic\Leads\StatusMachine;
use function Panic\log_lead_activity;

/**
 * Booking Inbox sub-resources — everything the rich claim/conversation/
 * onboarding workspace needs beyond the original Leads pipeline CRUD
 * (src/Leads.php, unchanged).
 *
 *   POST   /api/leads/{id}/claim
 *   POST   /api/leads/{id}/release-claim
 *   POST   /api/leads/{id}/assign
 *   POST   /api/leads/{id}/reassign          (reason required)
 *   POST   /api/leads/{id}/status             (goes through StatusMachine)
 *   GET    /api/leads/{id}/messages
 *   POST   /api/leads/{id}/messages           (reply / internal note)
 *   GET    /api/leads/{id}/drafts
 *   POST   /api/leads/{id}/drafts
 *   GET    /api/leads/{id}/presence
 *   POST   /api/leads/{id}/presence           (heartbeat)
 *   POST   /api/leads/{id}/attachments
 *   GET    /api/leads/{id}/classification
 *   PATCH  /api/leads/{id}/classification     (human correction)
 *   POST   /api/leads/{id}/onboard
 *   GET    /api/leads/{id}/audit              (view_lead_audit only)
 *
 * Every write path enforces capabilities server-side — the UI hiding a
 * button is never the only gate. Two tiers: `manage_booking_inbox` (Venue
 * administrator / Trusted booker — the whole pipeline) and
 * `manage_assigned_leads` (Restricted external booker — only rows where
 * they are assigned/owner/watcher, enforced by lead scope, not just the
 * capability flag).
 */
final class LeadsInbox extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $leadId = (int) ($this->params['leadId'] ?? 0);
        $child  = $this->params['child'] ?? '';

        if ($leadId <= 0) {
            return $this->notFound('Lead not found');
        }
        if ($denied = $this->requireGlobalCapability('view_booking_inbox')) {
            return $denied;
        }

        $lead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if ($lead === null) {
            return $this->notFound('Lead not found');
        }
        if (!$this->canView($lead)) {
            return $this->forbidden('You do not have access to this inquiry');
        }

        return match ($child) {
            'detail' => $this->detail($request, $lead),
            'claim' => $this->claim($request, $lead),
            'release-claim' => $this->releaseClaim($request, $lead),
            'assign' => $this->assign($request, $lead),
            'reassign' => $this->reassign($request, $lead),
            'status' => $this->status($request, $lead),
            'messages' => $this->messages($request, $lead),
            'drafts' => $this->drafts($request, $lead),
            'presence' => $this->presence($request, $lead),
            'attachments' => $this->attachments($request, $lead),
            'classification' => $this->classification($request, $lead),
            'onboard' => $this->onboard($request, $lead),
            'audit' => $this->audit($request, $lead),
            'tasks' => $this->tasks($request, $lead),
            default => Response::json(['error' => 'Unknown Booking Inbox action'], 404),
        };
    }

    // ── Scope helpers ─────────────────────────────────────────────────────────

    /**
     * The open, unclaimed triage queue: no assignee, no active claim, and
     * still in an early status. Same set src/Inbox.php's 'unassigned' saved
     * view and counts() query use — kept in sync with
     * BaseEndpoint::leadScopeSql()'s widened restricted-booker visibility.
     */
    private const OPEN_TRIAGE_STATUSES = ['new', 'classified'];

    private function isOpenTriage(array $lead): bool
    {
        return (int) ($lead['assigned_to_user_id'] ?? 0) === 0
            && (int) ($lead['claimed_by_user_id'] ?? 0) === 0
            && in_array((string) ($lead['status'] ?? ''), self::OPEN_TRIAGE_STATUSES, true);
    }

    /**
     * Full pipeline access, or a row this restricted user is actually
     * attached to, or (for anyone who can claim_leads at all) a row still
     * sitting in the open triage queue — mirrors leadScopeSql() so a
     * restricted booker can open exactly the rows the Inbox list shows them.
     */
    private function canView(array $lead): bool
    {
        if ($this->hasGlobalCapability('manage_booking_inbox') || $this->isVenueAdmin() || $this->isGlobalViewer()) {
            return true;
        }
        if ($this->inScope($lead)) {
            return true;
        }
        return $this->hasGlobalCapability('claim_leads') && $this->isOpenTriage($lead);
    }

    private function inScope(array $lead): bool
    {
        $me = $this->userId();
        if ($me === null) {
            return false;
        }
        if ((int) ($lead['assigned_to_user_id'] ?? 0) === $me
            || (int) ($lead['owner_user_id'] ?? 0) === $me
            || (int) ($lead['claimed_by_user_id'] ?? 0) === $me
            || (int) ($lead['point_person_id'] ?? 0) === $me
        ) {
            return true;
        }
        $watching = $this->db->one('SELECT id FROM lead_watchers WHERE lead_id = ? AND user_id = ?', [$lead['id'], $me]);
        return $watching !== null;
    }

    /** manage_booking_inbox (unrestricted), or manage_assigned_leads scoped to this row. */
    private function canManage(array $lead): bool
    {
        if ($this->hasGlobalCapability('manage_booking_inbox')) {
            return true;
        }
        return $this->hasGlobalCapability('manage_assigned_leads') && $this->inScope($lead);
    }

    /**
     * Per-lead claim eligibility (docs/booking-inbox.md "Claim vs. Assign vs.
     * Own" + the restricted-booker policy table below). Backs both the
     * claim() write path and detail()'s capabilities.claim, so the Claim
     * button only ever renders when clicking it would actually succeed.
     *
     * | Role                                              | May claim when                                                          |
     * |----------------------------------------------------|--------------------------------------------------------------------------|
     * | manage_booking_inbox (venue admin / Trusted booker) | any visible lead with no active claim                                    |
     * | claim_leads only (Restricted external booker)       | assigned to them, or owner, or watcher, OR unassigned+unclaimed and      |
     * |                                                      | status is in the open triage set (new/classified)                       |
     *
     * TODO(docs/booking-inbox.md "approved categories"): once a per-role
     * category allow-list exists, the open-triage branch below should also
     * check it before letting a restricted booker self-serve an unassigned
     * lead outside their approved categories.
     */
    private function canClaim(array $lead): bool
    {
        if (!$this->hasGlobalCapability('claim_leads')) {
            return false;
        }
        $claimedBy = (int) ($lead['claimed_by_user_id'] ?? 0);
        if ($claimedBy !== 0 && $claimedBy !== $this->userId()) {
            return false; // someone else already actively holds this claim
        }
        if ($this->hasGlobalCapability('manage_booking_inbox')) {
            return true;
        }
        return $this->inScope($lead) || $this->isOpenTriage($lead);
    }

    /** Scoped detail payload used by the Booking Inbox shell. */
    private function detail(Request $request, array $lead): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        $row = $this->db->one(
            "SELECT l.*, au.name assigned_to_name, cu.name claimed_by_name,
                    ou.name owner_name, pp.name point_person_name
             FROM leads l
             LEFT JOIN users au ON au.id = l.assigned_to_user_id
             LEFT JOIN users cu ON cu.id = l.claimed_by_user_id
             LEFT JOIN users ou ON ou.id = l.owner_user_id
             LEFT JOIN users pp ON pp.id = l.point_person_id
             WHERE l.id = ?",
            [$lead['id']]
        );
        $routed = $this->db->one(
            "SELECT details_json FROM lead_audit_log
             WHERE lead_id = ? AND action IN ('routed','manually_assigned','reassigned')
             ORDER BY id DESC LIMIT 1",
            [$lead['id']]
        );
        $routingDetails = $routed ? json_decode((string) $routed['details_json'], true) : null;
        $pendingApproval = null;
        if ($this->hasGlobalCapability('override_lead_claims')) {
            $pendingApproval = $this->db->one(
                "SELECT ar.*, u.name requested_by_name
                 FROM lead_approval_requests ar
                 LEFT JOIN users u ON u.id = ar.requested_by_user_id
                 WHERE ar.lead_id = ? AND ar.status = 'pending'
                 ORDER BY ar.id DESC LIMIT 1",
                [$lead['id']]
            );
        }

        return $this->ok([
            'lead' => $row,
            'duplicates' => \Panic\Leads\Onboarding::findDuplicates($this->db, $lead),
            'routing' => $routingDetails,
            'pending_approval' => $pendingApproval,
            // Used to prefill reply-template {{venue_name}} tokens (see
            // inbox-conversation.js::prefillTemplate) with the same identity
            // Acknowledgment.php/messages() actually send from.
            'venue_name' => OutboundIdentity::resolve($this->db)['venue_name'],
            'users' => $this->hasGlobalCapability('manage_booking_inbox')
                ? $this->db->all("SELECT id, name, role FROM users WHERE is_hidden = 0 ORDER BY name")
                : [],
            'reply_templates' => $this->db->all(
                'SELECT id, name, kind, subject, body_text FROM lead_reply_templates WHERE is_active = 1 ORDER BY sort_order, name'
            ),
            'capabilities' => [
                'manage' => $this->canManage($lead),
                'claim' => $this->canClaim($lead),
                'reassign' => $this->hasGlobalCapability('manage_booking_inbox'),
                'assign' => $this->isVenueAdmin(),
                'approve' => $this->hasGlobalCapability('override_lead_claims'),
                'tasks' => $this->hasGlobalCapability('manage_tasks_app'),
            ],
        ]);
    }

    // ── Claim / release ───────────────────────────────────────────────────────

    private function claim(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('claim_leads')) {
            return $denied;
        }
        // See canClaim()'s docblock for the full restricted-booker policy
        // table (docs/booking-inbox.md).
        if (!$this->canClaim($lead)) {
            return $this->forbidden('This inquiry is not available for you to claim');
        }

        $result = (new ClaimService())->claim($this->db, $lead, (int) $this->userId());
        if (!$result['ok']) {
            return Response::json(['error' => $result['error']], $result['code'] ?? 409);
        }
        return $this->ok(['claimed' => true, 'expiresAt' => $result['expiresAt'] ?? null]);
    }

    private function releaseClaim(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        $isClaimant = (int) ($lead['claimed_by_user_id'] ?? 0) === $this->userId();
        if (!$isClaimant && !$this->hasGlobalCapability('override_lead_claims')) {
            return $this->forbidden('Only the claimant or a manager can release this claim');
        }
        $reason = trim((string) $request->body('reason', ''));
        (new ClaimService())->release($this->db, $lead, $this->userId(), $reason ?: 'Released by ' . ($isClaimant ? 'claimant' : 'manager override'), 'human');
        return $this->ok(['released' => true]);
    }

    // ── Assign / reassign ─────────────────────────────────────────────────────

    private function assign(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        // Unlike reassign() (available to any Trusted booker for leads
        // currently their own), assign() can target any inquiry regardless
        // of current owner/claimant, so it's reserved for venue admins.
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only a venue administrator can assign an inquiry');
        }
        $toUserId = $request->body('user_id') !== null ? (int) $request->body('user_id') : null;
        $reason = trim((string) $request->body('reason', 'Manually assigned'));
        if ($toUserId !== null && $toUserId > 0
            && $this->db->one('SELECT id FROM users WHERE id = ? AND is_hidden = 0', [$toUserId]) === null
        ) {
            return Response::json(['error' => 'The selected assignee is not available'], 422);
        }
        if ($toUserId !== null && $toUserId <= 0) {
            $toUserId = null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->one('SELECT id FROM leads WHERE id = ? FOR UPDATE', [$lead['id']]);
            $this->db->run(
                'INSERT INTO lead_assignments (lead_id, assigned_to_user_id, assigned_by_user_id, reason) VALUES (?, ?, ?, ?)',
                [$lead['id'], $toUserId, $this->userId(), $reason]
            );
            $this->db->run(
                'UPDATE leads SET assigned_to_user_id = ?, assigned_at = IF(? IS NULL, NULL, NOW()) WHERE id = ?',
                [$toUserId, $toUserId, $lead['id']]
            );
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'manually_assigned', ['to' => $toUserId, 'reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->ok(['assigned_to_user_id' => $toUserId]);
    }

    private function reassign(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        // Trusted bookers may reassign a lead currently theirs; only a
        // manager (override_lead_claims) may reassign anyone else's.
        // Restricted external bookers cannot reassign at all (spec).
        if (!$this->hasGlobalCapability('manage_booking_inbox')) {
            return $this->forbidden('Only trusted staff or a manager may reassign an inquiry');
        }
        $isOwnLead = (int) ($lead['assigned_to_user_id'] ?? 0) === $this->userId() || (int) ($lead['owner_user_id'] ?? 0) === $this->userId();
        if (!$isOwnLead && !$this->hasGlobalCapability('override_lead_claims')) {
            return $this->forbidden('You can only reassign inquiries currently assigned to you (or ask a manager)');
        }

        $reason = trim((string) $request->body('reason', ''));
        if ($reason === '') {
            return Response::json(['error' => 'A reason is required to reassign an inquiry'], 422);
        }
        $toUserId = (int) $request->body('user_id', 0);
        if ($toUserId <= 0) {
            return Response::json(['error' => 'user_id is required'], 422);
        }
        if ($this->db->one('SELECT id FROM users WHERE id = ? AND is_hidden = 0', [$toUserId]) === null) {
            return Response::json(['error' => 'The selected assignee is not available'], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->one('SELECT id FROM leads WHERE id = ? FOR UPDATE', [$lead['id']]);
            $this->db->run(
                'INSERT INTO lead_assignments (lead_id, assigned_to_user_id, assigned_by_user_id, reason) VALUES (?, ?, ?, ?)',
                [$lead['id'], $toUserId, $this->userId(), $reason]
            );
            $this->db->run(
                'UPDATE leads SET assigned_to_user_id = ?, assigned_at = NOW(), claimed_by_user_id = NULL,
                                  claimed_at = NULL, claim_expires_at = NULL WHERE id = ?',
                [$toUserId, $lead['id']]
            );
            $this->db->run(
                "UPDATE lead_claims
                 SET status = 'released', active_slot = NULL, released_at = NOW(),
                     released_by_user_id = ?, released_reason = 'Reassigned'
                 WHERE lead_id = ? AND status = 'active'",
                [$this->userId(), $lead['id']]
            );
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'reassigned', ['to' => $toUserId, 'reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->ok(['assigned_to_user_id' => $toUserId]);
    }

    // ── Status ────────────────────────────────────────────────────────────────

    private function status(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }
        $toStatus = (string) $request->body('status', '');
        $reason = $request->body('reason');
        $messageId = $request->body('related_message_id') !== null ? (int) $request->body('related_message_id') : null;

        $machine = new StatusMachine($this->db);
        $result = $machine->transition(
            $lead, $toStatus, $this->userId(), $reason,
            $this->hasGlobalCapability('override_lead_claims'),
            $this->hasGlobalCapability('decline_high_value_leads'),
            'human', $messageId
        );

        if (!$result['ok']) {
            return Response::json(
                array_diff_key($result, ['ok' => true]),
                $result['code'] ?? 422
            );
        }
        return $this->ok($result);
    }

    // ── Conversation ──────────────────────────────────────────────────────────

    private function messages(Request $request, array $lead): Response
    {
        if ($request->method() === 'GET') {
            $rows = $this->db->all(
                "SELECT m.*, u.name sent_by_name FROM lead_messages m
                 LEFT JOIN users u ON u.id = m.sent_by_user_id
                 WHERE m.lead_id = ? ORDER BY m.created_at ASC, m.id ASC",
                [$lead['id']]
            );
            $this->db->run(
                "UPDATE lead_messages SET is_read = 1
                 WHERE lead_id = ? AND direction = 'inbound' AND is_read = 0",
                [$lead['id']]
            );
            return $this->ok(['messages' => $rows]);
        }

        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }

        $direction = (string) $request->body('direction', 'internal_note');
        if (!in_array($direction, ['outbound', 'internal_note'], true)) {
            return Response::json(['error' => 'direction must be outbound or internal_note'], 422);
        }

        $basedOnMessageId = $request->body('based_on_message_id') !== null ? (int) $request->body('based_on_message_id') : null;
        $subject = (string) $request->body('subject', '');
        $bodyText = (string) $request->body('body_text', '');
        $bodyHtml = $request->body('body_html');
        if (trim($bodyText) === '' && trim((string) $bodyHtml) === '') {
            return Response::json(['error' => 'A message body is required'], 422);
        }

        if ($direction === 'outbound' && !filter_var(trim((string) ($lead['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid contact email is required before sending a reply'], 422);
        }

        if ($direction === 'internal_note') {
            $id = $this->db->insert(
                'INSERT INTO lead_messages
                 (lead_id, direction, channel, status, from_name, body_text, body_html, sent_by_user_id, checksum)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $lead['id'], 'internal_note', 'manual', 'sent',
                    $this->auth->user()['name'] ?? 'Staff', $bodyText ?: null, $bodyHtml ?: null,
                    $this->userId(), hash('sha256', $bodyText . $bodyHtml),
                ]
            );
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'internal_note_added', ['message_id' => $id]);
            $this->db->run('DELETE FROM lead_drafts WHERE lead_id = ? AND user_id = ?', [$lead['id'], $this->userId()]);
            return $this->ok(['message' => $this->db->one('SELECT * FROM lead_messages WHERE id = ?', [$id])]);
        }

        $identity = OutboundIdentity::resolve($this->db);
        $fromName = $identity['from_name'];
        $fromEmail = $identity['from_email'];

        // A real Message-ID, generated before the row is written so the id
        // persisted here matches the one that goes out on the wire — that's
        // what lets a customer's reply be matched back to this exact message
        // (see Panic\Leads\ThreadMatcher). Threaded against the rest of this
        // lead's conversation via In-Reply-To/References so both the
        // customer's mail client and our own matching see one thread.
        $externalMessageId = $direction === 'outbound' ? Mailer::generateMessageId($fromEmail) : null;
        $idempotencyKey = trim((string) $request->body('idempotency_key', ''));
        if ($idempotencyKey === '' || !preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $idempotencyKey)) {
            return Response::json(['error' => 'A valid idempotency_key is required for outbound replies'], 422);
        }
        $attachmentIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($request->body('attachment_ids', [])) ? $request->body('attachment_ids', []) : []),
            static fn (int $id): bool => $id > 0
        )));
        if (count($attachmentIds) > 5) {
            return Response::json(['error' => 'A reply may include at most five attachments'], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->one('SELECT id FROM leads WHERE id = ? FOR UPDATE', [$lead['id']]);
            $existingSend = $this->db->one(
                'SELECT * FROM lead_messages WHERE lead_id = ? AND idempotency_key = ?',
                [$lead['id'], $idempotencyKey]
            );
            if ($existingSend !== null) {
                if ($existingSend['status'] === 'sent') {
                    $pdo->commit();
                    return $this->ok(['message' => $existingSend, 'idempotent_replay' => true]);
                }
                $queuedRecently = $existingSend['status'] === 'queued'
                    && strtotime((string) $existingSend['created_at']) > time() - 600;
                if ($queuedRecently) {
                    $pdo->rollBack();
                    return Response::json(['error' => 'This reply is already being delivered'], 409);
                }

                $id = (int) $existingSend['id'];
                $subject = (string) ($existingSend['subject'] ?? '');
                $bodyText = (string) ($existingSend['body_text'] ?? '');
                $bodyHtml = $existingSend['body_html'];
                $externalMessageId = (string) $existingSend['external_message_id'];
                $priorMessageIds = array_column(
                    $this->db->all(
                        "SELECT external_message_id FROM lead_messages
                         WHERE lead_id = ? AND id <> ? AND external_message_id IS NOT NULL
                         ORDER BY id ASC",
                        [$lead['id'], $id]
                    ),
                    'external_message_id'
                );
                $this->db->run(
                    "UPDATE lead_messages
                     SET status = 'queued', delivery_error = NULL, sent_by_user_id = ?
                     WHERE id = ?",
                    [$this->userId(), $id]
                );
                $attachmentRows = $this->outboundAttachmentRows((int) $lead['id'], $attachmentIds, $id);
                if ($attachmentIds !== []) {
                    $this->linkAttachmentsToMessage($attachmentIds, $id);
                }
                $mailAttachments = $this->mailAttachments($attachmentRows, (int) $lead['id']);
                $pdo->commit();
            } else {
                $attachmentRows = $this->outboundAttachmentRows((int) $lead['id'], $attachmentIds, null);
                $priorMessageIds = array_column(
                    $this->db->all(
                        "SELECT external_message_id FROM lead_messages
                         WHERE lead_id = ? AND external_message_id IS NOT NULL
                         ORDER BY id ASC",
                        [$lead['id']]
                    ),
                    'external_message_id'
                );
                $latest = $this->db->one('SELECT id FROM lead_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead['id']]);
                $latestId = $latest !== null ? (int) $latest['id'] : null;
                if ($basedOnMessageId !== null && $latestId !== null && $basedOnMessageId !== $latestId) {
                    $pdo->rollBack();
                    return Response::json([
                        'error' => 'A new message has arrived on this thread since you started composing. Please review it before sending.',
                        'latest_message_id' => $latestId,
                    ], 409);
                }

                $id = $this->db->insert(
                    'INSERT INTO lead_messages
                     (lead_id, direction, channel, status, from_name, from_email, to_recipients, subject,
                      body_text, body_html, sent_by_user_id, checksum, idempotency_key, external_message_id, in_reply_to)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $lead['id'], 'outbound', 'email', 'queued', $fromName, $fromEmail, $lead['contact_email'],
                        $subject ?: null, $bodyText ?: null, $bodyHtml ?: null, $this->userId(),
                        hash('sha256', $bodyText . $bodyHtml), $idempotencyKey,
                        $externalMessageId, $priorMessageIds !== [] ? end($priorMessageIds) : null,
                    ]
                );
                if ($attachmentIds !== []) {
                    $this->linkAttachmentsToMessage($attachmentIds, $id);
                }
                $mailAttachments = $this->mailAttachments($attachmentRows, (int) $lead['id']);
                $pdo->commit();
            }
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $mailer = (new Mailer($this->root, $this->db, $fromEmail, $fromName))
            ->skipInboxCopy();
        $mailer->send(
            (string) $lead['contact_email'],
            $subject ?: 'Re: your inquiry',
            $bodyText,
            $bodyHtml ?: null,
            null,
            [],
            $mailAttachments,
            $externalMessageId,
            $priorMessageIds !== [] ? end($priorMessageIds) : null,
            $priorMessageIds
        );
        $deliveryAccepted = $mailer->deliveryAccepted();
        $this->db->run(
            'UPDATE lead_messages SET status = ?, delivery_attempted_at = NOW(), delivery_error = ? WHERE id = ?',
            [$deliveryAccepted ? 'sent' : 'failed', $deliveryAccepted ? null : $mailer->deliveryError(), $id]
        );

        if ($deliveryAccepted) {
            $this->db->run('UPDATE leads SET first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?', [$lead['id']]);
            (new ClaimService())->recordPreservingAction($this->db, $lead, (int) $this->userId(), 'sent_response');
            // First meaningful outbound reply, with nobody yet marked as the
            // long-term owner, establishes ownership (see the assign/claim/own
            // distinction in docs/booking-inbox.md).
            if (empty($lead['owner_user_id'])) {
                $this->db->run('UPDATE leads SET owner_user_id = ?, owned_since = NOW() WHERE id = ?', [$this->userId(), $lead['id']]);
            }
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'response_sent', ['message_id' => $id]);
            $this->applyDeliveredWorkflowAction($lead, (string) $request->body('workflow_action', ''), $id);
        } else {
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'response_failed', [
                'message_id' => $id,
                'error' => $mailer->deliveryError(),
            ]);
        }

        if ($deliveryAccepted) {
            $this->db->run('DELETE FROM lead_drafts WHERE lead_id = ? AND user_id = ?', [$lead['id'], $this->userId()]);
        }

        $message = $this->db->one('SELECT * FROM lead_messages WHERE id = ?', [$id]);
        if (!$deliveryAccepted) {
            return Response::json([
                'error' => 'The reply was saved but the mail server did not accept it. It is marked failed and can be retried.',
                'message' => $message,
            ], 502);
        }
        return $this->ok(['message' => $message]);
    }

    /** @return list<array<string,mixed>> */
    private function outboundAttachmentRows(int $leadId, array $attachmentIds, ?int $messageId): array
    {
        if ($attachmentIds === []) {
            if ($messageId === null) {
                return [];
            }
            $rows = $this->db->all(
                'SELECT * FROM lead_attachments WHERE lead_id = ? AND message_id = ? ORDER BY id FOR UPDATE',
                [$leadId, $messageId]
            );
            $attachmentIds = array_map('intval', array_column($rows, 'id'));
        } else {
            $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
            $messageClause = $messageId === null ? 'message_id IS NULL' : '(message_id IS NULL OR message_id = ?)';
            $params = [$leadId, $this->userId(), ...$attachmentIds];
            if ($messageId !== null) {
                $params[] = $messageId;
            }
            $rows = $this->db->all(
                "SELECT * FROM lead_attachments
                 WHERE lead_id = ? AND uploaded_by_user_id = ?
                   AND id IN ({$placeholders}) AND {$messageClause}
                 ORDER BY id FOR UPDATE",
                $params
            );
        }
        if (count($rows) !== count($attachmentIds)) {
            throw new \InvalidArgumentException('One or more selected attachments are unavailable');
        }
        $total = array_sum(array_map(static fn (array $row): int => (int) ($row['size_bytes'] ?? 0), $rows));
        if ($total > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Reply attachments may total at most 20 MB');
        }
        return $rows;
    }

    private function linkAttachmentsToMessage(array $attachmentIds, int $messageId): void
    {
        $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
        $this->db->run(
            "UPDATE lead_attachments SET message_id = ? WHERE id IN ({$placeholders})",
            [$messageId, ...$attachmentIds]
        );
    }

    /** @return list<array{filename:string,mime:string,bytes:string}> */
    private function mailAttachments(array $rows, int $leadId): array
    {
        $attachments = [];
        foreach ($rows as $row) {
            $storagePath = (string) $row['storage_path'];
            if (str_starts_with($storagePath, 'files/')) {
                $path = \Panic\Tenant\TenantContext::clientDir($this->root)
                    . '/' . substr($storagePath, 6);
            } else {
                // Backward compatibility for attachments created before
                // private file delivery was introduced.
                $path = $this->root . '/public/' . ltrim($storagePath, '/');
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read selected attachment: ' . $row['filename']);
            }
            $attachments[] = [
                'filename' => (string) $row['filename'],
                'mime' => (string) ($row['mime_type'] ?: 'application/octet-stream'),
                'bytes' => $bytes,
            ];
        }
        return $attachments;
    }

    private function applyDeliveredWorkflowAction(array $lead, string $action, int $messageId): void
    {
        $map = [
            'availability' => ['status' => 'availability_sent', 'preserving' => 'sent_availability'],
            'proposal' => ['status' => 'proposal_sent', 'preserving' => null],
            'tour' => ['status' => 'tour_scheduled', 'preserving' => 'scheduled_tour'],
            'follow_up' => ['status' => 'awaiting_customer', 'preserving' => 'requested_information'],
        ];
        if (!isset($map[$action])) {
            return;
        }
        $spec = $map[$action];
        if ($spec['preserving'] !== null) {
            (new ClaimService())->recordPreservingAction(
                $this->db,
                $lead,
                (int) $this->userId(),
                $spec['preserving']
            );
        }
        $freshLead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$lead['id']]);
        if ($freshLead !== null && !in_array($freshLead['status'], StatusMachine::TERMINAL_STATUSES, true)) {
            (new StatusMachine($this->db))->transition(
                $freshLead,
                $spec['status'],
                $this->userId(),
                'Updated after delivered workflow reply',
                $this->hasGlobalCapability('override_lead_claims'),
                $this->hasGlobalCapability('decline_high_value_leads'),
                'human',
                $messageId
            );
        }
    }

    // ── Drafts (save-in-progress reply, optimistic-concurrency token) ───────

    private function drafts(Request $request, array $lead): Response
    {
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }
        if ($request->method() === 'GET') {
            $draft = $this->db->one('SELECT * FROM lead_drafts WHERE lead_id = ? AND user_id = ?', [$lead['id'], $this->userId()]);
            return $this->ok(['draft' => $draft]);
        }
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        $this->db->run(
            'INSERT INTO lead_drafts (lead_id, user_id, kind, subject, body_html, body_text, based_on_message_id)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE kind = VALUES(kind), subject = VALUES(subject), body_html = VALUES(body_html),
                                     body_text = VALUES(body_text), based_on_message_id = VALUES(based_on_message_id), updated_at = NOW()',
            [
                $lead['id'], $this->userId(), (string) $request->body('kind', 'reply'),
                $request->body('subject'), $request->body('body_html'), $request->body('body_text'),
                $request->body('based_on_message_id') !== null ? (int) $request->body('based_on_message_id') : null,
            ]
        );
        // Saving a draft is deliberately NOT a claim-preserving action — the
        // spec's fixed list (ClaimService::PRESERVING_ACTIONS) is real,
        // customer-facing actions only, so a draft someone forgot about
        // still lets the claim expire on schedule.
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'draft_saved');

        return $this->ok(['saved' => true]);
    }

    // ── Presence (viewer / drafting heartbeat, polled by everyone else) ─────

    private function presence(Request $request, array $lead): Response
    {
        if ($request->method() === 'GET') {
            $rows = $this->db->all(
                "SELECT p.user_id, u.name, p.state, p.updated_at FROM lead_presence p
                 JOIN users u ON u.id = p.user_id
                 WHERE p.lead_id = ? AND p.updated_at >= (NOW() - INTERVAL 20 SECOND) AND p.user_id != ?",
                [$lead['id'], $this->userId()]
            );
            return $this->ok(['presence' => $rows]);
        }
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        $state = (string) $request->body('state', 'viewing');
        if (!in_array($state, ['viewing', 'drafting'], true)) {
            $state = 'viewing';
        }
        $this->db->run(
            'INSERT INTO lead_presence (lead_id, user_id, state) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()',
            [$lead['id'], $this->userId(), $state]
        );
        return $this->ok(['ok' => true]);
    }

    // ── Attachments ───────────────────────────────────────────────────────────

    private function attachments(Request $request, array $lead): Response
    {
        if ($request->method() === 'GET') {
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'attachments_viewed');
            return $this->ok(['attachments' => $this->db->all('SELECT * FROM lead_attachments WHERE lead_id = ? ORDER BY created_at DESC', [$lead['id']])]);
        }
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }
        $file = $request->files()['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No file uploaded'], 422);
        }
        if ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            return Response::json(['error' => 'Attachments must be 20 MB or smaller'], 422);
        }

        // Attachments are private operational records, never public web
        // assets. Store them outside public/ in both deployment modes and
        // serve them only through public/files.php's authorization checks.
        $dir = \Panic\Tenant\TenantContext::clientDir($this->root)
            . '/assets/leads/' . $lead['id'];
        $path = 'files/assets/leads/' . $lead['id'] . '/';
        try {
            ensure_dir($dir);
        } catch (\RuntimeException $e) {
            error_log('LeadsInbox could not prepare attachment storage for lead ' . $lead['id'] . ': ' . $e->getMessage());
            return Response::json(['error' => 'Could not store upload'], 500);
        }
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-' . slugify(pathinfo($file['name'], PATHINFO_FILENAME));
        $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        $ext = $this->safeAttachmentExtension($mime);
        if ($ext !== '') {
            $filename .= '.' . $ext;
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return Response::json(['error' => 'Could not store upload'], 500);
        }

        $id = $this->db->insert(
            'INSERT INTO lead_attachments (lead_id, filename, mime_type, size_bytes, storage_path, uploaded_by_user_id) VALUES (?,?,?,?,?,?)',
            [$lead['id'], $file['name'], $mime, $file['size'] ?? null, $path . $filename, $this->userId()]
        );
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'attachment_uploaded', ['attachment_id' => $id]);
        return $this->ok(['id' => $id]);
    }

    private function safeAttachmentExtension(string $mime): string
    {
        return [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/calendar' => 'ics',
            'application/zip' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ][$mime] ?? 'bin';
    }

    // ── Classification ────────────────────────────────────────────────────────

    private function classification(Request $request, array $lead): Response
    {
        if ($request->method() === 'GET') {
            $current = $this->db->one('SELECT * FROM lead_classifications WHERE lead_id = ? AND is_current = 1', [$lead['id']]);
            return $this->ok(['classification' => $current]);
        }
        if ($request->method() !== 'PATCH') {
            return Response::methodNotAllowed();
        }
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }
        $fields = $request->body();
        unset($fields['id']);
        $id = (new Classifier())->recordCorrection($this->db, (int) $lead['id'], (int) $this->userId(), $fields);
        return $this->ok(['id' => $id]);
    }

    // ── Audit history ─────────────────────────────────────────────────────────

    private function audit(Request $request, array $lead): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('view_lead_audit')) {
            return $denied;
        }
        $rows = $this->db->all(
            "SELECT a.*, u.name user_name FROM lead_audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.lead_id = ? ORDER BY a.created_at DESC, a.id DESC",
            [$lead['id']]
        );
        return $this->ok(['audit' => $rows]);
    }

    // ── Linked Tasks ───────────────────────────────────────────────────────────

    private function tasks(Request $request, array $lead): Response
    {
        if ($request->method() === 'GET') {
            return $this->ok(['tasks' => $this->db->all(
                "SELECT t.id, t.title, t.description, t.status, t.priority, t.due_date,
                        t.assignee_user_id, u.name assignee_name, d.id document_id, d.name document_name
                 FROM tasks t
                 JOIN task_documents d ON d.id = t.document_id
                 LEFT JOIN users u ON u.id = t.assignee_user_id
                 WHERE t.related_lead_id = ?
                 ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date, t.id DESC",
                [$lead['id']]
            )]);
        }
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if (!$this->canManage($lead) || !$this->hasGlobalCapability('manage_tasks_app')) {
            return $this->forbidden('You do not have permission to create linked tasks');
        }
        $title = trim((string) $request->body('title', ''));
        if ($title === '') {
            return Response::json(['error' => 'Task title is required'], 422);
        }
        $priority = (string) $request->body('priority', 'medium');
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $priority = 'medium';
        }
        $assignee = $request->body('assignee_user_id') ? (int) $request->body('assignee_user_id') : $this->userId();
        if ($assignee && !$this->db->one('SELECT id FROM users WHERE id = ? AND is_hidden = 0', [$assignee])) {
            return Response::json(['error' => 'Assignee not found'], 422);
        }

        $document = $this->db->one("SELECT id FROM task_documents WHERE name = 'Booking Inbox Follow-up' LIMIT 1");
        if ($document === null) {
            $documentId = $this->db->insert(
                "INSERT INTO task_documents (name, icon, color, status, owner_user_id, sort_order)
                 VALUES ('Booking Inbox Follow-up', 'fa-solid fa-inbox', '#2563eb', 'on_track', ?, 0)",
                [$this->userId()]
            );
        } else {
            $documentId = (int) $document['id'];
        }
        $sort = (int) ($this->db->one(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 n FROM tasks WHERE document_id = ? AND parent_task_id IS NULL',
            [$documentId]
        )['n'] ?? 10);
        $id = $this->db->insert(
            'INSERT INTO tasks
             (document_id, related_lead_id, title, description, status, priority,
              assignee_user_id, due_date, sort_order, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $documentId, $lead['id'], $title,
                trim((string) $request->body('description', '')) ?: null,
                'not_started', $priority, $assignee,
                date_or_null($request->body('due_date')), $sort, $this->userId(),
            ]
        );
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'linked_task_created', ['task_id' => $id]);
        return $this->ok(['id' => $id]);
    }

    // ── Onboard Lead ──────────────────────────────────────────────────────────

    private function onboard(Request $request, array $lead): Response
    {
        if (!$this->canManage($lead)) {
            return $this->forbidden();
        }

        // GET previews what the review dialog needs before the user commits
        // (duplicate detection + a same-date availability check) — spec's
        // "review dialog that shows all extracted info... checks
        // availability... detects duplicates" steps, without side effects.
        if ($request->method() === 'GET') {
            $venues = $this->db->all('SELECT id FROM venues ORDER BY id LIMIT 1');
            $venueId = (int) ($venues[0]['id'] ?? 1);
            $date = (string) ($request->query('date') ?: ($lead['desired_date'] ?? date('Y-m-d', strtotime('+30 days'))));
            return $this->ok([
                'duplicates' => \Panic\Leads\Onboarding::findDuplicates($this->db, $lead),
                'availability' => \Panic\Leads\Onboarding::checkAvailability($this->db, $venueId, $date),
                'templates' => $this->db->all('SELECT id, name FROM event_templates ORDER BY name'),
            ]);
        }

        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        try {
            $result = \Panic\Leads\Onboarding::onboard($this->db, $lead, $request->body(), (int) $this->userId());
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }
        return $this->ok($result);
    }
}
