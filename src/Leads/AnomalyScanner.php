<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;

/**
 * Rule-based, informational anomaly detection for the Booking Inbox — the
 * spec is explicit that these "should inform managers without automatically
 * accusing users of misconduct," so every alert here is phrased as an
 * observation ("higher than the team average"), never a verdict, and
 * nothing here takes any action on its own (no auto-suspension, no auto-
 * reassignment) — a venue_admin reads these and decides what, if anything,
 * to do. Deliberately simple threshold rules over real data (lead_audit_log,
 * lead_claims, leads) rather than a statistical/ML model — explainable in
 * one sitting, matching src/Leads/Classifier.php's scoring philosophy.
 */
final class AnomalyScanner
{
    private const LOOKBACK_DAYS = 30;

    /** @return list<array{severity:string,message:string}> */
    public static function scan(Database $db): array
    {
        $alerts = [];
        $since = date('Y-m-d H:i:s', strtotime('-' . self::LOOKBACK_DAYS . ' days'));

        // Decline/archive rate per booker vs. the team average, over leads
        // they own. A single very-active booker or a genuinely bad run of
        // inquiries can trigger this — it's a prompt to look, not a verdict.
        $perBooker = $db->all(
            "SELECT u.id, u.name,
                COUNT(*) owned,
                SUM(l.status IN ('declined','lost')) declined,
                SUM(l.status = 'archived') archived
             FROM leads l JOIN users u ON u.id = l.owner_user_id
             WHERE l.updated_at >= ?
             GROUP BY u.id, u.name HAVING owned >= 5",
            [$since]
        );
        if (count($perBooker) >= 2) {
            $avgDeclineRate = array_sum(array_map(static fn($r) => $r['owned'] > 0 ? $r['declined'] / $r['owned'] : 0, $perBooker)) / count($perBooker);
            foreach ($perBooker as $r) {
                $rate = $r['owned'] > 0 ? $r['declined'] / $r['owned'] : 0;
                if ($rate > 0 && $rate >= $avgDeclineRate * 2 && $rate >= 0.4) {
                    $alerts[] = ['severity' => 'info', 'message' => sprintf(
                        '%s declined/lost %d of %d owned inquiries (%.0f%%) in the last %d days — well above the %.0f%% team average. Worth a quick look, not necessarily a problem.',
                        $r['name'], $r['declined'], $r['owned'], $rate * 100, self::LOOKBACK_DAYS, $avgDeclineRate * 100
                    )];
                }
            }
        }

        // Repeated expired claims by the same user — could mean an
        // overloaded booker, an SLA set too aggressively, or genuine
        // disengagement; the alert doesn't guess which.
        $expiredByUser = $db->all(
            "SELECT u.id, u.name, COUNT(*) n FROM lead_claims c
             JOIN users u ON u.id = c.claimed_by_user_id
             WHERE c.status = 'expired' AND c.claimed_at >= ?
             GROUP BY u.id, u.name HAVING n >= 3 ORDER BY n DESC",
            [$since]
        );
        foreach ($expiredByUser as $r) {
            $alerts[] = ['severity' => 'warning', 'message' => sprintf(
                '%s has had %d claimed inquiries expire without a response in the last %d days.',
                $r['name'], $r['n'], self::LOOKBACK_DAYS
            )];
        }

        // Repeated reassignment of the same inquiry — could indicate
        // thrashing/disagreement about ownership.
        $reassignedOften = $db->all(
            "SELECT lead_id, COUNT(*) n FROM lead_audit_log
             WHERE action = 'reassigned' AND created_at >= ?
             GROUP BY lead_id HAVING n >= 3",
            [$since]
        );
        foreach ($reassignedOften as $r) {
            $alerts[] = ['severity' => 'info', 'message' => sprintf(
                'Inquiry #%d has been reassigned %d times in the last %d days.',
                $r['lead_id'], $r['n'], self::LOOKBACK_DAYS
            )];
        }

        // Large export attempts.
        $bigExports = $db->all(
            "SELECT user_id, action, details_json, created_at FROM lead_audit_log
             WHERE action = 'export' AND created_at >= ? ORDER BY created_at DESC LIMIT 5",
            [$since]
        );
        foreach ($bigExports as $r) {
            $detail = json_decode((string) $r['details_json'], true) ?: [];
            $count = (int) ($detail['count'] ?? 0);
            if ($count >= 50) {
                $alerts[] = ['severity' => 'warning', 'message' => sprintf(
                    'A %d-row export was run on %s — confirm this was expected.',
                    $count, $r['created_at']
                )];
            }
        }

        // Low conversion vs. assigned volume — a booker with a healthy
        // number of assignments but few onboards, relative to the team.
        $perAssignee = $db->all(
            "SELECT u.id, u.name, COUNT(*) assigned, SUM(l.status = 'onboarded') onboarded
             FROM leads l JOIN users u ON u.id = l.assigned_to_user_id
             WHERE l.assigned_at >= ?
             GROUP BY u.id, u.name HAVING assigned >= 8",
            [$since]
        );
        if (count($perAssignee) >= 2) {
            $avgConversion = array_sum(array_map(static fn($r) => $r['assigned'] > 0 ? $r['onboarded'] / $r['assigned'] : 0, $perAssignee)) / count($perAssignee);
            foreach ($perAssignee as $r) {
                $conv = $r['assigned'] > 0 ? $r['onboarded'] / $r['assigned'] : 0;
                if ($avgConversion > 0 && $conv <= $avgConversion * 0.4) {
                    $alerts[] = ['severity' => 'info', 'message' => sprintf(
                        '%s converted %d of %d assigned inquiries (%.0f%%) — below the %.0f%% team average.',
                        $r['name'], $r['onboarded'], $r['assigned'], $conv * 100, $avgConversion * 100
                    )];
                }
            }
        }

        return $alerts;
    }
}
