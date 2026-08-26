<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\Database;

/**
 * Deterministic opportunity scoring service (Phase 8 — see
 * docs/OPPORTUNITIES-IMPLEMENTATION.md §4.8 and the spec's own "Opportunity
 * scoring service" section for the component/weight list and the
 * `{score, components, reasons}` response shape this mirrors exactly).
 *
 * Split into a pure `compute()` (hermetically unit-testable — see
 * tests/opportunity_scoring_test.php — no DB, same "pure core / thin DB
 * wrapper" shape Research\Modes.php already established in Phase 7) and a
 * `scoreForOpportunity()` wrapper that gathers real, already-stored context
 * via a handful of ad hoc queries (same cost profile as
 * Opportunities::show()'s existing riskFlags()/budgetFit()/
 * venueResourceOptions() calls — this is a detail-page feature, not
 * attached to the list/pipeline endpoint, precisely to avoid turning a
 * cheap bulk list query into an N+1 scoring pass per row).
 *
 * Every component is derived from a real, already-stored value — never AI,
 * never fabricated — matching every other "deterministic, data-derived"
 * surface this module already ships (Companies::venueFitTags()/pitchIdeas(),
 * Opportunities::riskFlags()/budgetFit(), Conferences::outreachAngles()).
 *
 * Weights (spec's own example list, adjustable — see class constants):
 *   conference_relevance     0–20   linked conference's own opportunity_score + venue proximity
 *   company_participation    0–15   the company's sponsor/exhibitor/... role at that conference
 *   hospitality_signals      0–15   hospitality_history/side_event_history signals on file
 *   buyer_identified         0–10   a decision maker/champion (or at least a primary contact) is known
 *   venue_date_availability  0–15   is the target_date actually open at the venue
 *   budget_value             0–10   estimated_value vs. the identified budget range
 *   guest_venue_fit          0–5    does a real room fit the guest count
 *   research_freshness       0–5    how recently the company/conference was last researched
 *   urgency                  0–5    days until the target date / linked conference
 */
final class Scoring
{
    /** Sponsor/exhibitor/... participation role -> points out of 15 (opportunity_conference_companies.role enum). */
    private const ROLE_WEIGHTS = [
        'organizer'        => 15,
        'headline_sponsor' => 15,
        'sponsor'          => 12,
        'exhibitor'        => 9,
        'speaker'          => 9,
        'partner'          => 7,
        'vendor'           => 5,
        'delegation'       => 3,
        'attendee'         => 2,
        'unknown'          => 1,
    ];

    /**
     * @param array{
     *   conference_linked?: bool, conference_score?: ?int, conference_distance_miles?: ?float,
     *   conference_name?: ?string, company_role?: ?string, company_name?: ?string,
     *   signal_count?: int, has_decision_maker?: bool, has_primary_contact?: bool,
     *   target_date_status?: string, budget_fit_status?: string, guest_fit_status?: string,
     *   days_since_research?: ?int, days_until?: ?int
     * } $ctx
     * @return array{score:int, components:array<string,int>, reasons:list<string>}
     */
    public static function compute(array $ctx): array
    {
        $components = [];
        $reasons    = [];

        // conference_relevance (0-20): the linked conference's own human-
        // entered opportunity_score, scaled to 14, plus a venue-proximity
        // bonus. No conference linked at all scores zero here — that's a
        // real gap, not an unknown.
        if (!empty($ctx['conference_linked'])) {
            $confScore = $ctx['conference_score'] ?? null;
            $base = $confScore !== null ? (int) round($confScore / 100 * 14) : 7;
            $distance = $ctx['conference_distance_miles'] ?? null;
            $proximityBonus = $distance === null ? 0 : ($distance <= 5 ? 6 : ($distance <= 15 ? 3 : 0));
            $components['conference_relevance'] = min(20, $base + $proximityBonus);
            $reasons[] = $confScore !== null
                ? sprintf('Linked to %s (conference opportunity score %d/100)', $ctx['conference_name'] ?? 'its conference', $confScore)
                : sprintf('Linked to %s', $ctx['conference_name'] ?? 'a conference');
            if ($proximityBonus >= 6) {
                $reasons[] = sprintf('Venue is within 5 miles of %s', $ctx['conference_name'] ?? 'the conference');
            }
        } else {
            $components['conference_relevance'] = 0;
        }

        // company_participation (0-15): the company's own role at that conference.
        $role = $ctx['company_role'] ?? null;
        $roleScore = $role !== null ? (self::ROLE_WEIGHTS[$role] ?? 1) : 0;
        $components['company_participation'] = min(15, $roleScore);
        if ($role !== null && $roleScore > 0) {
            $reasons[] = sprintf('%s is a %s at this conference', $ctx['company_name'] ?? 'This company', str_replace('_', ' ', $role));
        }

        // hospitality_signals (0-15): real opportunity_signals rows, 3 points each, capped.
        $signalCount = max(0, (int) ($ctx['signal_count'] ?? 0));
        $components['hospitality_signals'] = min(15, $signalCount * 3);
        if ($signalCount > 0) {
            $reasons[] = sprintf('%d hospitality/buying signal%s on file', $signalCount, $signalCount === 1 ? '' : 's');
        }

        // buyer_identified (0-10): a named decision maker/champion beats a bare primary contact.
        if (!empty($ctx['has_decision_maker'])) {
            $components['buyer_identified'] = 10;
            $reasons[] = 'A decision maker or champion is identified';
        } elseif (!empty($ctx['has_primary_contact'])) {
            $components['buyer_identified'] = 6;
            $reasons[] = 'A primary buyer contact is identified';
        } else {
            $components['buyer_identified'] = 0;
        }

        // venue_date_availability (0-15): a real empty-night/booking-conflict check, never guessed.
        $dateStatus = $ctx['target_date_status'] ?? 'unknown';
        $components['venue_date_availability'] = match ($dateStatus) {
            'available' => 15,
            'conflict'  => 0,
            default     => 5, // no target date set yet, or unknown — neutral, not penalized
        };
        if ($dateStatus === 'available') {
            $reasons[] = 'Venue is available on the target date';
        } elseif ($dateStatus === 'conflict') {
            $reasons[] = 'Target date already has another event booked';
        }

        // budget_value (0-10): estimated_value vs. the identified budget range.
        $budgetStatus = $ctx['budget_fit_status'] ?? 'unknown';
        $components['budget_value'] = match ($budgetStatus) {
            'within_range' => 10,
            'above_range'  => 8,
            'below_range'  => 4,
            default        => 3,
        };

        // guest_venue_fit (0-5): does a real, active room fit the guest estimate.
        $guestStatus = $ctx['guest_fit_status'] ?? 'unknown';
        $components['guest_venue_fit'] = match ($guestStatus) {
            'fits'   => 5,
            'no_fit' => 0,
            default  => 2,
        };

        // research_freshness (0-5): how recently the company/conference was last researched.
        $daysSinceResearch = $ctx['days_since_research'] ?? null;
        $components['research_freshness'] = match (true) {
            $daysSinceResearch === null   => 0,
            $daysSinceResearch <= 30      => 5,
            $daysSinceResearch <= 90      => 3,
            $daysSinceResearch <= 365     => 1,
            default                       => 0,
        };

        // urgency (0-5): days until the target date / linked conference, whichever is sooner.
        $daysUntil = $ctx['days_until'] ?? null;
        $components['urgency'] = match (true) {
            $daysUntil === null => 0,
            $daysUntil <= 14    => 5,
            $daysUntil <= 30    => 3,
            $daysUntil <= 90    => 1,
            default             => 0,
        };
        if ($daysUntil !== null && $components['urgency'] >= 3) {
            $reasons[] = sprintf('%d day%s until the target date/conference', $daysUntil, $daysUntil === 1 ? '' : 's');
        }

        return [
            'score'      => (int) array_sum($components),
            'components' => $components,
            // Most explainable first; capped so the UI shows a short, readable list (spec's own example shows 3).
            'reasons'    => array_slice($reasons, 0, 6),
        ];
    }

    /**
     * Gathers real context for one opportunity (already the `find()`-joined
     * row: company_id, company_name, conference_id, primary_contact_id,
     * target_date, estimated_value, budget_range_min/max, guest_count_min/max)
     * via a handful of ad hoc queries, then calls the pure compute() above.
     */
    public static function scoreForOpportunity(Database $db, array $opportunity): array
    {
        $companyId    = (int) $opportunity['company_id'];
        $conferenceId = $opportunity['conference_id'] !== null ? (int) $opportunity['conference_id'] : null;

        $conference = $conferenceId ? $db->one('SELECT * FROM opportunity_conferences WHERE id = ?', [$conferenceId]) : null;
        $distance   = $conference ? Availability::conferenceDistanceMiles($conference, Availability::venueCoordinates($db)) : null;

        $role = null;
        if ($conferenceId) {
            $link = $db->one('SELECT role FROM opportunity_conference_companies WHERE conference_id = ? AND company_id = ?', [$conferenceId, $companyId]);
            $role = $link['role'] ?? null;
        }

        $signalWhere  = ['company_id = ?'];
        $signalParams = [$companyId];
        if ($conferenceId !== null) {
            $signalWhere[]  = 'conference_id = ?';
            $signalParams[] = $conferenceId;
        }
        $signalWhere[]  = 'opportunity_id = ?';
        $signalParams[] = (int) $opportunity['id'];
        $signalCount = (int) ($db->one(
            "SELECT COUNT(*) c FROM opportunity_signals
             WHERE signal_type IN ('hospitality_history','side_event_history') AND (" . implode(' OR ', $signalWhere) . ')',
            $signalParams
        )['c'] ?? 0);

        $hasDecisionMaker = (bool) $db->one(
            "SELECT id FROM opportunity_decision_makers WHERE opportunity_id = ? AND role IN ('champion','decision_maker')",
            [(int) $opportunity['id']]
        );

        $targetDateStatus = 'unknown';
        if (!empty($opportunity['target_date'])) {
            $conflict = $db->one(
                "SELECT id FROM events WHERE date = ? AND status NOT IN ('canceled','empty') LIMIT 1",
                [$opportunity['target_date']]
            );
            $targetDateStatus = $conflict ? 'conflict' : 'available';
        }

        $budgetMin = $opportunity['budget_range_min'] ?? null;
        $budgetMax = $opportunity['budget_range_max'] ?? null;
        $value     = $opportunity['estimated_value'] ?? null;
        $budgetStatus = 'unknown';
        if ($value !== null && ($budgetMin !== null || $budgetMax !== null)) {
            if (($budgetMin === null || (float) $value >= (float) $budgetMin) && ($budgetMax === null || (float) $value <= (float) $budgetMax)) {
                $budgetStatus = 'within_range';
            } elseif ($budgetMax !== null && (float) $value > (float) $budgetMax) {
                $budgetStatus = 'above_range';
            } else {
                $budgetStatus = 'below_range';
            }
        }

        $guestTarget = $opportunity['guest_count_max'] ?? $opportunity['guest_count_min'] ?? null;
        $guestStatus = 'unknown';
        if ($guestTarget !== null) {
            $fits = $db->one('SELECT id FROM resources WHERE active = 1 AND capacity >= ? LIMIT 1', [(int) $guestTarget]);
            $guestStatus = $fits ? 'fits' : 'no_fit';
        }

        $company = $db->one('SELECT last_researched_at FROM opportunity_companies WHERE id = ?', [$companyId]);
        $researchDates = array_filter([
            $conference['last_researched_at'] ?? null,
            $company['last_researched_at'] ?? null,
        ]);
        $daysSinceResearch = null;
        foreach ($researchDates as $d) {
            $days = (int) floor((time() - strtotime((string) $d)) / 86400);
            if ($daysSinceResearch === null || $days < $daysSinceResearch) {
                $daysSinceResearch = $days;
            }
        }

        $urgencyDates = array_filter([$opportunity['target_date'] ?? null, $conference['starts_at'] ?? null]);
        $daysUntil = null;
        foreach ($urgencyDates as $d) {
            $days = (int) floor((strtotime((string) $d) - time()) / 86400);
            if ($days >= 0 && ($daysUntil === null || $days < $daysUntil)) {
                $daysUntil = $days;
            }
        }

        return self::compute([
            'conference_linked'         => $conferenceId !== null,
            'conference_score'          => $conference['opportunity_score'] ?? null,
            'conference_distance_miles' => $distance,
            'conference_name'           => $conference['name'] ?? null,
            'company_role'              => $role,
            'company_name'              => $opportunity['company_name'] ?? null,
            'signal_count'              => $signalCount,
            'has_decision_maker'        => $hasDecisionMaker,
            'has_primary_contact'       => !empty($opportunity['primary_contact_id']),
            'target_date_status'        => $targetDateStatus,
            'budget_fit_status'         => $budgetStatus,
            'guest_fit_status'          => $guestStatus,
            'days_since_research'       => $daysSinceResearch,
            'days_until'                => $daysUntil,
        ]);
    }
}
