// Publish-time (and edit-time) validation for a graph document.
//
// This is the browser-side twin of src/Processes/GraphValidator.php. The
// PHP copy is the one that actually blocks a publish request — this one
// runs continuously while editing (Validate button, and live markers on
// nodes) and covers a few more soft/UX rules that don't need duplicating
// server-side (nothing destructive hinges on them).
//
// Returns { errors: Finding[], warnings: Finding[] } where
// Finding = { nodeId, edgeId, message }. Errors block Publish; warnings are
// shown but can be published through with confirmation (see process-toolbar.js).

import { getNodeType } from './node-registry.js';

function finding(nodeId, edgeId, message) {
  return { nodeId: nodeId || null, edgeId: edgeId || null, message };
}

export function validateGraph(graph) {
  const errors = [];
  const warnings = [];
  const nodes = graph?.nodes || [];
  const edges = graph?.edges || [];
  const byId = new Map(nodes.map((n) => [n.id, n]));

  if (!nodes.length) {
    errors.push(finding(null, null, 'The process has no nodes yet.'));
    return { errors, warnings };
  }

  const triggers = nodes.filter((n) => n.type?.startsWith('trigger.'));
  if (!triggers.length) {
    errors.push(finding(null, null, 'Missing a trigger node — the process has no way to start.'));
  }

  const outgoing = new Map();
  const incoming = new Map();
  for (const edge of edges) {
    const sourceId = edge.source?.nodeId;
    const targetId = edge.target?.nodeId;
    if (!byId.has(sourceId)) {
      errors.push(finding(null, edge.id, `Edge references a missing source node (${sourceId || '—'}).`));
    } else {
      (outgoing.get(sourceId) || outgoing.set(sourceId, []).get(sourceId)).push(edge);
    }
    if (!byId.has(targetId)) {
      errors.push(finding(null, edge.id, `Edge references a missing target node (${targetId || '—'}).`));
    } else {
      (incoming.get(targetId) || incoming.set(targetId, []).get(targetId)).push(edge);
    }
  }

  for (const node of nodes) {
    if (node.type?.startsWith('trigger.')) continue;
    if (!(incoming.get(node.id) || []).length) {
      errors.push(finding(node.id, null, 'Unreachable — nothing transitions into this node.'));
    }
  }

  for (const node of nodes) {
    if (node.type === 'flow.end' || node.type === 'flow.failure_end') continue;
    if (!(outgoing.get(node.id) || []).length) {
      errors.push(finding(node.id, null, 'Dead end — this node has no outgoing transition and is not an End node.'));
    }
  }

  for (const node of nodes) {
    if (node.type !== 'flow.decision' && node.type !== 'ai.decision') continue;
    const branches = outgoing.get(node.id) || [];
    const hasDefault = branches.some((e) => e.isDefault || e.outcome == null);
    if (!hasDefault) {
      errors.push(finding(node.id, null, node.type === 'ai.decision'
        ? 'AI Decision has no human-fallback branch marked as default.'
        : 'Decision has no default branch — every condition could fail with nowhere to go.'));
    }
  }

  for (const node of nodes) {
    if (!node.type?.startsWith('human.')) continue;
    if (!node.config?.assigneeUserId && !node.config?.assigneeRole) {
      warnings.push(finding(node.id, null, 'No assignee or role set — this task will be unassigned.'));
    }
  }

  for (const node of nodes) {
    if (node.type !== 'flow.wait' && node.type !== 'flow.timer') continue;
    if (!node.config?.awaitedEvent && !node.config?.duration) {
      warnings.push(finding(node.id, null, 'No awaited event or duration configured.'));
    }
  }

  for (const node of nodes) {
    const def = getNodeType(node.type);
    if (!def) {
      errors.push(finding(node.id, null, `Unknown node type "${node.type}".`));
    }
  }

  // Reachability from every trigger — anything not reachable is already
  // caught by the "no incoming edge" rule above for direct cases, but a
  // node can have an incoming edge and still sit in an island disconnected
  // from any trigger (e.g. two triggers stitched into a private loop).
  const reachable = new Set();
  const queue = triggers.map((t) => t.id);
  while (queue.length) {
    const id = queue.shift();
    if (reachable.has(id)) continue;
    reachable.add(id);
    for (const edge of outgoing.get(id) || []) {
      if (byId.has(edge.target.nodeId)) queue.push(edge.target.nodeId);
    }
  }
  const hasEndReachable = nodes.some((n) => (n.type === 'flow.end' || n.type === 'flow.failure_end') && reachable.has(n.id));
  if (triggers.length && nodes.some((n) => n.type === 'flow.end' || n.type === 'flow.failure_end') && !hasEndReachable) {
    warnings.push(finding(null, null, 'No End node is reachable from a trigger — some cases may never terminate.'));
  }

  return { errors, warnings };
}

export function findingsForNode(result, nodeId) {
  return {
    errors: result.errors.filter((f) => f.nodeId === nodeId),
    warnings: result.warnings.filter((f) => f.nodeId === nodeId),
  };
}
