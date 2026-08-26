<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\Database;

use function Panic\slugify;
use function Panic\log_activity;
use function Panic\log_opportunity_activity;

/**
 * Convert-to-Event — mirrors Leads\Onboarding::createEventFromLead()
 * (docs/OPPORTUNITIES-IMPLEMENTATION.md §1.10/§4.5) exactly in shape: a
 * transaction does `SELECT ... FOR UPDATE` re-checking `won_event_id` is
 * still empty before inserting the event, because the caller's pre-check
 * alone (Opportunities::convert()) is not authoritative under a concurrent
 * double-click. Creates **exactly one** ordinary Backstage event; never a
 * second event/contract/settlement system.
 *
 * Not a BaseEndpoint (no Request/Auth context needed) — same reasoning as
 * Leads\Onboarding, so `assignPublicSlug()` is duplicated here rather than
 * reworking that trait's shape for one more static caller.
 */
final class Conversion
{
    private const VALID_EVENT_TYPES = [
        'live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night',
        'comedy', 'private_event', 'special_event',
    ];

    /**
     * @param array<string,mixed> $opportunity Full `opportunities` row, already
     *        joined with company_name/conference_name/primary_contact_name/
     *        primary_contact_title (Opportunities::find()'s shape) — the
     *        caller already has this loaded for the detail page.
     * @param array<string,mixed> $overrides   Optional caller-supplied overrides
     *        (title, date, event_type, estimated_guests, venue_id).
     * @return array{event_id:int, already_converted:bool}
     * @throws \RuntimeException on failure (already rolled back) or if the
     *         opportunity is in a non-convertible state.
     */
    public static function createEventFromOpportunity(Database $db, array $opportunity, array $overrides, int $userId): array
    {
        $opportunityId = (int) $opportunity['id'];

        $venues  = $db->all('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = isset($overrides['venue_id']) ? (int) $overrides['venue_id'] : (int) ($venues[0]['id'] ?? 1);

        $title = trim((string) ($overrides['title'] ?? $opportunity['name'] ?? 'Untitled Event'));
        if ($title === '') {
            $title = 'Untitled Event';
        }
        $date = (string) ($overrides['date'] ?? $opportunity['target_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $type = (string) ($overrides['event_type'] ?? $opportunity['event_type'] ?? 'private_event');
        if (!in_array($type, self::VALID_EVENT_TYPES, true)) {
            $type = 'private_event';
        }
        $estimatedGuests = isset($overrides['estimated_guests']) && $overrides['estimated_guests'] !== ''
            ? (int) $overrides['estimated_guests']
            : ($opportunity['guest_count_max'] ?? $opportunity['guest_count_min'] ?? null);

        $pdo = $db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $db->one('SELECT stage, won_event_id FROM opportunities WHERE id = ? FOR UPDATE', [$opportunityId]);
            if ($current === null) {
                throw new \RuntimeException('Opportunity no longer exists.');
            }
            if (!empty($current['won_event_id'])) {
                // Idempotent: a concurrent request (or a stale double-click)
                // already converted this opportunity — hand back the
                // existing event rather than creating a second one.
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return ['event_id' => (int) $current['won_event_id'], 'already_converted' => true];
            }
            if ($current['stage'] === 'lost') {
                throw new \RuntimeException('A lost opportunity cannot be converted to an event.');
            }

            $slug = slugify($title) . '-' . date('Ymd') . '-' . $opportunityId;

            $eventId = $db->insert(
                'INSERT INTO events
                 (venue_id, title, slug, event_type, status, date,
                  client_org, booker_name, booker_email, booker_phone,
                  estimated_guests, description_internal, av_requirements, catering_notes,
                  potential_revenue, owner_user_id, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $venueId, $title, $slug, $type, 'proposed', $date,
                    $opportunity['company_name'] ?? null,
                    $opportunity['primary_contact_name'] ?? null,
                    $opportunity['primary_contact_email'] ?? null,
                    $opportunity['primary_contact_phone'] ?? null,
                    $estimatedGuests,
                    $opportunity['event_concept'] ?? null,
                    $opportunity['av_requirements'] ?? null,
                    $opportunity['catering_notes'] ?? null,
                    $opportunity['estimated_value'] ?? null,
                    $opportunity['owner_user_id'] ?? $userId,
                ]
            );

            self::assignPublicSlug($db, $eventId, $title);

            $db->run(
                "UPDATE opportunities SET stage = 'won', won_event_id = ?, converted_at = NOW() WHERE id = ?",
                [$eventId, $opportunityId]
            );

            log_opportunity_activity($db, $opportunityId, $userId, 'converted', ['event_id' => $eventId]);
            log_activity($db, $eventId, $userId, 'event created from opportunity', [
                'opportunity_id' => $opportunityId,
                'company'        => $opportunity['company_name'] ?? null,
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Opportunity conversion failed: ' . $e->getMessage());
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Could not create the event. Please try again.', 0, $e);
        }

        return ['event_id' => $eventId, 'already_converted' => false];
    }

    /**
     * Assign the converted event's permanent public_slug — duplicated from
     * Leads\Onboarding::assignPublicSlug() for the same "not worth a shared
     * trait for one static caller" reason given in that class's docblock.
     * Best-effort: a failure leaves public_slug NULL, same fallback as
     * everywhere else event_public_path() is used.
     */
    private static function assignPublicSlug(Database $db, int $eventId, string $title): void
    {
        try {
            $base = substr(slugify($title), 0, 170);
            if (ctype_digit($base)) {
                $base .= '-event';
            }
            $candidate = $base;
            $suffix = 2;
            while ($db->one('SELECT id FROM events WHERE public_slug = ?', [$candidate]) !== null) {
                $candidate = $base . '-' . $suffix++;
            }
            $db->run('UPDATE events SET public_slug = ? WHERE id = ?', [$candidate, $eventId]);
        } catch (\Throwable $e) {
            @error_log('public slug assignment failed for event ' . $eventId . ': ' . $e->getMessage());
        }
    }
}
