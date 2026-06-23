// ── Event Execution Records panel ────────────────────────────────────────────
// Displays, creates, edits, and deletes execution records (incidents, change
// orders, bar notes, damage reports, etc.) for a specific event.

import { esc, titleCase, api, publish, can, emptyState, money, shortDate, PanicElement, $, $$ } from './core.js';

// ── Type → Font Awesome 6 solid icon ─────────────────────────────────────────
const TYPE_ICON = {
  incident:      'fa-triangle-exclamation',
  change_order:  'fa-pen-to-square',
  bar_note:      'fa-martini-glass-citrus',
  damage:        'fa-hammer',
  overage:       'fa-circle-plus',
  checklist:     'fa-list-check',
  deviation:     'fa-route',
  safety_note:   'fa-shield-halved',
  general:       'fa-note-sticky',
};

// ── Type → accent color ───────────────────────────────────────────────────────
const TYPE_COLOR = {
  incident:      '#dc3545',
  change_order:  '#0d6efd',
  bar_note:      '#20c997',
  damage:        '#fd7e14',
  overage:       '#6f42c1',
  checklist:     '#198754',
  deviation:     '#ffc107',
  safety_note:   '#0dcaf0',
  general:       '#6c757d',
};

// ── Filter tabs ───────────────────────────────────────────────────────────────
// Each entry: { key, label, types? }  — types===undefined means "All"
const FILTER_TABS = [
  { key: 'all',          label: 'All' },
  { key: 'change_order', label: 'Change Orders', types: ['change_order'] },
  { key: 'bar_note',     label: 'Bar Notes',     types: ['bar_note'] },
  { key: 'damage',       label: 'Damage',        types: ['damage'] },
  { key: 'incident',     label: 'Incidents',     types: ['incident'], restricted: true },
  { key: 'other',        label: 'Other',         types: ['overage','checklist','deviation','safety_note','general'] },
];

// All selectable record types (incident shown only to privileged users)
const ALL_RECORD_TYPES = [
  'general', 'change_order', 'bar_note', 'damage',
  'overage', 'checklist', 'deviation', 'safety_note', 'incident',
];

// ── CSS injected once into <head> ─────────────────────────────────────────────
const EXEC_STYLES = `
.exec-record { border-left: 4px solid #6c757d; padding: 0.75rem 1rem; margin-bottom: 0.5rem; background: var(--panel-bg, #fff); border-radius: 0 4px 4px 0; }
.exec-record.record-incident     { border-left-color: #dc3545; }
.exec-record.record-change_order { border-left-color: #0d6efd; }
.exec-record.record-bar_note     { border-left-color: #20c997; }
.exec-record.record-damage       { border-left-color: #fd7e14; }
.exec-record.record-overage      { border-left-color: #6f42c1; }
.exec-record.record-checklist    { border-left-color: #198754; }
.exec-record.record-deviation    { border-left-color: #ffc107; }
.exec-record.record-safety_note  { border-left-color: #0dcaf0; }
.exec-record .exec-body { display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer; }
.exec-record .exec-body.expanded { display:block; }
.exec-record-head { display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.25rem; }
.exec-record-foot { display:flex;gap:1rem;align-items:center;margin-top:0.5rem;font-size:0.85em;color:#666; }
.exec-record-edit-form { margin-top:0.5rem;display:flex;flex-direction:column;gap:0.4rem; }
.exec-record-edit-form input[type=text],
.exec-record-edit-form textarea,
.exec-record-edit-form input[type=number] { width:100%;box-sizing:border-box; }
.exec-record-edit-actions { display:flex;gap:0.5rem;margin-top:0.25rem; }
.exec-resolved-badge { display:inline-flex;align-items:center;gap:4px;color:#198754;font-weight:600;font-size:0.85em; }
.exec-resolve-form { margin-top:0.5rem;display:flex;flex-direction:column;gap:0.4rem;width:100%; }
.exec-resolve-form textarea { width:100%;box-sizing:border-box; }
`;

function injectStyles() {
  if (document.getElementById('pb-exec-styles')) return;
  const style = document.createElement('style');
  style.id = 'pb-exec-styles';
  style.textContent = EXEC_STYLES;
  document.head.appendChild(style);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(String(dateStr).replace(' ', 'T'));
  if (isNaN(d.getTime())) return esc(dateStr);
  const diff = Date.now() - d.getTime();
  const mins  = Math.floor(diff / 60000);
  if (mins < 2)   return 'just now';
  if (mins < 60)  return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs  < 24)  return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 7)   return `${days}d ago`;
  return shortDate(d);
}

function typeBadge(type) {
  const color = TYPE_COLOR[type] || '#6c757d';
  const icon  = TYPE_ICON[type]  || 'fa-note-sticky';
  const label = esc(titleCase(type));
  return `<span class="badge" style="background:${color};color:#fff;font-size:0.75em;padding:2px 7px;border-radius:3px;display:inline-flex;align-items:center;gap:4px;"><i class="fa-solid ${icon}" aria-hidden="true"></i>${label}</span>`;
}

// Render resolved badge or resolve button placeholder for incident records
function resolvedHtml(rec) {
  if (rec.record_type !== 'incident' && !Number(rec.is_restricted)) return '';
  if (rec.resolved_at) {
    return `<span class="exec-resolved-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Resolved ${esc(shortDate(new Date(String(rec.resolved_at).replace(' ','T'))))}</span>`;
  }
  return `<span data-exec-resolve-slot></span>`;
}

// Render one record card (view mode)
function renderCard(rec) {
  const icon    = TYPE_ICON[rec.record_type]  || 'fa-note-sticky';
  const lock    = Number(rec.is_restricted) ? ' <i class="fa-solid fa-lock" title="Restricted" aria-label="Restricted"></i>' : '';
  const ago     = timeAgo(rec.created_at);
  const amtHtml = Number(rec.amount) ? `<span>Amount: ${esc(money(Number(rec.amount)))}</span>` : '';
  const bodyHtml = rec.body ? `<p class="exec-body">${esc(rec.body)}</p>` : '';

  return `<article class="exec-record record-${esc(rec.record_type)}" data-exec-id="${esc(String(rec.id))}">
    <div class="exec-record-head">
      <span style="display:flex;align-items:center;gap:0.4rem;">
        <i class="fa-solid ${icon}" aria-hidden="true" style="color:${TYPE_COLOR[rec.record_type] || '#6c757d'}"></i>
        ${typeBadge(rec.record_type)}${lock}
        <strong>${esc(rec.summary)}</strong>
      </span>
      <span style="font-size:0.8em;color:#888;white-space:nowrap">${esc(ago)}</span>
    </div>
    ${bodyHtml}
    <div class="exec-record-foot">
      ${amtHtml}
      <span>by ${esc(rec.creator_name || '')}</span>
      ${resolvedHtml(rec)}
      <span data-exec-actions></span>
    </div>
  </article>`;
}

// ── Component ─────────────────────────────────────────────────────────────────
class EventExecution extends PanicElement {
  // Properties set by the workspace before mount
  // eventId, canEdit, canManageIncidents

  get eventId()  { return this._eventId; }
  set eventId(v) {
    this._eventId = v;
    if (v) this._load();
  }

  connect() {
    injectStyles();
    this._records   = [];
    this._filter    = 'all';
    this._showForm  = false;
    this._loading   = true;
    this._error     = null;
    this.render();
    // _load() is triggered by set eventId() once the workspace wires us up.
    // Guard handles the rare case where eventId was set before DOM insertion.
    if (this._eventId) this._load();
  }

  async _load() {
    this._loading = true;
    this._error   = null;
    this._renderList();
    try {
      const res = await api(`/events/${this.eventId}/execution`, { signal: this.abort.signal });
      this._records = res.records || [];
    } catch (err) {
      if (err.name === 'AbortError') return;
      this._error = err.message || 'Failed to load execution records.';
    }
    this._loading = false;
    this._renderList();
  }

  // Full render (shell: heading + filter tabs).  Called once in connect().
  render() {
    const canSeeIncidents = this.canManageIncidents || can(null, 'view_incidents');
    const visibleTabs = FILTER_TABS.filter(t => !t.restricted || canSeeIncidents);

    const tabsHtml = visibleTabs.map(t =>
      `<a class="${this._filter === t.key ? 'active' : ''}" href="#exec-${esc(t.key)}" data-exec-filter="${esc(t.key)}">${esc(t.label)}</a>`
    ).join('');

    const addBtn = this.canEdit
      ? `<button type="button" class="button small" data-exec-add-toggle>${this._showForm ? 'Cancel' : '+ Add Record'}</button>`
      : '';

    this.innerHTML = `<section class="panel" id="execution">
      <div class="section-head padded">
        <h2><i class="fa-solid fa-bolt" aria-hidden="true"></i> Execution Records</h2>
        ${addBtn}
      </div>
      <nav class="workspace-tabs tabs" style="margin:0 0 1rem">${tabsHtml}</nav>
      ${this.canEdit && this._showForm ? this._addFormHtml() : ''}
      <div class="padded" data-exec-list></div>
    </section>`;

    this._bindShell();
    this._renderList();
  }

  // Rebuild only the record list region (no tabs/header teardown).
  _renderList() {
    const listEl = $('[data-exec-list]', this);
    if (!listEl) return;

    if (this._loading) {
      listEl.innerHTML = '<p style="padding:1rem;color:#888">Loading…</p>';
      return;
    }
    if (this._error) {
      listEl.innerHTML = `<p style="padding:1rem;color:#dc3545">${esc(this._error)}</p>`;
      return;
    }

    const filtered = this._filteredRecords();
    if (!filtered.length) {
      listEl.innerHTML = emptyState('No execution records for this filter.');
      return;
    }

    listEl.innerHTML = filtered.map(renderCard).join('');
    this._bindCards(listEl);
  }

  _filteredRecords() {
    if (this._filter === 'all') return this._records;
    const tab = FILTER_TABS.find(t => t.key === this._filter);
    if (!tab || !tab.types) return this._records;
    return this._records.filter(r => tab.types.includes(r.record_type));
  }

  // ── Add form HTML ───────────────────────────────────────────────────────────
  _addFormHtml() {
    const canSeeIncidents = this.canManageIncidents || can(null, 'view_incidents');
    const types = ALL_RECORD_TYPES.filter(t => t !== 'incident' || canSeeIncidents);
    const typeOptions = types.map(t => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('');
    const restrictedField = this.canManageIncidents
      ? `<label class="check-label"><input type="checkbox" name="is_restricted" value="1"> Restrict to incident managers</label>`
      : '';
    return `<form class="padded exec-add-form" data-exec-add-form style="border-bottom:1px solid var(--border,#e5e5e5);padding-bottom:1rem;margin-bottom:1rem;">
      <div class="grid-form" style="gap:0.5rem;">
        <label>Type
          <select name="record_type" required>${typeOptions}</select>
        </label>
        <label>Summary
          <input type="text" name="summary" required placeholder="Brief summary…">
        </label>
        <label class="wide">Details (optional)
          <textarea name="body" rows="3" placeholder="Additional details…"></textarea>
        </label>
        <label>Financial Impact ($)
          <input type="number" name="amount" value="0" min="0" step="0.01">
        </label>
        ${restrictedField}
        <div class="exec-record-edit-actions wide">
          <button type="submit" class="button">Save</button>
          <button type="button" class="secondary" data-exec-cancel-add>Cancel</button>
        </div>
      </div>
    </form>`;
  }

  // ── Bind shell interactions (tabs, add toggle) ──────────────────────────────
  _bindShell() {
    // Filter tabs
    $$('[data-exec-filter]', this).forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        this._filter = a.dataset.execFilter;
        $$('[data-exec-filter]', this).forEach(x => x.classList.toggle('active', x === a));
        this._renderList();
      });
    });

    // Add toggle button
    const addToggleBtn = $('[data-exec-add-toggle]', this);
    if (addToggleBtn) {
      addToggleBtn.addEventListener('click', () => {
        this._showForm = !this._showForm;
        this.render();
      });
    }

    // Add form submission
    const addForm = $('[data-exec-add-form]', this);
    if (addForm) {
      addForm.addEventListener('submit', async e => {
        e.preventDefault();
        await this._submitAdd(addForm);
      });
      $('[data-exec-cancel-add]', addForm)?.addEventListener('click', () => {
        this._showForm = false;
        this.render();
      });
    }
  }

  // ── Add record ──────────────────────────────────────────────────────────────
  async _submitAdd(form) {
    const btn = $('button[type=submit]', form);
    if (btn) btn.disabled = true;
    try {
      const body = {
        record_type:   form.record_type.value,
        summary:       form.summary.value.trim(),
        body:          form.body?.value.trim() || '',
        amount:        parseFloat(form.amount?.value) || 0,
        is_restricted: form.is_restricted?.checked ? 1 : 0,
      };
      if (!body.summary) { publish('toast.show', { message: 'Summary is required.', tone: 'error' }); return; }
      await api(`/events/${this.eventId}/execution`, { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: 'Record added.' });
      this._showForm = false;
      await this._load();
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Save failed.', tone: 'error' });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // ── Bind per-card interactions ──────────────────────────────────────────────
  _bindCards(listEl) {
    // Expand/collapse body text
    $$('.exec-body', listEl).forEach(p => {
      p.addEventListener('click', () => p.classList.toggle('expanded'));
    });

    // Inject resolve button for unresolved incident records (requires manage_incidents)
    if (this.canManageIncidents) {
      $$('[data-exec-id]', listEl).forEach(article => {
        const recId = article.dataset.execId;
        const rec   = this._records.find(r => String(r.id) === recId);
        if (!rec) return;
        if (rec.record_type !== 'incident' && !Number(rec.is_restricted)) return;
        if (rec.resolved_at) return;
        const slot = $('[data-exec-resolve-slot]', article);
        if (!slot) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'secondary small';
        btn.textContent = 'Resolve';
        btn.addEventListener('click', () => this._startResolve(article, rec));
        slot.replaceWith(btn);
      });
    }

    if (!this.canEdit) return;

    // Inject edit/delete buttons into each card footer's actions slot
    $$('[data-exec-id]', listEl).forEach(article => {
      const recId   = article.dataset.execId;
      const rec     = this._records.find(r => String(r.id) === recId);
      if (!rec) return;
      const actionsEl = $('[data-exec-actions]', article);
      if (!actionsEl) return;
      actionsEl.innerHTML = `<button type="button" class="secondary small" data-exec-edit="${esc(recId)}">Edit</button> <button type="button" class="danger small" data-exec-delete="${esc(recId)}">Delete</button>`;

      $(`[data-exec-edit="${CSS.escape(recId)}"]`, actionsEl).addEventListener('click', () => {
        this._startEdit(article, rec);
      });
      $(`[data-exec-delete="${CSS.escape(recId)}"]`, actionsEl).addEventListener('click', () => {
        this._deleteRecord(recId);
      });
    });
  }

  // ── Resolve incident inline form ────────────────────────────────────────────
  _startResolve(article, rec) {
    const foot = $('.exec-record-foot', article);
    if (!foot) return;
    // Append resolve form below the footer
    const formHtml = `<div class="exec-resolve-form" data-exec-resolve-form>
      <label style="font-size:0.9em;font-weight:600;color:#333">Resolution notes (required)
        <textarea name="resolution_notes" rows="3" placeholder="Describe how this incident was resolved…"></textarea>
      </label>
      <div class="exec-record-edit-actions">
        <button type="button" class="button small" data-exec-resolve-submit>Mark Resolved</button>
        <button type="button" class="secondary small" data-exec-resolve-cancel>Cancel</button>
      </div>
    </div>`;
    foot.insertAdjacentHTML('afterend', formHtml);

    const formEl = $('[data-exec-resolve-form]', article);
    $('[data-exec-resolve-cancel]', formEl).addEventListener('click', () => {
      formEl.remove();
    });
    $('[data-exec-resolve-submit]', formEl).addEventListener('click', async () => {
      const notes = formEl.querySelector('textarea[name=resolution_notes]').value.trim();
      if (!notes) {
        publish('toast.show', { message: 'Resolution notes are required.', tone: 'error' });
        return;
      }
      const submitBtn = $('[data-exec-resolve-submit]', formEl);
      if (submitBtn) submitBtn.disabled = true;
      try {
        await api(`/events/${this.eventId}/execution/${rec.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ resolve: true, resolution_notes: notes }),
        });
        publish('toast.show', { message: 'Incident marked as resolved.' });
        await this._load();
      } catch (err) {
        publish('toast.show', { message: err.message || 'Failed to resolve incident.', tone: 'error' });
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // ── Inline edit mode ────────────────────────────────────────────────────────
  _startEdit(article, rec) {
    // Replace footer with inline edit form
    const foot = $('.exec-record-foot', article);
    if (!foot) return;
    foot.innerHTML = `<form class="exec-record-edit-form" data-exec-edit-form style="width:100%">
      <label>Summary
        <input type="text" name="summary" value="${esc(rec.summary)}" required style="width:100%;box-sizing:border-box;">
      </label>
      <label>Details
        <textarea name="body" rows="3" style="width:100%;box-sizing:border-box;">${esc(rec.body || '')}</textarea>
      </label>
      <label>Financial Impact ($)
        <input type="number" name="amount" value="${esc(String(rec.amount || 0))}" min="0" step="0.01" style="width:100%;box-sizing:border-box;">
      </label>
      <div class="exec-record-edit-actions">
        <button type="submit" class="button small">Save</button>
        <button type="button" class="secondary small" data-exec-cancel-edit>Cancel</button>
      </div>
    </form>`;

    const editForm = $('[data-exec-edit-form]', foot);

    $('[data-exec-cancel-edit]', editForm).addEventListener('click', () => {
      this._renderList();
    });

    editForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', editForm);
      if (btn) btn.disabled = true;
      try {
        const body = {
          summary: editForm.summary.value.trim(),
          body:    editForm.body?.value.trim() || '',
          amount:  parseFloat(editForm.amount?.value) || 0,
        };
        if (!body.summary) { publish('toast.show', { message: 'Summary is required.', tone: 'error' }); return; }
        await api(`/events/${this.eventId}/execution/${rec.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Record updated.' });
        await this._load();
      } catch (err) {
        publish('toast.show', { message: err.message || 'Update failed.', tone: 'error' });
        if (btn) btn.disabled = false;
      }
    });
  }

  // ── Delete record ───────────────────────────────────────────────────────────
  async _deleteRecord(recId) {
    if (!confirm('Delete this record?')) return;
    try {
      await api(`/events/${this.eventId}/execution/${recId}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Record deleted.' });
      await this._load();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Delete failed.', tone: 'error' });
    }
  }
}

customElements.define('pb-event-execution', EventExecution);
