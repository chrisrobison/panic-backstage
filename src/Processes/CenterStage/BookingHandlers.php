<?php
declare(strict_types=1);

namespace Panic\Processes\CenterStage;

use Panic\ContractService;
use Panic\Database;
use Panic\Processes\Runtime\HandlerRegistry;
use Panic\Processes\Runtime\OperationFailedException;
use function Panic\log_activity;

/**
 * Phase 3 — the real CenterStage-specific handlers for the "Event Booking"
 * process's op.* nodes, registered by config.operation id. This is the only
 * file in the whole automation feature that knows what a venue, a contract,
 * or an event_task is; Runtime/Engine.php stays completely generic and just
 * calls whatever's registered here (see HandlerRegistry.php).
 *
 * Every handler here only acts when the instance is actually linked to a
 * real record (`process_instances.entity_type = 'event'`, `entity_id` set)
 * — started manually from Live Cases with an event id, for now (see
 * Processes/Instances.php's startInstance()). Nothing auto-creates
 * instances from real inquiries yet; that's a deliberate, separate decision
 * to make once a few manually-started real runs have been watched.
 *
 * What's genuinely real below:
 *   - venue.check_availability   — reads the real `events` table for a
 *     date/venue conflict (a simplified same-venue-same-date check; it does
 *     not do fine-grained room-conflict logic — see comment inline).
 *   - contracts.generate_quote   — drafts a REAL `contracts` row via
 *     ContractService::create() (no email sent by that call).
 *   - events.set_status          — a conservative, explicit allow-list of
 *     transitions (mirroring the exact "proposed/confirmed -> booked"
 *     transition ContractSigningEndpoint/ContractWebhooks already perform
 *     elsewhere in this codebase), not the full Events::validateStatusTransition()
 *     rule set. Extend the allow-list deliberately, don't widen it blindly.
 *   - events.apply_task_template — creates real `event_tasks` rows from a
 *     real event_templates.checklist_json, mirroring Events\Tasks::applyTemplate().
 *   - email.send_proposal / email.send_alternatives — per your call: these
 *     never send anything. They build a real, pre-filled Gmail "compose"
 *     URL (recipient/subject/body all filled in from the linked event and
 *     the node's own config) and put it in the execution's detail/output —
 *     a human still has to open it and click Send. No Mailer call, ever.
 *
 * Deliberately left simulated (not registered here, so Engine falls back to
 * its generic simulated handler) — documented rather than faked:
 *   - payments.request_deposit — real deposit collection already goes
 *     through Stripe Payment Links via the existing Events > Payments UI
 *     (Events/Payments.php::sendPaymentLink()); wiring live Stripe calls
 *     through a brand-new engine wasn't worth the financial-consequence risk
 *     for this pass. An operator sends/tracks the deposit the existing way;
 *     nothing here corresponds to a deposit actually being requested/paid.
 *   - events.run_settlement    — no existing "run settlement" primitive was
 *     found to call into; settlement remains a manual process today.
 *   - ai.classify_text / ai.* — AI is an optional capability per the
 *     original spec, not wired to a real model here.
 */
final class BookingHandlers
{
    public static function registry(): HandlerRegistry
    {
        $registry = new HandlerRegistry();

        $registry->register('venue.check_availability', self::checkAvailability(...));
        $registry->register('contracts.generate_quote', self::generateQuote(...));
        $registry->register('events.set_status', self::setEventStatus(...));
        $registry->register('events.apply_task_template', self::applyTaskTemplate(...));

        $composeEmail = self::composeGmailLink(...);
        $registry->register('email.send_proposal', $composeEmail);
        $registry->register('email.send_alternatives', $composeEmail);

        return $registry;
    }

    private static function linkedEventId(array $instance): ?int
    {
        return ($instance['entity_type'] ?? null) === 'event' && !empty($instance['entity_id'])
            ? (int) $instance['entity_id']
            : null;
    }

    private static function checkAvailability(Database $db, array $instance, array $node, array &$variables): array
    {
        $eventId = self::linkedEventId($instance);
        if (!$eventId) {
            return ['detail' => 'Not linked to a real event — availability check skipped (simulated).'];
        }
        $event = $db->one('SELECT id, venue_id, date, room FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            throw new OperationFailedException("Linked event #$eventId no longer exists.");
        }
        // Simplified conflict rule: any OTHER non-canceled event at the same
        // venue on the same date. Real room-level double-booking logic
        // (upstairs/downstairs/both) lives in the booking UI elsewhere in
        // this app and isn't reimplemented here — this is deliberately the
        // coarse version.
        $conflict = $db->one(
            "SELECT id FROM events WHERE venue_id = ? AND date = ? AND id != ? AND status NOT IN ('canceled','empty')",
            [$event['venue_id'], $event['date'], $eventId]
        );
        $available = $conflict ? 'no' : 'yes';
        $variables['date_available'] = $available;
        return [
            'detail' => "Checked real availability for venue #{$event['venue_id']} on {$event['date']}: "
                . ($available === 'yes' ? 'available.' : "conflicts with event #{$conflict['id']}."),
            'date_available' => $available,
        ];
    }

    private static function generateQuote(Database $db, array $instance, array $node, array &$variables): array
    {
        $eventId = self::linkedEventId($instance);
        if (!$eventId) {
            return ['detail' => 'Not linked to a real event — quote drafting skipped (simulated).'];
        }
        $event = $db->one('SELECT id, venue_id, title, client_org, booker_name, booker_email FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            throw new OperationFailedException("Linked event #$eventId no longer exists.");
        }
        $templateId = !empty($node['config']['templateId']) ? (int) $node['config']['templateId'] : null;
        $ownerId = !empty($instance['owner_user_id']) ? (int) $instance['owner_user_id'] : null;

        $contractId = ContractService::create($db, [
            'event_id' => $eventId,
            'venue_id' => $event['venue_id'],
            'template_id' => $templateId,
            'contract_type' => 'private_event',
            'title' => 'Quote — ' . ($event['title'] ?: "Event #$eventId"),
            'counterparty_name' => $event['booker_name'] ?? null,
            'counterparty_org' => $event['client_org'] ?? null,
            'counterparty_email' => $event['booker_email'] ?? null,
        ], $ownerId);

        $variables['contract_id'] = $contractId;
        return [
            'detail' => "Drafted a real contract (#$contractId) for event #$eventId"
                . ($templateId ? " from template #$templateId." : ' — no template configured, so it has no sections yet.'),
            'contract_id' => $contractId,
        ];
    }

    private static function setEventStatus(Database $db, array $instance, array $node, array &$variables): array
    {
        $eventId = self::linkedEventId($instance);
        if (!$eventId) {
            return ['detail' => 'Not linked to a real event — status change skipped (simulated).'];
        }
        $mappings = $node['config']['fieldMappings'] ?? null;
        if (is_string($mappings)) {
            $mappings = json_decode($mappings, true);
        }
        $targetStatus = is_array($mappings) ? ($mappings['status'] ?? null) : null;
        if (!$targetStatus) {
            return ['detail' => 'No target status configured on this node (config.fieldMappings.status) — nothing changed.'];
        }

        // Conservative, explicit allow-list — extend deliberately.
        $allowedFrom = [
            'booked' => ['proposed', 'confirmed'],
            'completed' => ['booked', 'advanced', 'published'],
            'settled' => ['completed'],
        ];
        if (!isset($allowedFrom[$targetStatus])) {
            return ['detail' => "Status \"$targetStatus\" isn't in this handler's allow-list (see BookingHandlers.php) — nothing changed."];
        }

        $from = $allowedFrom[$targetStatus];
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $affected = $db->run("UPDATE events SET status = ? WHERE id = ? AND status IN ($placeholders)", [$targetStatus, $eventId, ...$from]);

        $ownerId = !empty($instance['owner_user_id']) ? (int) $instance['owner_user_id'] : null;
        log_activity($db, $eventId, $ownerId, 'status changed by process automation', ['to' => $targetStatus, 'process_instance_id' => $instance['id']]);

        return [
            'detail' => $affected
                ? "Set event #$eventId status to \"$targetStatus\"."
                : "Event #$eventId wasn't in an expected prior status (" . implode('/', $from) . ") — left unchanged.",
            'status' => $targetStatus,
            'applied' => (bool) $affected,
        ];
    }

    private static function applyTaskTemplate(Database $db, array $instance, array $node, array &$variables): array
    {
        $eventId = self::linkedEventId($instance);
        if (!$eventId) {
            return ['detail' => 'Not linked to a real event — task creation skipped (simulated).'];
        }
        $templateId = !empty($node['config']['templateId']) ? (int) $node['config']['templateId'] : null;
        if (!$templateId) {
            return ['detail' => 'No task template configured on this node (config.templateId) — no tasks created.'];
        }
        $template = $db->one('SELECT * FROM event_templates WHERE id = ?', [$templateId]);
        if (!$template) {
            throw new OperationFailedException("Task template #$templateId not found.");
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
        $ownerId = !empty($instance['owner_user_id']) ? (int) $instance['owner_user_id'] : null;
        log_activity($db, $eventId, $ownerId, 'tasks applied from template', ['template_id' => $templateId, 'count' => $count, 'process_instance_id' => $instance['id']]);

        return ['detail' => "Created $count real production task(s) on event #$eventId from template #$templateId.", 'count' => $count];
    }

    /** Never sends anything. Builds a pre-filled Gmail compose link so a
     *  human reviews and sends it themselves — see the class doc comment. */
    private static function composeGmailLink(Database $db, array $instance, array $node, array &$variables): array
    {
        $email = null;
        $name = null;
        $eventId = self::linkedEventId($instance);
        if ($eventId) {
            $event = $db->one('SELECT booker_email, promoter_email, booker_name, title FROM events WHERE id = ?', [$eventId]);
            $email = $event['booker_email'] ?? $event['promoter_email'] ?? null;
            $name = $event['booker_name'] ?? null;
        }
        $email = $email ?: ($variables['recipient_email'] ?? null);
        if (!$email) {
            return ['detail' => 'No recipient email on file (no linked event, and no variables.recipient_email) — nothing drafted.'];
        }

        $subject = (string) ($node['config']['subject'] ?? $node['name']);
        $bodyTemplate = (string) ($node['config']['bodyTemplate'] ?? "Hi {{name}},\n\n[draft this message before sending]\n");
        $body = strtr($bodyTemplate, [
            '{{name}}' => $name ?: 'there',
            '{{client_org}}' => (string) ($variables['client_org'] ?? ''),
        ]);
        $composeUrl = 'https://mail.google.com/mail/?view=cm&fs=1'
            . '&to=' . rawurlencode($email)
            . '&su=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body);

        return [
            'detail' => "Draft ready for $email — {$composeUrl}",
            'gmail_compose_url' => $composeUrl,
            'recipient' => $email,
        ];
    }
}
