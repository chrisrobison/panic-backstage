import { esc, api, publish, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';

// ── Mailing Lists (admin) ────────────────────────────────────────────────────
// Named, reusable recipient groups layered on top of `contacts`. Same
// split-pane shape as Outbox/Messages: fixed toolbar, scrollable list table,
// resizable scrollable detail pane (list fields + members + add-contacts).
//
// Reuses the existing `.outbox-*` split-pane CSS (see outbox.js / messages.js)
// rather than inventing new layout chrome. Net-new `.mlist-*` classes are only
// for content this app has no other component like (editable list name/desc,
// members table, contact-search-and-add) — see the bottom of this file's PR
// description / integration notes for the exact list that needs new CSS.

function fmtDate(raw) {
  if (!raw) return '—';
  const d = new Date(String(raw).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

const contactName = (c) => `${c.first_name || ''} ${c.last_name || ''}`.trim() || c.email || '(no name)';

const optedBadge = (c) => Number(c.marketing_opted_in)
  ? '<span class="badge status-confirmed">Opted in</span>'
  : '<span class="badge status-empty">Opted out</span>';

const memberStatusBadge = (status) => status === 'subscribed'
  ? '<span class="badge status-confirmed">Subscribed</span>'
  : '<span class="badge status-canceled">Unsubscribed</span>';

// Column definition drives both the table header and the client-side sort
// (the /mailing-lists endpoint returns everything unpaginated, so sorting the
// already-fetched array is simplest — no extra round-trip).
const LIST_COLS = [
  { key: 'name',         label: 'Name',        width: 'auto', sortBy: (l) => String(l.name || '') },
  { key: 'description',  label: 'Description', width: 'auto', sortBy: (l) => String(l.description || '') },
  { key: 'member_count', label: 'Members',     width: '8em',  sortBy: (l) => Number(l.member_count || 0), numeric: true },
  { key: 'created_at',   label: 'Created',     width: '10em', sortBy: (l) => String(l.created_at || '') },
];

class MailingListsPage extends PanicElement {
  connect() {
    this.lists    = [];
    this.query    = '';
    this.sort     = { key: 'name', dir: 'asc' };
    this.selected = null;   // full mailing_list row, shared reference with this.lists when possible
    this._debounce = null;

    // Members sub-state (reset each time a different list is selected)
    this.members  = [];
    this.mQuery   = '';
    this.mStatus  = '';
    this.mPage    = 1;
    this.mLimit   = 25;
    this.mTotal   = 0;
    this.mPages   = 1;
    this._mDebounce = null;

    // Add-contacts sub-state
    this.acResults  = [];
    this.acSelected = new Set();
    this._acDebounce = null;

    this._app = document.getElementById('app');
    if (this._app) this._app.classList.add('workspace-outbox');
    publish('page.context', { title: 'Mailing Lists', blurb: 'Named, reusable recipient groups for campaign email.' });

    // Restore user's preferred detail-pane height from last session.
    try {
      const saved = localStorage.getItem('pb-mlist-detail-h');
      if (saved) this.style.setProperty('--detail-h', saved);
    } catch { /* storage unavailable */ }

    this.renderShell();
    this.load();
  }

  disconnectedCallback() {
    this._app?.classList.remove('workspace-outbox');
    this.abort?.abort();
  }

  // ── Lists: data ──────────────────────────────────────────────────────────

  async load() {
    const pane = $('.outbox-table-pane', this);
    if (pane) pane.setAttribute('aria-busy', 'true');

    try {
      const qs = new URLSearchParams();
      if (this.query) qs.set('q', this.query);
      const data = await api(`/mailing-lists?${qs}`);
      this.lists = data.lists || [];
    } catch (err) {
      this.lists = [];
      const tbody = $('.mlist-table tbody', this);
      if (tbody) tbody.innerHTML = `<tr><td colspan="4" class="outbox-empty">Failed to load lists: ${esc(err.message)}</td></tr>`;
      if (pane) pane.removeAttribute('aria-busy');
      return;
    }
    if (pane) pane.removeAttribute('aria-busy');

    this.renderRows();
  }

  sortedLists() {
    const col = LIST_COLS.find((c) => c.key === this.sort.key);
    if (!col) return this.lists;
    const dir = this.sort.dir === 'asc' ? 1 : -1;
    return this.lists.slice().sort((a, b) => {
      const av = col.sortBy(a);
      const bv = col.sortBy(b);
      const cmp = col.numeric ? (av - bv) : String(av).localeCompare(String(bv), undefined, { sensitivity: 'base', numeric: true });
      return cmp * dir;
    });
  }

  async selectList(id) {
    const found = this.lists.find((l) => l.id === id);
    if (found) {
      this.selected = found;
    } else {
      try {
        const data = await api(`/mailing-lists/${id}`);
        this.selected = data.list;
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
        return;
      }
    }

    // Reset member/add-contacts sub-state for the newly selected list.
    this.mQuery = '';
    this.mStatus = '';
    this.mPage = 1;
    this.members = [];
    this.mTotal = 0;
    this.mPages = 1;
    this.acResults = [];
    this.acSelected = new Set();

    this.renderDetail();
    this.loadMembers();
  }

  closeDetail() {
    this.selected = null;
    const pane = $('.outbox-detail-pane', this);
    if (pane) pane.hidden = true;
    this.classList.remove('detail-open');
    $$('.outbox-row', this).forEach((row) => {
      row.classList.remove('selected');
      row.setAttribute('aria-pressed', 'false');
    });
  }

  // ── Lists: render ────────────────────────────────────────────────────────

  renderShell() {
    this.innerHTML = `
      <div class="outbox-head">
        <div class="outbox-search-row">
          <label class="outbox-search-label" aria-label="Search mailing lists">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input class="outbox-search" type="search" placeholder="Search lists…" autocomplete="off" aria-label="Search mailing lists">
          </label>
          ${addToggle('New list', true)}
        </div>
        <form class="row-form mlist-add-form" data-add-form hidden>
          <label>Name <input name="name" required maxlength="160" placeholder="List name" autocomplete="off"></label>
          <label>Description <span class="muted small">(optional)</span><input name="description" maxlength="500" placeholder="What is this list for?" autocomplete="off"></label>
          <button type="submit" class="small">Create list</button>
          <button type="button" class="small secondary" data-cancel-add>Cancel</button>
          <span class="mlist-field-error" data-create-error hidden></span>
        </form>
      </div>

      <div class="outbox-body">
        <div class="outbox-table-pane" role="region" aria-label="Mailing list list" tabindex="0">
          <table class="data-table outbox-table mlist-table">
            <thead><tr>${LIST_COLS.map((c) => this.thHtml(c)).join('')}</tr></thead>
            <tbody><tr><td colspan="4" class="outbox-empty">Loading…</td></tr></tbody>
          </table>
        </div>

        <div class="outbox-resize-bar" aria-hidden="true">
          <span class="outbox-resize-handle">&#xb7;&#xb7;&#xb7;</span>
        </div>

        <div class="outbox-detail-pane" aria-label="Mailing list detail" role="region" hidden>
          <div class="outbox-detail-inner">
            <div class="outbox-detail-head">
              <div class="outbox-detail-meta">
                <div class="mlist-detail-fields">
                  <input class="mlist-name-input" data-field="name" maxlength="160" aria-label="List name">
                  <div class="mlist-field-error" data-name-error hidden></div>
                  <textarea class="mlist-desc-input" data-field="description" maxlength="500" rows="2" placeholder="Add a description…" aria-label="List description"></textarea>
                </div>
                <dl class="outbox-meta-list">
                  <div><dt>Members</dt><dd data-meta-count>0</dd></div>
                  <div><dt>Created</dt><dd data-meta-created>—</dd></div>
                </dl>
              </div>
              <div class="outbox-detail-actions">
                <button type="button" class="small danger mlist-delete-btn">Delete list</button>
                <button type="button" class="small secondary outbox-close" aria-label="Close list"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Close</button>
              </div>
            </div>

            <div class="outbox-detail-body">
              <section class="mlist-members">
                <h3 class="mlist-h3">Members</h3>
                <div class="mlist-members-head">
                  <label class="outbox-search-label" aria-label="Search members">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input class="outbox-search mlist-members-search" type="search" placeholder="Search members…" autocomplete="off">
                  </label>
                  <select class="mlist-status-filter" aria-label="Filter members by status">
                    <option value="">All statuses</option>
                    <option value="subscribed">Subscribed</option>
                    <option value="unsubscribed">Unsubscribed</option>
                  </select>
                </div>
                <table class="data-table mlist-members-table">
                  <thead><tr><th>Name</th><th>Email</th><th>Marketing</th><th>Status</th><th></th></tr></thead>
                  <tbody><tr><td colspan="5" class="outbox-empty">Select a list to see its members.</td></tr></tbody>
                </table>
                <div class="outbox-pager mlist-members-pager" aria-live="polite"></div>
              </section>

              <section class="mlist-add-contacts">
                <h3 class="mlist-h3">Add contacts</h3>
                <label class="outbox-search-label" aria-label="Search contacts to add">
                  <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                  <input class="outbox-search mlist-add-contacts-search" type="search" placeholder="Search contacts by name or email…" autocomplete="off">
                </label>
                <ul class="mlist-add-contacts-results" hidden></ul>
                <div class="mlist-add-actions" hidden>
                  <button type="button" class="small" data-add-selected disabled>Add selected (0)</button>
                </div>
              </section>
            </div>
          </div>
        </div>
      </div>
    `;

    bindAddToggle(this);
    this.bindEvents();
  }

  thHtml(col) {
    const active = this.sort.key === col.key;
    const arrow  = active ? (this.sort.dir === 'asc' ? ' ↑' : ' ↓') : '';
    const style  = col.width !== 'auto' ? ` style="width:${col.width}"` : '';
    return `<th class="${active ? 'sorted' : ''}"${style}>
      <button type="button" class="th-sort" data-sort-key="${esc(col.key)}"
        aria-sort="${active ? (this.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}">
        ${esc(col.label)}<span class="sort-arrow">${arrow}</span>
      </button>
    </th>`;
  }

  renderRows() {
    const tbody = $('.mlist-table tbody', this);
    if (!tbody) return;

    const thead = $('.mlist-table thead tr', this);
    if (thead) thead.innerHTML = LIST_COLS.map((c) => this.thHtml(c)).join('');
    this.bindSortHeaders();

    const rows = this.sortedLists();
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="4" class="outbox-empty">${this.query ? 'No lists match your search.' : 'No mailing lists yet — create one above.'}</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map((l) => `
      <tr class="outbox-row${this.selected?.id === l.id ? ' selected' : ''}" data-id="${esc(l.id)}" tabindex="0" role="button" aria-pressed="${this.selected?.id === l.id}">
        <td data-label="Name"><strong>${esc(l.name)}</strong></td>
        <td data-label="Description"><span class="${l.description ? '' : 'muted'}">${esc(l.description || '—')}</span></td>
        <td data-label="Members">${esc(Number(l.member_count || 0).toLocaleString())}</td>
        <td data-label="Created" class="muted">${esc(fmtDate(l.created_at))}</td>
      </tr>
    `).join('');

    $$('.outbox-row', this).forEach((row) => {
      const open = (e) => {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        const id = Number(row.dataset.id);
        if (this.selected?.id === id) this.closeDetail();
        else this.selectList(id);
      };
      row.addEventListener('click', open);
      row.addEventListener('keydown', open);
    });
  }

  bindSortHeaders() {
    $$('.th-sort', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.sortKey;
        if (this.sort.key === key) this.sort.dir = this.sort.dir === 'asc' ? 'desc' : 'asc';
        else this.sort = { key, dir: 'asc' };
        this.renderRows();
      });
    });
  }

  renderDetail() {
    const pane = $('.outbox-detail-pane', this);
    if (!pane || !this.selected) return;

    pane.hidden = false;
    this.classList.add('detail-open');

    const l = this.selected;
    const nameInput = $('.mlist-name-input', this);
    const descInput = $('.mlist-desc-input', this);
    if (nameInput) { nameInput.value = l.name || ''; delete nameInput.dataset.dirty; }
    if (descInput) { descInput.value = l.description || ''; delete descInput.dataset.dirty; }
    const nameErr = $('[data-name-error]', this);
    if (nameErr) { nameErr.hidden = true; nameErr.textContent = ''; }

    $('[data-meta-count]', this).textContent = Number(l.member_count || 0).toLocaleString();
    $('[data-meta-created]', this).textContent = fmtDate(l.created_at);

    // Reset the members/add-contacts sub-panels' static controls.
    const mSearch = $('.mlist-members-search', this);
    if (mSearch) mSearch.value = '';
    const mFilter = $('.mlist-status-filter', this);
    if (mFilter) mFilter.value = '';
    const acSearch = $('.mlist-add-contacts-search', this);
    if (acSearch) acSearch.value = '';
    const acResults = $('.mlist-add-contacts-results', this);
    if (acResults) { acResults.hidden = true; acResults.innerHTML = ''; }
    $('.mlist-add-actions', this).hidden = true;

    $$('.outbox-row', this).forEach((row) => {
      const active = Number(row.dataset.id) === l.id;
      row.classList.toggle('selected', active);
      row.setAttribute('aria-pressed', String(active));
    });
    $('.outbox-detail-inner', this)?.scrollTo(0, 0);
  }

  // ── List field editing (blur-if-dirty) ──────────────────────────────────

  async saveListField(field, el, value) {
    const errEl = field === 'name' ? $('[data-name-error]', this) : null;
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    try {
      const { list } = await api(`/mailing-lists/${this.selected.id}`, { method: 'PATCH', body: JSON.stringify({ [field]: value }) });
      Object.assign(this.selected, list);
      delete el.dataset.dirty;
      const row = this.lists.find((x) => x.id === this.selected.id);
      if (row && row !== this.selected) Object.assign(row, list);
      this.renderRows();
      publish('toast.show', { message: field === 'name' ? 'List renamed.' : 'Description saved.' });
    } catch (err) {
      if (errEl) {
        errEl.textContent = err.message;
        errEl.hidden = false;
        el.focus();
      } else {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }
  }

  // ── Members: data ────────────────────────────────────────────────────────

  async loadMembers() {
    if (!this.selected) return;
    const tbody = $('.mlist-members-table tbody', this);
    if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">Loading…</td></tr>`;

    try {
      const qs = new URLSearchParams({ page: String(this.mPage), limit: String(this.mLimit) });
      if (this.mQuery) qs.set('q', this.mQuery);
      if (this.mStatus) qs.set('status', this.mStatus);
      const data = await api(`/mailing-lists/${this.selected.id}/members?${qs}`);
      this.members = data.members || [];
      this.mTotal  = data.total || 0;
      this.mPage   = data.page || this.mPage;
      this.mLimit  = data.limit || this.mLimit;
      this.mPages  = data.pages || Math.ceil(this.mTotal / this.mLimit) || 1;
    } catch (err) {
      this.members = [];
      if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">Failed to load members: ${esc(err.message)}</td></tr>`;
      return;
    }

    this.renderMembers();
  }

  async syncMemberCount() {
    if (!this.selected) return;
    try {
      const { list } = await api(`/mailing-lists/${this.selected.id}`);
      this.selected.member_count = list.member_count;
      const row = this.lists.find((x) => x.id === this.selected.id);
      if (row) row.member_count = list.member_count;
      const countEl = $('[data-meta-count]', this);
      if (countEl) countEl.textContent = Number(list.member_count || 0).toLocaleString();
      this.renderRows();
    } catch { /* non-fatal — counts will refresh on next full reload */ }
  }

  // ── Members: render ──────────────────────────────────────────────────────

  renderMembers() {
    const tbody = $('.mlist-members-table tbody', this);
    if (!tbody) return;

    if (!this.members.length) {
      const empty = (this.mQuery || this.mStatus) ? 'No members match.' : 'No members yet — add contacts below.';
      tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">${esc(empty)}</td></tr>`;
    } else {
      tbody.innerHTML = this.members.map((m) => {
        const nextStatus = m.status === 'subscribed' ? 'unsubscribed' : 'subscribed';
        const toggleLabel = m.status === 'subscribed' ? 'Unsubscribe' : 'Resubscribe';
        return `<tr data-contact-id="${esc(m.contact_id)}">
          <td data-label="Name">${esc(contactName(m))}</td>
          <td data-label="Email">${esc(m.email || '—')}</td>
          <td data-label="Marketing">${optedBadge(m)}</td>
          <td data-label="Status">${memberStatusBadge(m.status)}</td>
          <td data-label="" class="mlist-member-actions">
            <button type="button" class="small secondary" data-toggle-status="${esc(nextStatus)}">${esc(toggleLabel)}</button>
            <button type="button" class="small danger" data-remove-member>Remove</button>
          </td>
        </tr>`;
      }).join('');
    }

    this.renderMembersPager();

    $$('.mlist-members-table tbody tr[data-contact-id]', this).forEach((row) => {
      const contactId = Number(row.dataset.contactId);
      $('[data-toggle-status]', row)?.addEventListener('click', (e) => this.toggleMemberStatus(contactId, e.currentTarget.dataset.toggleStatus));
      $('[data-remove-member]', row)?.addEventListener('click', () => this.removeMember(contactId));
    });
  }

  renderMembersPager() {
    const el = $('.mlist-members-pager', this);
    if (!el) return;
    if (this.mTotal === 0) { el.innerHTML = ''; return; }

    const start = (this.mPage - 1) * this.mLimit + 1;
    const end   = Math.min(this.mPage * this.mLimit, this.mTotal);

    el.innerHTML = `
      <span class="pager-info">${start}–${end} of ${this.mTotal.toLocaleString()}</span>
      <button type="button" class="small secondary pager-prev" ${this.mPage <= 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
      <span class="pager-pages">${this.mPage} / ${this.mPages}</span>
      <button type="button" class="small secondary pager-next" ${this.mPage >= this.mPages ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
    `;
    $('.pager-prev', el)?.addEventListener('click', () => { if (this.mPage > 1) { this.mPage--; this.loadMembers(); } });
    $('.pager-next', el)?.addEventListener('click', () => { if (this.mPage < this.mPages) { this.mPage++; this.loadMembers(); } });
  }

  async toggleMemberStatus(contactId, status) {
    try {
      await api(`/mailing-lists/${this.selected.id}/members/${contactId}`, { method: 'PATCH', body: JSON.stringify({ status }) });
      publish('toast.show', { message: status === 'subscribed' ? 'Member resubscribed.' : 'Member unsubscribed.' });
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async removeMember(contactId) {
    if (!confirm('Remove this contact from the list? This fully deletes their membership (different from unsubscribing, which keeps them on the list but excluded from sends).')) return;
    try {
      await api(`/mailing-lists/${this.selected.id}/members/${contactId}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Contact removed from list.' });
      if (this.members.length === 1 && this.mPage > 1) this.mPage--;
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Add contacts (ad-hoc search) ─────────────────────────────────────────

  async searchContactsForAdd(query) {
    const resultsEl = $('.mlist-add-contacts-results', this);
    const actionsEl = $('.mlist-add-actions', this);
    if (!query) {
      this.acResults = [];
      if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
      if (actionsEl) actionsEl.hidden = true;
      return;
    }
    try {
      const qs = new URLSearchParams({ q: query, page: '1', limit: '20' });
      const data = await api(`/contacts?${qs}`);
      this.acResults = data.contacts || [];
    } catch (err) {
      this.acResults = [];
      publish('toast.show', { message: err.message, tone: 'error' });
    }
    this.renderAddContactsResults();
  }

  renderAddContactsResults() {
    const resultsEl = $('.mlist-add-contacts-results', this);
    const actionsEl = $('.mlist-add-actions', this);
    if (!resultsEl || !actionsEl) return;

    // Drop selections for contacts no longer in the current result set.
    this.acSelected = new Set([...this.acSelected].filter((id) => this.acResults.some((c) => c.id === id)));

    resultsEl.hidden = false;
    if (!this.acResults.length) {
      resultsEl.innerHTML = `<li class="mlist-add-contacts-empty muted">No matching contacts.</li>`;
    } else {
      resultsEl.innerHTML = this.acResults.map((c) => {
        const checked = this.acSelected.has(c.id) ? 'checked' : '';
        return `<li class="checkbox-row">
          <label>
            <input type="checkbox" data-contact-checkbox value="${esc(c.id)}" ${checked}>
            <strong>${esc(contactName(c))}</strong> <span class="muted">${esc(c.email || '—')}</span> ${optedBadge(c)}
          </label>
        </li>`;
      }).join('');
    }
    actionsEl.hidden = false;
    this.updateAddSelectedButton();

    $$('[data-contact-checkbox]', resultsEl).forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = Number(cb.value);
        if (cb.checked) this.acSelected.add(id);
        else this.acSelected.delete(id);
        this.updateAddSelectedButton();
      });
    });
  }

  updateAddSelectedButton() {
    const btn = $('[data-add-selected]', this);
    if (!btn) return;
    btn.textContent = `Add selected (${this.acSelected.size})`;
    btn.disabled = this.acSelected.size === 0;
  }

  async addSelectedContacts() {
    if (!this.selected || !this.acSelected.size) return;
    const ids = [...this.acSelected];
    const btn = $('[data-add-selected]', this);
    if (btn) btn.disabled = true;
    try {
      const { added, skipped } = await api(`/mailing-lists/${this.selected.id}/members`, {
        method: 'POST',
        body: JSON.stringify({ contact_ids: ids }),
      });
      publish('toast.show', { message: skipped ? `${added} added, ${skipped} skipped (already invalid)` : `${added} added.` });

      this.acSelected = new Set();
      this.acResults = [];
      const searchInput = $('.mlist-add-contacts-search', this);
      if (searchInput) searchInput.value = '';
      const resultsEl = $('.mlist-add-contacts-results', this);
      if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
      $('.mlist-add-actions', this).hidden = true;

      this.mPage = 1;
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // ── Event binding (one-time, on static shell elements) ──────────────────

  bindEvents() {
    // List search (debounced)
    const searchInput = $('.outbox-search:not(.mlist-members-search):not(.mlist-add-contacts-search)', this);
    searchInput?.addEventListener('input', () => {
      clearTimeout(this._debounce);
      this._debounce = setTimeout(() => {
        this.query = searchInput.value.trim();
        this.closeDetail();
        this.load();
      }, 300);
    });

    // New-list inline form
    const addForm = $('.mlist-add-form', this);
    addForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const name = form.elements.name.value.trim();
      const description = form.elements.description.value.trim();
      const errEl = $('[data-create-error]', form);
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      if (!name) return;

      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        const { list } = await api('/mailing-lists', { method: 'POST', body: JSON.stringify({ name, description }) });
        this.lists = [list, ...this.lists];
        this.query = '';
        const outerSearch = $('.outbox-search:not(.mlist-members-search):not(.mlist-add-contacts-search)', this);
        if (outerSearch) outerSearch.value = '';
        form.reset();
        form.setAttribute('hidden', '');
        $('[data-add]', this)?.classList.remove('active');
        publish('toast.show', { message: `List "${list.name}" created.` });
        this.renderRows();
        await this.selectList(list.id);
      } catch (err) {
        if (errEl) { errEl.textContent = err.message; errEl.hidden = false; }
        else publish('toast.show', { message: err.message, tone: 'error' });
      } finally {
        btn.disabled = false;
      }
    });

    // Detail: name / description blur-if-dirty save
    const nameInput = $('.mlist-name-input', this);
    const descInput = $('.mlist-desc-input', this);
    [[nameInput, 'name'], [descInput, 'description']].forEach(([el, field]) => {
      if (!el) return;
      el.addEventListener('input', () => { el.dataset.dirty = '1'; });
      el.addEventListener('blur', () => {
        if (!el.dataset.dirty || !this.selected) return;
        this.saveListField(field, el, el.value.trim());
      });
      if (field === 'name') {
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
      }
    });

    // Detail: delete / close
    $('.mlist-delete-btn', this)?.addEventListener('click', async () => {
      if (!this.selected) return;
      if (!confirm(`Delete "${this.selected.name}"? This removes the list and all its membership records. This cannot be undone.`)) return;
      try {
        const id = this.selected.id;
        await api(`/mailing-lists/${id}`, { method: 'DELETE' });
        this.lists = this.lists.filter((l) => l.id !== id);
        publish('toast.show', { message: 'List deleted.' });
        this.closeDetail();
        this.renderRows();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
    $('.outbox-close', this)?.addEventListener('click', () => this.closeDetail());

    // Members: search / status filter (debounced)
    const mSearch = $('.mlist-members-search', this);
    mSearch?.addEventListener('input', () => {
      clearTimeout(this._mDebounce);
      this._mDebounce = setTimeout(() => {
        this.mQuery = mSearch.value.trim();
        this.mPage = 1;
        this.loadMembers();
      }, 300);
    });
    const mFilter = $('.mlist-status-filter', this);
    mFilter?.addEventListener('change', () => {
      this.mStatus = mFilter.value;
      this.mPage = 1;
      this.loadMembers();
    });

    // Add-contacts: search (debounced) + add-selected button
    const acSearch = $('.mlist-add-contacts-search', this);
    acSearch?.addEventListener('input', () => {
      clearTimeout(this._acDebounce);
      this._acDebounce = setTimeout(() => this.searchContactsForAdd(acSearch.value.trim()), 300);
    });
    $('[data-add-selected]', this)?.addEventListener('click', () => this.addSelectedContacts());

    // Drag-to-resize handle between the list table and detail panes
    // (identical pattern to messages.js, distinct persisted pref key).
    const bar = $('.outbox-resize-bar', this);
    if (!bar) return;
    bar.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      bar.setPointerCapture(e.pointerId);
      this.classList.add('resizing');

      const body = $('.outbox-body', this);
      const detail = $('.outbox-detail-pane', this);
      const startY = e.clientY;
      const startH = detail.offsetHeight;

      const onMove = (ev) => {
        const delta = startY - ev.clientY;
        const bodyH = body.offsetHeight;
        const newH = Math.min(Math.max(startH + delta, 80), bodyH - 60);
        this.style.setProperty('--detail-h', `${newH}px`);
      };

      const onUp = () => {
        bar.removeEventListener('pointermove', onMove);
        bar.removeEventListener('pointerup', onUp);
        this.classList.remove('resizing');
        const h = this.style.getPropertyValue('--detail-h');
        if (h) window.PBConsent?.savePref('pb-mlist-detail-h', h);
      };

      bar.addEventListener('pointermove', onMove);
      bar.addEventListener('pointerup', onUp);
    });
  }
}

customElements.define('pb-msg-lists', MailingListsPage);
