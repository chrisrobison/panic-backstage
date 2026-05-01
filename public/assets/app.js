let csrf = null;
let currentUser = null;
let eventCache = [];

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const app = $('#app');
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const titleCase = (v) => String(v || '').replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase());
const appScript = document.currentScript || $$('script[src*="assets/app.js"]').at(-1);
const scriptUrl = appScript ? new URL(appScript.src) : new URL(location.href);
const appBaseUrl = /\/public\/assets\/app\.js$/i.test(scriptUrl.pathname)
  ? new URL('../..', scriptUrl)
  : new URL('..', scriptUrl);
const terminology = {
  openItemSingular: 'Open Item',
  openItemPlural: 'Open Items',
  needsAttention: 'Needs Attention',
  atRisk: 'At Risk',
  waitingOn: 'Waiting On',
  pointPerson: 'Point Person',
  markComplete: 'Mark Complete',
  requestHelp: 'Request Help',
  allClear: 'All clear!',
  noOutstandingItems: 'No outstanding items for this event.',
};
const displayLabel = (value) => ({
  blocked: terminology.atRisk,
  blockers: terminology.openItemPlural,
  blocker: terminology.openItemSingular,
})[String(value || '').toLowerCase()] || titleCase(value);
const badge = (s) => `<span class="badge status-${esc(s)}">${esc(displayLabel(s))}</span>`;
const waitingOnText = (value) => {
  const text = String(value || '').trim();
  if (!text) return `${terminology.waitingOn} details`;
  return /^waiting on\b/i.test(text) ? text.replace(/^waiting on\b/i, 'Waiting on') : `Waiting on ${text}`;
};
const appUrl = (path = '') => new URL(path.replace(/^\/+/, ''), appBaseUrl).toString();
const apiUrl = (path = '') => appUrl(`api/${path.replace(/^\/+/, '')}`);
const assetUrl = (path = '') => {
  const value = String(path || '');
  if (/^(?:[a-z]+:|#)/i.test(value)) return value;
  return appUrl(value);
};

async function api(path, options = {}) {
  const headers = options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const res = await fetch(apiUrl(path), { credentials: 'same-origin', ...options, headers: { ...headers, ...(options.headers || {}) } });
  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new Error(body?.error || `Request failed: ${res.status}`);
  if (body?.csrf) csrf = body.csrf;
  return body;
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

async function init() {
  if ($('#login-form')) return initLogin();
  if ($('#public-event')) return renderPublicEvent();
  if ($('#invite')) return renderInvite();
  try {
    const me = await api('/me');
    currentUser = me.user;
    csrf = me.csrf;
    if (!currentUser) location.href = appUrl('login.html');
  } catch {
    location.href = appUrl('login.html');
    return;
  }
  $('#logout')?.addEventListener('click', async () => {
    await api('/logout', { method: 'POST', body: '{}' });
    location.href = appUrl('login.html');
  });
  window.addEventListener('hashchange', route);
  route();
}

function initLogin() {
  $('#login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await api('/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = appUrl();
    } catch (error) {
      $('#error').textContent = error.message;
    }
  });
}

function route() {
  const hash = location.hash.replace(/^#/, '') || 'dashboard';
  setActiveNav(hash.startsWith('event-') ? 'events' : hash);
  if (hash.startsWith('event-')) return renderEvent(Number(hash.slice(6)));
  if (hash === 'calendar' || hash === 'pipeline') return renderCalendar(hash);
  if (hash === 'events') return renderEvents();
  if (hash === 'templates') return renderTemplates();
  if (hash === 'tonight') return renderTonight();
  return renderDashboard();
}

function setActiveNav(active) {
  $$('[data-nav]').forEach((link) => {
    link.classList.toggle('active', link.dataset.nav === active || (active === 'pipeline' && link.dataset.nav === 'calendar'));
  });
}

function eventDate(event) {
  const date = event.date ? new Date(`${event.date}T12:00:00`) : null;
  return Number.isNaN(date?.getTime()) ? null : date;
}

function shortDate(date) {
  return date ? date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : 'TBA';
}

function timeLabel(value) {
  if (!value) return 'TBA';
  const [hours, minutes] = value.split(':').map(Number);
  const date = new Date();
  date.setHours(hours || 0, minutes || 0, 0, 0);
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function statusTone(status) {
  if (['published'].includes(status)) return 'blue';
  if (['advanced', 'ready_to_announce'].includes(status)) return 'green';
  if (['needs_assets', 'confirmed'].includes(status)) return 'amber';
  if (['hold', 'canceled'].includes(status)) return 'red';
  return 'gray';
}

async function loadEvents() {
  const data = await api('/events');
  eventCache = data.events || [];
  return data;
}

async function renderDashboard() {
  const [dashboard, all] = await Promise.all([api('/dashboard'), loadEvents()]);
  const events = dashboard.events.length ? dashboard.events : all.events.slice(0, 7);
  const today = events[0] || all.events[0] || {};
  const attention = attentionItems(events, dashboard.cards);
  app.innerHTML = `
    <section class="page-head">
      <div>
        <h1>Dashboard</h1>
        <p class="subtle">What needs attention</p>
      </div>
    </section>
    <section class="metric-grid">
      ${metricToday(today)}
      ${metricCard('!', terminology.needsAttention, dashboard.cards.blockers, `${dashboard.cards.urgentItems || 0} urgent`, 'red')}
      ${metricCard('', 'Empty Nights', dashboard.cards.empty, 'Next empty Tuesday', '')}
      ${metricCard('', 'Needs Flyer', dashboard.cards.needsAssets, '2 announce-ready otherwise', 'amber')}
      ${metricCard('$', 'Unsettled Events', dashboard.cards.unsettled, 'Oldest Apr 22', 'red')}
    </section>
    <section class="dashboard-grid">
      <article class="panel">
        <div class="section-head padded">
          <h2>Next 14 Days</h2>
          <a class="button secondary small" href="#calendar">View Calendar</a>
        </div>
        ${dashboardTable(events)}
        <div class="legend">${legendItem('green', 'Ready')}${legendItem('blue', 'Published')}${legendItem('amber', terminology.needsAttention)}${legendItem('red', terminology.atRisk)}${legendItem('gray', 'Empty / Hold')}</div>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Needs Attention</h2><a class="button secondary small" href="#events">View All</a></div>
        <div class="attention-list">${attention.map(attentionCard).join('')}</div>
      </article>
    </section>
    ${tonightMarkup(today, true)}`;
}

function metricToday(event) {
  return `<article class="metric-card">
    <span class="icon-bubble"><span class="icon mic"></span></span>
    <h3>Tonight<br>${esc(event.title || 'No event')}</h3>
    <p>Doors ${esc(timeLabel(event.doors_time))}<br>Starts ${esc(timeLabel(event.show_time))}</p>
    ${badge(event.status || 'empty')}
  </article>`;
}

function metricCard(symbol, label, value, note, tone) {
  return `<article class="metric-card ${tone}">
    <span class="icon-bubble ${tone}">${symbol ? esc(symbol) : '<span class="icon calendar"></span>'}</span>
    <h3>${esc(label)}</h3>
    <strong>${esc(value)}</strong>
    <p>${esc(note)}</p>
  </article>`;
}

function dashboardTable(events) {
  return `<table class="data-table">
    <thead><tr><th>Date</th><th>Event</th><th>Status</th><th>Main Issue</th><th>Owner</th></tr></thead>
    <tbody>${events.map((event) => {
      const tone = event.primary_blocker ? 'red' : statusTone(event.status);
      const issue = event.primary_blocker || (Number(event.approved_flyers) ? 'Ready' : event.status === 'empty' ? 'Needs programming' : 'Flyer missing');
      return `<tr>
        <td data-label="Date">${esc(shortDate(eventDate(event)))}</td>
        <td data-label="Event"><a href="#event-${event.id}">${esc(event.title)}</a></td>
        <td data-label="Status">${badge(event.status)}</td>
        <td data-label="Main Issue"><span class="status-dot ${tone}"></span>${esc(issue)}</td>
        <td data-label="Owner">${esc(event.owner_name || '-')}</td>
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

function legendItem(tone, label) {
  return `<span><span class="status-dot ${tone}"></span>${esc(label)}</span>`;
}

function attentionItems(events, cards) {
  const atRisk = events.find((event) => event.primary_blocker);
  const needs = events.find((event) => !Number(event.approved_flyers) && ['confirmed', 'needs_assets', 'ready_to_announce'].includes(event.status));
  const unsettled = cards.unsettled ? { title: 'Saturday Showcase', date: 'Sat May 12', primary_blocker: 'settlement missing' } : null;
  return [
    atRisk && { tone: 'red', title: `${terminology.atRisk}: ${atRisk.title}`, detail: waitingOnText(atRisk.primary_blocker), date: shortDate(eventDate(atRisk)), id: atRisk.id },
    needs && { tone: 'amber', title: `${terminology.atRisk}: ${needs.title}`, detail: waitingOnText(Number(needs.ticket_price) > 0 && !needs.ticket_url ? 'ticket link' : 'flyer approval'), date: shortDate(eventDate(needs)), id: needs.id },
    unsettled && { tone: 'red', title: `${terminology.atRisk}: ${unsettled.title}`, detail: waitingOnText('settlement'), date: unsettled.date },
  ].filter(Boolean);
}

function attentionCard(item) {
  return `<a class="attention-card ${item.tone === 'amber' ? 'amber' : ''}" href="${item.id ? `#event-${item.id}` : '#events'}">
    <span class="icon-bubble ${item.tone}">${item.tone === 'amber' ? '!' : '<span class="icon ticket"></span>'}</span>
    <span><strong>${esc(item.title)}</strong><p>${esc(item.detail)}</p><small>${esc(item.date)}</small></span>
    <span class="arrow"></span>
  </a>`;
}

async function renderCalendar(mode = 'calendar') {
  const data = await loadEvents();
  const events = data.events || [];
  app.innerHTML = `
    <section class="calendar-page">
      <div class="page-head">
        <div><h1>Calendar & Pipeline</h1><p class="subtle">See coverage and move events through the show pipeline.</p></div>
      </div>
      <div class="filters">
        <button class="filter"><span>Apr 30 - Jun 7, 2025</span><span class="chev"></span></button>
        <button class="filter"><span>Event Type</span><span>All types</span></button>
        <button class="filter"><span>Owner</span><span>All owners</span></button>
        <button class="filter"><span>Status</span><span>All statuses</span></button>
        <button class="filter"><span class="icon bars"></span>Clear Filters</button>
      </div>
      ${calendarMarkup(events)}
      ${pipelineMarkup(events)}
    </section>
    ${tonightMarkup(events[0], true)}`;
  if (mode === 'pipeline') $('.calendar-shell')?.scrollIntoView();
}

function calendarMarkup(events) {
  const monthEvents = events.slice(0, 14);
  const start = new Date('2025-04-27T12:00:00');
  const days = Array.from({ length: 35 }, (_, index) => {
    const date = new Date(start);
    date.setDate(start.getDate() + index);
    return date;
  });
  return `<article class="panel calendar-shell">
    <div class="calendar-toolbar">
      <div class="calendar-controls"><button class="secondary small">&lt;</button><button class="secondary small">&gt;</button><button class="secondary small">Today</button></div>
      <h2>May 2025</h2>
      <div class="calendar-actions"><span class="view-toggle">Month&nbsp;&nbsp; Week</span><button class="secondary small" onclick="newEvent()">+ Add Event</button></div>
    </div>
    <div class="calendar-grid">
      ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<div class="weekday">${day}</div>`).join('')}
      ${days.map((date, index) => calendarDay(date, monthEvents[index % monthEvents.length], index)).join('')}
    </div>
  </article>`;
}

function calendarDay(date, event, index) {
  const showEvent = event && [2,3,4,5,8,10,14,18,22,26,29,31].includes(index);
  return `<div class="calendar-day">
    <span class="day-num">${index < 4 ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : date.getDate()}</span>
    ${showEvent ? `<a class="mini-event" href="#event-${event.id}"><span class="status-dot ${statusTone(event.status)}"></span>${esc(event.title)}<br>${badge(event.status)}</a>` : '<div class="program-night">+ Program This Night</div>'}
  </div>`;
}

function pipelineMarkup(events) {
  const groups = [
    ['empty', 'Empty'],
    ['proposed', 'Proposed'],
    ['hold', 'Hold'],
    ['confirmed', 'Confirmed'],
    ['needs_assets', 'Needs Assets'],
    ['ready_to_announce', 'Ready to Announce'],
    ['published', 'Published'],
    ['advanced', 'Advanced'],
  ];
  return `<section class="pipeline-board">
    ${groups.map(([status, label]) => {
      const items = events.filter((event) => event.status === status).slice(0, 3);
      return `<article class="pipe-col">
        <h3>${esc(label)} <span class="pipe-count">${items.length}</span></h3>
        ${items.map((event) => `<a class="pipe-card" href="#event-${event.id}">
          <strong>${esc(event.title)}</strong>
          <span>${esc(shortDate(eventDate(event)))}</span>
          <small>${esc(event.owner_name || 'Unassigned')}</small>
          <small>${esc(event.primary_blocker ? '1 open item' : '0 open items')} &nbsp; ${esc(event.incomplete_tasks || 0)} tasks</small>
        </a>`).join('')}
        <small>+ Add card</small>
      </article>`;
    }).join('')}
  </section>`;
}

async function renderEvents() {
  const data = await loadEvents();
  app.innerHTML = `
    <div class="page-head"><div><h1>Events</h1><p class="subtle">All upcoming and historical shows.</p></div><button onclick="newEvent()">Add Event</button></div>
    <article class="panel">${dashboardTable(data.events)}</article>
    ${tonightMarkup(data.events[0], true)}`;
}

async function newEvent() {
  const data = await loadEvents();
  app.innerHTML = `<div class="page-head"><h1>New Event</h1><a class="button secondary" href="#events">Back</a></div><section class="panel padded">${eventForm({}, data)}</section>`;
  $('#event-form').addEventListener('submit', saveEvent);
}

function eventForm(event, data) {
  const statuses = data.statuses || ['proposed','hold','confirmed','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled'];
  const types = data.types || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
  const option = (value, selected, label = value) => `<option value="${esc(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${esc(titleCase(label))}</option>`;
  return `<form id="event-form" class="grid-form">
    <input type="hidden" name="id" value="${esc(event.id || '')}">
    <label>Title <input name="title" required value="${esc(event.title || '')}"></label>
    <label>Date <input type="date" name="date" required value="${esc(event.date || '')}"></label>
    <label>Venue <select name="venue_id">${data.venues.map((v) => option(v.id, event.venue_id, v.name)).join('')}</select></label>
    <label>Type <select name="event_type">${types.map((t) => option(t, event.event_type || 'live_music')).join('')}</select></label>
    <label>Status <select name="status">${statuses.map((s) => option(s, event.status || 'proposed')).join('')}</select></label>
    <label>Owner <select name="owner_user_id"><option value="">Unassigned</option>${data.users.map((u) => option(u.id, event.owner_user_id, u.name)).join('')}</select></label>
    <label>Doors <input type="time" name="doors_time" value="${esc(event.doors_time || '')}"></label>
    <label>Show <input type="time" name="show_time" value="${esc(event.show_time || '')}"></label>
    <label>End <input type="time" name="end_time" value="${esc(event.end_time || '')}"></label>
    <label>Age restriction <input name="age_restriction" value="${esc(event.age_restriction || '')}"></label>
    <label>Ticket price <input type="number" step="0.01" name="ticket_price" value="${esc(event.ticket_price || 0)}"></label>
    <label>Capacity <input type="number" name="capacity" value="${esc(event.capacity || '')}"></label>
    <label class="wide">Ticket URL <input type="url" name="ticket_url" value="${esc(event.ticket_url || '')}"></label>
    <label class="wide">Public description <textarea name="description_public">${esc(event.description_public || '')}</textarea></label>
    <label class="wide">Internal notes <textarea name="description_internal">${esc(event.description_internal || '')}</textarea></label>
    <label><input type="checkbox" name="public_visibility" value="1" ${Number(event.public_visibility) ? 'checked' : ''}> Public page visible</label>
    <button>Save event</button>
  </form>`;
}

async function saveEvent(eventSubmit) {
  eventSubmit.preventDefault();
  const body = formData(eventSubmit.target);
  body.public_visibility = eventSubmit.target.public_visibility.checked ? 1 : 0;
  const id = body.id;
  delete body.id;
  const res = await api(id ? `/events/${id}` : '/events', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
  location.hash = `event-${id || res.id}`;
}

async function renderEvent(id) {
  const data = await api(`/events/${id}`);
  const event = data.event;
  app.innerHTML = `
    <section class="event-top">
      <div>
        <a class="back-link" href="#events">&lt;- Back to Events</a>
        <h1>${esc(event.title)}</h1>
        <p class="subtle">Event Workspace</p>
      </div>
      <div class="event-actions">
        <button class="secondary">More Actions <span class="chev"></span></button>
        <button class="danger" onclick="editEvent(${id})">Edit Event</button>
      </div>
    </section>
    <nav class="workspace-tabs tabs">${workspaceTabs().map((tab, index) => `<a class="${index === 0 ? 'active' : ''}" href="${esc(tab.href)}">${esc(tab.label)}</a>`).join('')}</nav>
    ${eventSummary(data)}
    <article class="next-action"><span class="icon-bubble amber">!</span><span><strong>Next Recommended Action</strong><p>${esc(data.nextAction)}</p></span><button class="secondary small">Mark as Complete</button></article>
    ${eventOverview(data)}
    ${detailSections(id, data)}
    ${tonightMarkup(event, true, data)}`;
  bindWorkspaceForms(id);
}

function eventSummary(data) {
  const e = data.event;
  const openBlockers = data.blockers.filter((b) => ['open','waiting'].includes(b.status)).length;
  const tasksLeft = data.tasks.filter((t) => !['done','canceled'].includes(t.status)).length;
  return `<article class="event-summary">
    <div class="flyer">${esc(e.title)}</div>
    <div class="facts-grid">
      ${fact('Date', shortDate(eventDate(e)))}
      ${fact('Doors', timeLabel(e.doors_time))}
      ${fact('Show', timeLabel(e.show_time))}
      ${fact('Age', e.age_restriction || '21+')}
      ${fact('Status', badge(e.status))}
      ${fact('Owner', e.owner_name || 'Unassigned')}
      ${fact('Public Page', Number(e.public_visibility) ? 'Live' : 'Hidden')}
      ${fact('Tickets', e.ticket_url ? 'Ticket link ready' : Number(e.ticket_price) > 0 ? `$${e.ticket_price}` : 'RSVP / Free')}
    </div>
    <div class="event-stats">
      <div class="event-stat">${terminology.openItemPlural}<strong>${openBlockers}</strong><a href="#blockers">View</a></div>
      <div class="event-stat">Tasks Left<strong>${tasksLeft}</strong><a href="#tasks">View</a></div>
    </div>
  </article>`;
}

function fact(label, value) {
  return `<div class="fact"><label>${esc(label)}</label><strong>${value}</strong></div>`;
}

function eventOverview(data) {
  const openBlockers = data.blockers.filter((b) => ['open','waiting'].includes(b.status));
  const tasks = data.tasks.filter((t) => !['done','canceled'].includes(t.status)).slice(0, 4);
  return `<section class="overview-grid">
    <div>
      <article class="panel">
        <div class="section-head padded"><h2>Event Health</h2><button class="secondary small">View Details</button></div>
        <div class="health-row">
          ${health('Flyer', hasFlyer(data) ? 'Approved' : 'Missing', hasFlyer(data))}
          ${health('Ticketing', data.event.ticket_url || Number(data.event.ticket_price) === 0 ? 'Active' : 'Needed', data.event.ticket_url || Number(data.event.ticket_price) === 0)}
          ${health('Lineup', data.lineup.length ? 'Confirmed' : 'Missing', data.lineup.length)}
          ${health('Schedule', data.schedule.length ? 'Ready' : 'Missing', data.schedule.length)}
          ${health('Staffing', data.event.owner_name ? 'Confirmed' : 'Unassigned', data.event.owner_name)}
          ${health('Settlement', data.settlement ? 'Started' : 'Not started', data.settlement, true)}
        </div>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Incomplete Tasks</h2><a class="button secondary small" href="#tasks">View All Tasks</a></div>
        ${tasks.length ? tasks.map(taskRow).join('') : '<div class="empty-state">All tasks are complete.</div>'}
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Schedule / Run of Show</h2><a class="button secondary small" href="#schedule">View Full Schedule</a></div>
        ${data.schedule.slice(0, 6).map(scheduleRow).join('') || '<div class="empty-state">No schedule has been added.</div>'}
      </article>
    </div>
    <div>
      <article class="panel">
        <div class="section-head padded"><h2>${terminology.openItemPlural}</h2><a class="button secondary small" href="#blockers">View All</a></div>
        ${openBlockers.length ? openBlockers.map((b) => openItemRow(b)).join('') : `<div class="empty-state"><span class="check">OK</span><p>${terminology.allClear}<br>${terminology.noOutstandingItems}</p></div>`}
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Performer Queue</h2><a class="button secondary small" href="#lineup">View Full Lineup</a></div>
        ${data.lineup.slice(0, 5).map((item, i) => performerRow(item, i)).join('') || '<div class="empty-state">No performers yet.</div>'}
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Assets</h2><a class="button secondary small" href="#assets">View All Assets</a></div>
        ${assetPreview(data)}
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Internal Notes</h2><button class="secondary small" onclick="editEvent(${data.event.id})">Edit</button></div>
        <div class="notes">${esc(data.event.description_internal || 'No internal notes yet.')}</div>
      </article>
    </div>
  </section>`;
}

function health(label, state, ok, neutral = false) {
  const mark = neutral ? '<span class="neutral">.</span>' : ok ? '<span class="check">OK</span>' : '<span class="warn-mark">!</span>';
  return `<div class="health-item">${mark}<span><strong>${esc(label)}</strong><br>${esc(state)}</span></div>`;
}

function hasFlyer(data) {
  return data.assets.some((a) => a.asset_type === 'flyer' && a.approval_status === 'approved');
}

function taskRow(task) {
  return `<div class="task-row"><span class="box"></span><span><span class="status-dot amber"></span>${esc(task.title)}</span><span>${esc(task.assigned_name || '')}</span><span>${esc(task.due_date || '')}</span></div>`;
}

function scheduleRow(item) {
  return `<div class="schedule-row"><strong>${esc(timeLabel(item.start_time))}</strong><span><span class="status-dot ${statusTone(item.item_type)}"></span>${esc(item.title)}</span><span>${esc(item.notes || '')}</span><span>${esc(titleCase(item.item_type))}</span></div>`;
}

function performerRow(item, index) {
  return `<div class="performer-row"><span>${index + 1}</span><span>${esc(item.display_name)}</span><span>${esc(timeLabel(item.set_time))}</span></div>`;
}

function assetPreview(data) {
  const asset = data.assets[0];
  if (!asset) return '<div class="empty-state">No assets uploaded.</div>';
  return `<div class="asset-row"><span class="asset-thumb">${esc(data.event.title)}</span><span><strong>${esc(asset.title)}</strong><br>${esc(asset.asset_type)} - ${esc(asset.approval_status)}<br>${esc(asset.created_at || '')}</span><button class="secondary small">Download</button></div>`;
}

function detailSections(id, data) {
  return `${lineupSection(id, data)}${tasksSection(id, data)}${blockersSection(id, data)}${scheduleSection(id, data)}${assetsSection(id, data)}${settlementSection(id, data)}${activitySection(data)}`;
}

function workspaceTabs() {
  return [
    ['#overview', 'Overview'],
    ['#lineup', 'Lineup'],
    ['#schedule', 'Schedule'],
    ['#tasks', 'Tasks'],
    ['#blockers', terminology.openItemPlural],
    ['#assets', 'Assets'],
    ['#public-page', 'Public Page'],
    ['#settlement', 'Settlement'],
    ['#activity', 'Activity'],
  ].map(([href, label]) => ({ href, label }));
}

async function editEvent(id) {
  const data = await api(`/events/${id}`);
  app.innerHTML = `<div class="page-head"><h1>Edit Event</h1><a class="button secondary" href="#event-${id}">Back</a></div><section class="panel padded">${eventForm(data.event, data)}</section>`;
  $('#event-form').addEventListener('submit', saveEvent);
}

function select(name, options, selected, labels = {}) {
  return `<select name="${name}">${options.map((o) => `<option value="${esc(o)}" ${o === selected ? 'selected' : ''}>${esc(labels[o] || displayLabel(o))}</option>`).join('')}</select>`;
}

function userSelect(users, selected) {
  return `<select name="owner_user_id"><option value="">Unassigned</option>${users.map((u) => `<option value="${esc(u.id)}" ${String(u.id) === String(selected || '') ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>`;
}

function openItemRow(item) {
  return `<div class="task-row"><span class="warn-mark">!</span><span>${esc(item.title)}</span><span>${esc(item.owner_name || '')}</span><span>${esc(item.due_date || '')}</span></div>`;
}

function lineupSection(id, data) {
  return `<section id="lineup" class="panel"><div class="section-head padded"><h2>Lineup</h2></div>${data.lineup.map((x) => `<form data-api="/events/${id}/lineup/${x.id}" data-method="PATCH" class="row-form"><input name="billing_order" type="number" value="${esc(x.billing_order)}"><input name="display_name" value="${esc(x.display_name)}"><input name="set_time" type="time" value="${esc(x.set_time || '')}"><input name="set_length_minutes" type="number" value="${esc(x.set_length_minutes || '')}">${select('status', ['invited','tentative','confirmed','canceled'], x.status)}<input name="payout_terms" value="${esc(x.payout_terms || '')}"><input name="notes" value="${esc(x.notes || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/lineup" data-method="POST" class="row-form"><input name="band_name" placeholder="Band/artist"><input name="display_name" placeholder="Display name"><input name="billing_order" type="number" placeholder="Order"><input name="set_time" type="time"><input name="set_length_minutes" type="number" placeholder="Minutes">${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}<input name="payout_terms" placeholder="Payout"><input name="notes" placeholder="Notes"><button>Add lineup</button></form></section>`;
}

function tasksSection(id, data) {
  return `<section id="tasks" class="panel"><div class="section-head padded"><h2>Tasks</h2></div>${data.tasks.map((t) => `<form data-api="/events/${id}/tasks/${t.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(t.title)}">${select('status', ['todo','in_progress','blocked','done','canceled'], t.status)}<input type="date" name="due_date" value="${esc(t.due_date || '')}">${select('priority', ['low','normal','high','urgent'], t.priority)}<input name="description" value="${esc(t.description || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/tasks" data-method="POST" class="row-form"><input name="title" required placeholder="New task"><input name="description" placeholder="Description">${select('status', ['todo','in_progress','blocked','done','canceled'], 'todo')}<input type="date" name="due_date">${select('priority', ['low','normal','high','urgent'], 'normal')}<button>Add task</button></form></section>`;
}

function blockersSection(id, data) {
  return `<section id="blockers" class="panel"><div class="section-head padded"><h2>${terminology.openItemPlural}</h2></div>${data.blockers.map((b) => `<form data-api="/events/${id}/open-items/${b.id}" data-method="PATCH" class="row-form"><label>Item Title<input name="title" value="${esc(b.title)}"></label><label>Status${select('status', ['open','waiting','resolved','canceled'], b.status, { waiting: terminology.waitingOn, resolved: 'Complete' })}</label><label>Due Date<input type="date" name="due_date" value="${esc(b.due_date || '')}"></label><label>Details<input name="description" value="${esc(b.description || '')}"></label><label>${terminology.pointPerson}${userSelect(data.users, b.owner_user_id)}</label><button>Save</button><button type="button" class="secondary" onclick="requestHelp(${id},${b.id})">${terminology.requestHelp}</button><button type="button" class="secondary" onclick="completeOpenItem(${id},${b.id})">${terminology.markComplete}</button></form>`).join('') || `<div class="empty-state"><span class="check">OK</span><p>${terminology.allClear}<br>${terminology.noOutstandingItems}</p></div>`}
  <form data-api="/events/${id}/open-items" data-method="POST" class="row-form"><label>Item Title<input name="title" required placeholder="Waiting on flyer approval"></label><label>Details<input name="description" placeholder="Details"></label><label>${terminology.pointPerson}${userSelect(data.users)}</label><input type="date" name="due_date"><button>Add ${terminology.openItemSingular}</button></form></section>`;
}

function scheduleSection(id, data) {
  return `<section id="schedule" class="panel"><div class="section-head padded"><h2>Run Sheet</h2></div>${data.schedule.map((s) => `<form data-api="/events/${id}/schedule/${s.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(s.title)}">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], s.item_type)}<input type="time" name="start_time" value="${esc(s.start_time || '')}"><input type="time" name="end_time" value="${esc(s.end_time || '')}"><input name="notes" value="${esc(s.notes || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/schedule" data-method="POST" class="row-form"><input name="title" required placeholder="Schedule item">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], 'other')}<input type="time" name="start_time"><input type="time" name="end_time"><input name="notes" placeholder="Notes"><button>Add item</button></form></section>`;
}

function assetsSection(id, data) {
  return `<section id="assets" class="panel"><div class="section-head padded"><h2>Assets</h2></div><div class="asset-grid">${data.assets.map((a) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp)$/i.test(a.filename) ? `<img src="${esc(assetUrl(a.file_path))}" alt="">` : '<span class="asset-thumb">Asset</span>'}<strong>${esc(a.title)}</strong><span>${esc(titleCase(a.asset_type))} - ${esc(titleCase(a.approval_status))}</span><div><button class="small" onclick="approveAsset(${id},${a.id},'approved')">Approve</button> <button class="small secondary" onclick="approveAsset(${id},${a.id},'rejected')">Reject</button></div></article>`).join('')}</div>
  <form id="asset-form" class="row-form"><input name="title" placeholder="Asset title">${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}<input type="file" name="asset" required><input name="notes" placeholder="Notes"><button>Upload asset</button></form></section>`;
}

function settlementSection(id, data) {
  const s = data.settlement || {};
  return `<section id="settlement" class="panel"><div class="section-head padded"><h2>Settlement</h2></div><form data-api="/events/${id}/settlement" data-method="POST" class="row-form">${['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'].map((f) => `<label>${esc(titleCase(f))}<input name="${f}" type="number" step="0.01" value="${esc(s[f] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(s.notes || '')}</textarea></label><button>Save settlement</button></form></section>`;
}

function activitySection(data) {
  return `<section id="activity" class="panel"><div class="section-head padded"><h2>Activity</h2></div><ul class="timeline">${data.activity.map((a) => `<li><strong>${esc(activityLabel(a.action))}</strong> by ${esc(a.user_name || 'system')} <span class="muted">${esc(a.created_at)}</span></li>`).join('')}</ul></section>`;
}

function bindWorkspaceForms(id) {
  $$('form[data-api]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
    renderEvent(id);
  }));
  $('#asset-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    await api(`/events/${id}/assets`, { method: 'POST', body: new FormData(event.target) });
    renderEvent(id);
  });
}

async function approveAsset(eventId, assetId, status) {
  await api(`/events/${eventId}/assets/${assetId}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
  renderEvent(eventId);
}

async function completeOpenItem(eventId, itemId) {
  await updateOpenItemStatus(eventId, itemId, 'resolved');
}

async function requestHelp(eventId, itemId) {
  await updateOpenItemStatus(eventId, itemId, 'waiting');
}

async function updateOpenItemStatus(eventId, itemId, status) {
  const form = $(`form[data-api="/events/${eventId}/open-items/${itemId}"]`);
  const body = formData(form);
  body.status = status;
  await api(`/events/${eventId}/open-items/${itemId}`, { method: 'PATCH', body: JSON.stringify(body) });
  renderEvent(eventId);
}

function activityLabel(action) {
  return ({
    'blocker created': 'open item created',
    'blocker resolved': 'open item completed',
  })[action] || action;
}

async function renderTemplates() {
  const data = await api('/templates');
  app.innerHTML = `<div class="page-head"><div><h1>Templates</h1><p class="subtle">Create repeatable event shells.</p></div></div><section class="pipeline-board">${data.templates.map((t) => `<article class="pipe-card"><h2>${esc(t.name)}</h2><p>${esc(titleCase(t.event_type))}</p><form data-template="${t.id}" class="grid-form compact"><input type="date" name="date" required><input type="time" name="doors_time" value="19:00"><input type="time" name="show_time" value="20:00"><input name="title" value="${esc(t.default_title || t.name)}"><button>Create event</button></form></article>`).join('')}</section>`;
  $$('form[data-template]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const res = await api(`/events/from-template/${form.dataset.template}`, { method: 'POST', body: JSON.stringify(formData(form)) });
    location.hash = `event-${res.id}`;
  }));
}

async function renderTonight() {
  const data = await loadEvents();
  const event = data.events[0];
  const detail = event ? await api(`/events/${event.id}`) : null;
  app.innerHTML = tonightMarkup(event, false, detail);
}

function tonightMarkup(event = {}, hidden = false, detail = null) {
  const tasks = detail?.tasks?.filter((task) => !['done','canceled'].includes(task.status)).slice(0, 2) || [];
  const schedule = detail?.schedule?.slice(0, 4) || [];
  const lineup = detail?.lineup?.slice(0, 3) || [];
  const doneCount = detail?.tasks?.filter((task) => task.status === 'done').length || 0;
  const totalTasks = detail?.tasks?.length || tasks.length;
  return `<section class="tonight-page" ${hidden ? 'aria-hidden="true"' : ''}>
    <div class="tonight-head"><span class="back-arrow"></span><span>Tonight</span></div>
    <article class="today-card">
      <span class="icon-bubble"><span class="icon mic"></span></span>
      <div>
        <h1>Tonight: ${esc(event.title || 'No Event')}</h1>
        <div class="today-meta"><span>${esc(shortDate(eventDate(event)))}</span><span>${esc(event.venue_name || 'The Blackroom')}</span></div>
        <div class="today-status">${badge(event.status || 'empty')}<span><span class="status-dot green"></span>${terminology.openItemPlural}: ${esc(detail?.blockers?.filter((b) => ['open','waiting'].includes(b.status)).length || 'None')}</span></div>
      </div>
    </article>
    <article class="next-action"><span class="icon-bubble"><span class="bolt"></span></span><span><h2>Next Action</h2><p>${esc(detail?.nextAction || 'Confirm projection setup and print signup sheet.')}</p></span><span class="arrow"></span></article>
    <article class="mobile-panel"><h2>Tasks <span class="badge">${doneCount} / ${totalTasks}</span></h2>${(tasks.length ? tasks : [{ title: 'Projection setup' }, { title: 'Print signup sheet' }]).map((task) => `<div class="mobile-row"><span class="mobile-check"></span><span>${esc(task.title)}</span></div>`).join('')}</article>
    <article class="mobile-panel"><h2>Schedule</h2>${(schedule.length ? schedule : [{ start_time: '19:00', title: 'Doors' }, { start_time: '20:00', title: 'Karaoke starts' }, { start_time: '23:30', title: 'Last call' }, { start_time: '00:00', title: 'End' }]).map((item) => `<div class="mobile-row"><span class="mobile-time">${esc(timeLabel(item.start_time).replace(' PM','').replace(' AM',''))}</span><span class="icon-bubble">${esc((item.title || '?').slice(0, 1))}</span><span>${esc(item.title)}</span></div>`).join('')}</article>
    <article class="mobile-panel mobile-kpis">
      ${mobileKpi('Owner', event.owner_name || 'Jenny')}
      ${mobileKpi('Public Page', Number(event.public_visibility) ? 'Live' : 'Hidden')}
      ${mobileKpi('Ticketing', event.ticket_url ? 'Active' : 'RSVP / Free')}
      ${mobileKpi('Flyer', detail && hasFlyer(detail) ? 'Approved' : 'Pending')}
    </article>
    <article class="mobile-panel"><h2>Performer Queue <a class="button secondary small" href="${event.id ? `#event-${event.id}` : '#events'}">View All</a></h2>${(lineup.length ? lineup : [{ display_name: 'The Sparetimes' }, { display_name: 'Neon Plastic' }, { display_name: 'Basement Panic' }]).map((item, i) => `<div class="mobile-row"><span class="icon-bubble">${i + 1}</span><span>${esc(item.display_name)}</span><span class="icon dots"></span></div>`).join('')}</article>
  </section>`;
}

function mobileKpi(label, value) {
  return `<div class="mobile-kpi"><span class="icon-bubble"></span><span>${esc(label)}<strong>${esc(value)}</strong></span></div>`;
}

async function renderPublicEvent() {
  const slug = new URLSearchParams(location.search).get('slug');
  const data = await api(`/public/events/${encodeURIComponent(slug)}`);
  const e = data.event;
  $('#public-event').innerHTML = `<article class="public-event">${data.flyer ? `<img class="public-flyer" src="${esc(assetUrl(data.flyer.file_path))}" alt="">` : ''}<div class="public-copy"><p class="eyebrow">${esc(e.date)} - ${esc(e.venue_name)}</p><h1>${esc(e.title)}</h1><p><strong>Doors</strong> ${esc(e.doors_time || 'TBA')} - <strong>Show</strong> ${esc(e.show_time || 'TBA')}</p><p>${esc(e.age_restriction || 'All ages unless noted')} - ${Number(e.ticket_price) > 0 ? `$${esc(e.ticket_price)}` : 'Free / door'}</p>${e.ticket_url ? `<a class="button" href="${esc(e.ticket_url)}">Tickets</a>` : ''}<p>${esc(e.description_public || '')}</p><h2>Lineup</h2><ul class="plain-list">${data.lineup.map((l) => `<li>${esc(l.display_name)} ${l.set_time ? `<span>${esc(l.set_time)}</span>` : ''}</li>`).join('')}</ul><p class="muted">${esc(e.address)}, ${esc(e.city)}, ${esc(e.state)}</p></div></article>`;
}

async function renderInvite() {
  const token = new URLSearchParams(location.search).get('token');
  const data = await api(`/invite/${encodeURIComponent(token)}`);
  $('#invite').innerHTML = `<h1>Join ${esc(data.invite.event_title)}</h1><p>Invited as <strong>${esc(titleCase(data.invite.role))}</strong> using ${esc(data.invite.email)}.</p><form id="invite-form" class="grid-form"><label>Name <input name="name"></label><label>Password <input type="password" name="password"></label><button>Accept invite</button></form>`;
  $('#invite-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const res = await api(`/invite/${encodeURIComponent(token)}`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
    location.href = appUrl(`#event-${res.event_id}`);
  });
}

init();
