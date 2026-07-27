<?php
declare(strict_types=1);

namespace Panic\Leads;

/**
 * Conservative pre-lead gate for the bookings mailbox.
 *
 * Messages rejected here are retained in lead_intake_emails with
 * status=skipped and a disposition reason. Thread matching runs before this
 * gate, so a real reply remains attached to its existing inquiry even when
 * the reply itself contains little booking vocabulary.
 */
final class IntakeGate
{
    /** @return array{accept:bool, reason:string} */
    public function evaluate(string $rawEmail, array $lead, array $meta): array
    {
        $method = (string) ($meta['parse_method'] ?? 'none');
        if (str_starts_with($method, 'jotform')) {
            return ['accept' => true, 'reason' => 'Structured booking form submission'];
        }

        $headers = $this->headers($rawEmail);
        $autoSubmitted = strtolower(trim($headers['auto-submitted'] ?? ''));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return ['accept' => false, 'reason' => 'Automated message (Auto-Submitted header)'];
        }
        if (isset($headers['list-unsubscribe']) || isset($headers['list-id'])) {
            return ['accept' => false, 'reason' => 'Mailing-list or newsletter message'];
        }
        if (preg_match('/\b(bulk|list|junk)\b/i', $headers['precedence'] ?? '')) {
            return ['accept' => false, 'reason' => 'Bulk-mail message'];
        }

        $from = strtolower((string) ($meta['from_email'] ?? ''));
        $subject = strtolower((string) ($meta['subject'] ?? ''));
        $body = strtolower((string) ($meta['body_text'] ?? ''));
        $haystack = $subject . "\n" . mb_substr($body, 0, 12000);

        $automatedSender = preg_match(
            '/(?:^|[._+@-])(no-?reply\d*|do-?not-?reply|notifications?|updates?|newsletter|'
            . 'marketing(?:-emails)?|bounces?)(?:[._+@-]|$)/i',
            $from
        );
        $newsletterScore = 0;
        if (preg_match(
            '/\b(unsubscribe|manage (?:your )?(?:email )?preferences|email preferences|'
            . 'view (?:this )?email in (?:your )?browser|view in your browser|'
            . 'you are receiving this email|update subscription preferences)\b/i',
            $haystack
        )) {
            $newsletterScore += 2;
        }
        if (preg_match(
            '/\b(mailchimp|mailchimpapp|list-manage|campaign-archive|constantcontact|'
            . 'kmail-lists|klaviyo|sendgrid|substack|campaignmonitor|mailgun)\b/i',
            $rawEmail . "\n" . $haystack
        )) {
            $newsletterScore++;
        }
        if (preg_match('/\b(newsletter|weekly (?:roundup|digest)|this week in|week in wonder)\b/i', $subject)) {
            $newsletterScore++;
        }
        if (preg_match_all('/https?:\/\//i', $haystack) >= 8) {
            $newsletterScore++;
        }
        if ($newsletterScore >= 2) {
            return ['accept' => false, 'reason' => 'Newsletter content fingerprints detected'];
        }

        $bookingSignal = preg_match(
            '/\b(book(?:ing|ed)?|inquir(?:y|e)|venue|event|show|concert|gig|perform(?:er|ance)?|'
            . 'band|artist|promoter|rental|private party|wedding|reception|corporate event|'
            . 'availability|hold (?:a )?date|tour date|expected (?:crowd|attendance)|guest count)\b/i',
            $haystack
        );
        $inquiryIntent = preg_match(
            '/\b(?:i|we|our band|my band|our organization|our company)\b.{0,100}'
            . '\b(?:book|rent|host|perform|play|reserve|availability|hold)\b|'
            . '\b(?:interested in|looking to|would like to|want to|trying to)\b.{0,80}'
            . '\b(?:book|rent|host|perform|play|reserve)\b/i',
            $haystack
        );
        $specificDateOrCrowd = !empty($lead['desired_date'])
            || !empty($lead['projected_attendance'])
            || preg_match('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $haystack);

        $nonInquiryNotification = preg_match(
            '/\b(left a review|new review for|access your tickets|your tickets (?:are|for)|'
            . 'subscription .* failed|payment .* failed|password reset|security alert|'
            . 'verify your (?:account|email)|weekly digest|events being planned in your area)\b/i',
            $haystack
        );
        if ($automatedSender && $nonInquiryNotification && !$inquiryIntent && !$specificDateOrCrowd) {
            return ['accept' => false, 'reason' => 'Automated non-inquiry notification'];
        }
        if ($automatedSender && !$bookingSignal) {
            return ['accept' => false, 'reason' => 'Automated sender with no booking signal'];
        }
        if (!$bookingSignal && !$specificDateOrCrowd) {
            return ['accept' => false, 'reason' => 'No booking, event, date, or attendance signal'];
        }

        return ['accept' => true, 'reason' => 'Booking inquiry signals detected'];
    }

    /** @return array<string,string> */
    private function headers(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $block = preg_split("/\n\n/", $raw, 2)[0] ?? '';
        $block = (string) preg_replace("/\n[ \t]+/", ' ', $block);
        $headers = [];
        foreach (explode("\n", $block) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}
