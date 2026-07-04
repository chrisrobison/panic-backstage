// ── JWT token storage ────────────────────────────────────────────────────────
const TOKEN_KEY   = 'backstage_access_token';

const REFRESH_KEY = 'backstage_refresh_token';

const getToken        = () => localStorage.getItem(TOKEN_KEY);

const getRefreshToken = () => localStorage.getItem(REFRESH_KEY);

const setTokens = (access, refresh) => {
  localStorage.setItem(TOKEN_KEY, access);
  if (refresh) localStorage.setItem(REFRESH_KEY, refresh);
};

const clearTokens = () => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(REFRESH_KEY);
};


const $ = (selector, root = document) => root.querySelector(selector);

const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

const titleCase = (value) => String(value || '').replace(/[_-]/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

const scriptUrl = new URL((document.currentScript || $$('script[src*="assets/app.js"]').at(-1) || { src: location.href }).src);

const appBaseUrl = /\/public\/assets\/app\.js$/i.test(scriptUrl.pathname) ? new URL('../..', scriptUrl) : new URL('..', scriptUrl);

const statuses = ['empty', 'proposed', 'confirmed', 'booked', 'needs_assets', 'ready_to_announce', 'published', 'advanced', 'completed', 'settled', 'canceled'];


function appUrl(path = '') {
  return new URL(path.replace(/^\/+/, ''), appBaseUrl).toString();
}


function apiUrl(path = '') {
  return appUrl(`api/${path.replace(/^\/+/, '')}`);
}


function assetUrl(path = '') {
  const value = String(path || '');
  return /^(?:[a-z]+:|#)/i.test(value) ? value : appUrl(value);
}


// Module-level cache of the signed-in user (incl. UI preferences from /me).
// Set by AppShell once /me resolves; read by views that honor preferences
// (e.g. EventsList default sort) without issuing another request.
let _appUser = null;

function getAppUser() { return _appUser; }

function setAppUser(user) { _appUser = user || null; }


// ── Real LARC/PAN bus adapter ────────────────────────────────────────────────
// pan.mjs (loaded in index.html) is a component autoloader; on init it mounts a
// <pan-bus> element and dynamically imports pan-bus-lite.mjs, the actual pub/sub
// implementation, which exposes window.pan.bus.publish()/subscribe() once ready
// and announces readiness via a `pan:sys.ready` CustomEvent on `document`. This
// adapter waits for that signal (buffering early calls) and then delegates to
// the real bus, translating its unsubscribe-function return into this app's
// existing AbortSignal-based unsubscribe convention so every call site keeps
// calling publish(topic, payload) / subscribe(topic, handler, signal) unchanged.
let _panReady = false;
const _panPending = [];
document.addEventListener('pan:sys.ready', () => {
  _panReady = true;
  _panPending.splice(0).forEach((fn) => fn());
}, { once: true });
// Fallback: if the CDN never responds, stop buffering after 3s so we degrade to
// silent no-ops instead of an unbounded pending-call queue.
setTimeout(() => { if (!_panReady) { _panReady = true; _panPending.splice(0).forEach((fn) => fn()); } }, 3000);

function _whenPanReady(fn) { _panReady ? fn() : _panPending.push(fn); }

function publish(topic, payload = {}) {
  _whenPanReady(() => {
    if (window.pan?.bus) window.pan.bus.publish(topic, payload);
  });
}

function subscribe(topic, handler, signal) {
  _whenPanReady(() => {
    if (signal?.aborted || !window.pan?.bus) return;
    const unsubscribe = window.pan.bus.subscribe(topic, (msg) => handler(msg.data));
    signal?.addEventListener('abort', () => unsubscribe(), { once: true });
  });
}


async function api(path, options = {}) {
  publish('events.requested', { path });
  const doFetch = async (token) => {
    const headers = options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return fetch(apiUrl(path), { ...options, headers: { ...headers, ...(options.headers || {}) } });
  };

  let response = await doFetch(getToken());

  // On 401, attempt a silent token refresh then retry once
  if (response.status === 401) {
    const newToken = await tryRefresh();
    if (newToken) {
      response = await doFetch(newToken);
    } else {
      clearTokens();
      location.href = appUrl('login.html');
      throw new Error('Session expired');
    }
  }

  const body = response.status === 204 ? null : await response.json().catch(() => null);
  if (!response.ok) {
    const message = body?.error || `Request failed: ${response.status}`;
    publish('api.error', { message, path });
    throw new Error(message);
  }
  return body;
}


async function tryRefresh() {
  const refreshToken = getRefreshToken();
  if (!refreshToken) return null;
  try {
    const response = await fetch(apiUrl('/auth/refresh'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });
    if (!response.ok) return null;
    const data = await response.json();
    if (data.access_token) {
      setTokens(data.access_token, data.refresh_token);
      return data.access_token;
    }
  } catch { /* network error */ }
  return null;
}


function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}


// Broadcast a fresh, full event payload on the page bus. Any component that
// derives from event data (e.g. the workspace summary counts/facts) can
// subscribe to `event.changed` and update itself without a page reload.
function broadcastEventData(data) {
  if (data?.event) publish('event.changed', { data });
}


// Re-render a single event-workspace section in place from fresh server data.
// Used instead of publishing `event.saved` (which re-mounts the whole event
// workspace and scrolls the page back to the top). Re-assigning `.data`
// re-runs just this component's render + bind, so the page keeps its scroll
// position, active tab, and sibling sections untouched. The same fresh payload
// is broadcast on the bus so summary listeners stay in sync.
async function refreshSection(component) {
  const id = component?.eventData?.event?.id;
  if (!id) return;
  const data = await api(`/events/${id}`);
  component.data = data;
  broadcastEventData(data);
}


function eventDate(event) {
  const date = event?.date ? new Date(`${event.date}T12:00:00`) : null;
  return Number.isNaN(date?.getTime()) ? null : date;
}


function shortDate(date) {
  return date ? date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : 'TBA';
}


// Full date including year — used for tooltips so multi-year lists are legible.
function longDate(date) {
  return date ? date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) : 'Date TBA';
}


function isoDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}


function addDays(date, days) {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  return next;
}


function timeLabel(value) {
  if (!value) return 'TBA';
  const [hours, minutes] = value.split(':').map(Number);
  const date = new Date();
  date.setHours(hours || 0, minutes || 0, 0, 0);
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}


function money(value) {
  return `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}


function statusTone(status) {
  if (status === 'published') return 'blue';
  if (['confirmed', 'booked', 'advanced', 'ready_to_announce', 'settled'].includes(status)) return 'green';
  if (['needs_assets', 'completed'].includes(status)) return 'amber';
  if (['hold', 'canceled'].includes(status)) return 'red';
  return 'gray';
}


/** Returns a CSS modifier class for a calendar event dot coloured by venue floor. */
function roomTone(zone) {
  if (zone === 'up')   return 'room-up';
  if (zone === 'down') return 'room-down';
  if (zone === 'both') return 'room-both';
  return 'gray';
}


// Sheet-derived display labels for the event-status enum. The MabEvents
// Google Sheet's "Status" column is the source of truth for vocabulary, so
// pipeline columns and badges read in the same language as the sheet.
// Statuses with no sheet counterpart fall through to titleCase().
const STATUS_LABELS = {
  proposed:          'Hold',
  confirmed:         'Intake Complete',
  booked:            'Booked',
  needs_assets:      'Needs Assets',
  ready_to_announce: 'Ready to Announce',
  published:         'Published',
  advanced:          'Advanced',
  completed:         'Archived',
  settled:           'Settled',
  canceled:          'Cancelled',
  empty:             'Empty',
};


function statusLabel(status) {
  return STATUS_LABELS[status] || titleCase(status);
}


function badge(status) {
  return `<span class="badge status-${esc(status)}">${esc(statusLabel(status))}</span>`;
}


function option(value, selected, label = value, labelFn) {
  const display = labelFn ? labelFn(value) : titleCase(label);
  return `<option value="${esc(value)}" ${String(value) === String(selected ?? '') ? 'selected' : ''}>${esc(display)}</option>`;
}


function select(name, values, selected, labelFn) {
  return `<select name="${esc(name)}">${values.map((value) => option(value, selected, value, labelFn)).join('')}</select>`;
}


function userSelect(users = [], selected = '') {
  return `<select name="assigned_user_id"><option value="">Unassigned</option>${users.map((user) => `<option value="${esc(user.id)}" ${String(user.id) === String(selected || '') ? 'selected' : ''}>${esc(user.name)}</option>`).join('')}</select>`;
}


function ownerSelect(users = [], selected = '') {
  return `<select name="owner_user_id"><option value="">Unassigned</option>${users.map((user) => `<option value="${esc(user.id)}" ${String(user.id) === String(selected || '') ? 'selected' : ''}>${esc(user.name)}</option>`).join('')}</select>`;
}


function emptyState(message) {
  return `<div class="empty-state">${esc(message)}</div>`;
}


function helpLink(slug, label) {
  const safe = esc(slug);
  const title = label ? `Help: ${esc(label)}` : 'Open help for this section';
  return `<a class="help-link" href="#help-${safe}" target="_blank" rel="noopener noreferrer" title="${title}" aria-label="${title}"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></a>`;
}


function can(data, capability) {
  return Boolean(data?.capabilities?.[capability]);
}


function eventRow(event) {
  const issue = event.primary_blocker || (Number(event.approved_flyers) ? 'Flyer approved' : 'Flyer needs review');
  return `<tr>
    <td data-label="ID"><a href="#event-${esc(event.id)}" class="event-code">${esc(event.external_id || '—')}</a></td>
    <td data-label="Date" title="${esc(longDate(eventDate(event)))}">${esc(shortDate(eventDate(event)))}</td>
    <td data-label="Event"><a href="#event-${esc(event.id)}">${esc(event.title)}</a></td>
    <td data-label="Status">${badge(event.status)}</td>
    <td data-label="Main Issue"><span class="status-dot ${esc(event.primary_blocker ? 'red' : statusTone(event.status))}"></span>${esc(issue)}</td>
    <td data-label="Owner">${esc(event.owner_name || 'Unassigned')}</td>
  </tr>`;
}


// Column metadata for the events table. `sortBy` returns a comparable value for
// each event; `type` picks the comparator (dates/strings) used by sortEvents().
const EVENT_COLUMNS = [
  { key: 'code',   label: 'ID',         type: 'string', sortBy: (e) => String(e.external_id || '') },
  { key: 'date',   label: 'Date',       type: 'date',   sortBy: (e) => `${e.date || ''} ${e.show_time || ''}` },
  { key: 'title',  label: 'Event',      type: 'string', sortBy: (e) => String(e.title || '') },
  { key: 'status', label: 'Status',     type: 'string', sortBy: (e) => statusLabel(e.status) },
  { key: 'issue',  label: 'Main Issue', type: 'string', sortBy: (e) => String(e.primary_blocker || '') },
  { key: 'owner',  label: 'Owner',      type: 'string', sortBy: (e) => String(e.owner_name || '') },
];


function sortEvents(events, sort) {
  const col = sort && EVENT_COLUMNS.find((c) => c.key === sort.key);
  if (!col) return events;
  const dir = sort.dir === 'asc' ? 1 : -1;
  return events.slice().sort((a, b) => {
    const av = col.sortBy(a);
    const bv = col.sortBy(b);
    const cmp = col.type === 'string'
      ? String(av).localeCompare(String(bv), undefined, { sensitivity: 'base', numeric: true })
      : (av < bv ? -1 : av > bv ? 1 : 0);
    return cmp * dir;
  });
}


// When `sort` is provided the column headers become sortable buttons and rows
// are ordered accordingly; without it the table renders static headers (used by
// the dashboard preview). Default Events-page sort is reverse date.
function table(events, sort) {
  const sortable = Boolean(sort);
  const rows = (sortable ? sortEvents(events, sort) : events).map(eventRow).join('');
  const head = EVENT_COLUMNS.map((col) => {
    if (!sortable) return `<th>${esc(col.label)}</th>`;
    const active = sort.key === col.key;
    const arrow = active ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : '';
    return `<th class="${active ? 'sorted' : ''}"><button type="button" class="th-sort" data-sort-key="${esc(col.key)}" aria-sort="${active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}">${esc(col.label)}<span class="sort-arrow">${arrow}</span></button></th>`;
  }).join('');
  return `<table class="data-table">
    <thead><tr>${head}</tr></thead>
    <tbody>${rows}</tbody>
  </table>`;
}


class PanicElement extends HTMLElement {
  connectedCallback() {
    this.abort = new AbortController();
    this.connect?.();
  }

  disconnectedCallback() {
    this.abort?.abort();
  }

  setLoading(label = 'Loading') {
    this.innerHTML = `<pb-loading-state label="${esc(label)}"></pb-loading-state>`;
  }

  showError(error) {
    this.innerHTML = `<div class="panel padded"><h2>Something went wrong</h2><p class="error-text">${esc(error.message || error)}</p></div>`;
  }
}


class LoadingState extends HTMLElement {
  connectedCallback() {
    this.innerHTML = `<div class="loading-state"><span class="spinner"></span>${esc(this.getAttribute('label') || 'Loading')}</div>`;
  }
}


class ToastStack extends PanicElement {
  connect() {
    this.items = [];
    subscribe('toast.show', (toast) => this.add(toast), this.abort.signal);
    subscribe('api.error', (error) => this.add({ tone: 'error', message: error.message }), this.abort.signal);
    this.render();
  }

  add(toast) {
    const id = crypto.randomUUID?.() || String(Date.now());
    this.items = [...this.items, { id, tone: toast.tone || 'info', message: toast.message || '' }];
    this.render();
    window.setTimeout(() => {
      this.items = this.items.filter((item) => item.id !== id);
      this.render();
    }, 4200);
  }

  render() {
    this.innerHTML = `<div class="toast-stack">${this.items.map((item) => `<div class="toast ${esc(item.tone)}">${esc(item.message)}</div>`).join('')}</div>`;
  }
}


// The "+" reveal button shown in a panel header (only when the user can edit).
function addToggle(label, editable) {
  return editable ? `<button type="button" class="add-toggle" data-add aria-label="${esc(label)}" title="${esc(label)}"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>` : '';
}

// Wire a panel-header "+" ([data-add]) to reveal/hide its add form
// ([data-add-form]), focusing the first field on open. Any [data-cancel-add]
// button inside the form collapses it again. Safe to call when the toggle or
// form is absent (no-op). Shared by the list panels and the Invites/Contracts
// sections so they all behave identically.
function bindAddToggle(root) {
  const addBtn = $('[data-add]', root);
  const addForm = $('[data-add-form]', root);
  if (!addBtn || !addForm) return;
  addBtn.addEventListener('click', () => {
    const show = addForm.hasAttribute('hidden');
    addForm.toggleAttribute('hidden', !show);
    addBtn.classList.toggle('active', show);
    if (show) $$('input, select, textarea', addForm).find((el) => !el.disabled && el.type !== 'hidden')?.focus();
  });
  $$('[data-cancel-add]', root).forEach((btn) => btn.addEventListener('click', () => {
    addForm.setAttribute('hidden', '');
    addBtn.classList.remove('active');
  }));
}
customElements.define('pb-loading-state', LoadingState);
customElements.define('pb-toast-stack', ToastStack);

// ── Markdown renderer ─────────────────────────────────────────────────────────
// Converts a small subset of Markdown to safe HTML. All raw text is HTML-escaped
// before any Markdown patterns are applied, so the output is XSS-safe even when
// the source is untrusted (event descriptions entered by bookers / promoters).
//
// Supported:
//   # / ## / ### headings      **bold**  *italic*
//   - / * unordered lists      1. ordered lists
//   [text](url) links           blank lines → paragraph breaks
//   single newlines → <br>
//
// javascript: link hrefs are stripped to '#' for safety.
function mdToHtml(text) {
  if (!text) return '';

  // 1. Escape HTML entities so raw text can never inject markup.
  const safe = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  // 2. Inline patterns — applied inside block elements.
  const inline = (s) => s
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, href) => {
      // Strip javascript: and data: URIs.
      const safe_href = /^(javascript|data):/i.test(href.trim()) ? '#' : href;
      return `<a href="${safe_href}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    });

  // 3. Split on blank lines → blocks, then classify each block.
  const blocks = safe.split(/\n{2,}/);
  return blocks.map((block) => {
    const lines = block.split('\n');

    // Heading (only if the block is a single heading line)
    const headingMatch = block.match(/^(#{1,3}) (.+)$/);
    if (headingMatch) {
      const level = headingMatch[1].length;
      return `<h${level} class="md-heading">${inline(headingMatch[2])}</h${level}>`;
    }

    // Unordered list — every line starts with "- " or "* "
    if (lines.every((l) => /^[-*] /.test(l))) {
      return `<ul class="md-list">${lines.map((l) => `<li>${inline(l.replace(/^[-*] /, ''))}</li>`).join('')}</ul>`;
    }

    // Ordered list — every line starts with "N. "
    if (lines.every((l) => /^\d+\. /.test(l))) {
      return `<ol class="md-list">${lines.map((l) => `<li>${inline(l.replace(/^\d+\. /, ''))}</li>`).join('')}</ol>`;
    }

    // Default: paragraph, with single newlines becoming <br>
    return `<p>${inline(lines.join('<br>'))}</p>`;
  }).join('\n');
}

export { TOKEN_KEY, REFRESH_KEY, getToken, getRefreshToken, setTokens, clearTokens, $, $$, esc, titleCase, scriptUrl, appBaseUrl, statuses, appUrl, apiUrl, assetUrl, _appUser, getAppUser, setAppUser, publish, subscribe, api, tryRefresh, formData, broadcastEventData, refreshSection, eventDate, shortDate, longDate, isoDate, addDays, timeLabel, money, statusTone, roomTone, STATUS_LABELS, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, eventRow, EVENT_COLUMNS, sortEvents, table, PanicElement, LoadingState, ToastStack, addToggle, bindAddToggle, mdToHtml };
