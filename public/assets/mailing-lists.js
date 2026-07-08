import { esc, api, publish, PanicElement, addToggle, bindAddToggle, optedBadge, memberStatusBadge, $, $$ } from './core.js';

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

function fmtDateTime(raw) {
  if (!raw) return 'never';
  const d = new Date(String(raw).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const contactName = (c) => `${c.first_name || ''} ${c.last_name || ''}`.trim() || c.email || '(no name)';

// Shared between the "New list" create form and a segment list's rules panel
// in the detail pane — same 4 criteria fields (see ContactFilters on the
// backend), just rendered into two different forms.
function rulesFieldsHtml(values = {}) {
  const opted = values.opted !== undefined && values.opted !== null ? String(values.opted) : '';
  return `
    <label>Opted in
      <select name="opted">
        <option value="" ${opted === '' ? 'selected' : ''}>Any</option>
        <option value="1" ${opted === '1' ? 'selected' : ''}>Opted in only</option>
        <option value="0" ${opted === '0' ? 'selected' : ''}>Not opted in only</option>
      </select>
    </label>
    <label>Min spend ($) <input type="number" name="min_spend" min="0" step="1" value="${esc(values.min_spend ?? '')}" placeholder="e.g. 500"></label>
    <label>Min events <input type="number" name="min_events" min="0" step="1" value="${esc(values.min_events ?? '')}" placeholder="e.g. 3"></label>
    <label>Min tickets <input type="number" name="min_tickets" min="0" step="1" value="${esc(values.min_tickets ?? '')}" placeholder="e.g. 5"></label>
  `;
}

function readRulesFromForm(form) {
  const rules = {};
  const opted = form.elements.opted?.value;
  if (opted === '0' || opted === '1') rules.opted = opted;
  for (const key of ['min_spend', 'min_events', 'min_tickets']) {
    const raw = form.elements[key]?.value;
    if (raw !== '' && raw != null) rules[key] = Number(raw);
  }
  return rules;
}

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
    this.acResults    = [];
    this.acSelected   = new Set();
    this.acTotal      = 0;
    this.acOptedOnly  = false;
    this._acDebounce  = null;

    // CSV import sub-state
    this.importBusy = false;

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
    this.acTotal = 0;
    this.acOptedOnly = false;

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
          <fieldset class="mlist-type-fieldset">
            <legend>List type</legend>
            <label><input type="radio" name="list_type" value="static" checked> Static <span class="muted small">(you choose members)</span></label>
            <label><input type="radio" name="list_type" value="segment"> Smart <span class="muted small">(auto-updates from rules)</span></label>
          </fieldset>
          <div class="mlist-rules-form" data-new-rules-form hidden>${rulesFieldsHtml()}</div>
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
              <div class="mlist-detail-fields">
                <div class="mlist-name-wrap">
                  <input class="mlist-name-input" data-field="name" maxlength="160" aria-label="List name" placeholder="List name">
                  <div class="mlist-field-error" data-name-error hidden></div>
                </div>
                <input class="mlist-desc-input" type="text" data-field="description" maxlength="500" placeholder="Add a description…" aria-label="List description">
              </div>
              <dl class="outbox-meta-list">
                <div><dt>Members</dt><dd data-meta-count>0</dd></div>
                <div><dt>Created</dt><dd data-meta-created>—</dd></div>
              </dl>
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
                <div class="mlist-add-contacts-head">
                  <label class="outbox-search-label" aria-label="Search contacts to add">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input class="outbox-search mlist-add-contacts-search" type="search" placeholder="Search by name or email… (leave blank + Enter for everyone)" autocomplete="off">
                  </label>
                  <label class="check-label mlist-add-opted-only"><input type="checkbox" class="mlist-add-opted-filter"> Opted in only</label>
                  <button type="button" class="small" data-add-all-opted>Add everyone opted-in</button>
                </div>
                <ul class="mlist-add-contacts-results" hidden></ul>
                <div class="mlist-add-actions" hidden>
                  <label class="check-label mlist-add-select-all"><input type="checkbox" data-select-all-contacts disabled> Select all</label>
                  <div class="mlist-add-actions-buttons">
                    <button type="button" class="small" data-add-selected disabled>Add selected (0)</button>
                    <button type="button" class="small secondary" data-add-all-matching hidden>Add all matching</button>
                  </div>
                </div>
              </section>

              <section class="mlist-import-csv">
                <h3 class="mlist-h3">Import CSV</h3>
                <p class="muted small">Upload a CSV with an <code>email</code> column (plus optional <code>first_name</code>, <code>last_name</code>, <code>phone</code>, <code>opted_in</code>). New emails become new contacts; existing ones are matched and added to this list.</p>
                <form class="row-form mlist-import-form" data-import-form>
                  <input type="file" name="csv" accept=".csv,text/csv" required aria-label="CSV file">
                  <button type="submit" class="small">Import CSV</button>
                </form>
                <div class="mlist-import-result" data-import-result hidden></div>
              </section>

              <section class="mlist-segment-rules">
                <h3 class="mlist-h3">Segment rules</h3>
                <p class="mlist-segment-meta">This list's membership is computed automatically — add/remove contacts by editing the rules below, then refresh. Last refreshed <strong data-segment-refreshed>never</strong>.</p>
                <form class="row-form mlist-rules-edit-form" data-rules-edit-form>
                  <div class="mlist-rules-form">${rulesFieldsHtml()}</div>
                  <button type="submit" class="small">Save rules</button>
                  <button type="button" class="small secondary" data-refresh-segment>Refresh now</button>
                  <span class="mlist-field-error" data-rules-error hidden></span>
                </form>
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

    // A segment (smart) list's membership is fully computed — hide the
    // manual add paths and show the rules/refresh panel instead. A static
    // list is the reverse. list_type is immutable, so this never has to
    // handle a list switching sides mid-session.
    const isSegment = l.list_type === 'segment';
    const addContactsSection = $('.mlist-add-contacts', this);
    const importSection = $('.mlist-import-csv', this);
    const rulesSection = $('.mlist-segment-rules', this);
    if (addContactsSection) addContactsSection.hidden = isSegment;
    if (importSection) importSection.hidden = isSegment;
    if (rulesSection) rulesSection.hidden = !isSegment;

    if (isSegment) {
      const rulesForm = $('.mlist-rules-edit-form', this);
      if (rulesForm) {
        const rules = l.segment_rules || {};
        if (rulesForm.elements.opted) rulesForm.elements.opted.value = rules.opted !== undefined ? String(rules.opted) : '';
        if (rulesForm.elements.min_spend) rulesForm.elements.min_spend.value = rules.min_spend ?? '';
        if (rulesForm.elements.min_events) rulesForm.elements.min_events.value = rules.min_events ?? '';
        if (rulesForm.elements.min_tickets) rulesForm.elements.min_tickets.value = rules.min_tickets ?? '';
      }
      const rulesErr = $('[data-rules-error]', this);
      if (rulesErr) { rulesErr.hidden = true; rulesErr.textContent = ''; }
      const refreshedEl = $('[data-segment-refreshed]', this);
      if (refreshedEl) refreshedEl.textContent = fmtDateTime(l.segment_refreshed_at);
    }

    // Reset the members/add-contacts sub-panels' static controls.
    const mSearch = $('.mlist-members-search', this);
    if (mSearch) mSearch.value = '';
    const mFilter = $('.mlist-status-filter', this);
    if (mFilter) mFilter.value = '';
    const acSearch = $('.mlist-add-contacts-search', this);
    if (acSearch) acSearch.value = '';
    const acOptedFilter = $('.mlist-add-opted-filter', this);
    if (acOptedFilter) acOptedFilter.checked = false;
    this.resetAddContactsResults();
    const importResult = $('[data-import-result]', this);
    if (importResult) { importResult.hidden = true; importResult.innerHTML = ''; }
    $('.mlist-import-form', this)?.reset();

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

    // Segment lists compute their own membership (see /refresh) — manual
    // toggle/remove would just get overwritten on the next refresh, so those
    // controls are hidden rather than offered and silently discarded.
    const isSegment = this.selected?.list_type === 'segment';

    if (!this.members.length) {
      const empty = (this.mQuery || this.mStatus)
        ? 'No members match.'
        : (isSegment ? 'No contacts match this segment’s rules yet.' : 'No members yet — add contacts below.');
      tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">${esc(empty)}</td></tr>`;
    } else {
      tbody.innerHTML = this.members.map((m) => {
        const nextStatus = m.status === 'subscribed' ? 'unsubscribed' : 'subscribed';
        const toggleLabel = m.status === 'subscribed' ? 'Unsubscribe' : 'Resubscribe';
        const actions = isSegment
          ? '<span class="mlist-segment-badge"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Auto</span>'
          : `<button type="button" class="small secondary" data-toggle-status="${esc(nextStatus)}">${esc(toggleLabel)}</button>
             <button type="button" class="small danger" data-remove-member>Remove</button>`;
        return `<tr data-contact-id="${esc(m.contact_id)}">
          <td data-label="Name">${esc(contactName(m))}</td>
          <td data-label="Email">${esc(m.email || '—')}</td>
          <td data-label="Marketing">${optedBadge(m)}</td>
          <td data-label="Status">${memberStatusBadge(m.status)}</td>
          <td data-label="" class="mlist-member-actions">${actions}</td>
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
    // A blank query is a valid "browse" search — it returns every contact
    // (optionally narrowed by the opted-in filter) rather than nothing.
    // This only runs from explicit user interaction (typing, clearing the
    // search box, or toggling the opted-in filter) — see resetAddContactsResults()
    // for the panel's hidden default/reset state.
    try {
      const qs = new URLSearchParams({ page: '1', limit: '20' });
      if (query) qs.set('q', query);
      if (this.acOptedOnly) qs.set('opted', '1');
      const data = await api(`/contacts?${qs}`);
      this.acResults = data.contacts || [];
      this.acTotal = data.total || 0;
    } catch (err) {
      this.acResults = [];
      this.acTotal = 0;
      publish('toast.show', { message: err.message, tone: 'error' });
    }
    this.renderAddContactsResults();
  }

  /** Hides/clears the add-contacts results panel — used on first mount
   *  (before any search has been run) and whenever a different list is
   *  selected, so we don't show stale results from another list. */
  resetAddContactsResults() {
    this.acResults = [];
    this.acTotal = 0;
    const resultsEl = $('.mlist-add-contacts-results', this);
    if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
    const actionsEl = $('.mlist-add-actions', this);
    if (actionsEl) actionsEl.hidden = true;
    const selectAll = $('[data-select-all-contacts]', this);
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; selectAll.disabled = true; }
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
            <span class="mlist-contact-info">
              <strong>${esc(contactName(c))}</strong>
              <span class="muted">${esc(c.email || '—')}</span>
            </span>
            ${optedBadge(c)}
          </label>
        </li>`;
      }).join('');
    }
    actionsEl.hidden = false;
    this.updateAddSelectedButton();
    this.updateAddAllButton();
    this.updateSelectAllCheckbox();

    $$('[data-contact-checkbox]', resultsEl).forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = Number(cb.value);
        if (cb.checked) this.acSelected.add(id);
        else this.acSelected.delete(id);
        this.updateAddSelectedButton();
        this.updateSelectAllCheckbox();
      });
    });
  }

  updateAddSelectedButton() {
    const btn = $('[data-add-selected]', this);
    if (!btn) return;
    btn.textContent = `Add selected (${this.acSelected.size})`;
    btn.disabled = this.acSelected.size === 0;
  }

  /** Keeps the "Select all" checkbox in sync with the loaded result page —
   *  checked when every visible row is selected, indeterminate when some
   *  (but not all) are, and disabled while there's nothing to select. */
  updateSelectAllCheckbox() {
    const cb = $('[data-select-all-contacts]', this);
    if (!cb) return;
    const total = this.acResults.length;
    const selectedCount = this.acResults.filter((c) => this.acSelected.has(c.id)).length;
    cb.disabled = total === 0;
    cb.checked = total > 0 && selectedCount === total;
    cb.indeterminate = selectedCount > 0 && selectedCount < total;
  }

  updateAddAllButton() {
    const btn = $('[data-add-all-matching]', this);
    if (!btn) return;
    if (this.acTotal > 0) {
      btn.hidden = false;
      btn.textContent = `Add all ${this.acTotal.toLocaleString()} matching`;
    } else {
      btn.hidden = true;
    }
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
      const searchInput = $('.mlist-add-contacts-search', this);
      if (searchInput) searchInput.value = '';
      this.resetAddContactsResults();

      this.mPage = 1;
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  /** "Add all N matching" — resolves the whole search (not just the visible page) server-side. */
  async addAllMatching() {
    if (!this.selected || !this.acTotal) return;
    const query = $('.mlist-add-contacts-search', this)?.value.trim() || '';
    if (!confirm(`Add all ${this.acTotal.toLocaleString()} matching contacts to this list?`)) return;

    const btn = $('[data-add-all-matching]', this);
    if (btn) btn.disabled = true;
    try {
      const body = {};
      if (query) body.q = query;
      if (this.acOptedOnly) body.opted = '1';
      const { added } = await api(`/mailing-lists/${this.selected.id}/add-by-filter`, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      publish('toast.show', { message: `${added} contact${added === 1 ? '' : 's'} added.` });

      this.acSelected = new Set();
      const searchInput = $('.mlist-add-contacts-search', this);
      if (searchInput) searchInput.value = '';
      this.resetAddContactsResults();

      this.mPage = 1;
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // ── CSV import ───────────────────────────────────────────────────────────

  async importCsv(e) {
    e.preventDefault();
    if (!this.selected || this.importBusy) return;
    const form = e.target;
    const resultEl = $('[data-import-result]', this);
    const btn = $('button[type="submit"]', form);

    this.importBusy = true;
    if (btn) btn.disabled = true;
    if (resultEl) { resultEl.hidden = true; resultEl.innerHTML = ''; }

    try {
      const data = await api(`/mailing-lists/${this.selected.id}/import`, {
        method: 'POST',
        body: new FormData(form),
      });
      const parts = [
        `${data.created || 0} new contact${data.created === 1 ? '' : 's'}`,
        `${data.updated || 0} matched`,
        `${data.added_to_list || 0} added to list`,
      ];
      if (data.skipped) parts.push(`${data.skipped} row${data.skipped === 1 ? '' : 's'} skipped`);
      publish('toast.show', { message: parts.join(', ') + '.' });

      if (resultEl) {
        const errors = Array.isArray(data.errors) ? data.errors : [];
        resultEl.hidden = errors.length === 0;
        if (errors.length) {
          resultEl.innerHTML = `<p class="muted small">Rows with problems:</p>
            <ul class="mlist-import-errors">${errors.map((e) => `<li>Row ${esc(e.row)}: ${esc(e.message)}</li>`).join('')}</ul>`;
        }
      }

      form.reset();
      this.mPage = 1;
      await this.loadMembers();
      await this.syncMemberCount();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    } finally {
      this.importBusy = false;
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

    // New-list inline form: Static/Smart toggle reveals the rules mini-form
    const newRulesForm = $('[data-new-rules-form]', this);
    $$('.mlist-add-form input[name="list_type"]', this).forEach((radio) => {
      radio.addEventListener('change', () => {
        if (newRulesForm) newRulesForm.hidden = radio.value !== 'segment' || !radio.checked;
      });
    });

    const addForm = $('.mlist-add-form', this);
    addForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const name = form.elements.name.value.trim();
      const description = form.elements.description.value.trim();
      const listType = form.elements.list_type.value;
      const errEl = $('[data-create-error]', form);
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      if (!name) return;

      const body = { name, description };
      if (listType === 'segment') {
        body.list_type = 'segment';
        body.segment_rules = readRulesFromForm(form);
      }

      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        const { list } = await api('/mailing-lists', { method: 'POST', body: JSON.stringify(body) });
        this.lists = [list, ...this.lists];
        this.query = '';
        const outerSearch = $('.outbox-search:not(.mlist-members-search):not(.mlist-add-contacts-search)', this);
        if (outerSearch) outerSearch.value = '';
        form.reset();
        if (newRulesForm) newRulesForm.hidden = true;
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

    // Detail: segment-list rules edit + refresh
    $('.mlist-rules-edit-form', this)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!this.selected) return;
      const form = e.target;
      const errEl = $('[data-rules-error]', this);
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        const { list } = await api(`/mailing-lists/${this.selected.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ segment_rules: readRulesFromForm(form) }),
        });
        Object.assign(this.selected, list);
        const row = this.lists.find((x) => x.id === this.selected.id);
        if (row && row !== this.selected) Object.assign(row, list);
        publish('toast.show', { message: 'Rules saved and list refreshed.' });
        this.renderRows();
        $('[data-segment-refreshed]', this).textContent = fmtDateTime(list.segment_refreshed_at);
        $('[data-meta-count]', this).textContent = Number(list.member_count || 0).toLocaleString();
        this.mPage = 1;
        await this.loadMembers();
      } catch (err) {
        if (errEl) { errEl.textContent = err.message; errEl.hidden = false; }
        else publish('toast.show', { message: err.message, tone: 'error' });
      } finally {
        btn.disabled = false;
      }
    });
    $('[data-refresh-segment]', this)?.addEventListener('click', async () => {
      if (!this.selected) return;
      const btn = $('[data-refresh-segment]', this);
      btn.disabled = true;
      try {
        const { added, removed, total_matching: totalMatching } = await api(`/mailing-lists/${this.selected.id}/refresh`, { method: 'POST' });
        publish('toast.show', { message: `Refreshed: ${added} added, ${removed} removed, ${totalMatching} total matching.` });
        await this.syncMemberCount();
        $('[data-segment-refreshed]', this).textContent = fmtDateTime(new Date().toISOString());
        this.mPage = 1;
        await this.loadMembers();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
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

    // Add-contacts: search (debounced) + opted-only filter + add-selected / add-all buttons
    const acSearch = $('.mlist-add-contacts-search', this);
    acSearch?.addEventListener('input', () => {
      clearTimeout(this._acDebounce);
      this._acDebounce = setTimeout(() => this.searchContactsForAdd(acSearch.value.trim()), 300);
    });
    // Enter runs the search immediately (bypassing the debounce) even when
    // the box is empty, so "search blank to browse everyone" has an
    // explicit trigger and doesn't rely on having typed-then-cleared text.
    acSearch?.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      clearTimeout(this._acDebounce);
      this.searchContactsForAdd(acSearch.value.trim());
    });
    const acOptedFilter = $('.mlist-add-opted-filter', this);
    acOptedFilter?.addEventListener('change', () => {
      this.acOptedOnly = acOptedFilter.checked;
      // Re-run with whatever's in the search box — blank is fine, it just
      // browses everyone (optionally narrowed to opted-in).
      this.searchContactsForAdd(acSearch?.value.trim() || '');
    });
    $('[data-select-all-contacts]', this)?.addEventListener('change', (e) => {
      const cb = e.target;
      if (cb.checked) this.acResults.forEach((c) => this.acSelected.add(c.id));
      else this.acResults.forEach((c) => this.acSelected.delete(c.id));
      this.updateAddSelectedButton();
      this.updateSelectAllCheckbox();
      $$('[data-contact-checkbox]', this).forEach((rowCb) => { rowCb.checked = this.acSelected.has(Number(rowCb.value)); });
    });
    $('[data-add-selected]', this)?.addEventListener('click', () => this.addSelectedContacts());
    $('[data-add-all-matching]', this)?.addEventListener('click', () => this.addAllMatching());
    // "Add everyone opted-in" — a one-click shortcut that turns on the
    // opted-in filter, clears any text search, and runs the same
    // server-side "add all matching" bulk-add used elsewhere (with its
    // confirm-count dialog) so a whole opted-in audience can be added
    // without hand-picking contacts.
    $('[data-add-all-opted]', this)?.addEventListener('click', async () => {
      if (!this.selected) return;
      if (acSearch) acSearch.value = '';
      if (acOptedFilter) acOptedFilter.checked = true;
      this.acOptedOnly = true;
      await this.searchContactsForAdd('');
      if (!this.acTotal) {
        publish('toast.show', { message: 'No opted-in contacts found to add.' });
        return;
      }
      await this.addAllMatching();
    });

    // CSV import
    $('.mlist-import-form', this)?.addEventListener('submit', (e) => this.importCsv(e));

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
