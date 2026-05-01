let csrf = null;
let currentUser = null;

const $ = (selector, root = document) => root.querySelector(selector);
const app = $('#app');
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const badge = (s) => `<span class="badge status-${esc(s)}">${esc(String(s || '').replaceAll('_', ' '))}</span>`;
const statusDot = (s) => `<span class="status-dot ${esc(s)}"></span>`;
const initials = (name) => esc(String(name || '--').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase());
const avatar = (name) => `<span class="avatar">${initials(name).slice(0, 1)}</span>`;
const fmtDate = (value, options = { weekday: 'short', month: 'short', day: 'numeric' }) => {
  if (!value) return '';
  const date = value instanceof Date ? value : new Date(`${String(value).slice(0, 10)}T12:00:00`);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-US', options);
};
const shortTime = (value) => value ? String(value).slice(0, 5) : 'TBD';

async function api(path, options = {}) {
  const headers = options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const res = await fetch(`/api${path}`, { credentials: 'same-origin', ...options, headers: { ...headers, ...(options.headers || {}) } });
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
    if (!currentUser) location.href = '/login.html';
  } catch {
    location.href = '/login.html';
    return;
  }
  $('#logout')?.addEventListener('click', async () => {
    await api('/logout', { method: 'POST', body: '{}' });
    location.href = '/login.html';
  });
  window.addEventListener('hashchange', route);
  route();
}

function initLogin() {
  $('#login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await api('/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = '/';
    } catch (error) {
      $('#error').textContent = error.message;
    }
  });
}

function route() {
  const hash = location.hash.replace(/^#/, '') || 'dashboard';
  if (['overview','lineup','schedule','tasks','blockers','assets','public-page','settlement','activity'].includes(hash) && document.getElementById(hash)) return;
  setActiveNav(hash.startsWith('event-') ? 'events' : hash);
  if (hash.startsWith('event-')) return renderEvent(Number(hash.slice(6)));
  if (hash === 'events') return renderEvents();
  if (hash === 'templates') return renderTemplates();
  return renderDashboard();
}

function setActiveNav(routeName) {
  document.querySelectorAll('[data-route]').forEach((link) => {
    link.classList.toggle('active', link.dataset.route === routeName);
  });
}

async function renderDashboard() {
  const data = await api('/dashboard');
  const tonight = data.events[0];
  const attention = data.events.filter((event) => event.primary_blocker || Number(event.incomplete_tasks) > 0 || Number(event.approved_flyers) === 0).slice(0, 3);
  app.innerHTML = `
    <div class="page-head"><div><h1>Dashboard</h1><p class="muted">What needs attention</p></div><button onclick="newEvent()">New event</button></div>
    <section class="dashboard-metrics">
      <a class="dashboard-card" href="${tonight ? `#event-${tonight.id}` : '#events'}">
        <span class="round-icon">●</span>
        <span><span class="kicker">Tonight</span><br><strong style="font-size:18px;color:var(--ink);line-height:1.2">${esc(tonight?.title || 'No event')}</strong><span class="micro">${tonight ? `Doors ${esc(shortTime(tonight.doors_time))}<br>Starts ${esc(shortTime(tonight.show_time))}` : 'Program this night'}</span><br><br>${badge(tonight?.status || 'empty')}</span>
      </a>
      ${metricCard('!', 'Open Blockers', data.cards.blockers, `${Math.min(data.cards.blockers, 3)} urgent`, 'red')}
      ${metricCard('▣', 'Empty Nights', data.cards.empty, 'Next empty Tuesday')}
      ${metricCard('▤', 'Needs Flyer', data.cards.needsAssets, 'announce-ready otherwise', 'yellow')}
      ${metricCard('$', 'Unsettled Events', data.cards.unsettled, 'Oldest Apr 22', 'red')}
    </section>
    ${tonightMobile(tonight)}
    <div class="dashboard-main-grid">
      <section class="panel table-panel"><div class="panel-head"><h2>Next 14 Days</h2><a class="button secondary small" href="#events">▣ View Calendar</a></div>${eventsTable(data.events)}</section>
      <section class="panel table-panel"><div class="panel-head"><h2>Needs Attention</h2><a class="button secondary small" href="#events">View All</a></div>
        <div class="attention-list">${attention.map((event, index) => attentionCard(event, index)).join('') || '<p class="muted">No urgent items in the next two weeks.</p>'}</div>
        <a class="panel-link" href="#events">View all blockers & risks ›</a>
      </section>
    </div>`;
}

function metric(value, label, cls = '') {
  return `<div class="metric ${cls}"><strong>${esc(value)}</strong><span>${esc(label)}</span></div>`;
}

function metricCard(icon, label, value, note, color = '') {
  return `<a class="dashboard-card" href="#events"><span class="round-icon ${color}">${esc(icon)}</span><span><span class="kicker">${esc(label)}</span><strong class="value" style="${color === '' ? 'color:#5f6670' : ''}">${esc(value)}</strong><span class="micro">${esc(note)}</span></span></a>`;
}

function attentionCard(event, index = 0) {
  const warn = index === 1;
  return `<a class="attention-card ${warn ? 'warn' : 'danger'}" href="#event-${event.id}">
    <span class="round-icon ${warn ? 'yellow' : 'red'}">${warn ? '!' : (event.primary_blocker ? '♢' : '$')}</span>
    <span><strong>${event.primary_blocker ? 'Blocked: ' : (warn ? 'At Risk: ' : 'Unsettled: ')}${esc(event.title)}</strong><small>${esc(event.primary_blocker || (Number(event.approved_flyers) ? 'settlement missing' : 'no ticket link'))}</small><small>▣ ${esc(fmtDate(event.date))}</small></span>
    <span>›</span>
  </a>`;
}

function tonightMobile(event) {
  if (!event) return '';
  return `<section class="tonight-mobile">
    <article class="mobile-event-card"><span class="round-icon">●</span><div><h2>Tonight: ${esc(event.title)}</h2><p class="muted">▣ ${esc(fmtDate(event.date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }))}</p><p class="muted">▥ The Blackroom</p>${badge(event.status)} <span style="margin-left:18px">${statusDot('advanced')}Blockers: ${event.primary_blocker ? 'Open' : 'None'}</span></div></article>
    <a class="next-action-card" href="#event-${event.id}"><span class="round-icon">ϟ</span><span><strong class="eyebrow" style="color:var(--green)">Next Action</strong><h2>Confirm projection setup and print signup sheet.</h2></span><span>›</span></a>
    <article class="mobile-list-card"><header><h2>Tasks</h2><span class="badge status-advanced">0 / ${Math.max(2, Number(event.incomplete_tasks || 0))}</span></header><div class="item"><span class="fake-check"></span><span>Projection setup</span></div><div class="item"><span class="fake-check"></span><span>Print signup sheet</span></div></article>
    <article class="mobile-list-card"><header><h2>Schedule</h2></header><div class="item"><strong>7:00</strong><span>Doors</span></div><div class="item"><strong>8:00</strong><span>Karaoke starts</span></div><div class="item"><strong>11:30</strong><span>Last call</span></div><div class="item"><strong>12:00</strong><span>End</span></div></article>
    <article class="mobile-stat-strip"><div><span class="round-icon" style="margin:auto">●</span><span class="muted">Owner</span><br><strong>${esc(event.owner_name || 'Jenny')}</strong></div><div><span class="round-icon blue" style="margin:auto">◎</span><span class="muted">Public Page</span><br><strong style="color:var(--blue)">Live</strong></div><div><span class="round-icon red" style="margin:auto">▱</span><span class="muted">Ticketing</span><br><strong style="color:var(--danger)">RSVP / Free</strong></div><div><span class="round-icon green" style="margin:auto">✓</span><span class="muted">Flyer</span><br><strong style="color:var(--green)">Approved</strong></div></article>
  </section>`;
}

function eventsTable(events) {
  return `<div class="table-wrap"><table><thead><tr><th>Date</th><th>Event</th><th>Status</th><th>Main Issue</th><th>Owner</th><th></th></tr></thead><tbody>
    ${events.map((event) => `<tr class="${event.primary_blocker ? 'row-blocked' : ''}">
      <td>${esc(fmtDate(event.date))}</td><td><strong>${esc(event.title)}</strong></td><td>${badge(event.status)}</td>
      <td>${statusDot(event.primary_blocker ? 'hold' : event.status)}${esc(event.primary_blocker || (Number(event.approved_flyers) ? 'Ready' : 'Flyer missing'))}</td><td>${avatar(event.owner_name)}${esc(event.owner_name || 'Unassigned')}</td>
      <td><a href="#event-${event.id}">Open</a></td></tr>`).join('')}
    </tbody></table></div>`;
}

async function renderEvents() {
  const data = await api('/events');
  app.innerHTML = `<div class="page-head"><div><h1>Calendar & Pipeline</h1><p class="muted">See coverage and move events through the show pipeline.</p></div><button onclick="newEvent()">Add Event</button></div>
    ${eventsFilters(data)}
    ${calendarView(data.events)}
    ${pipelineView(data.events)}`;
}

function eventsFilters(data) {
  return `<form class="filters-bar" onsubmit="return false">
    <label class="filter-control"><span>▣</span><input type="date"><span>–</span><input type="date"></label>
    <label class="filter-control">Event Type <select><option>All types</option>${data.types.map((t) => `<option>${esc(t.replaceAll('_', ' '))}</option>`).join('')}</select></label>
    <label class="filter-control">Owner <select><option>All owners</option>${data.users.map((u) => `<option>${esc(u.name)}</option>`).join('')}</select></label>
    <label class="filter-control">Status <select><option>All statuses</option>${data.statuses.map((s) => `<option>${esc(s.replaceAll('_', ' '))}</option>`).join('')}</select></label>
    <button class="secondary">☷ Clear Filters</button>
  </form>`;
}

function calendarView(events) {
  const byDay = events.reduce((acc, event) => {
    const key = String(event.date || '').slice(0, 10);
    acc[key] = acc[key] || [];
    acc[key].push(event);
    return acc;
  }, {});
  const now = new Date();
  const first = new Date(now.getFullYear(), now.getMonth(), 1);
  const start = new Date(first);
  start.setDate(first.getDate() - first.getDay());
  const cells = Array.from({ length: 35 }, (_, i) => {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    return d;
  });
  return `<section class="calendar-shell">
    <div class="calendar-toolbar"><div class="segmented"><a href="#events">‹</a><a href="#events">›</a><a href="#events">Today</a></div><div class="calendar-title">${esc(now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }))} ⌄</div><div class="segmented"><span class="active">Month</span><span>Week</span><a href="javascript:newEvent()">+ Add Event</a></div></div>
    <div class="calendar-grid">
      ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<div class="calendar-day-name">${day}</div>`).join('')}
      ${cells.map((day) => {
        const key = day.toISOString().slice(0, 10);
        const dayEvents = byDay[key] || [];
        return `<div class="calendar-cell"><div class="date">${day.getMonth() === now.getMonth() ? day.getDate() : fmtDate(day, { month: 'short', day: 'numeric' })}</div>${dayEvents.length ? dayEvents.slice(0, 2).map((event) => `<a class="calendar-event" href="#event-${event.id}">${statusDot(event.status)}${esc(event.title)}<br>${badge(event.status)}</a>`).join('') : '<a class="program-night" href="javascript:newEvent()">+ Program This Night</a>'}</div>`;
      }).join('')}
    </div>
  </section>`;
}

function pipelineView(events) {
  const groups = [['Empty','empty'], ['Proposed','proposed'], ['Hold','hold'], ['Confirmed','confirmed'], ['Needs Assets','needs_assets'], ['Ready to Announce','ready_to_announce'], ['Published','published'], ['Advanced','advanced']];
  return `<section class="pipeline-board">${groups.map(([label, status]) => {
    const list = events.filter((event) => event.status === status);
    return `<div class="kanban-column"><header><span>${esc(label)}</span><span class="count-pill">${list.length}</span></header>${list.slice(0, 3).map((event) => `<a class="pipeline-card" href="#event-${event.id}"><strong>${esc(event.title)}</strong><small>${esc(fmtDate(event.date))}</small><small>${avatar(event.owner_name)}${esc(event.owner_name || 'Unassigned')}</small><small>◎ 0 blockers &nbsp; ◴ ${Number(event.public_visibility) ? '2' : '1'} tasks</small></a>`).join('')}<a class="muted" href="javascript:newEvent()">+ Add card</a></div>`;
  }).join('')}</section>`;
}

async function newEvent() {
  const data = await api('/events');
  app.innerHTML = `<div class="page-head"><h1>New Event</h1><a href="#events">Back</a></div><section class="panel">${eventForm({}, data)}</section>`;
  $('#event-form').addEventListener('submit', saveEvent);
}

function eventForm(event, data) {
  const statuses = data.statuses || ['proposed','hold','confirmed','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled'];
  const types = data.types || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
  const option = (value, selected, label = value) => `<option value="${esc(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${esc(String(label).replaceAll('_', ' '))}</option>`;
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
  const openBlockers = data.blockers.filter((b) => ['open','waiting'].includes(b.status));
  const incompleteTasks = data.tasks.filter((t) => !['done','canceled'].includes(t.status));
  const approvedFlyer = data.assets.find((a) => a.asset_type === 'flyer' && a.approval_status === 'approved');
  app.innerHTML = `
    <div class="workspace-title">
      <div><a class="back-link" href="#events">← Back to Events</a><h1>${esc(event.title)} Night</h1><p class="muted">Event Workspace</p></div>
      <div class="actions"><button class="secondary" onclick="editEvent(${id})">More Actions⌄</button><button onclick="editEvent(${id})">↗ Edit Event</button></div>
    </div>
    <nav class="workspace-tabs">${['overview','lineup','schedule','tasks','blockers','assets','public page','settlement','activity'].map((t, index) => `<a class="${index === 0 ? 'active' : ''}" href="#${t.replace(' ', '-')}">${esc(t.split(' ').map((p) => p[0].toUpperCase() + p.slice(1)).join(' '))}</a>`).join('')}</nav>
    <section class="workspace-panel hero-panel">
      <div class="flyer-preview">${esc(event.title).replace(/\s+/g, '<br>')}</div>
      <div class="hero-details">
        <div class="detail-tile"><span>▣ Date</span><strong>${esc(fmtDate(event.date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }))}</strong></div>
        <div class="detail-tile"><span>▥ Doors</span><strong>${esc(shortTime(event.doors_time))}</strong></div>
        <div class="detail-tile"><span>◷ Show</span><strong>${esc(shortTime(event.show_time))}</strong></div>
        <div class="detail-tile"><span>♙ Age</span><strong>${esc(event.age_restriction || '-')}</strong></div>
        <div class="detail-tile"><span>☷ Status</span>${badge(event.status)}</div>
        <div class="detail-tile"><span>Owner</span><strong>${avatar(event.owner_name)}${esc(event.owner_name || 'Unassigned')}</strong></div>
        <div class="detail-tile"><span>◎ Public Page</span><strong>${statusDot('advanced')}${Number(event.public_visibility) ? 'Live' : 'Internal'}</strong><br>${Number(event.public_visibility) ? `<a class="muted" href="/event.html?slug=${esc(event.slug)}">View Page ↗</a>` : ''}</div>
        <div class="detail-tile"><span>▱ Tickets</span><strong>${esc(event.ticket_url ? 'Linked' : Number(event.ticket_price) > 0 ? `$${event.ticket_price}` : 'RSVP / Free')}</strong></div>
      </div>
      <div class="detail-side">
        <a href="#blockers"><span class="round-icon red" style="float:left;margin-right:12px">♢</span><span class="muted">Blockers</span><br><strong style="color:var(--danger);font-size:24px">${openBlockers.length}</strong><br><small>View</small></a>
        <a href="#tasks"><span class="round-icon yellow" style="float:left;margin-right:12px">▤</span><span class="muted">Tasks Left</span><br><strong style="color:var(--yellow);font-size:24px">${incompleteTasks.length}</strong><br><small>View</small></a>
      </div>
    </section>
    <section class="recommendation"><span class="round-icon yellow">!</span><span style="flex:1"><strong>Next Recommended Action</strong><br>${esc(data.nextAction)}</span><a class="button secondary small" href="#tasks">Mark as Complete</a></section>
    ${workspaceOverview(data, openBlockers, incompleteTasks, approvedFlyer)}
    ${overviewSection(data)}${lineupSection(id, data)}${tasksSection(id, data)}${blockersSection(id, data)}${scheduleSection(id, data)}${assetsSection(id, data)}${settlementSection(id, data)}${activitySection(data)}`;
  bindWorkspaceForms(id);
}

function workspaceOverview(data, openBlockers, incompleteTasks, approvedFlyer) {
  const event = data.event;
  return `<div class="workspace-grid">
    <div>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:10px"><h2>Event Health</h2><a class="button secondary small" href="#overview">View Details</a></div>
        <div class="health-row">
          <div class="health-item"><span class="check-dot">✓</span><span>Flyer<br><small>${approvedFlyer ? 'Approved' : 'Missing'}</small></span></div>
          <div class="health-item"><span class="check-dot">✓</span><span>Ticketing<br><small>${event.ticket_url ? 'Active' : 'RSVP / Free'}</small></span></div>
          <div class="health-item"><span class="check-dot">✓</span><span>Lineup<br><small>${data.lineup.length ? 'Confirmed' : 'Needed'}</small></span></div>
          <div class="health-item"><span class="check-dot warn">!</span><span>Schedule<br><small>${data.schedule.length ? 'Ready' : 'Missing soundcheck'}</small></span></div>
          <div class="health-item"><span class="check-dot">✓</span><span>Staffing<br><small>Confirmed</small></span></div>
          <div class="health-item"><span class="check-dot gray">•</span><span>Settlement<br><small>${data.settlement ? 'Started' : 'Not started'}</small></span></div>
        </div>
      </section>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Incomplete Tasks</h2><a class="button secondary small" href="#tasks">View All Tasks</a></div><div class="compact-list">${incompleteTasks.slice(0, 4).map((task) => `<div class="compact-row"><span class="fake-check"></span><span>${statusDot('needs_assets')}${esc(task.title)}</span><span>${avatar(task.assigned_name)}${esc(task.assigned_name || 'Unassigned')}</span><span class="error-text">${esc(task.due_date ? fmtDate(task.due_date) : '')}</span></div>`).join('') || '<p class="muted">No open tasks.</p>'}</div></section>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Schedule / Run of Show</h2><a class="button secondary small" href="#schedule">View Full Schedule</a></div><div class="compact-list">${data.schedule.slice(0, 6).map((item) => `<div class="compact-row"><strong>${esc(shortTime(item.start_time))}</strong><span>${statusDot(item.item_type === 'curfew' ? 'hold' : item.item_type === 'doors' ? 'needs_assets' : 'published')}${esc(item.title)}</span><span class="muted">${esc(item.notes || '')}</span><span class="muted">${esc(item.item_type.replaceAll('_',' '))}</span></div>`).join('') || '<p class="muted">No schedule items yet.</p>'}</div></section>
    </div>
    <aside>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Open Blockers</h2><a class="button secondary small" href="#blockers">View All</a></div>${openBlockers.length ? openBlockers.slice(0, 3).map((blocker) => `<a class="attention-card danger" style="margin-bottom:10px" href="#blockers"><span class="round-icon red">♢</span><span><strong>${esc(blocker.title)}</strong><small>${esc(blocker.description || 'Needs owner follow-up')}</small></span><span>›</span></a>`).join('') : '<p style="text-align:center;padding:24px"><span class="round-icon green" style="margin:auto">✓</span><br>All clear!<br><span class="muted">No open blockers for this event.</span></p>'}</section>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Performer Queue</h2><a class="button secondary small" href="#lineup">View Full Lineup</a></div>${data.lineup.slice(0, 5).map((item, index) => `<div class="queue-row"><span>${index + 1}</span>${avatar(item.display_name || item.band_name)}<span>${esc(item.display_name || item.band_name || 'Unnamed')}</span><span>${esc(shortTime(item.set_time))}</span></div>`).join('') || '<p class="muted">No performers added yet.</p>'}</section>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Assets</h2><a class="button secondary small" href="#assets">View All Assets</a></div><div class="asset-inline"><div class="mini-flyer">${esc(event.title).replace(/\s+/g, '<br>')}</div><span><strong>Flyer - ${esc(event.title)}</strong><br><small>${esc(approvedFlyer ? approvedFlyer.filename : 'PNG - 1080 x 1350')}</small><br><small>${approvedFlyer ? 'Approved' : 'Needs review'}</small></span><a class="button secondary small" href="#assets">↓</a></div></section>
      <section class="workspace-panel"><div class="panel-head" style="padding:0 0 12px;margin-bottom:8px"><h2>Internal Notes</h2><button class="secondary small" onclick="editEvent(${event.id})">Edit</button></div><p class="notes">${esc(event.description_internal || 'KJ brings laptop with Karafun and backup playlist. Venue provides projector and two vocal mics. Keep signup list printed at the door.')}</p></section>
    </aside>
  </div>`;
}

async function editEvent(id) {
  const data = await api(`/events/${id}`);
  app.innerHTML = `<div class="page-head"><h1>Edit Event</h1><a href="#event-${id}">Back</a></div><section class="panel">${eventForm(data.event, data)}</section>`;
  $('#event-form').addEventListener('submit', saveEvent);
}

function overviewSection(data) {
  const e = data.event;
  return `<section id="overview" class="panel"><h2>Overview</h2><div class="detail-grid">
    <p><strong>Venue</strong><br>${esc(e.venue_name)}</p><p><strong>Owner</strong><br>${esc(e.owner_name || 'Unassigned')}</p>
    <p><strong>Doors / Show / End</strong><br>${esc(e.doors_time || '-')} / ${esc(e.show_time || '-')} / ${esc(e.end_time || '-')}</p><p><strong>Tickets</strong><br>$${esc(e.ticket_price || 0)}</p></div>
    <h3>Public copy</h3><p>${esc(e.description_public || 'No public copy yet.')}</p><h3>Internal notes</h3><p>${esc(e.description_internal || 'No internal notes yet.')}</p></section>`;
}

function select(name, options, selected) {
  return `<select name="${name}">${options.map((o) => `<option value="${esc(o)}" ${o === selected ? 'selected' : ''}>${esc(o.replaceAll('_', ' '))}</option>`).join('')}</select>`;
}

function lineupSection(id, data) {
  return `<section id="lineup" class="panel"><h2>Lineup</h2>${data.lineup.map((x) => `<form data-api="/events/${id}/lineup/${x.id}" data-method="PATCH" class="row-form"><input name="billing_order" type="number" value="${esc(x.billing_order)}"><input name="display_name" value="${esc(x.display_name)}"><input name="set_time" type="time" value="${esc(x.set_time || '')}"><input name="set_length_minutes" type="number" value="${esc(x.set_length_minutes || '')}">${select('status', ['invited','tentative','confirmed','canceled'], x.status)}<input name="payout_terms" value="${esc(x.payout_terms || '')}"><input name="notes" value="${esc(x.notes || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/lineup" data-method="POST" class="grid-form compact"><input name="band_name" placeholder="Band/artist"><input name="display_name" placeholder="Display name"><input name="billing_order" type="number" placeholder="Order"><input name="set_time" type="time"><input name="set_length_minutes" type="number" placeholder="Minutes">${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}<input name="payout_terms" placeholder="Payout"><input name="notes" placeholder="Notes"><button>Add lineup</button></form></section>`;
}

function tasksSection(id, data) {
  return `<section id="tasks" class="panel"><h2>Tasks</h2>${data.tasks.map((t) => `<form data-api="/events/${id}/tasks/${t.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(t.title)}">${select('status', ['todo','in_progress','blocked','done','canceled'], t.status)}<input type="date" name="due_date" value="${esc(t.due_date || '')}">${select('priority', ['low','normal','high','urgent'], t.priority)}<input name="description" value="${esc(t.description || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/tasks" data-method="POST" class="grid-form compact"><input name="title" required placeholder="New task"><input name="description" placeholder="Description">${select('status', ['todo','in_progress','blocked','done','canceled'], 'todo')}<input type="date" name="due_date">${select('priority', ['low','normal','high','urgent'], 'normal')}<button>Add task</button></form></section>`;
}

function blockersSection(id, data) {
  return `<section id="blockers" class="panel"><h2>Blockers</h2>${data.blockers.map((b) => `<form data-api="/events/${id}/blockers/${b.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(b.title)}">${select('status', ['open','waiting','resolved','canceled'], b.status)}<input type="date" name="due_date" value="${esc(b.due_date || '')}"><input name="description" value="${esc(b.description || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/blockers" data-method="POST" class="grid-form compact"><input name="title" required placeholder="New blocker"><input name="description" placeholder="Description"><input type="date" name="due_date"><button>Add blocker</button></form></section>`;
}

function scheduleSection(id, data) {
  return `<section id="schedule" class="panel"><h2>Run Sheet</h2>${data.schedule.map((s) => `<form data-api="/events/${id}/schedule/${s.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(s.title)}">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], s.item_type)}<input type="time" name="start_time" value="${esc(s.start_time || '')}"><input type="time" name="end_time" value="${esc(s.end_time || '')}"><input name="notes" value="${esc(s.notes || '')}"><button>Save</button></form>`).join('')}
  <form data-api="/events/${id}/schedule" data-method="POST" class="grid-form compact"><input name="title" required placeholder="Schedule item">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], 'other')}<input type="time" name="start_time"><input type="time" name="end_time"><input name="notes" placeholder="Notes"><button>Add item</button></form></section>`;
}

function assetsSection(id, data) {
  return `<section id="assets" class="panel"><h2>Assets</h2><div class="asset-grid">${data.assets.map((a) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp)$/i.test(a.filename) ? `<img src="${esc(a.file_path)}" alt="">` : ''}<strong>${esc(a.title)}</strong><span>${esc(a.asset_type)} · ${esc(a.approval_status)}</span><button class="small" onclick="approveAsset(${id},${a.id},'approved')">Approve</button><button class="small secondary" onclick="approveAsset(${id},${a.id},'rejected')">Reject</button></article>`).join('')}</div>
  <form id="asset-form" class="grid-form compact"><input name="title" placeholder="Asset title">${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}<input type="file" name="asset" required><input name="notes" placeholder="Notes"><button>Upload asset</button></form></section>`;
}

function settlementSection(id, data) {
  const s = data.settlement || {};
  return `<section id="settlement" class="panel"><h2>Settlement</h2><form data-api="/events/${id}/settlement" data-method="POST" class="grid-form">${['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'].map((f) => `<label>${f.replaceAll('_',' ')}<input name="${f}" type="number" step="0.01" value="${esc(s[f] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(s.notes || '')}</textarea></label><button>Save settlement</button></form></section>`;
}

function activitySection(data) {
  return `<section id="activity" class="panel"><h2>Activity</h2><ul class="timeline">${data.activity.map((a) => `<li><strong>${esc(a.action)}</strong> by ${esc(a.user_name || 'system')} <span>${esc(a.created_at)}</span></li>`).join('')}</ul></section>`;
}

function bindWorkspaceForms(id) {
  document.querySelectorAll('form[data-api]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
    renderEvent(id);
  }));
  $('#asset-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = new FormData(event.target);
    await api(`/events/${id}/assets`, { method: 'POST', body: fd });
    renderEvent(id);
  });
}

async function approveAsset(eventId, assetId, status) {
  await api(`/events/${eventId}/assets/${assetId}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
  renderEvent(eventId);
}

async function renderTemplates() {
  const data = await api('/templates');
  app.innerHTML = `<div class="page-head"><h1>Templates</h1></div><section class="card-grid">${data.templates.map((t) => `<article class="panel"><h2>${esc(t.name)}</h2><p>${esc(t.event_type.replaceAll('_',' '))}</p><form data-template="${t.id}" class="grid-form compact"><input type="date" name="date" required><input type="time" name="doors_time" value="19:00"><input type="time" name="show_time" value="20:00"><input name="title" value="${esc(t.default_title || t.name)}"><button>Create event</button></form></article>`).join('')}</section>`;
  document.querySelectorAll('form[data-template]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const res = await api(`/events/from-template/${form.dataset.template}`, { method: 'POST', body: JSON.stringify(formData(form)) });
    location.hash = `event-${res.id}`;
  }));
}

async function renderPublicEvent() {
  const slug = new URLSearchParams(location.search).get('slug');
  const data = await api(`/public/events/${encodeURIComponent(slug)}`);
  const e = data.event;
  $('#public-event').innerHTML = `<article class="public-event">${data.flyer ? `<img class="public-flyer" src="${esc(data.flyer.file_path)}" alt="">` : ''}<div class="public-copy"><p class="eyebrow">${esc(e.date)} · ${esc(e.venue_name)}</p><h1>${esc(e.title)}</h1><p><strong>Doors</strong> ${esc(e.doors_time || 'TBA')} · <strong>Show</strong> ${esc(e.show_time || 'TBA')}</p><p>${esc(e.age_restriction || 'All ages unless noted')} · ${Number(e.ticket_price) > 0 ? `$${esc(e.ticket_price)}` : 'Free / door'}</p>${e.ticket_url ? `<a class="button" href="${esc(e.ticket_url)}">Tickets</a>` : ''}<p>${esc(e.description_public || '')}</p><h2>Lineup</h2><ul class="plain-list">${data.lineup.map((l) => `<li>${esc(l.display_name)} ${l.set_time ? `<span>${esc(l.set_time)}</span>` : ''}</li>`).join('')}</ul><p class="muted">${esc(e.address)}, ${esc(e.city)}, ${esc(e.state)}</p></div></article>`;
}

async function renderInvite() {
  const token = new URLSearchParams(location.search).get('token');
  const data = await api(`/invite/${encodeURIComponent(token)}`);
  $('#invite').innerHTML = `<h1>Join ${esc(data.invite.event_title)}</h1><p>Invited as <strong>${esc(data.invite.role.replaceAll('_',' '))}</strong> using ${esc(data.invite.email)}.</p><form id="invite-form" class="grid-form"><label>Name <input name="name"></label><label>Password <input type="password" name="password"></label><button>Accept invite</button></form>`;
  $('#invite-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const res = await api(`/invite/${encodeURIComponent(token)}`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
    location.href = `/#event-${res.event_id}`;
  });
}

init();
