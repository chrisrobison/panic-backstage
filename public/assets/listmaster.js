import { esc, api, apiUrl, getToken, publish, PanicElement, $, $$ } from './core.js';

// ── ListMaster ────────────────────────────────────────────────────────────────
// A from-scratch redesign of list management (sidebar of lists, stats cards,
// filterable/bulk-selectable member table, contact detail slide-over, plus
// Tags / Import History / Export History / Segments tool views), built to sit
// alongside the existing classic Mailing Lists page (pb-msg-lists / #lists —
// left entirely untouched) rather than replace it. Talks to the same
// src/MailingLists.php + src/Contacts.php endpoints that page uses, plus the
// new ContactTags.php / ContactStorage.php endpoints and the tag/activity/
// history/bounced-status additions made to MailingLists.php and Contacts.php
// for this page specifically (see database/migrations/055_listmaster_extras.sql).
//
// Layout reuses the app's #app.workspace-outbox full-bleed shell mechanism
// (same one Outbox/Messages/classic Lists use) — see the CSS block in
// app.css starting "── ListMaster ──".

const AVATAR_COLORS = ['#2563eb', '#7c3aed', '#0f8f46', '#d99100', '#dc2626', '#0891b2', '#c026d3', '#4f46e5'];

function fullName(c) {
  return `${c.first_name || ''} ${c.last_name || ''}`.trim() || c.email || '(no name)';
}

function initials(c) {
  const a = (c.first_name || '').trim()[0] || '';
  const b = (c.last_name || '').trim()[0] || '';
  return (a + b).toUpperCase() || (c.email || '?')[0].toUpperCase();
}

function hashSeed(value) {
  let h = 0;
  const s = String(value || '');
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return h;
}

function avatarColor(c) {
  return AVATAR_COLORS[hashSeed(c.id ?? c.contact_id ?? fullName(c)) % AVATAR_COLORS.length];
}

function avatar(c, big) {
  return `<span class="lm-avatar${big ? ' lm-avatar-lg' : ''}" style="background:${avatarColor(c)}">${esc(initials(c))}</span>`;
}

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

// "1y 4m" style relative age, used next to a contact's join date.
function relativeAge(raw) {
  if (!raw) return '';
  const then = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(then.getTime())) return '';
  const now = new Date();
  let months = (now.getFullYear() - then.getFullYear()) * 12 + (now.getMonth() - then.getMonth());
  if (now.getDate() < then.getDate()) months--;
  months = Math.max(0, months);
  const years = Math.floor(months / 12);
  const rem = months % 12;
  if (years === 0 && rem === 0) return 'this month';
  const parts = [];
  if (years) parts.push(`${years}y`);
  if (rem) parts.push(`${rem}m`);
  return parts.join(' ');
}

function timeAgo(raw) {
  if (!raw) return '';
  const then = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(then.getTime())) return '';
  const seconds = Math.max(0, (Date.now() - then.getTime()) / 1000);
  const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
  for (const [name, secs] of units) {
    const n = Math.floor(seconds / secs);
    if (n >= 1) return `${n} ${name}${n > 1 ? 's' : ''} ago`;
  }
  return 'just now';
}

const STATUS_LABEL = { subscribed: 'Active', unsubscribed: 'Unsubscribed', bounced: 'Bounced' };
const STATUS_BADGE_CLASS = { subscribed: 'status-confirmed', unsubscribed: 'status-needs_assets', bounced: 'status-canceled' };

function statusBadge(status) {
  return `<span class="badge ${STATUS_BADGE_CLASS[status] || 'status-empty'}">${esc(STATUS_LABEL[status] || status)}</span>`;
}

function tagPill(tag, removable) {
  return `<span class="lm-tag-pill" style="background:${esc(tag.color)}22;color:${esc(tag.color)}">
    ${esc(tag.name)}${removable ? `<i class="fa-solid fa-xmark lm-tag-remove" data-remove-tag="${esc(tag.id)}" title="Remove tag"></i>` : ''}
  </span>`;
}

const ACTIVITY_ICON = {
  list_joined: 'fa-user-plus', list_left: 'fa-user-minus', status_changed: 'fa-rotate',
  tag_added: 'fa-tag', tag_removed: 'fa-tag', contact_created: 'fa-star', contact_updated: 'fa-pen',
};

class ListMasterPage extends PanicElement {
  connect() {
    this.lists = [];
    this.tags = [];
    this.storage = null;
    this.selectedListId = null;
    this.selectedList = null;
    this.view = 'list'; // 'list' | 'tags' | 'import-history' | 'export-history' | 'segments'
    this.topSearch = '';

    this.members = [];
    this.mStatus = '';
    this.mTag = '';
    this.mPage = 1;
    this.mLimit = 25;
    this.mTotal = 0;
    this.mPages = 1;
    this.selectedContactIds = new Set();
    this.columns = this.loadColumnPrefs();

    this.detailContact = null;
    this.detailMemberships = [];
    this.detailActivity = null;
    this.detailTab = 'details';

    this.historyRows = [];
    this.historyTotal = 0;
    this.historyPage = 1;
    this.historyListFilter = '';

    this._debounce = null;
    this._acDebounce = null;

    this._app = document.getElementById('app');
    if (this._app) this._app.classList.add('workspace-outbox');
    publish('page.context', { title: 'ListMaster', blurb: 'Sidebar-of-lists list management, tags, and audit trail for your mailing lists.' });

    this.renderShell();
    this.bindGlobalEvents();
    this.bootstrap();
  }

  disconnectedCallback() {
    this._app?.classList.remove('workspace-outbox');
    this.abort?.abort();
  }

  loadColumnPrefs() {
    try {
      const saved = JSON.parse(localStorage.getItem('pb-listmaster-columns') || '{}');
      return { tags: saved.tags !== false, joined: saved.joined !== false, lists: saved.lists !== false };
    } catch { return { tags: true, joined: true, lists: true }; }
  }

  saveColumnPrefs() {
    window.PBConsent?.savePref('pb-listmaster-columns', JSON.stringify(this.columns));
  }

  async bootstrap() {
    await Promise.all([this.loadLists(), this.loadTags(), this.loadStorage()]);
    if (this.lists.length) await this.selectList(this.lists[0].id);
    else this.renderContent();
    this.renderSidebar();
  }

  // ── Data loading ──────────────────────────────────────────────────────────

  async loadLists() {
    try {
      const data = await api('/mailing-lists');
      this.lists = data.lists || [];
    } catch (err) {
      publish('toast.show', { message: `Failed to load lists: ${err.message}`, tone: 'error' });
      this.lists = [];
    }
  }

  async loadTags() {
    try {
      const data = await api('/contact-tags');
      this.tags = data.tags || [];
    } catch { this.tags = []; }
  }

  async loadStorage() {
    try {
      this.storage = await api('/contact-storage');
    } catch { this.storage = null; }
    this.renderStorageCard();
  }

  async selectList(id) {
    this.view = 'list';
    this.selectedListId = id;
    this.selectedList = this.lists.find((l) => l.id === id) || null;
    if (!this.selectedList) {
      try {
        const data = await api(`/mailing-lists/${id}`);
        this.selectedList = data.list;
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
        return;
      }
    }
    this.mStatus = '';
    this.mTag = '';
    this.mPage = 1;
    this.selectedContactIds.clear();
    this.closeDetail();
    this.renderSidebar();
    await this.loadMembers();
    this.renderContent();
  }

  async loadMembers() {
    if (!this.selectedListId) return;
    try {
      const qs = new URLSearchParams({ page: String(this.mPage), limit: String(this.mLimit) });
      if (this.topSearch) qs.set('q', this.topSearch);
      if (this.mStatus) qs.set('status', this.mStatus);
      if (this.mTag) qs.set('tag', this.mTag);
      const data = await api(`/mailing-lists/${this.selectedListId}/members?${qs}`);
      this.members = data.members || [];
      this.mTotal = data.total || 0;
      this.mPage = data.page || this.mPage;
      this.mPages = data.pages || 1;
    } catch (err) {
      this.members = [];
      publish('toast.show', { message: `Failed to load members: ${err.message}`, tone: 'error' });
    }
  }

  async refreshSelectedListStats() {
    if (!this.selectedListId) return;
    try {
      const { list } = await api(`/mailing-lists/${this.selectedListId}`);
      this.selectedList = list;
      const row = this.lists.find((l) => l.id === list.id);
      if (row) Object.assign(row, list);
      this.renderSidebar();
      this.renderStatsRow();
    } catch { /* non-fatal */ }
  }

  // ── Shell / top-level render ─────────────────────────────────────────────

  renderShell() {
    this.innerHTML = `
      <div class="lm-toolbar">
        <div class="lm-toolbar-actions">
          <button type="button" class="lm-btn lm-btn-primary" data-open-create><i class="fa-solid fa-plus" aria-hidden="true"></i> Create List</button>
          <button type="button" class="lm-btn" data-open-import><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i> Import CSV</button>
          <button type="button" class="lm-btn" data-export-list><i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Export List</button>
        </div>
        <label class="lm-search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" data-top-search placeholder="Search members or lists…" aria-label="Search members or lists">
          <kbd>⌘K</kbd>
        </label>
      </div>
      <div class="lm-body">
        <aside class="lm-sidebar">
          <div class="lm-sidebar-label">Mailing Lists</div>
          <nav class="lm-list-nav" data-list-nav></nav>
          <button type="button" class="lm-create-link" data-open-create><i class="fa-solid fa-plus" aria-hidden="true"></i> Create New List</button>

          <div class="lm-sidebar-label lm-tools-label">Tools</div>
          <nav class="lm-tools-nav">
            <button type="button" class="lm-tool-item" data-tool="import-history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Import History</button>
            <button type="button" class="lm-tool-item" data-tool="export-history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Export History</button>
            <button type="button" class="lm-tool-item" data-tool="tags"><i class="fa-solid fa-tags" aria-hidden="true"></i> Tags</button>
            <button type="button" class="lm-tool-item" data-tool="segments"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Segments</button>
          </nav>

          <div class="lm-sidebar-spacer"></div>
          <div class="lm-storage-card" data-storage-card></div>
        </aside>

        <div class="lm-main">
          <div class="lm-content" data-content></div>
        </div>

        <aside class="lm-detail" data-detail hidden></aside>
      </div>
    `;
    this.bindShellEvents();
  }

  bindShellEvents() {
    $('[data-open-create]', this)?.addEventListener('click', () => this.openCreateListModal());
    $$('[data-open-create]', this).forEach((btn) => btn.addEventListener('click', () => this.openCreateListModal()));
    $('[data-open-import]', this)?.addEventListener('click', () => this.openImportModal());
    $('[data-export-list]', this)?.addEventListener('click', () => this.exportSelectedList());

    $$('.lm-tool-item', this).forEach((btn) => btn.addEventListener('click', () => this.openTool(btn.dataset.tool)));

    const search = $('[data-top-search]', this);
    search?.addEventListener('input', () => {
      clearTimeout(this._debounce);
      this._debounce = setTimeout(() => {
        this.topSearch = search.value.trim();
        this.renderSidebar();
        if (this.view === 'list' && this.selectedListId) {
          this.mPage = 1;
          this.loadMembers().then(() => this.renderContent());
        }
      }, 280);
    });
  }

  bindGlobalEvents() {
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        $('[data-top-search]', this)?.focus();
      }
      if (e.key === 'Escape') this.closeDetail();
    }, { signal: this.abort.signal });

    // Close any open .lm-dropdown menu when clicking outside it.
    document.addEventListener('click', (e) => {
      $$('.lm-dropdown', this).forEach((dd) => {
        if (!dd.contains(e.target)) $('.lm-dropdown-menu', dd)?.setAttribute('hidden', '');
      });
    }, { signal: this.abort.signal });
  }

  toggleDropdown(menu) {
    const willOpen = menu.hasAttribute('hidden');
    $$('.lm-dropdown-menu', this).forEach((m) => m.setAttribute('hidden', ''));
    if (willOpen) menu.removeAttribute('hidden');
  }

  // ── Sidebar ───────────────────────────────────────────────────────────────

  renderSidebar() {
    const nav = $('[data-list-nav]', this);
    if (!nav) return;
    const q = this.topSearch.toLowerCase();
    const rows = q ? this.lists.filter((l) => l.name.toLowerCase().includes(q)) : this.lists;

    if (!rows.length) {
      nav.innerHTML = `<p class="lm-cell-muted" style="padding:8px 10px;font-size:12.5px;">${q ? 'No lists match.' : 'No lists yet.'}</p>`;
    } else {
      nav.innerHTML = rows.map((l) => `
        <button type="button" class="lm-list-item${l.id === this.selectedListId ? ' active' : ''}" data-select-list="${esc(l.id)}">
          <i class="fa-solid ${l.list_type === 'segment' ? 'fa-wand-magic-sparkles' : 'fa-envelope'}" aria-hidden="true"></i>
          <span class="lm-list-name">${esc(l.name)}</span>
          <span class="lm-list-count">${Number(l.member_count || 0).toLocaleString()}</span>
        </button>
      `).join('');
      $$('[data-select-list]', nav).forEach((btn) => btn.addEventListener('click', () => this.selectList(Number(btn.dataset.selectList))));
    }

    $$('.lm-tool-item', this).forEach((btn) => btn.classList.toggle('active', this.view === btn.dataset.tool));
  }

  renderStorageCard() {
    const el = $('[data-storage-card]', this);
    if (!el) return;
    if (!this.storage) { el.innerHTML = ''; return; }
    const { used, limit, percent } = this.storage;
    el.innerHTML = `
      <div class="lm-storage-head"><strong>List storage</strong><span class="lm-storage-pct">${percent}% used</span></div>
      <div class="lm-storage-bar"><div class="lm-storage-bar-fill${percent >= 90 ? ' lm-storage-hot' : ''}" style="width:${Math.min(100, percent)}%"></div></div>
      <div class="lm-storage-sub">${used.toLocaleString()} of ${limit.toLocaleString()} contacts</div>
      <button type="button" class="lm-storage-edit" data-edit-storage-limit>Edit limit</button>
    `;
    $('[data-edit-storage-limit]', el)?.addEventListener('click', () => this.editStorageLimit());
  }

  async editStorageLimit() {
    const next = prompt('Contact storage limit:', String(this.storage?.limit ?? 250000));
    if (next === null) return;
    const n = Number(next);
    if (!Number.isFinite(n) || n < 0) { publish('toast.show', { message: 'Enter a non-negative number.', tone: 'error' }); return; }
    try {
      this.storage = await api('/contact-storage', { method: 'PATCH', body: JSON.stringify({ limit: n }) });
      this.renderStorageCard();
      publish('toast.show', { message: 'Storage limit updated.' });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Tool switching ───────────────────────────────────────────────────────

  openTool(tool) {
    this.view = tool;
    this.closeDetail();
    this.renderSidebar();
    if (tool === 'import-history') this.loadHistory('import-history');
    else if (tool === 'export-history') this.loadHistory('export-history');
    else this.renderContent();
  }

  // ── Content dispatch ─────────────────────────────────────────────────────

  renderContent() {
    const el = $('[data-content]', this);
    if (!el) return;
    if (this.view === 'tags') return this.renderTagsView(el);
    if (this.view === 'segments') return this.renderSegmentsView(el);
    if (this.view === 'import-history') return this.renderHistoryView(el, 'import-history');
    if (this.view === 'export-history') return this.renderHistoryView(el, 'export-history');
    return this.renderListView(el);
  }

  // ── List view (member table) ─────────────────────────────────────────────

  renderListView(el) {
    if (!this.selectedList) {
      el.innerHTML = `<div class="lm-empty-state"><i class="fa-regular fa-envelope-open" aria-hidden="true"></i>No mailing lists yet — create one to get started.</div>`;
      return;
    }
    const l = this.selectedList;
    const isSegment = l.list_type === 'segment';
    const total = Number(l.total_count || 0);
    const active = Number(l.member_count || 0);
    const unsub = Number(l.unsubscribed_count || 0);
    const bounced = Number(l.bounced_count || 0);
    const pct = (n) => (total ? ((n / total) * 100).toFixed(1) : '0.0');

    el.innerHTML = `
      <div class="lm-list-header">
        <i class="fa-solid ${isSegment ? 'fa-wand-magic-sparkles' : 'fa-envelope'} lm-list-icon" aria-hidden="true"></i>
        <h2>${esc(l.name)}</h2>
        ${isSegment ? '<span class="badge status-published">Smart list</span>' : ''}
        ${l.description ? `<span class="lm-list-desc">${esc(l.description)}</span>` : ''}
        <div class="lm-list-header-actions">
          ${isSegment ? '<button type="button" class="lm-btn lm-btn-small" data-refresh-segment><i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh</button>' : ''}
          <button type="button" class="lm-btn lm-btn-small lm-btn-danger" data-delete-list><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete list</button>
        </div>
      </div>

      <div class="lm-stats-row">
        <div class="lm-stat-card"><div class="lm-stat-label">Total Members</div><div class="lm-stat-value">${total.toLocaleString()}</div></div>
        <div class="lm-stat-card"><div class="lm-stat-label">Active</div><div class="lm-stat-value lm-tone-green">${active.toLocaleString()}<span class="lm-stat-sub">${pct(active)}%</span></div></div>
        <div class="lm-stat-card"><div class="lm-stat-label">Unsubscribed</div><div class="lm-stat-value lm-tone-amber">${unsub.toLocaleString()}<span class="lm-stat-sub">${pct(unsub)}%</span></div></div>
        <div class="lm-stat-card"><div class="lm-stat-label">Bounced</div><div class="lm-stat-value lm-tone-red">${bounced.toLocaleString()}<span class="lm-stat-sub">${pct(bounced)}%</span></div></div>
        <div class="lm-stat-card lm-stat-date"><div class="lm-stat-label">Last Updated</div><div class="lm-stat-value">${esc(fmtDateTime(l.last_member_update))}</div></div>
      </div>

      ${isSegment ? '<p class="lm-cell-muted" style="font-size:12.5px;">This list\'s membership is computed automatically from its rules — manual add/remove/tag actions below are disabled. Edit rules from the classic Lists page.</p>' : this.bulkBarHtml()}

      <div class="lm-filter-bar">
        <span class="lm-filter-chip">All Filters ${this.mStatus || this.mTag ? `<span class="lm-filter-badge">${(this.mStatus ? 1 : 0) + (this.mTag ? 1 : 0)}</span>` : ''}</span>
        <select data-status-filter aria-label="Filter by status">
          <option value="">Status: All</option>
          <option value="subscribed" ${this.mStatus === 'subscribed' ? 'selected' : ''}>Active</option>
          <option value="unsubscribed" ${this.mStatus === 'unsubscribed' ? 'selected' : ''}>Unsubscribed</option>
          <option value="bounced" ${this.mStatus === 'bounced' ? 'selected' : ''}>Bounced</option>
        </select>
        <select data-tag-filter aria-label="Filter by tag">
          <option value="">Tag: Any</option>
          ${this.tags.map((t) => `<option value="${esc(t.id)}" ${String(this.mTag) === String(t.id) ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}
        </select>
        ${(this.mStatus || this.mTag) ? '<button type="button" class="lm-clear-filters" data-clear-filters>Clear all</button>' : ''}
        <span class="lm-filter-spacer"></span>
        <div class="lm-dropdown">
          <button type="button" class="lm-btn lm-btn-small" data-toggle-dropdown="columns"><i class="fa-solid fa-table-columns" aria-hidden="true"></i> Columns</button>
          <div class="lm-dropdown-menu" data-dropdown="columns" hidden>
            <label class="lm-dropdown-item"><input type="checkbox" data-col="tags" ${this.columns.tags ? 'checked' : ''}> Tags</label>
            <label class="lm-dropdown-item"><input type="checkbox" data-col="joined" ${this.columns.joined ? 'checked' : ''}> Joined</label>
            <label class="lm-dropdown-item"><input type="checkbox" data-col="lists" ${this.columns.lists ? 'checked' : ''}> Lists / Membership</label>
          </div>
        </div>
      </div>

      <div class="lm-table-wrap">${this.membersTableHtml()}</div>
      <div class="lm-pager" data-pager></div>
    `;

    this.bindListViewEvents(el);
    this.renderPager();
  }

  bulkBarHtml() {
    const n = this.selectedContactIds.size;
    return `
      <div class="lm-bulk-bar">
        <div class="lm-dropdown">
          <button type="button" class="lm-btn lm-btn-small lm-btn-primary" data-toggle-dropdown="add-members"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add Members</button>
          <div class="lm-dropdown-menu" data-dropdown="add-members" hidden>
            <button type="button" data-open-add-members><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search &amp; add contacts…</button>
            <button type="button" data-add-all-opted><i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i> Add everyone opted-in</button>
          </div>
        </div>
        <button type="button" class="lm-btn lm-btn-small" data-bulk-remove ${n ? '' : 'disabled'}><i class="fa-solid fa-user-minus" aria-hidden="true"></i> Remove from List</button>
        <div class="lm-dropdown">
          <button type="button" class="lm-btn lm-btn-small" data-toggle-dropdown="move" ${n ? '' : 'disabled'}><i class="fa-solid fa-right-left" aria-hidden="true"></i> Move</button>
          <div class="lm-dropdown-menu" data-dropdown="move" hidden>
            ${this.lists.filter((l) => l.id !== this.selectedListId).map((l) => `<button type="button" data-move-to="${esc(l.id)}">${esc(l.name)}</button>`).join('') || '<span class="lm-dropdown-empty">No other lists</span>'}
          </div>
        </div>
        <div class="lm-dropdown">
          <button type="button" class="lm-btn lm-btn-small" data-toggle-dropdown="assign-tags" ${n ? '' : 'disabled'}><i class="fa-solid fa-tags" aria-hidden="true"></i> Assign Tags</button>
          <div class="lm-dropdown-menu" data-dropdown="assign-tags" hidden>
            ${this.tags.map((t) => `<button type="button" data-assign-tag="${esc(t.id)}">${tagPill(t)}</button>`).join('')}
            <div class="lm-dropdown-search"><input type="text" data-new-tag-name placeholder="New tag name…"></div>
            <button type="button" data-assign-new-tag><i class="fa-solid fa-plus" aria-hidden="true"></i> Create &amp; assign</button>
          </div>
        </div>
        <div class="lm-dropdown">
          <button type="button" class="lm-btn lm-btn-small" data-toggle-dropdown="more"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
          <div class="lm-dropdown-menu" data-dropdown="more" hidden>
            <button type="button" data-bulk-status="subscribed" ${n ? '' : 'disabled'}><i class="fa-solid fa-check" aria-hidden="true"></i> Mark Active</button>
            <button type="button" data-bulk-status="unsubscribed" ${n ? '' : 'disabled'}><i class="fa-solid fa-ban" aria-hidden="true"></i> Mark Unsubscribed</button>
            <button type="button" data-bulk-status="bounced" ${n ? '' : 'disabled'}><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Mark Bounced</button>
          </div>
        </div>
        <span class="lm-bulk-count">${n ? `${n} selected` : 'No members selected'}</span>
      </div>
    `;
  }

  membersTableHtml() {
    if (!this.members.length) {
      return `<div class="lm-empty-state"><i class="fa-regular fa-user" aria-hidden="true"></i>${this.topSearch || this.mStatus || this.mTag ? 'No members match.' : 'No members yet.'}</div>`;
    }
    const allSelected = this.members.length > 0 && this.members.every((m) => this.selectedContactIds.has(m.contact_id));
    return `<table class="data-table lm-table">
      <thead><tr>
        <th><input type="checkbox" data-select-all-rows ${allSelected ? 'checked' : ''} aria-label="Select all members on this page"></th>
        <th>Name</th>
        <th>Email</th>
        <th>Status</th>
        ${this.columns.tags ? '<th>Tags</th>' : ''}
        ${this.columns.joined ? '<th>Joined</th>' : ''}
        ${this.columns.lists ? '<th>Lists / Membership</th>' : ''}
      </tr></thead>
      <tbody>${this.members.map((m) => this.memberRowHtml(m)).join('')}</tbody>
    </table>`;
  }

  memberRowHtml(m) {
    const checked = this.selectedContactIds.has(m.contact_id);
    return `<tr data-contact-id="${esc(m.contact_id)}" class="${checked ? 'lm-row-selected' : ''}">
      <td><input type="checkbox" data-row-check value="${esc(m.contact_id)}" ${checked ? 'checked' : ''} aria-label="Select ${esc(fullName(m))}"></td>
      <td><div class="lm-row-name">${avatar(m)}<span>${esc(fullName(m))}</span></div></td>
      <td>${esc(m.email || '—')}</td>
      <td>${statusBadge(m.status)}</td>
      ${this.columns.tags ? `<td><div class="lm-row-tags">${(m.tags || []).map((t) => tagPill(t)).join('') || '<span class="lm-empty-cell">—</span>'}</div></td>` : ''}
      ${this.columns.joined ? `<td class="lm-cell-muted">${esc(fmtDate(m.added_at))}</td>` : ''}
      ${this.columns.lists ? `<td>${Number(m.lists_count || 0)} list${Number(m.lists_count) === 1 ? '' : 's'}</td>` : ''}
    </tr>`;
  }

  renderPager() {
    const el = $('[data-pager]', this);
    if (!el) return;
    if (!this.mTotal) { el.innerHTML = ''; return; }
    const start = (this.mPage - 1) * this.mLimit + 1;
    const end = Math.min(this.mPage * this.mLimit, this.mTotal);
    el.innerHTML = `
      <span>Showing ${start}–${end} of ${this.mTotal.toLocaleString()}</span>
      <button type="button" class="lm-btn lm-btn-small" data-page-prev ${this.mPage <= 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
      <span>${this.mPage} / ${this.mPages}</span>
      <button type="button" class="lm-btn lm-btn-small" data-page-next ${this.mPage >= this.mPages ? 'disabled' : ''}><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
    `;
    $('[data-page-prev]', el)?.addEventListener('click', async () => { if (this.mPage > 1) { this.mPage--; await this.loadMembers(); this.renderContent(); } });
    $('[data-page-next]', el)?.addEventListener('click', async () => { if (this.mPage < this.mPages) { this.mPage++; await this.loadMembers(); this.renderContent(); } });
  }

  bindListViewEvents(el) {
    $('[data-refresh-segment]', el)?.addEventListener('click', () => this.refreshSegment());
    $('[data-delete-list]', el)?.addEventListener('click', () => this.deleteSelectedList());

    $('[data-status-filter]', el)?.addEventListener('change', async (e) => {
      this.mStatus = e.target.value;
      this.mPage = 1;
      await this.loadMembers();
      this.renderContent();
    });
    $('[data-tag-filter]', el)?.addEventListener('change', async (e) => {
      this.mTag = e.target.value;
      this.mPage = 1;
      await this.loadMembers();
      this.renderContent();
    });
    $('[data-clear-filters]', el)?.addEventListener('click', async () => {
      this.mStatus = '';
      this.mTag = '';
      this.mPage = 1;
      await this.loadMembers();
      this.renderContent();
    });

    $$('[data-toggle-dropdown]', el).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const menu = $(`[data-dropdown="${btn.dataset.toggleDropdown}"]`, el);
        if (menu) this.toggleDropdown(menu);
      });
    });
    $$('[data-col]', el).forEach((cb) => cb.addEventListener('change', () => {
      this.columns[cb.dataset.col] = cb.checked;
      this.saveColumnPrefs();
      this.renderContent();
    }));

    $('[data-open-add-members]', el)?.addEventListener('click', () => this.openAddMembersModal());
    $('[data-add-all-opted]', el)?.addEventListener('click', () => this.addAllOptedIn());
    $$('[data-move-to]', el).forEach((btn) => btn.addEventListener('click', () => this.moveSelectedTo(Number(btn.dataset.moveTo))));
    $$('[data-assign-tag]', el).forEach((btn) => btn.addEventListener('click', () => this.assignTagToSelected(Number(btn.dataset.assignTag))));
    $('[data-assign-new-tag]', el)?.addEventListener('click', () => {
      const input = $('[data-new-tag-name]', el);
      const name = input?.value.trim();
      if (!name) return;
      this.assignTagToSelected(null, name);
    });
    $('[data-bulk-remove]', el)?.addEventListener('click', () => this.bulkRemove());
    $$('[data-bulk-status]', el).forEach((btn) => btn.addEventListener('click', () => this.bulkStatus(btn.dataset.bulkStatus)));

    $('[data-select-all-rows]', el)?.addEventListener('change', (e) => {
      if (e.target.checked) this.members.forEach((m) => this.selectedContactIds.add(m.contact_id));
      else this.members.forEach((m) => this.selectedContactIds.delete(m.contact_id));
      this.renderContent();
    });
    $$('[data-row-check]', el).forEach((cb) => cb.addEventListener('click', (e) => e.stopPropagation()));
    $$('[data-row-check]', el).forEach((cb) => cb.addEventListener('change', () => {
      const id = Number(cb.value);
      if (cb.checked) this.selectedContactIds.add(id); else this.selectedContactIds.delete(id);
      this.renderContent();
    }));
    $$('tbody tr[data-contact-id]', el).forEach((row) => {
      row.addEventListener('click', () => this.openDetail(Number(row.dataset.contactId)));
    });
  }

  // ── Bulk actions ──────────────────────────────────────────────────────────

  async afterBulkChange() {
    this.selectedContactIds.clear();
    await this.loadMembers();
    this.renderContent();
    await this.refreshSelectedListStats();
  }

  async bulkRemove() {
    const ids = [...this.selectedContactIds];
    if (!ids.length) return;
    if (!confirm(`Remove ${ids.length} member${ids.length === 1 ? '' : 's'} from "${this.selectedList.name}"? This fully deletes their membership.`)) return;
    try {
      const { removed } = await api(`/mailing-lists/${this.selectedListId}/members`, { method: 'DELETE', body: JSON.stringify({ contact_ids: ids }) });
      publish('toast.show', { message: `${removed} removed from list.` });
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async bulkStatus(status) {
    const ids = [...this.selectedContactIds];
    if (!ids.length) return;
    try {
      const { updated } = await api(`/mailing-lists/${this.selectedListId}/members`, { method: 'PATCH', body: JSON.stringify({ contact_ids: ids, status }) });
      publish('toast.show', { message: `${updated} marked ${STATUS_LABEL[status].toLowerCase()}.` });
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async moveSelectedTo(targetListId) {
    const ids = [...this.selectedContactIds];
    if (!ids.length) return;
    const target = this.lists.find((l) => l.id === targetListId);
    if (!confirm(`Move ${ids.length} member${ids.length === 1 ? '' : 's'} to "${target?.name}"? They'll be added there and removed from "${this.selectedList.name}".`)) return;
    try {
      await api(`/mailing-lists/${targetListId}/members`, { method: 'POST', body: JSON.stringify({ contact_ids: ids }) });
      await api(`/mailing-lists/${this.selectedListId}/members`, { method: 'DELETE', body: JSON.stringify({ contact_ids: ids }) });
      publish('toast.show', { message: `Moved ${ids.length} member${ids.length === 1 ? '' : 's'} to "${target?.name}".` });
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async assignTagToSelected(tagId, newName) {
    const ids = [...this.selectedContactIds];
    if (!ids.length) return;
    try {
      const body = { contact_ids: ids };
      if (tagId) body.tag_id = tagId; else body.name = newName;
      const { tagged, tag } = await api('/contacts/bulk-tag', { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: `Tagged ${tagged} contact${tagged === 1 ? '' : 's'} with "${tag.name}".` });
      await this.loadTags();
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async addAllOptedIn() {
    if (!confirm('Add every opted-in contact to this list?')) return;
    try {
      const { added } = await api(`/mailing-lists/${this.selectedListId}/add-by-filter`, { method: 'POST', body: JSON.stringify({ opted: '1' }) });
      publish('toast.show', { message: `${added} opted-in contact${added === 1 ? '' : 's'} added.` });
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async refreshSegment() {
    try {
      const { added, removed, total_matching: total } = await api(`/mailing-lists/${this.selectedListId}/refresh`, { method: 'POST' });
      publish('toast.show', { message: `Refreshed: ${added} added, ${removed} removed, ${total} total matching.` });
      await this.afterBulkChange();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async deleteSelectedList() {
    const l = this.selectedList;
    if (!l) return;
    if (!confirm(`Delete "${l.name}"? This removes the list and all its membership records. This cannot be undone.`)) return;
    try {
      await api(`/mailing-lists/${l.id}`, { method: 'DELETE' });
      this.lists = this.lists.filter((x) => x.id !== l.id);
      this.selectedListId = null;
      this.selectedList = null;
      this.closeDetail();
      publish('toast.show', { message: `"${l.name}" deleted.` });
      if (this.lists.length) await this.selectList(this.lists[0].id);
      else { this.renderSidebar(); this.renderContent(); }
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async exportSelectedList() {
    if (!this.selectedListId) { publish('toast.show', { message: 'Select a list first.', tone: 'error' }); return; }
    try {
      const qs = new URLSearchParams();
      if (this.topSearch) qs.set('q', this.topSearch);
      if (this.mStatus) qs.set('status', this.mStatus);
      if (this.mTag) qs.set('tag', this.mTag);
      const resp = await fetch(apiUrl(`/mailing-lists/${this.selectedListId}/export-members?${qs}`), {
        headers: { Authorization: `Bearer ${getToken()}` },
      });
      if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        throw new Error(err.error || `Export failed (${resp.status})`);
      }
      const blob = await resp.blob();
      const stamp = new Date().toISOString().slice(0, 10);
      const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob),
        download: `${(this.selectedList?.name || 'list').replace(/[^a-z0-9]+/gi, '-')}-members-${stamp}.csv`,
      });
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(a.href);
      publish('toast.show', { message: 'Export downloaded.' });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Add-members modal ────────────────────────────────────────────────────

  openAddMembersModal() {
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Add contacts to "${esc(this.selectedList.name)}"</h2><button class="small secondary" type="button" data-close>Close</button></div>
      <div class="padded">
        <label class="outbox-search-label" style="margin-bottom:10px;display:flex;">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" data-ac-search placeholder="Search by name or email… (blank + Enter for everyone)" autocomplete="off" style="flex:1;border:none;outline:none;">
        </label>
        <label class="check-label"><input type="checkbox" data-ac-opted> Opted in only</label>
        <ul class="lm-modal-search-results" data-ac-results hidden></ul>
        <div class="form-actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;">
          <button type="button" class="small" data-ac-add-selected disabled>Add selected (0)</button>
          <button type="button" class="small secondary" data-ac-add-all hidden>Add all matching</button>
        </div>
      </div>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((b) => b.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

    const state = { results: [], total: 0, selected: new Set(), optedOnly: false };
    const search = $('[data-ac-search]', dialog);
    const optedCb = $('[data-ac-opted]', dialog);
    const resultsEl = $('[data-ac-results]', dialog);
    const addSelBtn = $('[data-ac-add-selected]', dialog);
    const addAllBtn = $('[data-ac-add-all]', dialog);

    const renderResults = () => {
      resultsEl.hidden = false;
      resultsEl.innerHTML = state.results.length ? state.results.map((c) => `
        <li><label>
          <input type="checkbox" data-c="${esc(c.id)}" ${state.selected.has(c.id) ? 'checked' : ''}>
          <span class="lm-contact-info"><strong>${esc(fullName(c))}</strong><span>${esc(c.email || '—')}</span></span>
        </label></li>
      `).join('') : '<li class="lm-dropdown-empty">No matching contacts.</li>';
      $$('[data-c]', resultsEl).forEach((cb) => cb.addEventListener('change', () => {
        const id = Number(cb.dataset.c);
        if (cb.checked) state.selected.add(id); else state.selected.delete(id);
        addSelBtn.disabled = state.selected.size === 0;
        addSelBtn.textContent = `Add selected (${state.selected.size})`;
      }));
      addAllBtn.hidden = state.total === 0;
      if (state.total) addAllBtn.textContent = `Add all ${state.total.toLocaleString()} matching`;
    };

    const runSearch = async (q) => {
      try {
        const qs = new URLSearchParams({ page: '1', limit: '20' });
        if (q) qs.set('q', q);
        if (state.optedOnly) qs.set('opted', '1');
        const data = await api(`/contacts?${qs}`);
        state.results = data.contacts || [];
        state.total = data.total || 0;
      } catch (err) {
        state.results = []; state.total = 0;
        publish('toast.show', { message: err.message, tone: 'error' });
      }
      renderResults();
    };

    search.addEventListener('input', () => {
      clearTimeout(this._acDebounce);
      this._acDebounce = setTimeout(() => runSearch(search.value.trim()), 280);
    });
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(this._acDebounce); runSearch(search.value.trim()); } });
    optedCb.addEventListener('change', () => { state.optedOnly = optedCb.checked; runSearch(search.value.trim()); });

    addSelBtn.addEventListener('click', async () => {
      try {
        const { added } = await api(`/mailing-lists/${this.selectedListId}/members`, { method: 'POST', body: JSON.stringify({ contact_ids: [...state.selected] }) });
        publish('toast.show', { message: `${added} added.` });
        close();
        await this.afterBulkChange();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
    addAllBtn.addEventListener('click', async () => {
      if (!confirm(`Add all ${state.total.toLocaleString()} matching contacts?`)) return;
      try {
        const body = {};
        if (search.value.trim()) body.q = search.value.trim();
        if (state.optedOnly) body.opted = '1';
        const { added } = await api(`/mailing-lists/${this.selectedListId}/add-by-filter`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${added} added.` });
        close();
        await this.afterBulkChange();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });

    search.focus();
  }

  // ── Create list modal ────────────────────────────────────────────────────

  openCreateListModal() {
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Create list</h2><button class="small secondary" type="button" data-close>Close</button></div>
      <form class="grid-form padded" data-create-form>
        <label class="wide">Name <input name="name" required maxlength="160" autocomplete="off"></label>
        <label class="wide">Description <span class="muted small">(optional)</span><input name="description" maxlength="500" autocomplete="off"></label>
        <fieldset class="wide">
          <legend>List type</legend>
          <label><input type="radio" name="list_type" value="static" checked> Static <span class="muted small">(you choose members)</span></label>
          <label><input type="radio" name="list_type" value="segment"> Smart <span class="muted small">(auto-updates from rules — edit rules afterward on the classic Lists page)</span></label>
        </fieldset>
        <div class="wide form-actions"><button type="submit" class="primary">Create list</button><button type="button" class="secondary" data-close>Cancel</button></div>
        <p class="error-text wide" data-error></p>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((b) => b.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('input[name="name"]', dialog).focus();

    $('[data-create-form]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const errEl = $('[data-error]', form);
      errEl.textContent = '';
      const body = {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim(),
        list_type: form.elements.list_type.value,
      };
      if (body.list_type === 'segment') body.segment_rules = { opted: '1' };
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        const { list } = await api('/mailing-lists', { method: 'POST', body: JSON.stringify(body) });
        this.lists = [list, ...this.lists];
        close();
        publish('toast.show', { message: `List "${list.name}" created.` });
        await this.selectList(list.id);
      } catch (err) {
        errEl.textContent = err.message;
        btn.disabled = false;
      }
    });
  }

  // ── Import CSV modal ─────────────────────────────────────────────────────

  openImportModal() {
    const staticLists = this.lists.filter((l) => l.list_type !== 'segment');
    if (!staticLists.length) { publish('toast.show', { message: 'Create a static list first — smart lists compute their own membership.', tone: 'error' }); return; }
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Import CSV</h2><button class="small secondary" type="button" data-close>Close</button></div>
      <form class="grid-form padded" data-import-form>
        <label class="wide">Target list
          <select name="list_id" required>
            ${staticLists.map((l) => `<option value="${esc(l.id)}" ${l.id === this.selectedListId ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}
          </select>
        </label>
        <p class="muted small wide">CSV needs an <code>email</code> column (plus optional <code>first_name</code>, <code>last_name</code>, <code>phone</code>, <code>opted_in</code>).</p>
        <label class="wide">CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label>
        <div class="wide form-actions"><button type="submit" class="primary">Import</button><button type="button" class="secondary" data-close>Cancel</button></div>
        <p class="error-text wide" data-error></p>
        <div class="wide" data-import-result></div>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((b) => b.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

    $('[data-import-form]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const errEl = $('[data-error]', form);
      const resultEl = $('[data-import-result]', form);
      errEl.textContent = '';
      resultEl.innerHTML = '';
      const listId = form.elements.list_id.value;
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        const data = await api(`/mailing-lists/${listId}/import`, { method: 'POST', body: new FormData(form) });
        publish('toast.show', { message: `${data.created || 0} new, ${data.updated || 0} matched, ${data.added_to_list || 0} added to list.` });
        if (Number(listId) === this.selectedListId) await this.afterBulkChange();
        else await this.loadLists();
        this.renderSidebar();
        close();
      } catch (err) {
        errEl.textContent = err.message;
        btn.disabled = false;
      }
    });
  }

  // ── Tags tool view ────────────────────────────────────────────────────────

  renderTagsView(el) {
    el.innerHTML = `
      <div class="lm-tool-header"><h2>Tags</h2></div>
      <form class="lm-tag-add-form" data-tag-form>
        <input type="text" name="name" placeholder="New tag name…" required maxlength="60">
        <input type="color" name="color" value="#2563eb">
        <button type="submit" class="lm-btn lm-btn-primary lm-btn-small"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add tag</button>
      </form>
      <div class="lm-table-wrap">
        <table class="data-table lm-table">
          <thead><tr><th>Tag</th><th>Used by</th><th></th></tr></thead>
          <tbody>${this.tags.length ? this.tags.map((t) => `
            <tr>
              <td><span class="lm-swatch" style="background:${esc(t.color)}"></span>${esc(t.name)}</td>
              <td>${Number(t.usage_count || 0)} contact${Number(t.usage_count) === 1 ? '' : 's'}</td>
              <td><button type="button" class="lm-btn lm-btn-small lm-btn-danger" data-delete-tag="${esc(t.id)}">Delete</button></td>
            </tr>
          `).join('') : '<tr><td colspan="3" class="lm-cell-muted">No tags yet.</td></tr>'}</tbody>
        </table>
      </div>
    `;
    $('[data-tag-form]', el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const name = form.elements.name.value.trim();
      if (!name) return;
      try {
        await api('/contact-tags', { method: 'POST', body: JSON.stringify({ name, color: form.elements.color.value }) });
        await this.loadTags();
        this.renderContent();
        publish('toast.show', { message: `Tag "${name}" created.` });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
    $$('[data-delete-tag]', el).forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this tag? It will be removed from every contact.')) return;
      try {
        await api(`/contact-tags/${btn.dataset.deleteTag}`, { method: 'DELETE' });
        await this.loadTags();
        this.renderContent();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));
  }

  // ── Segments tool view ───────────────────────────────────────────────────

  renderSegmentsView(el) {
    const rows = this.lists.filter((l) => l.list_type === 'segment');
    el.innerHTML = `
      <div class="lm-tool-header"><h2>Smart (segment) lists</h2></div>
      <p class="lm-cell-muted" style="font-size:12.5px;">Membership computes automatically from saved rules. Edit rules on the classic Lists page (#lists).</p>
      <div class="lm-table-wrap">
        <table class="data-table lm-table">
          <thead><tr><th>Name</th><th>Members</th><th>Last refreshed</th><th></th></tr></thead>
          <tbody>${rows.length ? rows.map((l) => `
            <tr>
              <td>${esc(l.name)}</td>
              <td>${Number(l.member_count || 0).toLocaleString()}</td>
              <td class="lm-cell-muted">${esc(fmtDateTime(l.segment_refreshed_at))}</td>
              <td><button type="button" class="lm-btn lm-btn-small" data-open-segment="${esc(l.id)}">Open</button></td>
            </tr>
          `).join('') : '<tr><td colspan="4" class="lm-cell-muted">No smart lists yet.</td></tr>'}</tbody>
        </table>
      </div>
    `;
    $$('[data-open-segment]', el).forEach((btn) => btn.addEventListener('click', () => this.selectList(Number(btn.dataset.openSegment))));
  }

  // ── Import/Export history tool views ─────────────────────────────────────

  async loadHistory(kind) {
    try {
      const qs = new URLSearchParams({ page: String(this.historyPage), limit: '25' });
      if (this.historyListFilter) qs.set('list_id', this.historyListFilter);
      const data = await api(`/mailing-lists/${kind}?${qs}`);
      this.historyRows = data.history || [];
      this.historyTotal = data.total || 0;
    } catch (err) {
      this.historyRows = [];
      publish('toast.show', { message: err.message, tone: 'error' });
    }
    this.renderContent();
  }

  renderHistoryView(el, kind) {
    const isImport = kind === 'import-history';
    el.innerHTML = `
      <div class="lm-tool-header">
        <h2>${isImport ? 'Import History' : 'Export History'}</h2>
        <select data-history-list-filter aria-label="Filter by list">
          <option value="">All lists</option>
          ${this.lists.map((l) => `<option value="${esc(l.id)}" ${this.historyListFilter === String(l.id) ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}
        </select>
      </div>
      <div class="lm-table-wrap">
        <table class="data-table lm-table">
          <thead><tr>
            <th>Date</th><th>List</th>
            ${isImport ? '<th>File</th><th>Created</th><th>Matched</th><th>Added</th><th>Skipped</th>' : '<th>Format</th><th>Rows</th>'}
            <th>By</th>
          </tr></thead>
          <tbody>${this.historyRows.length ? this.historyRows.map((r) => isImport ? `
            <tr>
              <td class="lm-cell-muted">${esc(fmtDateTime(r.created_at))}</td>
              <td>${esc(r.list_name || '(deleted list)')}</td>
              <td>${esc(r.filename || '—')}</td>
              <td>${Number(r.created_count)}</td>
              <td>${Number(r.updated_count)}</td>
              <td>${Number(r.added_to_list)}</td>
              <td>${Number(r.skipped_count)}</td>
              <td class="lm-cell-muted">${esc(r.imported_by_name || '—')}</td>
            </tr>
          ` : `
            <tr>
              <td class="lm-cell-muted">${esc(fmtDateTime(r.created_at))}</td>
              <td>${esc(r.list_name || 'All lists')}</td>
              <td>${esc(String(r.format || 'csv').toUpperCase())}</td>
              <td>${Number(r.row_count)}</td>
              <td class="lm-cell-muted">${esc(r.exported_by_name || '—')}</td>
            </tr>
          `).join('') : `<tr><td colspan="8" class="lm-cell-muted">No ${isImport ? 'imports' : 'exports'} yet.</td></tr>`}</tbody>
        </table>
      </div>
    `;
    $('[data-history-list-filter]', el).addEventListener('change', (e) => {
      this.historyListFilter = e.target.value;
      this.historyPage = 1;
      this.loadHistory(kind);
    });
  }

  // ── Contact detail slide-over ────────────────────────────────────────────

  closeDetail() {
    this.detailContact = null;
    this.detailActivity = null;
    this.detailTab = 'details';
    const el = $('[data-detail]', this);
    if (el) { el.hidden = true; el.innerHTML = ''; }
  }

  async openDetail(contactId) {
    this.detailTab = 'details';
    this.detailActivity = null;
    try {
      const [contactData, listsData] = await Promise.all([
        api(`/contacts/${contactId}`),
        api(`/contacts/${contactId}/lists`),
      ]);
      this.detailContact = contactData.contact;
      this.detailMemberships = listsData.memberships || [];
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      return;
    }
    this.renderDetail();
  }

  renderDetail() {
    const el = $('[data-detail]', this);
    if (!el || !this.detailContact) return;
    const c = this.detailContact;
    el.hidden = false;
    const age = relativeAge(c.created_at);
    const memberOf = this.detailMemberships.length;

    el.innerHTML = `
      <div class="lm-detail-head">
        ${avatar(c, true)}
        <div class="lm-detail-head-main">
          <h3>${esc(fullName(c))}</h3>
          <ul class="lm-detail-meta">
            ${c.email ? `<li><i class="fa-solid fa-envelope" aria-hidden="true"></i>${esc(c.email)}</li>` : ''}
            ${c.phone ? `<li><i class="fa-solid fa-phone" aria-hidden="true"></i>${esc(c.phone)}</li>` : ''}
            <li><i class="fa-solid fa-calendar" aria-hidden="true"></i>Joined ${esc(fmtDate(c.created_at))}${age ? ` (${esc(age)})` : ''}</li>
          </ul>
        </div>
        <button type="button" class="lm-detail-close" data-close-detail aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="lm-detail-tabs">
        <button type="button" class="lm-detail-tab ${this.detailTab === 'details' ? 'active' : ''}" data-detail-tab="details">Details</button>
        <button type="button" class="lm-detail-tab ${this.detailTab === 'activity' ? 'active' : ''}" data-detail-tab="activity">Activity</button>
        <button type="button" class="lm-detail-tab ${this.detailTab === 'notes' ? 'active' : ''}" data-detail-tab="notes">Notes</button>
      </div>
      <div class="lm-detail-body" data-detail-body></div>
      <div class="lm-detail-footer"><a href="#contacts">View Full Profile <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></div>
    `;

    $('[data-close-detail]', el).addEventListener('click', () => this.closeDetail());
    $$('[data-detail-tab]', el).forEach((btn) => btn.addEventListener('click', () => {
      this.detailTab = btn.dataset.detailTab;
      this.renderDetail();
    }));

    const body = $('[data-detail-body]', el);
    if (this.detailTab === 'details') this.renderDetailDetailsTab(body, memberOf);
    else if (this.detailTab === 'activity') this.renderDetailActivityTab(body);
    else this.renderDetailNotesTab(body);
  }

  renderDetailDetailsTab(body, memberOf) {
    const c = this.detailContact;
    body.innerHTML = `
      <div class="lm-detail-section">
        <div class="lm-detail-section-label">Tags</div>
        <div class="lm-detail-tags-row" data-tags-row>
          ${(c.tags || []).map((t) => tagPill(t, true)).join('')}
          <button type="button" class="lm-add-tag-btn" data-add-contact-tag><i class="fa-solid fa-plus" aria-hidden="true"></i> Add</button>
        </div>
      </div>
      <div class="lm-detail-section">
        <div class="lm-detail-section-label">Lists &amp; Membership <span class="lm-badge-count">${memberOf} of ${this.lists.length}</span></div>
        <ul class="lm-membership-list" data-membership-list>
          ${this.lists.map((l) => {
            const m = this.detailMemberships.find((x) => x.list_id === l.id);
            const isSegment = l.list_type === 'segment';
            return `<li class="lm-membership-row">
              <input type="checkbox" data-toggle-membership="${esc(l.id)}" ${m ? 'checked' : ''} ${isSegment ? 'disabled title="Smart list — membership is automatic"' : ''}>
              <span>
                <div class="lm-membership-name">${esc(l.name)}</div>
                <div class="lm-membership-sub">${m ? `Joined ${esc(fmtDate(m.added_at))}` : 'Not a member'}</div>
              </span>
            </li>`;
          }).join('')}
        </ul>
      </div>
    `;
    $('[data-add-contact-tag]', body).addEventListener('click', () => this.addTagToDetailContact());
    $$('[data-remove-tag]', body).forEach((el) => el.addEventListener('click', () => this.removeTagFromDetailContact(Number(el.dataset.removeTag))));
    $$('[data-toggle-membership]', body).forEach((cb) => cb.addEventListener('change', () => this.toggleDetailMembership(Number(cb.dataset.toggleMembership), cb.checked)));
  }

  async addTagToDetailContact() {
    const name = prompt('Tag name:');
    if (!name || !name.trim()) return;
    try {
      await api(`/contacts/${this.detailContact.id}/tags`, { method: 'POST', body: JSON.stringify({ name: name.trim() }) });
      await this.loadTags();
      const { contact } = await api(`/contacts/${this.detailContact.id}`);
      this.detailContact = contact;
      this.renderDetail();
      if (this.view === 'list') { await this.loadMembers(); this.renderContent(); }
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async removeTagFromDetailContact(tagId) {
    try {
      await api(`/contacts/${this.detailContact.id}/tags/${tagId}`, { method: 'DELETE' });
      const { contact } = await api(`/contacts/${this.detailContact.id}`);
      this.detailContact = contact;
      this.renderDetail();
      if (this.view === 'list') { await this.loadMembers(); this.renderContent(); }
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async toggleDetailMembership(listId, on) {
    try {
      if (on) await api(`/mailing-lists/${listId}/members`, { method: 'POST', body: JSON.stringify({ contact_ids: [this.detailContact.id] }) });
      else await api(`/mailing-lists/${listId}/members/${this.detailContact.id}`, { method: 'DELETE' });
      const listsData = await api(`/contacts/${this.detailContact.id}/lists`);
      this.detailMemberships = listsData.memberships || [];
      await this.loadLists();
      this.renderSidebar();
      this.renderDetail();
      if (this.view === 'list' && this.selectedListId === listId) { await this.loadMembers(); this.renderContent(); }
      else if (this.view === 'list') this.renderStatsRow();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      this.renderDetail();
    }
  }

  renderStatsRow() {
    // Cheap refresh path for the currently selected list's header stats
    // without rebuilding the whole content pane (used after a membership
    // toggle on a *different* list than the one currently open).
    if (this.view === 'list') this.renderListView($('[data-content]', this));
  }

  async renderDetailActivityTab(body) {
    if (this.detailActivity === null) {
      body.innerHTML = '<p class="lm-cell-muted">Loading…</p>';
      try {
        const data = await api(`/contacts/${this.detailContact.id}/activity`);
        this.detailActivity = data.activity || [];
      } catch (err) {
        body.innerHTML = `<p class="error-text">${esc(err.message)}</p>`;
        return;
      }
      if (this.detailTab !== 'activity') return;
    }
    body.innerHTML = this.detailActivity.length ? `<ul class="lm-activity-list">
      ${this.detailActivity.map((a) => `<li class="lm-activity-item">
        <span class="lm-activity-icon"><i class="fa-solid ${ACTIVITY_ICON[a.type] || 'fa-circle-info'}" aria-hidden="true"></i></span>
        <span><div class="lm-activity-message">${esc(a.message)}</div><div class="lm-activity-time">${esc(timeAgo(a.created_at))}${a.user_name ? ` · ${esc(a.user_name)}` : ''}</div></span>
      </li>`).join('')}
    </ul>` : '<p class="lm-cell-muted">No activity yet.</p>';
  }

  renderDetailNotesTab(body) {
    const c = this.detailContact;
    body.innerHTML = `
      <textarea class="lm-notes-textarea" data-notes placeholder="Notes about this contact…">${esc(c.notes || '')}</textarea>
      <div class="form-actions" style="margin-top:10px;"><button type="button" class="small primary" data-save-notes>Save notes</button></div>
    `;
    $('[data-save-notes]', body).addEventListener('click', async () => {
      const textarea = $('[data-notes]', body);
      const btn = $('[data-save-notes]', body);
      btn.disabled = true;
      try {
        // Contacts::update() rewrites the full editable row, not a partial
        // patch — send every current field back so only notes actually changes.
        await api(`/contacts/${c.id}`, {
          method: 'PATCH',
          body: JSON.stringify({
            first_name: c.first_name, last_name: c.last_name, email: c.email, phone: c.phone,
            gender: c.gender, birthday: c.birthday, marketing_opted_in: c.marketing_opted_in,
            notes: textarea.value,
          }),
        });
        c.notes = textarea.value;
        publish('toast.show', { message: 'Notes saved.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      } finally {
        btn.disabled = false;
      }
    });
  }
}

customElements.define('pb-listmaster', ListMasterPage);
