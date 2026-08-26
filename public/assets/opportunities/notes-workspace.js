// <pb-opportunities-notes> — Opportunities > Notes (Phase 6: First-class
// Research Notes workspace, docs/opportunity-ui/opportunity-6.png).
//
// Three panes when screen width permits (spec: "Note list / Editor /
// Context-AI panel"): a filterable cross-cutting note list (backed by the
// new Notes::generalIndex(), Phase 6), a lightweight Markdown editor with a
// controlled toolbar (never a WYSIWYG/contenteditable dependency — spec's
// explicit fallback), and a context pane showing the note's resolved linked
// records (AI-extracted metadata is honestly deferred to Phase 7, same
// "Planned" pattern every other AI-shaped panel in this module already
// uses).
//
// Markdown body is rendered read-only through the existing, already-escaped
// `mdToHtml()` helper (core.js) — the same one event descriptions use — so
// this workspace never introduces a second unsanitized-HTML rendering path.
// `esc()` still guards every other piece of note-adjacent text (author
// names, tags, context labels).
import {
  esc, api, emptyState, openModal, formData, publish, subscribe, mdToHtml,
  getAppCapabilities, PanicElement, $, $$,
} from '../core.js';
import { noteTypeLabel, NOTE_TYPES, relativeTime, shortMonthDay, debounce } from './shared.js';

const LINKED_TYPE_LABELS = { conference: 'Conference', company: 'Company', contact: 'Contact', opportunity: 'Opportunity' };
const LINKED_TYPE_ICONS  = { conference: 'fa-calendar-days', company: 'fa-building', contact: 'fa-address-card', opportunity: 'fa-briefcase' };
// Which linked-record types can own a lazily-provisioned Tasks link (see
// src/Opportunities/TaskLink.php) — contacts can't (there's no
// opportunity_contacts.task_document_id column, and a "task on a person"
// isn't the pattern this module uses anywhere).
const TASK_OWNER_ROUTE = { conference: 'opportunity-conferences', company: 'opportunity-companies', opportunity: 'opportunities' };

function stripMarkdown(text) {
  return String(text || '')
    .replace(/^#{1,3}\s+/gm, '')
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/\*(.+?)\*/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/^[-*]\s+(\[[ xX]\]\s+)?/gm, '')
    .replace(/\n+/g, ' ')
    .trim();
}

function blankEditorState() {
  return { body: '', note_type: 'general', is_pinned: false, tags: [], links: [] };
}

class OpportunitiesNotesWorkspace extends PanicElement {
  async connect() {
    this.canManage = !!getAppCapabilities().manage_opportunities;
    publish('page.context', { title: 'Opportunities › Notes', blurb: 'The research and knowledge layer across every conference, company, contact, and opportunity.' });

    this.filters = { q: '', note_type: '', linked_type: '', author_id: '', pinned: '', ai: '', tag: '' };
    this.notes = [];
    this.authors = [];
    this.selectedNoteId = null; // number | 'new' | null
    this.editorState = blankEditorState();
    this.showPreview = false;
    this.versions = null; // loaded lazily when the History panel opens

    this.reloadDebounced = debounce(() => this.loadList(), 300);
    // Phase 8: opportunity_notes (+ its links/tags/versions) are now their
    // own mapped RealtimeInvalidationMapper entity ('opportunity_note'),
    // rather than only ever falling through to 'global' — see
    // src/RealtimeInvalidationMapper.php. Still also reload on 'opportunity'
    // (a linked opportunity's own name/stage may have changed) and 'global'
    // as a safety net for anything not yet mapped.
    subscribe('data.invalidated', (msg) => {
      if (msg.entity === 'opportunity_note' || msg.entity === 'opportunity' || msg.entity === 'global') this.reloadDebounced();
    }, this.abort.signal);

    await Promise.all([this.loadList(), this.loadLinkPickerSources()]);

    if (this.initialNoteId) {
      await this.selectNote(this.initialNoteId);
    } else {
      this.render();
    }
  }

  async loadList() {
    try {
      const query = new URLSearchParams();
      if (this.filters.q) query.set('q', this.filters.q);
      if (this.filters.note_type) query.set('note_type', this.filters.note_type);
      if (this.filters.linked_type) query.set('linked_type', this.filters.linked_type);
      if (this.filters.author_id) query.set('created_by', this.filters.author_id);
      if (this.filters.pinned !== '') query.set('is_pinned', this.filters.pinned);
      if (this.filters.ai !== '') query.set('is_ai_generated', this.filters.ai);
      if (this.filters.tag) query.set('tag', this.filters.tag);
      const data = await api(`/opportunity-notes?${query.toString()}`);
      this.notes = data.notes || [];
      this.authors = data.authors || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  // One-time fetch of pickable records for the "+ Add Link" search (client-
  // filtered thereafter — same tradeoff pipeline-board.js's own filters make
  // over its single fetched page; Conferences/Companies already cap at 200
  // server-side, Opportunities likewise, so this is bounded, not unbounded).
  async loadLinkPickerSources() {
    try {
      const [confRes, coRes, oppRes] = await Promise.all([
        api('/opportunity-conferences'),
        api('/opportunity-companies'),
        api('/opportunities'),
      ]);
      this.pickerConferences = confRes.conferences || [];
      this.pickerCompanies = coRes.companies || [];
      this.pickerOpportunities = oppRes.opportunities || [];
    } catch {
      this.pickerConferences = []; this.pickerCompanies = []; this.pickerOpportunities = [];
    }
  }

  async selectNote(id) {
    if (id === 'new') {
      this.selectedNoteId = 'new';
      this.editorState = blankEditorState();
      this.versions = null;
      this.showPreview = false;
      this.render();
      return;
    }
    try {
      const res = await api(`/opportunity-notes/${id}`);
      this.selectedNoteId = res.note.id;
      this.editorState = {
        body: res.note.body,
        note_type: res.note.note_type,
        is_pinned: !!res.note.is_pinned,
        tags: res.note.tags || [],
        links: res.note.contexts && res.note.contexts.length ? res.note.contexts : (res.note.links || []).map((l) => ({ ...l, label: null })),
      };
      this.currentNote = res.note;
      this.versions = null;
      this.showPreview = false;
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not load that note.', tone: 'error' });
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────

  render() {
    this.innerHTML = `
      <div class="page-head">
        <div><h1>Notes</h1><p class="subtle">What do we know? Where did we learn it? What should happen next?</p></div>
        ${this.canManage ? '<button type="button" class="button primary" data-new-note">+ New Note</button>' : ''}
      </div>
      <div class="opp-notes-workspace">
        <section class="opp-notes-list-pane panel">
          ${this.filterBarHtml()}
          <div class="opp-notes-list">${this.noteListItemsHtml()}</div>
        </section>
        <section class="opp-notes-editor-pane panel padded">
          ${this.editorHtml()}
        </section>
        <section class="opp-notes-context-pane panel padded">
          ${this.contextPaneHtml()}
        </section>
      </div>`;
    this.bind();
  }

  filterBarHtml() {
    const f = this.filters;
    return `<div class="opp-notes-filters">
      <input type="search" placeholder="Search note text…" data-f-q value="${esc(f.q)}">
      <select data-f-type><option value="">All Types</option>${NOTE_TYPES.map((t) => `<option value="${t}" ${f.note_type === t ? 'selected' : ''}>${esc(noteTypeLabel(t))}</option>`).join('')}</select>
      <select data-f-linked><option value="">Any Record</option>${Object.entries(LINKED_TYPE_LABELS).map(([v, l]) => `<option value="${v}" ${f.linked_type === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
      <select data-f-author><option value="">Any Author</option>${this.authors.map((a) => `<option value="${esc(a.id)}" ${f.author_id === String(a.id) ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select>
      <input type="text" placeholder="Tag" data-f-tag value="${esc(f.tag)}" style="max-width:110px">
      <label class="opp-inline-check"><input type="checkbox" data-f-pinned ${f.pinned === '1' ? 'checked' : ''}> Pinned</label>
      <label class="opp-inline-check"><input type="checkbox" data-f-ai ${f.ai === '1' ? 'checked' : ''}> AI-generated</label>
      <button type="button" class="button secondary small" data-f-clear>Clear</button>
    </div>`;
  }

  noteListItemsHtml() {
    if (!this.notes.length) {
      return emptyState('No notes match these filters.');
    }
    return this.notes.map((n) => {
      const contexts = n.contexts || [];
      return `<button type="button" class="opp-notes-list-item${this.selectedNoteId === n.id ? ' active' : ''}" data-select-note="${esc(n.id)}">
        <div class="opp-notes-list-item-head">
          <span class="badge">${esc(noteTypeLabel(n.note_type))}</span>
          ${n.is_pinned ? '<i class="fa-solid fa-thumbtack" title="Pinned" aria-hidden="true"></i>' : ''}
          ${n.is_ai_generated ? '<span class="badge info">AI</span>' : ''}
        </div>
        ${contexts.length ? `<div class="opp-notes-list-contexts">${contexts.map((c) => `<span class="pill"><i class="fa-solid ${LINKED_TYPE_ICONS[c.type] || 'fa-link'}" aria-hidden="true"></i> ${esc(c.label)}</span>`).join('')}</div>` : ''}
        <p class="opp-notes-list-preview">${esc(stripMarkdown(n.body).slice(0, 140))}</p>
        <small class="muted">${n.created_by_name ? esc(n.created_by_name) + ' &middot; ' : ''}${esc(relativeTime(n.created_at))}</small>
      </button>`;
    }).join('');
  }

  editorHtml() {
    if (!this.selectedNoteId) {
      return `<div class="opp-notes-empty">
        <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
        <p>Select a note from the list, or start a new one.</p>
        ${this.canManage ? '<button type="button" class="button primary" data-new-note>+ New Note</button>' : ''}
      </div>`;
    }

    const s = this.editorState;
    const isNew = this.selectedNoteId === 'new';
    return `
      <div class="opp-notes-editor-head">
        <select data-note-type ${this.canManage ? '' : 'disabled'}>${NOTE_TYPES.map((t) => `<option value="${t}" ${s.note_type === t ? 'selected' : ''}>${esc(noteTypeLabel(t))}</option>`).join('')}</select>
        <label class="opp-inline-check"><input type="checkbox" data-is-pinned ${s.is_pinned ? 'checked' : ''} ${this.canManage ? '' : 'disabled'}> Pinned</label>
        <div class="opp-notes-editor-head-actions">
          ${!isNew ? `<button type="button" class="button secondary small" data-view-history>History</button>` : ''}
          ${this.canManage && !isNew ? '<button type="button" class="button danger small" data-delete-note>Delete</button>' : ''}
        </div>
      </div>
      <div class="opp-notes-link-chips">
        ${s.links.map((l, i) => `<span class="pill opp-notes-link-chip"><i class="fa-solid ${LINKED_TYPE_ICONS[l.type] || 'fa-link'}" aria-hidden="true"></i> ${esc(l.label || `${LINKED_TYPE_LABELS[l.type]} #${l.id}`)}${this.canManage ? ` <button type="button" data-remove-link="${i}" aria-label="Unlink">&times;</button>` : ''}</span>`).join('')}
        ${this.canManage ? '<button type="button" class="button secondary small" data-add-link>+ Add Link</button>' : ''}
      </div>
      ${this.canManage ? `<div class="opp-notes-toolbar" role="toolbar" aria-label="Formatting">
        <button type="button" data-md="bold" title="Bold"><i class="fa-solid fa-bold" aria-hidden="true"></i></button>
        <button type="button" data-md="italic" title="Italic"><i class="fa-solid fa-italic" aria-hidden="true"></i></button>
        <button type="button" data-md="h2" title="Heading"><i class="fa-solid fa-heading" aria-hidden="true"></i></button>
        <button type="button" data-md="ul" title="Bulleted list"><i class="fa-solid fa-list-ul" aria-hidden="true"></i></button>
        <button type="button" data-md="ol" title="Numbered list"><i class="fa-solid fa-list-ol" aria-hidden="true"></i></button>
        <button type="button" data-md="checklist" title="Checklist"><i class="fa-solid fa-list-check" aria-hidden="true"></i></button>
        <button type="button" data-md="link" title="Link"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
        <span class="opp-notes-toolbar-spacer"></span>
        <button type="button" class="${this.showPreview ? '' : 'active'}" data-toggle-preview="edit">Edit</button>
        <button type="button" class="${this.showPreview ? 'active' : ''}" data-toggle-preview="preview">Preview</button>
      </div>` : ''}
      ${this.showPreview
        ? `<div class="opp-notes-preview">${mdToHtml(s.body) || '<p class="muted">Nothing written yet.</p>'}</div>`
        : `<textarea class="opp-notes-textarea" data-note-body placeholder="Use @ to mention, # to tag… Markdown supported: **bold**, *italic*, # heading, - list, - [ ] checklist, [text](url)" ${this.canManage ? '' : 'readonly'}>${esc(s.body)}</textarea>`}
      <label class="opp-notes-tags-field">Tags <input type="text" data-note-tags placeholder="comma, separated, tags" value="${esc((s.tags || []).join(', '))}" ${this.canManage ? '' : 'readonly'}></label>
      ${this.canManage ? `<div class="opp-notes-editor-actions">
        <button type="button" class="button primary" data-save-note>${isNew ? 'Create Note' : 'Save'}</button>
        <button type="button" class="button secondary" data-create-task ${this.taskEligibleLinks().length ? '' : 'disabled'}>Create Task</button>
        <button type="button" class="button secondary" data-create-opportunity ${s.links.some((l) => l.type === 'company') ? '' : 'disabled'}>Create Opportunity</button>
      </div>` : ''}
      ${this.versions ? this.historyPanelHtml() : ''}`;
  }

  taskEligibleLinks() {
    return this.editorState.links.filter((l) => TASK_OWNER_ROUTE[l.type]);
  }

  historyPanelHtml() {
    if (!this.versions.length) {
      return `<div class="opp-notes-history"><div class="section-head"><h2>Version History</h2><button type="button" class="small secondary" data-close-history">Close</button></div><p class="muted">No prior revisions — this note hasn't been edited yet.</p></div>`;
    }
    return `<div class="opp-notes-history">
      <div class="section-head"><h2>Version History</h2><button type="button" class="small secondary" data-close-history">Close</button></div>
      <ul class="opp-notes-version-list">
        ${this.versions.map((v, i) => `<li class="opp-notes-version-item">
          <div class="opp-notes-version-head">
            <strong>${v.edited_by_name ? esc(v.edited_by_name) : 'Unknown'}</strong>
            <small class="muted">${esc(relativeTime(v.edited_at))}</small>
            ${this.canManage ? `<button type="button" class="small secondary" data-restore-version="${i}">Restore this version</button>` : ''}
          </div>
          <p class="opp-notes-version-body">${esc(stripMarkdown(v.body).slice(0, 220))}</p>
        </li>`).join('')}
      </ul>
    </div>`;
  }

  contextPaneHtml() {
    if (!this.selectedNoteId) {
      return `<div class="section-head"><h2>Context</h2></div><p class="muted">Select a note to see its linked records here.</p>`;
    }
    const links = this.editorState.links;
    return `
      <div class="section-head"><h2>Linked Records</h2></div>
      ${links.length ? `<ul class="opp-notes-context-list">${links.map((l) => `<li class="opp-notes-context-item">
          <i class="fa-solid ${LINKED_TYPE_ICONS[l.type] || 'fa-link'}" aria-hidden="true"></i>
          <div>
            <span class="muted">${esc(LINKED_TYPE_LABELS[l.type] || l.type)}</span>
            ${l.label ? `<strong><a href="${esc(this.linkHref(l))}">${esc(l.label)}</a></strong>` : `<strong class="muted">#${esc(l.id)}</strong>`}
          </div>
        </li>`).join('')}</ul>` : '<p class="muted">No linked records yet — use "+ Add Link" in the editor.</p>'}
      <div class="panel padded opp-notes-ai-panel">
        <span class="pill pill-muted">Planned — not yet built</span>
        <p class="muted small">AI-assisted summaries, extracted facts, and suggested next actions from this note's content arrive in Phase 7. For now, this pane shows exactly what's linked — nothing inferred.</p>
      </div>`;
  }

  linkHref(l) {
    if (l.type === 'conference') return `#opportunities-conference-${l.id}`;
    if (l.type === 'company') return `#opportunities-company-${l.id}`;
    if (l.type === 'opportunity') return `#opportunities-${l.id}`;
    return '#opportunities-notes';
  }

  // ── Wiring ───────────────────────────────────────────────────────────────

  bind() {
    $('[data-new-note]', this)?.addEventListener('click', () => this.selectNote('new'));
    $$('[data-select-note]', this).forEach((btn) => btn.addEventListener('click', () => this.selectNote(Number(btn.dataset.selectNote))));

    $('[data-f-q]', this)?.addEventListener('input', debounce((e) => { this.filters.q = e.target.value; this.loadList(); }, 300));
    $('[data-f-type]', this)?.addEventListener('change', (e) => { this.filters.note_type = e.target.value; this.loadList(); });
    $('[data-f-linked]', this)?.addEventListener('change', (e) => { this.filters.linked_type = e.target.value; this.loadList(); });
    $('[data-f-author]', this)?.addEventListener('change', (e) => { this.filters.author_id = e.target.value; this.loadList(); });
    $('[data-f-tag]', this)?.addEventListener('input', debounce((e) => { this.filters.tag = e.target.value; this.loadList(); }, 300));
    $('[data-f-pinned]', this)?.addEventListener('change', (e) => { this.filters.pinned = e.target.checked ? '1' : ''; this.loadList(); });
    $('[data-f-ai]', this)?.addEventListener('change', (e) => { this.filters.ai = e.target.checked ? '1' : ''; this.loadList(); });
    $('[data-f-clear]', this)?.addEventListener('click', () => {
      this.filters = { q: '', note_type: '', linked_type: '', author_id: '', pinned: '', ai: '', tag: '' };
      this.loadList();
    });

    const bodyField = $('[data-note-body]', this);
    bodyField?.addEventListener('input', (e) => { this.editorState.body = e.target.value; });
    $('[data-note-type]', this)?.addEventListener('change', (e) => { this.editorState.note_type = e.target.value; });
    $('[data-is-pinned]', this)?.addEventListener('change', (e) => { this.editorState.is_pinned = e.target.checked; });
    $('[data-note-tags]', this)?.addEventListener('input', (e) => {
      this.editorState.tags = e.target.value.split(',').map((t) => t.trim()).filter(Boolean);
    });

    $$('[data-toggle-preview]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.showPreview = btn.dataset.togglePreview === 'preview';
      this.render();
    }));

    $$('[data-md]', this).forEach((btn) => btn.addEventListener('click', () => this.applyMarkdown(btn.dataset.md)));

    $('[data-save-note]', this)?.addEventListener('click', () => this.saveNote());
    $('[data-delete-note]', this)?.addEventListener('click', () => this.deleteNote());
    $('[data-add-link]', this)?.addEventListener('click', () => this.openAddLinkModal());
    $$('[data-remove-link]', this).forEach((btn) => btn.addEventListener('click', () => this.removeLink(Number(btn.dataset.removeLink))));
    $('[data-view-history]', this)?.addEventListener('click', () => this.toggleHistory());
    $('[data-close-history]', this)?.addEventListener('click', () => { this.versions = null; this.render(); });
    $$('[data-restore-version]', this).forEach((btn) => btn.addEventListener('click', () => {
      const v = this.versions[Number(btn.dataset.restoreVersion)];
      if (!v) return;
      this.editorState.body = v.body;
      this.showPreview = false;
      this.versions = null;
      this.render();
      publish('toast.show', { message: 'Version loaded into the editor — click Save to keep it.' });
    }));
    $('[data-create-task]', this)?.addEventListener('click', () => this.openCreateTaskModal());
    $('[data-create-opportunity]', this)?.addEventListener('click', () => this.openCreateOpportunityModal());
  }

  applyMarkdown(kind) {
    const el = $('[data-note-body]', this);
    if (!el) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = el.value.slice(start, end);
    let insert = selected;
    let cursorOffset = 0;

    if (kind === 'bold') { insert = `**${selected || 'bold text'}**`; cursorOffset = selected ? 0 : -2; }
    else if (kind === 'italic') { insert = `*${selected || 'italic text'}*`; cursorOffset = selected ? 0 : -1; }
    else if (kind === 'h2') { insert = `## ${selected || 'Heading'}`; }
    else if (kind === 'ul') { insert = (selected || 'List item').split('\n').map((l) => `- ${l}`).join('\n'); }
    else if (kind === 'ol') { insert = (selected || 'List item').split('\n').map((l, i) => `${i + 1}. ${l}`).join('\n'); }
    else if (kind === 'checklist') { insert = (selected || 'To do').split('\n').map((l) => `- [ ] ${l}`).join('\n'); }
    else if (kind === 'link') { insert = `[${selected || 'link text'}](https://)`; }

    el.value = el.value.slice(0, start) + insert + el.value.slice(end);
    this.editorState.body = el.value;
    const newPos = start + insert.length + cursorOffset;
    el.focus();
    el.setSelectionRange(newPos, newPos);
  }

  async saveNote() {
    const s = this.editorState;
    if (!s.body.trim()) {
      publish('toast.show', { message: 'A note needs some text.', tone: 'error' });
      return;
    }
    try {
      if (this.selectedNoteId === 'new') {
        if (!s.links.length) {
          publish('toast.show', { message: 'Link this note to at least one record first (use "+ Add Link").', tone: 'error' });
          return;
        }
        const [primary, ...rest] = s.links;
        const res = await api('/opportunity-notes', {
          method: 'POST',
          body: JSON.stringify({
            body: s.body, note_type: s.note_type, is_pinned: s.is_pinned, tags: s.tags,
            linked_type: primary.type, linked_id: primary.id,
            additional_links: rest.map((l) => ({ type: l.type, id: l.id })),
          }),
        });
        publish('toast.show', { message: 'Note created.' });
        await this.loadList();
        await this.selectNote(res.note.id);
      } else {
        await api(`/opportunity-notes/${this.selectedNoteId}`, {
          method: 'PATCH',
          body: JSON.stringify({ body: s.body, note_type: s.note_type, is_pinned: s.is_pinned, tags: s.tags }),
        });
        publish('toast.show', { message: 'Note saved.' });
        await this.loadList();
        await this.selectNote(this.selectedNoteId);
      }
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not save this note.', tone: 'error' });
    }
  }

  async deleteNote() {
    if (typeof this.selectedNoteId !== 'number') return;
    if (!confirm('Delete this note? This cannot be undone.')) return;
    try {
      await api(`/opportunity-notes/${this.selectedNoteId}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Note deleted.' });
      this.selectedNoteId = null;
      this.editorState = blankEditorState();
      await this.loadList();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async removeLink(index) {
    const link = this.editorState.links[index];
    if (!link) return;
    if (this.selectedNoteId === 'new') {
      this.editorState.links.splice(index, 1);
      this.render();
      return;
    }
    try {
      await api(`/opportunity-notes/${this.selectedNoteId}`, { method: 'PATCH', body: JSON.stringify({ remove_links: [{ type: link.type, id: link.id }] }) });
      await this.selectNote(this.selectedNoteId);
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async addLink(link) {
    if (this.editorState.links.some((l) => l.type === link.type && l.id === link.id)) {
      publish('toast.show', { message: 'Already linked.', tone: 'error' });
      return;
    }
    if (this.selectedNoteId === 'new') {
      this.editorState.links.push(link);
      this.render();
      return;
    }
    try {
      await api(`/opportunity-notes/${this.selectedNoteId}`, { method: 'PATCH', body: JSON.stringify({ add_links: [{ type: link.type, id: link.id }] }) });
      await this.selectNote(this.selectedNoteId);
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async toggleHistory() {
    if (this.versions) { this.versions = null; this.render(); return; }
    try {
      const res = await api(`/opportunity-notes/${this.selectedNoteId}/versions`);
      this.versions = res.versions || [];
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Modals ───────────────────────────────────────────────────────────────

  openAddLinkModal() {
    const { dialog, close } = openModal({
      title: 'Add Link',
      wide: true,
      bodyHtml: `<div class="grid-form padded">
        <label class="wide">Record type
          <select data-link-type>
            ${Object.entries(LINKED_TYPE_LABELS).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}
          </select>
        </label>
        <label class="wide">Search <input type="text" data-link-search placeholder="Start typing a name…" autocomplete="off"></label>
        <div class="wide opp-company-results" data-link-results></div>
      </div>`,
    });
    const typeSelect = $('[data-link-type]', dialog);
    const searchInput = $('[data-link-search]', dialog);
    const resultsBox = $('[data-link-results]', dialog);

    const runSearch = async () => {
      const type = typeSelect.value;
      const q = searchInput.value.trim().toLowerCase();
      let candidates = [];
      if (type === 'conference') {
        candidates = this.pickerConferences.map((c) => ({ type: 'conference', id: c.id, label: c.name }));
      } else if (type === 'company') {
        candidates = this.pickerCompanies.map((c) => ({ type: 'company', id: c.id, label: c.name }));
      } else if (type === 'opportunity') {
        candidates = this.pickerOpportunities.map((o) => ({ type: 'opportunity', id: o.id, label: `${o.name} (${o.company_name})` }));
      } else if (type === 'contact') {
        const companyLink = this.editorState.links.find((l) => l.type === 'company');
        if (!companyLink) {
          resultsBox.innerHTML = '<p class="muted small">Link a company first — contacts are searched within one company.</p>';
          return;
        }
        try {
          const res = await api(`/opportunity-companies/${companyLink.id}/contacts`);
          candidates = (res.contacts || []).map((c) => ({ type: 'contact', id: c.id, label: c.name }));
        } catch { candidates = []; }
      }
      const filtered = q ? candidates.filter((c) => c.label.toLowerCase().includes(q)) : candidates;
      resultsBox.innerHTML = filtered.slice(0, 20).map((c) => `<button type="button" class="opp-company-result" data-pick-link data-type="${c.type}" data-id="${c.id}" data-label="${esc(c.label)}">${esc(c.label)}</button>`).join('') || '<p class="muted small">No matches.</p>';
      $$('[data-pick-link]', resultsBox).forEach((btn) => btn.addEventListener('click', async () => {
        await this.addLink({ type: btn.dataset.type, id: Number(btn.dataset.id), label: btn.dataset.label });
        close();
      }));
    };

    typeSelect.addEventListener('change', runSearch);
    searchInput.addEventListener('input', debounce(runSearch, 200));
    runSearch();
  }

  openCreateTaskModal() {
    const eligible = this.taskEligibleLinks();
    if (!eligible.length) return;
    const { dialog, close } = openModal({
      title: 'Create Task',
      bodyHtml: `<form class="grid-form padded" data-form="note-task-form">
        ${eligible.length > 1 ? `<label class="wide">On <select name="link_index">${eligible.map((l, i) => `<option value="${i}">${esc(LINKED_TYPE_LABELS[l.type])}: ${esc(l.label || '#' + l.id)}</option>`).join('')}</select></label>` : `<input type="hidden" name="link_index" value="0">`}
        <label class="wide">Task <span class="req">*</span><input type="text" name="title" required placeholder="e.g. Follow up on budget"></label>
        <div class="wide"><button type="submit" class="primary">Create Task</button></div>
      </form>`,
      focus: '[name="title"]',
    });
    const form = $('[data-form="note-task-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      const link = eligible[Number(body.link_index || 0)];
      const route = TASK_OWNER_ROUTE[link.type];
      try {
        const ensured = await api(`/${route}/${link.id}/tasks`, { method: 'POST' });
        await api(`/task-documents/${ensured.task_document_id}/tasks`, { method: 'POST', body: JSON.stringify({ title: body.title }) });
        publish('toast.show', { message: 'Task created.' });
        close();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  openCreateOpportunityModal() {
    const companyLink = this.editorState.links.find((l) => l.type === 'company');
    if (!companyLink) return;
    const conferenceLink = this.editorState.links.find((l) => l.type === 'conference');
    const { dialog, close } = openModal({
      title: `Create Opportunity — ${companyLink.label || 'Company'}`,
      bodyHtml: `<form class="grid-form padded" data-form="note-opportunity-form">
        <label class="wide">Opportunity name <span class="req">*</span><input type="text" name="name" required value="${esc(companyLink.label || '')} Opportunity"></label>
        <label>Estimated value <input type="number" min="0" step="0.01" name="estimated_value"></label>
        <label>Probability % <input type="number" min="0" max="100" name="probability"></label>
        <div class="wide"><button type="submit" class="primary">Create Opportunity</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="note-opportunity-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      body.company_id = companyLink.id;
      if (conferenceLink) body.conference_id = conferenceLink.id;
      try {
        const res = await api('/opportunities', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${res.opportunity.name} created and linked to this note.` });
        close();
        await this.addLink({ type: 'opportunity', id: res.opportunity.id, label: res.opportunity.name });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}
customElements.define('pb-opportunities-notes', OpportunitiesNotesWorkspace);
