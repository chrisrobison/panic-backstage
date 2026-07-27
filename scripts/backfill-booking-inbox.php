<?php
declare(strict_types=1);

/**
 * Rebuild Booking Inbox conversation rows for pre-Inbox email leads and
 * quarantine untouched, obvious non-inquiries using the same IntakeGate as
 * live ingestion.
 *
 * Dry-run is the default:
 *   php scripts/backfill-booking-inbox.php
 *   php scripts/backfill-booking-inbox.php --apply
 *   php scripts/backfill-booking-inbox.php --apply --classify --limit=25
 *
 * --classify is intentionally explicit because it invokes the configured
 * model once per accepted, still-unclassified lead. Routing follows a
 * successful classification.
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\LeadEmailParser;
use Panic\Leads\Classifier;
use Panic\Leads\IntakeGate;
use Panic\Leads\RoutingEngine;
use Panic\Leads\StatusMachine;

Env::load($root . '/.env');

$apply = in_array('--apply', $argv, true);
$classify = in_array('--classify', $argv, true);
$limit = 1000;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(5000, (int) substr($arg, 8)));
    }
}

$db = new Database();
$parser = new LeadEmailParser(false);
$gate = new IntakeGate();
$classifier = new Classifier();

$rows = $db->all(
    "SELECT l.*, ie.id intake_id, ie.message_id, ie.raw_email, ie.subject intake_subject
     FROM leads l
     JOIN lead_intake_emails ie ON ie.id = (
       SELECT MIN(first_ie.id) FROM lead_intake_emails first_ie
       WHERE first_ie.lead_id = l.id AND first_ie.raw_email IS NOT NULL
     )
     WHERE l.source = 'email'
       AND NOT EXISTS (
         SELECT 1 FROM lead_messages m
         WHERE m.lead_id = l.id AND m.direction = 'inbound'
       )
     ORDER BY l.created_at, l.id
     LIMIT {$limit}"
);

$stats = [
    'candidates' => count($rows),
    'messages' => 0,
    'accepted' => 0,
    'quarantine' => 0,
    'protected' => 0,
    'classified' => 0,
    'errors' => 0,
];

foreach ($rows as $lead) {
    try {
        $parsed = $parser->parse((string) $lead['raw_email']);
        $disposition = $gate->evaluate((string) $lead['raw_email'], $parsed['lead'], $parsed['meta']);
        $worked = !in_array($lead['status'], ['new', 'classified'], true)
            || !empty($lead['assigned_to_user_id'])
            || !empty($lead['claimed_by_user_id'])
            || !empty($lead['owner_user_id'])
            || $db->one(
                "SELECT id FROM lead_messages
                 WHERE lead_id = ? AND direction IN ('outbound','internal_note') LIMIT 1",
                [$lead['id']]
            ) !== null;

        $action = $disposition['accept'] ? 'accept' : ($worked ? 'protect' : 'quarantine');
        $stats[$action === 'accept' ? 'accepted' : ($action === 'protect' ? 'protected' : 'quarantine')]++;
        printf(
            "%s lead #%-4d %-10s %s\n",
            $apply ? '[apply]' : '[dry-run]',
            $lead['id'],
            $action,
            $disposition['reason']
        );
        if (!$apply) {
            continue;
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $locked = $db->one('SELECT * FROM leads WHERE id = ? FOR UPDATE', [$lead['id']]);
            $existing = $db->one(
                "SELECT id FROM lead_messages WHERE lead_id = ? AND direction = 'inbound' LIMIT 1",
                [$lead['id']]
            );
            if ($locked === null || $existing !== null) {
                $pdo->rollBack();
                continue;
            }
            $meta = $parsed['meta'];
            $messageId = $db->insert(
                'INSERT INTO lead_messages
                 (lead_id, direction, channel, status, from_name, from_email, to_recipients,
                  subject, body_text, body_html, external_message_id, in_reply_to, checksum,
                  intake_email_id, is_read)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $lead['id'], 'inbound', 'email', 'received',
                    $meta['from_name'], $meta['from_email'], $meta['to_recipients'],
                    $meta['subject'], $meta['body_text'], $meta['body_html'],
                    $meta['message_id'], $meta['in_reply_to'],
                    hash('sha256', (string) $meta['body_text']),
                    $lead['intake_id'], 1,
                ]
            );
            $stats['messages']++;
            if ($action === 'quarantine') {
                (new StatusMachine($db))->transition(
                    $locked,
                    'spam',
                    null,
                    'Historical intake quarantine: ' . $disposition['reason'],
                    true,
                    true,
                    'automation',
                    $messageId
                );
                $db->run(
                    'UPDATE lead_intake_emails SET disposition_reason = ? WHERE id = ?',
                    [$disposition['reason'], $lead['intake_id']]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($classify && $action === 'accept' && $classifier->isEnabled()) {
            $current = $db->one(
                'SELECT id FROM lead_classifications WHERE lead_id = ? AND is_current = 1',
                [$lead['id']]
            );
            if ($current === null) {
                $classification = $classifier->classify(
                    $db,
                    (int) $lead['id'],
                    (string) $parsed['meta']['body_text'],
                    $parsed['meta']['subject'],
                    $messageId
                );
                if ($classification !== null) {
                    $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$lead['id']]);
                    if ($fresh !== null) {
                        (new RoutingEngine())->route($db, $fresh);
                    }
                    $stats['classified']++;
                }
            }
        }
    } catch (\Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, "lead #{$lead['id']}: {$e->getMessage()}\n");
    }
}

echo "\n";
foreach ($stats as $name => $count) {
    printf("%-12s %d\n", $name . ':', $count);
}
exit($stats['errors'] > 0 ? 1 : 0);
