<?php
declare(strict_types=1);

/**
 * Re-evaluate active historical email leads with the current IntakeGate and
 * quarantine untouched newsletter/non-inquiry rows.
 *
 * Dry-run is the default:
 *   php scripts/quarantine-booking-inbox-noise.php
 *   php scripts/quarantine-booking-inbox-noise.php --apply
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\LeadEmailParser;
use Panic\Leads\IntakeGate;
use Panic\Leads\StatusMachine;

Env::load($root . '/.env');

$apply = in_array('--apply', $argv, true);
$db = new Database();
$parser = new LeadEmailParser(false);
$gate = new IntakeGate();

$rows = $db->all(
    "SELECT l.*, ie.id AS intake_id, ie.raw_email, m.id AS message_id,
            c.spam_probability
     FROM leads l
     JOIN lead_intake_emails ie ON ie.id = (
       SELECT MIN(first_ie.id)
       FROM lead_intake_emails first_ie
       WHERE first_ie.lead_id = l.id AND first_ie.raw_email IS NOT NULL
     )
     LEFT JOIN lead_messages m ON m.intake_email_id = ie.id
     LEFT JOIN lead_classifications c ON c.lead_id = l.id AND c.is_current = 1
     WHERE l.source = 'email'
       AND l.status NOT IN ('spam','duplicate','archived','declined','lost','booked',
                            'converted','onboarded','canceled')
     ORDER BY l.created_at, l.id"
);

$matched = 0;
$quarantined = 0;
$flagged = 0;
$errors = 0;

foreach ($rows as $lead) {
    try {
        $parsed = $parser->parse((string) $lead['raw_email']);
        $disposition = $gate->evaluate(
            (string) $lead['raw_email'],
            $parsed['lead'],
            $parsed['meta']
        );
        $aiSpam = isset($lead['spam_probability']) && (float) $lead['spam_probability'] >= 0.90;
        if ($disposition['accept'] && !$aiSpam) {
            continue;
        }

        $matched++;
        $reason = $aiSpam && $disposition['accept']
            ? 'AI spam probability was at least 90%'
            : $disposition['reason'];
        $unsafe = unsafeNoiseReasons($db, $lead);
        if ($unsafe !== []) {
            $flagged++;
            printf(
                "[review] lead #%-5d %s (%s)\n",
                $lead['id'],
                $lead['event_name'] ?: ($lead['contact_email'] ?: 'untitled'),
                implode('; ', $unsafe)
            );
            continue;
        }

        printf(
            "%s lead #%-5d %-48s %s\n",
            $apply ? '[apply]' : '[dry-run]',
            $lead['id'],
            mb_strimwidth((string) ($lead['event_name'] ?: $lead['contact_email']), 0, 48, '...'),
            $reason
        );
        if (!$apply) {
            continue;
        }

        $db->pdo()->beginTransaction();
        try {
            $fresh = $db->one('SELECT * FROM leads WHERE id = ? FOR UPDATE', [$lead['id']]);
            if ($fresh === null || in_array($fresh['status'], ['spam', 'duplicate'], true)) {
                $db->pdo()->rollBack();
                continue;
            }
            (new StatusMachine($db))->forceApply(
                $fresh,
                'spam',
                null,
                'Automatically quarantined by historical intake recheck: ' . $reason,
                'automation',
                !empty($lead['message_id']) ? (int) $lead['message_id'] : null
            );
            $db->run(
                'UPDATE lead_intake_emails SET disposition_reason = ? WHERE lead_id = ?',
                [$reason, $lead['id']]
            );
            $db->pdo()->commit();
            $quarantined++;
        } catch (\Throwable $e) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
            throw $e;
        }
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "lead #{$lead['id']}: {$e->getMessage()}\n");
    }
}

printf(
    "\nMatched: %d; quarantined: %d; manual review: %d; errors: %d%s\n",
    $matched,
    $quarantined,
    $flagged,
    $errors,
    $apply ? '' : ' (dry run)'
);
exit($errors > 0 ? 1 : 0);

/** @return list<string> */
function unsafeNoiseReasons(Database $db, array $lead): array
{
    $reasons = [];
    if (!empty($lead['assigned_to_user_id'])) {
        $reasons[] = 'assigned';
    }
    if (!empty($lead['claimed_by_user_id'])) {
        $reasons[] = 'claimed';
    }
    if (!empty($lead['owner_user_id'])) {
        $reasons[] = 'owned';
    }
    if (!empty($lead['first_response_at'])) {
        $reasons[] = 'staff response recorded';
    }
    if (!empty($lead['converted_event_id'])
        || $db->one('SELECT id FROM events WHERE lead_id = ? LIMIT 1', [$lead['id']]) !== null
    ) {
        $reasons[] = 'linked to an event';
    }
    if ($db->one('SELECT id FROM tasks WHERE related_lead_id = ? LIMIT 1', [$lead['id']]) !== null) {
        $reasons[] = 'linked task';
    }
    if ($db->one(
        "SELECT id FROM lead_notes WHERE lead_id = ? AND type != 'audit' LIMIT 1",
        [$lead['id']]
    ) !== null) {
        $reasons[] = 'human note';
    }
    if ($db->one(
        "SELECT id FROM lead_messages
         WHERE lead_id = ? AND direction = 'outbound'
           AND external_message_id != 'auto-ack'
           AND (raw_headers_json IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(raw_headers_json, '$.kind')) != 'auto-ack')
         LIMIT 1",
        [$lead['id']]
    ) !== null) {
        $reasons[] = 'staff reply';
    }
    if ($db->one('SELECT id FROM lead_attachments WHERE lead_id = ? LIMIT 1', [$lead['id']]) !== null) {
        $reasons[] = 'attachment';
    }
    return $reasons;
}
