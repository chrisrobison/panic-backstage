<?php
declare(strict_types=1);

namespace Panic\Processes\Runtime;

use Panic\Database;

/**
 * A generic, non-CenterStage-specific lookup table from a node's
 * config.operation string (e.g. "events.set_status") to a real handler
 * callable. Engine.php only knows about this interface — it has zero
 * knowledge of what "events.set_status" means. The actual CenterStage
 * handlers (real DB writes, real contract creation, etc.) are registered
 * from outside, in src/Processes/CenterStage/BookingHandlers.php, and
 * wired in wherever the app constructs an Engine (Processes/Instances.php,
 * Processes/Tasks.php, scripts/process-tick.php) — exactly the "provide
 * CenterStage-aware nodes through a registry, don't hard-code venue-
 * specific behavior into the generic graph engine" requirement.
 *
 * A node whose config.operation has no registered handler (or has none at
 * all) falls back to Engine's existing generic simulated handler — nothing
 * breaks and nothing is faked as real; it just isn't wired to anything yet.
 *
 * Handler signature: fn(Database $db, array $instance, array $node, array &$variables): array
 *   - $instance is the process_instances row (use entity_type/entity_id to
 *     find the real CenterStage record this case is about, owner_user_id
 *     for created_by attribution on writes).
 *   - $node is the graph node (id/type/name/config).
 *   - $variables is the instance's variable bag, passed by reference so a
 *     handler can record findings (e.g. date_available) the same way
 *     config.setVariables does for the simulated path.
 *   - Return value becomes the execution's output_json. Throw
 *     OperationFailedException to fail the instance (same as a simulated
 *     failure) — a real handler's exception is treated identically.
 */
final class HandlerRegistry
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function register(string $operationId, callable $handler): void
    {
        $this->handlers[$operationId] = $handler;
    }

    public function has(string $operationId): bool
    {
        return isset($this->handlers[$operationId]);
    }

    public function get(string $operationId): callable
    {
        return $this->handlers[$operationId];
    }
}
