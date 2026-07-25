<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
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
}
