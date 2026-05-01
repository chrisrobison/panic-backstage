let csrf = null;
let currentUser = null;

const $ = (selector, root = document) => root.querySelector(selector);
const app = $('#app');
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const badge = (s) => `<span class="badge status-${esc(s)}">${esc(String(s || '').replaceAll('_', ' '))}</span>`;

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
  if (hash.startsWith('event-')) return renderEvent(Number(hash.slice(6)));
  if (hash === 'events') return renderEvents();
  if (hash === 'templates') return renderTemplates();
  return renderDashboard();
}

async function renderDashboard() {
  const data = await api('/dashboard');
  app.innerHTML = `
    <div class="page-head"><div><h1>Operations Dashboard</h1><p class="muted">Next 14 days, blockers, assets, ticketing, and settlement gaps.</p></div><button onclick="newEvent()">New event</button></div>
    <section class="metric-grid">
      ${metric(data.cards.empty, 'Empty / hold nights')}
      ${metric(data.cards.needsAssets, 'Need flyers/assets')}
      ${metric(data.cards.ready, 'Ready to announce')}
      ${metric(data.cards.blockers, 'Events with blockers', 'danger')}
      ${metric(data.cards.published, 'Upcoming published')}
      ${metric(data.cards.unsettled, 'Completed, unsettled', 'warn')}
    </section>
    <section class="panel"><h2>Next 14 Days</h2>${eventsTable(data.events)}</section>`;
}

function metric(value, label, cls = '') {
  return `<div class="metric ${cls}"><strong>${esc(value)}</strong><span>${esc(label)}</span></div>`;
}

function eventsTable(events) {
  return `<div class="table-wrap"><table><thead><tr><th>Date</th><th>Event</th><th>Type</th><th>Status</th><th>Owner</th><th>Blocked</th><th>Tasks / Assets</th><th></th></tr></thead><tbody>
    ${events.map((event) => `<tr class="${event.primary_blocker ? 'row-blocked' : ''}">
      <td>${esc(event.date)}</td><td><strong>${esc(event.title)}</strong></td><td>${esc(event.event_type.replaceAll('_', ' '))}</td><td>${badge(event.status)}</td>
      <td>${esc(event.owner_name || 'Unassigned')}</td><td>${esc(event.primary_blocker || 'Clear')}</td><td>${esc(event.incomplete_tasks)} open / ${event.approved_flyers > 0 ? 'flyer approved' : 'missing flyer'}</td>
      <td><a href="#event-${event.id}">Open</a></td></tr>`).join('')}
    </tbody></table></div>`;
}

async function renderEvents() {
  const data = await api('/events');
  app.innerHTML = `<div class="page-head"><h1>Events</h1><button onclick="newEvent()">New event</button></div><section class="panel">${eventsTable(data.events)}</section>`;
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
  app.innerHTML = `
    <div class="event-hero"><div><p class="eyebrow">${esc(event.date)} · ${esc(event.event_type.replaceAll('_', ' '))}</p><h1>${esc(event.title)}</h1><div class="status-line">${badge(event.status)}<strong>Next:</strong> ${esc(data.nextAction)}</div></div>
      <div class="actions"><button onclick="editEvent(${id})">Edit</button>${Number(event.public_visibility) ? `<a class="button secondary" href="/event.html?slug=${esc(event.slug)}">Public page</a>` : ''}</div></div>
    <section class="ops-grid">
      <div class="ops-card ${data.blockers.some((b) => ['open','waiting'].includes(b.status)) ? 'danger' : ''}"><strong>${data.blockers.filter((b) => ['open','waiting'].includes(b.status)).length}</strong><span>Open blockers</span></div>
      <div class="ops-card"><strong>${data.tasks.filter((t) => !['done','canceled'].includes(t.status)).length}</strong><span>Incomplete tasks</span></div>
      <div class="ops-card"><strong>${data.assets.some((a) => a.asset_type === 'flyer' && a.approval_status === 'approved') ? 'Ready' : 'Missing'}</strong><span>Approved flyer</span></div>
      <div class="ops-card"><strong>${Number(event.public_visibility) ? 'Live' : 'Internal'}</strong><span>Public page</span></div>
      <div class="ops-card"><strong>${event.ticket_url ? 'Linked' : Number(event.ticket_price) > 0 ? 'Needed' : 'Free/door'}</strong><span>Ticket status</span></div>
    </section>
    <nav class="tabs">${['overview','lineup','tasks','blockers','schedule','assets','settlement','activity'].map((t) => `<a href="#${t}">${t}</a>`).join('')}</nav>
    ${overviewSection(data)}${lineupSection(id, data)}${tasksSection(id, data)}${blockersSection(id, data)}${scheduleSection(id, data)}${assetsSection(id, data)}${settlementSection(id, data)}${activitySection(data)}`;
  bindWorkspaceForms(id);
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
