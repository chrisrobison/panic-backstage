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

// Sheet-derived display labels for the event-status enum. The MabEvents
// Google Sheet's "Status" column is the source of truth for vocabulary, so
// pipeline columns and badges read in the same language as the sheet.
// Statuses with no sheet counterpart fall through to titleCase().
const STATUS_LABELS = {
  proposed:  'Prospect',
  hold:      'In Negotiations',
  confirmed: 'Booked',
  canceled:  'Cancelled',
  completed: 'Archived',
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
  const shifts = (data.staffing || []).slice().sort((a, b) => {
    const ta = a.call_time || '99:99:99';
    const tb = b.call_time || '99:99:99';
    return ta.localeCompare(tb);
  });
  const collaborators = (data.collaborators || []).filter((c) => ['venue_admin','event_owner','promoter','staff','designer'].includes(c.event_role));
  const staffCalls = (data.schedule || []).filter((item) => item.item_type === 'staff_call');

  const shiftRows = shifts.length ? shifts.map((s) => `<tr>
    <td class="time">${esc(timeLabel(s.call_time))}${s.end_time ? `<br><span style="color:#666;">${esc(timeLabel(s.end_time))}</span>` : ''}</td>
    <td>${esc(titleCase(s.role))}</td>
    <td><strong>${esc(s.staff_name || 'TBD')}</strong>${s.staff_phone ? `<br><span style="color:#666;">${esc(s.staff_phone)}</span>` : ''}</td>
    <td>${printPill(s.status)}</td>
    <td>${esc(s.notes || '')}</td>
  </tr>`).join('') : '';
  const peopleRows = collaborators.length ? collaborators.map((c) => `<tr>
    <td><strong>${esc(c.name || '—')}</strong></td>
    <td>${esc(titleCase(c.event_role))}</td>
    <td>${esc(c.email || '')}</td>
  </tr>`).join('') : '';
  const callRows = staffCalls.length ? staffCalls.map((item) => `<tr>
    <td class="time">${esc(timeLabel(item.start_time))}</td>
    <td><strong>${esc(item.title)}</strong></td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('') : '';

  return `<h2 class="section">Staffing Schedule</h2>
    <h3 class="subsection">Shifts</h3>
    ${shiftRows ? `<table>
      <thead><tr><th class="time">Call / End</th><th>Role</th><th>Staff</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>${shiftRows}</tbody>
    </table>` : `<p class="empty">No shifts scheduled.</p>`}
    <h3 class="subsection">Event Collaborators</h3>
    ${peopleRows ? `<table>
      <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
      <tbody>${peopleRows}</tbody>
    </table>` : `<p class="empty">No collaborators assigned.</p>`}
    <h3 class="subsection">Staff Call Times (Run Sheet)</h3>
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
    ['Status', statusLabel(event.status)],
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
    // ── Magic-link landing ────────────────────────────────────────────────
    // Critical: do NOT call /auth/verify on page load. iMessage / SMS link
    // previewers and some corporate scanners execute the page's JavaScript,
    // and a verify call here would mark the token used_at and burn it
    // before the human ever clicks the bubble. Instead we render an
    // explicit "Continue" interstitial and verify only on a real click.
    const urlToken = new URLSearchParams(location.search).get('token');
    if (urlToken) {
      await this.renderTokenLanding(urlToken);
      return;
    }
    this.email = '';
    this.showEmailStep();
    this.startConditionalPasskey();
  }

  /** Step 0: explicit "Continue to your account" interstitial for magic-link URLs. */
  async renderTokenLanding(token) {
    this.innerHTML = `<main class="auth-card"><pb-loading-state label="Checking your link"></pb-loading-state></main>`;
    let status;
    try {
      status = await api('/auth/verify-status', { method: 'POST', body: JSON.stringify({ token }) });
    } catch {
      this.showEmailStep('We could not check that login link. Please request a new one.');
      return;
    }
    if (!status?.valid) {
      this.showEmailStep('That login link is invalid or has already been used. Request a new one below.');
      return;
    }

    const greeting = status.name ? esc(status.name) : esc(status.email);
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      <p class="auth-greeting">Hi, <strong>${greeting}</strong></p>
      <p class="muted">Click below to finish signing in. This link can only be used once.</p>
      <button class="primary block" data-action="continue" type="button">Continue to your account</button>
      <p class="auth-sub"><a href="#" data-action="request-new">Send a new login link</a></p>
      <p class="error-text" data-error></p>
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-action="continue"]', this).addEventListener('click', () => this.consumeToken(token));
    $('[data-action="request-new"]', this).addEventListener('click', (e) => {
      e.preventDefault();
      this.email = status.email || '';
      this.showEmailStep('Enter your email and we will send a fresh link.');
    });
  }

  async consumeToken(token) {
    const btn = $('[data-action="continue"]', this);
    if (btn) { btn.disabled = true; btn.textContent = 'Signing you in…'; }
    try {
      const data = await api('/auth/verify', { method: 'POST', body: JSON.stringify({ token }) });
      this.completeLogin(data);
    } catch (err) {
      const errEl = $('[data-error]', this);
      if (errEl) errEl.textContent = err.message || 'Could not sign in. Request a fresh link.';
      if (btn) { btn.disabled = false; btn.textContent = 'Continue to your account'; }
    }
  }

  /** Hand off to the app after any successful sign-in path. */
  completeLogin(data) {
    setTokens(data.access_token, data.refresh_token);
    publish('auth.changed', data);
    // The app shell calls /api/me on load and decides whether to show the
    // credential-setup modal from that — no client-side hint needed.
    location.href = appUrl();
  }

  // ── Email-first multi-step sign-in ────────────────────────────────────────

  /** Step 1: just an email field. We branch from here based on what the account has. */
  showEmailStep(notice = '') {
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      ${notice ? `<div class="auth-notice ${notice.startsWith('✓') ? 'success' : 'error'}">${esc(notice)}</div>` : ''}

      <form class="stack" data-form="email-step">
        <label>Email <input type="email" name="email" required autocomplete="username webauthn" placeholder="you@example.com" autofocus value="${esc(this.email || '')}"></label>
        <button class="primary block" type="submit">Continue</button>
        <p class="error-text" data-email-error></p>
      </form>

      <div class="auth-or"><span>or</span></div>

      <button class="passkey-btn" data-action="passkey" type="button">
        <span class="passkey-icon">🔑</span>Sign in with passkey
      </button>
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-form="email-step"]', this).addEventListener('submit', (e) => this.onEmailContinue(e));
    $('[data-action="passkey"]', this).addEventListener('click', () => this.passkeyLogin());
  }

  /** Step 2: per-account methods. Always offers the magic-link fallback. */
  showMethodStep(email, methods) {
    this.email = email;
    const friendly = methods?.name ? esc(methods.name) : esc(email);

    const blocks = [];
    if (methods?.has_passkey) {
      blocks.push(`<button class="passkey-btn" data-action="passkey" type="button">
        <span class="passkey-icon">🔑</span>Sign in with passkey
      </button>`);
    }
    if (methods?.has_password) {
      if (blocks.length) blocks.push(`<div class="auth-or"><span>or password</span></div>`);
      blocks.push(`<form class="stack" data-form="password">
        <input type="hidden" name="email" value="${esc(email)}">
        <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Password" autofocus></label>
        <button type="submit">Sign in</button>
        <p class="error-text" data-pw-error></p>
      </form>`);
    }
    if (blocks.length) blocks.push(`<div class="auth-or"><span>or</span></div>`);

    // Magic-link is always offered. For accounts with no credentials yet
    // this is the primary path; otherwise it's the fallback.
    const isOnly = !methods?.has_password && !methods?.has_passkey;
    blocks.push(`<form class="stack" data-form="magic-link">
      <input type="hidden" name="email" value="${esc(email)}">
      <button type="submit" class="${isOnly ? 'primary block' : ''}">Email me a login link</button>
      ${isOnly ? '<p class="muted small">We will email <strong>' + esc(email) + '</strong> a one-time link that expires in 24 hours.</p>' : ''}
      <p class="error-text" data-ml-error></p>
    </form>`);

    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      <p class="auth-greeting">Signing in as <strong>${friendly}</strong> <a href="#" data-action="back" class="small">change</a></p>
      ${blocks.join('\n')}
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-action="back"]', this).addEventListener('click', (e) => {
      e.preventDefault();
      this.showEmailStep();
    });
    $('[data-action="passkey"]', this)?.addEventListener('click', () => this.passkeyLogin(email));
    $('[data-form="password"]', this)?.addEventListener('submit', (e) => this.passwordLogin(e));
    $('[data-form="magic-link"]', this).addEventListener('submit', (e) => this.requestMagicLink(e));
  }

  async onEmailContinue(event) {
    event.preventDefault();
    const fd = formData(event.target);
    const email = String(fd.email || '').trim().toLowerCase();
    if (!email) return;
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Checking…';
    $('[data-email-error]', this).textContent = '';
    try {
      const methods = await api('/auth/lookup', { method: 'POST', body: JSON.stringify({ email }) });
      this.showMethodStep(email, methods);
    } catch (err) {
      $('[data-email-error]', this).textContent = err.message || 'Could not look that up. Try again.';
      btn.disabled = false;
      btn.textContent = 'Continue';
    }
  }

  // ── Passkey / password / magic-link handlers ──────────────────────────────

  /** Browser-native passkey autocomplete on the email field. Silent, non-blocking. */
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

  async passkeyLogin(email = '') {
    const btn = $('[data-action="passkey"]', this);
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="passkey-icon">🔑</span>Waiting for passkey…';
    }
    try {
      const opts = await fetch(apiUrl('/auth/passkey-login-begin'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(email ? { email } : {}),
      }).then((r) => r.json());
      const cred = await navigator.credentials.get({ publicKey: prepareGetOptions(opts) });
      await this.finishPasskeyLogin(cred);
    } catch (err) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="passkey-icon">🔑</span>Sign in with passkey'; }
      if (err?.name !== 'NotAllowedError') {
        const errEl = $('[data-pw-error]', this) || $('[data-email-error]', this);
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
    this.completeLogin(data);
  }

  async passwordLogin(event) {
    event.preventDefault();
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Signing in…';
    $('[data-pw-error]', this).textContent = '';
    try {
      const data = await api('/auth/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      this.completeLogin(data);
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
      event.target.innerHTML = `<div class="auth-notice success">✓ Login link sent — check your email. It expires in 24 hours and can be used once.</div>`;
    } catch (err) {
      $('[data-ml-error]', this).textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Email me a login link';
    }
  }
}

// ── Credential setup modal ──────────────────────────────────────────────────
//
// Shown after sign-in when a user has neither a passkey nor a password.
// Dismissible — re-shown on every sign-in until they set up at least one
// credential or check "Don't show this again". Reuses the existing
// `/auth/passkey-register-*` and `/auth/set-password` endpoints.

function openCredentialSetupModal(user, onChange) {
  if (document.querySelector('[data-credential-setup-modal]')) return; // already open
  const dialog = document.createElement('div');
  dialog.className = 'modal-backdrop';
  dialog.setAttribute('data-credential-setup-modal', '');
  dialog.innerHTML = renderCredentialSetupBody(user);
  document.body.appendChild(dialog);

  const close = () => dialog.remove();
  const refresh = async () => {
    try {
      const me = await api('/me');
      if (me?.user) {
        user = me.user;
        if (typeof onChange === 'function') onChange(user);
        // If they now have a credential, close the modal.
        if (user.has_password || user.has_passkey) {
          publish('toast.show', { message: 'Sign-in method saved.', tone: 'success' });
          close();
          return;
        }
        dialog.querySelector('.modal-card').outerHTML = renderCredentialSetupBody(user, true);
        bind();
      }
    } catch { /* ignore */ }
  };

  const bind = () => {
    $('[data-action="skip"]', dialog).addEventListener('click', async () => {
      const hide = $('[data-hide-future]', dialog)?.checked;
      if (hide) {
        try {
          await api('/auth/preferences', {
            method: 'POST',
            body: JSON.stringify({ hide_credential_setup_prompt: true }),
          });
          user.hide_credential_setup_prompt = true;
          if (typeof onChange === 'function') onChange(user);
        } catch (err) {
          publish('toast.show', { message: err.message || 'Could not save preference', tone: 'error' });
          return;
        }
      }
      close();
    });

    $('[data-action="add-passkey"]', dialog)?.addEventListener('click', async () => {
      const btn = $('[data-action="add-passkey"]', dialog);
      btn.disabled = true;
      btn.textContent = 'Waiting for device…';
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
        await refresh();
      } catch (err) {
        if (err?.name !== 'NotAllowedError') {
          publish('toast.show', { message: err.message || 'Could not add passkey', tone: 'error' });
        }
        btn.disabled = false;
        btn.textContent = 'Add a passkey for this device';
      }
    });

    $('[data-form="password"]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = formData(event.target);
      if (fd.password !== fd.confirm_password) {
        $('[data-pw-error]', dialog).textContent = 'Passwords do not match';
        return;
      }
      const btn = $('button[type="submit"]', event.target);
      btn.disabled = true;
      $('[data-pw-error]', dialog).textContent = '';
      try {
        // current_password is empty — user has no password yet, the server
        // accepts that path.
        await api('/auth/set-password', { method: 'POST', body: JSON.stringify(fd) });
        await refresh();
      } catch (err) {
        $('[data-pw-error]', dialog).textContent = err.message || 'Could not set password';
        btn.disabled = false;
      }
    });
  };

  bind();
}

// ── Quick-create event modal ─────────────────────────────────────────────────
//
// Friction-reducing entry point for new events: opened from a calendar day
// cell, the topbar "+ New event" button, or any other future affordance.
// Picks an event template (or "Blank event"), pre-fills sensible defaults,
// posts to /events/from-template/{id} or /events, then jumps to the new
// event's workspace.

async function openEventQuickCreate({ date = null } = {}) {
  if (document.querySelector('[data-event-quick-create-modal]')) return;
  const isoToday = isoDate(new Date());
  const startDate = date || isoToday;

  const dialog = document.createElement('div');
  dialog.className = 'modal-backdrop';
  dialog.setAttribute('data-event-quick-create-modal', '');
  dialog.innerHTML = `<div class="modal-card">
    <div class="section-head padded"><h2>New event</h2><button class="small secondary" data-close type="button">Close</button></div>
    <div class="padded"><pb-loading-state label="Loading templates"></pb-loading-state></div>
  </div>`;
  document.body.appendChild(dialog);

  const close = () => dialog.remove();
  $('[data-close]', dialog).addEventListener('click', close);
  dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
  document.addEventListener('keydown', function onEsc(e) {
    if (e.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
  });

  let data;
  try {
    data = await api('/templates');
  } catch (err) {
    dialog.querySelector('.modal-card .padded').innerHTML = `<p class="error-text">${esc(err.message || 'Could not load templates.')}</p>`;
    return;
  }

  const templates = data.templates || [];
  const venues    = data.venues    || [];
  const types     = data.types     || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
  // Default to "General Event" if it exists, else the first template.
  const defaultTemplate = templates.find((t) => t.name === 'General Event') || templates[0];

  const body = dialog.querySelector('.modal-card .padded');
  body.innerHTML = `<form class="grid-form" data-form="quick-create">
    <label class="wide">Template
      <select name="template_id">
        ${templates.map((t) => `<option value="${esc(t.id)}" ${defaultTemplate && Number(t.id) === Number(defaultTemplate.id) ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}
        <option value="">— Blank event —</option>
      </select>
    </label>

    <label>Date <input type="date" name="date" required value="${esc(startDate)}"></label>
    <label class="wide">Title <input name="title" required value="${esc(defaultTemplate?.default_title || defaultTemplate?.name || '')}" placeholder="Event title"></label>
    <label>Doors <input type="time" name="doors_time" value="19:00"></label>
    <label>Show <input type="time" name="show_time" value="20:00"></label>

    <fieldset class="quick-create-blank-fields" hidden>
      <label>Venue <select name="venue_id">${venues.map((v) => `<option value="${esc(v.id)}">${esc(v.name)}</option>`).join('')}</select></label>
      <label>Type <select name="event_type">${types.map((t) => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('')}</select></label>
    </fieldset>

    <div class="wide quick-create-actions">
      <button type="submit" class="primary">Create event</button>
      <button type="button" class="secondary" data-close>Cancel</button>
    </div>
    <p class="error-text wide" data-error></p>
  </form>`;

  // Make the second "Cancel" close button work, too.
  $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));

  const form          = $('[data-form="quick-create"]', dialog);
  const templateSelect = $('select[name="template_id"]', form);
  const titleInput    = $('input[name="title"]', form);
  const blankFields   = $('.quick-create-blank-fields', form);

  // Keep the title in sync with the chosen template's default until the user
  // edits it manually. Show the venue/type fields when "Blank event" is picked.
  let titleEdited = false;
  titleInput.addEventListener('input', () => { titleEdited = true; });
  templateSelect.addEventListener('change', () => {
    const id = templateSelect.value;
    const chosen = templates.find((t) => String(t.id) === id);
    if (!titleEdited) {
      titleInput.value = chosen?.default_title || chosen?.name || '';
    }
    blankFields.hidden = Boolean(id);
    if (!id) {
      blankFields.querySelectorAll('select').forEach((select) => select.required = true);
    } else {
      blankFields.querySelectorAll('select').forEach((select) => select.required = false);
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = $('button[type="submit"]', form);
    submit.disabled = true;
    submit.textContent = 'Creating…';
    $('[data-error]', form).textContent = '';
    const fd = formData(form);
    try {
      let created;
      if (fd.template_id) {
        const id = Number(fd.template_id);
        delete fd.template_id; delete fd.venue_id; delete fd.event_type;
        created = await api(`/events/from-template/${id}`, { method: 'POST', body: JSON.stringify(fd) });
      } else {
        delete fd.template_id;
        // Blank-event path uses /events POST which needs venue_id + event_type.
        fd.status = 'proposed';
        created = await api('/events', { method: 'POST', body: JSON.stringify(fd) });
      }
      publish('toast.show', { message: `Created "${fd.title}".`, tone: 'success' });
      publish('event.saved', { id: created.id });
      close();
      location.hash = `event-${created.id}`;
    } catch (err) {
      $('[data-error]', form).textContent = err.message || 'Could not create event.';
      submit.disabled = false;
      submit.textContent = 'Create event';
    }
  });
}

function renderCredentialSetupBody(user, isRefresh = false) {
  const passkeySupported = Boolean(window.PublicKeyCredential);
  const name = user?.name || user?.email || 'there';
  return `<div class="modal-card credential-setup-card">
    <div class="section-head padded">
      <h2>Make future sign-ins faster</h2>
    </div>
    <div class="padded">
      <p>Welcome, <strong>${esc(name)}</strong>. You're signed in.</p>
      <p class="muted">Email links work, but they can get eaten by message previews before you click them.
        Set up one (or both) of the options below so future sign-ins go straight through.</p>

      <div class="credential-setup-options">
        ${passkeySupported ? `
        <div class="credential-setup-option">
          <h3><span class="passkey-icon">🔑</span>Passkey</h3>
          <p class="muted small">Use Face ID, Touch ID, Windows Hello, or your password manager. Fastest option on a phone or laptop you trust.</p>
          <button class="primary block" data-action="add-passkey" type="button">Add a passkey for this device</button>
        </div>` : `
        <div class="credential-setup-option muted">
          <h3>Passkey</h3>
          <p class="small">Your browser does not support passkeys. Set a password instead.</p>
        </div>`}

        <div class="credential-setup-option">
          <h3><i class="fa-solid fa-lock" aria-hidden="true"></i> Password</h3>
          <p class="muted small">Classic email + password. Works everywhere.</p>
          <form class="stack" data-form="password">
            <label>New password <input type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters" minlength="8"></label>
            <label>Confirm <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Same password again"></label>
            <button type="submit">Set password</button>
            <p class="error-text" data-pw-error></p>
          </form>
        </div>
      </div>

      <div class="credential-setup-footer">
        <label class="checkbox-row">
          <input type="checkbox" data-hide-future>
          <span>Don't show this again — I'm fine using email links</span>
        </label>
        <button class="secondary" data-action="skip" type="button">Skip for now</button>
      </div>
    </div>
  </div>`;
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
      this.maybeShowCredentialSetup();
    } catch {
      location.href = appUrl('login.html');
    }
  }

  /**
   * Show the credential-setup modal when the user has no password AND no
   * passkey AND has not opted out via hide_credential_setup_prompt.
   */
  maybeShowCredentialSetup() {
    const u = this.user || {};
    if (u.hide_credential_setup_prompt) return;
    if (u.has_password || u.has_passkey) return;

    openCredentialSetupModal(this.user, (updatedUser) => {
      // Modal calls back with the latest user state after setup or skip.
      this.user = updatedUser;
      const pill = $('[data-user-pill]', this);
      if (pill) pill.textContent = updatedUser.name || updatedUser.email || 'Account';
    });
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
        <a data-nav="admin" href="#admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i>Admin</a>
        <a data-nav="help" href="#help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i>Help</a>
      </nav>
      <div class="side-card"><span class="bolt"></span><strong>Good shows.<br><span>No surprises.</span></strong></div>
      <button class="venue-switch" type="button"><i class="fa-solid fa-building" aria-hidden="true"></i>Mabuhay Gardens</button>
      <p class="copyright">&copy; 2026 Panic Backstage</p>
    </aside>
    <header class="topbar">
      <a class="mobile-brand" href="#dashboard"><span class="brand-mark"></span><span>Panic Backstage</span></a>
      <label class="search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-search placeholder="Search events..." aria-label="Search events"></label>
      <button class="topbar-create" data-action="new-event" type="button" title="Create event" aria-label="Create event"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>New event</span></button>
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
      <a data-nav="admin" href="#admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i>Admin</a>
      <a data-nav="help" href="#help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i>Help</a>
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
    $('[data-action="new-event"]', this).addEventListener('click', () => openEventQuickCreate());
  }

  applyCapabilities() {
    if (!this.capabilities?.manage_templates) {
      $$('[data-nav="templates"]', this).forEach((link) => link.remove());
    }
    if (!this.capabilities?.create_events) {
      $$('[data-action="new-event"]', this).forEach((btn) => btn.remove());
    }
    if (!this.capabilities?.manage_users && !this.capabilities?.manage_staff_roster && !this.capabilities?.manage_templates) {
      $$('[data-nav="admin"]', this).forEach((link) => link.remove());
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
    if (route === 'admin' || route.startsWith('admin-') || route.startsWith('admin/')) {
      const tab = route === 'admin' ? '' : route.replace(/^admin[-/]/, '');
      return this.mount(outlet, 'pb-admin-page', { initialTab: tab });
    }
    if (route === 'help' || route.startsWith('help-') || route.startsWith('help/')) {
      const anchor = route === 'help' ? '' : route.replace(/^help[-/]/, '');
      return this.mount(outlet, 'pb-help-page', { anchor });
    }
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
      this.canCreate = Boolean(data?.capabilities?.create_events);
      this.render(data.events || [], start);
    } catch (error) {
      this.showError(error);
    }
  }

  render(events, start) {
    const days = Array.from({ length: 42 }, (_, index) => addDays(start, index));
    const createable = this.canCreate ? ' calendar-clickable' : '';
    this.innerHTML = `<section class="calendar-page">
      <div class="page-head"><div><h1>Calendar</h1><p class="subtle">Dynamic booking window for Mabuhay Gardens.${this.canCreate ? ' <span class="muted small">Click any day to create.</span>' : ''}</p></div>${this.canCreate ? '<button class="button" data-action="quick-new" type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> New event</button>' : ''}</div>
      <article class="panel calendar-shell">
        <div class="calendar-toolbar">
          <div class="calendar-controls"><button class="secondary small" data-prev>&lt;</button><button class="secondary small" data-next>&gt;</button><button class="secondary small" data-today>Today</button></div>
          <h2>${esc(this.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))}</h2>
          <div class="calendar-actions"><a class="button secondary small" href="#pipeline">Pipeline</a></div>
        </div>
        <div class="calendar-grid">
          ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<div class="weekday">${day}</div>`).join('')}
          ${days.map((date) => {
            const iso = isoDate(date);
            const dayEvents = events.filter((event) => event.date === iso);
            const clickAttr = this.canCreate ? ` data-create-date="${esc(iso)}" role="button" tabindex="0"` : '';
            return `<div class="calendar-day${createable}"${clickAttr}><span class="day-num">${date.getDate()}</span>${dayEvents.length ? dayEvents.map((event) => `<a class="mini-event" href="#event-${esc(event.id)}"><span class="status-dot ${statusTone(event.status)}"></span>${esc(event.title)}<br>${badge(event.status)}</a>`).join('') : `<div class="program-night">${this.canCreate ? '+ Available' : 'Available'}</div>`}</div>`;
          }).join('')}
        </div>
      </article>
    </section>`;
    $('[data-prev]', this).addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1); this.load(); });
    $('[data-next]', this).addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1); this.load(); });
    $('[data-today]', this).addEventListener('click', () => { this.month = new Date(); this.load(); });
    $('[data-action="quick-new"]', this)?.addEventListener('click', () => openEventQuickCreate());

    if (this.canCreate) {
      // Day-cell click → open the quick-create modal with that date pre-filled.
      // Mini-event links inside the cell still navigate normally (the listener
      // ignores clicks that originated on an <a>, button, or other link).
      $$('[data-create-date]', this).forEach((cell) => {
        const open = () => openEventQuickCreate({ date: cell.dataset.createDate });
        cell.addEventListener('click', (event) => {
          if (event.target.closest('a, button')) return;
          open();
        });
        cell.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
        });
      });
    }
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
        return `<article class="pipe-col"><h3>${esc(statusLabel(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => {
          const editable = Boolean(event.capabilities?.edit_event);
          return `<article class="pipe-card"><strong>${esc(event.title)}</strong><span>${esc(shortDate(eventDate(event)))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small>${editable ? `<form data-event="${esc(event.id)}" class="inline-status">${select('status', statuses, event.status, statusLabel)}<button class="small">Move</button><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></form>` : `<div class="inline-status"><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></div>`}</article>`;
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
    const tabs = ['overview', 'details', 'tasks', 'lineup', 'schedule', 'staffing', 'guest-list', 'open-items', 'assets', 'activity'];
    if (can(data, 'manage_invites')) tabs.splice(8, 0, 'invites');
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
      <article class="panel"><div class="section-head padded"><h2>Readiness ${helpLink('overview', 'Overview &amp; Readiness')}</h2></div><div class="health-row">${data.readiness.map((item) => `<div class="health-item">${item.ok ? '<span class="check">OK</span>' : '<span class="warn-mark">!</span>'}<span><strong>${esc(item.label)}</strong><br>${esc(item.state)}</span></div>`).join('')}</div></article>
      <article class="panel"><div class="section-head padded"><h2>Internal Notes</h2></div><div class="notes">${esc(event.description_internal || 'No internal notes yet.')}</div></article>
    </section>
    <pb-event-details-form id="details"></pb-event-details-form>
    <pb-task-list id="tasks"></pb-task-list>
    <pb-lineup-editor id="lineup"></pb-lineup-editor>
    <pb-run-sheet id="schedule"></pb-run-sheet>
    <pb-staffing-manager id="staffing"></pb-staffing-manager>
    <pb-guest-list-manager id="guest-list"></pb-guest-list-manager>
    <pb-open-items id="open-items"></pb-open-items>
    <pb-asset-manager id="assets"></pb-asset-manager>
    ${can(data, 'manage_invites') ? '<pb-invite-manager id="invites"></pb-invite-manager>' : ''}
    ${can(data, 'view_settlement') ? '<pb-settlement-form id="settlement"></pb-settlement-form>' : ''}
    <section id="activity" class="panel"><div class="section-head padded"><h2>Activity ${helpLink('activity', 'Activity Log')}</h2></div><ul class="timeline">${data.activity.map((entry) => `<li><strong>${esc(entry.action)}</strong> by ${esc(entry.user_name || 'system')} <span class="muted">${esc(entry.created_at)}</span></li>`).join('')}</ul></section>`;
    $('pb-event-details-form', this).data = data;
    $('pb-task-list', this).data = data;
    $('pb-lineup-editor', this).data = data;
    $('pb-run-sheet', this).data = data;
    $('pb-staffing-manager', this).data = data;
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Event Details ${helpLink('details', 'Event Details')}</h2></div><form class="grid-form padded">
      <label>Title <input name="title" required value="${esc(event.title)}"${disabled}></label>
      <label>Date <input type="date" name="date" required value="${esc(event.date)}"${disabled}></label>
      <label>Venue <select name="venue_id"${disabled}>${data.venues.map((venue) => option(venue.id, event.venue_id, venue.name)).join('')}</select></label>
      <label>Type ${select('event_type', ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'], event.event_type).replace('<select ', `<select${disabled} `)}</label>
      <label>Status ${select('status', statuses, event.status, statusLabel).replace('<select ', `<select${disabled} `)}</label>
      <label>Owner ${ownerSelect(data.users, event.owner_user_id).replace('<select ', `<select${disabled} `)}</label>
      <label>Doors <input type="time" name="doors_time" value="${esc(event.doors_time || '')}"${disabled}></label>
      <label>Show <input type="time" name="show_time" value="${esc(event.show_time || '')}"${disabled}></label>
      <label>End <input type="time" name="end_time" value="${esc(event.end_time || '')}"${disabled}></label>
      <label>Age <input name="age_restriction" value="${esc(event.age_restriction || '')}"${disabled}></label>
      <label>Ticket price <input type="number" step="0.01" name="ticket_price" value="${esc(event.ticket_price || 0)}"${disabled}></label>
      <label>Paid deposit <input type="number" step="0.01" min="0" name="deposit_amount" value="${esc(event.deposit_amount ?? '')}" placeholder="0.00"${disabled}></label>
      <label>Potential revenue <input type="number" step="0.01" min="0" name="potential_revenue" value="${esc(event.potential_revenue ?? '')}" placeholder="0.00"${disabled}></label>
      <label>Capacity <input type="number" name="capacity" value="${esc(event.capacity || '')}"${disabled}></label>
      <label>Ticket system <input name="ticket_system" value="${esc(event.ticket_system || '')}" placeholder="TIXR / Eventbrite / Door"${disabled}></label>
      <label class="wide">Ticket URL <input type="url" name="ticket_url" value="${esc(event.ticket_url || '')}"${disabled}></label>
      <label class="wide">Contract link <input name="contract_url" value="${esc(event.contract_url || '')}" placeholder="URL or note (e.g. 'Verbal contract')"${disabled}></label>
      <label class="check-label"><input type="checkbox" name="walkthrough_done" value="1" ${Number(event.walkthrough_done) ? 'checked' : ''}${disabled}> Walk-through happened</label>
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
      body.walkthrough_done  = submitEvent.target.walkthrough_done.checked ? 1 : 0;
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Tasks ${helpLink('tasks', 'Tasks')}</h2></div>${tasks.map((task) => `<form data-api="/events/${data.event.id}/tasks/${task.id}" data-method="PATCH" class="row-form"><label>Task<input name="title" value="${esc(task.title)}"${disabled}></label><label>Status${select('status', ['todo','in_progress','blocked','done','canceled'], task.status).replace('<select ', `<select${disabled} `)}</label><label>Assigned${userSelect(data.users, task.assigned_user_id).replace('<select ', `<select${disabled} `)}</label><label>Due<input type="date" name="due_date" value="${esc(task.due_date || '')}"${disabled}></label><label>Priority${select('priority', ['low','normal','high','urgent'], task.priority).replace('<select ', `<select${disabled} `)}</label><label>Details<input name="description" value="${esc(task.description || '')}"${disabled}></label>${editable ? `<button>Save</button><button type="button" class="secondary" data-complete="${esc(task.id)}">Done</button>` : ''}</form>`).join('') || emptyState('No tasks for this event.')}
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Lineup ${helpLink('lineup', 'Lineup &amp; Bands')}</h2></div>${lineup.map((item) => `<form data-api="/events/${data.event.id}/lineup/${item.id}" data-method="PATCH" class="row-form"><input name="billing_order" type="number" value="${esc(item.billing_order)}"${disabled}><input name="display_name" value="${esc(item.display_name)}"${disabled}><input name="set_time" type="time" value="${esc(item.set_time || '')}"${disabled}><input name="set_length_minutes" type="number" value="${esc(item.set_length_minutes || '')}"${disabled}>${select('status', ['invited','tentative','confirmed','canceled'], item.status).replace('<select ', `<select${disabled} `)}<input name="payout_terms" value="${esc(item.payout_terms || '')}"${disabled}><input name="notes" value="${esc(item.notes || '')}"${disabled}>${editable ? '<button>Save</button>' : ''}</form>`).join('')}
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Run Sheet ${helpLink('schedule', 'Schedule &amp; Run Sheet')}</h2></div>${schedule.map((item) => `<form data-api="/events/${data.event.id}/schedule/${item.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(item.title)}"${disabled}>${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], item.item_type).replace('<select ', `<select${disabled} `)}<input type="time" name="start_time" value="${esc(item.start_time || '')}"${disabled}><input type="time" name="end_time" value="${esc(item.end_time || '')}"${disabled}><input name="notes" value="${esc(item.notes || '')}"${disabled}>${editable ? '<button>Save</button>' : ''}</form>`).join('')}
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

class StaffingManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const shifts = data.staffing || [];
    const roster = data.staffRoster || [];
    const roles  = data.staffRoles || ['manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other'];
    const statuses = data.staffingStatuses || ['scheduled','confirmed','declined','no_show','completed','canceled'];
    const editable = can(data, 'manage_staffing');
    const disabled = editable ? '' : ' disabled';

    const rosterOptions = (selectedId) => `<option value="">— TBD —</option>${roster.map((s) => `<option value="${esc(s.id)}" data-default-role="${esc(s.default_role)}" data-default-rate="${esc(s.hourly_rate || '')}" ${Number(s.id) === Number(selectedId || 0) ? 'selected' : ''}>${esc(s.name)} (${esc(titleCase(s.default_role))})</option>`).join('')}`;

    // Group shifts by role for a tidy night-of-show layout.
    const grouped = shifts.reduce((map, shift) => {
      const key = shift.role || 'other';
      (map[key] = map[key] || []).push(shift);
      return map;
    }, {});
    const roleOrder = ['manager','sound','lighting','security','door','bartender','barback','stagehand','runner','cleaner','other'];

    const totalShifts = shifts.length;
    const confirmed = shifts.filter((s) => s.status === 'confirmed').length;
    const tbd = shifts.filter((s) => !s.staff_member_id).length;

    const groupSections = roleOrder
      .filter((role) => grouped[role])
      .map((role) => {
        const rows = grouped[role].map((shift) => `<form data-shift="${esc(shift.id)}" class="row-form staffing-row">
          <label>Staff <select name="staff_member_id"${disabled}>${rosterOptions(shift.staff_member_id)}</select></label>
          <label>Role ${select('role', roles, shift.role).replace('<select ', `<select${disabled} `)}</label>
          <label>Call <input type="time" name="call_time" value="${esc(shift.call_time || '')}"${disabled}></label>
          <label>End <input type="time" name="end_time" value="${esc(shift.end_time || '')}"${disabled}></label>
          <label>Rate <input type="number" step="0.01" name="hourly_rate" value="${esc(shift.hourly_rate || '')}" placeholder="$/hr"${disabled}></label>
          <label>Status ${select('status', statuses, shift.status).replace('<select ', `<select${disabled} `)}</label>
          <label>Notes <input name="notes" value="${esc(shift.notes || '')}"${disabled}></label>
          ${editable ? `<button>Save</button><button type="button" class="small danger" data-delete="${esc(shift.id)}">Remove</button>` : ''}
          ${shift.staff_phone || shift.staff_email ? `<small class="staffing-contact muted">${esc(shift.staff_phone || '')}${shift.staff_phone && shift.staff_email ? ' &middot; ' : ''}${esc(shift.staff_email || '')}</small>` : ''}
        </form>`).join('');
        return `<div class="staffing-section">
          <h3 class="guest-section-head">${esc(titleCase(role))} <span class="muted">${grouped[role].length} shift${grouped[role].length === 1 ? '' : 's'}</span></h3>
          ${rows}
        </div>`;
      }).join('');

    const rosterHint = roster.length
      ? ''
      : (editable
        ? '<p class="muted padded">No active staff in the roster yet. Open <a href="#admin-staff">Admin &rarr; Staff</a> to add bartenders, security, sound, etc.</p>'
        : '');

    const addForm = editable ? `<form data-form="add" class="row-form staffing-add">
      <label>Staff <select name="staff_member_id">${rosterOptions(null)}</select></label>
      <label>Role ${select('role', roles, 'security')}</label>
      <label>Call <input type="time" name="call_time"></label>
      <label>End <input type="time" name="end_time"></label>
      <label>Rate <input type="number" step="0.01" name="hourly_rate" placeholder="$/hr"></label>
      <label>Status ${select('status', statuses, 'scheduled')}</label>
      <label>Notes <input name="notes" placeholder="Door area, late call, etc."></label>
      <button>Add shift</button>
    </form>` : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Staffing ${helpLink('staffing', 'Staffing')}</h2>
        <div class="staffing-totals muted">${totalShifts} shift${totalShifts === 1 ? '' : 's'} &middot; ${confirmed} confirmed${tbd ? ` &middot; ${tbd} TBD` : ''}</div>
      </div>
      <div class="staffing-body">
        ${rosterHint}
        ${shifts.length ? groupSections : emptyState('No shifts assigned yet. Add bartenders, security, sound, door staff, etc. below.')}
        ${addForm}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    const eventId = this.eventData.event.id;
    const buildBody = (form) => {
      const body = formData(form);
      if (body.staff_member_id === '') body.staff_member_id = null;
      if (body.hourly_rate === '')     body.hourly_rate = null;
      return body;
    };

    // When picking a staff member, prefill role + rate if those fields are empty.
    $$('select[name="staff_member_id"]', this).forEach((select) => select.addEventListener('change', () => {
      const opt = select.selectedOptions[0];
      if (!opt || !opt.value) return;
      const form = select.closest('form');
      if (!form) return;
      const defRole = opt.dataset.defaultRole;
      const defRate = opt.dataset.defaultRate;
      if (defRole && form.elements.role && !form.elements.role.dataset.touched) {
        form.elements.role.value = defRole;
      }
      if (defRate && form.elements.hourly_rate && !form.elements.hourly_rate.value) {
        form.elements.hourly_rate.value = defRate;
      }
    }));
    $$('select[name="role"], input[name="hourly_rate"]', this).forEach((el) => el.addEventListener('input', () => { el.dataset.touched = '1'; }));

    $$('form[data-shift]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${eventId}/staffing/${form.dataset.shift}`, { method: 'PATCH', body: JSON.stringify(buildBody(form)) });
        publish('event.saved', { id: eventId });
        publish('toast.show', { message: 'Shift saved.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      if (!confirm('Remove this shift?')) return;
      try {
        await api(`/events/${eventId}/staffing/${button.dataset.delete}`, { method: 'DELETE' });
        publish('event.saved', { id: eventId });
        publish('toast.show', { message: 'Shift removed.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $('[data-form="add"]', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${eventId}/staffing`, { method: 'POST', body: JSON.stringify(buildBody(event.target)) });
        publish('event.saved', { id: eventId });
        publish('toast.show', { message: 'Shift added.' });
        event.target.reset();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}

class OpenItems extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const items = data.blockers || [];
    const editable = can(data, 'manage_open_items');
    const disabled = editable ? '' : ' disabled';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Open Items ${helpLink('open-items', 'Open Items')}</h2></div>${items.map((item) => `<form data-api="/events/${data.event.id}/open-items/${item.id}" data-method="PATCH" class="row-form"><label>Item<input name="title" value="${esc(item.title)}"${disabled}></label><label>Status${select('status', ['open','waiting','resolved','canceled'], item.status).replace('<select ', `<select${disabled} `)}</label><label>Due<input type="date" name="due_date" value="${esc(item.due_date || '')}"${disabled}></label><label>Details<input name="description" value="${esc(item.description || '')}"${disabled}></label><input type="hidden" name="owner_user_id" value="${esc(item.owner_user_id || '')}">${editable ? `<button>Save</button><button type="button" class="secondary" data-resolve="${esc(item.id)}">Mark Complete</button>` : ''}</form>`).join('') || emptyState('No open items for this event.')}
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
        <h2>Door / Guest List ${helpLink('guest-list', 'Guest List')}</h2>
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Assets ${helpLink('assets', 'Assets &amp; Flyers')}</h2></div><div class="asset-grid">${assets.map((asset) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp|svg)$/i.test(asset.filename) ? `<img src="${esc(assetUrl(asset.file_path))}" alt="">` : '<span class="asset-thumb">PDF</span>'}<strong>${esc(asset.title)}</strong><span>${esc(titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span><div class="inline-actions"><a class="button small secondary" href="${esc(assetUrl(asset.file_path))}" download>Download</a>${canManage ? `<button class="small" data-approve="${esc(asset.id)}">Approve</button><button class="small secondary" data-reject="${esc(asset.id)}">Reject</button><button class="small danger" data-delete="${esc(asset.id)}">Delete</button>` : ''}</div></article>`).join('') || emptyState('No assets uploaded yet.')}</div>
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
    const eventId = data.event.id;
    const roles = ['event_owner','promoter','band','artist','designer','staff','viewer'];
    const invites = data.invites || [];

    const rowsHtml = invites.length ? invites.map((invite) => {
      const url = appUrl(`invite.html?token=${invite.token}`);
      const accepted = Boolean(invite.used_at);
      const meta = accepted ? 'Accepted' : `Expires ${esc(invite.expires_at)}`;
      const emailBtn = accepted
        ? ''
        : `<button class="secondary small" data-email="${esc(invite.id)}">Email invite</button>`;
      return `<article class="invite-row">
        <span><strong>${esc(invite.email)}</strong><br><small>${esc(titleCase(invite.role))} - ${meta}</small></span>
        <input readonly value="${esc(url)}">
        <button class="secondary small" data-copy="${esc(url)}">Copy link</button>
        ${emailBtn}
      </article>`;
    }).join('') : emptyState('No invites have been created for this event.');

    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Invites ${helpLink('invites', 'Invites &amp; Collaborators')}</h2></div>
      <div class="invite-list">${rowsHtml}</div>
      <form class="row-form invite-add">
        <label>Email <input type="email" name="email" required placeholder="promoter@example.com"></label>
        <label>Role ${select('role', roles, 'viewer')}</label>
        <label class="check-label"><input type="checkbox" name="send_email" value="1" checked> Send invitation email</label>
        <button>Create invite</button>
      </form>
    </section>`;

    $('form', this).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      // formData() omits unchecked checkboxes entirely, so coerce to a clean boolean.
      body.send_email = event.target.send_email.checked;
      try {
        const result = await api(`/events/${eventId}/invites`, { method: 'POST', body: JSON.stringify(body) });
        publish('event.saved', { id: eventId });
        publish('toast.show', {
          message: result.emailed
            ? `Invite emailed to ${body.email}.`
            : `Invite link created: ${appUrl(result.url)}`,
        });
        event.target.reset();
        event.target.send_email.checked = true;
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
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

    $$('[data-email]', this).forEach((button) => button.addEventListener('click', async () => {
      const inviteId = button.dataset.email;
      button.disabled = true;
      const original = button.textContent;
      button.textContent = 'Sending...';
      try {
        await api(`/events/${eventId}/invites/${inviteId}`, { method: 'POST', body: '{}' });
        publish('toast.show', { message: 'Invite email sent.' });
        button.textContent = 'Sent';
        setTimeout(() => { button.textContent = original; button.disabled = false; }, 2000);
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
        button.textContent = original;
        button.disabled = false;
      }
    }));
  }
}

class SettlementForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const settlement = data.settlement || {};
    const event = data.event || {};
    const fields = ['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'];
    const docUrl = event.settlement_doc_url || '';
    const docLink = docUrl && /^https?:/i.test(docUrl)
      ? `<a class="button small secondary" href="${esc(docUrl)}" target="_blank" rel="noopener noreferrer">Open settlement doc &nearr;</a>`
      : '';
    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Settlement ${helpLink('settlement', 'Settlement')}</h2><div class="inline-actions">${docLink}<button class="secondary small" type="button" data-calc>Calculate venue net</button></div></div>
      <form class="row-form" data-form="doc"><label class="wide">Settlement document <input name="settlement_doc_url" value="${esc(docUrl)}" placeholder="URL or note pointing to the night-of settlement sheet"></label><button class="small">Save link</button></form>
      <form class="row-form" data-form="settlement">${fields.map((field) => `<label>${esc(titleCase(field))}<input name="${esc(field)}" type="number" step="0.01" value="${esc(settlement[field] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(settlement.notes || '')}</textarea></label><button>Save settlement</button></form>
    </section>`;
    const form = $('form[data-form="settlement"]', this);
    const calculate = () => {
      const values = formData(form);
      const venueNet = Number(values.gross_ticket_sales || 0) + Number(values.bar_sales || 0) - Number(values.expenses || 0) - Number(values.band_payouts || 0) - Number(values.promoter_payout || 0);
      form.elements.venue_net.value = venueNet.toFixed(2);
    };
    $('[data-calc]', this).addEventListener('click', calculate);
    ['gross_ticket_sales','bar_sales','expenses','band_payouts','promoter_payout'].forEach((name) => form.elements[name].addEventListener('input', calculate));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventData.event.id}/settlement`, { method: 'POST', body: JSON.stringify(formData(e.target)) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Settlement saved.' });
    });
    $('form[data-form="doc"]', this).addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventData.event.id}`, { method: 'PATCH', body: JSON.stringify({ settlement_doc_url: formData(e.target).settlement_doc_url }) });
      publish('event.saved', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Settlement doc link saved.' });
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
      const [data, me] = await Promise.all([
        api('/auth/passkeys', { method: 'POST', body: '{}' }),
        api('/me'),
      ]);
      this.passkeys           = data.passkeys || [];
      this.hasPassword        = Boolean(data.has_password);
      this.hideCredentialNudge = Boolean(me?.user?.hide_credential_setup_prompt);
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

      <div class="account-section">
        <h2>Sign-in nudges</h2>
        <label class="checkbox-row">
          <input type="checkbox" data-pref-nudge ${this.hideCredentialNudge ? '' : 'checked'}>
          <span>Remind me to set up a passkey or password when I don't have one</span>
        </label>
        <p class="muted small">When this is on and your account has neither a passkey nor a password, a small modal appears after each sign-in to help you set one up. Turn it off if you prefer email-link sign-ins.</p>
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
    $('[data-pref-nudge]', this)?.addEventListener('change', (e) => this.setNudgePref(e.target.checked));
  }

  async setNudgePref(remind) {
    // UI shows "remind me" — DB stores the inverse (hide_credential_setup_prompt).
    const hide = !remind;
    try {
      await api('/auth/preferences', {
        method: 'POST',
        body: JSON.stringify({ hide_credential_setup_prompt: hide }),
      });
      this.hideCredentialNudge = hide;
      publish('toast.show', {
        message: remind ? 'Reminders enabled.' : 'Reminders off — you can re-enable from this page.',
        tone: 'info',
      });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not save preference', tone: 'error' });
      // Roll the checkbox back
      const cb = $('[data-pref-nudge]', this);
      if (cb) cb.checked = !remind;
    }
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

// ── Help page ────────────────────────────────────────────────────────────────
// Long-form documentation for the backstage app. Sections are anchored so the
// small "?" icons next to each event section can deep-link via #help-<slug>.

const HELP_SECTIONS = [
  {
    group: 'Getting Started',
    items: [
      { slug: 'welcome',      title: 'Welcome' },
      { slug: 'sign-in',      title: 'Signing in' },
      { slug: 'account',      title: 'Account &amp; passkeys' },
      { slug: 'roles',        title: 'Roles &amp; permissions' },
      { slug: 'onboarding',   title: 'Onboarding collaborators' },
    ],
  },
  {
    group: 'Working with the App',
    items: [
      { slug: 'navigation',   title: 'Main navigation' },
      { slug: 'dashboard',    title: 'Dashboard' },
      { slug: 'calendar',     title: 'Calendar' },
      { slug: 'pipeline',     title: 'Pipeline board' },
      { slug: 'events-list',  title: 'Events list &amp; search' },
      { slug: 'templates',    title: 'Templates' },
    ],
  },
  {
    group: 'Running an Event',
    items: [
      { slug: 'event-create', title: 'Creating an event' },
      { slug: 'overview',     title: 'Overview &amp; readiness' },
      { slug: 'details',      title: 'Event details' },
      { slug: 'tasks',        title: 'Tasks' },
      { slug: 'lineup',       title: 'Lineup &amp; bands' },
      { slug: 'schedule',     title: 'Schedule &amp; run sheet' },
      { slug: 'staffing',     title: 'Staffing' },
      { slug: 'open-items',   title: 'Open items' },
      { slug: 'guest-list',   title: 'Guest list &amp; door' },
      { slug: 'assets',       title: 'Assets &amp; flyers' },
      { slug: 'invites',      title: 'Invites &amp; collaborators' },
      { slug: 'settlement',   title: 'Settlement' },
      { slug: 'publish',      title: 'Publishing the public page' },
      { slug: 'print',        title: 'Printable packets' },
      { slug: 'activity',     title: 'Activity log' },
    ],
  },
  {
    group: 'Administration',
    items: [
      { slug: 'admin',        title: 'Admin overview' },
      { slug: 'admin-users',  title: 'Managing login accounts' },
      { slug: 'admin-staff',  title: 'Staff roster' },
      { slug: 'admin-templates', title: 'Editing event templates' },
    ],
  },
  {
    group: 'Reference',
    items: [
      { slug: 'statuses',     title: 'Event status reference' },
      { slug: 'workflow',     title: 'End-to-end show workflow' },
      { slug: 'faq',          title: 'FAQ' },
      { slug: 'troubleshooting', title: 'Troubleshooting' },
    ],
  },
];

const HELP_CONTENT = {
  welcome: `
    <h2>Welcome to Panic Backstage</h2>
    <p>Panic Backstage helps a venue run a show from the first hold through final settlement. It keeps the lineup, run sheet, flyers, ticketing notes, open items, door list, and money in one place so a small team can hand off cleanly between bookers, promoters, designers, and night-of-show staff.</p>
    <p>If this is your first visit, start with <a href="#help-sign-in">Signing in</a>, then <a href="#help-navigation">Main navigation</a>, then <a href="#help-event-create">Creating an event</a>. Section "?" icons inside each event open the relevant help page in a new tab so you do not lose your place.</p>
  `,

  'sign-in': `
    <h2 id="help-sign-in-h">Signing in</h2>
    <p>The login page is <strong>email-first</strong>: enter your email and Backstage shows only the sign-in methods your account actually has. No more guessing which option to click.</p>

    <h3>Step 1 — Enter your email</h3>
    <p>Type your address and click <em>Continue</em>. Backstage looks up what's on file and takes you to step 2 with only the options that apply to you.</p>
    <p>If your browser has a passkey saved for any Backstage account, it may offer to fill the email field for you — accept the suggestion and you'll skip straight past steps 1 and 2.</p>
    <p>On the same screen you can also click <em>Sign in with passkey</em> as a shortcut. This works without typing an email when the passkey is already registered to this device.</p>

    <h3>Step 2 — Pick a method</h3>
    <p>The page now shows your name and the methods available on your account:</p>
    <ul>
      <li><strong>Passkey.</strong> Click <em>Sign in with passkey</em> and approve with Face ID, Touch ID, Windows Hello, or a hardware key.</li>
      <li><strong>Password.</strong> Type your password and submit.</li>
      <li><strong>Email me a login link.</strong> Always offered as a fallback. We send a one-time link to your address that's valid for <strong>24 hours</strong>. For brand-new accounts (no passkey, no password) this is the primary path and the button is highlighted.</li>
    </ul>
    <p>If you landed on the wrong account, click <em>change</em> next to your name to go back to step 1.</p>

    <h3>Using a magic-link email</h3>
    <p>When you click the link in the email, Backstage shows a <em>Continue to your account</em> screen before signing you in. This is intentional: message previewers in iMessage, SMS, Slack, and corporate scanners often "click" links in the background, and we don't want them to burn your one-time token before you ever see it. The token is only consumed when you actually click the button.</p>
    <p>If the link is invalid or already used, you'll see an error with a quick path to request a fresh one.</p>

    <h3>After a first sign-in</h3>
    <p>If you signed in via an email link and your account has no passkey or password yet, Backstage offers a one-time <em>Make future sign-ins faster</em> modal:</p>
    <ul>
      <li><em>Add a passkey for this device</em> — fastest. Stored in your OS / password manager and unlocked with biometrics.</li>
      <li><em>Set a password</em> — works everywhere. At least 8 characters.</li>
      <li><em>Skip for now</em> — dismiss once. You'll see the prompt again next time.</li>
      <li><em>Don't show this again</em> — opt out permanently. You can still set up either method later from <a href="#help-account">Account</a>.</li>
    </ul>

    <h3>Sessions</h3>
    <p>Sessions persist via access + refresh tokens stored in your browser. If a session expires mid-use, the app silently refreshes; if the refresh fails you're bounced back to the login page with your email pre-filled.</p>

    <p class="muted small">Demo admin (when seeded): <code>admin@mabuhay.local</code> / <code>changeme</code>.</p>
  `,

  account: `
    <h2>Account &amp; passkeys</h2>
    <p>Open <em>Account</em> from the topbar to manage how you sign in. Anything you set up here is the same set of options the login page's <a href="#help-sign-in">email-first flow</a> will offer next time you (or anyone signing into your address) authenticates.</p>
    <h3>Passkeys</h3>
    <p>Click <em>+ Add passkey for this device</em> and approve the prompt with Face ID, Touch ID, Windows Hello, or a hardware key. The device name is stored along with the date added and last-used date so you can spot stale devices. Remove a passkey any time; the next sign-in on that device falls back to password or email link.</p>
    <p>Passkeys are scoped to the device that created them, but a passkey in a synced password manager (1Password, iCloud Keychain, Google Password Manager) will follow you across devices automatically.</p>
    <h3>Password</h3>
    <p>Set or change a password. New passwords must be at least 8 characters. If you already have a password, the current one is required before saving a new one.</p>
    <h3>The "Make future sign-ins faster" prompt</h3>
    <p>If you logged in via email link and have no credentials on file, Backstage shows a one-time setup modal right after sign-in (see <a href="#help-sign-in">Signing in</a>). If you ticked <em>Don't show this again</em> there and changed your mind, just come to this page and set up a passkey or password manually — the prompt won't reappear, but Account always works.</p>
    <p>You can mix and match all three methods on the same account. Most venues recommend a passkey on the daily-driver laptop plus a password as a fallback for new devices.</p>
  `,

  roles: `
    <h2>Roles &amp; permissions</h2>
    <p>Authorization is enforced server-side based on your global role plus per-event collaborator rows.</p>
    <h3>Global roles</h3>
    <ul>
      <li><strong>Venue admin.</strong> Full access to every event, template, asset, settlement, invite, and user. Can create events from templates and manage the venue.</li>
      <li><strong>Staff user.</strong> Sees only events they own or where they appear in <code>event_collaborators</code>.</li>
    </ul>
    <h3>Per-event collaborator roles</h3>
    <ul>
      <li><strong>Event owner.</strong> Full access to that event except global user/template administration.</li>
      <li><strong>Promoter.</strong> Read the event, edit lineup, tasks, schedule, and open items, view and copy the public page. <em>Settlement is hidden.</em></li>
      <li><strong>Band / Artist.</strong> Read the event, upload their own assets, see tasks assigned directly to them.</li>
      <li><strong>Designer.</strong> Read the event and upload/manage assets. Settlement is hidden.</li>
      <li><strong>Staff.</strong> Read the event and edit tasks, schedule, and open items.</li>
      <li><strong>Viewer.</strong> Read-only access.</li>
    </ul>
    <p>If a control is greyed out or missing, your role does not have permission for it. Ask the event owner or a venue admin to elevate your role if you need more.</p>
  `,

  onboarding: `
    <h2>Onboarding collaborators</h2>
    <p>Bring a promoter, designer, band, or staffer onto a single event with an invite link.</p>
    <ol>
      <li>Open the event and scroll to <a href="#help-invites">Invites</a>.</li>
      <li>Enter their email, pick the role, and click <em>Create invite link</em>.</li>
      <li>Copy the generated URL with the <em>Copy link</em> button and share it via your usual channel (email, Slack, SMS). Backstage does not send the email itself.</li>
      <li>The recipient opens the link, sets a name, and is signed in directly to the event workspace.</li>
    </ol>
    <p>Invites are scoped to a single event and role. Create a new invite for each additional event. Existing accounts can also accept a new invite to add a second event to their workspace.</p>
  `,

  navigation: `
    <h2>Main navigation</h2>
    <p>The left sidebar (or bottom bar on mobile) is the primary navigation.</p>
    <ul>
      <li><strong>Dashboard</strong> — the next-two-weeks operational view.</li>
      <li><strong>Calendar</strong> — month grid of confirmed and held dates.</li>
      <li><strong>Pipeline</strong> — Kanban board grouped by event status.</li>
      <li><strong>Events</strong> — searchable list of every event you can see.</li>
      <li><strong>Templates</strong> — venue admins only, used to spawn new events.</li>
      <li><strong>Help</strong> — this page.</li>
    </ul>
    <p>The topbar holds:</p>
    <ul>
      <li><strong>Search</strong> — type to filter the Events list by title.</li>
      <li><strong>Account</strong> — passkey and password management.</li>
      <li><strong>Logout</strong> — clears tokens and returns to the login page.</li>
    </ul>
  `,

  dashboard: `
    <h2>Dashboard</h2>
    <p>The dashboard summarises Mabuhay show operations for the next two weeks.</p>
    <ul>
      <li><strong>Next Show</strong> — top-of-fold card with doors and show times and current status.</li>
      <li><strong>Open Items / Empty / Needs Flyer / Unsettled</strong> — counters that link straight to the relevant work.</li>
      <li><strong>Next 14 Days</strong> — table of upcoming events with main issue and owner.</li>
      <li><strong>Needs Attention</strong> — events with primary blockers or unapproved flyers.</li>
    </ul>
    <p>Click any event row to jump into its workspace. The cards refresh whenever you save changes in any event.</p>
  `,

  calendar: `
    <h2>Calendar</h2>
    <p>The calendar shows a six-week window. Use the <code>&lt;</code> and <code>&gt;</code> buttons to move months, or <em>Today</em> to snap back. Dates without an event show an <em>Available</em> chip; dates with events show a colored status dot and the event title. Click any event to open it.</p>
    <p>The dashboard, pipeline, and calendar all read from the same <code>/api/events</code> data, so adding or moving a show updates all three.</p>
  `,

  pipeline: `
    <h2>Pipeline board</h2>
    <p>The pipeline groups events by status into columns. To advance an event, choose the new status in its card's inline dropdown and click <em>Move</em>. Open the card to jump into the full event workspace. The pipeline is the fastest way to move several events forward at once.</p>
    <p>See <a href="#help-statuses">Event status reference</a> for what each column means.</p>
  `,

  'events-list': `
    <h2>Events list &amp; search</h2>
    <p>The Events page shows every event you have access to. Use the topbar search to filter by title. Click any row to open the workspace. Admins see a <em>Create Event</em> button that links to <a href="#help-templates">Templates</a>.</p>
  `,

  templates: `
    <h2>Templates</h2>
    <p>Templates are pre-built event blueprints. Only venue admins see this page. Each template captures the venue, event type, default title, default tasks, default schedule blocks, and standard open items for a kind of show (for example a three-band local show or a swing dancing night).</p>
    <h3>Creating an event from a template</h3>
    <ol>
      <li>Open <em>Templates</em>.</li>
      <li>On the template card, pick a date and adjust doors/show/title.</li>
      <li>Click <em>Create event</em>. You are taken straight into the new event.</li>
    </ol>
    <p>The event is created with all of the template's seeded tasks, schedule items, and open items already in place, so you only have to fill in lineup-specific details.</p>
  `,

  'event-create': `
    <h2>Creating an event</h2>
    <p>Every show starts from a template. Open <a href="#help-templates">Templates</a>, pick a template that matches the kind of night you are programming, fill in date and doors/show times, and click <em>Create event</em>.</p>
    <p>From there, work top to bottom in the event workspace:</p>
    <ol>
      <li><a href="#help-details">Event details</a> — set venue, type, status, owner, ticket price, capacity, age restriction.</li>
      <li><a href="#help-lineup">Lineup</a> — add the bands or performers.</li>
      <li><a href="#help-schedule">Run sheet</a> — set load-in, soundcheck, set times, curfew.</li>
      <li><a href="#help-tasks">Tasks</a> — assign anything that has to be done before doors.</li>
      <li><a href="#help-assets">Assets</a> — collect and approve flyers.</li>
      <li><a href="#help-publish">Publish</a> — flip the public page on when the show is ready to announce.</li>
      <li><a href="#help-guest-list">Guest list</a> — close to show day, build the door list.</li>
      <li><a href="#help-settlement">Settlement</a> — after the show, reconcile the numbers.</li>
    </ol>
  `,

  overview: `
    <h2>Overview &amp; readiness</h2>
    <p>The top of every event workspace shows a flyer thumbnail, the event facts (date, doors, show, status, owner, public-page state), and two counters that link straight to the matching tabs:</p>
    <ul>
      <li><strong>Open Items</strong> count — blockers that are still <em>open</em> or <em>waiting</em>.</li>
      <li><strong>Tasks Left</strong> count — tasks not yet marked <em>done</em> or <em>canceled</em>.</li>
    </ul>
    <p>Below that is a <strong>Next Recommended Action</strong> banner suggesting the most important next step (sign the artist, approve the flyer, build the run sheet, etc.). It refreshes when you click <em>Refresh</em> or save something.</p>
    <p>The <strong>Readiness</strong> panel lists the gates we check before a show is "ready" (lineup confirmed, flyer approved, public page on, run sheet built, settlement filed, and so on) with a clear OK / not-OK mark. The <strong>Internal Notes</strong> panel is the place for anything you do not want on the public page — green-room arrangements, transport, dietary notes, comp commitments.</p>
  `,

  details: `
    <h2>Event details</h2>
    <p>The Event Details form holds the facts of the show. Edits save with the <em>Save details</em> button.</p>
    <ul>
      <li><strong>Title</strong> — the marquee name of the show. Used everywhere (dashboard, calendar, public page, print packets).</li>
      <li><strong>Date</strong> — show date.</li>
      <li><strong>Venue</strong> — choose from the venues your account can see.</li>
      <li><strong>Type</strong> — live music, karaoke, open mic, promoter night, DJ night, comedy, private event, or special event.</li>
      <li><strong>Status</strong> — see <a href="#help-statuses">Event status reference</a>.</li>
      <li><strong>Owner</strong> — the staff member responsible. Owners get implicit access to the event.</li>
      <li><strong>Doors / Show / End</strong> — set the public-facing times.</li>
      <li><strong>Age restriction</strong> — shown on the public page (e.g. 21+, All Ages).</li>
      <li><strong>Ticket price / Capacity / Ticket URL</strong> — used for ticketing handoff and public page.</li>
      <li><strong>Public description</strong> — copy that appears on the public event page.</li>
      <li><strong>Internal notes</strong> — only visible to staff and collaborators.</li>
      <li><strong>Public page visible</strong> — toggles the publish state from inside the form. The big <em>Publish</em> button at the top of the workspace does the same thing.</li>
    </ul>
  `,

  tasks: `
    <h2>Tasks</h2>
    <p>Tasks are anything a person has to do before the show. They appear on the dashboard's open-items metric and feed the "Next Recommended Action" hint.</p>
    <h3>Adding a task</h3>
    <p>Fill in the form at the bottom of the Tasks panel: a title (required), an assignee, a due date, a priority (low / normal / high / urgent), and details. Click <em>Add task</em>.</p>
    <h3>Updating a task</h3>
    <p>Each row is an inline form. Change any field and click <em>Save</em>, or use the <em>Done</em> shortcut to mark it complete in one click. Statuses are <em>todo</em>, <em>in_progress</em>, <em>blocked</em>, <em>done</em>, <em>canceled</em>.</p>
    <h3>Who sees what</h3>
    <p>Promoters and staff can edit all tasks. Bands and artists see tasks assigned directly to them. Viewers see tasks but cannot edit them.</p>
  `,

  lineup: `
    <h2>Lineup &amp; bands</h2>
    <p>The lineup captures who is playing the show.</p>
    <h3>Adding a band or artist</h3>
    <p>Use the add form at the bottom of the lineup panel:</p>
    <ul>
      <li><strong>Band / artist</strong> — internal record name. Bands you re-book are reused across events.</li>
      <li><strong>Display name</strong> — what appears on the public page and the flyer (e.g. "The Examples ft. Special Guest").</li>
      <li><strong>Billing order</strong> — 1 is headliner. Schedule defaults are sorted by this number.</li>
      <li><strong>Set time / Set length minutes</strong> — used to build the run sheet.</li>
      <li><strong>Status</strong> — <em>invited</em>, <em>tentative</em>, <em>confirmed</em>, <em>canceled</em>.</li>
      <li><strong>Payout terms</strong> — short text like "$200 guarantee", "70/30 after $400", or "door split". Surfaced in the print packet and settlement.</li>
      <li><strong>Notes</strong> — backline, hospitality, anything the booker needs to remember.</li>
    </ul>
    <h3>Editing</h3>
    <p>Edit any field inline and click <em>Save</em> on that row. Re-ordering is done by editing the billing order numbers.</p>
    <h3>Band assets</h3>
    <p>Press photos, logos, and band-supplied artwork live in <a href="#help-assets">Assets</a>. Bands with their own backstage account can upload assets directly without needing the booker to relay files.</p>
  `,

  schedule: `
    <h2>Schedule &amp; run sheet</h2>
    <p>The run sheet is the minute-by-minute night-of-show plan.</p>
    <h3>Item types</h3>
    <ul>
      <li><strong>load_in</strong> — when crew/bands arrive and gear comes in.</li>
      <li><strong>soundcheck</strong> — per-band soundcheck blocks.</li>
      <li><strong>doors</strong> — when the public is admitted. Should match the public doors time on <a href="#help-details">Event details</a>.</li>
      <li><strong>set</strong> — a performance set. Create one per band; the lineup's billing order suggests the order.</li>
      <li><strong>changeover</strong> — buffer between sets.</li>
      <li><strong>curfew</strong> — hard stop time.</li>
      <li><strong>staff_call</strong> — when each staff member should arrive.</li>
      <li><strong>other</strong> — anything else (vendor arrival, photographer arrival, VIP arrival).</li>
    </ul>
    <h3>Adding items</h3>
    <p>Use the add form with title, type, start, end, and notes. Save and the row joins the schedule. Edit times inline; save each row when you change it.</p>
    <h3>Printing</h3>
    <p>The run-of-show printout (see <a href="#help-print">Printable packets</a>) prints the schedule as a single-sheet timeline that staff and bands can keep on hand night of show.</p>
  `,

  staffing: `
    <h2>Staffing</h2>
    <p>The Staffing tab is where you schedule night-of-show personnel — security, bartenders, barbacks, door staff, sound, lighting, stagehands, runners, cleaners, manager-on-duty, and anyone else assigned a shift. It is separate from the <a href="#help-lineup">Lineup</a> (which is for performers) and from <a href="#help-invites">Invites</a> (which gives someone backstage app access).</p>
    <h3>Roles</h3>
    <p>The role dropdown offers a fixed list: <em>Manager, Security, Bartender, Barback, Door, Sound, Lighting, Stagehand, Runner, Cleaner, Other.</em> Use <em>Other</em> for anything unusual and put the specifics in the Notes field.</p>
    <h3>Adding a shift</h3>
    <p>Use the form at the bottom of the panel. Pick the staff member from the roster (or leave as TBD), set the role, call time, end time, hourly rate, status, and any notes. The roster is managed under <a href="#help-admin-staff">Admin &rarr; Staff</a>.</p>
    <p>When you pick a staff member from the dropdown, their default role and hourly rate prefill automatically — you can override either before saving.</p>
    <h3>Shift statuses</h3>
    <ul>
      <li><strong>scheduled</strong> — assigned but not confirmed.</li>
      <li><strong>confirmed</strong> — staff member has confirmed.</li>
      <li><strong>declined</strong> — staff member can't make it; reassign or leave as TBD.</li>
      <li><strong>no_show</strong> — recorded after the fact.</li>
      <li><strong>completed</strong> — shift finished as scheduled.</li>
      <li><strong>canceled</strong> — shift no longer needed.</li>
    </ul>
    <h3>Night-of-show</h3>
    <p>Shifts are grouped by role for a clean read at the door. Print the staffing schedule from the <em>Print</em> menu — it lists call times, role, staff name and phone, and shift status, alongside the run sheet's staff_call times for cross-reference.</p>
    <h3>TBD shifts</h3>
    <p>You can save a shift without picking a staff member — it appears as <em>TBD</em>. Useful when you know you need (say) two security at 7:30 PM but haven't picked who yet.</p>
  `,

  'open-items': `
    <h2>Open items</h2>
    <p>Open items are external blockers — things waiting on someone or some other system. Examples: "Waiting on ticket link from promoter", "Need signed contract from headliner", "Insurance certificate pending".</p>
    <h3>Statuses</h3>
    <ul>
      <li><strong>open</strong> — actively blocking.</li>
      <li><strong>waiting</strong> — assigned to someone, ticking down.</li>
      <li><strong>resolved</strong> — done.</li>
      <li><strong>canceled</strong> — no longer needed.</li>
    </ul>
    <p>Open items contribute to the dashboard's <em>Open Items</em> count and the readiness signal. Use <em>Mark Complete</em> on a row to resolve it in one click.</p>
    <p>Use <a href="#help-tasks">Tasks</a> for things <em>your team</em> needs to do, and open items for things you are waiting on someone else for. Both feed the same dashboard metric.</p>
  `,

  'guest-list': `
    <h2>Guest list &amp; door</h2>
    <p>The guest list is the door's source of truth — comps, will-call, VIP holds, press, and industry. It is grouped by list type and gives you a live check-in count.</p>
    <h3>List types</h3>
    <ul>
      <li><strong>VIP</strong> — venue or owner VIPs.</li>
      <li><strong>Press</strong> — reviewers, photographers.</li>
      <li><strong>Industry</strong> — promoters, agents, label reps.</li>
      <li><strong>Comp</strong> — free entries the venue is comping.</li>
      <li><strong>Guest</strong> — band and promoter guests (count against their guest allowance).</li>
      <li><strong>Will call</strong> — paid tickets to be picked up at door.</li>
    </ul>
    <h3>Adding a guest</h3>
    <p>Use the add form with name, party size (defaults to 1), list type, optional <em>guest of</em> (e.g. "Headliner"), and notes. Save.</p>
    <h3>Night of show</h3>
    <p>At the door, click the check-in toggle on each row as guests arrive. The header shows total entries, total seats, checked-in entries, and checked-in seats. The row turns muted when checked in so you can see at a glance who has and has not arrived.</p>
    <h3>Printing</h3>
    <p>Use the <em>Print</em> menu at the top of the event to print a door/guest list packet sorted by list type. See <a href="#help-print">Printable packets</a>.</p>
  `,

  assets: `
    <h2>Assets &amp; flyers</h2>
    <p>Assets are flyers, band photos, logos, social cards, and other files attached to the event.</p>
    <h3>Uploading</h3>
    <p>Use the form at the bottom of the Assets panel. Give the file a title, pick a type, choose a file (PNG, JPG, GIF, WEBP, or PDF), add notes, and click <em>Upload asset</em>. Uploads go to local disk under <code>storage/uploads/events/&lt;id&gt;</code>.</p>
    <h3>Asset types</h3>
    <ul>
      <li><strong>Flyer</strong> — the primary show flyer. The first approved flyer is shown on the public event page and on print packets.</li>
      <li><strong>Poster</strong> — print poster for the venue wall.</li>
      <li><strong>Band photo / Press photo</strong> — used for press kits and social.</li>
      <li><strong>Logo</strong> — band or sponsor mark.</li>
      <li><strong>Social square / Social story</strong> — sized for IG feed and IG/FB stories.</li>
      <li><strong>Other</strong> — anything else.</li>
    </ul>
    <h3>Approval flow</h3>
    <p>Each asset has an approval status: <em>pending</em>, <em>approved</em>, or <em>rejected</em>. Promoters and admins click <em>Approve</em> or <em>Reject</em>. The dashboard's "Needs Flyer" counter watches the count of <em>approved</em> flyers per event.</p>
    <h3>Bands uploading their own assets</h3>
    <p>Bands with a backstage account and a band/artist invite on this event can upload their own press photos and stage plot PDFs without round-tripping through the booker.</p>
  `,

  invites: `
    <h2>Invites &amp; collaborators</h2>
    <p>Invites add another person to a single event as a specific role (see <a href="#help-roles">Roles &amp; permissions</a>).</p>
    <h3>Creating an invite</h3>
    <ol>
      <li>Scroll to the Invites panel on the event.</li>
      <li>Enter the collaborator's email and pick the role.</li>
      <li>Leave <em>Send invitation email</em> checked to have Backstage email the link directly, or uncheck it to generate the link silently (useful when you want to share it via Slack, SMS, or a calendar invite).</li>
      <li>Click <em>Create invite</em>.</li>
      <li>Use <em>Copy link</em> to copy the URL, or — for pending invites — click <em>Email invite</em> later to (re-)send the link.</li>
    </ol>
    <h3>Accepting an invite</h3>
    <p>When the recipient opens the link they see an acceptance page with the event title and role. They enter their name and are signed straight into the event workspace. If they already have an account, the invite is attached to it.</p>
    <h3>Expiration</h3>
    <p>Invite links show their expiry date and last 14 days. Once used they switch to <em>Accepted</em> and the <em>Email invite</em> button disappears. Create a fresh invite if a link expires before it is used.</p>
    <h3>Email delivery</h3>
    <p>Backstage hands invitation emails to the server's <code>sendmail</code> (Exim) for delivery and writes a copy to <code>storage/mail/</code> for local inspection. Delivery problems are logged but never block the API response — if a message fails to send, your link is still valid and you can resend with the <em>Email invite</em> button.</p>
  `,

  settlement: `
    <h2>Settlement</h2>
    <p>Settlement is the night-of-show or next-day reconciliation. It is visible to venue admins and event owners and hidden from promoters, designers, bands, and viewers.</p>
    <h3>Fields</h3>
    <ul>
      <li><strong>Gross ticket sales</strong> — total ticket revenue (Stripe export or manual).</li>
      <li><strong>Tickets sold</strong> — paid tickets, excluding comps.</li>
      <li><strong>Bar sales</strong> — bar take.</li>
      <li><strong>Expenses</strong> — production, hospitality, security, etc.</li>
      <li><strong>Band payouts</strong> — total paid to performers (sum of all lineup payouts).</li>
      <li><strong>Promoter payout</strong> — paid to outside promoter if applicable.</li>
      <li><strong>Venue net</strong> — the venue's take. Click <em>Calculate venue net</em> to derive: <code>gross + bar − expenses − band − promoter</code>.</li>
      <li><strong>Notes</strong> — anything else (cash float, discrepancies, comp count).</li>
    </ul>
    <p>Save the form to record the settlement. Once filed, the event drops off the dashboard's <em>Unsettled</em> count.</p>
  `,

  publish: `
    <h2>Publishing the public page</h2>
    <p>Every event has a public-facing page at <code>/event.html?slug=&lt;slug&gt;</code> that shows the title, date, doors/show, age restriction, ticket link, public description, lineup, and the approved flyer.</p>
    <h3>Toggling publish</h3>
    <p>Click <em>Publish Public Page</em> at the top of the event workspace to make it live, or <em>Hide Public Page</em> to take it offline. The same toggle exists as a checkbox in <a href="#help-details">Event details</a>.</p>
    <h3>Previewing</h3>
    <p>Click <em>Public Page</em> in the event header to open the public page in a new tab. It is fetched anonymously from <code>/api/public/events/&lt;slug&gt;</code>; if the event is hidden the API returns an error.</p>
  `,

  print: `
    <h2>Printable packets</h2>
    <p>The <em>Print</em> menu at the top right of the event opens a self-contained print window with five layouts:</p>
    <ul>
      <li><strong>Band Lineup</strong> — billing order, set times, set lengths, payout terms.</li>
      <li><strong>Staffing Schedule</strong> — staff call times pulled from the run sheet.</li>
      <li><strong>Run of Show</strong> — full run sheet with timeline.</li>
      <li><strong>Door / Guest List</strong> — guest list grouped by list type with check-in columns.</li>
      <li><strong>Master Event Packet</strong> — every section combined into one printable packet for the production binder.</li>
    </ul>
    <p>Use Cmd/Ctrl+P or click the <em>Print</em> button inside the new window. Layouts are sized for US Letter with 0.5 inch margins.</p>
  `,

  activity: `
    <h2>Activity log</h2>
    <p>The Activity panel at the bottom of every event lists every meaningful change — who saved what and when. Use it for forensic questions ("when did the doors time change?") and as a hand-off log between bookers and night-of-show staff.</p>
  `,

  admin: `
    <h2>Admin overview</h2>
    <p>The Admin nav item is visible only to venue admins. It groups three management tools as tabs on a single page:</p>
    <ul>
      <li><a href="#help-admin-users">Users</a> — create, edit, and delete backstage login accounts; reset passwords; change roles.</li>
      <li><a href="#help-admin-staff">Staff</a> — keep the roster of bartenders, security, door, sound, etc. used in event staffing.</li>
      <li><a href="#help-admin-templates">Templates</a> — edit run-sheet and checklist templates used to create new events.</li>
    </ul>
    <p>Each tab has a stable deep link: <code>#admin-users</code>, <code>#admin-staff</code>, <code>#admin-templates</code>.</p>
  `,

  'admin-users': `
    <h2>Managing login accounts</h2>
    <p>Admin &rarr; Users lists every account that can log into backstage. The table shows name, email, role, authentication methods (password and registered passkeys), and how many events each user owns or collaborates on.</p>
    <h3>Creating a user</h3>
    <p>Use the <em>Create User</em> form. Required: name, email, role. Password is optional — if you leave it blank, the user can still sign in via passkey or by requesting an email login link from the login page.</p>
    <h3>Editing a user</h3>
    <p>Click <em>Edit</em> on any row. The dialog lets you change name, email, role, and reset the password. To leave the password unchanged, leave the password field blank. Existing passkeys are listed by count; users remove individual passkeys themselves from their <em>Account</em> page.</p>
    <h3>Roles</h3>
    <p>A user's global role determines what they can do across the whole app (admins see every event; others only see what they own or collaborate on). Per-event collaborator roles are managed from each event's <a href="#help-invites">Invites</a> panel. See <a href="#help-roles">Roles &amp; permissions</a> for the full breakdown.</p>
    <h3>Deleting a user</h3>
    <p>You cannot delete yourself. You cannot delete a user who currently owns events — reassign their events first (via each event's <em>Owner</em> field). Deleting a user removes their <code>event_collaborators</code> rows; their authored activity-log entries remain but show as orphaned.</p>
  `,

  'admin-staff': `
    <h2>Staff roster</h2>
    <p>The staff roster is the master list of people who work events — security, bartenders, barbacks, door, sound engineers, lighting, stagehands, runners, cleaners, and on-duty managers. It is intentionally separate from the Users table: most night-of-show staff don't need a backstage login.</p>
    <h3>Adding a staff member</h3>
    <p>Use the <em>Add Staff</em> form. Required: name and default role. Email, phone, hourly rate, and notes are optional. If the staff member also has a backstage login (e.g. a manager), pick their user account in the <em>Link to login</em> dropdown so the two records stay connected.</p>
    <h3>Default role and rate</h3>
    <p>The default role and hourly rate prefill into new shift forms when you pick the staff member, but you can override either per-shift. Useful when (for example) a bartender occasionally picks up a barback shift.</p>
    <h3>Active vs inactive</h3>
    <p>Toggle <em>Active</em> off when someone leaves or stops picking up shifts. Inactive staff stop appearing in the event Staffing dropdowns but stay in the roster so historical shifts continue to show their name.</p>
    <h3>Deleting a staff member</h3>
    <p>Deleting removes them from the roster permanently. Past shifts they were assigned to remain in the database — the shift's staff_member link is cleared and the shift shows as <em>TBD</em> on the historical record.</p>
  `,

  'admin-templates': `
    <h2>Editing event templates</h2>
    <p>Templates are pre-built event blueprints used by <a href="#help-templates">Templates</a> to spawn new events with pre-loaded tasks and schedule blocks. The Admin &rarr; Templates tab is where you create, edit, and delete them.</p>
    <h3>Anatomy of a template</h3>
    <ul>
      <li><strong>Name</strong> — what staff see when picking a template.</li>
      <li><strong>Type</strong> — the event type the template produces.</li>
      <li><strong>Venue</strong> — which venue this template is for.</li>
      <li><strong>Default title, ticket price, age, public description</strong> — values pre-filled into new events.</li>
      <li><strong>Checklist</strong> — one task per line. Each line becomes a Task on every new event created from this template.</li>
      <li><strong>Schedule</strong> — one line per item in the form <code>HH:MM | type | title</code>. The type must be one of <em>load_in, soundcheck, doors, set, changeover, curfew, staff_call, other</em>. Each line becomes a schedule row on the new event's run sheet.</li>
    </ul>
    <h3>Editing existing schedules</h3>
    <p>Open the template, edit the text, save. The new format will be used by future events created from this template; existing events keep their current run sheets unchanged.</p>
    <h3>Deleting a template</h3>
    <p>Deletes the template only. Events that were already created from it continue to exist and behave normally.</p>
  `,

  statuses: `
    <h2>Event status reference</h2>
    <p>Events move through these statuses, roughly left to right on the pipeline. Labels match the MabEvents Google Sheet so the vocabulary is consistent across both tools:</p>
    <ol>
      <li><strong>Empty</strong> — the date is held but nothing is booked.</li>
      <li><strong>Prospect</strong> — a show idea exists but is not confirmed.</li>
      <li><strong>In Negotiations</strong> — soft hold with a band/promoter; terms still being worked out.</li>
      <li><strong>Booked</strong> — show is on (includes deposits paid), but assets and announcement are pending.</li>
      <li><strong>Needs Assets</strong> — booked, blocked on flyer/social art.</li>
      <li><strong>Ready To Announce</strong> — flyer approved, ticketing ready; just needs to flip public on.</li>
      <li><strong>Published</strong> — public page is live.</li>
      <li><strong>Advanced</strong> — production advanced; ready for night-of-show.</li>
      <li><strong>Archived</strong> — show happened, waiting on settlement.</li>
      <li><strong>Settled</strong> — books closed.</li>
      <li><strong>Cancelled</strong> — show was cancelled.</li>
    </ol>
    <p>Statuses do not enforce hard transitions — you can move between any of them. They are signals to the rest of the team and to the dashboard.</p>
  `,

  workflow: `
    <h2>End-to-end show workflow</h2>
    <p>A typical Mabuhay show moves through these phases:</p>
    <ol>
      <li><strong>Program the night</strong> — pick a template (Templates page), set the date, and create the event.</li>
      <li><strong>Sign the artists</strong> — add bands to the lineup, capture payout terms, mark them <em>tentative</em> then <em>confirmed</em>.</li>
      <li><strong>Set the times</strong> — fill in doors, show, set times, and curfew on the run sheet.</li>
      <li><strong>Collect assets</strong> — invite the band's designer if needed; upload flyers; approve the primary flyer.</li>
      <li><strong>Announce</strong> — set ticket URL, public description, and flip the public page on. Status becomes <em>published</em>.</li>
      <li><strong>Advance</strong> — close out open items, confirm hospitality, share run sheet with bands. Status becomes <em>advanced</em>.</li>
      <li><strong>Night of show</strong> — print the master event packet, use the guest list for door, check guests in.</li>
      <li><strong>Settle</strong> — file settlement next-day, mark <em>settled</em>.</li>
    </ol>
  `,

  faq: `
    <h2>FAQ</h2>
    <h3>Why can't I see settlement on this event?</h3>
    <p>Settlement is hidden from promoter, band/artist, designer, and viewer roles. Only venue admins and event owners see it.</p>
    <h3>Why is a tab missing on my event?</h3>
    <p>Tabs are filtered by your capabilities. For example, the <em>Invites</em> tab only appears if you can manage invites for the event.</p>
    <h3>Why didn't my collaborator get an email?</h3>
    <p>Backstage generates an invite URL but does not send the invite email itself. Copy the link and share it via your usual channel.</p>
    <h3>Someone's login-link email never arrived</h3>
    <p>Login links <em>are</em> sent by Backstage via the configured mail relay, but Gmail and other providers occasionally swallow them silently (especially for new addresses). If a user can't find their link in spam/promotions either, a venue admin can mint a fresh single-use link directly from the database and hand it over out-of-band. The link format is <code>/backstage/login.html?token=&lt;hex&gt;</code> and the token row goes into <code>magic_link_tokens</code>.</p>
    <h3>How do I move a show to a new date?</h3>
    <p>Open <a href="#help-details">Event details</a> and change the date. Calendar, dashboard, and pipeline all update.</p>
    <h3>How do I delete an event?</h3>
    <p>Events are not deleted in the MVP. Move them to <em>canceled</em> instead — they drop off the calendar and active dashboard cards but stay queryable for reporting.</p>
    <h3>Where are uploaded files stored?</h3>
    <p>Local disk under <code>storage/uploads/events/&lt;event id&gt;</code>. The web server serves them via the <code>public/uploads</code> symlink.</p>
  `,

  troubleshooting: `
    <h2>Troubleshooting</h2>
    <h3>"Session expired" or you keep getting bounced to login</h3>
    <p>Your access and refresh tokens both expired. Sign in again. If it happens often, your browser may be clearing local storage; check your privacy settings.</p>
    <h3>Passkey button does nothing</h3>
    <p>Your browser may not support WebAuthn, or you have no passkey registered for that hostname. Use password or email-link login and add a passkey from <em>Account</em>.</p>
    <h3>"This login link is invalid or has already been used"</h3>
    <p>Login links are single-use and expire after 24 hours. The most common cause of a "fresh" link appearing burned is a message previewer (iMessage, Slack, corporate URL scanners) silently visiting the link to render a preview — which used to consume the token. Backstage now shows a <em>Continue to your account</em> interstitial that only burns the token on a real click, so previewers should no longer be a problem; but if you've already followed an older-flow link, just request a new one from the login page.</p>
    <h3>Public page shows "Something went wrong"</h3>
    <p>Either the event is hidden (toggle <em>Publish Public Page</em> on) or the slug is wrong. The public page only returns data for events with public visibility enabled.</p>
    <h3>Upload failed</h3>
    <p>Check that the file is under the server's <code>upload_max_filesize</code> and is one of the accepted types (PNG, JPG, GIF, WEBP, PDF). The server enforces type by both extension and MIME via <code>finfo</code>.</p>
    <h3>Asset won't approve</h3>
    <p>Only promoters and admins can approve assets. Bands and designers can upload but not approve.</p>
  `,
};

class HelpPage extends PanicElement {
  set anchor(value) {
    this._anchor = value || '';
    if (this.isConnected) this.afterRender();
  }

  connect() {
    this._anchor = this._anchor || '';
    this.render();
    this.afterRender();
  }

  render() {
    const toc = HELP_SECTIONS.map((group) => `
      <div class="help-toc-group">
        <h4>${group.group}</h4>
        <ul>${group.items.map((item) => `<li><a data-toc href="#help-${esc(item.slug)}">${item.title}</a></li>`).join('')}</ul>
      </div>
    `).join('');

    const sections = HELP_SECTIONS.flatMap((g) => g.items).map((item) => {
      const body = HELP_CONTENT[item.slug] || `<h2>${item.title}</h2><p class="muted">Documentation coming soon.</p>`;
      return `<section class="help-section" id="help-${esc(item.slug)}">${body}<p class="help-back"><a href="#help-welcome">&uarr; Back to top</a></p></section>`;
    }).join('');

    this.innerHTML = `
      <section class="page-head">
        <div><h1>Backstage Help</h1><p class="subtle">How the app works — onboarding, events, lineup, assets, settlement, and everything in between.</p></div>
        <a class="button secondary" href="#dashboard">Back to Dashboard</a>
      </section>
      <div class="help-layout">
        <aside class="help-toc" aria-label="Help topics">${toc}</aside>
        <article class="help-content panel padded">${sections}</article>
      </div>
    `;

    $$('[data-toc]', this).forEach((link) => link.addEventListener('click', (event) => {
      // Let the browser scroll, but also highlight the active TOC item.
      const slug = (link.getAttribute('href') || '').replace('#help-', '');
      this.highlight(slug);
    }));
  }

  afterRender() {
    const slug = this._anchor || 'welcome';
    // Defer to next frame so layout is settled before scrolling.
    requestAnimationFrame(() => {
      const target = this.querySelector(`#help-${CSS.escape(slug)}`);
      if (target) target.scrollIntoView({ behavior: 'auto', block: 'start' });
      this.highlight(slug);
    });
  }

  highlight(slug) {
    $$('[data-toc]', this).forEach((link) => {
      link.classList.toggle('active', link.getAttribute('href') === `#help-${slug}`);
    });
  }
}

// ── Admin page ───────────────────────────────────────────────────────────────
// Three tabs: Users (login accounts), Staff (employee roster), Templates
// (run-sheet / checklist event templates). Admin-only — sidebar entry is
// hidden by AppShell.applyCapabilities() when the user lacks admin caps.

const ADMIN_TABS = [
  { key: 'users',     title: 'Users',     icon: 'fa-user-gear' },
  { key: 'staff',     title: 'Staff',     icon: 'fa-people-group' },
  { key: 'templates', title: 'Templates', icon: 'fa-layer-group' },
];

class AdminPage extends PanicElement {
  connect() {
    this.tab = ADMIN_TABS.find((t) => t.key === this.initialTab) ? this.initialTab : 'users';
    this.render();
  }

  render() {
    this.innerHTML = `
      <section class="page-head">
        <div><h1>Admin</h1><p class="subtle">Manage login accounts, the staff roster, and event templates.</p></div>
      </section>
      <nav class="workspace-tabs tabs admin-tabs">
        ${ADMIN_TABS.map((t) => `<a data-admin-tab="${esc(t.key)}" href="#admin-${esc(t.key)}" class="${t.key === this.tab ? 'active' : ''}"><i class="fa-solid ${esc(t.icon)}" aria-hidden="true"></i> ${esc(t.title)}</a>`).join('')}
      </nav>
      <div class="admin-outlet"></div>
    `;
    $$('[data-admin-tab]', this).forEach((link) => link.addEventListener('click', (event) => {
      event.preventDefault();
      this.tab = link.dataset.adminTab;
      this.render();
    }));
    const outlet = $('.admin-outlet', this);
    const tag = { users: 'pb-admin-users', staff: 'pb-admin-staff', templates: 'pb-admin-templates' }[this.tab];
    outlet.replaceChildren(document.createElement(tag));
  }
}

class AdminUsers extends PanicElement {
  async connect() {
    this.setLoading('Loading users');
    try {
      this.data = await api('/users');
      this.renderList();
    } catch (error) {
      this.showError(error);
    }
  }

  renderList() {
    const users = this.data.users || [];
    const roles = this.data.roles || [];
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded"><h2>Login Accounts</h2><span class="muted">${users.length} total</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Auth</th><th>Events</th><th></th></tr></thead>
          <tbody>
            ${users.map((u) => `<tr>
              <td>${esc(u.name)}</td>
              <td>${esc(u.email)}</td>
              <td><span class="badge">${esc(titleCase(u.role))}</span></td>
              <td>${Number(u.has_password) ? '<span class="muted">Password</span>' : '<span class="muted">—</span>'}${Number(u.passkey_count) ? ` &middot; ${esc(u.passkey_count)} passkey${Number(u.passkey_count) === 1 ? '' : 's'}` : ''}</td>
              <td>${esc(u.owned_event_count || 0)} owned &middot; ${esc(u.collaborator_event_count || 0)} collab</td>
              <td class="row-actions">
                <button class="small secondary" data-edit="${esc(u.id)}">Edit</button>
                <button class="small danger" data-delete="${esc(u.id)}" data-name="${esc(u.name)}">Delete</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">No users yet.</div></td></tr>'}
          </tbody>
        </table>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Create User</h2></div>
        <form data-form="create" class="grid-form padded">
          <label>Name <input name="name" required placeholder="Full name"></label>
          <label>Email <input type="email" name="email" required placeholder="user@example.com"></label>
          <label>Role ${select('role', roles, 'viewer')}</label>
          <label>Password <input type="password" name="password" placeholder="Optional — they can also use email link"></label>
          <button>Create user</button>
        </form>
      </article>
    `;
    $('[data-form="create"]', this).addEventListener('submit', (event) => this.create(event));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openEdit(Number(b.dataset.edit))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
  }

  async create(event) {
    event.preventDefault();
    const body = formData(event.target);
    try {
      await api('/users', { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: `User ${body.name} created.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openEdit(id) {
    const user = (this.data.users || []).find((u) => Number(u.id) === id);
    if (!user) return;
    const roles = this.data.roles || [];
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Edit user</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="edit">
        <label>Name <input name="name" required value="${esc(user.name)}"></label>
        <label>Email <input type="email" name="email" required value="${esc(user.email)}"></label>
        <label>Role ${select('role', roles, user.role)}</label>
        <label>Reset password <input type="password" name="password" placeholder="Leave blank to keep current"></label>
        <p class="muted">${Number(user.has_password) ? 'Password is set.' : 'No password set — user can sign in via passkey or email link.'} ${Number(user.passkey_count)} passkey${Number(user.passkey_count) === 1 ? '' : 's'} registered.</p>
        <button>Save</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="edit"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      try {
        await api(`/users/${user.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'User updated.' });
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Delete user ${name}? This cannot be undone.`)) return;
    try {
      await api(`/users/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `${name} deleted.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}

class AdminStaff extends PanicElement {
  async connect() {
    this.setLoading('Loading staff roster');
    try {
      this.data = await api('/staff-members');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const staff = this.data.staff || [];
    const roles = this.data.roles || [];
    const users = this.data.users || [];
    const userOpts = `<option value="">— No login linked —</option>${users.map((u) => `<option value="${esc(u.id)}">${esc(u.name)} (${esc(u.email)})</option>`).join('')}`;
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded"><h2>Staff Roster</h2><span class="muted">${staff.filter((s) => Number(s.active)).length} active &middot; ${staff.length} total</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Default role</th><th>Contact</th><th>Rate</th><th>Login</th><th>Status</th><th></th></tr></thead>
          <tbody>
            ${staff.map((s) => `<tr class="${Number(s.active) ? '' : 'muted-row'}">
              <td><strong>${esc(s.name)}</strong>${s.pronoun ? ` <small class="muted">(${esc(s.pronoun)})</small>` : ''}${s.position ? `<br><small>${esc(s.position)}</small>` : ''}${s.notes ? `<br><small class="muted">${esc(s.notes)}</small>` : ''}</td>
              <td><span class="badge">${esc(titleCase(s.default_role))}</span></td>
              <td>${s.email ? esc(s.email) : ''}${s.email && s.phone ? '<br>' : ''}${s.phone ? esc(s.phone) : ''}</td>
              <td>${s.hourly_rate ? `$${esc(Number(s.hourly_rate).toFixed(2))}/hr` : '—'}</td>
              <td>${s.user_name ? esc(s.user_name) : '<span class="muted">—</span>'}</td>
              <td>${Number(s.active) ? '<span class="badge status-confirmed">Active</span>' : '<span class="badge status-canceled">Inactive</span>'}</td>
              <td class="row-actions">
                <button class="small secondary" data-edit="${esc(s.id)}">Edit</button>
                <button class="small danger" data-delete="${esc(s.id)}" data-name="${esc(s.name)}">Delete</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="7"><div class="empty-state">No staff yet — add your first crew member below.</div></td></tr>'}
          </tbody>
        </table>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Add Staff</h2></div>
        <form data-form="create" class="grid-form padded">
          <label>Name <input name="name" required placeholder="Full name"></label>
          <label>Pronoun <input name="pronoun" placeholder="they/them, she/her, …"></label>
          <label>Default role ${select('default_role', roles, 'security')}</label>
          <label>Position <input name="position" placeholder="Lead bartender, Head of Security, …"></label>
          <label>Email <input type="email" name="email" placeholder="Optional"></label>
          <label>Phone <input name="phone" placeholder="Optional"></label>
          <label>Hourly rate <input type="number" step="0.01" name="hourly_rate" placeholder="Optional"></label>
          <label>Link to login <select name="user_id">${userOpts}</select></label>
          <label class="wide">Notes <input name="notes" placeholder="Allergies, certifications, availability"></label>
          <input type="hidden" name="active" value="1">
          <button>Add staff member</button>
        </form>
      </article>
    `;
    $('[data-form="create"]', this).addEventListener('submit', (event) => this.create(event));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openEdit(Number(b.dataset.edit))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
  }

  async create(event) {
    event.preventDefault();
    const body = formData(event.target);
    try {
      await api('/staff-members', { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: `${body.name} added.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openEdit(id) {
    const s = (this.data.staff || []).find((row) => Number(row.id) === id);
    if (!s) return;
    const roles = this.data.roles || [];
    const users = this.data.users || [];
    const userOpts = `<option value="">— No login linked —</option>${users.map((u) => `<option value="${esc(u.id)}" ${Number(s.user_id) === Number(u.id) ? 'selected' : ''}>${esc(u.name)} (${esc(u.email)})</option>`).join('')}`;
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Edit staff member</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="edit">
        <label>Name <input name="name" required value="${esc(s.name)}"></label>
        <label>Pronoun <input name="pronoun" value="${esc(s.pronoun || '')}" placeholder="they/them, she/her, …"></label>
        <label>Default role ${select('default_role', roles, s.default_role)}</label>
        <label>Position <input name="position" value="${esc(s.position || '')}" placeholder="Lead bartender, Head of Security, …"></label>
        <label>Email <input type="email" name="email" value="${esc(s.email || '')}"></label>
        <label>Phone <input name="phone" value="${esc(s.phone || '')}"></label>
        <label>Hourly rate <input type="number" step="0.01" name="hourly_rate" value="${esc(s.hourly_rate || '')}"></label>
        <label>Link to login <select name="user_id">${userOpts}</select></label>
        <label class="wide">Notes <input name="notes" value="${esc(s.notes || '')}"></label>
        <label class="check-label"><input type="checkbox" name="active" value="1" ${Number(s.active) ? 'checked' : ''}> Active</label>
        <button>Save</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="edit"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      body.active = event.target.active.checked ? 1 : 0;
      try {
        await api(`/staff-members/${s.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Staff member updated.' });
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Remove ${name} from the roster? Past shifts are kept as "TBD" assignments.`)) return;
    try {
      await api(`/staff-members/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `${name} removed.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}

class AdminTemplates extends PanicElement {
  async connect() {
    this.setLoading('Loading templates');
    try {
      this.data = await api('/templates');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const templates = this.data.templates || [];
    const types = this.data.types || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
    const venues = this.data.venues || [];
    const venueOpts = venues.map((v) => `<option value="${esc(v.id)}">${esc(v.name)}</option>`).join('');
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded"><h2>Event Templates</h2><span class="muted">${templates.length} total</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Type</th><th>Venue</th><th>Default title</th><th>Checklist / Schedule</th><th></th></tr></thead>
          <tbody>
            ${templates.map((t) => {
              const checklist = (() => { try { return JSON.parse(t.checklist_json || '[]'); } catch { return []; } })();
              const schedule  = (() => { try { return JSON.parse(t.schedule_json  || '[]'); } catch { return []; } })();
              return `<tr>
                <td><strong>${esc(t.name)}</strong></td>
                <td>${esc(titleCase(t.event_type))}</td>
                <td>${esc(t.venue_name)}</td>
                <td>${esc(t.default_title || '—')}</td>
                <td>${checklist.length} task${checklist.length === 1 ? '' : 's'} &middot; ${schedule.length} schedule item${schedule.length === 1 ? '' : 's'}</td>
                <td class="row-actions">
                  <button class="small secondary" data-edit="${esc(t.id)}">Edit</button>
                  <button class="small danger" data-delete="${esc(t.id)}" data-name="${esc(t.name)}">Delete</button>
                </td>
              </tr>`;
            }).join('') || '<tr><td colspan="6"><div class="empty-state">No templates yet — create one below to start programming nights.</div></td></tr>'}
          </tbody>
        </table>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Create Template</h2></div>
        <form data-form="create" class="grid-form padded">
          <label>Name <input name="name" required placeholder="e.g. Three-Band Local Show"></label>
          <label>Type ${select('event_type', types, 'live_music')}</label>
          <label>Venue <select name="venue_id" required>${venueOpts}</select></label>
          <label>Default title <input name="default_title" placeholder="Used when creating events"></label>
          <label>Default ticket price <input type="number" step="0.01" name="default_ticket_price" value="0"></label>
          <label>Default age <input name="default_age_restriction" placeholder="21+ / All Ages"></label>
          <label class="wide">Public description <textarea name="default_description_public" rows="2"></textarea></label>
          <label class="wide">Checklist <small class="muted">One task per line. Pre-populates the Tasks list of new events.</small><textarea name="_checklist" rows="5" placeholder="Confirm headliner\nApprove flyer\nPublish event page"></textarea></label>
          <label class="wide">Schedule <small class="muted">One per line as <code>HH:MM | type | title</code>. Types: load_in, soundcheck, doors, set, changeover, curfew, staff_call, other.</small><textarea name="_schedule" rows="5" placeholder="17:00 | load_in | Load-in\n18:00 | soundcheck | Soundcheck\n20:00 | doors | Doors\n20:30 | set | Opener"></textarea></label>
          <button>Create template</button>
        </form>
      </article>
    `;
    $('[data-form="create"]', this).addEventListener('submit', (event) => this.create(event));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openEdit(Number(b.dataset.edit))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
  }

  parseChecklist(value) {
    return String(value || '').split('\n').map((l) => l.trim()).filter(Boolean).map((title) => ({ title }));
  }

  parseSchedule(value) {
    const validTypes = new Set(['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other']);
    return String(value || '').split('\n').map((l) => l.trim()).filter(Boolean).map((line) => {
      const parts = line.split('|').map((p) => p.trim());
      const time = parts[0] || null;
      const type = validTypes.has((parts[1] || '').toLowerCase()) ? parts[1].toLowerCase() : 'other';
      const title = parts.slice(2).join(' | ') || (parts[1] || 'Schedule item');
      return { start_time: time, item_type: type, title };
    });
  }

  serializeChecklist(json) {
    try {
      const arr = JSON.parse(json || '[]');
      return (arr || []).map((row) => typeof row === 'string' ? row : row.title).filter(Boolean).join('\n');
    } catch { return ''; }
  }

  serializeSchedule(json) {
    try {
      const arr = JSON.parse(json || '[]');
      return (arr || []).map((row) => `${row.start_time || ''} | ${row.item_type || 'other'} | ${row.title || ''}`).join('\n');
    } catch { return ''; }
  }

  buildBody(form) {
    const body = formData(form);
    body.checklist_json = JSON.stringify(this.parseChecklist(body._checklist));
    body.schedule_json  = JSON.stringify(this.parseSchedule(body._schedule));
    delete body._checklist;
    delete body._schedule;
    return body;
  }

  async create(event) {
    event.preventDefault();
    try {
      await api('/templates', { method: 'POST', body: JSON.stringify(this.buildBody(event.target)) });
      publish('toast.show', { message: 'Template created.' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openEdit(id) {
    const t = (this.data.templates || []).find((row) => Number(row.id) === id);
    if (!t) return;
    const types = this.data.types || [];
    const venues = this.data.venues || [];
    const venueOpts = venues.map((v) => `<option value="${esc(v.id)}" ${Number(v.id) === Number(t.venue_id) ? 'selected' : ''}>${esc(v.name)}</option>`).join('');
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide">
      <div class="section-head padded"><h2>Edit template</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="edit">
        <label>Name <input name="name" required value="${esc(t.name)}"></label>
        <label>Type ${select('event_type', types, t.event_type)}</label>
        <label>Venue <select name="venue_id" required>${venueOpts}</select></label>
        <label>Default title <input name="default_title" value="${esc(t.default_title || '')}"></label>
        <label>Default ticket price <input type="number" step="0.01" name="default_ticket_price" value="${esc(t.default_ticket_price || 0)}"></label>
        <label>Default age <input name="default_age_restriction" value="${esc(t.default_age_restriction || '')}"></label>
        <label class="wide">Public description <textarea name="default_description_public" rows="2">${esc(t.default_description_public || '')}</textarea></label>
        <label class="wide">Checklist <small class="muted">One task per line.</small><textarea name="_checklist" rows="7">${esc(this.serializeChecklist(t.checklist_json))}</textarea></label>
        <label class="wide">Schedule <small class="muted">HH:MM | type | title  (types: load_in, soundcheck, doors, set, changeover, curfew, staff_call, other)</small><textarea name="_schedule" rows="7">${esc(this.serializeSchedule(t.schedule_json))}</textarea></label>
        <button>Save template</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="edit"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/templates/${t.id}`, { method: 'PATCH', body: JSON.stringify(this.buildBody(event.target)) });
        publish('toast.show', { message: 'Template saved.' });
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Delete the ${name} template? Existing events created from it are not affected.`)) return;
    try {
      await api(`/templates/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Template deleted.' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
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
customElements.define('pb-staffing-manager', StaffingManager);
customElements.define('pb-open-items', OpenItems);
customElements.define('pb-guest-list-manager', GuestListManager);
customElements.define('pb-asset-manager', AssetManager);
customElements.define('pb-invite-manager', InviteManager);
customElements.define('pb-settlement-form', SettlementForm);
customElements.define('pb-public-event-page', PublicEventPage);
customElements.define('pb-invite-acceptance', InviteAcceptance);
customElements.define('pb-help-page', HelpPage);
customElements.define('pb-admin-page', AdminPage);
customElements.define('pb-admin-users', AdminUsers);
customElements.define('pb-admin-staff', AdminStaff);
customElements.define('pb-admin-templates', AdminTemplates);
