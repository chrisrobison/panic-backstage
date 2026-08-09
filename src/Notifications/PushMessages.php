<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * Factory for the notifications Backstage actually sends.
 *
 * Wording, deep links and dedupe keys live here rather than at the call
 * sites, so the Booking Inbox and the background worker describe the same
 * event identically, and so adding a category later (task assignments,
 * day-of-show schedule changes, unresolved blockers) is a method here plus a
 * caller — never a change to the FCM transport.
 *
 * The initial set is deliberately small and high-signal. Routine event edits,
 * status transitions, ordinary saves and marketing activity are NOT push
 * notifications: a phone alert that fires on every save trains people to
 * ignore the ones that matter.
 */
final class PushMessages
{
    /**
     * A new booking inquiry landed in the Booking Inbox and needs triage.
     *
     * @param array<string,mixed> $lead A `leads` row.
     */
    public static function newBookingInquiry(array $lead): PushMessage
    {
        return new PushMessage(
            PushPreferences::BOOKING_UPDATES,
            'New booking inquiry',
            self::leadSummary($lead),
            '#inbox-unassigned',
            'lead',
            (int) ($lead['id'] ?? 0),
            null,
            'lead-new:' . (int) ($lead['id'] ?? 0),
        );
    }

    /**
     * An inquiry was assigned (or reassigned) to somebody by somebody else.
     *
     * Links to the assignee's own queue rather than to a shared view — the
     * point of the alert is "this is now yours".
     *
     * @param array<string,mixed> $lead         A `leads` row.
     * @param int                 $assignmentId The `lead_assignments` row id.
     */
    public static function leadAssigned(array $lead, int $assignmentId): PushMessage
    {
        return new PushMessage(
            PushPreferences::TASK_ASSIGNMENTS,
            'Booking inquiry assigned to you',
            self::leadSummary($lead),
            '#inbox-mine',
            'lead',
            (int) ($lead['id'] ?? 0),
            null,
            // Keyed on the assignment, not the lead: the same inquiry can
            // legitimately be reassigned many times, and each one is a new
            // notification.
            'lead-assigned:' . $assignmentId,
        );
    }

    /**
     * A contract reached a terminal signing state.
     *
     * @param array<string,mixed> $contract A `contracts` row.
     * @param string              $state    'signed' | 'declined' | provider status.
     * @param string|null         $eventLabel Optional "<Event> — <date>" summary.
     */
    public static function contractAction(array $contract, string $state, ?string $eventLabel = null): PushMessage
    {
        $contractId = (int) ($contract['id'] ?? 0);
        $title = match ($state) {
            'signed', 'signed_by_client', 'countersigned' => 'Contract signed',
            'fully_executed' => 'Contract fully executed',
            'declined'       => 'Contract declined',
            'voided'         => 'Contract voided',
            default          => 'Contract updated',
        };

        $body = $eventLabel !== null && $eventLabel !== ''
            ? $eventLabel
            : (string) ($contract['title'] ?? 'Contract');

        return new PushMessage(
            PushPreferences::CONTRACTS,
            $title,
            $body,
            '#contract-' . $contractId,
            'contract',
            $contractId,
            isset($contract['event_id']) ? (int) $contract['event_id'] : null,
            "contract-{$state}:{$contractId}",
        );
    }

    /**
     * One-line human summary of an inquiry: who, then what and when.
     * e.g. "Acme Events — October 14 private event".
     *
     * @param array<string,mixed> $lead
     */
    public static function leadSummary(array $lead): string
    {
        $who = self::text($lead['contact_org'] ?? '') ?: self::text($lead['contact_name'] ?? '');

        $what = [];
        if ($date = self::formatDate($lead['desired_date'] ?? null)) {
            $what[] = $date;
        }
        // event_name is the visitor's own words; event_type is our normalized
        // bucket. Prefer the specific one when they gave us one.
        $detail = self::text($lead['event_name'] ?? '') ?: self::text($lead['event_type'] ?? '');
        if ($detail !== '') {
            $what[] = str_replace('_', ' ', $detail);
        }

        $right = implode(' ', $what);
        if ($who !== '' && $right !== '') {
            return self::clip("{$who} — {$right}");
        }
        return self::clip($who ?: ($right ?: 'New inquiry'));
    }

    /**
     * "<Event title> — Aug 19" for an events row, used as contract push body.
     *
     * @param array<string,mixed>|null $event
     */
    public static function eventLabel(?array $event): ?string
    {
        if ($event === null) {
            return null;
        }
        $title = self::text($event['title'] ?? '');
        $date  = self::formatDate($event['event_date'] ?? null, 'M j');
        if ($title === '') {
            return $date === '' ? null : $date;
        }
        return self::clip($date === '' ? $title : "{$title} — {$date}");
    }

    private static function formatDate(mixed $value, string $format = 'F j'): string
    {
        $raw = self::text($value);
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return '';
        }
        $time = strtotime($raw);
        return $time === false ? '' : date($format, $time);
    }

    private static function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /** Notification bodies are read on a lock screen — keep them to one line. */
    private static function clip(string $value): string
    {
        return mb_strimwidth($value, 0, 120, '…');
    }
}
