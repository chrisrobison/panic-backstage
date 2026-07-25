<?php
declare(strict_types=1);

namespace Panic;

use Panic\Leads\ClaimService;
use Panic\Leads\Classifier;
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
            default => Response::json(['error' => 'Unknown Booking Inbox action'], 404),
        };
    }

    // ── Scope helpers ─────────────────────────────────────────────────────────

    /** Full pipeline access, or a row this restricted user is actually attached to. */
    private function canView(array $lead): bool
    {
        if ($this->hasGlobalCapability('manage_booking_inbox') || $this->isVenueAdmin() || $this->isGlobalViewer()) {
            return true;
        }
        return $this->inScope($lead);
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

    // ── Claim / release ───────────────────────────────────────────────────────

    private function claim(Request $request, array $lead): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('claim_leads')) {
            return $denied;
        }
        // Restricted bookers may only claim inquiries that are currently
        // unassigned or already theirs — a lightweight stand-in for the
        // spec's "approved categories" concept until a full per-role
        // category allow-list exists (see docs/booking-inbox.md).
        if (!$this->hasGlobalCapability('manage_booking_inbox')) {
            $assignedTo = (int) ($lead['assigned_to_user_id'] ?? 0);
            if ($assignedTo !== 0 && $assignedTo !== $this->userId()) {
                return $this->forbidden('This inquiry is assigned to someone else');
            }
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
        if ($denied = $this->requireGlobalCapability('manage_booking_inbox')) {
            return $denied;
        }
        $toUserId = $request->body('user_id') !== null ? (int) $request->body('user_id') : null;
        $reason = trim((string) $request->body('reason', 'Manually assigned'));

        $this->db->run(
            'INSERT INTO lead_assignments (lead_id, assigned_to_user_id, assigned_by_user_id, reason) VALUES (?, ?, ?, ?)',
            [$lead['id'], $toUserId, $this->userId(), $reason]
        );
        $this->db->run('UPDATE leads SET assigned_to_user_id = ?, assigned_at = NOW() WHERE id = ?', [$toUserId, $lead['id']]);
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'manually_assigned', ['to' => $toUserId, 'reason' => $reason]);

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

        $this->db->run(
            'INSERT INTO lead_assignments (lead_id, assigned_to_user_id, assigned_by_user_id, reason) VALUES (?, ?, ?, ?)',
            [$lead['id'], $toUserId, $this->userId(), $reason]
        );
        $this->db->run('UPDATE leads SET assigned_to_user_id = ?, assigned_at = NOW(), claimed_by_user_id = NULL, claim_expires_at = NULL WHERE id = ?', [$toUserId, $lead['id']]);
        $this->db->run("UPDATE lead_claims SET status = 'released', released_at = NOW(), released_by_user_id = ?, released_reason = 'Reassigned' WHERE lead_id = ? AND status = 'active'", [$this->userId(), $lead['id']]);
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'reassigned', ['to' => $toUserId, 'reason' => $reason]);

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
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'viewed');
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

        // Duplicate-send / stale-thread guard: the composer must have been
        // looking at the true latest message, or a newer one has arrived
        // since and this send is blocked until the sender reviews it.
        $basedOnMessageId = $request->body('based_on_message_id') !== null ? (int) $request->body('based_on_message_id') : null;
        $latest = $this->db->one('SELECT id FROM lead_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead['id']]);
        $latestId = $latest !== null ? (int) $latest['id'] : null;
        if ($direction === 'outbound' && $basedOnMessageId !== null && $latestId !== null && $basedOnMessageId !== $latestId) {
            return Response::json([
                'error' => 'A new message has arrived on this thread since you started composing. Please review it before sending.',
                'latest_message_id' => $latestId,
            ], 409);
        }

        $subject = (string) $request->body('subject', '');
        $bodyText = (string) $request->body('body_text', '');
        $bodyHtml = $request->body('body_html');
        if (trim($bodyText) === '' && trim((string) $bodyHtml) === '') {
            return Response::json(['error' => 'A message body is required'], 422);
        }

        $status = $direction === 'outbound' ? 'sent' : 'sent';
        $fromName = $direction === 'outbound' ? 'Mabuhay Gardens Booking Team' : $this->auth->user()['name'] ?? 'Staff';
        $fromEmail = $direction === 'outbound' ? 'bookings@themab.org' : null;

        $id = $this->db->insert(
            'INSERT INTO lead_messages (lead_id, direction, channel, status, from_name, from_email, to_recipients, subject, body_text, body_html, sent_by_user_id, checksum)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $lead['id'], $direction, $direction === 'outbound' ? 'email' : 'manual', $status,
                $fromName, $fromEmail, $direction === 'outbound' ? $lead['contact_email'] : null,
                $subject ?: null, $bodyText ?: null, $bodyHtml ?: null, $this->userId(),
                hash('sha256', $bodyText . $bodyHtml),
            ]
        );

        if ($direction === 'outbound') {
            $mailer = new Mailer($this->root, $this->db, 'bookings@themab.org', 'Mabuhay Gardens Booking Team');
            if (trim((string) $lead['contact_email']) !== '') {
                $mailer->send((string) $lead['contact_email'], $subject ?: 'Re: your inquiry', $bodyText, $bodyHtml ?: null);
            }
            $this->db->run('UPDATE leads SET first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?', [$lead['id']]);
            (new ClaimService())->recordPreservingAction($this->db, $lead, (int) $this->userId(), 'sent_response');
            // First meaningful outbound reply, with nobody yet marked as the
            // long-term owner, establishes ownership (see the assign/claim/own
            // distinction in docs/booking-inbox.md).
            if (empty($lead['owner_user_id'])) {
                $this->db->run('UPDATE leads SET owner_user_id = ?, owned_since = NOW() WHERE id = ?', [$this->userId(), $lead['id']]);
            }
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'response_sent', ['message_id' => $id]);
        } else {
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'internal_note_added', ['message_id' => $id]);
        }

        // A sent reply/note clears this user's draft slot, if any.
        $this->db->run('DELETE FROM lead_drafts WHERE lead_id = ? AND user_id = ?', [$lead['id'], $this->userId()]);

        $message = $this->db->one('SELECT * FROM lead_messages WHERE id = ?', [$id]);
        return $this->ok(['message' => $message]);
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

        $ctx = \Panic\Tenant\TenantContext::current();
        if ($ctx !== null) {
            $dir = $this->root . '/clients/' . $ctx->tenant['slug'] . '/assets/leads/' . $lead['id'];
            $path = 'files/assets/leads/' . $lead['id'] . '/';
        } else {
            $dir = $this->root . '/public/uploads/leads/' . $lead['id'];
            $path = 'uploads/leads/' . $lead['id'] . '/';
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-' . slugify(pathinfo($file['name'], PATHINFO_FILENAME));
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if ($ext !== '') {
            $filename .= '.' . $ext;
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return Response::json(['error' => 'Could not store upload'], 500);
        }

        $id = $this->db->insert(
            'INSERT INTO lead_attachments (lead_id, filename, mime_type, size_bytes, storage_path, uploaded_by_user_id) VALUES (?,?,?,?,?,?)',
            [$lead['id'], $file['name'], mime_content_type($dir . '/' . $filename) ?: null, $file['size'] ?? null, $path . $filename, $this->userId()]
        );
        log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'attachment_uploaded', ['attachment_id' => $id]);
        return $this->ok(['id' => $id]);
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
