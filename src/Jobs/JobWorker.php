<?php
declare(strict_types=1);

namespace Panic\Jobs;

use Panic\Database;
use Panic\Leads\PublicInquiryFollowup;
use Panic\Notifications\PushNotifier;
use Panic\Opportunities\Research\Runner as OpportunityResearchRunner;

final class JobWorker
{
    private readonly JobQueue $queue;

    public function __construct(
        private readonly Database $db,
        private readonly string $root,
        private readonly string $workerId
    ) {
        $this->queue = new JobQueue($db);
    }

    /**
     * Drain up to $limit ready jobs and return processing counts.
     *
     * @return array{completed:int,failed:int}
     */
    public function run(int $limit = 25): array
    {
        $counts = ['completed' => 0, 'failed' => 0];
        for ($i = 0; $i < max(1, $limit); $i++) {
            $job = $this->queue->reserve($this->workerId);
            if ($job === null) {
                break;
            }
            try {
                $this->dispatch($job);
                $this->queue->complete((int) $job['id'], $this->workerId);
                $counts['completed']++;
            } catch (\Throwable $error) {
                $this->queue->fail($job, $this->workerId, $error);
                error_log(sprintf(
                    'background job #%d (%s) failed: %s',
                    $job['id'],
                    $job['job_type'],
                    $error->getMessage()
                ));
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $job */
    private function dispatch(array $job): void
    {
        $payload = $job['payload'];
        $followup = new PublicInquiryFollowup($this->root);

        match ((string) $job['job_type']) {
            'public_inquiry_followup' => $followup->classifyRouteAndAcknowledge(
                $this->db,
                (int) ($payload['lead_id'] ?? 0),
                (int) ($payload['message_id'] ?? 0)
            ),
            'public_inquiry_admin_notification' => $followup->notifyAdmins(
                $this->db,
                (int) ($payload['lead_id'] ?? 0)
            ),
            // Firebase web push. Fans one queued message out to every enabled
            // device belonging to the recipients; dead registrations disable
            // themselves, transient FCM failures rethrow so the queue's
            // existing backoff owns the retry.
            PushNotifier::JOB_TYPE => (new PushNotifier())->deliver($this->db, $payload),
            // Opportunities AI/web research (Phase 7) — see
            // src/Opportunities/Research/Runner.php's docblock. The actual
            // `claude` CLI call happens here, on the worker, never on a
            // public request thread.
            'opportunity_research' => OpportunityResearchRunner::run(
                $this->db,
                (int) ($payload['research_job_id'] ?? 0)
            ),
            default => throw new \RuntimeException('Unknown background job type: ' . $job['job_type']),
        };
    }
}
