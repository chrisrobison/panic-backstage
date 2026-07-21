<?php
declare(strict_types=1);

namespace Panic\Processes;

/**
 * Server-side mirror of public/assets/processes/validator.js.
 *
 * The client validator is the one users interact with while editing (it runs
 * on every keystroke and paints markers directly on nodes) — this is the
 * gate that actually decides whether a version is allowed to publish, so a
 * request forged straight against the API can't skip validation the way it
 * could if only the browser enforced it. Deliberately a *subset* of the full
 * rule list in validator.js: the structural checks that matter for data
 * integrity (dangling edges, missing triggers/branches) are duplicated here;
 * softer UX-only warnings are not.
 */
final class GraphValidator
{
    /**
     * @return array{errors: list<array{nodeId: ?string, edgeId: ?string, message: string}>,
     *               warnings: list<array{nodeId: ?string, edgeId: ?string, message: string}>}
     */
    public static function validate(array $graph): array
    {
        $errors = [];
        $warnings = [];

        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        $nodesById = [];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['id'])) {
                $nodesById[(string) $node['id']] = $node;
            }
        }

        if (!$nodes) {
            $errors[] = self::err(null, null, 'The process has no nodes yet.');
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        // Missing start trigger — exactly the kind of thing that must block
        // publish: a graph with no trigger.* node has no way to ever start.
        $triggers = array_filter($nodes, static fn ($n) => str_starts_with((string) ($n['type'] ?? ''), 'trigger.'));
        if (!$triggers) {
            $errors[] = self::err(null, null, 'Missing a trigger node — the process has no way to start.');
        }

        // Dangling edges: source/target node ids that don't exist.
        $outgoing = [];
        $incoming = [];
        foreach ($edges as $edge) {
            $edgeId = (string) ($edge['id'] ?? '');
            $sourceId = (string) ($edge['source']['nodeId'] ?? '');
            $targetId = (string) ($edge['target']['nodeId'] ?? '');
            if ($sourceId === '' || !isset($nodesById[$sourceId])) {
                $errors[] = self::err(null, $edgeId, "Edge references a missing source node ($sourceId).");
            } else {
                $outgoing[$sourceId][] = $edge;
            }
            if ($targetId === '' || !isset($nodesById[$targetId])) {
                $errors[] = self::err(null, $edgeId, "Edge references a missing target node ($targetId).");
            } else {
                $incoming[$targetId][] = $edge;
            }
        }

        // Unreachable nodes: anything (other than a trigger) with no incoming edge.
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');
            if (str_starts_with($type, 'trigger.')) {
                continue;
            }
            if (empty($incoming[$id])) {
                $errors[] = self::err($id, null, 'Unreachable — nothing transitions into this node.');
            }
        }

        // Dead ends: a non-terminal node with no outgoing edge.
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');
            if (in_array($type, ['flow.end', 'flow.failure_end'], true)) {
                continue;
            }
            if (empty($outgoing[$id])) {
                $errors[] = self::err($id, null, 'Dead end — this node has no outgoing transition and is not an End node.');
            }
        }

        // Decision nodes need a default/else branch.
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'flow.decision') {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            $branches = $outgoing[$id] ?? [];
            $hasDefault = (bool) array_filter($branches, static fn ($e) => !empty($e['isDefault']) || ($e['outcome'] ?? null) === null);
            if (!$hasDefault) {
                $errors[] = self::err($id, null, 'Decision has no default branch — every condition could fail with nowhere to go.');
            }
        }

        // Human-task nodes need an assignee or a role.
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (!str_starts_with($type, 'human.')) {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            if (empty($config['assigneeUserId']) && empty($config['assigneeRole'])) {
                $warnings[] = self::warn((string) ($node['id'] ?? ''), null, 'No assignee or role set — this task will be unassigned.');
            }
        }

        // Wait nodes should declare what they're waiting for.
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (!in_array($type, ['flow.wait', 'flow.timer'], true)) {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            if (empty($config['awaitedEvent']) && empty($config['duration'])) {
                $warnings[] = self::warn((string) ($node['id'] ?? ''), null, 'No awaited event or duration configured.');
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private static function err(?string $nodeId, ?string $edgeId, string $message): array
    {
        return ['nodeId' => $nodeId, 'edgeId' => $edgeId, 'message' => $message, 'severity' => 'error'];
    }

    private static function warn(?string $nodeId, ?string $edgeId, string $message): array
    {
        return ['nodeId' => $nodeId, 'edgeId' => $edgeId, 'message' => $message, 'severity' => 'warning'];
    }
}
