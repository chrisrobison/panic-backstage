<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use Panic\LeadEmailParser;
use Panic\Mailer;
use function Panic\log_lead_activity;

/**
 * Automatic "we got your inquiry" receipt for a brand-new Booking Inbox
 * lead — the spec's neutral acknowledgment message. Sent at most once per
 * lead, never for spam/duplicate/internal sources, gated per-venue by
 * lead_inbox_settings.ack_enabled (migration 075), and always appears to
 * come from bookings@themab.org via Mailer's from-address override so a
 * customer reply lands back in the same mailbox the ingestion pipe reads
 * (docs/booking-email-import.md).
 *
 * Only sent for inquiries that actually came in through one of our own
 * intake forms — the website's own inquiry form (source=website), or a
 * Jotform submission forwarded as email (lead_intake_emails.parse_method
 * starting with "jotform") — never for a freeform email a person wrote
 * directly to bookings@ (parse_method llm/heuristic/none). An automatic
 * form-receipt-sounding reply to someone's personally-written email reads as
 * a canned brush-off; a human triaging/replying is the real acknowledgment
 * there. See isFromOneOfOurForms().
 *
 * Also never sent for anything that is plainly a reply to some existing
 * conversation (In-Reply-To set, or a Re:/Fwd:/Fw:/Aw: subject prefix) —
 * even when it landed here as a "new" lead because Panic\Leads\ThreadMatcher
 * couldn't resolve it to a specific prior lead (a reply to a pre-threading-
 * era message, a different channel, or a dropped References chain). A
 * "thanks for reaching out" first-contact receipt is plainly wrong for
 * something that says right in its headers/subject that it's a continuation
 * of a conversation. See looksLikeAReply().
 *
 * Every send (or skip, with why) is recorded: a `lead_messages` row so the
 * Conversation tab shows it like any other outbound message, and a
 * `lead_audit_log` row for the audit trail.
 */
final class Acknowledgment
{
    /**
     * Marker stored in lead_messages.raw_headers_json so the send-once check
     * below can find this row cheaply. external_message_id used to double as
     * this sentinel, but now holds the auto-ack's real Message-ID instead
     * (see Mailer::generateMessageId()) so a customer replying to the
     * acknowledgment threads back to this lead via Panic\Leads\ThreadMatcher.
     */
    private const MARKER = 'auto-ack';

    private const SKIP_SOURCES = ['internal', 'manual'];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param array $lead A full `leads` row (must include id, source, status,
     *                    contact_name, contact_email).
     * @return bool true if a new acknowledgment was sent.
     */
    public function maybeSend(Database $db, array $lead): bool
    {
        $leadId = (int) $lead['id'];
        $email  = trim((string) ($lead['contact_email'] ?? ''));

        if ($email === '' || in_array((string) $lead['source'], self::SKIP_SOURCES, true)) {
            return false;
        }
        if (in_array((string) $lead['status'], ['spam', 'duplicate'], true)) {
            return false;
        }
        if ($this->looksLikeAReply($db, $lead)) {
            return false;
        }
        if (!$this->isFromOneOfOurForms($db, $lead)) {
            return false;
        }

        $already = $db->one(
            "SELECT id FROM lead_messages
             WHERE lead_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(raw_headers_json, '$.kind')) = ?
             LIMIT 1",
            [$leadId, self::MARKER]
        );
        if ($already !== null) {
            return false;
        }

        $settings = $db->one(
            'SELECT * FROM lead_inbox_settings WHERE venue_id = (SELECT id FROM venues ORDER BY id LIMIT 1)'
        );
        if ($settings === null || !((int) $settings['ack_enabled'])) {
            return false;
        }

        $subject = (string) $settings['ack_subject'];
        $body    = (string) $settings['ack_body'];
        $name    = trim((string) ($lead['contact_name'] ?? ''));
        if ($name !== '') {
            $body = "Hi {$name},\n\n{$body}";
        }

        // Threaded against whatever inbound message(s) triggered this lead so
        // far (normally just the one that created it) — see the class
        // docblock and Panic\Leads\ThreadMatcher.
        $priorMessageIds = array_column(
            $db->all(
                "SELECT external_message_id FROM lead_messages
                 WHERE lead_id = ? AND external_message_id IS NOT NULL
                 ORDER BY id ASC",
                [$leadId]
            ),
            'external_message_id'
        );
        $externalMessageId = Mailer::generateMessageId('bookings@themab.org');

        $mailer = new Mailer($this->root, $db, 'bookings@themab.org', 'Mabuhay Gardens Booking Team');
        $mailer->send(
            $email,
            $subject,
            $body,
            null,
            null,
            [],
            [],
            $externalMessageId,
            $priorMessageIds !== [] ? end($priorMessageIds) : null,
            $priorMessageIds
        );

        $db->insert(
            'INSERT INTO lead_messages
             (lead_id, direction, channel, status, from_name, from_email, to_recipients, subject,
              body_text, external_message_id, in_reply_to, raw_headers_json, is_read)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $leadId, 'outbound', 'email', 'sent',
                'Mabuhay Gardens Booking Team', 'bookings@themab.org', $email, $subject,
                $body, $externalMessageId, $priorMessageIds !== [] ? end($priorMessageIds) : null,
                json_encode(['kind' => self::MARKER]),
            ]
        );

        log_lead_activity($db, $leadId, null, 'auto_acknowledged', ['to' => $email]);

        return true;
    }

    /**
     * True when the inbound message that created this lead carries its own
     * evidence of being a reply/forward. Checked against the lead's earliest
     * inbound lead_messages row rather than the leads row itself, since
     * that's where in_reply_to/subject were captured verbatim at ingestion
     * (scripts/ingest-booking-email.php); a lead with no such row (e.g. a
     * website-form submission, which was never an email at all) can't be a
     * reply.
     */
    private function looksLikeAReply(Database $db, array $lead): bool
    {
        $msg = $db->one(
            "SELECT subject, in_reply_to FROM lead_messages
             WHERE lead_id = ? AND direction = 'inbound'
             ORDER BY id ASC LIMIT 1",
            [(int) $lead['id']]
        );
        if ($msg === null) {
            return false;
        }
        if (!empty($msg['in_reply_to'])) {
            return true;
        }
        return LeadEmailParser::hasReplyPrefix($msg['subject'] ?? null);
    }

    /**
     * True for a lead that came in through one of our own intake forms —
     * non-email sources (the website's own inquiry form is source=website)
     * are always one of "our forms" by definition, since there's no
     * freeform-prose email to have written instead. For an email-sourced
     * lead, only a Jotform submission forwarded as email counts: its
     * originating lead_intake_emails row was parsed deterministically from
     * labelled Jotform fields (parse_method 'jotform' or 'jotform+llm' — see
     * LeadEmailParser), as opposed to a person's own freeform email
     * (parse_method 'llm'/'heuristic'/'none').
     */
    private function isFromOneOfOurForms(Database $db, array $lead): bool
    {
        if ((string) ($lead['source'] ?? '') !== 'email') {
            return true;
        }
        $intake = $db->one(
            'SELECT parse_method FROM lead_intake_emails WHERE lead_id = ? ORDER BY id ASC LIMIT 1',
            [(int) $lead['id']]
        );
        return str_starts_with((string) ($intake['parse_method'] ?? ''), 'jotform');
    }
}
