<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;

/**
 * Shared SLA-settings lookup for RoutingEngine (claim deadline) and
 * ClaimService (response/claim-expiry deadline) — one place resolving
 * "which venue's lead_inbox_settings apply, and does the high-value
 * shortened SLA kick in for this particular lead" so the two don't drift.
 *
 * Single-venue assumption: `leads` has no venue_id of its own (a lead isn't
 * tied to a physical venue until it's onboarded into an event) — this
 * mirrors the exact fallback Leads::convert() already uses ("the first
 * venue row") rather than inventing a new convention.
 */
final class SlaSettings
{
    /**
     * @return array{claim_deadline_hours:float, response_deadline_hours:float,
     *               timezone:string, business_hours_start:string,
     *               business_hours_end:string, business_days:string}|null
     */
    public static function forLead(Database $db, array $lead): ?array
    {
        $venue = $db->one('SELECT id, timezone FROM venues ORDER BY id LIMIT 1');
        if ($venue === null) {
            return null;
        }
        $settings = $db->one('SELECT * FROM lead_inbox_settings WHERE venue_id = ?', [$venue['id']]);
        if ($settings === null) {
            return null;
        }

        $highValue = (new StatusMachine($db))->isHighValue($lead);

        $claimHours = $highValue && $settings['high_value_claim_deadline_hours'] !== null
            ? (float) $settings['high_value_claim_deadline_hours']
            : (float) $settings['claim_deadline_hours'];
        $responseHours = $highValue && $settings['high_value_response_deadline_hours'] !== null
            ? (float) $settings['high_value_response_deadline_hours']
            : (float) $settings['response_deadline_hours'];

        return [
            'claim_deadline_hours' => $claimHours,
            'response_deadline_hours' => $responseHours,
            'timezone' => (string) ($venue['timezone'] ?: 'America/Los_Angeles'),
            'business_hours_start' => (string) $settings['business_hours_start'],
            'business_hours_end' => (string) $settings['business_hours_end'],
            'business_days' => (string) $settings['business_days'],
        ];
    }
}
