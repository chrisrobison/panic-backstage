<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * A push send failed in a way the CALLER should not swallow.
 *
 * Thrown only for transient (rate limit / FCM outage) and configuration
 * (bad credentials, wrong project) failures, so the job fails and the
 * existing JobQueue backoff decides when to try again. A dead or malformed
 * registration token is NOT an exception — that is a normal, expected
 * outcome handled by disabling the subscription (see FcmClient::OUTCOME_DROP).
 *
 * Messages are sanitized by construction: FcmClient never puts a registration
 * token, OAuth access token, or private key into one, because JobQueue::fail()
 * persists getMessage() into background_jobs.last_error and JobWorker
 * error_log()s it.
 */
final class FcmException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
