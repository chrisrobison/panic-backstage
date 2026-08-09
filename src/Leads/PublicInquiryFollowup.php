<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use Panic\Mailer;
use Panic\NotificationPreferences;
use Panic\Notifications\PushMessages;
use Panic\Notifications\PushNotifier;
use function Panic\log_lead_activity;

/**
 * Slow and failure-prone work that follows a public inquiry submission.
 * Called only by the background worker, never on the public request thread.
 */
final class PublicInquiryFollowup
{
    public function __construct(private readonly string $root)
    {
    }

    public function classifyRouteAndAcknowledge(Database $db, int $leadId, int $messageId): void
    {
        $lead = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        $message = $db->one('SELECT subject, body_text FROM lead_messages WHERE id = ? AND lead_id = ?', [$messageId, $leadId]);
        if ($lead === null || $message === null) {
            // A deleted lead is a successfully obsolete job, not a poison job.
            return;
        }

        $classification = $db->one(
            'SELECT spam_probability FROM lead_classifications WHERE lead_id = ? AND is_current = 1',
            [$leadId]
        );
        if ($classification === null) {
            $classifier = new Classifier();
            if ($classifier->isEnabled()) {
                $result = $classifier->classify(
                    $db,
                    $leadId,
                    (string) $message['body_text'],
                    (string) $message['subject'],
                    $messageId
                );
                if ($result === null) {
                    throw new \RuntimeException('Inquiry classification did not complete.');
                }
                $classification = $db->one(
                    'SELECT spam_probability FROM lead_classifications WHERE lead_id = ? AND is_current = 1',
                    [$leadId]
                );
            }
        }

        if ($classification !== null && (float) ($classification['spam_probability'] ?? 0) >= 0.90) {
            $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            if ($fresh !== null && (string) $fresh['status'] !== 'spam') {
                (new StatusMachine($db))->transition(
                    $fresh,
                    'spam',
                    null,
                    'Automatically quarantined: classifier spam probability was at least 90%.',
                    true,
                    true,
                    'automation'
                );
            }
            return;
        }

        // A retry must not re-run routing and produce duplicate audit entries.
        $alreadyRouted = $db->one(
            "SELECT id FROM lead_audit_log
             WHERE lead_id = ? AND action = 'public_inquiry_routed'
             LIMIT 1",
            [$leadId]
        );
        if ($alreadyRouted === null) {
            $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            if ($fresh !== null) {
                (new RoutingEngine())->route($db, $fresh);
                log_lead_activity($db, $leadId, null, 'public_inquiry_routed');
            }
        }

        $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if ($fresh !== null) {
            (new Acknowledgment($this->root))->maybeSend($db, $fresh);
            $failedAck = $db->one(
                "SELECT id FROM lead_messages
                 WHERE lead_id = ? AND status = 'failed'
                   AND JSON_UNQUOTE(JSON_EXTRACT(raw_headers_json, '$.kind')) = 'auto-ack'
                 LIMIT 1",
                [$leadId]
            );
            if ($failedAck !== null) {
                throw new \RuntimeException('Inquiry acknowledgment delivery was not accepted.');
            }
        }
    }

    public function notifyAdmins(Database $db, int $leadId): void
    {
        $lead = $db->one(
            'SELECT id, contact_name, contact_email, contact_org, event_name, event_type, desired_date
             FROM leads WHERE id = ?',
            [$leadId]
        );
        if ($lead === null) {
            return;
        }
        if ($db->one(
            "SELECT id FROM lead_audit_log
             WHERE lead_id = ? AND action = 'public_inquiry_admins_notified'
             LIMIT 1",
            [$leadId]
        ) !== null) {
            return;
        }

        $admins = $db->all(
            "SELECT name, email, notify_event_updates FROM users
             WHERE role = 'venue_admin' AND email IS NOT NULL AND email != ''
               AND email NOT LIKE '%.local' AND is_hidden = 0"
        );
        $link = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/#inbox-unassigned';
        $subject = '[Backstage] New booking inquiry: ' . (string) $lead['contact_name'];
        $text = "A new booking inquiry came in through the website widget.\n\n"
            . "From: {$lead['contact_name']} <{$lead['contact_email']}>\n\n"
            . "Review it in the Booking Inbox: {$link}\n";

        $sent = [];
        foreach ($admins as $admin) {
            if (!NotificationPreferences::wants($admin, NotificationPreferences::EVENT_UPDATES)) {
                continue;
            }
            $mailer = new Mailer($this->root, $db);
            $mailer->send((string) $admin['email'], $subject, $text);
            if (!$mailer->deliveryAccepted()) {
                throw new \RuntimeException('Admin notification delivery was not accepted.');
            }
            $sent[] = (string) $admin['email'];
        }

        // Same operational event, second channel. Queued (never sent inline)
        // and a no-op when Firebase is unconfigured, so this cannot fail a
        // job that has already delivered its email. The unique key is safe as
        // a bare lead id because this whole method runs once per lead — the
        // audit-log guard above makes a retry return before reaching here.
        (new PushNotifier())->notifyVenueAdmins(
            $db,
            PushMessages::newBookingInquiry($lead),
            null,
            "push-new-inquiry:{$leadId}"
        );

        log_lead_activity($db, $leadId, null, 'public_inquiry_admins_notified', [
            'recipient_count' => count($sent),
        ]);
    }
}
