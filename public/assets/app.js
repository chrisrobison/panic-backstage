let csrf = null;

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const titleCase = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
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
  const headers = options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const response = await fetch(apiUrl(path), { credentials: 'same-origin', ...options, headers: { ...headers, ...(options.headers || {}) } });
  const body = response.status === 204 ? null : await response.json().catch(() => null);
  if (!response.ok) {
    const message = body?.error || `Request failed: ${response.status}`;
    publish('api.error', { message, path });
    throw new Error(message);
  }
  if (body?.csrf) csrf = body.csrf;
  return body;
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

function emptyState(message) {
  return `<div class="empty-state">${esc(message)}</div>`;
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
  connect() {
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      <p class="muted">Mabuhay Gardens demo workspace</p>
      <form class="stack">
        <label>Email <input type="email" name="email" value="admin@mabuhay.local" required autofocus></label>
        <label>Password <input type="password" name="password" value="changeme" required></label>
        <button>Login</button>
        <p class="error-text" data-error></p>
      </form>
    </main>
    <pb-toast-stack></pb-toast-stack>`;
    $('form', this).addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        const body = await api('/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
        publish('auth.changed', body);
        location.href = appUrl();
      } catch (error) {
        $('[data-error]', this).textContent = error.message;
      }
    });
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
      csrf = me.csrf;
      this.user = me.user;
      publish('auth.changed', me);
      if (!this.user) {
        location.href = appUrl('login.html');
        return;
      }
      await this.route();
    } catch {
      location.href = appUrl('login.html');
    }
  }

  renderShell() {
    this.innerHTML = `<aside class="sidebar">
      <a class="brand" href="#dashboard" aria-label="Panic Backstage home"><span class="brand-mark" aria-hidden="true"></span><span>Panic Backstage</span></a>
      <nav class="side-nav" aria-label="Main navigation">
        <a data-nav="dashboard" href="#dashboard"><span class="icon gauge"></span>Dashboard</a>
        <a data-nav="calendar" href="#calendar"><span class="icon calendar"></span>Calendar</a>
        <a data-nav="pipeline" href="#pipeline"><span class="icon pipeline"></span>Pipeline</a>
        <a data-nav="events" href="#events"><span class="icon ticket"></span>Events</a>
        <a data-nav="templates" href="#templates"><span class="icon doc"></span>Templates</a>
      </nav>
      <div class="side-card"><span class="bolt"></span><strong>Good shows.<br><span>No surprises.</span></strong></div>
      <button class="venue-switch" type="button"><span class="icon building"></span>Mabuhay Gardens</button>
      <p class="copyright">&copy; 2026 Panic Backstage</p>
    </aside>
    <header class="topbar">
      <a class="mobile-brand" href="#dashboard"><span class="brand-mark"></span><span>Panic Backstage</span></a>
      <label class="search"><span class="icon search-icon"></span><input data-search placeholder="Search events..." aria-label="Search events"></label>
      <span class="session-pill">Mabuhay Gardens demo</span>
      <button id="logout" class="logout">Logout</button>
    </header>
    <main id="app" class="workspace"><pb-loading-state></pb-loading-state></main>
    <footer class="app-footer"><span></span><strong><span class="bolt small-bolt"></span>Built for venues. Run by humans.</strong><span>Demo-ready local and staging paths</span></footer>
    <nav class="mobile-tabs" aria-label="Mobile navigation">
      <a data-nav="dashboard" href="#dashboard"><span class="icon pie"></span>Dashboard</a>
      <a data-nav="calendar" href="#calendar"><span class="icon calendar"></span>Calendar</a>
      <a data-nav="pipeline" href="#pipeline"><span class="icon pipeline"></span>Pipeline</a>
      <a data-nav="events" href="#events"><span class="icon ticket"></span>Events</a>
      <a data-nav="templates" href="#templates"><span class="icon doc"></span>Templates</a>
    </nav>
    <pb-toast-stack></pb-toast-stack>`;
    $('#logout', this).addEventListener('click', async () => {
      await api('/logout', { method: 'POST', body: '{}' });
      location.href = appUrl('login.html');
    });
    $('[data-search]', this).addEventListener('input', (event) => publish('events.search', { query: event.target.value }));
  }

  async route() {
    const route = location.hash.replace(/^#/, '') || 'dashboard';
    publish('app.route.changed', { route });
    $$('[data-nav]', this).forEach((link) => link.classList.toggle('active', route.startsWith(link.dataset.nav) || (route.startsWith('event-') && link.dataset.nav === 'events')));
    const outlet = $('#app', this);
    if (route.startsWith('event-')) return this.mount(outlet, 'pb-event-workspace', { eventId: Number(route.slice(6)) });
    if (route === 'calendar') return this.mount(outlet, 'pb-event-calendar');
    if (route === 'pipeline') return this.mount(outlet, 'pb-pipeline-board');
    if (route === 'events') return this.mount(outlet, 'pb-events-list');
    if (route === 'templates') return this.mount(outlet, 'pb-template-picker');
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
      this.render(dashboard, events.events || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(dashboard, allEvents) {
    const events = dashboard.events?.length ? dashboard.events : allEvents.slice(0, 8);
    const today = events[0] || allEvents[0] || {};
    const attention = events.filter((event) => event.primary_blocker || Number(event.open_items) || (!Number(event.approved_flyers) && ['confirmed', 'needs_assets', 'ready_to_announce'].includes(event.status))).slice(0, 4);
    const oldest = dashboard.highlights?.oldest_unsettled;
    this.innerHTML = `<section class="page-head">
      <div><h1>Dashboard</h1><p class="subtle">Mabuhay Gardens show operations for the next two weeks.</p></div>
      <a class="button" href="#templates">Create From Template</a>
    </section>
    <section class="metric-grid">
      <article class="metric-card"><span class="icon-bubble"><span class="icon mic"></span></span><h3>Next Show<br>${esc(today.title || 'No event')}</h3><p>Doors ${esc(timeLabel(today.doors_time))}<br>Starts ${esc(timeLabel(today.show_time))}</p>${badge(today.status || 'empty')}</article>
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
    return `<article class="metric-card ${esc(tone)}"><span class="icon-bubble ${esc(tone)}">${symbol ? esc(symbol) : '<span class="icon calendar"></span>'}</span><h3>${esc(label)}</h3><strong>${esc(value)}</strong><p>${esc(note)}</p></article>`;
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
        return `<article class="pipe-col"><h3>${esc(titleCase(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => `<a class="pipe-card" href="#event-${esc(event.id)}"><strong>${esc(event.title)}</strong><span>${esc(shortDate(eventDate(event)))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small></a>`).join('') || '<small>No events</small>'}</article>`;
      }).join('')}</section>
    </section>`;
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
    this.innerHTML = `<div class="page-head"><div><h1>Events</h1><p class="subtle">Search, open, and advance every show.</p></div><a class="button" href="#templates">Create Event</a></div><article class="panel">${table(events)}</article>`;
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
    this.innerHTML = `<section class="event-top">
      <div><a class="back-link" href="#events">&lt;- Back to Events</a><h1>${esc(event.title)}</h1><p class="subtle">${esc(shortDate(eventDate(event)))} at ${esc(event.venue_name)}</p></div>
      <div class="event-actions">
        <a class="button secondary" href="${esc(appUrl(data.links.public_page))}" target="_blank" rel="noreferrer">Public Page</a>
        <button class="danger" data-publish>${Number(event.public_visibility) ? 'Hide Public Page' : 'Publish Public Page'}</button>
      </div>
    </section>
    <nav class="workspace-tabs tabs">${['overview','lineup','schedule','tasks','open-items','assets','settlement','activity'].map((tab, index) => `<a class="${index === 0 ? 'active' : ''}" href="#${tab}">${esc(titleCase(tab))}</a>`).join('')}</nav>
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
    <pb-lineup-editor id="lineup"></pb-lineup-editor>
    <pb-run-sheet id="schedule"></pb-run-sheet>
    <pb-open-items id="open-items"></pb-open-items>
    <pb-asset-manager id="assets"></pb-asset-manager>
    <pb-settlement-form id="settlement"></pb-settlement-form>
    <section id="activity" class="panel"><div class="section-head padded"><h2>Activity</h2></div><ul class="timeline">${data.activity.map((entry) => `<li><strong>${esc(entry.action)}</strong> by ${esc(entry.user_name || 'system')} <span class="muted">${esc(entry.created_at)}</span></li>`).join('')}</ul></section>`;
    $('pb-lineup-editor', this).data = data;
    $('pb-run-sheet', this).data = data;
    $('pb-open-items', this).data = data;
    $('pb-asset-manager', this).data = data;
    $('pb-settlement-form', this).data = data;
    $('[data-publish]', this).addEventListener('click', () => this.togglePublic());
    $('[data-next-action]', this).addEventListener('click', () => this.load());
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

class LineupEditor extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const lineup = data.lineup || [];
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Lineup</h2></div>${lineup.map((item) => `<form data-api="/events/${data.event.id}/lineup/${item.id}" data-method="PATCH" class="row-form"><input name="billing_order" type="number" value="${esc(item.billing_order)}"><input name="display_name" value="${esc(item.display_name)}"><input name="set_time" type="time" value="${esc(item.set_time || '')}"><input name="set_length_minutes" type="number" value="${esc(item.set_length_minutes || '')}">${select('status', ['invited','tentative','confirmed','canceled'], item.status)}<input name="payout_terms" value="${esc(item.payout_terms || '')}"><input name="notes" value="${esc(item.notes || '')}"><button>Save</button></form>`).join('')}
    <form data-api="/events/${data.event.id}/lineup" data-method="POST" class="row-form"><input name="band_name" placeholder="Band/artist"><input name="display_name" placeholder="Display name"><input name="billing_order" type="number" placeholder="Order"><input name="set_time" type="time"><input name="set_length_minutes" type="number" placeholder="Minutes">${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}<input name="payout_terms" placeholder="Payout"><button>Add lineup</button></form></section>`;
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Run Sheet</h2></div>${schedule.map((item) => `<form data-api="/events/${data.event.id}/schedule/${item.id}" data-method="PATCH" class="row-form"><input name="title" value="${esc(item.title)}">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], item.item_type)}<input type="time" name="start_time" value="${esc(item.start_time || '')}"><input type="time" name="end_time" value="${esc(item.end_time || '')}"><input name="notes" value="${esc(item.notes || '')}"><button>Save</button></form>`).join('')}
    <form data-api="/events/${data.event.id}/schedule" data-method="POST" class="row-form"><input name="title" required placeholder="Schedule item">${select('item_type', ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'], 'other')}<input type="time" name="start_time"><input type="time" name="end_time"><input name="notes" placeholder="Notes"><button>Add item</button></form></section>`;
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
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Open Items</h2></div>${items.map((item) => `<form data-api="/events/${data.event.id}/open-items/${item.id}" data-method="PATCH" class="row-form"><label>Item<input name="title" value="${esc(item.title)}"></label><label>Status${select('status', ['open','waiting','resolved','canceled'], item.status)}</label><label>Due<input type="date" name="due_date" value="${esc(item.due_date || '')}"></label><label>Details<input name="description" value="${esc(item.description || '')}"></label><input type="hidden" name="owner_user_id" value="${esc(item.owner_user_id || '')}"><button>Save</button><button type="button" class="secondary" data-resolve="${esc(item.id)}">Mark Complete</button></form>`).join('') || emptyState('No open items for this event.')}
    <form data-api="/events/${data.event.id}/open-items" data-method="POST" class="row-form"><label>Item<input name="title" required placeholder="Waiting on ticket link"></label><label>Details<input name="description" placeholder="Details"></label><input type="hidden" name="status" value="open"><input type="date" name="due_date"><button>Add open item</button></form></section>`;
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

class AssetManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const assets = data.assets || [];
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Assets</h2></div><div class="asset-grid">${assets.map((asset) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp|svg)$/i.test(asset.filename) ? `<img src="${esc(assetUrl(asset.file_path))}" alt="">` : '<span class="asset-thumb">PDF</span>'}<strong>${esc(asset.title)}</strong><span>${esc(titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span><div><button class="small" data-approve="${esc(asset.id)}">Approve</button> <button class="small secondary" data-reject="${esc(asset.id)}">Reject</button></div></article>`).join('') || emptyState('No assets uploaded yet.')}</div>
    <form id="asset-form" class="row-form"><input name="title" placeholder="Asset title">${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}<input type="file" name="asset" accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,.pdf" required><input name="notes" placeholder="Notes"><button>Upload asset</button></form></section>`;
    this.bind();
  }

  bind() {
    $('#asset-form', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(`/events/${this.eventData.event.id}/assets`, { method: 'POST', body: new FormData(event.target) });
      publish('event.assetUploaded', { id: this.eventData.event.id });
      publish('toast.show', { message: 'Asset uploaded.' });
    });
    $$('[data-approve],[data-reject]', this).forEach((button) => button.addEventListener('click', async () => {
      const status = button.dataset.approve ? 'approved' : 'rejected';
      await api(`/events/${this.eventData.event.id}/assets/${button.dataset.approve || button.dataset.reject}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
      publish('event.assetUploaded', { id: this.eventData.event.id });
      publish('toast.show', { message: `Asset ${status}.` });
    }));
  }
}

class SettlementForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const settlement = data.settlement || {};
    const fields = ['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'];
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Settlement</h2></div><form class="row-form">${fields.map((field) => `<label>${esc(titleCase(field))}<input name="${esc(field)}" type="number" step="0.01" value="${esc(settlement[field] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(settlement.notes || '')}</textarea></label><button>Save settlement</button></form></section>`;
    $('form', this).addEventListener('submit', async (event) => {
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
      this.innerHTML = `<main class="auth-card"><h1>Join ${esc(data.invite.event_title)}</h1><p>Invited as <strong>${esc(titleCase(data.invite.role))}</strong> using ${esc(data.invite.email)}.</p><form class="grid-form"><label>Name <input name="name" required></label><label>Password <input type="password" name="password" required></label><button>Accept Invite</button></form></main><pb-toast-stack></pb-toast-stack>`;
      $('form', this).addEventListener('submit', async (event) => {
        event.preventDefault();
        const result = await api(`/invite/${encodeURIComponent(token || '')}`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
        location.href = appUrl(`#event-${result.event_id}`);
      });
    } catch (error) {
      this.showError(error);
    }
  }
}

customElements.define('pb-loading-state', LoadingState);
customElements.define('pb-toast-stack', ToastStack);
customElements.define('pb-login-page', LoginPage);
customElements.define('pb-app-shell', AppShell);
customElements.define('pb-dashboard', DashboardView);
customElements.define('pb-event-calendar', EventCalendar);
customElements.define('pb-pipeline-board', PipelineBoard);
customElements.define('pb-events-list', EventsList);
customElements.define('pb-template-picker', TemplatePicker);
customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-lineup-editor', LineupEditor);
customElements.define('pb-run-sheet', RunSheet);
customElements.define('pb-open-items', OpenItems);
customElements.define('pb-asset-manager', AssetManager);
customElements.define('pb-settlement-form', SettlementForm);
customElements.define('pb-public-event-page', PublicEventPage);
customElements.define('pb-invite-acceptance', InviteAcceptance);
