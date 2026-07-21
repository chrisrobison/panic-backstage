<?php
declare(strict_types=1);

namespace Panic\Processes\Runtime;

/** A user-facing runtime error (bad instance id, wrong state for the
 *  requested action, etc.) — caught at the API boundary and turned into a
 *  4xx JSON response rather than a 500. */
final class EngineException extends \RuntimeException
{
}
