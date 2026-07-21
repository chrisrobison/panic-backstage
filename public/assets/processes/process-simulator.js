// Simulation mode ("Test" button) — steps through a graph document node by
// node with zero real side effects: no emails sent, no records written, no
// tasks assigned. Automatic nodes (triggers/operations/AI) just log a
// mocked line and advance; decision/AI-decision nodes let you pick which
// branch to take; human-task and wait/timer nodes pause and ask you to
// choose how they resolve (approve/revise/reject, resumed/timeout, etc).
//
// This intentionally does NOT model parallel-split/join concurrency in
// Phase 1 — it follows the first branch and logs that simplification, so
// nobody mistakes a straight-line walk for the real fan-out/fan-in
// semantics the runtime will eventually implement.
import { $, $$, esc, openModal } from '../core.js';
import { getNodeType, nodePorts } from './node-registry.js';

export function openProcessSimulator(graph) {
  const nodesById = new Map(graph.nodes.map((n) => [n.id, n]));
  const outgoing = new Map();
  for (const edge of graph.edges) {
    if (!outgoing.has(edge.source.nodeId)) outgoing.set(edge.source.nodeId, []);
    outgoing.get(edge.source.nodeId).push(edge);
  }
  const triggers = graph.nodes.filter((n) => n.type?.startsWith('trigger.'));

  const state = {
    currentNodeId: null,
    log: [],
    status: triggers.length ? 'picking-trigger' : 'no-trigger',
  };

  const { dialog, close } = openModal({
    title: 'Test / Simulate Process',
    wide: true,
    bodyHtml: `<div class="proc-sim-body padded" data-sim-body></div>`,
  });

  function log(message, tone = '') {
    state.log.push({ message, tone, at: new Date().toLocaleTimeString() });
  }

  function nodeLabel(node) {
    return `${getNodeType(node.type)?.label || node.type}${node.name ? ` — ${node.name}` : ''}`;
  }

  function advanceTo(nodeId) {
    state.currentNodeId = nodeId;
    const node = nodesById.get(nodeId);
    if (!node) { state.status = 'failed'; log(`No node found for id ${nodeId} — stopping.`, 'error'); render(); return; }

    if (node.type === 'flow.end') { state.status = 'done'; log(`Reached End — process complete.`, 'success'); render(); return; }
    if (node.type === 'flow.failure_end') { state.status = 'failed'; log(`Reached Failure End.`, 'error'); render(); return; }

    if (node.type?.startsWith('human.')) { state.status = 'waiting-human'; log(`Waiting for human task: ${nodeLabel(node)}`); render(); return; }
    if (node.type === 'flow.wait' || node.type === 'flow.timer') { state.status = 'waiting-event'; log(`Waiting: ${nodeLabel(node)}`); render(); return; }
    if (node.type === 'flow.decision' || node.type === 'ai.decision') { state.status = 'waiting-decision'; log(`Evaluating decision: ${nodeLabel(node)}`); render(); return; }

    // Automatic node — mocked side effect, then auto-advance.
    log(`Ran ${nodeLabel(node)} (simulated — no real side effect).`);
    autoAdvance(node);
  }

  function autoAdvance(node) {
    const edges = outgoing.get(node.id) || [];
    if (!edges.length) { state.status = 'done'; log('No outgoing transition — process ends here.', 'success'); render(); return; }
    if (edges.length > 1) log(`Node has ${edges.length} outgoing branches (parallel split) — following the first for this simulation.`, 'warn');
    advanceTo(edges[0].target.nodeId);
  }

  function chooseBranch(portId) {
    const node = nodesById.get(state.currentNodeId);
    const edge = (outgoing.get(node.id) || []).find((e) => e.source.port === portId);
    const ports = nodePorts(node).outputs || [];
    const portLabel = ports.find((p) => p.id === portId)?.label || portId;
    if (!edge) { log(`No edge wired for branch "${portLabel}" — process stalls here.`, 'error'); state.status = 'failed'; render(); return; }
    log(`Chose branch: ${portLabel}`);
    advanceTo(edge.target.nodeId);
  }

  function render() {
    const body = $('[data-sim-body]', dialog);
    if (state.status === 'no-trigger') {
      body.innerHTML = `<p class="error-text">This process has no trigger node yet — nothing to simulate.</p>`;
      return;
    }
    if (state.status === 'picking-trigger') {
      body.innerHTML = `<p>Choose a trigger to start from:</p>
        <div class="inline-actions">${triggers.map((t) => `<button type="button" class="small secondary" data-start="${esc(t.id)}">${esc(nodeLabel(t))}</button>`).join('')}</div>`;
      $$('[data-start]', body).forEach((btn) => btn.addEventListener('click', () => { log(`Started from trigger: ${esc(btn.textContent)}`); autoAdvance(nodesById.get(btn.dataset.start)); }));
      return;
    }

    const node = nodesById.get(state.currentNodeId);
    const def = node ? getNodeType(node.type) : null;
    let controls = '';

    if (state.status === 'waiting-decision' && node) {
      const ports = nodePorts(node).outputs || [];
      controls = `<p>Pick a simulated outcome for <strong>${esc(nodeLabel(node))}</strong>:</p>
        <div class="inline-actions">${ports.map((p) => `<button type="button" class="small secondary" data-branch="${esc(p.id)}">${esc(p.label || p.id)}${p.isDefault ? ' (default)' : ''}</button>`).join('')}</div>`;
    } else if (state.status === 'waiting-human' && node) {
      const ports = nodePorts(node).outputs || [];
      controls = `<p>Simulate the outcome of human task <strong>${esc(nodeLabel(node))}</strong>${node.config?.assigneeRole ? ` (assigned to ${esc(node.config.assigneeRole)})` : ''}:</p>
        <div class="inline-actions">${ports.map((p) => `<button type="button" class="small secondary" data-branch="${esc(p.id)}">${esc(p.label || p.id)}${p.isDefault ? ' (default)' : ''}</button>`).join('')}</div>`;
    } else if (state.status === 'waiting-event' && node) {
      controls = `<p>Simulate <strong>${esc(nodeLabel(node))}</strong> resolving:</p>
        <div class="inline-actions">
          <button type="button" class="small secondary" data-branch="resumed">Resumed (event arrived)</button>
          <button type="button" class="small secondary" data-branch="timeout">Timeout</button>
        </div>`;
    } else if (state.status === 'done') {
      controls = `<p class="proc-sim-status success"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Simulation complete.</p>`;
    } else if (state.status === 'failed') {
      controls = `<p class="proc-sim-status error"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Simulation stopped on a failure path.</p>`;
    }

    body.innerHTML = `
      ${controls}
      <div class="proc-sim-log">
        <h3>Simulation log</h3>
        <ol>${state.log.map((l) => `<li class="${l.tone}"><span class="muted small">${l.at}</span> ${esc(l.message)}</li>`).join('')}</ol>
      </div>
      <div class="inline-actions">
        <button type="button" class="small secondary" data-reset><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</button>
      </div>`;

    $$('[data-branch]', body).forEach((btn) => btn.addEventListener('click', () => {
      if (state.status === 'waiting-event') {
        log(btn.dataset.branch === 'timeout' ? 'Timed out.' : 'Event arrived — resumed.');
        chooseBranch(btn.dataset.branch);
      } else {
        chooseBranch(btn.dataset.branch);
      }
    }));
    $('[data-reset]', body)?.addEventListener('click', () => {
      state.currentNodeId = null;
      state.log = [];
      state.status = triggers.length ? 'picking-trigger' : 'no-trigger';
      render();
    });
  }

  render();
  return { close };
}
