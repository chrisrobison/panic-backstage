<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use function Panic\log_lead_activity;

/**
 * Deterministic, configurable, versioned inquiry routing (database/
 * migrations/075_add_booking_inbox_routing.sql).
 *
 * The AI classifier (Classifier.php) may recommend a category/urgency/value,
 * but routing itself is decided entirely by this class reading plain data —
 * `routing_rules`/`routing_rule_versions` rows an admin authored — never by
 * the model. Every decision this makes, including "nothing matched, left
 * unassigned", is written to lead_audit_log with enough detail to render the
 * UI's routing explanation ("Routed to Kathy because the inquiry was
 * classified as Punk / Live Music with 94% confidence").
 *
 * Rule precedence (first match wins):
 *   1. An existing active-customer thread — a prior lead from the same
 *      contact_email with an owner — routes back to that owner.
 *   2. An existing event thread — a booked/proposed event with the same
 *      booker_email — routes to that event's owner.
 *   3. Published routing_rules, evaluated in ascending `priority` order,
 *      each against a merged view of the lead's own columns + its current
 *      classification's extracted fields.
 *   4. No match (or the matching rule's confidence_threshold isn't met) —
 *      the lead is left unassigned (the "unassigned triage queue" the spec
 *      calls out), which is a legitimate, logged outcome, not an error.
 *
 * A rule's `conditions_json` keys (all optional, AND-combined):
 *   event_category_in, music_genre_in, age_restriction_in, source_in
 *   (arrays of allowed values), min_attendance, max_attendance, min_budget,
 *   max_budget, min_confidence / max_confidence (against the
 *   classification's overall_confidence — max_confidence is how the seeded
 *   "low-confidence → unassigned triage" rule is expressed). `action_json`:
 *   {"assign_to_user_id": int} or {"fallback_unassigned": true}.
 */
final class RoutingEngine
{
    /**
     * @return array{assigned_to_user_id: ?int, reason: string, rule_id: ?int,
     *               rule_version_id: ?int, confidence: ?float}
     */
    public function route(Database $db, array $lead): array
    {
        $leadId = (int) $lead['id'];

        $existingOwner = $this->existingCustomerOwner($db, $lead);
        if ($existingOwner !== null) {
            return $this->assign($db, $leadId, $existingOwner['user_id'], $existingOwner['reason'], null, null, null);
        }

        $classification = $db->one(
            'SELECT extracted_json, overall_confidence FROM lead_classifications WHERE lead_id = ? AND is_current = 1',
            [$leadId]
        );
        $extracted = [];
        $confidence = null;
        if ($classification !== null) {
            $extracted = json_decode((string) $classification['extracted_json'], true) ?: [];
            $confidence = $classification['overall_confidence'] !== null ? (float) $classification['overall_confidence'] : null;
        }

        $fields = array_merge($extracted, [
            'event_category' => $lead['event_category'] ?? $extracted['event_category'] ?? null,
            'music_genre' => $lead['music_genre'] ?? $extracted['music_genre'] ?? null,
            'age_restriction' => $lead['age_restriction'] ?? $extracted['age_restriction'] ?? null,
            'attendance' => $extracted['attendance'] ?? $lead['projected_attendance'] ?? null,
            'budget' => $lead['budget'] ?? $extracted['budget'] ?? null,
            'source' => $lead['source'] ?? null,
        ]);

        $rules = $db->all(
            "SELECT rr.id rule_id, rr.name rule_name, rv.id version_id, rv.conditions_json, rv.action_json
             FROM routing_rules rr
             JOIN routing_rule_versions rv ON rv.id = rr.current_published_version_id
             WHERE rr.is_active = 1
             ORDER BY rr.priority ASC, rr.id ASC"
        );

        foreach ($rules as $rule) {
            $conditions = json_decode((string) $rule['conditions_json'], true) ?: [];
            if (!$this->matches($conditions, $fields, $confidence)) {
                continue;
            }
            $action = json_decode((string) $rule['action_json'], true) ?: [];
            if (!empty($action['fallback_unassigned'])) {
                return $this->assign($db, $leadId, null, "Rule \"{$rule['rule_name']}\" routes low-confidence inquiries to the unassigned triage queue.", (int) $rule['rule_id'], (int) $rule['version_id'], $confidence);
            }
            $assignTo = isset($action['assign_to_user_id']) ? (int) $action['assign_to_user_id'] : null;
            $confidencePct = $confidence !== null ? round($confidence * 100) . '%' : 'unknown confidence';
            $reason = "Routed by rule \"{$rule['rule_name']}\" ({$confidencePct}).";
            return $this->assign($db, $leadId, $assignTo, $reason, (int) $rule['rule_id'], (int) $rule['version_id'], $confidence);
        }

        return $this->assign($db, $leadId, null, 'No routing rule matched — left in the unassigned triage queue.', null, null, $confidence);
    }

    /** @return array{user_id:int, reason:string}|null */
    private function existingCustomerOwner(Database $db, array $lead): ?array
    {
        $email = trim((string) ($lead['contact_email'] ?? ''));
        if ($email === '') {
            return null;
        }

        $priorLead = $db->one(
            "SELECT owner_user_id, point_person_id FROM leads
             WHERE contact_email = ? AND id != ? AND (owner_user_id IS NOT NULL OR point_person_id IS NOT NULL)
             ORDER BY updated_at DESC LIMIT 1",
            [$email, (int) $lead['id']]
        );
        $ownerId = (int) ($priorLead['owner_user_id'] ?? $priorLead['point_person_id'] ?? 0);
        if ($ownerId > 0) {
            return ['user_id' => $ownerId, 'reason' => 'Routed to the existing owner of a prior inquiry from this contact.'];
        }

        $priorEvent = $db->one(
            "SELECT owner_user_id FROM events WHERE booker_email = ? AND owner_user_id IS NOT NULL
             ORDER BY updated_at DESC LIMIT 1",
            [$email]
        );
        if (!empty($priorEvent['owner_user_id'])) {
            return ['user_id' => (int) $priorEvent['owner_user_id'], 'reason' => 'Routed to the owner of this contact\'s existing event.'];
        }

        return null;
    }

    /**
     * Substring containment rather than strict equality: the classifier
     * returns free-text values (a "music_genre" of "punk/ska" or "hardcore
     * punk" is realistic model output), so an allow-list of "punk" should
     * still match either direction — the field value contains the allowed
     * term, or (for a short field value like an age_restriction "21+"
     * matching an allowed "21+") the allowed term contains the field value.
     */
    private function containsAny(string $value, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $candidate = strtolower((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($value, $candidate) || str_contains($candidate, $value)) {
                return true;
            }
        }
        return false;
    }

    /** Pure rule-matching logic — public for testability (see tests/leads_routing_engine_test.php); no DB access. */
    public function matches(array $conditions, array $fields, ?float $confidence): bool
    {
        foreach (['event_category', 'music_genre', 'age_restriction', 'source'] as $key) {
            $allowed = $conditions[$key . '_in'] ?? null;
            if (is_array($allowed) && $allowed !== []) {
                $value = strtolower((string) ($fields[$key] ?? ''));
                if ($value === '' || !$this->containsAny($value, $allowed)) {
                    return false;
                }
            }
        }

        if (isset($conditions['min_attendance']) && (int) ($fields['attendance'] ?? 0) < (int) $conditions['min_attendance']) {
            return false;
        }
        if (isset($conditions['max_attendance']) && (int) ($fields['attendance'] ?? PHP_INT_MAX) > (int) $conditions['max_attendance']) {
            return false;
        }
        if (isset($conditions['min_budget']) && (float) ($fields['budget'] ?? 0) < (float) $conditions['min_budget']) {
            return false;
        }
        if (isset($conditions['max_budget']) && (float) ($fields['budget'] ?? PHP_INT_MAX) > (float) $conditions['max_budget']) {
            return false;
        }
        if (isset($conditions['min_confidence']) && ($confidence === null || $confidence < (float) $conditions['min_confidence'])) {
            return false;
        }
        // max_confidence: the inverse — a "low confidence" catch-all rule
        // (a lead with NO classification at all, $confidence === null,
        // counts as matching this: it's exactly the ambiguous case such a
        // rule exists to catch).
        if (isset($conditions['max_confidence']) && $confidence !== null && $confidence > (float) $conditions['max_confidence']) {
            return false;
        }

        return true;
    }

    private function assign(
        Database $db,
        int $leadId,
        ?int $userId,
        string $reason,
        ?int $ruleId,
        ?int $ruleVersionId,
        ?float $confidence
    ): array {
        $db->run(
            'INSERT INTO lead_assignments (lead_id, assigned_to_user_id, assigned_by_user_id, reason, routing_rule_version_id, confidence)
             VALUES (?, ?, NULL, ?, ?, ?)',
            [$leadId, $userId, $reason, $ruleVersionId, $confidence]
        );

        $claimDueAt = $userId !== null ? $this->computeClaimDueAt($db, $leadId) : null;

        $db->run(
            'UPDATE leads SET assigned_to_user_id = ?, assigned_at = NOW(), sla_claim_due_at = ?,
                               status = IF(status IN (?, ?), ?, status)
             WHERE id = ?',
            [$userId, $claimDueAt, 'new', 'classified', $userId !== null ? 'assigned' : 'classified', $leadId]
        );

        log_lead_activity($db, $leadId, null, 'routed', [
            'assigned_to_user_id' => $userId,
            'reason' => $reason,
            'rule_id' => $ruleId,
            'rule_version_id' => $ruleVersionId,
            'confidence' => $confidence,
        ]);

        return [
            'assigned_to_user_id' => $userId,
            'reason' => $reason,
            'rule_id' => $ruleId,
            'rule_version_id' => $ruleVersionId,
            'confidence' => $confidence,
        ];
    }

    /**
     * How long the newly-assigned user has to claim this inquiry, business-
     * hours-aware and shortened for high-value inquiries — see
     * SlaSettings::forLead() for the shared settings/high-value lookup this
     * and ClaimService both use.
     */
    private function computeClaimDueAt(Database $db, int $leadId): ?string
    {
        $lead = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if ($lead === null) {
            return null;
        }
        $sla = SlaSettings::forLead($db, $lead);
        if ($sla === null) {
            return null;
        }
        return BusinessHours::addBusinessHours(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $sla['claim_deadline_hours'],
            $sla['timezone'],
            $sla['business_hours_start'],
            $sla['business_hours_end'],
            $sla['business_days']
        )->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
