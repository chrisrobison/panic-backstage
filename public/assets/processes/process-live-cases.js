// <pb-process-live-cases> — the collapsible "Live Instances" drawer shown
// under the canvas on the Live Cases tab. Lists process_instances rows
// (search/filter by status, node, owner) and expands a selected case inline
// into its timeline + variables + real operator controls. Acting on the
// current step itself (completing a task, resuming a wait) is delegated to
// the shared <pb-process-step-form> — see process-step-form.js — the same
// component the Tasks inbox and an embedded Event-workspace card use, so
// there's exactly one rendering of "what does this step need" instead of
// three. retry/cancel/pause/resume/move stay here since they're instance-
// level operator actions, not step-specific. The four seeded is_demo=1
// example cases predate the runtime and have no tasks/waits/executions to
// act on — the "Demo data" pill says so rather than pretending they're real
// in-flight cases.
import { $, $$, esc } from '../core.js';
import './process-step-form.js';

// Escape first (so nothing in the raw text can inject markup), then turn any
// http(s) URL into a real link — this is how a Phase 3 real-handler detail
// like "Draft ready — https://mail.google.com/...&body=..." becomes an
// actual clickable "Open in Gmail" link in the timeline, without needing a
// structured field just for that one case.
function linkify(text) {
  return esc(text).replace(/(https?:\/\/[^\s<]+)/g, (url) => `<a href="${url}" target="_blank" rel="noopener">${url.includes('mail.google.com') ? 'Open in Gmail' : 'Open link'}</a>`);
}

export class ProcessLiveCasesElement extends HTMLElement {
  connectedCallback() {
    this.open = true;
    this.filters = { status: '', q: '' };
    this.expandedId = null;
    this.render();
  }

  /** { instances, nodeCounts, hasDemoData } */
  set data(value) { this._data = value || { instances: [] }; this.render(); }

  /** async (instanceId) => detail payload, provided by the designer so this
   *  component doesn't need to know the API shape. */
  set loadDetail(fn) { this._loadDetail = fn; }

  render() {
    const d = this._data || { instances: [] };
    const filtered = this.applyFilters(d.instances || []);
    const demoNote = d.instances?.some((i) => i.is_demo)
      ? `<span class="pill pill-muted" title="These cases are seeded demonstration data — they predate the Phase 2 runtime and have no tasks/waits to act on.">Demo data</span>`
      : '';
    const canStart = !!d.canStart;

    this.innerHTML = `
      <div class="proc-drawer-head">
        <button type="button" class="proc-drawer-toggle" data-toggle-drawer aria-expanded="${this.open}">
          <i class="fa-solid fa-chevron-${this.open ? 'down' : 'up'}" aria-hidden="true"></i>
          Live Instances <span class="pill">${filtered.length}</span> ${demoNote}
        </button>
        <div class="proc-drawer-filters"${this.open ? '' : ' hidden'}>
          <input type="search" placeholder="Search cases…" value="${esc(this.filters.q)}" data-filter="q" aria-label="Search cases">
          <select data-filter="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            ${['active', 'waiting', 'overdue', 'failed', 'completed', 'canceled', 'paused'].map((s) => `<option value="${s}" ${this.filters.status === s ? 'selected' : ''}>${s[0].toUpperCase()}${s.slice(1)}</option>`).join('')}
          </select>
          ${canStart ? `<button type="button" class="small" data-start-instance><i class="fa-solid fa-play" aria-hidden="true"></i> Start Instance</button>` : ''}
        </div>
      </div>
      <div class="proc-drawer-body"${this.open ? '' : ' hidden'}>
        ${filtered.length ? `<div class="table-scroll"><table class="data-table">
          <thead><tr><th>Case</th><th>Current Step</th><th>Owner</th><th>Elapsed</th><th>Status</th><th></th></tr></thead>
          <tbody>${filtered.map((i) => this.renderRow(i)).join('')}</tbody>
        </table></div>` : '<div class="empty-state padded">No cases match these filters.</div>'}
      </div>`;

    $('[data-toggle-drawer]', this)?.addEventListener('click', () => { this.open = !this.open; this.render(); });
    $('[data-start-instance]', this)?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.dispatchEvent(new CustomEvent('start-instance', { bubbles: true }));
    });
    $$('[data-filter]', this).forEach((el) => el.addEventListener('input', () => {
      this.filters[el.dataset.filter] = el.value;
      this.render();
    }));
    $$('[data-row-id]', this).forEach((row) => row.addEventListener('click', (e) => {
      if (e.target.closest('[data-badge-jump]')) return;
      const id = Number(row.dataset.rowId);
      this.expandedId = this.expandedId === id ? null : id;
      this.render();
      if (this.expandedId) this.loadAndShowDetail(id);
      this.dispatchEvent(new CustomEvent('select-instance', { bubbles: true, detail: { instanceId: this.expandedId } }));
    }));
  }

  renderRow(instance) {
    const expanded = this.expandedId === instance.id;
    const elapsed = this.elapsedLabel(instance.started_at);
    const statusClass = { active: 'confirmed', waiting: 'needs_assets', overdue: 'canceled', failed: 'canceled', completed: 'advanced', canceled: 'empty' }[instance.status] || 'empty';
    return `<tr class="clickable-row" data-row-id="${instance.id}">
        <td data-label="Case"><strong>${esc(instance.name)}</strong>${instance.is_demo ? ' <span class="pill pill-muted small">demo</span>' : ''}</td>
        <td data-label="Current Step">${esc(instance.current_node_id || '—')}</td>
        <td data-label="Owner">${esc(instance.owner_name || 'Unassigned')}</td>
        <td data-label="Elapsed">${esc(elapsed)}</td>
        <td data-label="Status"><span class="badge status-${statusClass}">${esc(instance.status)}</span></td>
        <td><i class="fa-solid fa-chevron-${expanded ? 'up' : 'down'}" aria-hidden="true"></i></td>
      </tr>
      ${expanded ? `<tr class="proc-instance-detail-row"><td colspan="6"><div class="proc-instance-detail padded" data-detail-for="${instance.id}">Loading…</div></td></tr>` : ''}`;
  }

  elapsedLabel(startedAt) {
    if (!startedAt) return '—';
    const ms = Date.now() - new Date(startedAt.replace(' ', 'T') + 'Z').getTime();
    const hours = Math.floor(ms / 36e5);
    if (hours < 1) return `${Math.max(1, Math.floor(ms / 60000))}m`;
    if (hours < 48) return `${hours}h`;
    return `${Math.floor(hours / 24)}d`;
  }

  applyFilters(instances) {
    return instances.filter((i) => {
      if (this.filters.status && i.status !== this.filters.status) return false;
      if (this.filters.q && !i.name.toLowerCase().includes(this.filters.q.toLowerCase())) return false;
      return true;
    });
  }

  async loadAndShowDetail(id) {
    const container = $(`[data-detail-for="${id}"]`, this);
    if (!container || !this._loadDetail) return;
    try {
      const detail = await this._loadDetail(id);
      if (!$(`[data-detail-for="${id}"]`, this)) return; // row collapsed while loading
      this._lastDetail = detail;
      container.innerHTML = this.renderDetail(detail);
      const stepForm = $('pb-process-step-form', container);
      if (stepForm) stepForm.detail = detail;
      this.bindDetailActions(container, id, detail);
    } catch (err) {
      container.innerHTML = `<p class="error-text">${esc(err.message)}</p>`;
    }
  }

  renderDetail(detail) {
    const vars = detail.instance?.variables || {};
    const varRows = Object.entries(vars).map(([k, v]) => `<div><strong>${esc(k)}</strong>: ${esc(String(v))}</div>`).join('') || '<span class="muted">No variables recorded.</span>';
    const timeline = (detail.events || []).map((e) => `<li><span class="timeline-dot"></span><div><strong>${esc(e.label)}</strong> <span class="muted small">${esc(e.created_at)}</span>${e.detail ? `<div class="muted small">${linkify(e.detail)}</div>` : ''}</div></li>`).join('') || '<li class="muted">No timeline events recorded yet.</li>';

    const status = detail.instance?.status;
    const opActions = [];
    if (status === 'failed') opActions.push('<button type="button" class="small" data-instance-action="retry">Retry</button>');
    if (!['completed', 'canceled'].includes(status)) opActions.push('<button type="button" class="small danger" data-instance-action="cancel">Cancel</button>');
    if (status === 'paused') opActions.push('<button type="button" class="small" data-instance-action="resume">Resume</button>');
    else if (!['completed', 'canceled'].includes(status)) opActions.push('<button type="button" class="small secondary" data-instance-action="pause">Pause</button>');

    return `<div class="proc-instance-detail-grid">
      <div>
        <h3>Timeline</h3><ul class="proc-timeline">${timeline}</ul>
      </div>
      <div>
        <h3>Variables</h3><div class="proc-var-list">${varRows}</div>
        <h3>Current step</h3><pb-process-step-form></pb-process-step-form>
        ${opActions.length ? `<h3>Operator actions</h3><div class="proc-instance-actions">${opActions.join('')}</div>` : ''}
      </div>
    </div>`;
  }

  bindDetailActions(container, instanceId, detail) {
    $$('[data-instance-action]', container).forEach((btn) => btn.addEventListener('click', () => {
      const action = btn.dataset.instanceAction;
      const note = prompt(`Reason for "${action}" (required):`);
      if (!note || !note.trim()) return;
      this.dispatchEvent(new CustomEvent('instance-action', { bubbles: true, detail: { instanceId, action, note: note.trim() } }));
    }));
  }
}
customElements.define('pb-process-live-cases', ProcessLiveCasesElement);
