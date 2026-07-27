<?php
declare(strict_types=1);

/**
 * Backfill thread grouping for booking-email leads imported before
 * Panic\Leads\ThreadMatcher existed (see scripts/ingest-booking-email.php
 * and docs/booking-email-import.md#threading). Every reply back then
 * created its own brand-new `leads` row instead of being folded into the
 * original inquiry's conversation. This builds connected components from
 * every email's own Message-ID plus its complete References ancestry. That
 * means two messages which share an unimported parent are still grouped.
 *
 * Deliberately HEADER-ONLY (In-Reply-To/References) — NOT ThreadMatcher's
 * subject+sender fallback. A real reply-chain match via these headers is
 * exact and safe to apply automatically; several existing "leads" in this
 * database are automated/marketing senders (Eventbrite, Bandsintown, event
 * listing sites, ...) that reuse the same templated subject line across
 * many genuinely distinct emails from the same address — running the
 * subject fallback retroactively over ALL historical data at once risks
 * silently merging unrelated inquiries. (The subject fallback is still fine
 * going forward for brand-new mail, where ThreadMatcher scopes it to a
 * 180-day lookback against one sender rather than the whole history.)
 *
 * A merge is only ever APPLIED automatically when the later ("duplicate")
 * lead shows no sign of having been worked — see unsafeReasons() for the
 * exact checks (still in an active pipeline status, nobody is assigned to,
 * claiming, or owning it, no real reply was sent, no human-authored note,
 * no attachments, no linked task, not converted to an event). Two spam
 * records may also converge when their canonical thread is already spam.
 *   - lead_intake_emails / lead_messages / lead_notes / lead_attachments
 *     rows move to the earlier (canonical) lead
 *   - an audit lead_note is added to the canonical lead
 *   - the duplicate `leads` row is marked status='duplicate' (via
 *     Leads\StatusMachine — a real, existing status for exactly this
 *     situation) rather than deleted, so nothing about it is lost and it
 *     stays a visible, auditable tombstone
 *
 * Anything that doesn't meet that bar is left completely untouched and
 * printed under "needs manual review" instead — this script never silently
 * reconciles conflicting classification/assignment/status history.
 *
 * Usage:
 *   php scripts/backfill-lead-threads.php              (dry run — default, writes nothing)
 *   php scripts/backfill-lead-threads.php --apply       (writes the safe merges)
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\LeadEmailParser;
use Panic\Leads\StatusMachine;
use Panic\Leads\ThreadMatcher;

Env::load($root . '/.env');

$apply = in_array('--apply', array_slice($argv, 1), true);

$db = new Database();

$rows = $db->all(
    "SELECT ie.id, ie.lead_id, ie.message_id, ie.raw_email,
            lm.id AS lead_message_id
     FROM lead_intake_emails ie
     LEFT JOIN lead_messages lm ON lm.intake_email_id = ie.id
     WHERE ie.lead_id IS NOT NULL AND ie.raw_email IS NOT NULL AND ie.raw_email != ''
     ORDER BY COALESCE(ie.received_at, ie.created_at) ASC, ie.id ASC"
);

$parser = new LeadEmailParser(false); // enabled:false — no LLM; only the deterministic header extraction is needed here.

$merged = [];
$flagged = [];
$parseErrors = 0;
$referenceRows = 0;
$leadOrder = [];
$adjacency = [];
$tokenOwners = [];
$matcher = new ThreadMatcher();

foreach ($rows as $position => $row) {
    try {
        $meta = $parser->parse((string) $row['raw_email'])['meta'];
    } catch (\Throwable $e) {
        $parseErrors++;
        continue;
    }

    $referenceIds = array_values(array_unique(array_filter(
        array_merge($meta['in_reply_to'] ? [$meta['in_reply_to']] : [], $meta['reference_ids'] ?? []),
        static fn ($id) => $id !== null && $id !== '' && $id !== $row['message_id']
    )));

    if ($apply && !empty($row['lead_message_id'])) {
        $matcher->recordReferences($db, (int) $row['lead_message_id'], $referenceIds);
        $referenceRows += count($referenceIds);
    }

    $currentLeadId = (int) $row['lead_id'];
    $leadOrder[$currentLeadId] ??= $position;
    $adjacency[$currentLeadId] ??= [];

    $tokens = array_values(array_unique(array_filter([
        $row['message_id'],
        ...$referenceIds,
    ], static fn ($id) => $id !== null && trim((string) $id) !== '')));
    foreach ($tokens as $token) {
        $token = trim((string) $token, " \t<>");
        foreach ($tokenOwners[$token] ?? [] as $otherLeadId) {
            if ($otherLeadId === $currentLeadId) {
                continue;
            }
            $adjacency[$currentLeadId][$otherLeadId] = true;
            $adjacency[$otherLeadId][$currentLeadId] = true;
        }
        $tokenOwners[$token][$currentLeadId] = $currentLeadId;
    }
}

// Traverse each exact References graph and use the chronologically earliest
// imported lead as its canonical inquiry.
$seen = [];
foreach (array_keys($adjacency) as $leadId) {
    if (isset($seen[$leadId])) {
        continue;
    }
    $component = [];
    $queue = [$leadId];
    while ($queue !== []) {
        $candidate = array_pop($queue);
        if (isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        $component[] = $candidate;
        foreach (array_keys($adjacency[$candidate] ?? []) as $neighbor) {
            if (!isset($seen[$neighbor])) {
                $queue[] = $neighbor;
            }
        }
    }
    if (count($component) < 2) {
        continue;
    }
    usort($component, static fn (int $a, int $b): int => ($leadOrder[$a] <=> $leadOrder[$b]) ?: ($a <=> $b));
    $canonicalId = array_shift($component);
    foreach ($component as $duplicateId) {
        $reasons = unsafeReasons($db, $duplicateId, $canonicalId);
        if ($reasons !== []) {
            $flagged[] = ['lead_id' => $duplicateId, 'canonical' => $canonicalId, 'why' => $reasons];
            continue;
        }
        if ($apply) {
            mergeLead($db, $duplicateId, $canonicalId);
        }
        $merged[] = ['from' => $duplicateId, 'to' => $canonicalId];
    }
}

// ── Report ──────────────────────────────────────────────────────────────────
echo "Scanned " . count($rows) . " existing intake email(s).\n";
if ($apply) {
    echo "  Indexed {$referenceRows} References value(s) for future matching.\n";
}
if ($parseErrors > 0) {
    echo "  {$parseErrors} raw message(s) failed to re-parse and were skipped.\n";
}
echo "\n" . ($apply ? 'Merged' : 'Would merge') . ' ' . count($merged) . " duplicate lead(s) found via a connected References chain:\n";
foreach ($merged as $m) {
    echo "  lead #{$m['from']}  →  lead #{$m['to']}\n";
}

echo "\nFlagged for manual review — a header match was found but the duplicate lead shows signs of having been worked, so it was left untouched (" . count($flagged) . "):\n";
foreach ($flagged as $f) {
    echo "  lead #{$f['lead_id']} appears to reply to lead #{$f['canonical']}, but: " . implode('; ', $f['why']) . "\n";
}

if (!$apply) {
    echo "\nDry run only — nothing was written. Re-run with --apply to write the merges listed above.\n";
    echo "(Flagged leads are never auto-merged, even with --apply — they need a human look.)\n";
}

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Reasons NOT to auto-merge $leadId into another lead — empty array means
 * it's safe (still untouched by any human/business process).
 *
 * @return list<string>
 */
function unsafeReasons(Database $db, int $leadId, int $canonicalId): array
{
    $reasons = [];
    $lead = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    if ($lead === null) {
        return ['lead row not found'];
    }

    $canonical = $db->one('SELECT status FROM leads WHERE id = ?', [$canonicalId]);
    $sameSpamThread = $lead['status'] === 'spam' && ($canonical['status'] ?? null) === 'spam';
    if (!$sameSpamThread && !in_array((string) $lead['status'], ['new', 'classified', 'assigned', 'evaluating', 'triage'], true)) {
        $reasons[] = "status is '{$lead['status']}' (past the automatic pipeline stages)";
    }
    if (!empty($lead['assigned_to_user_id'])) {
        $reasons[] = 'assigned to a staff member';
    }
    if (!empty($lead['claimed_by_user_id'])) {
        $reasons[] = 'claimed by a staff member';
    }
    if (!empty($lead['owner_user_id'])) {
        $reasons[] = 'already has an owner';
    }
    if (!empty($lead['first_response_at'])) {
        $reasons[] = 'a first response was already recorded';
    }
    if (!empty($lead['converted_event_id'])) {
        $reasons[] = 'converted to an event';
    }
    if ($db->one('SELECT id FROM events WHERE lead_id = ? LIMIT 1', [$leadId]) !== null) {
        $reasons[] = 'linked to an event';
    }
    if ($db->one('SELECT id FROM tasks WHERE related_lead_id = ? LIMIT 1', [$leadId]) !== null) {
        $reasons[] = 'has a linked task';
    }
    if ($db->one("SELECT id FROM lead_notes WHERE lead_id = ? AND type != 'audit' LIMIT 1", [$leadId]) !== null) {
        $reasons[] = 'has a human-authored note';
    }
    if ($db->one(
        "SELECT id FROM lead_messages WHERE lead_id = ? AND direction = 'outbound'
         AND external_message_id != 'auto-ack'
         AND (raw_headers_json IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_headers_json, '$.kind')) != 'auto-ack')
         LIMIT 1",
        [$leadId]
    ) !== null) {
        $reasons[] = 'a staff reply was already sent';
    }
    if ($db->one('SELECT id FROM lead_attachments WHERE lead_id = ? LIMIT 1', [$leadId]) !== null) {
        $reasons[] = 'has file attachment(s)';
    }

    return $reasons;
}

/**
 * Fold $duplicateId's conversation into $canonicalId and tombstone the
 * duplicate lead as status=duplicate. All-or-nothing in one transaction.
 */
function mergeLead(Database $db, int $duplicateId, int $canonicalId): void
{
    $canonical = $db->one('SELECT inquiry_number FROM leads WHERE id = ?', [$canonicalId]);
    $duplicate = $db->one('SELECT * FROM leads WHERE id = ?', [$duplicateId]);
    if ($duplicate === null) {
        return;
    }

    $db->pdo()->beginTransaction();
    try {
        ensureConversationRows($db, $canonicalId);
        ensureConversationRows($db, $duplicateId);
        $db->run('UPDATE lead_intake_emails SET lead_id = ? WHERE lead_id = ?', [$canonicalId, $duplicateId]);
        $db->run('UPDATE lead_messages SET lead_id = ? WHERE lead_id = ?', [$canonicalId, $duplicateId]);
        $db->run('UPDATE lead_notes SET lead_id = ? WHERE lead_id = ?', [$canonicalId, $duplicateId]);
        $db->run('UPDATE lead_attachments SET lead_id = ? WHERE lead_id = ?', [$canonicalId, $duplicateId]);

        $db->insert(
            'INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?, NULL, ?, ?)',
            [
                $canonicalId,
                'audit',
                "Retroactively merged lead #{$duplicateId}"
                    . (!empty($duplicate['inquiry_number']) ? " (inquiry {$duplicate['inquiry_number']})" : '')
                    . ' into this conversation — threading backfill (scripts/backfill-lead-threads.php).',
            ]
        );

        (new StatusMachine($db))->forceApply(
            $duplicate,
            'duplicate',
            null,
            'Merged into inquiry ' . ($canonical['inquiry_number'] ?? "#{$canonicalId}")
                . " (lead #{$canonicalId}) — retroactive threading backfill.",
            'automation'
        );

        $db->pdo()->commit();
    } catch (\Throwable $e) {
        $db->pdo()->rollBack();
        throw $e;
    }
}

/**
 * Older imports do not always have the normalized lead_messages row that the
 * conversation UI reads. Rebuild any missing row before its intake is moved.
 */
function ensureConversationRows(Database $db, int $leadId): void
{
    $intakes = $db->all(
        "SELECT ie.*
         FROM lead_intake_emails ie
         WHERE ie.lead_id = ? AND ie.raw_email IS NOT NULL
           AND NOT EXISTS (
             SELECT 1 FROM lead_messages lm WHERE lm.intake_email_id = ie.id
           )
         ORDER BY COALESCE(ie.received_at, ie.created_at), ie.id",
        [$leadId]
    );
    if ($intakes === []) {
        return;
    }

    $parser = new LeadEmailParser(false);
    $matcher = new ThreadMatcher();
    foreach ($intakes as $intake) {
        $meta = $parser->parse((string) $intake['raw_email'])['meta'];
        $messageId = $db->insert(
            'INSERT INTO lead_messages
             (lead_id, direction, channel, status, from_name, from_email, to_recipients,
              subject, body_text, body_html, external_message_id, in_reply_to, checksum,
              intake_email_id, is_read)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
            [
                $leadId, 'inbound', 'email', 'received',
                $meta['from_name'], $meta['from_email'], $meta['to_recipients'],
                $meta['subject'], $meta['body_text'], $meta['body_html'],
                $meta['message_id'], $meta['in_reply_to'],
                hash('sha256', (string) $meta['body_text']), $intake['id'],
            ]
        );
        $matcher->recordReferences($db, $messageId, $meta['reference_ids'] ?? []);
    }
}
