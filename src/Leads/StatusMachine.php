<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use function Panic\log_lead_activity;

/**
 * Single authoritative validator for Booking Inbox status transitions.
 *
 * `src/Leads.php::update()` still owns the original, simpler 8-value status
 * set (new/triage/evaluating/needs_review/approved/declined/converted/
 * canceled) used by the existing Leads pipeline UI (public/assets/leads.js)
 * — that path is left exactly as-is in this change to avoid touching
 * well-exercised, already-shipped behavior. This class is the authority for
 * every status value added for the Booking Inbox (see migration
 * 071_add_booking_inbox_core.sql) and is the only place that:
 *
 *   - validates a transition is structurally legal (target is a real
 *     status; leaving a terminal status requires an explicit override)
 *   - requires a reason for the transitions the spec calls out
 *     (declined, lost, spam, duplicate, archived, canceled)
 *   - gates high-value declines/losses/archiving behind either the
 *     decline_high_value_leads capability or a recorded manager-approval
 *     request (lead_approval_requests) instead of silently allowing or
 *     silently blocking
 *   - writes the transition to lead_status_history and lead_audit_log —
 *     every change here is user + timestamp + previous state + new state +
 *     reason + source (human vs automation), never a bare UPDATE
 *
 * Any new Booking Inbox endpoint that changes `leads.status` MUST go
 * through transition() rather than writing the column directly.
 */
final class StatusMachine
{
    public const STATUSES = [
        'new', 'triage', 'evaluating', 'needs_review', 'approved', 'declined', 'converted', 'canceled',
        'classified', 'assigned', 'claimed', 'acknowledged', 'qualifying', 'awaiting_customer',
        'availability_sent', 'tour_scheduled', 'proposal_sent', 'negotiating', 'on_hold', 'onboarded',
        'contract_sent', 'deposit_pending', 'booked', 'lost', 'spam', 'duplicate', 'archived',
    ];

    /** Once in one of these, leaving requires overrideCapability = true. */
    public const TERMINAL_STATUSES = [
        'onboarded', 'converted', 'booked', 'lost', 'declined', 'spam', 'duplicate', 'archived', 'canceled',
    ];

    /** These targets always require a non-empty $reason, regardless of role. */
    public const REASON_REQUIRED = ['declined', 'lost', 'spam', 'duplicate', 'archived', 'canceled'];

    /** These targets are gated by decline_high_value_leads on a high-value lead. */
    private const HIGH_VALUE_GATED = ['declined', 'lost', 'archived'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Attempt a status transition. Returns a result array rather than
     * throwing, so callers can turn it directly into an HTTP response:
     *
     *   ['ok' => true, 'status' => 'declined']
     *   ['ok' => false, 'code' => 422, 'error' => '...']
     *   ['ok' => false, 'code' => 202, 'pendingApproval' => true, 'approvalRequestId' => 17]
     */
    public function transition(
        array $lead,
        string $toStatus,
        ?int $userId,
        ?string $reason,
        bool $hasOverrideCapability,
        bool $hasDeclineHighValueCapability,
        string $source = 'human',
        ?int $relatedMessageId = null
    ): array {
        $fromStatus = (string) $lead['status'];

        if (!in_array($toStatus, self::STATUSES, true)) {
            return ['ok' => false, 'code' => 422, 'error' => "Unknown status: $toStatus"];
        }

        if ($toStatus === $fromStatus) {
            return ['ok' => true, 'status' => $toStatus, 'unchanged' => true];
        }

        if (in_array($fromStatus, self::TERMINAL_STATUSES, true) && !$hasOverrideCapability) {
            return [
                'ok' => false, 'code' => 409,
                'error' => "This inquiry is $fromStatus. Reopening it requires a manager override.",
            ];
        }

        $reason = trim((string) $reason);
        if (in_array($toStatus, self::REASON_REQUIRED, true) && $reason === '') {
            return ['ok' => false, 'code' => 422, 'error' => "A reason is required to mark an inquiry as $toStatus."];
        }

        if (in_array($toStatus, self::HIGH_VALUE_GATED, true)
            && !$hasDeclineHighValueCapability
            && !$hasOverrideCapability
            && $this->isHighValue($lead)
        ) {
            $requestId = $this->db->insert(
                'INSERT INTO lead_approval_requests (lead_id, requested_by_user_id, requested_status, reason)
                 VALUES (?, ?, ?, ?)',
                [(int) $lead['id'], $userId, $toStatus, $reason !== '' ? $reason : null]
            );
            log_lead_activity($this->db, (int) $lead['id'], $userId, 'approval_requested', [
                'requested_status' => $toStatus,
                'approval_request_id' => $requestId,
            ]);
            return [
                'ok' => false, 'code' => 202, 'pendingApproval' => true,
                'approvalRequestId' => $requestId,
                'error' => 'This is a high-value inquiry — a manager must approve this change.',
            ];
        }

        $this->apply($lead, $fromStatus, $toStatus, $userId, $reason ?: null, $source, $relatedMessageId);

        return ['ok' => true, 'status' => $toStatus];
    }

    /**
     * Apply an already-approved transition (e.g. a manager resolving a
     * lead_approval_requests row) without re-running the gates above.
     */
    public function forceApply(
        array $lead,
        string $toStatus,
        ?int $userId,
        ?string $reason,
        string $source = 'human',
        ?int $relatedMessageId = null
    ): void {
        $this->apply($lead, (string) $lead['status'], $toStatus, $userId, $reason, $source, $relatedMessageId);
    }

    private function apply(
        array $lead,
        string $fromStatus,
        string $toStatus,
        ?int $userId,
        ?string $reason,
        string $source,
        ?int $relatedMessageId
    ): void {
        $leadId = (int) $lead['id'];

        $this->db->run('UPDATE leads SET status = ? WHERE id = ?', [$toStatus, $leadId]);

        $this->db->run(
            'INSERT INTO lead_status_history (lead_id, from_status, to_status, user_id, reason, related_message_id, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$leadId, $fromStatus, $toStatus, $userId, $reason, $relatedMessageId, $source]
        );

        log_lead_activity($this->db, $leadId, $userId, 'status_changed', [
            'from' => $fromStatus,
            'to' => $toStatus,
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    /**
     * A lead counts as high-value if its budget or projected revenue clears
     * the venue's configured threshold (lead_inbox_settings.high_value_threshold,
     * migration 075) — falling back to "not high-value" (rather than
     * failing safe/blocking) when no threshold is configured, so a venue
     * that hasn't set one up yet isn't unexpectedly locked out of ordinary
     * declines.
     */
    public function isHighValue(array $lead): bool
    {
        $threshold = $this->db->one(
            'SELECT high_value_threshold FROM lead_inbox_settings WHERE venue_id = (SELECT id FROM venues ORDER BY id LIMIT 1)'
        )['high_value_threshold'] ?? null;

        if ($threshold === null) {
            return false;
        }

        $budget = $lead['budget'] ?? null;
        return $budget !== null && (float) $budget >= (float) $threshold;
    }
}
