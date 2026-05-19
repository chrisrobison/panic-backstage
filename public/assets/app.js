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
const statuses = ['empty', 'proposed', 'hold', 'confirmed', 'needs_assets', 'ready_to_announce', 'published', 'advanced', 'completed', 'settled', 'canceled'];

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

function publish(topic, payload = {}) {
  document.dispatchEvent(new CustomEvent(topic, { detail: payload }));
  document.dispatchEvent(new CustomEvent('pan:publish', { detail: { topic, payload } }));
}

function subscribe(topic, handler, signal) {
  const local = (event) => handler(event.detail);
  const pan = (event) => {
    if (event.detail?.topic === topic) handler(event.detail.payload);
  };
  document.addEventListener(topic, local, { signal });
  document.addEventListener('pan:message', pan, { signal });
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

function eventDate(event) {
  const date = event?.date ? new Date(`${event.date}T12:00:00`) : null;
  return Number.isNaN(date?.getTime()) ? null : date;
}

function shortDate(date) {
  return date ? date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : 'TBA';
}

function isoDate(date) {
  return date.toISOString().slice(0, 10);
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
  if (['advanced', 'ready_to_announce', 'settled'].includes(status)) return 'green';
  if (['needs_assets', 'confirmed', 'completed'].includes(status)) return 'amber';
  if (['hold', 'canceled'].includes(status)) return 'red';
  return 'gray';
}

function badge(status) {
  return `<span class="badge status-${esc(status)}">${esc(titleCase(status))}</span>`;
}

function option(value, selected, label = value) {
  return `<option value="${esc(value)}" ${String(value) === String(selected ?? '') ? 'selected' : ''}>${esc(titleCase(label))}</option>`;
}

function select(name, values, selected) {
  return `<select name="${esc(name)}">${values.map((value) => option(value, selected)).join('')}</select>`;
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

function can(data, capability) {
  return Boolean(data?.capabilities?.[capability]);
}

function eventRow(event) {
  const issue = event.primary_blocker || (Number(event.approved_flyers) ? 'Flyer approved' : 'Flyer needs review');
  return `<tr>
    <td data-label="Date">${esc(shortDate(eventDate(event)))}</td>
    <td data-label="Event"><a href="#event-${esc(event.id)}">${esc(event.title)}</a></td>
    <td data-label="Status">${badge(event.status)}</td>
    <td data-label="Main Issue"><span class="status-dot ${esc(event.primary_blocker ? 'red' : statusTone(event.status))}"></span>${esc(issue)}</td>
    <td data-label="Owner">${esc(event.owner_name || 'Unassigned')}</td>
  </tr>`;
}

function table(events) {
  return `<table class="data-table">
    <thead><tr><th>Date</th><th>Event</th><th>Status</th><th>Main Issue</th><th>Owner</th></tr></thead>
    <tbody>${events.map(eventRow).join('')}</tbody>
  </table>`;
}

// ── WebAuthn / passkey helpers ────────────────────────────────────────────────
function b64uToBuffer(b64u) {
  const pad = b64u.length % 4 ? 4 - b64u.length % 4 : 0;
  return Uint8Array.from(atob(b64u.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat(pad)), (c) => c.charCodeAt(0)).buffer;
}

function bufToB64u(buf) {
  const bytes = new Uint8Array(buf instanceof ArrayBuffer ? buf : (buf.buffer ?? buf));
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/** Serialise a PublicKeyCredential into plain JSON for the server. */
function serializeCredential(cred) {
  const r = cred.response;
  const out = { id: cred.id, type: cred.type, response: { clientDataJSON: bufToB64u(r.clientDataJSON) } };
  if (r.attestationObject) out.response.attestationObject = bufToB64u(r.attestationObject);
  if (r.authenticatorData) out.response.authenticatorData = bufToB64u(r.authenticatorData);
  if (r.signature)         out.response.signature          = bufToB64u(r.signature);
  if (r.userHandle)        out.response.userHandle         = bufToB64u(r.userHandle);
  if (r.getTransports)     out.response.transports         = r.getTransports();
  return out;
}

/** Convert server-side registration options into the form navigator.credentials.create() expects. */
function prepareCreateOptions(opts) {
  return {
    ...opts,
    challenge: b64uToBuffer(opts.challenge),
    user: { ...opts.user, id: b64uToBuffer(opts.user.id) },
    excludeCredentials: (opts.excludeCredentials || []).map((c) => ({ ...c, id: b64uToBuffer(c.id) })),
  };
}

/** Convert server-side authentication options into the form navigator.credentials.get() expects. */
function prepareGetOptions(opts) {
  return {
    challenge: b64uToBuffer(opts.challenge),
    timeout: opts.timeout || 60000,
    rpId: opts.rpId,
    allowCredentials: (opts.allowCredentials || []).map((c) => ({ ...c, id: b64uToBuffer(c.id) })),
    userVerification: opts.userVerification || 'preferred',
  };
}

// ── Print feature ────────────────────────────────────────────────────────────
// Opens a new window with a self-contained, print-styled HTML document built
// from already-loaded event data. The user prints via Cmd/Ctrl+P (or the
// "Print" button injected into the printout). Five printout types are
// supported: lineup, staffing, run-of-show, guest-list, and master (combined).

const PRINT_TITLES = {
  lineup: 'Band Lineup',
  staffing: 'Staffing Schedule',
  'run-of-show': 'Run of Show',
  'guest-list': 'Door / Guest List',
  master: 'Master Event Packet',
};

const PRINT_CSS = `
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111; background: #f5f5f5; padding: 24px; }
  .sheet { background: #fff; max-width: 8.5in; margin: 0 auto 24px; padding: 0.6in 0.65in; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
  .print-toolbar { max-width: 8.5in; margin: 0 auto 12px; display: flex; justify-content: flex-end; gap: 8px; }
  .print-toolbar button { font: inherit; padding: 8px 14px; border: 1px solid #888; background: #fff; border-radius: 4px; cursor: pointer; }
  .print-toolbar button.primary { background: #111; color: #fff; border-color: #111; }
  header.event-head { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; }
  header.event-head h1 { font-size: 22pt; margin: 0 0 4px; line-height: 1.15; }
  header.event-head .meta { font-size: 10pt; color: #444; }
  header.event-head .head-right { text-align: right; font-size: 10pt; }
  header.event-head .head-right strong { display: block; font-size: 14pt; color: #111; }
  h2.section { font-size: 14pt; margin: 22px 0 10px; padding-bottom: 4px; border-bottom: 1px solid #999; }
  h3.subsection { font-size: 11pt; margin: 14px 0 6px; color: #333; text-transform: uppercase; letter-spacing: 0.04em; }
  table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 12px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
  th { background: #f0f0f0; font-weight: 600; text-transform: uppercase; font-size: 8.5pt; letter-spacing: 0.04em; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  td.time, th.time { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 16px; margin: 12px 0 18px; font-size: 10pt; }
  .facts .fact { border-left: 3px solid #111; padding-left: 8px; }
  .facts .fact label { display: block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #666; }
  .facts .fact strong { font-size: 11pt; }
  .notes-block { background: #f7f7f7; border-left: 3px solid #888; padding: 8px 10px; font-size: 10pt; white-space: pre-wrap; margin: 8px 0 12px; }
  .empty { color: #888; font-style: italic; font-size: 10pt; padding: 8px 0; }
  .pill { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 8pt; background: #eee; color: #333; text-transform: uppercase; letter-spacing: 0.05em; }
  .pill.confirmed { background: #d4edda; color: #155724; }
  .pill.tentative { background: #fff3cd; color: #856404; }
  .pill.canceled { background: #f8d7da; color: #721c24; }
  .pill.invited { background: #d1ecf1; color: #0c5460; }
  footer.sheet-foot { margin-top: 24px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 8.5pt; color: #777; display: flex; justify-content: space-between; }
  .page-break { page-break-before: always; break-before: page; }

  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; margin: 0; padding: 0.5in 0.55in; max-width: none; }
    .print-toolbar { display: none; }
    @page { size: letter; margin: 0.5in; }
  }
`;

function printDateRange(event) {
  const date = eventDate(event);
  if (!date) return 'Date TBA';
  return date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

function printDuration(minutes) {
  const value = Number(minutes);
  if (!value) return '';
  if (value < 60) return `${value} min`;
  const hours = Math.floor(value / 60);
  const rem = value % 60;
  return rem ? `${hours}h ${rem}m` : `${hours}h`;
}

function printPill(status) {
  if (!status) return '';
  const cls = String(status).toLowerCase().replace(/[^a-z]+/g, '');
  return `<span class="pill ${cls}">${esc(titleCase(status))}</span>`;
}

function printHeader(data, subtitle) {
  const event = data.event;
  const venueLine = [event.venue_name, event.venue_city, event.venue_state].filter(Boolean).join(', ');
  return `<header class="event-head">
    <div>
      <h1>${esc(event.title)}</h1>
      <div class="meta">${esc(venueLine || 'Venue TBA')}${event.venue_address ? ' &middot; ' + esc(event.venue_address) : ''}</div>
      <div class="meta">${esc(printDateRange(event))}</div>
    </div>
    <div class="head-right">
      <strong>${esc(subtitle)}</strong>
      <div>Doors ${esc(timeLabel(event.doors_time))} &middot; Show ${esc(timeLabel(event.show_time))}</div>
      ${event.age_restriction ? `<div>${esc(event.age_restriction)}</div>` : ''}
    </div>
  </header>`;
}

function printFooter(data) {
  const stamp = new Date().toLocaleString();
  return `<footer class="sheet-foot">
    <span>${esc(data.event.title)} &middot; ${esc(printDateRange(data.event))}</span>
    <span>Printed ${esc(stamp)}</span>
  </footer>`;
}

function renderLineupSection(data) {
  const lineup = data.lineup || [];
  if (!lineup.length) return `<h2 class="section">Band Lineup</h2><p class="empty">No lineup entries.</p>`;
  const rows = lineup.map((item, index) => `<tr>
    <td class="num">${index + 1}</td>
    <td><strong>${esc(item.display_name || item.band_name || 'Untitled')}</strong>${item.band_name && item.band_name !== item.display_name ? `<br><span style="color:#666;font-size:9pt;">${esc(item.band_name)}</span>` : ''}</td>
    <td class="time">${esc(timeLabel(item.set_time))}</td>
    <td class="time">${esc(printDuration(item.set_length_minutes))}</td>
    <td>${printPill(item.status)}</td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('');
  return `<h2 class="section">Band Lineup</h2>
    <table>
      <thead><tr><th class="num">#</th><th>Act</th><th class="time">Set Time</th><th class="time">Length</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

function renderStaffingSection(data) {
  const collaborators = (data.collaborators || []).filter((c) => ['venue_admin','event_owner','promoter','staff','designer'].includes(c.event_role));
  const staffCalls = (data.schedule || []).filter((item) => item.item_type === 'staff_call');
  const peopleRows = collaborators.length
    ? collaborators.map((c) => `<tr>
        <td><strong>${esc(c.name || '—')}</strong></td>
        <td>${esc(titleCase(c.event_role))}</td>
        <td>${esc(c.email || '')}</td>
      </tr>`).join('')
    : '';
  const callRows = staffCalls.length
    ? staffCalls.map((item) => `<tr>
        <td class="time">${esc(timeLabel(item.start_time))}</td>
        <td><strong>${esc(item.title)}</strong></td>
        <td>${esc(item.notes || '')}</td>
      </tr>`).join('')
    : '';
  return `<h2 class="section">Staffing Schedule</h2>
    <h3 class="subsection">Personnel</h3>
    ${peopleRows ? `<table>
      <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
      <tbody>${peopleRows}</tbody>
    </table>` : `<p class="empty">No staff or collaborators assigned.</p>`}
    <h3 class="subsection">Staff Call Times</h3>
    ${callRows ? `<table>
      <thead><tr><th class="time">Call</th><th>What</th><th>Notes</th></tr></thead>
      <tbody>${callRows}</tbody>
    </table>` : `<p class="empty">No staff call times scheduled.</p>`}`;
}

function renderRunOfShowSection(data) {
  const schedule = (data.schedule || []).slice().sort((a, b) => {
    const ta = a.start_time || '99:99:99';
    const tb = b.start_time || '99:99:99';
    return ta.localeCompare(tb);
  });
  if (!schedule.length) return `<h2 class="section">Run of Show</h2><p class="empty">No schedule items.</p>`;
  const rows = schedule.map((item) => `<tr>
    <td class="time">${esc(timeLabel(item.start_time))}${item.end_time ? `<br><span style="color:#666;">${esc(timeLabel(item.end_time))}</span>` : ''}</td>
    <td><strong>${esc(item.title)}</strong></td>
    <td>${esc(titleCase(item.item_type))}</td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('');
  return `<h2 class="section">Run of Show</h2>
    <table>
      <thead><tr><th class="time">Start / End</th><th>Item</th><th>Type</th><th>Notes</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

function renderGuestListSection(data) {
  const guests = data.guests || [];
  if (!guests.length) {
    return `<h2 class="section">Door / Guest List</h2>
      <p class="empty">No guest list entries yet. Add entries to <code>event_guest_list</code> via the API to populate this printout.</p>`;
  }
  // Group by list_type for door-friendly layout
  const grouped = guests.reduce((map, g) => {
    const key = g.list_type || 'guest';
    (map[key] = map[key] || []).push(g);
    return map;
  }, {});
  const order = ['vip', 'press', 'industry', 'comp', 'guest', 'will_call'];
  const sections = order
    .filter((key) => grouped[key])
    .map((key) => {
      const rows = grouped[key].map((g) => `<tr>
        <td style="width:24px;"><span style="display:inline-block;width:14px;height:14px;border:1px solid #333;"></span></td>
        <td><strong>${esc(g.name)}</strong></td>
        <td class="num">${esc(g.party_size || 1)}</td>
        <td>${esc(g.guest_of || '')}</td>
        <td>${esc(g.notes || '')}</td>
      </tr>`).join('');
      const total = grouped[key].reduce((sum, g) => sum + Number(g.party_size || 1), 0);
      return `<h3 class="subsection">${esc(titleCase(key))} &middot; ${grouped[key].length} entries, ${total} seats</h3>
        <table>
          <thead><tr><th></th><th>Name</th><th class="num">+</th><th>Guest of</th><th>Notes</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    }).join('');
  const grandTotal = guests.reduce((sum, g) => sum + Number(g.party_size || 1), 0);
  return `<h2 class="section">Door / Guest List <span style="font-size:10pt;font-weight:normal;color:#666;">(${guests.length} entries, ${grandTotal} seats)</span></h2>${sections}`;
}

function renderEventFactsSection(data) {
  const event = data.event;
  const facts = [
    ['Date', shortDate(eventDate(event))],
    ['Doors', timeLabel(event.doors_time)],
    ['Show', timeLabel(event.show_time)],
    ['End', timeLabel(event.end_time)],
    ['Venue', event.venue_name || '—'],
    ['Room', event.room ? titleCase(event.room) : '—'],
    ['Capacity', event.capacity || '—'],
    ['Age', event.age_restriction || 'All ages'],
    ['Ticket', event.ticket_price ? money(event.ticket_price) : 'Free'],
    ['Promoter', event.promoter_name || '—'],
    ['Owner', event.owner_name || 'Unassigned'],
    ['Status', titleCase(event.status)],
  ];
  const notes = event.description_internal ? `<h3 class="subsection">Internal Notes</h3><div class="notes-block">${esc(event.description_internal)}</div>` : '';
  return `<h2 class="section">Event Overview</h2>
    <div class="facts">${facts.map(([label, value]) => `<div class="fact"><label>${esc(label)}</label><strong>${esc(value)}</strong></div>`).join('')}</div>
    ${notes}`;
}

function renderPrintBody(type, data) {
  switch (type) {
    case 'lineup':       return renderLineupSection(data);
    case 'staffing':     return renderStaffingSection(data);
    case 'run-of-show':  return renderRunOfShowSection(data);
    case 'guest-list':   return renderGuestListSection(data);
    case 'master':       return [
      renderEventFactsSection(data),
      `<div class="page-break"></div>` + renderLineupSection(data),
      `<div class="page-break"></div>` + renderRunOfShowSection(data),
      `<div class="page-break"></div>` + renderStaffingSection(data),
      `<div class="page-break"></div>` + renderGuestListSection(data),
    ].join('');
    default:             return `<p class="empty">Unknown printout: ${esc(type)}</p>`;
  }
}

function openPrintWindow(type, data) {
  const title = PRINT_TITLES[type] || 'Printout';
  const win = window.open('', '_blank', 'width=900,height=1100');
  if (!win) {
    publish('toast.show', { message: 'Pop-up blocked — allow pop-ups to print.' });
    return;
  }
  const body = renderPrintBody(type, data);
  const docTitle = `${data.event.title} — ${title}`;
  win.document.open();
  win.document.write(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>${esc(docTitle)}</title>
  <style>${PRINT_CSS}</style>
</head>
<body>
  <div class="print-toolbar">
    <button type="button" onclick="window.print()" class="primary">Print</button>
    <button type="button" onclick="window.close()">Close</button>
  </div>
  <article class="sheet">
    ${printHeader(data, title)}
    ${body}
    ${printFooter(data)}
  </article>
</body>
</html>`);
  win.document.close();
  win.focus();
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

class LoginPage extends PanicElement {
  async connect() {
    // If a magic-link token is in the URL, verify it immediately
    const urlToken = new URLSearchParams(location.search).get('token');
    if (urlToken) {
      this.innerHTML = `<main class="auth-card"><pb-loading-state label="Signing you in"></pb-loading-state></main>`;
      try {
        const data = await api('/auth/verify', { method: 'POST', body: JSON.stringify({ token: urlToken }) });
        setTokens(data.access_token, data.refresh_token);
        publish('auth.changed', data);
        location.href = appUrl();
      } catch {
        this.showForm('That login link is invalid or has already been used.');
      }
      return;
    }
    this.showForm();
    this.startConditionalPasskey();
  }

  showForm(notice = '') {
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      ${notice ? `<div class="auth-notice error">${esc(notice)}</div>` : ''}

      <button class="passkey-btn" data-action="passkey" type="button">
        <span class="passkey-icon">🔑</span>Sign in with passkey
      </button>

      <div class="auth-or"><span>or use password</span></div>

      <form class="stack" data-form="password">
        <label>Email <input type="email" name="email" required autocomplete="username webauthn" placeholder="you@example.com" autofocus></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Password"></label>
        <button type="submit">Sign in</button>
        <p class="error-text" data-pw-error></p>
      </form>

      <div class="auth-or"><span>or</span></div>

      <details class="auth-email-link">
        <summary>Email me a login link instead</summary>
        <form class="stack" data-form="magic-link">
          <label>Email <input type="email" name="email" required placeholder="you@example.com"></label>
          <button type="submit">Send login link</button>
          <p class="error-text" data-ml-error></p>
        </form>
      </details>
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-action="passkey"]', this).addEventListener('click', () => this.passkeyLogin());
    $('[data-form="password"]', this).addEventListener('submit', (e) => this.passwordLogin(e));
    $('[data-form="magic-link"]', this).addEventListener('submit', (e) => this.requestMagicLink(e));
  }

  /** Offer browser-native passkey autocomplete on the email field (silent, non-blocking). */
  async startConditionalPasskey() {
    try {
      if (!window.PublicKeyCredential?.isConditionalMediationAvailable) return;
      const available = await PublicKeyCredential.isConditionalMediationAvailable();
      if (!available) return;
      const opts = await fetch(apiUrl('/auth/passkey-login-begin'), {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
      }).then((r) => r.json());
      if (!opts.challenge) return;
      const cred = await navigator.credentials.get({ publicKey: prepareGetOptions(opts), mediation: 'conditional' });
      if (cred) await this.finishPasskeyLogin(cred);
    } catch { /* cancelled or unsupported — silently ignore */ }
  }

  async passkeyLogin() {
    const btn = $('[data-action="passkey"]', this);
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="passkey-icon">🔑</span>Waiting for passkey…';
    try {
      const opts = await fetch(apiUrl('/auth/passkey-login-begin'), {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
      }).then((r) => r.json());
      const cred = await navigator.credentials.get({ publicKey: prepareGetOptions(opts) });
      await this.finishPasskeyLogin(cred);
    } catch (err) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="passkey-icon">🔑</span>Sign in with passkey'; }
      if (err?.name !== 'NotAllowedError') {
        const errEl = $('[data-pw-error]', this);
        if (errEl) errEl.textContent = err.message || 'Passkey sign-in failed';
      }
    }
  }

  async finishPasskeyLogin(cred) {
    const body = serializeCredential(cred);
    const res  = await fetch(apiUrl('/auth/passkey-login-complete'), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Passkey login failed');
    setTokens(data.access_token, data.refresh_token);
    publish('auth.changed', data);
    location.href = appUrl();
  }

  async passwordLogin(event) {
    event.preventDefault();
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Signing in…';
    $('[data-pw-error]', this).textContent = '';
    try {
      const data = await api('/auth/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      setTokens(data.access_token, data.refresh_token);
      publish('auth.changed', data);
      location.href = appUrl();
    } catch (err) {
      $('[data-pw-error]', this).textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Sign in';
    }
  }

  async requestMagicLink(event) {
    event.preventDefault();
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Sending…';
    $('[data-ml-error]', this).textContent = '';
    try {
      await api('/auth/magic-link', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      event.target.innerHTML = `<div class="auth-notice success">✓ Login link sent — check your email. It expires in 15 minutes.</div>`;
    } catch (err) {
      $('[data-ml-error]', this).textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Send login link';
    }
  }
}

class AppShell extends PanicElement {
  async connect() {
    this.classList.add('app-shell');
    this.renderShell();
    subscribe('event.saved', () => this.refreshCurrent(), this.abort.signal);
    subscribe('event.assetUploaded', () => this.refreshCurrent(), this.abort.signal);
    subscribe('event.openItemResolved', () => this.refreshCurrent(), this.abort.signal);
    subscribe('event.publicationChanged', () => this.refreshCurrent(), this.abort.signal);
    window.addEventListener('hashchange', () => this.route(), { signal: this.abort.signal });
    try {
      const me = await api('/me');
      this.user = me.user;
      this.capabilities = me.capabilities || {};
      publish('auth.changed', me);
      if (!this.user) {
        location.href = appUrl('login.html');
        return;
      }
      this.applyCapabilities();
      await this.route();
    } catch {
      location.href = appUrl('login.html');
    }
  }

  renderShell() {
    this.innerHTML = `<aside class="sidebar">
      <a class="brand" href="#dashboard" aria-label="Panic Backstage home"><span class="brand-mark" aria-hidden="true"></span><span>Panic Backstage</span></a>
      <nav class="side-nav" aria-label="Main navigation">
        <a data-nav="dashboard" href="#dashboard"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i>Dashboard</a>
        <a data-nav="calendar" href="#calendar"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Calendar</a>
        <a data-nav="pipeline" href="#pipeline"><i class="fa-solid fa-table-columns" aria-hidden="true"></i>Pipeline</a>
        <a data-nav="events" href="#events"><i class="fa-solid fa-ticket" aria-hidden="true"></i>Events</a>
        <a data-nav="templates" href="#templates"><i class="fa-solid fa-layer-group" aria-hidden="true"></i>Templates</a>
      </nav>
      <div class="side-card"><span class="bolt"></span><strong>Good shows.<br><span>No surprises.</span></strong></div>
      <button class="venue-switch" type="button"><i class="fa-solid fa-building" aria-hidden="true"></i>Mabuhay Gardens</button>
      <p class="copyright">&copy; 2026 Panic Backstage</p>
    </aside>
    <header class="topbar">
      <a class="mobile-brand" href="#dashboard"><span class="brand-mark"></span><span>Panic Backstage</span></a>
      <label class="search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-search placeholder="Search events..." aria-label="Search events"></label>
      <span class="session-pill" data-user-pill>…</span>
      <a href="#account" class="logout" style="text-decoration:none">Account</a>
      <button id="logout" class="logout">Logout</button>
    </header>
    <main id="app" class="workspace"><pb-loading-state></pb-loading-state></main>
    <footer class="app-footer"><span></span><strong><span class="bolt small-bolt"></span>Built for venues. Run by humans.</strong><span>Demo-ready local and staging paths</span></footer>
    <nav class="mobile-tabs" aria-label="Mobile navigation">
      <a data-nav="dashboard" href="#dashboard"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i>Dashboard</a>
      <a data-nav="calendar" href="#calendar"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Calendar</a>
      <a data-nav="pipeline" href="#pipeline"><i class="fa-solid fa-table-columns" aria-hidden="true"></i>Pipeline</a>
      <a data-nav="events" href="#events"><i class="fa-solid fa-ticket" aria-hidden="true"></i>Events</a>
      <a data-nav="templates" href="#templates"><i class="fa-solid fa-layer-group" aria-hidden="true"></i>Templates</a>
    </nav>
    <pb-toast-stack></pb-toast-stack>`;
    $('#logout', this).addEventListener('click', async () => {
      const refreshToken = getRefreshToken();
      if (refreshToken) {
        await api('/auth/logout', { method: 'POST', body: JSON.stringify({ refresh_token: refreshToken }) }).catch(() => {});
      }
      clearTokens();
      location.href = appUrl('login.html');
    });
    $('[data-search]', this).addEventListener('input', (event) => publish('events.search', { query: event.target.value }));
  }

  applyCapabilities() {
    if (!this.capabilities?.manage_templates) {
      $$('[data-nav="templates"]', this).forEach((link) => link.remove());
    }
    const pill = $('[data-user-pill]', this);
    if (pill && this.user) pill.textContent = this.user.name || this.user.email || 'Account';
  }

  async route() {
    const route = location.hash.replace(/^#/, '') || 'dashboard';
    publish('app.route.changed', { route });
    $$('[data-nav]', this).forEach((link) => link.classList.toggle('active', route.startsWith(link.dataset.nav) || (route.startsWith('event-') && link.dataset.nav === 'events')));
    const outlet = $('#app', this);
    if (route.startsWith('event-')) return this.mount(outlet, 'pb-event-workspace', { eventId: Number(route.slice(6)) });
    if (route === 'calendar')  return this.mount(outlet, 'pb-event-calendar');
    if (route === 'pipeline')  return this.mount(outlet, 'pb-pipeline-board');
    if (route === 'events')    return this.mount(outlet, 'pb-events-list');
    if (route === 'templates') return this.mount(outlet, 'pb-template-picker');
    if (route === 'account')   return this.mount(outlet, 'pb-account-settings');
    return this.mount(outlet, 'pb-dashboard');
  }

  mount(outlet, tagName, props = {}) {
    const element = document.createElement(tagName);
    Object.assign(element, props);
    outlet.replaceChildren(element);
  }

  refreshCurrent() {
    if (location.hash.startsWith('#event-')) this.route();
  }
}

class DashboardView extends PanicElement {
  async connect() {
    this.setLoading('Loading dashboard');
    try {
      const [dashboard, events] = await Promise.all([api('/dashboard'), api('/events')]);
      publish('events.loaded', events);
      this.render(dashboard, events.events || [], events.capabilities || {});
    } catch (error) {
      this.showError(error);
    }
  }

  render(dashboard, allEvents, capabilities = {}) {
    const events = dashboard.events?.length ? dashboard.events : allEvents.slice(0, 8);
    const today = events[0] || allEvents[0] || {};
    const attention = events.filter((event) => event.primary_blocker || Number(event.open_items) || (!Number(event.approved_flyers) && ['confirmed', 'needs_assets', 'ready_to_announce'].includes(event.status))).slice(0, 4);
    const oldest = dashboard.highlights?.oldest_unsettled;
    this.innerHTML = `<section class="page-head">
      <div><h1>Dashboard</h1><p class="subtle">Mabuhay Gardens show operations for the next two weeks.</p></div>
      ${capabilities.manage_templates ? '<a class="button" href="#templates">Create From Template</a>' : ''}
    </section>
    <section class="metric-grid">
      <article class="metric-card"><span class="icon-bubble"><i class="fa-solid fa-microphone" aria-hidden="true"></i></span><h3>Next Show<br>${esc(today.title || 'No event')}</h3><p>Doors ${esc(timeLabel(today.doors_time))}<br>Starts ${esc(timeLabel(today.show_time))}</p>${badge(today.status || 'empty')}</article>
      ${this.metric('!', 'Open Items', dashboard.cards.blockers, `${dashboard.cards.urgentItems || 0} due soon`, 'red')}
      ${this.metric('', 'Empty / Hold', dashboard.cards.empty, dashboard.highlights?.next_empty_date ? shortDate(eventDate({ date: dashboard.highlights.next_empty_date })) : 'No holds soon', '')}
      ${this.metric('', 'Needs Flyer', dashboard.cards.needsAssets, `${dashboard.cards.ready || 0} ready to announce`, 'amber')}
      ${this.metric('$', 'Unsettled', dashboard.cards.unsettled, oldest ? oldest.title : 'All settled', 'red')}
    </section>
    <section class="dashboard-grid">
      <article class="panel"><div class="section-head padded"><h2>Next 14 Days</h2><a class="button secondary small" href="#calendar">Calendar</a></div>${table(events)}</article>
      <article class="panel"><div class="section-head padded"><h2>Needs Attention</h2><a class="button secondary small" href="#events">All Events</a></div>
        <div class="attention-list">${attention.length ? attention.map((event) => `<a class="attention-card ${event.primary_blocker ? '' : 'amber'}" href="#event-${esc(event.id)}"><span class="icon-bubble ${event.primary_blocker ? 'red' : 'amber'}">!</span><span><strong>${esc(event.title)}</strong><p>${esc(event.primary_blocker || 'Flyer or publish step needs review')}</p><small>${esc(shortDate(eventDate(event)))}</small></span><span class="arrow"></span></a>`).join('') : emptyState('No urgent items in the demo window.')}</div>
      </article>
    </section>`;
  }

  metric(symbol, label, value, note, tone) {
    return `<article class="metric-card ${esc(tone)}"><span class="icon-bubble ${esc(tone)}">${symbol ? esc(symbol) : '<i class="fa-solid fa-calendar-days" aria-hidden="true"></i>'}</span><h3>${esc(label)}</h3><strong>${esc(value)}</strong><p>${esc(note)}</p></article>`;
  }
}

class EventCalendar extends PanicElement {
  async connect() {
    this.month = new Date();
    await this.load();
  }

  async load() {
    this.setLoading('Loading calendar');
    const first = new Date(this.month.getFullYear(), this.month.getMonth(), 1);
    const start = addDays(first, -first.getDay());
    const end = addDays(start, 41);
    try {
      const data = await api(`/events?start_date=${isoDate(start)}&end_date=${isoDate(end)}`);
      publish('events.loaded', data);
      this.render(data.events || [], start);
    } catch (error) {
      this.showError(error);
    }
  }

  render(events, start) {
    const days = Array.from({ length: 42 }, (_, index) => addDays(start, index));
    this.innerHTML = `<section class="calendar-page">
      <div class="page-head"><div><h1>Calendar</h1><p class="subtle">Dynamic booking window for Mabuhay Gardens.</p></div><a class="button" href="#templates">Program a Night</a></div>
      <article class="panel calendar-shell">
        <div class="calendar-toolbar">
          <div class="calendar-controls"><button class="secondary small" data-prev>&lt;</button><button class="secondary small" data-next>&gt;</button><button class="secondary small" data-today>Today</button></div>
          <h2>${esc(this.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))}</h2>
          <div class="calendar-actions"><a class="button secondary small" href="#pipeline">Pipeline</a></div>
        </div>
        <div class="calendar-grid">
          ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<div class="weekday">${day}</div>`).join('')}
          ${days.map((date) => {
            const dayEvents = events.filter((event) => event.date === isoDate(date));
            return `<div class="calendar-day"><span class="day-num">${date.getDate()}</span>${dayEvents.length ? dayEvents.map((event) => `<a class="mini-event" href="#event-${esc(event.id)}"><span class="status-dot ${statusTone(event.status)}"></span>${esc(event.title)}<br>${badge(event.status)}</a>`).join('') : '<div class="program-night">Available</div>'}</div>`;
          }).join('')}
        </div>
      </article>
    </section>`;
    $('[data-prev]', this).addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1); this.load(); });
    $('[data-next]', this).addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1); this.load(); });
    $('[data-today]', this).addEventListener('click', () => { this.month = new Date(); this.load(); });
  }
}

class PipelineBoard extends PanicElement {
  async connect() {
    this.setLoading('Loading pipeline');
    try {
      const data = await api('/events');
      this.render(data.events || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(events) {
    this.innerHTML = `<section class="calendar-page">
      <div class="page-head"><div><h1>Pipeline</h1><p class="subtle">Move events from holds to settlement.</p></div></div>
      <section class="pipeline-board">${statuses.slice(0, 10).map((status) => {
        const items = events.filter((event) => event.status === status);
        return `<article class="pipe-col"><h3>${esc(titleCase(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => {
          const editable = Boolean(event.capabilities?.edit_event);
          return `<article class="pipe-card"><strong>${esc(event.title)}</strong><span>${esc(shortDate(eventDate(event)))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small>${editable ? `<form data-event="${esc(event.id)}" class="inline-status">${select('status', statuses, event.status)}<button class="small">Move</button><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></form>` : `<div class="inline-status"><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></div>`}</article>`;
        }).join('') || '<small>No events</small>'}</article>`;
      }).join('')}</section>
    </section>`;
    $$('form[data-event]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(`/events/${form.dataset.event}`, { method: 'PATCH', body: JSON.stringify({ status: formData(form).status }) });
      publish('event.saved', { id: form.dataset.event });
      publish('toast.show', { message: 'Event status updated.' });
      this.connect();
    }));
  }
}

class EventsList extends PanicElement {
  async connect() {
    this.query = '';
    subscribe('events.search', ({ query }) => { this.query = query.toLowerCase(); this.render(this.data); }, this.abort.signal);
    this.setLoading('Loading events');
    try {
      this.data = await api('/events');
      this.render(this.data);
    } catch (error) {
      this.showError(error);
    }
  }

  render(data) {
    if (!data) return;
    const events = (data.events || []).filter((event) => !this.query || String(event.title).toLowerCase().includes(this.query));
    this.innerHTML = `<div class="page-head"><div><h1>Events</h1><p class="subtle">Search, open, and advance every show.</p></div>${data.capabilities?.manage_templates ? '<a class="button" href="#templates">Create Event</a>' : ''}</div><article class="panel">${table(events)}</article>`;
  }
}

class TemplatePicker extends PanicElement {
  async connect() {
    this.setLoading('Loading templates');
    try {
      const data = await api('/templates');
      this.render(data.templates || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(templates) {
    const tomorrow = isoDate(addDays(new Date(), 14));
    this.innerHTML = `<div class="page-head"><div><h1>Templates</h1><p class="subtle">Start the demo by programming a repeatable Mabuhay night.</p></div></div>
    <section class="pipeline-board">${templates.map((template) => `<article class="pipe-card template-card">
      <h2>${esc(template.name)}</h2><p>${esc(titleCase(template.event_type))} at ${esc(template.venue_name)}</p>
      <form data-template="${esc(template.id)}" class="grid-form compact">
        <label>Date <input type="date" name="date" value="${esc(tomorrow)}" required></label>
        <label>Doors <input type="time" name="doors_time" value="19:00"></label>
        <label>Show <input type="time" name="show_time" value="20:00"></label>
        <label>Title <input name="title" value="${esc(template.default_title || template.name)}"></label>
        <button>Create event</button>
      </form>
    </article>`).join('')}</section>`;
    $$('form[data-template]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const result = await api(`/events/from-template/${form.dataset.template}`, { method: 'POST', body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: result.id });
      publish('toast.show', { message: 'Event created from template.' });
      location.hash = `event-${result.id}`;
    }));
  }
}

class EventWorkspace extends PanicElement {
  async connect() {
    await this.load();
  }

  async load() {
    this.setLoading('Loading event workspace');
    try {
      this.data = await api(`/events/${this.eventId}`);
      publish('event.selected', { event: this.data.event });
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const data = this.data;
    const event = data.event;
    const tabs = ['overview', 'details', 'tasks', 'lineup', 'schedule', 'guest-list', 'open-items', 'assets', 'activity'];
    if (can(data, 'manage_invites')) tabs.splice(7, 0, 'invites');
    if (can(data, 'view_settlement')) tabs.splice(tabs.length - 1, 0, 'settlement');
    this.innerHTML = `<section class="event-top">
      <div><a class="back-link" href="#events">&lt;- Back to Events</a><h1>${esc(event.title)}</h1><p class="subtle">${esc(shortDate(eventDate(event)))} at ${esc(event.venue_name)}</p></div>
      <div class="event-actions">
        <a class="button secondary" href="${esc(appUrl(data.links.public_page))}" target="_blank" rel="noreferrer">Public Page</a>
        ${can(data, 'read_event') ? `<details class="print-menu">
          <summary class="button secondary">Print &#9662;</summary>
          <div class="print-menu-items">
            <button type="button" data-print="lineup">Band Lineup</button>
            <button type="button" data-print="staffing">Staffing Schedule</button>
            <button type="button" data-print="run-of-show">Run of Show</button>
            <button type="button" data-print="guest-list">Door / Guest List</button>
            <button type="button" data-print="master">Master Event Packet</button>
          </div>
        </details>` : ''}
        ${can(data, 'publish_event') ? `<button class="danger" data-publish>${Number(event.public_visibility) ? 'Hide Public Page' : 'Publish Public Page'}</button>` : ''}
      </div>
    </section>
    <nav class="workspace-tabs tabs">${tabs.map((tab, index) => `<a class="${index === 0 ? 'active' : ''}" href="#${tab}">${esc(titleCase(tab))}</a>`).join('')}</nav>
    <article class="event-summary">
      <div class="flyer">${esc(event.title)}</div>
      <div class="facts-grid">
        ${this.fact('Date', shortDate(eventDate(event)))}
        ${this.fact('Doors', timeLabel(event.doors_time))}
        ${this.fact('Show', timeLabel(event.show_time))}
        ${this.fact('Status', badge(event.status))}
        ${this.fact('Owner', event.owner_name || 'Unassigned')}
        ${this.fact('Public Page', Number(event.public_visibility) ? 'Live' : 'Hidden')}
      </div>
      <div class="event-stats"><div class="event-stat">Open Items<strong>${data.blockers.filter((item) => ['open','waiting'].includes(item.status)).length}</strong><a href="#open-items">View</a></div><div class="event-stat">Tasks Left<strong>${data.tasks.filter((task) => !['done','canceled'].includes(task.status)).length}</strong><a href="#tasks">View</a></div></div>
    </article>
    <article class="next-action"><span class="icon-bubble amber">!</span><span><strong>Next Recommended Action</strong><p>${esc(data.nextAction)}</p></span><button class="secondary small" data-next-action>Refresh</button></article>
    <section id="overview" class="overview-grid">
      <article class="panel"><div class="section-head padded"><h2>Readiness</h2></div><div class="health-row">${data.readiness.map((item) => `<div class="health-item">${item.ok ? '<span class="check">OK</span>' : '<span class="warn-mark">!</span>'}<span><strong>${esc(item.label)}</strong><br>${esc(item.state)}</span></div>`).join('')}</div></article>
      <article class="panel"><div class="section-head padded"><h2>Internal Notes</h2></div><div class="notes">${esc(event.description_internal || 'No internal notes yet.')}</div></article>
    </section>
    <pb-event-details-form id="details"></pb-event-details-form>
    <pb-task-list id="tasks"></pb-task-list>
    <pb-lineup-editor id="lineup"></pb-lineup-editor>
    <pb-run-sheet id="schedule"></pb-run-sheet>
    <pb-guest-list-manager id="guest-list"></pb-guest-list-manager>
    <pb-open-items id="open-items"></pb-open-items>
    <pb-asset-manager id="assets"></pb-asset-manager>
    ${can(data, 'manage_invites') ? '<pb-invite-manager id="invites"></pb-invite-manager>' : ''}
    ${can(data, 'view_settlement') ? '<pb-settlement-form id="settlement"></pb-settlement-form>' : ''}
    <section id="activity" class="panel"><div class="section-head padded"><h2>Activity</h2></div><ul class="timeline">${data.activity.map((entry) => `<li><strong>${esc(entry.action)}</strong> by ${esc(entry.user_name || 'system')} <span class="muted">${esc(entry.created_at)}</span></li>`).join('')}</ul></section>`;
    $('pb-event-details-form', this).data = data;
    $('pb-task-list', this).data = data;
    $('pb-lineup-editor', this).data = data;
    $('pb-run-sheet', this).data = data;
    $('pb-guest-list-manager', this).data = data;
    $('pb-open-items', this).data = data;
    $('pb-asset-manager', this).data = data;
    if ($('pb-invite-manager', this)) $('pb-invite-manager', this).data = data;
    if ($('pb-settlement-form', this)) $('pb-settlement-form', this).data = data;
    $('[data-publish]', this)?.addEventListener('click', () => this.togglePublic());
    $('[data-next-action]', this).addEventListener('click', () => this.load());
    $$('[data-print]', this).forEach((button) => button.addEventListener('click', () => {
      $('details.print-menu', this)?.removeAttribute('open');
      openPrintWindow(button.dataset.print, this.data);
    }));
    $$('.workspace-tabs a', this).forEach((tab) => tab.addEventListener('click', (event) => {
      event.preventDefault();
      const target = (tab.getAttribute('href') || '').slice(1);
      const section = target ? this.querySelector(`#${CSS.escape(target)}`) : null;
      if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      $$('.workspace-tabs a', this).forEach((other) => other.classList.toggle('active', other === tab));
    }));
  }

  fact(label, value) {
    return `<div class="fact"><label>${esc(label)}</label><strong>${value}</strong></div>`;
  }

  async togglePublic() {
    const event = this.data.event;
    const body = { ...event, public_visibility: Number(event.public_visibility) ? 0 : 1 };
    await api(`/events/${event.id}`, { method: 'PATCH', body: JSON.stringify(body) });
    publish('event.publicationChanged', { id: event.id, public_visibility: body.public_visibility });
    publish('toast.show', { message: body.public_visibility ? 'Public page is live.' : 'Public page hidden.' });
  }
}

class EventDetailsForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const event = data.event;
    const editable = can(data, 'edit_event');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Event Details</h2></div><form class="grid-form padded">
      <label>Title <input name="title" required value="${esc(event.title)}"${disabled}></label>
      <label>Date <input type="date" name="date" required value="${esc(event.date)}"${disabled}></label>
      <label>Venue <select name="venue_id"${disabled}>${data.venues.map((venue) => option(venue.id, event.venue_id, venue.name)).join('')}</select></label>
      <label>Type ${select('event_type', ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'], event.event_type).replace('<select ', `<select${disabled} `)}</label>
      <label>Status ${select('status', statuses, event.status).replace('<select ', `<select${disabled} `)}</label>
      <label>Owner ${ownerSelect(data.users, event.owner_user_id).replace('<select ', `<select${disabled} `)}</label>
      <label>Doors <input type="time" name="doors_time" value="${esc(event.doors_time || '')}"${disabled}></label>
      <label>Show <input type="time" name="show_time" value="${esc(event.show_time || '')}"${disabled}></label>
      <label>End <input type="time" name="end_time" value="${esc(event.end_time || '')}"${disabled}></label>
      <label>Age <input name="age_restriction" value="${esc(event.age_restriction || '')}"${disabled}></label>
      <label>Ticket price <input type="number" step="0.01" name="ticket_price" value="${esc(event.ticket_price || 0)}"${disabled}></label>
      <label>Capacity <input type="number" name="capacity" value="${esc(event.capacity || '')}"${disabled}></label>
      <label class="wide">Ticket URL <input type="url" name="ticket_url" value="${esc(event.ticket_url || '')}"${disabled}></label>
      <label class="wide">Public description <textarea name="description_public"${disabled}>${esc(event.description_public || '')}</textarea></label>
      <label class="wide">Internal notes <textarea name="description_internal"${disabled}>${esc(event.description_internal || '')}</textarea></label>
      <label class="check-label"><input type="checkbox" name="public_visibility" value="1" ${Number(event.public_visibility) ? 'checked' : ''}${disabled}> Public page visible</label>
      ${editable ? '<button>Save details</button>' : ''}
    </form></section>`;
    if (!editable) return;
    $('form', this).addEventListener('submit', async (submitEvent) => {
      submitEvent.preventDefault();
      const body = formData(submitEvent.target);
      body.public_visibility = submitEvent.target.public_visibility.checked ? 1 : 0;
      await api(`/events/${event.id}`, { method: 'PATCH', body: JSON.stringify(body) });
      publish('event.saved', { id: event.id });
      publish('toast.show', { message: 'Event details saved.' });
    });
  }
}

class TaskList extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const tasks = data.tasks || [];
    const editable = can(data, 'manage_tasks');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Tasks</h2></div>${tasks.map((task) => `<form data-api="/events/${data.event.id}/tasks/${task.id}" data-method="PATCH" class="row-form"><label>Task<input name="title" value="${esc(task.title)}"${disabled}></label><label>Status${select('status', ['todo','in_progress','blocked','done','canceled'], task.status).replace('<select ', `<select${disabled} `)}</label><label>Assigned${userSelect(data.users, task.assigned_user_id).replace('<select ', `<select${disabled} `)}</label><label>Due<input type="date" name="due_date" value="${esc(task.due_date || '')}"${disabled}></label><label>Priority${select('priority', ['low','normal','high','urgent'], task.priority).replace('<select ', `<select${disabled} `)}</label><label>Details<input name="description" value="${esc(task.description || '')}"${disabled}></label>${editable ? `<button>Save</button><button type="button" class="secondary" data-complete="${esc(task.id)}">Done</button>` : ''}</form>`).join('') || emptyState('No tasks for this event.')}
    ${editable ? `<form data-api="/events/${data.event.id}/tasks" data-method="POST" class="row-form"><label>Task<input name="title" required placeholder="Confirm door count"></label><label>Assigned${userSelect(data.users)}</label><label>Due<input type="date" name="due_date"></label><label>Priority${select('priority', ['low','normal','high','urgent'], 'normal')}</label><input type="hidden" name="status" value="todo"><input name="description" placeholder="Details"><button>Add task</button></form>` : ''}</section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Task saved.' });
    }));
    $$('[data-complete]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'done';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Task completed.' });
    }));
  }
}

class LineupEditor extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const lineup = data.lineup || [];
    const editable = can(data, 'manage_lineup');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Lineup</h2></div>${lineup.map((item) => `<form data-api="/events/${data.event.id}/lineup/${item.id}" data-method="PATCH" class="row-form"><input name="billing_order" type="number" value="${esc(item.billing_order)}"${disabled}><input name="display_name" value="${esc(item.display_name)}"${disabled}><input name="set_time" type="time" value="${esc(item.set_time || '')}"${disabled}><input name="set_length_minutes" type="number" value="${esc(item.set_length_minutes || '')}"${disabled}>${select('status', ['invited','tentative','confirmed','canceled'], item.status).replace('<select ', `<select${disabled} `)}<input name="payout_terms" value="${esc(item.payout_terms || '')}"${disabled}><input name="notes" value="${esc(item.notes || '')}"${disabled}>${editable ? '<button>Save</button>' : ''}</form>`).join('')}
    ${editable ? `<form data-api="/events/${data.event.id}/lineup" data-method="POST" class="row-form"><input name="band_name" placeholder="Band/artist"><input name="display_name" placeholder="Display name"><input name="billing_order" type="number" placeholder="Order"><input name="set_time" type="time"><input name="set_length_minutes" type="number" placeholder="Minutes">${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}<input name="payout_terms" placeholder="Payout"><button>Add lineup</button></form>` : ''}</section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Lineup saved.' });
    }));
  }
}

class RunSheet extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const schedule = data.schedule || [];
    const editable = can(data, 'manage_schedule');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Run Sheet</h2></div>${schedule.map((item) => `<form data-api="/events/${data.event.id}/schedule/${item.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(item.title)}"${disabled}>${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], item.item_type).replace('<select ', `<select${disabled} `)}<input type="time" name="start_time" value="${esc(item.start_time || '')}"${disabled}><input type="time" name="end_time" value="${esc(item.end_time || '')}"${disabled}><input name="notes" value="${esc(item.notes || '')}"${disabled}>${editable ? '<button>Save</button>' : ''}</form>`).join('')}
    ${editable ? `<form data-api="/events/${data.event.id}/schedule" data-method="POST" class="row-form"><input name="title" required placeholder="Schedule item">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], 'other')}<input type="time" name="start_time"><input type="time" name="end_time"><input name="notes" placeholder="Notes"><button>Add item</button></form>` : ''}</section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Run sheet saved.' });
    }));
  }
}

class OpenItems extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const items = data.blockers || [];
    const editable = can(data, 'manage_open_items');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Open Items</h2></div>${items.map((item) => `<form data-api="/events/${data.event.id}/open-items/${item.id}" data-method="PATCH" class="row-form"><label>Item<input name="title" value="${esc(item.title)}"${disabled}></label><label>Status${select('status', ['open','waiting','resolved','canceled'], item.status).replace('<select ', `<select${disabled} `)}</label><label>Due<input type="date" name="due_date" value="${esc(item.due_date || '')}"${disabled}></label><label>Details<input name="description" value="${esc(item.description || '')}"${disabled}></label><input type="hidden" name="owner_user_id" value="${esc(item.owner_user_id || '')}">${editable ? `<button>Save</button><button type="button" class="secondary" data-resolve="${esc(item.id)}">Mark Complete</button>` : ''}</form>`).join('') || emptyState('No open items for this event.')}
    ${editable ? `<form data-api="/events/${data.event.id}/open-items" data-method="POST" class="row-form"><label>Item<input name="title" required placeholder="Waiting on ticket link"></label><label>Details<input name="description" placeholder="Details"></label><input type="hidden" name="status" value="open"><input type="date" name="due_date"><button>Add open item</button></form>` : ''}</section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Open item saved.' });
    }));
    $$('[data-resolve]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'resolved';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      publish('event.openItemResolved', { id: this.eventData.event.id, itemId: button.dataset.resolve });
      publish('toast.show', { message: 'Open item completed.' });
    }));
  }
}

class GuestListManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const guests = data.guests || [];
    const editable = can(data, 'manage_guest_list');
    const disabled = editable ? '' : ' disabled';
    const listTypes = ['comp', 'guest', 'will_call', 'vip', 'press', 'industry'];

    const grouped = guests.reduce((map, guest) => {
      const key = guest.list_type || 'guest';
      (map[key] = map[key] || []).push(guest);
      return map;
    }, {});
    const sectionOrder = ['vip', 'press', 'industry', 'comp', 'guest', 'will_call'];

    const totalEntries = guests.length;
    const totalSeats = guests.reduce((sum, g) => sum + Number(g.party_size || 1), 0);
    const checkedIn = guests.filter((g) => Number(g.checked_in)).length;
    const checkedSeats = guests
      .filter((g) => Number(g.checked_in))
      .reduce((sum, g) => sum + Number(g.party_size || 1), 0);

    const sections = sectionOrder
      .filter((key) => grouped[key])
      .map((key) => {
        const rows = grouped[key].map((guest) => `<form data-api="/events/${data.event.id}/guest-list/${guest.id}" data-method="PATCH" class="row-form guest-row ${Number(guest.checked_in) ? 'checked-in' : ''}">
          <label class="guest-check">
            <input type="checkbox" data-checkin="${esc(guest.id)}" ${Number(guest.checked_in) ? 'checked' : ''}${disabled}>
            <span>${Number(guest.checked_in) ? 'In' : 'Out'}</span>
          </label>
          <input name="name" value="${esc(guest.name)}"${disabled}>
          <input name="party_size" type="number" min="1" value="${esc(guest.party_size || 1)}" style="max-width:70px"${disabled}>
          ${select('list_type', listTypes, guest.list_type).replace('<select ', `<select${disabled} `)}
          <input name="guest_of" placeholder="Guest of" value="${esc(guest.guest_of || '')}"${disabled}>
          <input name="notes" placeholder="Notes" value="${esc(guest.notes || '')}"${disabled}>
          ${editable ? `<button>Save</button><button type="button" class="small danger" data-delete="${esc(guest.id)}">Delete</button>` : ''}
        </form>`).join('');
        const subtotalEntries = grouped[key].length;
        const subtotalSeats = grouped[key].reduce((sum, g) => sum + Number(g.party_size || 1), 0);
        return `<div class="guest-section">
          <h3 class="guest-section-head">${esc(titleCase(key))} <span class="muted">${subtotalEntries} entries &middot; ${subtotalSeats} seats</span></h3>
          ${rows}
        </div>`;
      }).join('');

    const addForm = editable ? `<form data-api="/events/${data.event.id}/guest-list" data-method="POST" class="row-form guest-add">
      <input name="name" required placeholder="Guest name">
      <input name="party_size" type="number" min="1" value="1" placeholder="+" style="max-width:70px">
      ${select('list_type', listTypes, 'guest')}
      <input name="guest_of" placeholder="Guest of (band/promoter)">
      <input name="notes" placeholder="Notes">
      <button>Add guest</button>
    </form>` : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Door / Guest List</h2>
        <div class="guest-totals muted">${totalEntries} entries &middot; ${totalSeats} seats &middot; ${checkedIn} checked in (${checkedSeats} seats)</div>
      </div>
      <div class="guest-list-body">
        ${guests.length ? sections : emptyState('No guest list entries yet.')}
        ${addForm}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Guest list saved.' });
    }));
    $$('[data-checkin]', this).forEach((checkbox) => checkbox.addEventListener('change', async () => {
      const id = checkbox.dataset.checkin;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ checked_in: checkbox.checked ? 1 : 0 }),
      });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: checkbox.checked ? 'Checked in.' : 'Check-in cleared.' });
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      const id = button.dataset.delete;
      if (!confirm('Remove this guest from the list?')) return;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, { method: 'DELETE' });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Guest removed.' });
    }));
  }
}

class AssetManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const assets = data.assets || [];
    const canManage = can(data, 'manage_assets');
    const canUpload = can(data, 'upload_assets');
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Assets</h2></div><div class="asset-grid">${assets.map((asset) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp|svg)$/i.test(asset.filename) ? `<img src="${esc(assetUrl(asset.file_path))}" alt="">` : '<span class="asset-thumb">PDF</span>'}<strong>${esc(asset.title)}</strong><span>${esc(titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span><div class="inline-actions"><a class="button small secondary" href="${esc(assetUrl(asset.file_path))}" download>Download</a>${canManage ? `<button class="small" data-approve="${esc(asset.id)}">Approve</button><button class="small secondary" data-reject="${esc(asset.id)}">Reject</button><button class="small danger" data-delete="${esc(asset.id)}">Delete</button>` : ''}</div></article>`).join('') || emptyState('No assets uploaded yet.')}</div>
    ${canUpload ? `<form id="asset-form" class="row-form"><input name="title" placeholder="Asset title">${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}<input type="file" name="asset" accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,.pdf" required><input name="notes" placeholder="Notes"><button>Upload asset</button></form>` : ''}</section>`;
    this.bind();
  }

  bind() {
    $('#asset-form', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${this.eventData.event.id}/assets`, { method: 'POST', body: new FormData(event.target) });
        publish('event.assetUploaded', { id: this.eventData.event.id });
        publish('toast.show', { message: 'Asset uploaded.' });
        event.target.reset();
      } catch (err) {
        publish('toast.show', { message: err.message || 'Upload failed.', tone: 'error' });
      }
    });
    $$('[data-approve],[data-reject]', this).forEach((button) => button.addEventListener('click', async () => {
      const status = button.dataset.approve ? 'approved' : 'rejected';
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.approve || button.dataset.reject}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
        publish('event.assetUploaded', { id: this.eventData.event.id });
        publish('toast.show', { message: `Asset ${status}.` });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Action failed.', tone: 'error' });
      }
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.delete}`, { method: 'DELETE' });
        publish('event.assetUploaded', { id: this.eventData.event.id });
        publish('toast.show', { message: 'Asset deleted.' });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Delete failed.', tone: 'error' });
      }
    }));
  }
}

class InviteManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const roles = ['event_owner','promoter','band','artist','designer','staff','viewer'];
    const invites = data.invites || [];
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Invites</h2></div><div class="invite-list">${invites.length ? invites.map((invite) => {
      const url = appUrl(`invite.html?token=${invite.token}`);
      return `<article class="invite-row"><span><strong>${esc(invite.email)}</strong><br><small>${esc(titleCase(invite.role))} - ${invite.used_at ? 'Accepted' : `Expires ${esc(invite.expires_at)}`}</small></span><input readonly value="${esc(url)}"><button class="secondary small" data-copy="${esc(url)}">Copy link</button></article>`;
    }).join('') : emptyState('No invites have been created for this event.')}</div>
    <form class="row-form"><label>Email<input type="email" name="email" required placeholder="promoter@example.com"></label><label>Role${select('role', roles, 'viewer')}</label><button>Create invite link</button></form></section>`;
    $('form', this).addEventListener('submit', async (event) => {
      event.preventDefault();
      const result = await api(`/events/${this.eventData.event.id}/invites`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: `Invite created: ${appUrl(result.url)}` });
    });
    $$('[data-copy]', this).forEach((button) => button.addEventListener('click', async () => {
      if (navigator.clipboard) {
        await navigator.clipboard.writeText(button.dataset.copy);
      } else {
        button.previousElementSibling?.select();
        document.execCommand('copy');
      }
      publish('toast.show', { message: 'Invite link copied.' });
    }));
  }
}

class SettlementForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const settlement = data.settlement || {};
    const fields = ['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'];
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Settlement</h2><button class="secondary small" type="button" data-calc>Calculate venue net</button></div><form class="row-form">${fields.map((field) => `<label>${esc(titleCase(field))}<input name="${esc(field)}" type="number" step="0.01" value="${esc(settlement[field] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(settlement.notes || '')}</textarea></label><button>Save settlement</button></form></section>`;
    const form = $('form', this);
    const calculate = () => {
      const values = formData(form);
      const venueNet = Number(values.gross_ticket_sales || 0) + Number(values.bar_sales || 0) - Number(values.expenses || 0) - Number(values.band_payouts || 0) - Number(values.promoter_payout || 0);
      form.elements.venue_net.value = venueNet.toFixed(2);
    };
    $('[data-calc]', this).addEventListener('click', calculate);
    ['gross_ticket_sales','bar_sales','expenses','band_payouts','promoter_payout'].forEach((name) => form.elements[name].addEventListener('input', calculate));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(`/events/${this.eventData.event.id}/settlement`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Settlement saved.' });
    });
  }
}

class PublicEventPage extends PanicElement {
  async connect() {
    this.setLoading('Loading public event');
    const slug = new URLSearchParams(location.search).get('slug');
    try {
      const data = await api(`/public/events/${encodeURIComponent(slug || '')}`);
      const event = data.event;
      this.innerHTML = `<main class="public-container"><article class="public-event">${data.flyer ? `<img class="public-flyer" src="${esc(assetUrl(data.flyer.file_path))}" alt="">` : `<div class="public-flyer flyer">${esc(event.title)}</div>`}<div class="public-copy"><p class="eyebrow">${esc(shortDate(eventDate(event)))} - ${esc(event.venue_name)}</p><h1>${esc(event.title)}</h1><p><strong>Doors</strong> ${esc(timeLabel(event.doors_time))} - <strong>Show</strong> ${esc(timeLabel(event.show_time))}</p><p>${esc(event.age_restriction || 'All ages unless noted')} - ${Number(event.ticket_price) > 0 ? money(event.ticket_price) : 'Free / door'}</p>${event.ticket_url ? `<a class="button" href="${esc(event.ticket_url)}">Tickets</a>` : ''}<p>${esc(event.description_public || '')}</p><h2>Lineup</h2><ul class="plain-list">${data.lineup.map((item) => `<li>${esc(item.display_name)} ${item.set_time ? `<span>${esc(timeLabel(item.set_time))}</span>` : ''}</li>`).join('')}</ul><p class="muted">${esc(event.address)}, ${esc(event.city)}, ${esc(event.state)}</p></div></article></main>`;
    } catch (error) {
      this.showError(error);
    }
  }
}

class InviteAcceptance extends PanicElement {
  async connect() {
    this.setLoading('Loading invite');
    const token = new URLSearchParams(location.search).get('token');
    try {
      const data = await api(`/invite/${encodeURIComponent(token || '')}`);
      this.innerHTML = `<main class="auth-card"><h1>Join ${esc(data.invite.event_title)}</h1><p>Invited as <strong>${esc(titleCase(data.invite.role))}</strong> using ${esc(data.invite.email)}.</p><form class="grid-form"><label>Your name <input name="name" required placeholder="First and last name" autofocus></label><button>Accept Invite</button></form></main><pb-toast-stack></pb-toast-stack>`;
      $('form', this).addEventListener('submit', async (event) => {
        event.preventDefault();
        const result = await api(`/invite/${encodeURIComponent(token || '')}`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
        setTokens(result.access_token, result.refresh_token);
        location.href = appUrl(`#event-${result.event_id}`);
      });
    } catch (error) {
      this.showError(error);
    }
  }
}

class AccountSettings extends PanicElement {
  async connect() {
    this.setLoading('Loading account settings');
    try {
      const data   = await api('/auth/passkeys', { method: 'POST', body: '{}' });
      this.passkeys    = data.passkeys || [];
      this.hasPassword = Boolean(data.has_password);
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const passkeySupported = Boolean(window.PublicKeyCredential);
    this.innerHTML = `<section class="page-head">
      <div><h1>Account Settings</h1><p class="subtle">Manage your login methods.</p></div>
    </section>
    <div class="panel padded" style="max-width: 560px">

      <div class="account-section">
        <h2>Passkeys (biometric login)</h2>
        ${this.passkeys.length
          ? `<div class="passkey-list">${this.passkeys.map((pk) => `
            <div class="passkey-item">
              <span class="passkey-icon" style="font-size:20px">🔑</span>
              <div class="passkey-item-info">
                <div class="passkey-item-name">${esc(pk.name)}</div>
                <div class="passkey-item-meta">Added ${esc(new Date(pk.created_at).toLocaleDateString())}${pk.last_used_at ? ' · Last used ' + esc(new Date(pk.last_used_at).toLocaleDateString()) : ''}</div>
              </div>
              <button class="button" style="background:var(--danger,#dc2626)" data-remove="${esc(pk.id)}">Remove</button>
            </div>`).join('')}</div>`
          : `<p class="muted" style="margin-bottom:12px">No passkeys registered yet.</p>`}
        ${passkeySupported
          ? `<button class="button" data-action="add-passkey">+ Add passkey for this device</button>`
          : `<p class="muted">Your browser does not support passkeys.</p>`}
      </div>

      <div class="account-section">
        <h2>${this.hasPassword ? 'Change password' : 'Set a password'}</h2>
        <p class="muted">${this.hasPassword ? 'Enter your current password before setting a new one.' : 'A password lets you sign in alongside passkeys and email links.'}</p>
        <form class="stack" data-form="password" style="margin-top:14px">
          ${this.hasPassword ? `<label>Current password <input type="password" name="current_password" required autocomplete="current-password" placeholder="Current password"></label>` : ''}
          <label>New password <input type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters" minlength="8"></label>
          <label>Confirm <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Same password again"></label>
          <button type="submit">${this.hasPassword ? 'Change password' : 'Set password'}</button>
          <p class="error-text" data-pw-error></p>
        </form>
      </div>

    </div>`;

    $$('[data-remove]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        if (confirm('Remove this passkey? You will no longer be able to sign in with it.')) {
          this.removePasskey(Number(btn.dataset.remove));
        }
      });
    });
    $('[data-action="add-passkey"]', this)?.addEventListener('click', () => this.addPasskey());
    $('[data-form="password"]', this)?.addEventListener('submit', (e) => this.setPassword(e));
  }

  async removePasskey(id) {
    try {
      await api('/auth/remove-passkey', { method: 'POST', body: JSON.stringify({ id }) });
      publish('toast.show', { message: 'Passkey removed.', tone: 'info' });
      this.passkeys = this.passkeys.filter((pk) => pk.id !== id);
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async addPasskey() {
    const btn = $('[data-action="add-passkey"]', this);
    if (btn) { btn.disabled = true; btn.textContent = 'Waiting for device…'; }
    try {
      const opts = await api('/auth/passkey-register-begin', { method: 'POST', body: '{}' });
      const cred = await navigator.credentials.create({ publicKey: prepareCreateOptions(opts) });
      const body = serializeCredential(cred);
      const name = (cred.authenticatorAttachment === 'platform' ? 'This device' : 'Security key')
                 + ' — ' + new Date().toLocaleDateString();
      await api('/auth/passkey-register-complete', {
        method: 'POST',
        body: JSON.stringify({ name, response: body.response }),
      });
      publish('toast.show', { message: 'Passkey added — you can now sign in with biometrics.', tone: 'success' });
      this.connect();
    } catch (err) {
      if (err?.name !== 'NotAllowedError') {
        publish('toast.show', { message: err.message || 'Could not add passkey', tone: 'error' });
      }
      if (btn) { btn.disabled = false; btn.textContent = '+ Add passkey for this device'; }
    }
  }

  async setPassword(event) {
    event.preventDefault();
    const fd = formData(event.target);
    if (fd.password !== fd.confirm_password) {
      $('[data-pw-error]', this).textContent = 'Passwords do not match';
      return;
    }
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    $('[data-pw-error]', this).textContent = '';
    try {
      await api('/auth/set-password', { method: 'POST', body: JSON.stringify(fd) });
      publish('toast.show', { message: 'Password saved.', tone: 'success' });
      this.hasPassword = true;
      this.render();
    } catch (err) {
      $('[data-pw-error]', this).textContent = err.message;
      btn.disabled = false;
    }
  }
}

customElements.define('pb-loading-state', LoadingState);
customElements.define('pb-toast-stack', ToastStack);
customElements.define('pb-login-page', LoginPage);
customElements.define('pb-account-settings', AccountSettings);
customElements.define('pb-app-shell', AppShell);
customElements.define('pb-dashboard', DashboardView);
customElements.define('pb-event-calendar', EventCalendar);
customElements.define('pb-pipeline-board', PipelineBoard);
customElements.define('pb-events-list', EventsList);
customElements.define('pb-template-picker', TemplatePicker);
customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-event-details-form', EventDetailsForm);
customElements.define('pb-task-list', TaskList);
customElements.define('pb-lineup-editor', LineupEditor);
customElements.define('pb-run-sheet', RunSheet);
customElements.define('pb-open-items', OpenItems);
customElements.define('pb-guest-list-manager', GuestListManager);
customElements.define('pb-asset-manager', AssetManager);
customElements.define('pb-invite-manager', InviteManager);
customElements.define('pb-settlement-form', SettlementForm);
customElements.define('pb-public-event-page', PublicEventPage);
customElements.define('pb-invite-acceptance', InviteAcceptance);
