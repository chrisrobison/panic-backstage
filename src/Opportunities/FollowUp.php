<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use DateTimeImmutable;

/**
 * Follow-up intelligence (Phase 8 — "make the module actively helps decide
 * what to do next", docs/OPPORTUNITIES-IMPLEMENTATION.md §4.8). Pure, DB-free
 * logic: given one already-hydrated opportunity row plus a small
 * already-fetched context (last activity timestamp), returns the ADDITIONAL
 * warning/risk codes this phase adds — `no_activity`, `proposal_stalled`,
 * `conference_approaching`, `target_date_approaching`.
 *
 * Deliberately layered on top of, not a replacement for, the existing
 * Phase 2/5 warning/risk-flag codes already produced by
 * Opportunities::opportunityWarnings() (pipeline cards: needs_follow_up,
 * no_next_action, waiting_on_intro, date_conflict, stale, budget_unknown)
 * and Opportunities::riskFlags() (detail page: budget_unapproved,
 * no_followup_scheduled, no_decision_maker, date_conflict,
 * competitor_venue) — those two already cover "next_action_at overdue" and
 * "no buyer contact" from the spec's follow-up-intelligence list, so this
 * class doesn't duplicate them under a second name. Both callers append
 * this class's codes to their own existing arrays.
 *
 * Thresholds are named class constants rather than scattered magic numbers
 * (spec: "keep stale thresholds configurable/constants") — one edit point
 * per threshold, same convention this codebase already uses for other fixed
 * business rules (e.g. Opportunities::STAGES, QUALIFICATION_ITEMS) rather
 * than a database-backed setting, since these are the same kind of "one
 * team, one set of sales-process rules" constant every other tunable in
 * this module already is.
 */
final class FollowUp
{
    /** No update to the opportunity row at all (existing Phase 5 'stale' pipeline warning — unchanged, just named here for discoverability). */
    public const STALE_DAYS = 21;

    /** No opportunity_activities entry of any kind (stage change, note, signal, manual log, ...) in this many days. */
    public const NO_ACTIVITY_DAYS = 14;

    /** Stage is proposal_sent, no follow-up is scheduled/overdue, and nothing has happened in this many days. */
    public const PROPOSAL_STALLED_DAYS = 10;

    /** The opportunity's linked conference starts within this many days. */
    public const CONFERENCE_APPROACHING_DAYS = 14;

    /** The opportunity's own target_date is within this many days. */
    public const TARGET_DATE_APPROACHING_DAYS = 14;

    public const LABELS = [
        'no_activity'             => 'No recent activity',
        'proposal_stalled'        => 'Proposal sent with no follow-up',
        'conference_approaching'  => 'Linked conference is approaching',
        'target_date_approaching' => 'Target date is approaching',
    ];

    /**
     * @param array<string,mixed> $opportunity Must include `stage`,
     *   `next_action_at`, `created_at`. May include `target_date` and
     *   `conference_starts_at` (the linked conference's own starts_at, if
     *   any — callers join it in, see Opportunities::index()/find()).
     * @param array<string,mixed> $context `last_activity_at` (nullable
     *   string — the MAX(created_at) of this opportunity's
     *   opportunity_activities rows, already bulk-fetched by the caller)
     *   and an optional `now` override (string, for tests only).
     * @return list<string> Additional flag codes, most-relevant first.
     */
    public static function additionalFlags(array $opportunity, array $context = []): array
    {
        if (in_array($opportunity['stage'] ?? null, ['won', 'lost'], true)) {
            return [];
        }

        $now = (string) ($context['now'] ?? gmdate('Y-m-d H:i:s'));
        $lastActivityAt = $context['last_activity_at'] ?? ($opportunity['updated_at'] ?? $opportunity['created_at'] ?? null);

        $flags = [];

        $daysSinceActivity = $lastActivityAt !== null ? self::daysBetween((string) $lastActivityAt, $now) : null;
        if ($daysSinceActivity !== null && $daysSinceActivity >= self::NO_ACTIVITY_DAYS) {
            $flags[] = 'no_activity';
        }

        if (($opportunity['stage'] ?? null) === 'proposal_sent') {
            $followUpMissingOrOverdue = empty($opportunity['next_action_at']) || (string) $opportunity['next_action_at'] < $now;
            if ($followUpMissingOrOverdue && $daysSinceActivity !== null && $daysSinceActivity >= self::PROPOSAL_STALLED_DAYS) {
                $flags[] = 'proposal_stalled';
            }
        }

        if (!empty($opportunity['conference_starts_at'])) {
            $daysUntil = self::daysBetween($now, (string) $opportunity['conference_starts_at']);
            if ($daysUntil !== null && $daysUntil >= 0 && $daysUntil <= self::CONFERENCE_APPROACHING_DAYS) {
                $flags[] = 'conference_approaching';
            }
        }

        if (!empty($opportunity['target_date'])) {
            $daysUntil = self::daysBetween($now, (string) $opportunity['target_date']);
            if ($daysUntil !== null && $daysUntil >= 0 && $daysUntil <= self::TARGET_DATE_APPROACHING_DAYS) {
                $flags[] = 'target_date_approaching';
            }
        }

        return $flags;
    }

    private static function daysBetween(string $from, string $to): ?int
    {
        try {
            $a = new DateTimeImmutable($from);
            $b = new DateTimeImmutable($to);
        } catch (\Exception) {
            return null;
        }
        return (int) floor(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }
}
