// Canonical, mutable graph state for the process designer.
//
// This is the one place that mutates a graph document. Every UI component
// (canvas, palette, inspector, toolbar) only *publishes intent* — "add this
// node", "connect these two ports", "rename this node" — by calling a
// method here; the store applies it, snapshots undo history, and notifies
// listeners. Nothing else reaches into `store.graph` and edits it directly.
//
// Undo/redo is whole-document snapshotting rather than fine-grained inverse
// commands: graphs here are at most a few dozen nodes, so structuredClone()
// on each edit is cheap, and snapshotting is far less code (and far fewer
// ways to get an inverse operation subtly wrong) than a command-object
// stack would be. `transaction(label, fn)` batches a drag or a multi-step
// edit into a single undo step.
//
// Deliberately a plain EventTarget, not the app-wide LARC/PAN bus
// (publish/subscribe in core.js): graph edits are page-local, high
// frequency (every pointermove while dragging), and never need to reach
// another part of the app — exactly the "keep graph state separate from
// temporary UI state" + "use events to reduce coupling" split the brief
// asks for, just scoped to this one designer instance instead of global.

import { normalizeGraph, nextId } from './graph-schema.js';
import { defaultConfigFor } from './node-registry.js';

export class ProcessGraphStore extends EventTarget {
  constructor(graph) {
    super();
    this.graph = normalizeGraph(graph);
    this.selection = new Set();
    this.undoStack = [];
    this.redoStack = [];
    this.dirty = false;
    this._batchDepth = 0;
  }

  // ── Change plumbing ─────────────────────────────────────────────────────
  transaction(label, fn) {
    if (!this._batchDepth) this._pushUndo(label);
    this._batchDepth++;
    try {
      fn();
    } finally {
      this._batchDepth--;
      this.dirty = true;
      this._emit('change');
    }
  }

  /** Explicit open/close pair for interactions that span multiple discrete
   *  calls over time (a pointer drag) rather than one synchronous function —
   *  everything mutated between beginBatch()/endBatch() collapses into a
   *  single undo step. Every mutator below already goes through
   *  transaction(), so as long as a batch is open they just contribute to it
   *  instead of pushing their own snapshot. */
  beginBatch(label) {
    if (!this._batchDepth) this._pushUndo(label);
    this._batchDepth++;
  }

  endBatch() {
    this._batchDepth = Math.max(0, this._batchDepth - 1);
    this.dirty = true;
    this._emit('change');
  }

  _pushUndo(label) {
    this.undoStack.push({ label, snapshot: structuredClone(this.graph) });
    if (this.undoStack.length > 100) this.undoStack.shift();
    this.redoStack = [];
  }

  undo() {
    if (!this.undoStack.length) return;
    const entry = this.undoStack.pop();
    this.redoStack.push({ label: entry.label, snapshot: structuredClone(this.graph) });
    this.graph = entry.snapshot;
    this.selection = new Set([...this.selection].filter((id) => this.graph.nodes.some((n) => n.id === id)));
    this.dirty = true;
    this._emit('change');
  }

  redo() {
    if (!this.redoStack.length) return;
    const entry = this.redoStack.pop();
    this.undoStack.push({ label: entry.label, snapshot: structuredClone(this.graph) });
    this.graph = entry.snapshot;
    this.dirty = true;
    this._emit('change');
  }

  canUndo() { return this.undoStack.length > 0; }
  canRedo() { return this.redoStack.length > 0; }

  markClean() { this.dirty = false; this._emit('change'); }

  _emit(type, detail = {}) {
    this.dispatchEvent(new CustomEvent(type, { detail }));
  }

  // ── Node ops ────────────────────────────────────────────────────────────
  addNode(type, position, overrides = {}) {
    const node = {
      id: nextId('n'),
      type,
      name: overrides.name || '',
      description: '',
      position: { x: position?.x ?? 0, y: position?.y ?? 0 },
      config: defaultConfigFor(type),
      runtimePolicy: {},
      ui: {},
      ...overrides,
    };
    this.transaction('Add node', () => this.graph.nodes.push(node));
    return node;
  }

  updateNode(id, patch) {
    this.transaction('Edit node', () => {
      const node = this.graph.nodes.find((n) => n.id === id);
      if (!node) return;
      Object.assign(node, patch);
    });
  }

  updateNodeConfig(id, configPatch) {
    this.transaction('Edit node config', () => {
      const node = this.graph.nodes.find((n) => n.id === id);
      if (!node) return;
      node.config = { ...node.config, ...configPatch };
    });
  }

  moveNodes(ids, dx, dy) {
    this.transaction('Move', () => {
      for (const id of ids) {
        const node = this.graph.nodes.find((n) => n.id === id);
        if (node) { node.position.x += dx; node.position.y += dy; }
      }
    });
  }

  setNodePosition(id, x, y) {
    this.transaction('Move', () => {
      const node = this.graph.nodes.find((n) => n.id === id);
      if (node) { node.position.x = x; node.position.y = y; }
    });
  }

  removeNodes(ids) {
    const idSet = new Set(ids);
    this.transaction('Delete', () => {
      this.graph.nodes = this.graph.nodes.filter((n) => !idSet.has(n.id));
      this.graph.edges = this.graph.edges.filter((e) => !idSet.has(e.source.nodeId) && !idSet.has(e.target.nodeId));
    });
    for (const id of idSet) this.selection.delete(id);
  }

  duplicateNodes(ids) {
    const idSet = new Set(ids);
    const idMap = new Map();
    const clones = [];
    this.transaction('Duplicate', () => {
      for (const node of this.graph.nodes) {
        if (!idSet.has(node.id)) continue;
        const clone = structuredClone(node);
        clone.id = nextId('n');
        clone.position = { x: node.position.x + 40, y: node.position.y + 40 };
        idMap.set(node.id, clone.id);
        clones.push(clone);
      }
      this.graph.nodes.push(...clones);
      // Duplicate edges that ran strictly between duplicated nodes, so a
      // copy-pasted subgraph keeps its internal wiring.
      const innerEdges = this.graph.edges.filter((e) => idSet.has(e.source.nodeId) && idSet.has(e.target.nodeId));
      for (const edge of innerEdges) {
        this.graph.edges.push({
          ...structuredClone(edge),
          id: nextId('e'),
          source: { ...edge.source, nodeId: idMap.get(edge.source.nodeId) },
          target: { ...edge.target, nodeId: idMap.get(edge.target.nodeId) },
        });
      }
    });
    this.selection = new Set(clones.map((c) => c.id));
    return clones;
  }

  // ── Edge ops ────────────────────────────────────────────────────────────
  addEdge(source, target, extra = {}) {
    if (source.nodeId === target.nodeId) return null; // no self-loops from a drag
    const edge = {
      id: nextId('e'),
      source,
      target,
      type: 'normal',
      outcome: null,
      isDefault: false,
      label: '',
      priority: 0,
      ...extra,
    };
    this.transaction('Connect', () => this.graph.edges.push(edge));
    return edge;
  }

  updateEdge(id, patch) {
    this.transaction('Edit edge', () => {
      const edge = this.graph.edges.find((e) => e.id === id);
      if (edge) Object.assign(edge, patch);
    });
  }

  removeEdges(ids) {
    const idSet = new Set(ids);
    this.transaction('Delete edge', () => {
      this.graph.edges = this.graph.edges.filter((e) => !idSet.has(e.id));
    });
  }

  // ── Selection (not undoable — pure UI state) ───────────────────────────
  select(ids, { additive = false } = {}) {
    if (!additive) this.selection.clear();
    for (const id of ids) this.selection.add(id);
    this._emit('selection');
  }

  toggle(id) {
    if (this.selection.has(id)) this.selection.delete(id);
    else this.selection.add(id);
    this._emit('selection');
  }

  clearSelection() {
    this.selection.clear();
    this._emit('selection');
  }

  selectedIds() { return [...this.selection]; }

  // ── Viewport (not undoable) ─────────────────────────────────────────────
  setViewport(viewport) {
    this.graph.viewport = { ...this.graph.viewport, ...viewport };
    this._emit('viewport');
  }

  // ── Meta ────────────────────────────────────────────────────────────────
  setMeta(patch) {
    this.transaction('Edit details', () => {
      this.graph.meta = { ...this.graph.meta, ...patch };
    });
  }

  toJSON() { return structuredClone(this.graph); }
}
