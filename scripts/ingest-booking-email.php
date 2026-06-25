<?php
declare(strict_types=1);

/**
 * Booking-email importer — turns an inbound email into a `leads` row.
 *
 * Designed to be invoked from an Exim user filter on the mailbox that receives
 * bookings@themab.org, e.g. in ~/.forward:
 *
 *   # Exim filter
 *   if "$h_to:$h_cc:$h_delivered-to:$h_x-forwarded-to:" contains "bookings@themab.org"
 *   then
 *     unseen pipe "/usr/local/bin/php /path/to/backstage/scripts/ingest-booking-email.php"
 *   endif
 *
 * `unseen` keeps normal delivery to the inbox; the pipe gets a copy. The raw
 * RFC822 message arrives on STDIN.
 *
 * Usage:
 *   php scripts/ingest-booking-email.php            < message.eml      (pipe / cron)
 *   php scripts/ingest-booking-email.php --file=msg.eml
 *   php scripts/ingest-booking-email.php --dry-run  < message.eml      (parse only, no DB)
 *
 * Safety: this is a mail-delivery pipe. It NEVER exits non-zero on a parse or DB
 * error (that would bounce or freeze the message). Every failure is logged and,
 * where possible, recorded in lead_intake_emails with status='error'.
 *
 * Env:
 *   ANTHROPIC_API_KEY        — enables LLM extraction of freeform emails.
 *   ANTHROPIC_API_KEY_FILE   — path to a file containing the key (env-style or raw).
 *   LEAD_PARSER_MODEL        — Anthropic model id (default claude-opus-4-8).
 *   BOOKING_INTAKE_LOG       — log file (default storage/logs/booking-intake.log).
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\LeadEmailParser;

Env::load($root . '/.env');

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$file   = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--file=')) {
        $file = substr($a, 7);
    }
}

$logFile = getenv('BOOKING_INTAKE_LOG') ?: ($root . '/storage/logs/booking-intake.log');
$log = static function (string $msg) use ($logFile): void {
    @mkdir(dirname($logFile), 0775, true);
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
};

// ── Read the raw message ────────────────────────────────────────────────────
$raw = $file !== null ? (string) @file_get_contents($file) : (string) stream_get_contents(STDIN);
if (trim($raw) === '') {
    $log('Empty message — nothing to import.');
    exit(0);
}

$apiKey = resolve_api_key();
$model  = getenv('LEAD_PARSER_MODEL') ?: 'claude-opus-4-8';

try {
    $parser = new LeadEmailParser($apiKey, $model);
    $result = $parser->parse($raw);
    $lead   = $result['lead'];
    $meta   = $result['meta'];
} catch (\Throwable $e) {
    $log('Parse error: ' . $e->getMessage());
    // Record the raw message so nothing is lost, if the DB is reachable.
    try {
        record_error_only($root, $raw, $e->getMessage());
    } catch (\Throwable $ignore) {
        // fall through
    }
    exit(0);
}

if ($dryRun) {
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

// ── Persist ─────────────────────────────────────────────────────────────────
try {
    $db = new Database();

    // Dedup: skip if we've already imported this Message-ID.
    $messageId = $meta['message_id'] ?? null;
    if ($messageId !== null) {
        $existing = $db->one('SELECT id, lead_id FROM lead_intake_emails WHERE message_id = ?', [$messageId]);
        if ($existing !== null) {
            $log("Duplicate message-id {$messageId} (intake #{$existing['id']}, lead #" . ($existing['lead_id'] ?? '-') . ') — skipped.');
            exit(0);
        }
    }

    $db->pdo()->beginTransaction();

    $leadId = $db->insert(
        'INSERT INTO leads (status, source, contact_name, contact_email, contact_org, contact_phone,
         event_name, event_type, band_name, desired_date, desired_date_alt, rooms_requested,
         projected_attendance, is_private, alcohol_plan, notes, risk_level)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $lead['status'],
            $lead['source'],
            $lead['contact_name'],
            $lead['contact_email'],
            $lead['contact_org'],
            $lead['contact_phone'],
            $lead['event_name'],
            $lead['event_type'],
            $lead['band_name'],
            $lead['desired_date'],
            $lead['desired_date_alt'],
            null, // rooms_requested
            $lead['projected_attendance'],
            $lead['is_private'],
            $lead['alcohol_plan'],
            $lead['notes'],
            $lead['risk_level'],
        ]
    );

    $db->insert(
        'INSERT INTO lead_intake_emails
         (lead_id, channel, message_id, from_name, from_email, reply_to, to_recipients,
          subject, parse_method, status, parsed_json, raw_email, received_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $leadId,
            'email',
            $meta['message_id'],
            $meta['from_name'],
            $meta['from_email'],
            $meta['reply_to'],
            $meta['to_recipients'],
            $meta['subject'],
            $meta['parse_method'],
            'imported',
            json_encode($lead, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $raw,
            $meta['received_at'],
        ]
    );

    $contact = $lead['contact_name'] ?: ($lead['contact_email'] ?: 'unknown sender');
    $db->insert(
        'INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)',
        [
            $leadId,
            null,
            'audit',
            "Imported from booking email (via {$meta['parse_method']}) — from {$contact}"
                . ($meta['subject'] ? ", subject: \"{$meta['subject']}\"" : ''),
        ]
    );

    $db->pdo()->commit();
    $log("Imported lead #{$leadId} from {$contact} (method={$meta['parse_method']}, msgid=" . ($messageId ?? '-') . ').');
    exit(0);
} catch (\Throwable $e) {
    if (isset($db) && $db->pdo()->inTransaction()) {
        $db->pdo()->rollBack();
    }
    $log('DB error: ' . $e->getMessage());
    try {
        record_error_only($root, $raw, $e->getMessage(), $meta ?? []);
    } catch (\Throwable $ignore) {
        // give up quietly
    }
    exit(0);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function resolve_api_key(): ?string
{
    $key = getenv('ANTHROPIC_API_KEY');
    if ($key) {
        return $key;
    }
    $path = getenv('ANTHROPIC_API_KEY_FILE');
    if ($path && is_readable($path)) {
        $contents = trim((string) file_get_contents($path));
        // Accept either a bare key or an env-style "ANTHROPIC_API_KEY=sk-...".
        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);
            if (stripos($line, 'ANTHROPIC_API_KEY=') === 0) {
                return trim(substr($line, strlen('ANTHROPIC_API_KEY=')), " \"'");
            }
        }
        if (str_starts_with($contents, 'sk-')) {
            return $contents;
        }
    }
    return null;
}

/** Record an intake row with status='error' so a failed message is never silently lost. */
function record_error_only(string $root, string $raw, string $error, array $meta = []): void
{
    $db = new Database();
    $messageId = $meta['message_id'] ?? null;
    if ($messageId !== null) {
        $existing = $db->one('SELECT id FROM lead_intake_emails WHERE message_id = ?', [$messageId]);
        if ($existing !== null) {
            return;
        }
    }
    $db->insert(
        'INSERT INTO lead_intake_emails
         (lead_id, channel, message_id, from_name, from_email, reply_to, to_recipients,
          subject, parse_method, status, error_message, raw_email, received_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            null, 'email', $messageId,
            $meta['from_name'] ?? null, $meta['from_email'] ?? null, $meta['reply_to'] ?? null,
            $meta['to_recipients'] ?? null, $meta['subject'] ?? null,
            $meta['parse_method'] ?? 'none', 'error', mb_substr($error, 0, 2000), $raw,
            $meta['received_at'] ?? null,
        ]
    );
}
