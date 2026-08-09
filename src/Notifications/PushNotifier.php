<?php
declare(strict_types=1);

namespace Panic\Notifications;

use Panic\Database;
use Panic\Jobs\JobQueue;

/**
 * The application-facing half of push notifications.
 *
 * Business code calls one of the notify*() methods with a PushMessage and a
 * candidate recipient set. This class decides who actually gets it (push
 * preference + at least one registered device), then hands the work to the
 * existing JobQueue. Nothing here talks to Firebase — that happens later, on
 * the worker thread, in deliver().
 *
 *   business event → PushNotifier → JobQueue → JobWorker → FcmClient → FCM
 *
 * Two properties make it safe to call from anywhere, including hot request
 * paths on installs that have never heard of Firebase:
 *
 *   - it NEVER performs network I/O on the calling thread;
 *   - it silently no-ops when push is unconfigured, so an existing code path
 *     that gains a notify() call gains no new failure mode.
 *
 * Adding a category later (task assignments, day-of-show changes, unresolved
 * blockers) means adding a PushPreferences key and a caller — not touching
 * the transport.
 */
final class PushNotifier
{
    public const JOB_TYPE = 'push_notification';

    private readonly PushConfig $config;

    public function __construct(?PushConfig $config = null)
    {
        $this->config = $config ?? PushConfig::fromEnvironment();
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    /**
     * Queue a notification for a specific set of users.
     *
     * @param list<int>   $userIds      Candidate recipients (server-derived).
     * @param int|null    $excludeUserId Actor to omit — you do not need a phone
     *                                   alert about the thing you just did.
     * @param string|null $uniqueKey    JobQueue dedupe key. Careful: keys are
     *                                  unique FOREVER (completed rows stay in
     *                                  background_jobs), so it must include
     *                                  something that varies per occurrence —
     *                                  never a bare entity id, or the second
     *                                  notification about that entity is
     *                                  silently swallowed.
     * @return int The job id, or 0 when nothing was queued.
     */
    public function notifyUsers(
        Database $db,
        array $userIds,
        PushMessage $message,
        ?int $excludeUserId = null,
        ?string $uniqueKey = null
    ): int {
        if (!$this->config->enabled) {
            return 0;
        }

        $recipients = $this->eligibleRecipients($db, $userIds, $message->category, $excludeUserId);
        if ($recipients === []) {
            return 0;
        }

        return (new JobQueue($db))->enqueue(
            self::JOB_TYPE,
            ['user_ids' => $recipients, 'message' => $message->toArray()],
            $uniqueKey
        );
    }

    /**
     * Queue a notification for the venue's administrators — the recipient set
     * the existing admin *email* notifications already use (see
     * PublicInquiryFollowup::notifyAdmins and
     * ContractSigningEndpoint::notifyAdmins), so push reaches the same desks
     * without inventing a parallel notion of "who is on duty".
     */
    public function notifyVenueAdmins(
        Database $db,
        PushMessage $message,
        ?int $excludeUserId = null,
        ?string $uniqueKey = null
    ): int {
        if (!$this->config->enabled) {
            return 0;
        }

        return $this->notifyUsers($db, self::venueAdminIds($db), $message, $excludeUserId, $uniqueKey);
    }

    /**
     * The venue's active administrators — the same population the existing
     * admin *email* notifications address.
     *
     * @return list<int>
     */
    public static function venueAdminIds(Database $db): array
    {
        $rows = $db->all(
            "SELECT id FROM users WHERE role = 'venue_admin' AND is_hidden = 0 AND access_status = 'active'"
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Narrow a candidate list to users who both opted into this category and
     * have at least one enabled device.
     *
     * Done here, at enqueue time, so we never queue a job for nobody; it is
     * re-checked in deliver() because preferences can change between queuing
     * and sending.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    private function eligibleRecipients(
        Database $db,
        array $userIds,
        string $category,
        ?int $excludeUserId
    ): array {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($excludeUserId !== null) {
            $userIds = array_values(array_filter($userIds, static fn (int $id): bool => $id !== $excludeUserId));
        }
        if ($userIds === [] || !PushPreferences::isKey($category)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        // The column name is interpolated, but only ever from the
        // PushPreferences allow-list checked immediately above.
        $rows = $db->all(
            "SELECT DISTINCT u.id
             FROM users u
             JOIN push_subscriptions ps ON ps.user_id = u.id AND ps.enabled = 1
             WHERE u.id IN ({$placeholders}) AND u.{$category} = 1",
            $userIds
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    // ── Worker side ──────────────────────────────────────────────────────────

    /**
     * Deliver a queued notification. Called only by JobWorker.
     *
     * Failure handling is deliberately per-device:
     *   - a dead registration disables that ONE row and does not fail the job
     *     (retrying it forever would never succeed);
     *   - a transient/config failure throws, so the whole job goes back to the
     *     queue under the existing backoff policy.
     *
     * A retry re-sends to devices that already succeeded. That is inherent to
     * an at-least-once queue; the message's dedupe key is passed to FCM as a
     * collapse topic and to the browser as a notification tag, so a duplicate
     * replaces the earlier notification rather than stacking on it.
     *
     * @param array<string,mixed> $payload
     */
    public function deliver(Database $db, array $payload): void
    {
        if (!$this->config->canSend()) {
            // Push was switched off (or the key file moved) after this job was
            // queued. Dropping it is correct: an operational alert delivered
            // days late is worse than not delivered.
            error_log('push_notification job skipped: Firebase push is not configured for sending.');
            return;
        }

        $message = PushMessage::fromArray((array) ($payload['message'] ?? []));
        $userIds = array_map('intval', (array) ($payload['user_ids'] ?? []));

        // Re-check preferences at delivery time — someone may have opted out
        // in the seconds since this was queued.
        $recipients = $this->eligibleRecipients($db, $userIds, $message->category, null);
        if ($recipients === []) {
            return;
        }

        $client = new FcmClient($this->config);
        $deferred = null;

        foreach (PushSubscriptions::enabledForUsers($db, $recipients) as $subscription) {
            try {
                $result = $client->send($subscription['token'], $message);
            } catch (FcmException $error) {
                // Keep going: one rate-limited or misconfigured send should
                // not stop the other devices from being tried this pass.
                $deferred ??= $error;
                continue;
            }

            if ($result['outcome'] === FcmClient::OUTCOME_DROP) {
                PushSubscriptions::disable($db, $subscription['id'], (string) $result['error']);
                continue;
            }
            PushSubscriptions::markSuccess($db, $subscription['id']);
        }

        if ($deferred !== null) {
            throw $deferred;
        }
    }
}
