<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use function Panic\log_lead_activity;

/**
 * Claim lifecycle for the Booking Inbox (database/migrations/
 * 073_add_booking_inbox_assignment_claim.sql).
 *
 * "Claimed" is a distinct concept from "Assigned" (RoutingEngine — the
 * system's recommendation) and "Owned" (the first meaningful response, or a
 * manager's explicit long-term assignment) — see docs/booking-inbox.md.
 * This class only ever has one *active* claim per lead at a time; claiming
 * an already-actively-claimed lead is rejected outright rather than
 * silently taking it over, so two people can never believe they each own
 * the next reply.
 *
 * A claim's `expires_at` is the response deadline (see SlaSettings) — not
 * indefinitely extendable by clicking a button. Only the fixed list of
 * claim-preserving actions in ACTIONS can push it out, and every one of
 * those calls is itself logged (log_lead_activity), so the extension is
 * inherently bounded and auditable rather than an "extend" button a user
 * could click forever.
 */
final class ClaimService
{
    /** The spec's fixed list of actions that count as "still working this" and preserve an active claim. */
    public const PRESERVING_ACTIONS = [
        'sent_response', 'scheduled_tour', 'sent_availability',
        'logged_call', 'requested_information', 'manager_approved_followup_task',
    ];

    /**
     * @return array{ok:bool, code?:int, error?:string, expiresAt?:string}
     */
    public function claim(Database $db, array $lead, int $userId): array
    {
        $leadId = (int) $lead['id'];

        $active = $db->one(
            "SELECT c.*, u.name claimed_by_name FROM lead_claims c
             LEFT JOIN users u ON u.id = c.claimed_by_user_id
             WHERE c.lead_id = ? AND c.status = 'active' LIMIT 1",
            [$leadId]
        );
        if ($active !== null) {
            if ((int) $active['claimed_by_user_id'] === $userId) {
                return ['ok' => true, 'expiresAt' => $active['expires_at'], 'alreadyOwn' => true];
            }
            return [
                'ok' => false, 'code' => 409,
                'error' => "Already claimed by {$active['claimed_by_name']}, expires {$active['expires_at']}.",
            ];
        }

        $sla = SlaSettings::forLead($db, $lead);
        $expiresAt = $sla !== null
            ? BusinessHours::addBusinessHours(
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $sla['response_deadline_hours'], $sla['timezone'],
                $sla['business_hours_start'], $sla['business_hours_end'], $sla['business_days']
              )->format('Y-m-d H:i:s')
            : null;

        $db->run(
            'INSERT INTO lead_claims (lead_id, claimed_by_user_id, claimed_at, expires_at, status)
             VALUES (?, ?, NOW(), ?, ?)',
            [$leadId, $userId, $expiresAt, 'active']
        );
        $db->run(
            'UPDATE leads SET claimed_by_user_id = ?, claimed_at = NOW(), claim_expires_at = ?,
                               status = IF(status IN (?, ?, ?), ?, status)
             WHERE id = ?',
            [$userId, $expiresAt, 'new', 'classified', 'assigned', 'claimed', $leadId]
        );

        log_lead_activity($db, $leadId, $userId, 'claimed', ['expires_at' => $expiresAt]);

        return ['ok' => true, 'expiresAt' => $expiresAt];
    }

    /**
     * Release the active claim — either a human giving it up, or the SLA
     * sweep (scripts/lead-sla-tick.php) reclaiming an expired one. Returns
     * the lead to the unassigned/assigned queue rather than leaving it
     * stuck showing a claimant who no longer holds it.
     */
    public function release(Database $db, array $lead, ?int $releasedByUserId, string $reason, string $source = 'human'): void
    {
        $leadId = (int) $lead['id'];
        $status = $source === 'automation' ? 'expired' : 'released';

        $db->run(
            "UPDATE lead_claims SET status = ?, released_at = NOW(), released_by_user_id = ?, released_reason = ?
             WHERE lead_id = ? AND status = 'active'",
            [$status, $releasedByUserId, $reason, $leadId]
        );
        $db->run(
            "UPDATE leads SET claimed_by_user_id = NULL, claimed_at = NULL, claim_expires_at = NULL,
                               status = IF(status = 'claimed', IF(assigned_to_user_id IS NOT NULL, 'assigned', 'classified'), status)
             WHERE id = ?",
            [$leadId]
        );

        log_lead_activity($db, $leadId, $releasedByUserId, $source === 'automation' ? 'claim_expired' : 'claim_released', [
            'reason' => $reason,
        ]);
    }

    /**
     * Record a claim-preserving action: extends the active claim's deadline
     * from *now* (not indefinitely — a fresh SLA window per real action) and
     * logs the action itself. No-ops quietly if the lead has no active
     * claim (the action still happened; there's just no claim clock to
     * extend), since callers (send-reply, log-call, etc.) shouldn't have to
     * care whether the sender happens to also hold the claim.
     */
    public function recordPreservingAction(Database $db, array $lead, int $userId, string $action): void
    {
        if (!in_array($action, self::PRESERVING_ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown claim-preserving action: $action");
        }

        $leadId = (int) $lead['id'];
        $active = $db->one("SELECT id FROM lead_claims WHERE lead_id = ? AND status = 'active' LIMIT 1", [$leadId]);

        $sla = SlaSettings::forLead($db, $lead);
        $newExpiry = $sla !== null
            ? BusinessHours::addBusinessHours(
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $sla['response_deadline_hours'], $sla['timezone'],
                $sla['business_hours_start'], $sla['business_hours_end'], $sla['business_days']
              )->format('Y-m-d H:i:s')
            : null;

        if ($active !== null && $newExpiry !== null) {
            $db->run(
                "UPDATE lead_claims SET expires_at = ?, last_preserving_action_at = NOW() WHERE id = ?",
                [$newExpiry, $active['id']]
            );
            $db->run('UPDATE leads SET claim_expires_at = ? WHERE id = ?', [$newExpiry, $leadId]);
        }

        if ($action === 'sent_response') {
            $db->run('UPDATE leads SET first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?', [$leadId]);
        }

        log_lead_activity($db, $leadId, $userId, 'claim_preserving_action', [
            'action' => $action,
            'new_expiry' => $newExpiry,
        ]);
    }
}
