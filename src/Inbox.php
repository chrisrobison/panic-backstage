<?php
declare(strict_types=1);

namespace Panic;

use Panic\Leads\Classifier;
use Panic\Leads\RoutingEngine;
use Panic\Leads\StatusMachine;
use function Panic\log_lead_activity;

/**
 * Booking Inbox cross-lead endpoints that aren't about one specific lead:
 *
 *   GET  /api/inbox/list?view=...&q=...                 the inquiry queue (saved views)
 *   GET  /api/inbox/changes?since=<Y-m-d H:i:s|epoch>   realtime-by-polling feed
 *   GET  /api/inbox/counts                              left-nav badge counts
 *
 * No SSE/WebSocket precedent exists anywhere in this app (see
 * docs/booking-inbox.md) — the Inbox UI polls `changes` every few seconds
 * while open and republishes what comes back onto the existing core.js
 * pub/sub bus, same "child reacts to a bubbling event" pattern the Tasks
 * app already uses for same-tab updates.
 */
final class Inbox extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_booking_inbox')) {
            return $denied;
        }
        $action = $this->params['action'] ?? '';
        return match ($action) {
            'list' => $this->list($request),
            'changes' => $this->changes($request),
            'counts' => $this->counts(),
            'approvals' => $this->approvals($request),
            'quarantine' => $this->quarantine($request),
            default => Response::json(['error' => 'Unknown Booking Inbox endpoint'], 404),
        };
    }

    private const OPEN_STATUSES_EXCLUDE = "'onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled'";

    /**
     * The saved views the spec calls out by name. `view` maps 1:1 to the
     * nav_items children (migration 077) for the five primary ones, plus a
     * handful more surfaced as an in-page dropdown (see incoming-ui.png's
     * "Unassigned ▾" view switcher) rather than separate nav entries.
     */
    private function list(Request $request): Response
    {
        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');
        $me = (int) $this->userId();
        $view = (string) $request->query('view', 'all');
        $q = trim((string) $request->query('q', ''));

        $where = [$scopeSql];
        $params = $scopeParams;

        switch ($view) {
            case 'mine':
                $where[] = "(l.assigned_to_user_id = ? OR l.claimed_by_user_id = ? OR l.owner_user_id = ?)";
                array_push($params, $me, $me, $me);
                $where[] = 'l.status NOT IN (' . self::OPEN_STATUSES_EXCLUDE . ')';
                break;
            case 'unassigned':
                $where[] = 'l.assigned_to_user_id IS NULL AND l.claimed_by_user_id IS NULL';
                $where[] = "l.status IN ('new','classified')";
                break;
            case 'follow_up':
                $where[] = "l.status = 'awaiting_customer'";
                break;
            case 'archived':
                $where[] = "l.status = 'archived'";
                break;
            case 'awaiting_first_response':
                $where[] = 'l.first_response_at IS NULL';
                $where[] = "l.status IN ('assigned','claimed','acknowledged')";
                break;
            case 'claims_expiring':
                $where[] = "l.status = 'claimed' AND l.claim_expires_at IS NOT NULL AND l.claim_expires_at <= (NOW() + INTERVAL 1 HOUR)";
                break;
            case 'follow_up_overdue':
                $where[] = "l.status = 'awaiting_customer' AND l.updated_at <= (NOW() - INTERVAL 3 DAY)";
                break;
            case 'high_value':
                $where[] = 'l.budget IS NOT NULL AND l.budget >= 5000';
                break;
            case 'recently_onboarded':
                $where[] = "l.status = 'onboarded' AND l.updated_at >= (NOW() - INTERVAL 30 DAY)";
                break;
            case 'declined':
                $where[] = "l.status IN ('declined','lost')";
                break;
            case 'all':
            default:
                $where[] = 'l.status NOT IN (' . self::OPEN_STATUSES_EXCLUDE . ')';
                break;
        }

        if ($q !== '') {
            $where[] = '(l.contact_name LIKE ? OR l.contact_org LIKE ? OR l.contact_email LIKE ? OR l.event_name LIKE ? OR l.inquiry_number LIKE ?)';
            $needle = '%' . $q . '%';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }

        $leads = $this->db->all(
            "SELECT l.*, au.name assigned_to_name, cu.name claimed_by_name, ou.name owner_name,
                    EXISTS(
                      SELECT 1 FROM lead_messages unread
                      WHERE unread.lead_id = l.id AND unread.direction = 'inbound' AND unread.is_read = 0
                    ) has_unread,
                    (SELECT MAX(COALESCE(lie.received_at, lm.created_at))
                     FROM lead_messages lm
                     LEFT JOIN lead_intake_emails lie ON lie.id = lm.intake_email_id
                     WHERE lm.lead_id = l.id) latest_message_at,
                    (SELECT COUNT(*) FROM lead_messages lm WHERE lm.lead_id = l.id) message_count,
                    (SELECT COUNT(DISTINCT LOWER(lm.from_email))
                     FROM lead_messages lm
                     WHERE lm.lead_id = l.id AND lm.direction = 'inbound' AND lm.from_email IS NOT NULL) participant_count,
                    (SELECT lm.subject
                     FROM lead_messages lm
                     LEFT JOIN lead_intake_emails lie ON lie.id = lm.intake_email_id
                     WHERE lm.lead_id = l.id AND lm.subject IS NOT NULL AND lm.subject != ''
                     ORDER BY COALESCE(lie.received_at, lm.created_at) ASC, lm.id ASC
                     LIMIT 1) thread_subject
             FROM leads l
             LEFT JOIN users au ON au.id = l.assigned_to_user_id
             LEFT JOIN users cu ON cu.id = l.claimed_by_user_id
             LEFT JOIN users ou ON ou.id = l.owner_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY has_unread DESC, COALESCE(latest_message_at, l.created_at) DESC LIMIT 200",
            $params
        );
        foreach ($leads as &$lead) {
            $lead['conversation_subject'] = LeadEmailParser::canonicalSubject(
                $lead['thread_subject'] ?? $lead['event_name'] ?? null
            );
            unset($lead['thread_subject']);
        }
        unset($lead);

        return $this->ok(['leads' => $leads, 'view' => $view]);
    }

    private function changes(Request $request): Response
    {
        $since = (string) $request->query('since', '1970-01-01 00:00:00');
        if (ctype_digit($since)) {
            $since = gmdate('Y-m-d H:i:s', (int) $since);
        }

        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');

        $leads = $this->db->all(
            "SELECT l.id, l.inquiry_number, l.status, l.assigned_to_user_id, l.claimed_by_user_id,
                    l.owner_user_id, l.claim_expires_at, l.sla_claim_due_at, l.updated_at
             FROM leads l WHERE $scopeSql AND l.updated_at >= ? ORDER BY l.updated_at DESC LIMIT 200",
            [...$scopeParams, $since]
        );

        $messages = $this->db->all(
            "SELECT m.id, m.lead_id, m.direction, m.created_at FROM lead_messages m
             JOIN leads l ON l.id = m.lead_id
             WHERE $scopeSql AND m.created_at >= ? ORDER BY m.created_at DESC LIMIT 200",
            [...$scopeParams, $since]
        );

        return $this->ok([
            'leads' => $leads,
            'messages' => $messages,
            'server_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function counts(): Response
    {
        [$scopeSql, $scopeParams] = $this->leadScopeSql('l');
        $me = (int) $this->userId();

        $row = $this->db->one(
            "SELECT
                SUM(CASE WHEN l.assigned_to_user_id = ? AND l.status NOT IN ('onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled') THEN 1 ELSE 0 END) mine,
                SUM(CASE WHEN l.assigned_to_user_id IS NULL AND l.claimed_by_user_id IS NULL AND l.status IN ('new','classified') THEN 1 ELSE 0 END) unassigned,
                SUM(CASE WHEN l.status NOT IN ('onboarded','converted','booked','lost','declined','spam','duplicate','archived','canceled') THEN 1 ELSE 0 END) all_open,
                SUM(CASE WHEN l.status = 'awaiting_customer' THEN 1 ELSE 0 END) follow_up,
                SUM(CASE WHEN l.status = 'archived' THEN 1 ELSE 0 END) archived
             FROM leads l WHERE $scopeSql",
            [$me, ...$scopeParams]
        );

        return $this->ok(['counts' => [
            'mine' => (int) ($row['mine'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'all' => (int) ($row['all_open'] ?? 0),
            'follow_up' => (int) ($row['follow_up'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ]]);
    }

    private function approvals(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('override_lead_claims')) {
            return $denied;
        }

        $approvalId = (int) ($this->params['approvalId'] ?? 0);
        if ($request->method() === 'GET' && $approvalId === 0) {
            return $this->ok(['approvals' => $this->db->all(
                "SELECT ar.*, l.inquiry_number, l.contact_name, l.contact_org,
                        l.event_name, l.budget, u.name requested_by_name
                 FROM lead_approval_requests ar
                 JOIN leads l ON l.id = ar.lead_id
                 LEFT JOIN users u ON u.id = ar.requested_by_user_id
                 WHERE ar.status = 'pending'
                 ORDER BY ar.created_at ASC"
            )]);
        }
        if ($request->method() !== 'POST' || $approvalId <= 0) {
            return Response::methodNotAllowed();
        }

        $decision = (string) $request->body('decision', '');
        if (!in_array($decision, ['approve', 'deny'], true)) {
            return Response::json(['error' => 'decision must be approve or deny'], 422);
        }
        $note = trim((string) $request->body('note', ''));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $approval = $this->db->one(
                'SELECT * FROM lead_approval_requests WHERE id = ? FOR UPDATE',
                [$approvalId]
            );
            if ($approval === null) {
                $pdo->rollBack();
                return $this->notFound('Approval request not found');
            }
            if ($approval['status'] !== 'pending') {
                $pdo->rollBack();
                return Response::json(['error' => 'This approval request has already been decided'], 409);
            }
            $lead = $this->db->one('SELECT * FROM leads WHERE id = ? FOR UPDATE', [$approval['lead_id']]);
            if ($lead === null) {
                $pdo->rollBack();
                return $this->notFound('Inquiry not found');
            }

            if ($decision === 'approve') {
                (new StatusMachine($this->db))->forceApply(
                    $lead,
                    (string) $approval['requested_status'],
                    $this->userId(),
                    (string) ($approval['reason'] ?: $note ?: 'Approved by manager')
                );
            }
            $this->db->run(
                'UPDATE lead_approval_requests
                 SET status = ?, decided_by_user_id = ?, decided_at = NOW(), decision_note = ?
                 WHERE id = ?',
                [$decision === 'approve' ? 'approved' : 'denied', $this->userId(), $note ?: null, $approvalId]
            );
            log_lead_activity($this->db, (int) $lead['id'], $this->userId(), 'approval_decided', [
                'approval_request_id' => $approvalId,
                'decision' => $decision,
                'requested_status' => $approval['requested_status'],
                'note' => $note ?: null,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->ok(['decided' => true, 'decision' => $decision]);
    }

    private function quarantine(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_booking_inbox')) {
            return $denied;
        }

        $intakeId = (int) ($this->params['itemId'] ?? 0);
        if ($request->method() === 'GET' && $intakeId === 0) {
            return $this->ok(['items' => $this->db->all(
                "SELECT id, from_name, from_email, subject, received_at, created_at, disposition_reason
                 FROM lead_intake_emails
                 WHERE status = 'skipped' AND lead_id IS NULL
                 ORDER BY COALESCE(received_at, created_at) DESC
                 LIMIT 100"
            )]);
        }
        if ($request->method() !== 'POST' || $intakeId <= 0) {
            return Response::methodNotAllowed();
        }

        $action = (string) $request->body('action', '');
        if ($action !== 'promote') {
            return Response::json(['error' => 'action must be promote'], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $intake = $this->db->one(
                'SELECT * FROM lead_intake_emails WHERE id = ? FOR UPDATE',
                [$intakeId]
            );
            if ($intake === null) {
                $pdo->rollBack();
                return $this->notFound('Quarantined message not found');
            }
            if ($intake['status'] !== 'skipped' || $intake['lead_id'] !== null) {
                $pdo->rollBack();
                return Response::json(['error' => 'This message has already been reviewed'], 409);
            }

            $parsed = (new LeadEmailParser(false))->parse((string) $intake['raw_email']);
            $lead = json_decode((string) $intake['parsed_json'], true);
            if (!is_array($lead)) {
                $lead = $parsed['lead'];
            }
            $meta = $parsed['meta'];

            $leadId = $this->db->insert(
                'INSERT INTO leads (status, source, contact_name, contact_email, contact_org, contact_phone,
                 event_name, event_type, band_name, desired_date, desired_date_alt, rooms_requested,
                 projected_attendance, is_private, alcohol_plan, notes, risk_level)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    'new', 'email', $lead['contact_name'] ?? null, $lead['contact_email'] ?? null,
                    $lead['contact_org'] ?? null, $lead['contact_phone'] ?? null,
                    $lead['event_name'] ?? null, $lead['event_type'] ?? null, $lead['band_name'] ?? null,
                    $lead['desired_date'] ?? null, $lead['desired_date_alt'] ?? null, null,
                    $lead['projected_attendance'] ?? null, (int) ($lead['is_private'] ?? 0),
                    $lead['alcohol_plan'] ?? null, $lead['notes'] ?? ($meta['body_text'] ?? null),
                    $lead['risk_level'] ?? 'unknown',
                ]
            );
            $this->db->run(
                "UPDATE lead_intake_emails
                 SET lead_id = ?, status = 'imported',
                     disposition_reason = CONCAT('Promoted by user #', ?, ': ', COALESCE(disposition_reason, 'manual review'))
                 WHERE id = ?",
                [$leadId, $this->userId(), $intakeId]
            );
            $messageId = $this->db->insert(
                'INSERT INTO lead_messages
                 (lead_id, direction, channel, status, from_name, from_email, to_recipients, subject,
                  body_text, body_html, external_message_id, in_reply_to, checksum, intake_email_id, is_read)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [
                    $leadId, 'inbound', 'email', 'received',
                    $meta['from_name'], $meta['from_email'], $meta['to_recipients'], $meta['subject'],
                    $meta['body_text'] ?? null, $meta['body_html'] ?? null,
                    $meta['message_id'], $meta['in_reply_to'] ?? null,
                    hash('sha256', (string) ($meta['body_text'] ?? '')), $intakeId,
                ]
            );
            (new \Panic\Leads\ThreadMatcher())->recordReferences(
                $this->db,
                $messageId,
                $meta['reference_ids'] ?? []
            );
            $this->db->insert(
                'INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)',
                [$leadId, $this->userId(), 'audit', 'Promoted from the Booking Inbox quarantine.']
            );
            log_lead_activity($this->db, $leadId, $this->userId(), 'quarantine_promoted', [
                'intake_email_id' => $intakeId,
                'message_id' => $messageId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $classifier = new Classifier();
        $classification = null;
        if ($classifier->isEnabled()) {
            $classification = $classifier->classify(
                $this->db,
                $leadId,
                (string) ($meta['body_text'] ?? ''),
                $meta['subject'] ?? null,
                $messageId
            );
        }
        if ($classification !== null && (float) ($classification['spam_probability'] ?? 0) >= 0.90) {
            $fresh = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            if ($fresh !== null) {
                (new StatusMachine($this->db))->transition(
                    $fresh,
                    'spam',
                    null,
                    'Automatically quarantined: classifier spam probability was at least 90%.',
                    true,
                    true,
                    'automation',
                    $messageId
                );
            }
            return $this->ok(['promoted' => true, 'lead_id' => $leadId, 'status' => 'spam']);
        }

        $fresh = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if ($fresh !== null) {
            (new RoutingEngine())->route($this->db, $fresh);
        }

        return $this->ok(['promoted' => true, 'lead_id' => $leadId]);
    }
}
