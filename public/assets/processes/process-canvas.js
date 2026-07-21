// <pb-process-canvas> — the graph surface: pan/zoom, node drag, box select,
// connect-by-drag, keyboard operations, a minimap, and validation markers.
//
// SVG draws edges; each node is an HTML <pb-process-node> custom element
// (process-node.js) absolutely positioned inside a single transformed
// "world" layer shared by both, so pan/zoom is one CSS transform and edge
// paths/node positions never need separate coordinate systems.
//
// All interaction is delegated at the canvas root rather than attached
// per-node — one drag/box-select/connect state machine, not N of them.
// Every structural edit (move, connect, delete, duplicate, paste) goes
// through the ProcessGraphStore passed in via `.store`; this component
// never mutates a graph document itself.
import { esc } from '../core.js';
import { nodePorts, getNodeType, toneFor } from './node-registry.js';
import { validateGraph, findingsForNode } from './validator.js';
import './process-node.js';

const NODE_W = 190;
const NODE_H = 68;

// Session-lifetime clipboard for copy/paste — deliberately not the system
// clipboard (no permission prompt, works offline, and paste targets whatever
// canvas last had focus, mirroring how most desktop diagram tools scope
// their internal clipboard).
let _clipboard = null;

export class ProcessCanvasElement extends HTMLElement {
  connectedCallback() {
    this.abort = new AbortController();
    this._nodeEls = new Map();
    this.readOnly = this.hasAttribute('read-only');
    this.innerHTML = `
      <div class="proc-canvas-world">
        <svg class="proc-canvas-edges"></svg>
        <div class="proc-canvas-nodes"></div>
        <div class="proc-canvas-rubberband" hidden></div>
      </div>
      <div class="proc-canvas-minimap" aria-hidden="true"><svg></svg><div class="proc-minimap-viewport"></div></div>
      <p class="proc-canvas-hint muted small">Drag from a node's right-hand dot to connect it. Click empty space to select-box. Delete/Backspace removes selected nodes; Ctrl/Cmd+D duplicates; arrow keys nudge.</p>
    `;
    this.world = this.querySelector('.proc-canvas-world');
    this.edgesSvg = this.querySelector('.proc-canvas-edges');
    this.nodesLayer = this.querySelector('.proc-canvas-nodes');
    this.rubberband = this.querySelector('.proc-canvas-rubberband');
    this.minimap = this.querySelector('.proc-canvas-minimap');
    this.setAttribute('tabindex', '-1');

    this._bindPointer();
    this._bindKeyboard();
    this._bindWheel();
    this._bindDrop();

    if (this._pendingStore) this.store = this._pendingStore;
  }

  disconnectedCallback() {
    this.abort?.abort();
  }

  set store(store) {
    if (!this.isConnected) { this._pendingStore = store; return; }
    this._store?.removeEventListener?.('change', this._onChange);
    this._store?.removeEventListener?.('selection', this._onChange);
    this._store?.removeEventListener?.('viewport', this._onChange);
    this._store = store;
    this._onChange = () => this.render();
    store.addEventListener('change', this._onChange, { signal: this.abort.signal });
    store.addEventListener('selection', this._onChange, { signal: this.abort.signal });
    store.addEventListener('viewport', this._onChange, { signal: this.abort.signal });
    this.render();
  }

  get store() { return this._store; }

  /** Optional live operational data: { nodeCounts: { [nodeId]: {active,waiting,overdue,failed} } } */
  set liveData(value) { this._liveData = value; this.render(); }

  /** Optional case-trace overlay: { currentNodeId, visitedNodeIds: string[] } */
  set highlightPath(value) { this._highlightPath = value; this.render(); }

  // ── Rendering ─────────────────────────────────────────────────────────
  render() {
    if (!this._store) return;
    const graph = this._store.graph;
    const vp = graph.viewport || { x: 0, y: 0, zoom: 1 };
    this.world.style.transform = `translate(${vp.x}px, ${vp.y}px) scale(${vp.zoom})`;

    const validation = validateGraph(graph);
    this._lastValidation = validation;

    const seen = new Set();
    for (const node of graph.nodes) {
      seen.add(node.id);
      let el = this._nodeEls.get(node.id);
      if (!el) {
        el = document.createElement('pb-process-node');
        this._nodeEls.set(node.id, el);
        this.nodesLayer.appendChild(el);
      }
      const def = getNodeType(node.type);
      el.data = {
        node,
        tone: toneFor(node.type),
        icon: def?.icon || 'fa-solid fa-circle',
        label: def?.label || node.type,
        ports: nodePorts(node),
        selected: this._store.selection.has(node.id),
        findings: findingsForNode(validation, node.id),
        badges: this._badgesFor(node.id),
        disabled: this._highlightPath ? !this._highlightPath.visitedNodeIds?.includes(node.id) && this._highlightPath.currentNodeId !== node.id : false,
      };
      el.classList.toggle('is-current', this._highlightPath?.currentNodeId === node.id);
      el.classList.toggle('is-visited', !!this._highlightPath?.visitedNodeIds?.includes(node.id));
    }
    for (const [id, el] of this._nodeEls) {
      if (!seen.has(id)) { el.remove(); this._nodeEls.delete(id); }
    }

    this._renderEdges(graph);
    this._renderMinimap(graph, vp);
  }

  _badgesFor(nodeId) {
    const counts = this._liveData?.nodeCounts?.[nodeId];
    if (!counts) return null;
    return counts;
  }

  _renderEdges(graph) {
    const nodeById = new Map(graph.nodes.map((n) => [n.id, n]));
    const portY = (node, portId, list, isOutput) => {
      const ports = isOutput ? (nodePorts(node).outputs || []) : null;
      if (isOutput && ports && ports.length > 1) {
        const idx = ports.findIndex((p) => p.id === portId);
        return node.position.y + (idx + 1) * (NODE_H / (ports.length + 1));
      }
      return node.position.y + NODE_H / 2;
    };
    const paths = graph.edges.map((edge) => {
      const source = nodeById.get(edge.source.nodeId);
      const target = nodeById.get(edge.target.nodeId);
      if (!source || !target) return '';
      const x1 = source.position.x + NODE_W;
      const y1 = portY(source, edge.source.port, null, true);
      const x2 = target.position.x;
      const y2 = target.position.y + NODE_H / 2;
      const dx = Math.max(40, (x2 - x1) / 2);
      const d = `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`;
      const midX = (x1 + x2) / 2;
      const midY = (y1 + y2) / 2;
      const label = edge.label || (edge.outcome && edge.outcome !== 'out' ? edge.outcome : '');
      const cls = `proc-edge proc-edge-${esc(edge.type || 'normal')}${edge.isDefault ? ' is-default' : ''}`;
      const selected = this._store.selection.has(edge.id) ? ' selected' : '';
      return `<path class="${cls}${selected}" data-edge-id="${esc(edge.id)}" d="${d}" marker-end="url(#proc-arrow-${esc(edge.type || 'normal')})"></path>
        ${label ? `<foreignObject x="${midX - 55}" y="${midY - 11}" width="110" height="22" class="proc-edge-label-wrap"><div xmlns="http://www.w3.org/1999/xhtml" class="proc-edge-label">${esc(label)}</div></foreignObject>` : ''}`;
    }).join('');

    const tempEdge = this._connectDraft
      ? `<path class="proc-edge proc-temp-edge" d="${this._connectDraft.path}"></path>`
      : '';

    this.edgesSvg.innerHTML = `
      <defs>
        ${['normal', 'conditional', 'error', 'timeout', 'escalation', 'data'].map((t) => `
          <marker id="proc-arrow-${t}" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="userSpaceOnUse">
            <path d="M0,0 L0,6 L9,3 z" class="proc-arrow proc-arrow-${t}"></path>
          </marker>`).join('')}
      </defs>
      ${paths}
      ${tempEdge}
    `;
  }

  _renderMinimap(graph, vp) {
    const svg = this.minimap.querySelector('svg');
    if (!graph.nodes.length) { svg.innerHTML = ''; this.minimap.querySelector('.proc-minimap-viewport').style.display = 'none'; return; }
    const xs = graph.nodes.map((n) => n.position.x);
    const ys = graph.nodes.map((n) => n.position.y);
    const minX = Math.min(...xs) - 40;
    const minY = Math.min(...ys) - 40;
    const maxX = Math.max(...xs) + NODE_W + 40;
    const maxY = Math.max(...ys) + NODE_H + 40;
    const spanX = Math.max(1, maxX - minX);
    const spanY = Math.max(1, maxY - minY);
    this._minimapBounds = { minX, minY, spanX, spanY };
    const scale = Math.min(160 / spanX, 100 / spanY);
    svg.setAttribute('viewBox', `0 0 ${spanX * scale} ${spanY * scale}`);
    svg.innerHTML = graph.nodes.map((n) => `<rect class="proc-minimap-node" data-tone="${esc(toneFor(n.type))}"
      x="${(n.position.x - minX) * scale}" y="${(n.position.y - minY) * scale}" width="${NODE_W * scale}" height="${NODE_H * scale}" rx="2"></rect>`).join('');

    const rect = this.getBoundingClientRect();
    const viewportEl = this.minimap.querySelector('.proc-minimap-viewport');
    viewportEl.style.display = '';
    viewportEl.style.left = `${((-vp.x / vp.zoom) - minX) * scale}px`;
    viewportEl.style.top = `${((-vp.y / vp.zoom) - minY) * scale}px`;
    viewportEl.style.width = `${(rect.width / vp.zoom) * scale}px`;
    viewportEl.style.height = `${(rect.height / vp.zoom) * scale}px`;
  }

  // ── Coordinate helpers ──────────────────────────────────────────────────
  _toWorld(clientX, clientY) {
    const rect = this.getBoundingClientRect();
    const vp = this._store.graph.viewport;
    return { x: (clientX - rect.left - vp.x) / vp.zoom, y: (clientY - rect.top - vp.y) / vp.zoom };
  }

  // ── Zoom/pan API (called by the toolbar) ────────────────────────────────
  zoomBy(factor) {
    const vp = this._store.graph.viewport;
    const rect = this.getBoundingClientRect();
    this._zoomAt(rect.width / 2, rect.height / 2, vp.zoom * factor);
  }

  zoomReset() {
    this._store.setViewport({ zoom: 1 });
  }

  fitToScreen() {
    const graph = this._store.graph;
    if (!graph.nodes.length) { this._store.setViewport({ x: 0, y: 0, zoom: 1 }); return; }
    const xs = graph.nodes.map((n) => n.position.x);
    const ys = graph.nodes.map((n) => n.position.y);
    const minX = Math.min(...xs) - 40;
    const minY = Math.min(...ys) - 40;
    const maxX = Math.max(...xs) + NODE_W + 40;
    const maxY = Math.max(...ys) + NODE_H + 40;
    const rect = this.getBoundingClientRect();
    const zoom = Math.max(0.25, Math.min(1.4, rect.width / (maxX - minX), rect.height / (maxY - minY)));
    this._store.setViewport({
      zoom,
      x: rect.width / 2 - ((minX + maxX) / 2) * zoom,
      y: rect.height / 2 - ((minY + maxY) / 2) * zoom,
    });
  }

  fitSelection() {
    const ids = this._store.selectedIds();
    if (!ids.length) return this.fitToScreen();
    const nodes = this._store.graph.nodes.filter((n) => ids.includes(n.id));
    const xs = nodes.map((n) => n.position.x);
    const ys = nodes.map((n) => n.position.y);
    const minX = Math.min(...xs) - 60;
    const minY = Math.min(...ys) - 60;
    const maxX = Math.max(...xs) + NODE_W + 60;
    const maxY = Math.max(...ys) + NODE_H + 60;
    const rect = this.getBoundingClientRect();
    const zoom = Math.max(0.25, Math.min(1.8, rect.width / (maxX - minX), rect.height / (maxY - minY)));
    this._store.setViewport({ zoom, x: rect.width / 2 - ((minX + maxX) / 2) * zoom, y: rect.height / 2 - ((minY + maxY) / 2) * zoom });
  }

  _zoomAt(clientX, clientY, nextZoom) {
    const vp = this._store.graph.viewport;
    const zoom = Math.max(0.2, Math.min(2.5, nextZoom));
    const rect = this.getBoundingClientRect();
    const px = clientX - rect.left;
    const py = clientY - rect.top;
    const worldX = (px - vp.x) / vp.zoom;
    const worldY = (py - vp.y) / vp.zoom;
    this._store.setViewport({ zoom, x: px - worldX * zoom, y: py - worldY * zoom });
  }

  _bindDrop() {
    this.addEventListener('dragover', (event) => {
      if (event.dataTransfer?.types?.includes('text/proc-node-type')) event.preventDefault();
    }, { signal: this.abort.signal });
    this.addEventListener('drop', (event) => {
      const type = event.dataTransfer?.getData('text/proc-node-type');
      if (!type || !this._store || this.readOnly) return;
      event.preventDefault();
      const world = this._toWorld(event.clientX, event.clientY);
      const node = this._store.addNode(type, { x: world.x - NODE_W / 2, y: world.y - NODE_H / 2 });
      this._store.select([node.id]);
    }, { signal: this.abort.signal });
  }

  _bindWheel() {
    this.addEventListener('wheel', (event) => {
      if (!this._store) return;
      event.preventDefault();
      if (event.ctrlKey || event.metaKey) {
        this._zoomAt(event.clientX, event.clientY, this._store.graph.viewport.zoom * (1 - event.deltaY * 0.01));
      } else {
        const vp = this._store.graph.viewport;
        this._store.setViewport({ x: vp.x - event.deltaX, y: vp.y - event.deltaY });
      }
    }, { passive: false, signal: this.abort.signal });
  }

  // ── Pointer interaction: drag, box-select, connect ──────────────────────
  _bindPointer() {
    // Selection (click a node, box-select) always works, even read-only —
    // read-only only blocks *mutation*: dragging a node, connecting ports,
    // deleting/duplicating. Viewing a published graph still needs to let an
    // operator click through nodes to inspect their configuration.
    this.addEventListener('pointerdown', (event) => {
      if (!this._store) return;
      const portEl = event.target.closest('[data-port-role="out"]');
      const nodeEl = event.target.closest('.proc-node');
      const badgeEl = event.target.closest('[data-badge-filter]');
      if (badgeEl) { this._emitBadgeFilter(badgeEl); return; }

      if (portEl) {
        if (!this.readOnly) this._beginConnect(portEl, event);
        return;
      }
      if (nodeEl) {
        this._beginNodeDrag(nodeEl, event);
        return;
      }
      this._beginBoxSelect(event);
    }, { signal: this.abort.signal });

    this.addEventListener('focusin', (event) => {
      const nodeEl = event.target.closest?.('.proc-node');
      if (nodeEl && !this._store.selection.has(nodeEl.dataset.nodeId)) {
        this._store.select([nodeEl.dataset.nodeId]);
      }
    }, { signal: this.abort.signal });
  }

  _emitBadgeFilter(badgeEl) {
    this.dispatchEvent(new CustomEvent('badge-filter', {
      bubbles: true,
      detail: { nodeId: badgeEl.dataset.nodeId, status: badgeEl.dataset.badgeFilter },
    }));
  }

  _beginNodeDrag(nodeEl, event) {
    const nodeId = nodeEl.dataset.nodeId;
    const additive = event.shiftKey || event.metaKey || event.ctrlKey;
    if (!this._store.selection.has(nodeId)) {
      this._store.select([nodeId], { additive });
    }
    if (this.readOnly) return; // selection only — no move-tracking when viewing a published graph
    const ids = [...this._store.selection];
    let last = this._toWorld(event.clientX, event.clientY);
    let moved = false;
    this._store.beginBatch('Move');

    const onMove = (e) => {
      const world = this._toWorld(e.clientX, e.clientY);
      const dx = world.x - last.x;
      const dy = world.y - last.y;
      if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) moved = true;
      last = world;
      this._store.moveNodes(ids, dx, dy);
    };
    const onUp = () => {
      this._store.endBatch();
      if (!moved && !additive) this._store.select([nodeId]);
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
    window.addEventListener('pointermove', onMove, { signal: this.abort.signal });
    window.addEventListener('pointerup', onUp, { signal: this.abort.signal, once: true });
  }

  _beginBoxSelect(event) {
    const additive = event.shiftKey;
    const start = this._toWorld(event.clientX, event.clientY);
    this.rubberband.hidden = false;
    if (!additive) this._store.clearSelection();

    const update = (e) => {
      const cur = this._toWorld(e.clientX, e.clientY);
      const x = Math.min(start.x, cur.x);
      const y = Math.min(start.y, cur.y);
      const w = Math.abs(cur.x - start.x);
      const h = Math.abs(cur.y - start.y);
      Object.assign(this.rubberband.style, { left: `${x}px`, top: `${y}px`, width: `${w}px`, height: `${h}px` });
      const ids = this._store.graph.nodes.filter((n) => n.position.x < x + w && n.position.x + NODE_W > x && n.position.y < y + h && n.position.y + NODE_H > y).map((n) => n.id);
      this._store.select(ids, { additive });
    };
    const finish = () => {
      this.rubberband.hidden = true;
      window.removeEventListener('pointermove', update);
      window.removeEventListener('pointerup', finish);
    };
    window.addEventListener('pointermove', update, { signal: this.abort.signal });
    window.addEventListener('pointerup', finish, { signal: this.abort.signal, once: true });
  }

  _beginConnect(portEl, event) {
    const sourceNodeId = portEl.dataset.nodeId;
    const sourcePort = portEl.dataset.port;
    const sourceNode = this._store.graph.nodes.find((n) => n.id === sourceNodeId);
    const sourcePos = this._portScreenToWorld(sourceNode, sourcePort);

    const move = (e) => {
      const cur = this._toWorld(e.clientX, e.clientY);
      const dx = Math.max(30, (cur.x - sourcePos.x) / 2);
      this._connectDraft = { path: `M ${sourcePos.x} ${sourcePos.y} C ${sourcePos.x + dx} ${sourcePos.y}, ${cur.x - dx} ${cur.y}, ${cur.x} ${cur.y}` };
      this._renderEdges(this._store.graph);
    };
    const finish = (e) => {
      this._connectDraft = null;
      const targetNodeEl = document.elementFromPoint(e.clientX, e.clientY)?.closest('.proc-node');
      if (targetNodeEl && targetNodeEl.dataset.nodeId !== sourceNodeId) {
        this._store.addEdge({ nodeId: sourceNodeId, port: sourcePort }, { nodeId: targetNodeEl.dataset.nodeId, port: 'in' });
      } else {
        this.render();
      }
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', finish);
    };
    window.addEventListener('pointermove', move, { signal: this.abort.signal });
    window.addEventListener('pointerup', finish, { signal: this.abort.signal, once: true });
  }

  _portScreenToWorld(node, portId) {
    if (!node) return { x: 0, y: 0 };
    const ports = nodePorts(node).outputs || [];
    if (ports.length > 1) {
      const idx = ports.findIndex((p) => p.id === portId);
      return { x: node.position.x + NODE_W, y: node.position.y + (idx + 1) * (NODE_H / (ports.length + 1)) };
    }
    return { x: node.position.x + NODE_W, y: node.position.y + NODE_H / 2 };
  }

  // ── Keyboard ─────────────────────────────────────────────────────────────
  _bindKeyboard() {
    this.addEventListener('keydown', (event) => {
      if (!this._store || this.readOnly) return;
      const meta = event.metaKey || event.ctrlKey;
      const selected = this._store.selectedIds();

      if ((event.key === 'Delete' || event.key === 'Backspace') && selected.length) {
        event.preventDefault();
        this._store.removeNodes(selected);
      } else if (meta && event.key.toLowerCase() === 'd' && selected.length) {
        event.preventDefault();
        this._store.duplicateNodes(selected);
      } else if (meta && event.key.toLowerCase() === 'c' && selected.length) {
        event.preventDefault();
        _clipboard = structuredClone(this._store.graph.nodes.filter((n) => selected.includes(n.id)));
      } else if (meta && event.key.toLowerCase() === 'v' && _clipboard) {
        event.preventDefault();
        this._pasteClipboard();
      } else if (meta && event.key.toLowerCase() === 'z' && !event.shiftKey) {
        event.preventDefault();
        this._store.undo();
      } else if (meta && (event.key.toLowerCase() === 'y' || (event.key.toLowerCase() === 'z' && event.shiftKey))) {
        event.preventDefault();
        this._store.redo();
      } else if (event.key === 'Escape') {
        this._store.clearSelection();
      } else if (event.key.startsWith('Arrow') && selected.length) {
        event.preventDefault();
        const step = event.shiftKey ? 24 : 8;
        const dx = event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
        const dy = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
        this._store.moveNodes(selected, dx, dy);
      }
    }, { signal: this.abort.signal });
  }

  _pasteClipboard() {
    if (!_clipboard) return;
    const idMap = new Map();
    const clones = _clipboard.map((n) => {
      const clone = structuredClone(n);
      const newId = `n${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
      idMap.set(n.id, newId);
      clone.id = newId;
      clone.position = { x: n.position.x + 60, y: n.position.y + 60 };
      return clone;
    });
    this._store.transaction('Paste', () => {
      this._store.graph.nodes.push(...clones);
    });
    this._store.select(clones.map((c) => c.id));
  }
}
customElements.define('pb-process-canvas', ProcessCanvasElement);
