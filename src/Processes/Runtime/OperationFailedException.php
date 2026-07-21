<?php
declare(strict_types=1);

namespace Panic\Processes\Runtime;

/** Thrown by the simulated operation handler when a node's
 *  config.simulateFailure is set — lets the engine's real failure/retry
 *  path be exercised without a real flaky integration. */
final class OperationFailedException extends \RuntimeException
{
}
