<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use function Panic\slugify;
use function Panic\boolish;
use function Panic\log_activity;
use function Panic\log_lead_activity;

/**
 * The Booking Inbox's "Onboard Lead" workflow — the green button in
 * incoming-ui.png. Converts an inquiry into an active event opportunity; it
 * does NOT mark the event as booked (status starts 'proposed', same as
 * every other event creation path in this app).
 *
 * `createEventFromLead()` is the one place that actually inserts the
 * `events` row from a lead — both this class's onboard() and the original
 * `Leads::convert()` call it, so the two entry points (the simple Leads
 * pipeline's "Convert" action and the Inbox's richer "Onboard Lead" wizard)
 * can never drift into two different mappings of lead fields → event
 * fields. `Leads::convert()` keeps its own narrower precondition (only from
 * approved/evaluating/needs_review, final status 'converted', for backward
 * compatibility with the existing Leads UI); onboard() has the wider
 * precondition the Inbox needs (any non-terminal status) and sets the
 * lead's final status to 'onboarded' instead.
 */
final class Onboarding
{
    private const VALID_EVENT_TYPES = [
        'live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night',
        'comedy', 'private_event', 'special_event',
    ];

    private const NON_ONBOARDABLE_STATUSES = [
        'onboarded', 'converted', 'booked', 'declined', 'lost', 'spam', 'duplicate', 'archived', 'canceled',
    ];

    /**
     * Full Onboard Lead flow: duplicate/availability checks (informational —
     * never block the onboard, since a human already reviewed them in the
     * wizard before submitting), event creation, optional task-checklist
     * application, and the audit trail.
     *
     * @return array{event_id:int, event_url:string, warnings:list<string>, tasks_created:int}
     * @throws \RuntimeException on a hard failure (already onboarded, event creation error)
     */
    public static function onboard(Database $db, array $lead, array $input, int $userId): array
    {
        if (in_array($lead['status'], self::NON_ONBOARDABLE_STATUSES, true)) {
            throw new \RuntimeException("This inquiry is already {$lead['status']} and cannot be onboarded again.");
        }

        $warnings = [];
        $venues = $db->all('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = isset($input['venue_id']) ? (int) $input['venue_id'] : (int) ($venues[0]['id'] ?? 1);
        $date = (string) ($input['date'] ?? $lead['desired_date'] ?? date('Y-m-d', strtotime('+30 days')));

        foreach (self::findDuplicates($db, $lead) as $dup) {
            $warnings[] = "Possible duplicate: {$dup['kind']} #{$dup['id']} ({$dup['label']})";
        }
        $availability = self::checkAvailability($db, $venueId, $date);
        if (!$availability['available']) {
            $warnings[] = "Potential calendar conflict: event #{$availability['conflict_event_id']} is already on the calendar for {$date} at this venue.";
        }

        $result = self::createEventFromLead($db, $lead, $input, $userId, 'onboarded');

        $tasksCreated = 0;
        if (!empty($input['task_template_id'])) {
            $tasksCreated = self::applyTaskTemplate($db, $result['event_id'], (int) $input['task_template_id'], $userId);
        }

        log_lead_activity($db, (int) $lead['id'], $userId, 'onboarded', [
            'event_id' => $result['event_id'],
            'warnings' => $warnings,
            'tasks_created' => $tasksCreated,
        ]);
        $db->run(
            'INSERT INTO lead_status_history (lead_id, from_status, to_status, user_id, reason, source) VALUES (?,?,?,?,?,?)',
            [$lead['id'], $lead['status'], 'onboarded', $userId, 'Onboarded to event #' . $result['event_id'], 'human']
        );

        return [
            'event_id' => $result['event_id'],
            'event_url' => '#event-' . $result['event_id'],
            'warnings' => $warnings,
            'tasks_created' => $tasksCreated,
        ];
    }

    /**
     * The shared lead → event mapping. $finalStatus is the `leads.status`
     * value set on success ('converted' for the original Leads pipeline
     * convert() action, 'onboarded' for the Inbox's onboard() above).
     *
     * @return array{event_id:int, title:string, venue_id:int}
     * @throws \RuntimeException on a DB failure (already rolled back)
     */
    public static function createEventFromLead(Database $db, array $lead, array $overrides, int $userId, string $finalStatus): array
    {
        $leadId = (int) $lead['id'];

        $venues = $db->all('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = isset($overrides['venue_id']) ? (int) $overrides['venue_id'] : (int) ($venues[0]['id'] ?? 1);

        $title = (string) ($overrides['title'] ?? $lead['event_name'] ?? 'Untitled Event');
        $slug = slugify($title) . '-' . date('Ymd') . '-' . $leadId;
        $date = (string) ($overrides['date'] ?? $lead['desired_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $type = (string) ($overrides['event_type'] ?? $lead['event_type'] ?? 'private_event');
        $isPrivate = boolish($lead['is_private'] ?? false);

        if (!in_array($type, self::VALID_EVENT_TYPES, true)) {
            $type = 'special_event';
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $eventId = $db->insert(
                'INSERT INTO events
                 (venue_id, title, slug, event_type, status, date, lead_id, is_private,
                  promoter_name, promoter_email, promoter_phone,
                  client_org, booker_name, booker_email, booker_phone,
                  estimated_guests, description_internal, owner_user_id, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $venueId, $title, $slug, $type, 'proposed', $date, $leadId, $isPrivate,
                    $lead['contact_name'], $lead['contact_email'], $lead['contact_phone'],
                    $lead['contact_org'], $lead['contact_name'], $lead['contact_email'], $lead['contact_phone'],
                    $lead['projected_attendance'], $lead['notes'], $userId,
                ]
            );

            $db->run(
                'UPDATE leads SET status=?, converted_event_id=?, converted_at=NOW() WHERE id=?',
                [$finalStatus, $eventId, $leadId]
            );
            $db->run(
                'INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)',
                [$leadId, $userId, 'audit', "Converted to event #$eventId: \"$title\""]
            );
            log_activity($db, $eventId, $userId, 'event created from lead', ['lead_id' => $leadId, 'source' => $lead['source']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('Lead onboarding/convert failed: ' . $e->getMessage());
            throw new \RuntimeException('Conversion failed: ' . $e->getMessage(), 0, $e);
        }

        return ['event_id' => $eventId, 'title' => $title, 'venue_id' => $venueId];
    }

    /**
     * Same coarse "any other non-canceled event at this venue on this date"
     * rule as the Automation module's venue.check_availability handler
     * (src/Processes/CenterStage/BookingHandlers.php) — kept consistent
     * deliberately rather than reimplementing fine-grained room logic here.
     */
    public static function checkAvailability(Database $db, int $venueId, string $date, ?int $excludeEventId = null): array
    {
        $conflict = $db->one(
            "SELECT id FROM events WHERE venue_id = ? AND date = ? AND id != ? AND status NOT IN ('canceled','empty')",
            [$venueId, $date, $excludeEventId ?? 0]
        );
        return ['available' => $conflict === null, 'conflict_event_id' => $conflict['id'] ?? null];
    }

    /**
     * Informational duplicate detection: other leads or events from the same
     * contact email/org with a nearby desired date. Never blocks onboarding
     * (a human already sees these as warnings in the wizard) — the spec
     * asks the wizard to "detect duplicate event records," not refuse.
     *
     * @return list<array{kind:string,id:int,label:string}>
     */
    public static function findDuplicates(Database $db, array $lead): array
    {
        $email = trim((string) ($lead['contact_email'] ?? ''));
        if ($email === '') {
            return [];
        }

        $dupes = [];
        $otherLeads = $db->all(
            "SELECT id, event_name, status FROM leads
             WHERE contact_email = ? AND id != ? AND status NOT IN ('spam','duplicate','declined','lost','canceled')
             ORDER BY created_at DESC LIMIT 5",
            [$email, (int) $lead['id']]
        );
        foreach ($otherLeads as $row) {
            $dupes[] = ['kind' => 'inquiry', 'id' => (int) $row['id'], 'label' => ($row['event_name'] ?: 'Untitled') . " ({$row['status']})"];
        }

        $events = $db->all(
            "SELECT id, title, date FROM events WHERE booker_email = ? AND status NOT IN ('canceled') ORDER BY date DESC LIMIT 5",
            [$email]
        );
        foreach ($events as $row) {
            $dupes[] = ['kind' => 'event', 'id' => (int) $row['id'], 'label' => "{$row['title']} ({$row['date']})"];
        }

        return $dupes;
    }

    /**
     * Mirrors BookingHandlers::applyTaskTemplate() exactly (same
     * event_templates.checklist_json -> event_tasks mapping) so an onboarded
     * event's starter checklist is indistinguishable from one applied by an
     * Automation process — one convention, not two.
     */
    public static function applyTaskTemplate(Database $db, int $eventId, int $templateId, ?int $userId): int
    {
        $template = $db->one('SELECT * FROM event_templates WHERE id = ?', [$templateId]);
        if ($template === null) {
            return 0;
        }
        $checklist = json_decode($template['checklist_json'] ?? '[]', true);
        $count = 0;
        foreach (is_array($checklist) ? $checklist : [] as $task) {
            $title = is_array($task) ? ($task['title'] ?? '') : (string) $task;
            $priority = is_array($task) ? ($task['priority'] ?? 'normal') : 'normal';
            if ($title === '') {
                continue;
            }
            $db->run('INSERT INTO event_tasks (event_id, title, priority) VALUES (?, ?, ?)', [$eventId, $title, $priority]);
            $count++;
        }
        log_activity($db, $eventId, $userId, 'tasks applied from template', ['template_id' => $templateId, 'count' => $count]);
        return $count;
    }
}
