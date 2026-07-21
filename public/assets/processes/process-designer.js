// <pb-process-designer> — Automation > Processes > (one process). Composes
// the toolbar, palette, canvas, inspector, Live Cases drawer, and History
// panel around a single ProcessGraphStore. This is the only component that
// talks to the /api/processes endpoints; everything else underneath it
// only publishes intent (add-node, toolbar-action, select-instance...) or
// reads store state.
//
// Three tabs share one shell: Design (the editable draft, or a read-only
// view of the published graph if no draft exists yet), Live Cases (the
// published graph as a read-only backdrop with live instance badges + the
// bottom drawer), History (versions + audit log).
import { $, api, esc, formData, openModal, publish, PanicElement } from '../core.js';
import { ProcessGraphStore } from './graph-store.js';
import { normalizeGraph } from './graph-schema.js';
import { validateGraph } from './validator.js';
import { openProcessSimulator } from './process-simulator.js';
import './process-canvas.js';
import './process-palette.js';
import './process-inspector.js';
import './process-toolbar.js';
import './process-live-cases.js';
import './process-history.js';

export class ProcessDesignerElement extends PanicElement {
  async connect() {
    this.tab = 'design';
    this.setLoading('Loading process…');
    await this.load();
  }

  async load() {
    const data = await api(`/processes/${this.processId}`);
    this.process = data.process;
    this.versions = data.versions;
    this.draftVersion = data.draftVersion;
    this.publishedVersion = data.publishedVersion;
    this.assignableUsers = data.assignableUsers || [];
    this.capabilities = data.capabilities || {};
    publish('page.context', { title: this.process.name, blurb: 'Automation > Processes' });

    this.editingVersion = this.draftVersion || this.publishedVersion;
    this.editStore = new ProcessGraphStore(this.editingVersion?.graph || { nodes: [], edges: [] });
    this.editReadOnly = !this.draftVersion;

    this.buildShell();
    this.wireEvents();
    this.refreshToolbar();
    if (this.tab === 'live') this.loadLiveData();
    if (this.tab === 'history') this.loadHistoryData();
  }

  buildShell() {
    this.innerHTML = `
      <div class="proc-designer">
        <pb-process-toolbar></pb-process-toolbar>
        <div class="proc-designer-body" data-tab="design">
          <aside class="proc-designer-palette" data-pane="palette"></aside>
          <div class="proc-designer-main" data-pane="design-main">
            <pb-process-canvas data-canvas="design"></pb-process-canvas>
          </div>
          <aside class="proc-designer-inspector" data-pane="inspector"></aside>
        </div>
        <div class="proc-designer-live" data-pane="live" hidden>
          <div class="proc-designer-main">
            <pb-process-canvas data-canvas="live" read-only></pb-process-canvas>
          </div>
          <pb-process-live-cases data-pane="live-cases"></pb-process-live-cases>
        </div>
        <div class="proc-designer-history" data-pane="history" hidden>
          <pb-process-history></pb-process-history>
        </div>
      </div>`;

    this.toolbar = $('pb-process-toolbar', this);
    this.palette = document.createElement('pb-process-palette');
    this.palette.readOnly = this.editReadOnly;
    $('[data-pane="palette"]', this).appendChild(this.palette);

    this.designCanvas = $('[data-canvas="design"]', this);
    this.designCanvas.readOnly = this.editReadOnly;
    this.designCanvas.store = this.editStore;

    this.inspector = document.createElement('pb-process-inspector');
    this.inspector.readOnly = this.editReadOnly;
    this.inspector.assignableUsers = this.assignableUsers;
    this.inspector.store = this.editStore;
    $('[data-pane="inspector"]', this).appendChild(this.inspector);

    this.liveCanvas = $('[data-canvas="live"]', this);
    this.liveStore = new ProcessGraphStore(this.publishedVersion?.graph || { nodes: [], edges: [] });
    this.liveCanvas.store = this.liveStore;

    this.liveCases = $('[data-pane="live-cases"]', this);
    this.liveCases.loadDetail = (id) => api(`/processes/${this.processId}/instances/${id}`).then((r) => {
      const visited = (r.events || []).filter((e) => e.node_id).map((e) => e.node_id);
      this.liveCanvas.highlightPath = { currentNodeId: r.instance.current_node_id, visitedNodeIds: [...new Set(visited)] };
      return r;
    });

    this.history = $('pb-process-history', this);
  }

  wireEvents() {
    this.addEventListener('toolbar-action', (e) => this.onToolbarAction(e.detail));
    this.addEventListener('add-node', (e) => this.onAddNode(e.detail.type));
    this.editStore.addEventListener('change', () => this.refreshToolbar());
    this.editStore.addEventListener('selection', () => this.refreshToolbar());
    this.editStore.addEventListener('viewport', () => this.refreshToolbar());
    this.addEventListener('select-instance', () => {}); // reserved for future cross-component wiring
    this.addEventListener('badge-filter', (e) => {
      if (this.tab !== 'live') return;
      this.liveCases.filters = { ...this.liveCases.filters, status: e.detail.status || '' };
      this.liveCases.render();
    });
    this.addEventListener('view-version', (e) => this.viewHistoricalVersion(e.detail.versionId));
  }

  onAddNode(type) {
    if (this.editReadOnly) return;
    const rect = this.designCanvas.getBoundingClientRect();
    const center = this.designCanvas._toWorld(rect.left + rect.width / 2, rect.top + rect.height / 2);
    // Cascade repeated click-to-adds diagonally instead of stacking every
    // new node exactly on top of the last one at the same canvas center.
    const cascade = (this._addNodeCount = (this._addNodeCount || 0) + 1) % 8;
    const node = this.editStore.addNode(type, { x: center.x - 95 + cascade * 46, y: center.y - 34 + cascade * 34 });
    this.editStore.select([node.id]);
  }

  refreshToolbar() {
    const validation = validateGraph(this.editStore.graph);
    this.toolbar.data = {
      process: this.process,
      version: this.editingVersion,
      dirty: this.editStore.dirty,
      validation,
      canUndo: this.editStore.canUndo(),
      canRedo: this.editStore.canRedo(),
      zoom: this.editStore.graph.viewport?.zoom ?? 1,
      readOnly: this.editReadOnly,
    };
  }

  async onToolbarAction({ kind, action, tab }) {
    if (kind === 'tab') return this.switchTab(tab);
    switch (action) {
      case 'validate': return this.runValidate();
      case 'test': return openProcessSimulator(this.editStore.graph);
      case 'save-draft': return this.saveDraft();
      case 'publish': return this.publishVersion();
      case 'new-draft': return this.createDraft();
      case 'undo': return this.editStore.undo();
      case 'redo': return this.editStore.redo();
      case 'zoom-in': return this.activeCanvas().zoomBy(1.2);
      case 'zoom-out': return this.activeCanvas().zoomBy(1 / 1.2);
      case 'fit': return this.activeCanvas().fitToScreen();
      case 'export': return this.exportGraph();
      case 'import': return this.openImportModal();
      case 'rename': return this.openRenameModal();
      case 'show-validation': return this.runValidate();
      default: return;
    }
  }

  activeCanvas() { return this.tab === 'live' ? this.liveCanvas : this.designCanvas; }

  switchTab(tab) {
    this.tab = tab;
    $('[data-pane="palette"]', this).closest('.proc-designer-body').hidden = tab !== 'design';
    $('[data-pane="live"]', this).hidden = tab !== 'live';
    $('[data-pane="history"]', this).hidden = tab !== 'history';
    if (tab === 'live') this.loadLiveData();
    if (tab === 'history') this.loadHistoryData();
  }

  async loadLiveData() {
    if (!this.publishedVersion) {
      this.liveCases.data = { instances: [] };
      $('[data-canvas="live"]', this).closest('.proc-designer-main').insertAdjacentHTML('afterbegin',
        this.querySelector('.proc-live-empty') ? '' : '<div class="empty-state padded proc-live-empty">Publish this process to see live cases here.</div>');
      return;
    }
    this.querySelector('.proc-live-empty')?.remove();
    const data = await api(`/processes/${this.processId}/instances`);
    this.liveCanvas.liveData = { nodeCounts: data.nodeCounts };
    this.liveCases.data = { instances: data.instances };
  }

  async loadHistoryData() {
    const [audit] = await Promise.all([api(`/processes/${this.processId}/audit`)]);
    this.history.data = { versions: this.versions, audit: audit.entries };
  }

  // ── Design actions ───────────────────────────────────────────────────────
  async runValidate() {
    const result = validateGraph(this.editStore.graph);
    this.showValidationModal(result);
  }

  showValidationModal(result) {
    const nodeName = (id) => this.editStore.graph.nodes.find((n) => n.id === id)?.name || id;
    const row = (f, kind) => `<li class="proc-finding proc-finding-${kind}"><i class="fa-solid ${kind === 'error' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation'}" aria-hidden="true"></i>
      ${f.nodeId ? `<button type="button" class="linklike" data-goto-node="${esc(f.nodeId)}">${esc(nodeName(f.nodeId))}</button>: ` : ''}${esc(f.message)}</li>`;
    const { dialog } = openModal({
      title: 'Validation',
      bodyHtml: `<div class="padded">
        ${result.errors.length ? `<h3>Errors (${result.errors.length})</h3><ul class="proc-finding-list">${result.errors.map((f) => row(f, 'error')).join('')}</ul>` : ''}
        ${result.warnings.length ? `<h3>Warnings (${result.warnings.length})</h3><ul class="proc-finding-list">${result.warnings.map((f) => row(f, 'warning')).join('')}</ul>` : ''}
        ${!result.errors.length && !result.warnings.length ? '<p class="proc-sim-status success"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> No problems found.</p>' : ''}
      </div>`,
    });
    dialog.querySelectorAll('[data-goto-node]').forEach((btn) => btn.addEventListener('click', () => {
      this.switchTab('design');
      this.toolbar.setTab('design');
      this.editStore.select([btn.dataset.gotoNode]);
    }));
  }

  async saveDraft() {
    if (this.editReadOnly) return;
    try {
      await api(`/processes/${this.processId}/versions/${this.editingVersion.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ graph: this.editStore.toJSON() }),
      });
      this.editStore.markClean();
      publish('toast.show', { message: 'Draft saved.' });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async publishVersion() {
    if (this.editReadOnly) return;
    await this.saveDraft();
    const result = validateGraph(this.editStore.graph);
    if (result.errors.length) {
      this.showValidationModal(result);
      publish('toast.show', { message: 'Fix the validation errors before publishing.', tone: 'error' });
      return;
    }
    if (result.warnings.length && !window.confirm(`This version has ${result.warnings.length} warning(s). Publish anyway?`)) {
      return;
    }
    try {
      await api(`/processes/${this.processId}/versions/${this.editingVersion.id}/publish`, { method: 'POST', body: JSON.stringify({}) });
      publish('toast.show', { message: `Published version ${this.editingVersion.version_number}.` });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async createDraft() {
    try {
      await api(`/processes/${this.processId}/versions`, {
        method: 'POST',
        body: JSON.stringify({ fromVersionId: this.publishedVersion?.id }),
      });
      publish('toast.show', { message: 'New draft created.' });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  exportGraph() {
    const blob = new Blob([JSON.stringify(this.editStore.toJSON(), null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${this.process.key_slug}-v${this.editingVersion?.version_number ?? 'draft'}.json`;
    a.click();
    URL.revokeObjectURL(url);
  }

  openImportModal() {
    if (this.editReadOnly) return;
    const { dialog, close } = openModal({
      title: 'Import graph JSON',
      wide: true,
      bodyHtml: `<form class="padded" data-form="import">
        <label>Paste a graph document (or one exported from this designer)<textarea name="json" rows="14" style="font-family:ui-monospace,monospace"></textarea></label>
        <div class="wide"><button type="submit">Load</button></div>
      </form>`,
      focus: 'textarea',
    });
    $('[data-form="import"]', dialog).addEventListener('submit', (e) => {
      e.preventDefault();
      const { json } = formData(e.target);
      try {
        const parsed = normalizeGraph(JSON.parse(json));
        this.editStore.transaction('Import graph', () => {
          this.editStore.graph.nodes = parsed.nodes;
          this.editStore.graph.edges = parsed.edges;
          this.editStore.graph.meta = parsed.meta;
          this.editStore.graph.viewport = parsed.viewport;
        });
        close();
        publish('toast.show', { message: 'Graph imported — remember to Save Draft.' });
      } catch (err) {
        publish('toast.show', { message: `Invalid graph JSON: ${err.message}`, tone: 'error' });
      }
    });
  }

  openRenameModal() {
    const { dialog, close } = openModal({
      title: 'Rename process',
      bodyHtml: `<form class="grid-form padded" data-form="rename">
        <label class="wide">Name<input type="text" name="name" value="${esc(this.process.name)}" required></label>
        <label class="wide">Description<textarea name="description">${esc(this.process.description || '')}</textarea></label>
        <label class="wide">Category<input type="text" name="category" value="${esc(this.process.category || '')}"></label>
        <div class="wide"><button type="submit">Save</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    $('[data-form="rename"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = formData(e.target);
      try {
        await api(`/processes/${this.processId}`, { method: 'PATCH', body: JSON.stringify(data) });
        close();
        await this.load();
        publish('toast.show', { message: 'Process updated.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async viewHistoricalVersion(versionId) {
    const { version } = await api(`/processes/${this.processId}/versions/${versionId}`);
    const store = new ProcessGraphStore(version.graph);
    const { dialog } = openModal({
      title: `Version ${version.version_number} (${version.status})`,
      wide: true,
      bodyHtml: `<div class="proc-history-preview"><pb-process-canvas read-only></pb-process-canvas></div>
        <div class="padded inline-actions"><button type="button" class="small secondary" data-restore>Restore as new draft from this version</button></div>`,
    });
    const canvas = $('pb-process-canvas', dialog);
    canvas.readOnly = true;
    canvas.store = store;
    requestAnimationFrame(() => canvas.fitToScreen());
    $('[data-restore]', dialog)?.addEventListener('click', async () => {
      await api(`/processes/${this.processId}/versions`, { method: 'POST', body: JSON.stringify({ fromVersionId: versionId }) });
      publish('toast.show', { message: 'Restored as a new draft.' });
      dialog.remove();
      await this.load();
      this.switchTab('design');
      this.toolbar.setTab('design');
    });
  }
}
customElements.define('pb-process-designer', ProcessDesignerElement);
