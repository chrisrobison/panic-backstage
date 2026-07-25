<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;
use Panic\LeadEmailParser;

/**
 * Decides whether an inbound booking email is obviously a continuation of an
 * existing lead's conversation, so scripts/ingest-booking-email.php can fold
 * it into that lead (a new lead_messages row) instead of creating a second
 * `leads` row for what's really one back-and-forth inquiry.
 *
 * Two signals are tried, most-trustworthy first:
 *
 *   1. Threading headers. The inbound message's In-Reply-To/References ids
 *      are checked against lead_messages.external_message_id — populated for
 *      every inbound email (its own Message-ID) and, since this feature
 *      landed, every outbound send too (Mailer::generateMessageId(), see
 *      LeadsInbox.php and Leads\Acknowledgment). A hit here is exact: mail
 *      clients don't fabricate these ids.
 *
 *   2. Subject + sender fallback, for the cases headers miss — a webmail
 *      client that drops References on forward, or a customer who starts a
 *      fresh compose instead of hitting reply. When the normalized subject
 *      (Re:/Fwd:/Fw:/Aw: prefixes stripped, see
 *      LeadEmailParser::normalizeSubject()) matches a prior message from the
 *      same contact_email within the lookback window, treat it as the same
 *      thread.
 *
 * Read-only: no I/O beyond SELECTs. Callers decide what to do with the
 * result (attach vs. create a new lead).
 */
final class ThreadMatcher
{
    /** How far back the subject+sender fallback looks for a prior message. */
    private const SUBJECT_LOOKBACK_DAYS = 180;

    /** How many recent same-sender messages the subject fallback compares against. */
    private const SUBJECT_CANDIDATE_LIMIT = 50;

    /**
     * @param list<string> $referenceIds Message-IDs (no angle brackets) from
     *   the inbound email's In-Reply-To + References headers, in any order.
     * @return int|null The existing lead_id this email belongs to, or null
     *   when nothing matched and the caller should create a new lead.
     */
    public function findLeadId(Database $db, array $referenceIds, ?string $contactEmail, ?string $subject): ?int
    {
        $byHeaders = $this->matchByHeaders($db, $referenceIds);
        if ($byHeaders !== null) {
            return $byHeaders;
        }
        return $this->matchBySubject($db, $contactEmail, $subject);
    }

    private function matchByHeaders(Database $db, array $referenceIds): ?int
    {
        $ids = array_values(array_unique(array_filter(
            $referenceIds,
            static fn($id): bool => trim((string) $id) !== ''
        )));
        if ($ids === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $row = $db->one(
            "SELECT lead_id FROM lead_messages
             WHERE external_message_id IN ($placeholders)
             ORDER BY id DESC LIMIT 1",
            $ids
        );
        return $row !== null ? (int) $row['lead_id'] : null;
    }

    private function matchBySubject(Database $db, ?string $contactEmail, ?string $subject): ?int
    {
        $email = strtolower(trim((string) $contactEmail));
        $normalized = LeadEmailParser::normalizeSubject($subject);
        if ($email === '' || $normalized === '') {
            return null;
        }

        $candidates = $db->all(
            "SELECT m.lead_id, m.subject
             FROM lead_messages m
             JOIN leads l ON l.id = m.lead_id
             WHERE l.contact_email = ?
               AND m.subject IS NOT NULL
               AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY m.id DESC
             LIMIT ?",
            [$email, self::SUBJECT_LOOKBACK_DAYS, self::SUBJECT_CANDIDATE_LIMIT]
        );

        foreach ($candidates as $row) {
            if (LeadEmailParser::normalizeSubject((string) $row['subject']) === $normalized) {
                return (int) $row['lead_id'];
            }
        }
        return null;
    }
}
