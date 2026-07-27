<?php
declare(strict_types=1);

namespace Panic\Jobs;

use Panic\Database;

/**
 * Small database-backed, at-least-once job queue.
 *
 * Enqueue may participate in an existing transaction. Reservation uses a
 * row lock with SKIP LOCKED so multiple workers can safely drain the same
 * tenant without performing the same job concurrently.
 */
final class JobQueue
{
    public const DEFAULT_MAX_ATTEMPTS = 5;
    private const STALE_AFTER_MINUTES = 15;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(
        string $jobType,
        array $payload,
        ?string $uniqueKey = null,
        string $queue = 'default',
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): int {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $maxAttempts = max(1, min(25, $maxAttempts));

        $this->db->run(
            'INSERT INTO background_jobs (queue, job_type, payload_json, unique_key, max_attempts)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$queue, $jobType, $json, $uniqueKey, $maxAttempts]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function reserve(string $workerId, string $queue = 'default'): ?array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // A worker killed mid-job does not strand the row forever.
            $this->db->run(
                "UPDATE background_jobs
                 SET status = 'pending', locked_at = NULL, locked_by = NULL,
                     available_at = NOW()
                 WHERE status = 'processing'
                   AND locked_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_AFTER_MINUTES . ' MINUTE)'
            );

            $job = $this->db->one(
                "SELECT * FROM background_jobs
                 WHERE queue = ? AND status = 'pending' AND available_at <= NOW()
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED",
                [$queue]
            );
            if ($job === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }

            $claimed = $this->db->run(
                "UPDATE background_jobs
                 SET status = 'processing', attempts = attempts + 1,
                     locked_at = NOW(), locked_by = ?, last_error = NULL
                 WHERE id = ? AND status = 'pending'",
                [$workerId, $job['id']]
            );
            if ($claimed !== 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            $job['attempts'] = (int) $job['attempts'] + 1;
            $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('Job payload must decode to an object.');
            }
            $job['payload'] = $payload;
            return $job;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function complete(int $jobId, string $workerId): void
    {
        $this->db->run(
            "UPDATE background_jobs
             SET status = 'completed', completed_at = NOW(),
                 locked_at = NULL, locked_by = NULL, last_error = NULL
             WHERE id = ? AND status = 'processing' AND locked_by = ?",
            [$jobId, $workerId]
        );
    }

    public function fail(array $job, string $workerId, \Throwable $error): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = (int) ($job['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);
        $terminal = $attempts >= $maxAttempts;
        $message = mb_substr($error::class . ': ' . $error->getMessage(), 0, 2000);

        $this->db->run(
            "UPDATE background_jobs
             SET status = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 locked_at = NULL, locked_by = NULL, last_error = ?
             WHERE id = ? AND status = 'processing' AND locked_by = ?",
            [
                $terminal ? 'failed' : 'pending',
                $terminal ? 0 : self::retryDelaySeconds($attempts),
                $message,
                $job['id'],
                $workerId,
            ]
        );
    }

    /** Pure helper kept public for hermetic queue-policy tests. */
    public static function retryDelaySeconds(int $attempt): int
    {
        $attempt = max(1, min(10, $attempt));
        return min(3600, 30 * (2 ** ($attempt - 1)));
    }
}
