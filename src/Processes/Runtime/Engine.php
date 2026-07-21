<?php
declare(strict_types=1);

namespace Panic\Processes\Runtime;

use Panic\Database;
use function Panic\log_process_audit;

/**
 * The process runtime — Phase 2. Everything in Phase 1 (src/Processes.php,
 * Processes/Versions.php, Processes/Instances.php) is design-time: it reads
 * and writes graph documents but never runs one. This class is the actual
 * state machine that walks a process_instances row through its bound
 * process_versions.graph_json, exactly as described in the spec's "Runtime
 * requirements" section:
 *
 *   - database-backed, resumable state (nothing lives only in a PHP
 *     process/browser tab — every stop point is a row in process_tasks or
 *     process_waits)
 *   - idempotent resume: completing a task or resuming a wait is a
 *     conditional `WHERE status = 'open'/'waiting'` UPDATE, so a duplicate
 *     webhook delivery or double-submit is a silent no-op, not a duplicate
 *     side effect
 *   - transactional claiming: advance() holds the instance row locked
 *     (`SELECT ... FOR UPDATE` inside one transaction) for the whole burst
 *     of automatic steps it runs, so two overlapping calls (a request and a
 *     cron tick, say) can't interleave
 *   - a bounded step count per burst, so a genuine "loop without exit" in a
 *     graph fails loudly instead of hanging a request forever
 *
 * Honest scope note (do not read more into this than is here): the ops this
 * engine can execute automatically (op.* / ai.* node types) run through a
 * single generic SIMULATED handler — see runSimulatedOperation() below. It
 * updates instance variables from the node's own config (a generic,
 * non-CenterStage-specific mechanism: `config.setVariables`) and logs a
 * clearly-labeled simulated execution, but never sends a real email, calls a
 * real payment API, or writes to an unrelated CenterStage table. Wiring real
 * per-operation handlers (send an actual email, actually create the Event
 * row, etc.) through the same node-type registry is Phase 3. What IS fully
 * real here: the state machine itself, decision branching, human tasks,
 * waits/timeouts, retries, cancellation, pause/resume, and the audit trail.
 */
final class Engine
{
    /** Safety cap on automatic (non-stopping) steps per advance() burst. */
    private const MAX_STEPS = 200;

    public function __construct(private Database $db)
    {
    }

    // ── Starting an instance ─────────────────────────────────────────────

    /**
     * @param array $definition process_definitions row
     * @param array $version    process_versions row (must be published or a
     *                          draft explicitly opted into for testing)
     * @param array $opts       name, variables, entity_type, entity_id,
     *                          owner_user_id, actor
     */
    public function startInstance(array $definition, array $version, array $opts = []): array
    {
        $graph = $this->decodeGraph($version);
        $trigger = $this->findTriggerNode($graph);
        if (!$trigger) {
            throw new EngineException('This process has no trigger node to start from.');
        }

        $variables = is_array($opts['variables'] ?? null) ? $opts['variables'] : [];
        $name = trim((string) ($opts['name'] ?? ''));
        if ($name === '') {
            $name = $definition['name'] . ' — ' . date('M j, Y g:ia');
        }

        $instanceId = $this->db->insert(
            'INSERT INTO process_instances
                (process_definition_id, process_version_id, name, status, current_node_id, entity_type, entity_id, owner_user_id, is_demo, variables_json, started_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
            [
                $definition['id'],
                $version['id'],
                $name,
                'active',
                $trigger['id'],
                $opts['entity_type'] ?? null,
                $opts['entity_id'] ?? null,
                $opts['owner_user_id'] ?? null,
                json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        $this->logEvent($instanceId, $trigger['id'], 'entered', 'Started: ' . $trigger['name'], null, $opts['actor'] ?? 'system');
        log_process_audit($this->db, (int) $definition['id'], (int) $version['id'], $opts['actor_user_id'] ?? null, 'instance_started', [], ['instance_id' => $instanceId, 'name' => $name]);

        $this->advance($instanceId);
        return $this->loadInstance($instanceId);
    }

    // ── The planner/executor loop ────────────────────────────────────────

    /**
     * Runs automatic nodes starting at the instance's current node until it
     * hits a human task, a wait/timer, an end, a failure, or the step cap.
     * Safe to call redundantly (e.g. after a task completion AND from a
     * stray retry click) — an instance that isn't 'active' is a no-op.
     */
    public function advance(int $instanceId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ? FOR UPDATE', [$instanceId]);
            if (!$instance) {
                $pdo->rollBack();
                throw new EngineException('Instance not found');
            }
            if ($instance['status'] !== 'active') {
                // Waiting on a task/wait, already terminal, paused, etc. — nothing to do.
                $pdo->commit();
                return $this->loadInstance($instanceId);
            }

            $version = $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
            $graph = $this->decodeGraph($version);
            $variables = $this->decodeVariables($instance);
            $nodeId = $instance['current_node_id'];

            for ($step = 0; $step < self::MAX_STEPS; $step++) {
                $node = $this->findNode($graph, $nodeId);
                if (!$node) {
                    $this->failInTx($instanceId, $nodeId, "Current node \"$nodeId\" no longer exists in this process version.");
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                $type = (string) $node['type'];

                if ($type === 'flow.end') {
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', false);
                    $this->logEvent($instanceId, $node['id'], 'completed', 'Reached end: ' . $node['name'], null, 'system');
                    $this->completeInTx($instanceId, $node['id'], $variables);
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                if ($type === 'flow.failure_end') {
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', false);
                    $this->logEvent($instanceId, $node['id'], 'failed', 'Reached failure end: ' . $node['name'], null, 'system');
                    $this->failInTx($instanceId, $node['id'], 'Process reached a designed failure end: ' . $node['name'], $variables);
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                if ($type === 'flow.decision' || $type === 'ai.decision') {
                    $outcome = $this->evaluateDecision($node, $variables);
                    $edge = $this->pickEdge($this->outgoingEdges($graph, $node['id']), $outcome);
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', false, ['outcome' => $outcome]);
                    $this->logEvent($instanceId, $node['id'], 'completed', $node['name'] . ' → ' . $outcome, null, 'system');
                    if (!$edge) {
                        $this->failInTx($instanceId, $node['id'], 'Decision "' . $node['name'] . '" has no branch for outcome "' . $outcome . '" and no default.', $variables);
                        $pdo->commit();
                        return $this->loadInstance($instanceId);
                    }
                    $nodeId = $edge['target']['nodeId'];
                    continue;
                }

                if ($type === 'flow.wait' || $type === 'flow.timer' || $type === 'flow.delay') {
                    $this->createWait($instanceId, $node);
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', false);
                    $this->logEvent($instanceId, $node['id'], 'waiting', 'Waiting at: ' . $node['name'], null, 'system');
                    $this->setInstanceState($instanceId, 'waiting', $node['id'], $variables);
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                if (str_starts_with($type, 'human.')) {
                    $this->createTask($instanceId, $node);
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', false);
                    $this->logEvent($instanceId, $node['id'], 'waiting', 'Assigned: ' . $node['name'], null, 'system');
                    $this->setInstanceState($instanceId, 'waiting', $node['id'], $variables);
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                if (in_array($type, ['flow.parallel_split', 'flow.join', 'flow.subprocess'], true)) {
                    // Phase 2 simplification, documented rather than faked: the
                    // engine models a single "current node" per instance, so it
                    // cannot truly run branches concurrently or step into a
                    // nested subprocess graph yet. It takes the first outgoing
                    // edge and logs exactly that, instead of silently pretending
                    // to fan out/in.
                    $edges = $this->outgoingEdges($graph, $node['id']);
                    $edge = $edges[0] ?? null;
                    $this->recordExecution($instanceId, $node, 1, 'succeeded', true, ['note' => 'Simplified: parallel/subprocess execution is not modeled by Phase 2 — first branch taken.']);
                    $this->logEvent($instanceId, $node['id'], 'completed', $node['name'] . ' (simplified pass-through)', 'Parallel/subprocess execution is not yet modeled by the runtime.', 'system');
                    if (!$edge) {
                        $this->failInTx($instanceId, $node['id'], 'Node "' . $node['name'] . '" has no outgoing path.', $variables);
                        $pdo->commit();
                        return $this->loadInstance($instanceId);
                    }
                    $nodeId = $edge['target']['nodeId'];
                    continue;
                }

                // Everything else (trigger.*, op.*, ai.* non-decision): either
                // a real, side-effect-free trigger pass-through, or the
                // generic simulated operation handler.
                try {
                    if (str_starts_with($type, 'trigger.')) {
                        $output = ['note' => 'Trigger already fired at instance start.'];
                        $simulated = false;
                    } else {
                        $output = $this->runSimulatedOperation($node, $variables);
                        $simulated = true;
                    }
                } catch (OperationFailedException $e) {
                    $this->recordExecution($instanceId, $node, 1, 'failed', true, null, $e->getMessage());
                    $this->logEvent($instanceId, $node['id'], 'failed', $node['name'] . ' failed: ' . $e->getMessage(), null, 'system');
                    $this->setInstanceState($instanceId, 'failed', $node['id'], $variables, $e->getMessage());
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }

                $this->recordExecution($instanceId, $node, 1, 'succeeded', $simulated, $output);
                $this->logEvent($instanceId, $node['id'], 'completed', $node['name'] . ' completed', $simulated ? ($output['note'] ?? null) : null, 'system');

                $edge = $this->pickEdge($this->outgoingEdges($graph, $node['id']), null);
                if (!$edge) {
                    // No explicit end node reached, but nowhere left to go —
                    // treat as a clean completion rather than hanging.
                    $this->completeInTx($instanceId, $node['id'], $variables);
                    $pdo->commit();
                    return $this->loadInstance($instanceId);
                }
                $nodeId = $edge['target']['nodeId'];
            }

            // Exhausted the step cap without stopping — almost certainly a
            // loop without an exit condition (GraphValidator warns about
            // this at publish time, but can't prove it never happens at
            // runtime for every graph).
            $this->failInTx($instanceId, $nodeId, 'Exceeded ' . self::MAX_STEPS . ' automatic steps without reaching a stop point — likely a loop without an exit condition.', $variables);
            $pdo->commit();
            return $this->loadInstance($instanceId);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── Resuming from a stop point ───────────────────────────────────────

    /** Complete a human task. Idempotent: a second call on an
     *  already-completed task is a harmless no-op (returns ok=true, already=true). */
    public function completeTask(int $instanceId, int $taskId, string $outcome, ?string $note, ?int $actorUserId, string $actorLabel = 'system'): array
    {
        $affected = $this->db->run(
            "UPDATE process_tasks SET status = 'completed', outcome = ?, completed_at = NOW(), completed_by = ? WHERE id = ? AND process_instance_id = ? AND status = 'open'",
            [$outcome, $actorUserId, $taskId, $instanceId]
        );
        if ($affected === 0) {
            return ['ok' => true, 'already' => true];
        }

        $task = $this->db->one('SELECT * FROM process_tasks WHERE id = ?', [$taskId]);
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
        $graph = $this->decodeGraph($version);
        $node = $this->findNode($graph, $task['node_id']);
        $edge = $node ? $this->pickEdge($this->outgoingEdges($graph, $node['id']), $outcome) : null;

        $this->logEvent($instanceId, $task['node_id'], 'completed', ($node['name'] ?? $task['title']) . ' → ' . $outcome, $note, $actorLabel);

        if (!$edge) {
            // No outgoing edge from this task's node — GraphValidator should
            // have flagged this dead end at publish time; treat it the same
            // way the automatic-node loop treats a dead end: a clean finish,
            // not a fabricated failure.
            $variables = $this->decodeVariables($instance);
            $this->completeInTx($instanceId, $task['node_id'], $variables);
            return $this->loadInstance($instanceId);
        }

        $this->db->run("UPDATE process_instances SET status = 'active', current_node_id = ? WHERE id = ?", [$edge['target']['nodeId'], $instanceId]);
        return $this->advance($instanceId);
    }

    /** Resume a wait because its awaited event arrived. Idempotent for the
     *  same reason as completeTask(). $correlationKey, if provided, must
     *  match the wait's stored correlation key. */
    public function resumeWait(int $instanceId, int $waitId, ?string $correlationKey, ?string $note, string $actorLabel = 'system'): array
    {
        $wait = $this->db->one('SELECT * FROM process_waits WHERE id = ? AND process_instance_id = ?', [$waitId, $instanceId]);
        if (!$wait) {
            throw new EngineException('Wait not found');
        }
        if ($wait['correlation_key'] !== null && $correlationKey !== null && $wait['correlation_key'] !== $correlationKey) {
            throw new EngineException('Correlation key does not match this wait.');
        }

        $affected = $this->db->run(
            "UPDATE process_waits SET status = 'resumed', resumed_via = 'event', resumed_at = NOW() WHERE id = ? AND status = 'waiting'",
            [$waitId]
        );
        if ($affected === 0) {
            return ['ok' => true, 'already' => true];
        }

        return $this->resumeFromWaitRow($instanceId, $wait, 'resumed', $note, $actorLabel);
    }

    /** Called by the scheduled tick job when a wait's timeout has passed. */
    public function timeoutWait(int $waitId): void
    {
        $wait = $this->db->one('SELECT * FROM process_waits WHERE id = ?', [$waitId]);
        if (!$wait) {
            return;
        }
        $affected = $this->db->run(
            "UPDATE process_waits SET status = 'timed_out', resumed_via = 'timeout', resumed_at = NOW() WHERE id = ? AND status = 'waiting'",
            [$waitId]
        );
        if ($affected === 0) {
            return;
        }
        $this->resumeFromWaitRow((int) $wait['process_instance_id'], $wait, 'timeout', 'Automatic — timeout reached.', 'system (scheduled job)');
    }

    private function resumeFromWaitRow(int $instanceId, array $wait, string $outcome, ?string $note, string $actorLabel): array
    {
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
        $graph = $this->decodeGraph($version);
        $node = $this->findNode($graph, $wait['node_id']);
        $edge = $node ? $this->pickEdge($this->outgoingEdges($graph, $node['id']), $outcome) : null;

        $this->logEvent($instanceId, $wait['node_id'], $outcome === 'timeout' ? 'timeout' : 'completed', ($node['name'] ?? $wait['node_id']) . ' → ' . $outcome, $note, $actorLabel);

        if (!$edge) {
            $variables = $this->decodeVariables($instance);
            $this->completeInTx($instanceId, $wait['node_id'], $variables);
            return $this->loadInstance($instanceId);
        }

        $this->db->run("UPDATE process_instances SET status = 'active', current_node_id = ? WHERE id = ?", [$edge['target']['nodeId'], $instanceId]);
        return $this->advance($instanceId);
    }

    // ── Operator actions (require a caller-supplied audit note) ─────────

    public function retry(int $instanceId, ?int $actorUserId, string $note): array
    {
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        if (!$instance) {
            throw new EngineException('Instance not found');
        }
        if ($instance['status'] !== 'failed') {
            throw new EngineException('Only a failed instance can be retried.');
        }
        $definition = $this->db->one('SELECT id FROM process_definitions WHERE id = ?', [$instance['process_definition_id']]);

        $this->db->run("UPDATE process_instances SET status = 'active', last_error = NULL WHERE id = ?", [$instanceId]);
        $this->logEvent($instanceId, $instance['current_node_id'], 'note', 'Retried by operator', $note, 'operator');
        log_process_audit($this->db, (int) $instance['process_definition_id'], (int) $instance['process_version_id'], $actorUserId, 'instance_retried', ['status' => 'failed'], ['status' => 'active'], $note);

        return $this->advance($instanceId);
    }

    public function cancel(int $instanceId, ?int $actorUserId, string $note): array
    {
        $instance = $this->requireActionable($instanceId);
        $this->db->run("UPDATE process_instances SET status = 'canceled', updated_at = NOW() WHERE id = ?", [$instanceId]);
        $this->db->run("UPDATE process_tasks SET status = 'canceled' WHERE process_instance_id = ? AND status = 'open'", [$instanceId]);
        $this->db->run("UPDATE process_waits SET status = 'canceled' WHERE process_instance_id = ? AND status = 'waiting'", [$instanceId]);
        $this->logEvent($instanceId, $instance['current_node_id'], 'note', 'Canceled by operator', $note, 'operator');
        log_process_audit($this->db, (int) $instance['process_definition_id'], (int) $instance['process_version_id'], $actorUserId, 'instance_canceled', ['status' => $instance['status']], ['status' => 'canceled'], $note);
        return $this->loadInstance($instanceId);
    }

    public function pause(int $instanceId, ?int $actorUserId, string $note): array
    {
        $instance = $this->requireActionable($instanceId);
        if ($instance['status'] === 'paused') {
            return $this->loadInstance($instanceId);
        }
        $this->db->run("UPDATE process_instances SET status = 'paused', resume_status = ? WHERE id = ?", [$instance['status'], $instanceId]);
        $this->logEvent($instanceId, $instance['current_node_id'], 'note', 'Paused by operator', $note, 'operator');
        log_process_audit($this->db, (int) $instance['process_definition_id'], (int) $instance['process_version_id'], $actorUserId, 'instance_paused', ['status' => $instance['status']], ['status' => 'paused'], $note);
        return $this->loadInstance($instanceId);
    }

    public function resume(int $instanceId, ?int $actorUserId, string $note): array
    {
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        if (!$instance) {
            throw new EngineException('Instance not found');
        }
        if ($instance['status'] !== 'paused') {
            throw new EngineException('Instance is not paused.');
        }
        $restoreTo = $instance['resume_status'] ?: 'active';
        $this->db->run("UPDATE process_instances SET status = ?, resume_status = NULL WHERE id = ?", [$restoreTo, $instanceId]);
        $this->logEvent($instanceId, $instance['current_node_id'], 'note', 'Resumed by operator', $note, 'operator');
        log_process_audit($this->db, (int) $instance['process_definition_id'], (int) $instance['process_version_id'], $actorUserId, 'instance_resumed', ['status' => 'paused'], ['status' => $restoreTo], $note);
        if ($restoreTo === 'active') {
            return $this->advance($instanceId);
        }
        return $this->loadInstance($instanceId);
    }

    /** Move an instance to a different node by hand — an explicit,
     *  audited override for operators, not something the state machine
     *  does on its own. */
    public function moveTo(int $instanceId, string $nodeId, ?int $actorUserId, string $note): array
    {
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        if (!$instance) {
            throw new EngineException('Instance not found');
        }
        $version = $this->db->one('SELECT * FROM process_versions WHERE id = ?', [$instance['process_version_id']]);
        $graph = $this->decodeGraph($version);
        if (!$this->findNode($graph, $nodeId)) {
            throw new EngineException('Node not found in this process version.');
        }
        $before = ['current_node_id' => $instance['current_node_id'], 'status' => $instance['status']];
        $this->db->run("UPDATE process_instances SET current_node_id = ?, status = 'active' WHERE id = ?", [$nodeId, $instanceId]);
        $this->logEvent($instanceId, $nodeId, 'note', 'Moved to "' . $nodeId . '" by operator', $note, 'operator');
        log_process_audit($this->db, (int) $instance['process_definition_id'], (int) $instance['process_version_id'], $actorUserId, 'instance_moved', $before, ['current_node_id' => $nodeId], $note);
        return $this->advance($instanceId);
    }

    private function requireActionable(int $instanceId): array
    {
        $instance = $this->db->one('SELECT * FROM process_instances WHERE id = ?', [$instanceId]);
        if (!$instance) {
            throw new EngineException('Instance not found');
        }
        if (in_array($instance['status'], ['completed', 'canceled'], true)) {
            throw new EngineException('Instance is already ' . $instance['status'] . '.');
        }
        return $instance;
    }

    // ── Node execution ────────────────────────────────────────────────────

    /**
     * The one, generic, non-CenterStage-specific "operation" handler.
     * config.setVariables (an optional object on any op.* or ai.* node) is
     * merged into instance variables as DEFAULTS — anything the instance
     * already has (from the trigger or an earlier node) wins. That is what
     * lets a demo/test instance override "Check Availability"'s result by
     * starting with `variables: {date_available: 'no'}` instead of letting
     * the node's own default apply.
     *
     * config.simulateFailure (bool) exists purely so tests/operators can
     * exercise the failure + retry path without needing a real flaky
     * integration to fail on demand.
     */
    private function runSimulatedOperation(array $node, array &$variables): array
    {
        $cfg = $node['config'] ?? [];
        if (!empty($cfg['simulateFailure'])) {
            throw new OperationFailedException((string) ($cfg['failureMessage'] ?? 'Simulated failure (config.simulateFailure is set on this node, for testing).'));
        }
        $set = is_array($cfg['setVariables'] ?? null) ? $cfg['setVariables'] : [];
        if ($set) {
            $variables = array_merge($set, $variables);
        }
        return [
            'note' => 'Simulated execution — no real ' . $node['type'] . ' side effect was performed. Wiring a real handler for this operation is Phase 3.',
            'variables_after' => $variables,
        ];
    }

    private function evaluateDecision(array $node, array $variables): string
    {
        $branches = $node['config']['branches'] ?? [];
        $variableKey = $node['config']['variableKey'] ?? null;
        if ($variableKey && array_key_exists($variableKey, $variables)) {
            $value = (string) $variables[$variableKey];
            foreach ($branches as $b) {
                if ((string) ($b['id'] ?? '') === $value) {
                    return (string) $b['id'];
                }
            }
        }
        foreach ($branches as $b) {
            if (!empty($b['isDefault'])) {
                return (string) $b['id'];
            }
        }
        return (string) ($branches[0]['id'] ?? 'default');
    }

    private function createTask(int $instanceId, array $node): int
    {
        $cfg = $node['config'] ?? [];
        return $this->db->insert(
            'INSERT INTO process_tasks (process_instance_id, node_id, title, description, assignee_user_id, assignee_role, due_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "open", NOW())',
            [
                $instanceId,
                $node['id'],
                $node['name'],
                $cfg['instructions'] ?? null,
                is_numeric($cfg['assignee'] ?? null) ? (int) $cfg['assignee'] : null,
                $cfg['assigneeRole'] ?? null,
                $this->parseRelativeDue($cfg['dueRule'] ?? null),
            ]
        );
    }

    private function createWait(int $instanceId, array $node): int
    {
        $cfg = $node['config'] ?? [];
        return $this->db->insert(
            'INSERT INTO process_waits (process_instance_id, node_id, awaited_event, correlation_key, timeout_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, "waiting", NOW())',
            [
                $instanceId,
                $node['id'],
                $cfg['awaitedEvent'] ?? null,
                $cfg['correlationKey'] ?? null,
                $this->parseRelativeDue($cfg['duration'] ?? null),
            ]
        );
    }

    private function parseRelativeDue(?string $rule): ?string
    {
        if (!$rule) {
            return null;
        }
        $ts = strtotime('+' . ltrim($rule));
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    // ── Instance state transitions ───────────────────────────────────────

    private function setInstanceState(int $instanceId, string $status, string $nodeId, array $variables, ?string $error = null): void
    {
        $this->db->run(
            'UPDATE process_instances SET status = ?, current_node_id = ?, variables_json = ?, last_error = ?, updated_at = NOW() WHERE id = ?',
            [$status, $nodeId, json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $error, $instanceId]
        );
    }

    private function failInTx(int $instanceId, string $nodeId, string $message, array $variables = []): void
    {
        $this->setInstanceState($instanceId, 'failed', $nodeId, $variables, $message);
    }

    private function completeInTx(int $instanceId, string $nodeId, array $variables): void
    {
        $this->db->run(
            'UPDATE process_instances SET status = "completed", current_node_id = ?, variables_json = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$nodeId, json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $instanceId]
        );
    }

    private function recordExecution(int $instanceId, array $node, int $attempt, string $status, bool $simulated, ?array $output = null, ?string $error = null): void
    {
        $this->db->insert(
            'INSERT INTO process_executions (process_instance_id, node_id, node_type, attempt, status, simulated, output_json, error_text, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$instanceId, $node['id'], $node['type'], $attempt, $status, $simulated ? 1 : 0, $output ? json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $error]
        );
    }

    private function logEvent(int $instanceId, ?string $nodeId, string $eventType, string $label, ?string $detail, string $actor): void
    {
        $this->db->run(
            'INSERT INTO process_instance_events (process_instance_id, node_id, event_type, label, detail, actor, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$instanceId, $nodeId, $eventType, $label, $detail, $actor]
        );
    }

    // ── Graph helpers ─────────────────────────────────────────────────────

    private function decodeGraph(?array $version): array
    {
        $graph = $version ? json_decode((string) $version['graph_json'], true) : null;
        return is_array($graph) ? $graph : ['nodes' => [], 'edges' => []];
    }

    private function decodeVariables(array $instance): array
    {
        $variables = $instance['variables_json'] ? json_decode((string) $instance['variables_json'], true) : null;
        return is_array($variables) ? $variables : [];
    }

    private function findNode(array $graph, ?string $nodeId): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    private function findTriggerNode(array $graph): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (str_starts_with((string) ($node['type'] ?? ''), 'trigger.')) {
                return $node;
            }
        }
        return null;
    }

    private function outgoingEdges(array $graph, string $nodeId): array
    {
        return array_values(array_filter($graph['edges'] ?? [], static function (array $edge) use ($nodeId) {
            return ($edge['source']['nodeId'] ?? null) === $nodeId;
        }));
    }

    private function pickEdge(array $edges, ?string $outcome): ?array
    {
        if ($outcome !== null) {
            foreach ($edges as $edge) {
                if (($edge['source']['port'] ?? null) === $outcome || ($edge['outcome'] ?? null) === $outcome) {
                    return $edge;
                }
            }
        }
        if (count($edges) === 1) {
            return $edges[0];
        }
        foreach ($edges as $edge) {
            if (!empty($edge['isDefault'])) {
                return $edge;
            }
        }
        return $edges[0] ?? null;
    }

    // ── Reading instance state back out ──────────────────────────────────

    public function loadInstance(int $instanceId): array
    {
        $instance = $this->db->one(
            'SELECT i.*, u.name AS owner_name FROM process_instances i LEFT JOIN users u ON u.id = i.owner_user_id WHERE i.id = ?',
            [$instanceId]
        );
        if (!$instance) {
            throw new EngineException('Instance not found');
        }
        $tasks = $this->db->all('SELECT * FROM process_tasks WHERE process_instance_id = ? ORDER BY created_at DESC', [$instanceId]);
        $waits = $this->db->all('SELECT * FROM process_waits WHERE process_instance_id = ? ORDER BY created_at DESC', [$instanceId]);
        $executions = $this->db->all('SELECT * FROM process_executions WHERE process_instance_id = ? ORDER BY started_at DESC, id DESC', [$instanceId]);
        return ['instance' => $instance, 'tasks' => $tasks, 'waits' => $waits, 'executions' => $executions];
    }
}
