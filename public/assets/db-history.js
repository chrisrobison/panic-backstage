import { esc, api, publish, PanicElement, $, $$ } from './core.js';

// ── DB History ───────────────────────────────────────────────────────────────
// Browse the audit-trigger log (db_history) and undo/redo individual writes.
// Restricted to venue admins (server gates on manage_db_history; the nav entry
// is hidden by AppShell.applyCapabilities() for everyone else).
//
// There's no separate "redo" control: undoing a change is itself a real write,
// so it lands its own db_history entry. Redoing the original change is just
// undoing *that* entry — the detail panel always shows an "Undo this" button
// for whichever entry you're looking at, and links the pair together.

const PAGE_SIZE = 50;

const ACTION_TONE = { INSERT: 'dbh-insert', UPDATE: 'dbh-update', DELETE: 'dbh-delete' };

class AdminDbHistory extends PanicElement {
  async connect() {
    this.entries = [];
    this.tables = [];
    this.total = 0;
    this.page = 1;
    this.selectedId = null;
    this.detail = null;
    this.loadingList = false;
    this.loadingDetail = false;
    this.undoing = false;
    this.filters = { table: '', pk: '', actor: '', action: '', undone: '' };
    this._filterTimer = null;

    publish('page.context', {
      title: 'Database History',
      blurb: 'Every insert, update, and delete on this database, with a one-click undo — and redo, by undoing the undo.',
    });

    await this.loadList();
  }

  queryParams(extra = {}) {
    const params = new URLSearchParams(extra);
    Object.entries(this.filters).forEach(([key, value]) => {
      if (value) params.set(key, value);
    });
    return params;
  }

  async loadList({ showLoading = true } = {}) {
    this.loadingList = showLoading;
    if (showLoading) this.render();
    try {
      const params = this.queryParams({ page: this.page, limit: PAGE_SIZE });
      const data = await api(`/db-history?${params.toString()}`);
      this.entries = data.entries || [];
      this.total = data.total || 0;
      this.tables = data.tables || [];
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
      this.entries = [];
      this.total = 0;
    } finally {
      this.loadingList = false;
      this.render();
    }
  }

  async selectEntry(id) {
    if (this.selectedId === id) {
      this.selectedId = null;
      this.detail = null;
      this.render();
      return;
    }
    this.selectedId = id;
    this.detail = null;
    this.loadingDetail = true;
    this.render();
    try {
      this.detail = await api(`/db-history/${id}`);
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
    } finally {
      this.loadingDetail = false;
      this.render();
    }
  }

  onFilterChange(key, value) {
    this.filters = { ...this.filters, [key]: value };
    this.page = 1;
    clearTimeout(this._filterTimer);
    this._filterTimer = setTimeout(() => this.loadList(), key === 'pk' || key === 'actor' ? 350 : 0);
  }

  async changePage(delta) {
    const maxPage = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const next = Math.min(maxPage, Math.max(1, this.page + delta));
    if (next === this.page) return;
    this.page = next;
    await this.loadList();
  }

  async undoEntry(id, describedAs) {
    if (this.undoing) return;
    const verb = describedAs || 'this change';
    if (!confirm(`Undo ${verb}?\n\nThis runs the stored reverse SQL immediately. You can undo the undo afterward if you change your mind.`)) {
      return;
    }
    this.undoing = true;
    this.render();
    try {
      const result = await api(`/db-history/${id}/undo`, { method: 'POST' });
      publish('toast.show', { message: 'Undone.', tone: 'success' });
      await this.loadList({ showLoading: false });
      if (result.result_entry_id) {
        await this.selectEntry(result.result_entry_id);
      } else if (this.selectedId === id) {
        await this.selectEntry(id);
      }
    } catch (error) {
      publish('toast.show', { message: error.message || 'Undo failed.', tone: 'error' });
    } finally {
      this.undoing = false;
      this.render();
    }
  }

  // ─── Formatting ──────────────────────────────────────────────────────────────

  fmtTime(ts) {
    if (!ts) return '';
    const d = new Date(ts.replace(' ', 'T') + 'Z');
    return isNaN(d) ? ts : d.toLocaleString();
  }

  fmtValue(v) {
    if (v === null || v === undefined) return '<span class="dbx-nulltag">NULL</span>';
    const str = typeof v === 'object' ? JSON.stringify(v) : String(v);
    return esc(str.length > 200 ? `${str.slice(0, 200)}…` : str);
  }

  actorLabel(actor) {
    if (!actor) return '<span class="muted">unattributed</span>';
    if (actor.startsWith('user:')) return `<i class="fa-solid fa-user" aria-hidden="true"></i> user #${esc(actor.slice(5))}`;
    if (actor.startsWith('cli:')) return `<i class="fa-solid fa-terminal" aria-hidden="true"></i> ${esc(actor.slice(4))}`;
    return esc(actor);
  }

  // ─── Render ──────────────────────────────────────────────────────────────────

  render() {
    this.innerHTML = `
      <div class="dbx dbh">
        <section class="dbx-main">
          ${this.renderFilters()}
          <div class="dbx-split ${this.selectedId ? 'has-detail' : ''}">
            <div class="dbx-rows-pane" style="${this.selectedId ? 'height:55%' : ''}">
              <div class="dbx-rows-scroll">
                ${this.loadingList ? '<div class="dbx-loading"><span class="spinner"></span> Loading…</div>' : this.renderList()}
              </div>
              ${this.renderPager()}
            </div>
            ${this.selectedId ? this.renderDetail() : ''}
          </div>
        </section>
      </div>
    `;
    this.bind();
  }

  renderFilters() {
    return `
      <div class="dbx-toolbar dbh-filters">
        <select data-filter="table" aria-label="Filter by table">
          <option value="">All tables</option>
          ${this.tables.map((t) => `<option value="${esc(t)}" ${this.filters.table === t ? 'selected' : ''}>${esc(t)}</option>`).join('')}
        </select>
        <select data-filter="action" aria-label="Filter by action">
          <option value="">Insert / Update / Delete</option>
          <option value="INSERT" ${this.filters.action === 'INSERT' ? 'selected' : ''}>Insert</option>
          <option value="UPDATE" ${this.filters.action === 'UPDATE' ? 'selected' : ''}>Update</option>
          <option value="DELETE" ${this.filters.action === 'DELETE' ? 'selected' : ''}>Delete</option>
        </select>
        <input type="text" data-filter="pk" placeholder="Row id…" value="${esc(this.filters.pk)}" aria-label="Filter by row id">
        <input type="text" data-filter="actor" placeholder="Actor (user:12, cli:…)…" value="${esc(this.filters.actor)}" aria-label="Filter by actor">
        <select data-filter="undone" aria-label="Filter by undo status">
          <option value="">Undone or not</option>
          <option value="no" ${this.filters.undone === 'no' ? 'selected' : ''}>Not undone</option>
          <option value="yes" ${this.filters.undone === 'yes' ? 'selected' : ''}>Undone</option>
        </select>
      </div>
    `;
  }

  renderPager() {
    const maxPage = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const from = this.total === 0 ? 0 : (this.page - 1) * PAGE_SIZE + 1;
    const to = Math.min(this.total, this.page * PAGE_SIZE);
    return `
      <div class="dbx-pager dbh-pager">
        <span class="dbx-range">${from}–${to} of ${Number(this.total).toLocaleString()}</span>
        <button type="button" class="small secondary" data-page="-1" ${this.page <= 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
        <span class="dbx-page-num">${this.page} / ${maxPage}</span>
        <button type="button" class="small secondary" data-page="1" ${this.page >= maxPage ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
      </div>
    `;
  }

  renderList() {
    if (!this.entries.length) return '<div class="empty-state">No history entries match these filters.</div>';
    return `
      <table class="data-table dbx-data dbh-table">
        <thead><tr>
          <th>When</th><th>Table</th><th>Row</th><th>Action</th><th>Actor</th><th>Change</th><th></th>
        </tr></thead>
        <tbody>
          ${this.entries.map((e) => `
            <tr class="dbx-row ${e.id === this.selectedId ? 'selected' : ''}" data-entry="${e.id}" tabindex="0">
              <td class="muted">${esc(this.fmtTime(e.created_at))}</td>
              <td>${esc(e.table_name)}</td>
              <td>${esc(e.pk_value)}</td>
              <td><span class="badge ${ACTION_TONE[e.action] || 'dbh-gray'}">${esc(e.action)}</span></td>
              <td>${this.actorLabel(e.actor)}</td>
              <td>${this.changeSummary(e)}</td>
              <td>${e.undone_at ? '<span class="badge dbh-gray" title="Already undone"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> undone</span>' : ''}${e.undo_of_id ? '<span class="badge dbh-update" title="This is the result of undoing another entry">↩ redo</span>' : ''}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }

  changeSummary(e) {
    if (e.action !== 'UPDATE') return '<span class="muted">—</span>';
    if (!e.changed_fields?.length) return '<span class="muted">no field changes</span>';
    const shown = e.changed_fields.slice(0, 2).map((c) => esc(c.field)).join(', ');
    const more = e.changed_fields.length > 2 ? ` +${e.changed_fields.length - 2} more` : '';
    return `${shown}${more}`;
  }

  renderDetail() {
    const d = this.detail;
    if (this.loadingDetail || !d) {
      return `<div class="dbx-detail-pane"><div class="dbx-loading"><span class="spinner"></span> Loading…</div></div>`;
    }

    const canUndo = !d.undone_at;
    const undoLabel = d.action === 'DELETE' ? 're-insert this row'
      : d.action === 'INSERT' ? 'delete this row'
      : 'revert this update';

    return `
      <div class="dbx-detail-pane dbh-detail">
        <div class="dbx-detail-head">
          <div class="dbx-detail-title">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            ${esc(d.table_name)} <span class="muted">· ${esc(d.pk_column)} = ${esc(d.pk_value)}</span>
            <span class="badge ${ACTION_TONE[d.action] || 'dbh-gray'}">${esc(d.action)}</span>
          </div>
          <button type="button" class="small secondary" data-close-detail aria-label="Close detail"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="dbx-detail-scroll">
          <div class="dbh-meta">
            <div><strong>When</strong> ${esc(this.fmtTime(d.created_at))}</div>
            <div><strong>Actor</strong> ${this.actorLabel(d.actor)}</div>
            ${d.undo_of_id ? `<div><strong>Redo of</strong> undoing <a href="#" data-goto="${d.undo_of_id}">entry #${d.undo_of_id}</a></div>` : ''}
            ${d.undone_at ? `<div><strong>Undone</strong> ${esc(this.fmtTime(d.undone_at))} by ${this.actorLabel(d.undone_by_actor)}${d.undo_result ? ` — see <a href="#" data-goto="${d.undo_result.id}">entry #${d.undo_result.id}</a>` : ''}</div>` : ''}
          </div>

          ${d.action === 'UPDATE' ? this.renderFieldDiff(d) : this.renderFullRow(d)}

          <details class="dbh-sql">
            <summary>Undo SQL</summary>
            <pre class="dbh-sql-pre">${esc(d.undo_sql)}</pre>
          </details>

          <div class="dbh-actions">
            ${canUndo
              ? `<button type="button" class="button danger" data-undo="${d.id}" ${this.undoing ? 'disabled' : ''}>
                   <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> ${this.undoing ? 'Undoing…' : `Undo — ${undoLabel}`}
                 </button>`
              : `<span class="muted"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Already undone.</span>`}
          </div>
        </div>
      </div>
    `;
  }

  renderFieldDiff(d) {
    if (!d.changed_fields.length) {
      return '<div class="empty-state">No field values actually changed.</div>';
    }
    return `
      <fieldset class="dbx-fieldset">
        <legend>Changed fields</legend>
        <table class="data-table dbh-diff">
          <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
          <tbody>
            ${d.changed_fields.map((c) => `
              <tr><td>${esc(c.field)}</td><td class="dbh-before">${this.fmtValue(c.from)}</td><td class="dbh-after">${this.fmtValue(c.to)}</td></tr>
            `).join('')}
          </tbody>
        </table>
      </fieldset>
    `;
  }

  renderFullRow(d) {
    const row = d.action === 'DELETE' ? d.old_row : d.new_row;
    if (!row) return '';
    return `
      <fieldset class="dbx-fieldset">
        <legend>${d.action === 'DELETE' ? 'Deleted row' : 'Inserted row'}</legend>
        <div class="dbx-fields">
          ${Object.entries(row).map(([k, v]) => `
            <div class="dbx-field ${v === null ? 'is-null' : ''}">
              <span class="dbx-field-label">${esc(k)}</span>
              <span class="dbx-field-value">${this.fmtValue(v)}</span>
            </div>
          `).join('')}
        </div>
      </fieldset>
    `;
  }

  bind() {
    $$('[data-filter]', this).forEach((el) => {
      el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => this.onFilterChange(el.dataset.filter, el.value));
    });
    $$('[data-page]', this).forEach((btn) => btn.addEventListener('click', () => this.changePage(Number(btn.dataset.page))));
    $$('[data-entry]', this).forEach((tr) => {
      const open = () => this.selectEntry(Number(tr.dataset.entry));
      tr.addEventListener('click', open);
      tr.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
    $('[data-close-detail]', this)?.addEventListener('click', () => { this.selectedId = null; this.detail = null; this.render(); });
    $('[data-undo]', this)?.addEventListener('click', (event) => {
      const id = Number(event.currentTarget.dataset.undo);
      const entry = this.detail;
      const label = entry ? `${entry.action.toLowerCase()} on ${entry.table_name} #${entry.pk_value}` : null;
      this.undoEntry(id, label);
    });
    $$('[data-goto]', this).forEach((a) => a.addEventListener('click', (event) => {
      event.preventDefault();
      this.selectEntry(Number(a.dataset.goto));
    }));
  }
}

customElements.define('pb-admin-db-history', AdminDbHistory);
