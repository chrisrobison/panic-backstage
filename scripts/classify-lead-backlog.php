<?php
declare(strict_types=1);

/**
 * Retry Booking Inbox classification for active inquiries that have an
 * inbound message but no current classification.
 *
 * Dry-run is the default:
 *   php scripts/classify-lead-backlog.php
 *   php scripts/classify-lead-backlog.php --apply --limit=2
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\Classifier;
use Panic\Leads\RoutingEngine;
use Panic\Leads\StatusMachine;

Env::load($root . '/.env');

$apply = in_array('--apply', $argv, true);
$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(25, (int) substr($arg, 8)));
    }
}

$db = new Database();
$classifier = new Classifier();
if (!$classifier->isEnabled()) {
    echo "Classifier is unavailable; no inquiries processed.\n";
    exit(0);
}

$rows = $db->all(
    "SELECT l.*, m.id message_id, m.subject message_subject, m.body_text message_body
     FROM leads l
     JOIN lead_messages m ON m.id = (
       SELECT first_message.id FROM lead_messages first_message
       WHERE first_message.lead_id = l.id
         AND first_message.direction = 'inbound'
         AND first_message.body_text IS NOT NULL
         AND first_message.body_text != ''
       ORDER BY first_message.id ASC LIMIT 1
     )
     WHERE l.status NOT IN ('onboarded','converted','booked','declined','lost','spam','duplicate','archived','canceled')
       AND NOT EXISTS (
         SELECT 1 FROM lead_classifications c
         WHERE c.lead_id = l.id AND c.is_current = 1
       )
     ORDER BY l.created_at, l.id
     LIMIT {$limit}"
);

if (!$apply) {
    foreach ($rows as $row) {
        printf(
            "[dry-run] lead #%-5d %-12s %s\n",
            $row['id'],
            $row['status'],
            $row['event_name'] ?: ($row['contact_name'] ?: $row['contact_email'])
        );
    }
    echo count($rows) . " inquiry/inquiries ready for classification. Dry run only.\n";
    exit(0);
}

$classified = 0;
$quarantined = 0;
$failed = 0;
foreach ($rows as $row) {
    try {
        $classification = $classifier->classify(
            $db,
            (int) $row['id'],
            (string) $row['message_body'],
            $row['message_subject'],
            (int) $row['message_id']
        );
        if ($classification === null) {
            $failed++;
            echo "lead #{$row['id']}: classifier returned no result\n";
            continue;
        }
        $classified++;

        $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$row['id']]);
        if ($fresh === null) {
            continue;
        }
        if ((float) ($classification['spam_probability'] ?? 0) >= 0.90) {
            (new StatusMachine($db))->transition(
                $fresh,
                'spam',
                null,
                'Automatically quarantined: classifier spam probability was at least 90%.',
                true,
                true,
                'automation',
                (int) $row['message_id']
            );
            $quarantined++;
            echo "lead #{$row['id']}: classified and quarantined as spam\n";
            continue;
        }

        $unworked = empty($fresh['assigned_to_user_id'])
            && empty($fresh['claimed_by_user_id'])
            && empty($fresh['owner_user_id'])
            && in_array($fresh['status'], ['new', 'classified', 'triage'], true);
        if ($unworked) {
            (new RoutingEngine())->route($db, $fresh);
        }
        echo "lead #{$row['id']}: classified" . ($unworked ? ' and routed' : '') . "\n";
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "lead #{$row['id']}: {$e->getMessage()}\n");
    }
}

printf(
    "Classification backlog: %d classified, %d quarantined, %d failed.\n",
    $classified,
    $quarantined,
    $failed
);
exit($failed > 0 ? 1 : 0);
