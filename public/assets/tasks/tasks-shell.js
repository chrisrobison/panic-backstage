// <pb-tasks-app> — Tasks (tasks-ui.png): a standalone, ClickUp/Asana-style
// task manager independent of events. This is the shell: left sidebar of
// "task documents" (projects/lists), the selected document's header
// (name/star/status/progress) + view tabs, and the task detail slide-over.
// The four tab bodies and the detail panel are separate custom elements
// (task-list-view.js / task-board-view.js / task-timeline-view.js /
// task-calendar-view.js / task-detail-panel.js) that receive read-only
// `.data` from here and talk back via bubbling CustomEvents
// (`task-open`, `task-close-detail`, `task-changed`, `task-collapse-toggle`)
// — the same "child calls the API itself, parent just reacts to an event"
// shape process-tasks-list.js uses for its step-form modal.
//
// Layout reuses the app's #app.workspace-outbox full-bleed shell mechanism
// (see the CSS block in app.css starting "── Tasks App ──"), same mechanism
// ListMaster (listmaster.js) uses for its own sidebar+main+detail layout.
import { esc, api, publish, formData, openModal, PanicElement, $, $$ } from '../core.js';
import { DOC_STATUSES, docStatusLabel, progressOf, DOCUMENT_ICONS } from './task-shared.js';
import './task-list-view.js';
import './task-board-view.js';
import './task-timeline-view.js';
import './task-calendar-view.js';
import './task-detail-panel.js';

const TABS = [['list', 'Tasks'], ['board', 'Board'], ['timeline', 'Timeline'], ['calendar', 'Calendar']];
const VIEW_TAG = { list: 'pb-task-list-view', board: 'pb-task-board-view', timeline: 'pb-task-timeline-view', calendar: 'pb-task-calendar-view' };

class TasksApp extends PanicElement {
  connect() {
    this._app = document.getElementById('app');
    this._app?.classList.add('workspace-outbox');
    publish('page.context', { title: 'Tasks', blurb: 'Task documents, subtasks, and views for team task tracking.' });

    this.documents = [];
    this.users = [];
    this.selectedDocumentId = this.documentId || null;
    this.document = null;
    this.tasks = [];
    this.activeTab = 'list';
    this.selectedTaskId = null;
    this.collapsed = new Set();
    this.showArchived = false;

    this.renderShell();
    this.bootstrap();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this._app?.classList.remove('workspace-outbox');
  }

  async bootstrap() {
    await this.loadDocuments();
    if (!this.selectedDocumentId && this.documents.length) this.selectedDocumentId = this.documents[0].id;
    this.renderSidebar();
    if (this.selectedDocumentId) await this.loadTasks();
    else this.renderMain();
  }

  async loadDocuments() {
    try {
      const data = await api(`/task-documents${this.showArchived ? '?archived=1' : ''}`);
      this.documents = data.documents || [];
      this.users = data.users || [];
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async loadTasks() {
    this.document = this.documents.find((d) => d.id === this.selectedDocumentId) || null;
    if (!this.selectedDocumentId) {
      this.tasks = [];
      this.renderMain();
      return;
    }
    try {
      const data = await api(`/task-documents/${this.selectedDocumentId}/tasks`);
      this.tasks = data.tasks || [];
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      this.tasks = [];
    }
    this.renderMain();
  }

  /** Re-pull both documents (for sidebar counts/progress) and the active document's tasks. */
  async refreshAll() {
    await this.loadDocuments();
    this.renderSidebar();
    await this.loadTasks();
    if (this.selectedTaskId) this.renderDetail();
  }

  async openDocument(id) {
    if (id === this.selectedDocumentId) return;
    this.selectedDocumentId = id;
    this.selectedTaskId = null;
    this.collapsed = new Set();
    this.renderSidebar();
    this.renderDetail();
    await this.loadTasks();
  }

  // ── Shell chrome ─────────────────────────────────────────────────────────
  renderShell() {
    this.innerHTML = `
      <div class="tk-body">
        <aside class="tk-sidebar">
          <div class="tk-sidebar-head">
            <span class="tk-sidebar-title">Task Documents</span>
            <button type="button" class="tk-icon-btn" data-new-doc title="New document"><i class="fa-solid fa-plus" aria-hidden="true"></i> New</button>
          </div>
          <nav class="tk-doc-nav" data-doc-nav></nav>
          <button type="button" class="tk-archived-toggle" data-toggle-archived><i class="fa-solid fa-box-archive" aria-hidden="true"></i> <span data-archived-label>Show Archived</span></button>
        </aside>
        <div class="tk-main" data-main></div>
        <aside class="tk-detail" data-detail hidden></aside>
      </div>`;
    this.bindShellEvents();
  }

  bindShellEvents() {
    $('[data-new-doc]', this)?.addEventListener('click', () => this.openCreateDocModal());
    $('[data-toggle-archived]', this)?.addEventListener('click', async () => {
      this.showArchived = !this.showArchived;
      const label = $('[data-archived-label]', this);
      if (label) label.textContent = this.showArchived ? 'Hide Archived' : 'Show Archived';
      await this.loadDocuments();
      this.renderSidebar();
    });

    this.addEventListener('task-open', (e) => this.openTask(e.detail.taskId), { signal: this.abort.signal });
    this.addEventListener('task-close-detail', () => this.closeDetail(), { signal: this.abort.signal });
    this.addEventListener('task-changed', () => this.refreshAll(), { signal: this.abort.signal });
    this.addEventListener('task-collapse-toggle', (e) => {
      const id = e.detail.taskId;
      if (this.collapsed.has(id)) this.collapsed.delete(id); else this.collapsed.add(id);
      this.renderMain();
    }, { signal: this.abort.signal });
  }

  renderSidebar() {
    const nav = $('[data-doc-nav]', this);
    if (!nav) return;
    if (!this.documents.length) {
      nav.innerHTML = '<div class="tk-empty-sidebar muted small">No task documents yet.</div>';
      return;
    }
    nav.innerHTML = this.documents.map((d) => {
      const active = d.id === this.selectedDocumentId ? ' active' : '';
      const archived = d.archived_at ? ' tk-doc-item-archived' : '';
      return `<button type="button" class="tk-doc-item${active}${archived}" data-doc-id="${d.id}">
        <i class="${esc(d.icon || 'fa-solid fa-list-check')}" style="color:${esc(d.color || 'var(--blue)')}" aria-hidden="true"></i>
        <span class="tk-doc-name">${esc(d.name)}</span>
        <span class="tk-doc-count">${esc(String(d.task_count ?? 0))}</span>
        ${d.starred ? '<i class="fa-solid fa-star tk-doc-star" aria-hidden="true" title="Starred"></i>' : ''}
      </button>`;
    }).join('');
    $$('.tk-doc-item', nav).forEach((btn) => btn.addEventListener('click', () => this.openDocument(Number(btn.dataset.docId))));
  }

  // ── Main pane: document header + tabs + active view ─────────────────────
  renderMain() {
    const main = $('[data-main]', this);
    if (!main) return;

    if (!this.document) {
      main.innerHTML = this.documents.length
        ? '<div class="empty-state padded">Select a task document from the sidebar.</div>'
        : `<div class="tk-empty-main">
            <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            <h2>No task documents yet</h2>
            <p class="muted">Create one to start tracking work.</p>
            <button type="button" class="button" data-empty-new-doc>+ New Document</button>
          </div>`;
      $('[data-empty-new-doc]', main)?.addEventListener('click', () => this.openCreateDocModal());
      return;
    }

    const doc = this.document;
    const { total, done, pct } = progressOf(this.tasks);

    main.innerHTML = `
      <div class="tk-doc-header">
        <div class="tk-doc-header-top">
          <h1>${esc(doc.name)}</h1>
          <button type="button" class="tk-star-btn${Number(doc.starred) ? ' active' : ''}" data-toggle-star title="Star this document" aria-label="Star this document"><i class="fa-solid fa-star" aria-hidden="true"></i></button>
          <select class="tk-doc-status-select" data-doc-status aria-label="Document status">
            ${DOC_STATUSES.map((s) => `<option value="${s}" ${s === doc.status ? 'selected' : ''}>${esc(docStatusLabel(s))}</option>`).join('')}
          </select>
          <div class="tk-doc-header-actions">
            <button type="button" class="small secondary" data-archive-doc>${doc.archived_at ? 'Unarchive' : 'Archive'}</button>
            <button type="button" class="small danger" data-delete-doc>Delete</button>
          </div>
        </div>
        <div class="tk-doc-progress-row">
          <span class="tk-doc-progress-label">${pct}% Complete</span>
          <div class="tk-progress-bar"><div class="tk-progress-fill" style="width:${pct}%"></div></div>
          <span class="tk-doc-progress-count">${done} / ${total}</span>
        </div>
        <nav class="tk-tabs tabs" data-tabs>
          ${TABS.map(([id, label]) => `<a href="#" class="${this.activeTab === id ? 'active' : ''}" data-tab="${id}">${esc(label)}</a>`).join('')}
        </nav>
      </div>
      <div class="tk-view-wrap" data-view-wrap></div>`;

    $('[data-toggle-star]', main)?.addEventListener('click', () => this.toggleStar());
    $('[data-doc-status]', main)?.addEventListener('change', (e) => this.updateDocStatus(e.target.value));
    $('[data-archive-doc]', main)?.addEventListener('click', () => this.toggleArchiveDoc());
    $('[data-delete-doc]', main)?.addEventListener('click', () => this.deleteDoc());
    $$('[data-tab]', main).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); this.setTab(a.dataset.tab); }));

    this.mountView();
  }

  setTab(tab) {
    if (tab === this.activeTab) return;
    this.activeTab = tab;
    this.renderMain();
  }

  mountView() {
    const wrap = $('[data-view-wrap]', this);
    if (!wrap) return;
    const el = document.createElement(VIEW_TAG[this.activeTab] || VIEW_TAG.list);
    el.data = {
      documentId: this.selectedDocumentId,
      tasks: this.tasks,
      users: this.users,
      collapsed: this.collapsed,
      selectedTaskId: this.selectedTaskId,
    };
    wrap.replaceChildren(el);
  }

  // ── Detail panel ─────────────────────────────────────────────────────────
  openTask(taskId) {
    this.selectedTaskId = taskId;
    this.renderDetail();
  }

  closeDetail() {
    this.selectedTaskId = null;
    this.renderDetail();
  }

  renderDetail() {
    const aside = $('[data-detail]', this);
    if (!aside) return;
    const task = this.selectedTaskId ? this.tasks.find((t) => t.id === this.selectedTaskId) : null;
    if (!task) {
      aside.hidden = true;
      aside.innerHTML = '';
      return;
    }
    aside.hidden = false;
    aside.innerHTML = '<pb-task-detail-panel></pb-task-detail-panel>';
    const panel = $('pb-task-detail-panel', aside);
    panel.data = { documentId: this.selectedDocumentId, task, tasks: this.tasks, users: this.users };
  }

  // ── Document CRUD ─────────────────────────────────────────────────────────
  openCreateDocModal() {
    const defaultIcon = 'fa-solid fa-list-check';
    const iconGrid = DOCUMENT_ICONS.map(([cls, label]) => `<button type="button" class="tk-icon-swatch${cls === defaultIcon ? ' selected' : ''}" data-icon-choice="${esc(cls)}" title="${esc(label)}" aria-label="${esc(label)}"><i class="${esc(cls)}" aria-hidden="true"></i></button>`).join('');

    const { dialog, close } = openModal({
      title: 'New task document',
      bodyHtml: `<form class="grid-form padded" data-form="new-doc">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required placeholder="Q3 Marketing Campaign"></label>
        <div class="wide tk-icon-field">
          <div class="tk-icon-field-head">
            <span class="tk-icon-field-label">Icon</span>
            <span class="tk-icon-preview" data-icon-preview><i class="${esc(defaultIcon)}" aria-hidden="true" data-icon-preview-i></i></span>
          </div>
          <div class="tk-icon-picker" data-icon-picker>${iconGrid}</div>
          <input type="text" name="icon" value="${esc(defaultIcon)}" data-icon-input class="tk-icon-custom-input" placeholder="or type a FontAwesome class, e.g. fa-solid fa-star">
        </div>
        <label>Color<input type="color" name="color" value="#2563eb" data-color-input></label>
        <div class="wide"><button type="submit">Create</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="new-doc"]', dialog);
    const iconInput = $('[data-icon-input]', form);
    const previewIcon = $('[data-icon-preview-i]', form);
    const colorInput = $('[data-color-input]', form);

    const syncPreview = () => {
      previewIcon.className = iconInput.value.trim() || 'fa-solid fa-list-check';
      previewIcon.style.color = colorInput.value;
    };
    const syncSelection = () => {
      $$('[data-icon-choice]', form).forEach((btn) => btn.classList.toggle('selected', btn.dataset.iconChoice === iconInput.value.trim()));
    };
    $$('[data-icon-choice]', form).forEach((btn) => btn.addEventListener('click', () => {
      iconInput.value = btn.dataset.iconChoice;
      syncSelection();
      syncPreview();
    }));
    iconInput.addEventListener('input', () => { syncSelection(); syncPreview(); });
    colorInput.addEventListener('input', syncPreview);
    syncPreview();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = formData(form);
      const name = (fd.name || '').trim();
      if (!name) return;
      try {
        const res = await api('/task-documents', { method: 'POST', body: JSON.stringify(fd) });
        publish('toast.show', { message: `"${name}" created.` });
        close();
        await this.loadDocuments();
        this.renderSidebar();
        await this.openDocument(res.id);
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async toggleStar() {
    const doc = this.document;
    if (!doc) return;
    try {
      await api(`/task-documents/${doc.id}`, { method: 'PATCH', body: JSON.stringify({ starred: Number(doc.starred) ? 0 : 1 }) });
      await this.loadDocuments();
      this.document = this.documents.find((d) => d.id === doc.id) || null;
      this.renderSidebar();
      this.renderMain();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async updateDocStatus(status) {
    if (!this.document) return;
    try {
      await api(`/task-documents/${this.document.id}`, { method: 'PATCH', body: JSON.stringify({ status }) });
      await this.loadDocuments();
      this.document = this.documents.find((d) => d.id === this.selectedDocumentId) || null;
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async toggleArchiveDoc() {
    const doc = this.document;
    if (!doc) return;
    const archiving = !doc.archived_at;
    if (archiving && !window.confirm(`Archive "${doc.name}"? It will be hidden from the sidebar unless "Show Archived" is on.`)) return;
    try {
      await api(`/task-documents/${doc.id}`, { method: 'PATCH', body: JSON.stringify({ archived: archiving ? 1 : 0 }) });
      publish('toast.show', { message: archiving ? 'Document archived.' : 'Document restored.' });
      await this.loadDocuments();
      if (archiving && !this.showArchived) {
        this.selectedDocumentId = this.documents[0]?.id || null;
        this.selectedTaskId = null;
        this.renderSidebar();
        this.renderDetail();
        await this.loadTasks();
      } else {
        this.document = this.documents.find((d) => d.id === doc.id) || null;
        this.renderSidebar();
        this.renderMain();
      }
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async deleteDoc() {
    const doc = this.document;
    if (!doc) return;
    if (!window.confirm(`Delete "${doc.name}" and all of its tasks? This can't be undone.`)) return;
    try {
      await api(`/task-documents/${doc.id}`, { method: 'DELETE' });
      publish('toast.show', { message: `Deleted "${doc.name}".` });
      await this.loadDocuments();
      this.selectedDocumentId = this.documents[0]?.id || null;
      this.selectedTaskId = null;
      this.renderSidebar();
      this.renderDetail();
      await this.loadTasks();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}
customElements.define('pb-tasks-app', TasksApp);
