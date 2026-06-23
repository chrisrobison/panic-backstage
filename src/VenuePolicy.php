<?php
declare(strict_types=1);

namespace Panic;

use function Panic\date_or_null;
use function Panic\boolish;

/**
 * Venue operational policy configuration (versioned).
 *
 *   GET    /api/venue-policy             get active policy for the venue
 *   POST   /api/venue-policy             create a new policy version (supersedes current)
 *   PATCH  /api/venue-policy/{id}        update a draft policy version
 *   GET    /api/venue-policy/history     list all versions
 *
 * Policies are versioned with effective dates.  Events snapshot the policy
 * at booking time (events.policy_snapshot_json) so rate/rule changes don't
 * silently affect existing bookings.
 *
 * Unverified values are labelled in the response so admins know what needs
 * confirmation.
 *
 * Capability: manage_venue_policy (venue_admin only)
 */
final class VenuePolicy extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $policyId = $this->params['policyId'] ?? null;
        $sub      = $this->params['sub']      ?? null;

        if ($sub === 'history' && $request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('manage_venue_policy')) {
                return $denied;
            }
            return $this->history();
        }

        // Read is open to any authenticated user (they need to see current policies).
        if ($request->method() === 'GET') {
            if ($denied = $this->requireAuth()) {
                return $denied;
            }
            return $policyId ? $this->showVersion((int) $policyId) : $this->active();
        }

        if ($denied = $this->requireGlobalCapability('manage_venue_policy')) {
            return $denied;
        }

        return match ($request->method()) {
            'POST'  => $this->create($request),
            'PATCH' => $this->update($request, (int) $policyId),
            default => Response::methodNotAllowed(),
        };
    }

    private function active(): Response
    {
        $venue  = $this->db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = (int) ($venue['id'] ?? 1);

        $policy = $this->db->one(
            "SELECT * FROM venue_policies WHERE venue_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1",
            [$venueId]
        );

        return $this->ok([
            'policy'      => $policy,
            'has_verified' => $policy ? (bool) $policy['is_verified'] : false,
            'note'        => $policy ? null : 'No policy configured. Defaults apply. Create a policy to confirm your rules.',
        ]);
    }

    private function showVersion(int $policyId): Response
    {
        $policy = $this->db->one('SELECT * FROM venue_policies WHERE id = ?', [$policyId]);
        return $policy ? $this->ok(['policy' => $policy]) : $this->notFound();
    }

    private function history(): Response
    {
        $venue   = $this->db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = (int) ($venue['id'] ?? 1);

        $policies = $this->db->all(
            "SELECT vp.*, u.name created_by_name
             FROM venue_policies vp LEFT JOIN users u ON u.id = vp.created_by_id
             WHERE vp.venue_id = ?
             ORDER BY vp.version DESC",
            [$venueId]
        );
        return $this->ok(['policies' => $policies]);
    }

    private function create(Request $request): Response
    {
        $venue   = $this->db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = (int) ($venue['id'] ?? 1);

        // Deactivate the current active policy
        $current = $this->db->one(
            'SELECT id, version FROM venue_policies WHERE venue_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1',
            [$venueId]
        );
        $nextVersion = ($current['version'] ?? 0) + 1;

        $b = $request->body();

        // Build rooms_json from provided array or keep existing
        $roomsJson       = !empty($b['rooms']) ? json_encode($b['rooms']) : null;
        $staffingRates   = !empty($b['staffing_rates']) ? json_encode($b['staffing_rates']) : null;
        $effectiveFrom   = date_or_null($b['effective_from'] ?? null) ?? date('Y-m-d');

        $id = $this->db->insert(
            'INSERT INTO venue_policies
             (venue_id, version, is_active, effective_from, effective_to,
              rooms_json, default_age_rule, default_alcohol_mode, default_bar_minimum,
              deposit_required, deposit_pct, deposit_flat, deposit_due_days,
              doors_earliest, curfew_time, load_in_earliest,
              staffing_rates_json, contract_required, coi_required, notes,
              is_verified, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $venueId, $nextVersion, 1, $effectiveFrom,
                date_or_null($b['effective_to'] ?? null),
                $roomsJson,
                in_array($b['default_age_rule'] ?? '', ['all_ages','18_plus','21_plus','venue_discretion'], true)
                    ? $b['default_age_rule'] : 'venue_discretion',
                in_array($b['default_alcohol_mode'] ?? '', ['none','cash_bar','hosted_bar','bar_minimum','venue_discretion'], true)
                    ? $b['default_alcohol_mode'] : 'venue_discretion',
                isset($b['default_bar_minimum']) ? (float) $b['default_bar_minimum'] : null,
                boolish($b['deposit_required'] ?? true),
                isset($b['deposit_pct'])  ? (float) $b['deposit_pct']  : null,
                isset($b['deposit_flat']) ? (float) $b['deposit_flat'] : null,
                (int) ($b['deposit_due_days'] ?? 14),
                $b['doors_earliest']    ?? null,
                $b['curfew_time']       ?? null,
                $b['load_in_earliest']  ?? null,
                $staffingRates,
                boolish($b['contract_required'] ?? true),
                boolish($b['coi_required']      ?? false),
                $b['notes']             ?? null,
                boolish($b['is_verified'] ?? false),
                $this->userId(),
            ]
        );

        // Deactivate prior version and set effective_to
        if ($current) {
            $this->db->run(
                'UPDATE venue_policies SET is_active = 0, effective_to = ? WHERE id = ?',
                [date('Y-m-d', strtotime('-1 day')), $current['id']]
            );
        }

        return $this->ok(['id' => $id, 'version' => $nextVersion]);
    }

    private function update(Request $request, int $policyId): Response
    {
        $policy = $this->db->one('SELECT * FROM venue_policies WHERE id = ?', [$policyId]);
        if (!$policy) {
            return $this->notFound();
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        $fields = [
            'default_age_rule','default_alcohol_mode','default_bar_minimum',
            'deposit_required','deposit_pct','deposit_flat','deposit_due_days',
            'doors_earliest','curfew_time','load_in_earliest',
            'contract_required','coi_required','notes','is_verified','effective_from',
        ];

        foreach ($fields as $f) {
            if (!array_key_exists($f, $b)) continue;
            $sets[]   = "$f = ?";
            $params[] = $b[$f];
        }

        if (!empty($b['rooms'])) {
            $sets[]   = 'rooms_json = ?';
            $params[] = json_encode($b['rooms']);
        }
        if (!empty($b['staffing_rates'])) {
            $sets[]   = 'staffing_rates_json = ?';
            $params[] = json_encode($b['staffing_rates']);
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $policyId;
        $this->db->run('UPDATE venue_policies SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    /**
     * Return the active policy as a snapshot JSON string, suitable for storing
     * on events.policy_snapshot_json at booking time.
     */
    public function getSnapshotJson(int $venueId): ?string
    {
        $policy = $this->db->one(
            'SELECT * FROM venue_policies WHERE venue_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1',
            [$venueId]
        );
        if (!$policy) {
            return null;
        }
        // Don't include internal metadata in the snapshot
        unset($policy['created_by_id'], $policy['updated_at']);
        return json_encode($policy);
    }
}
