import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, eventDate, shortDate, isoDate, addDays, timeLabel, money, statusTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, $, $$ } from './core.js';
import { openPrintWindow } from './print.js';


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
    <div class="padded" data-qc-body><pb-loading-state label="Loading templates"></pb-loading-state></div>
  </div>`;
  document.body.appendChild(dialog);

  const close = () => dialog.remove();
  $('[data-close]', dialog).addEventListener('click', close);
  dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
  document.addEventListener('keydown', function onEsc(e) {
    if (e.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
  });

  let templates, venues, types;
  try {
    const data = (await api('/templates')) || {};
    templates = data.templates || [];
    venues    = data.venues    || [];
    types     = data.types     || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
  } catch (err) {
    // The dialog may have been closed while the request was in flight.
    const padded = dialog.querySelector('[data-qc-body]');
    if (padded) padded.innerHTML = `<p class="error-text">${esc(err.message || 'Could not load templates.')}</p>`;
    return;
  }

  // Default to "General Event" if it exists, else the first template.
  const defaultTemplate = templates.find((t) => t.name === 'General Event') || templates[0];

  const body = dialog.querySelector('[data-qc-body]');
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
    // Default to a focused "next two weeks" window so the board reflects what
    // is actually coming up instead of every far-future hold ever created.
    this.showAll = false;
    this.start = isoDate(new Date());
    this.end = isoDate(addDays(new Date(), 14));
    this.setLoading('Loading pipeline');
    try {
      await this.reload();
    } catch (error) {
      this.showError(error);
    }
  }

  async reload() {
    const data = await api('/events');
    this.events = data.events || [];
    this.render();
  }

  // Apply the date-range filter (unless "show all" is on) and sort each
  // column's cards by date ascending. Undated (TBA) events only appear in the
  // "show all" view and sort to the bottom.
  visibleEvents() {
    return (this.events || [])
      .filter((event) => {
        if (this.showAll) return true;
        if (!event.date) return false;
        return (!this.start || event.date >= this.start) && (!this.end || event.date <= this.end);
      })
      .sort((a, b) => {
        if (a.date === b.date) return 0;
        if (!a.date) return 1;
        if (!b.date) return -1;
        return a.date < b.date ? -1 : 1;
      });
  }

  render() {
    const events = this.visibleEvents();
    const hidden = (this.events || []).length - events.length;
    const controls = `<div class="list-controls pipeline-controls">
      <label class="checkbox-inline"><input type="checkbox" data-show-all ${this.showAll ? 'checked' : ''}> Show all events${hidden > 0 && !this.showAll ? ` <span class="muted">(${hidden} hidden)</span>` : ''}</label>
      <label class="date-field">From <input type="date" data-start value="${esc(this.start)}" ${this.showAll ? 'disabled' : ''}></label>
      <label class="date-field">To <input type="date" data-end value="${esc(this.end)}" ${this.showAll ? 'disabled' : ''}></label>
    </div>`;
    this.innerHTML = `<section class="calendar-page">
      <div class="page-head"><div><h1>Pipeline</h1><p class="subtle">Move events from holds to settlement.</p></div></div>
      ${controls}
      <section class="pipeline-board">${statuses.slice(0, 10).map((status) => {
        const items = events.filter((event) => event.status === status);
        return `<article class="pipe-col"><h3>${esc(statusLabel(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => {
          const editable = Boolean(event.capabilities?.edit_event);
          return `<article class="pipe-card"><strong>${esc(event.title)}</strong><span>${esc(shortDate(eventDate(event)))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small>${editable ? `<form data-event="${esc(event.id)}" class="inline-status">${select('status', statuses, event.status, statusLabel)}<button class="small">Move</button><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></form>` : `<div class="inline-status"><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></div>`}</article>`;
        }).join('') || '<small>No events</small>'}</article>`;
      }).join('')}</section>
    </section>`;
    $('[data-show-all]', this)?.addEventListener('change', (event) => { this.showAll = event.target.checked; this.render(); });
    $('[data-start]', this)?.addEventListener('change', (event) => { this.start = event.target.value; this.render(); });
    $('[data-end]', this)?.addEventListener('change', (event) => { this.end = event.target.value; this.render(); });
    $$('form[data-event]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(`/events/${form.dataset.event}`, { method: 'PATCH', body: JSON.stringify({ status: formData(form).status }) });
      publish('event.saved', { id: form.dataset.event });
      publish('toast.show', { message: 'Event status updated.' });
      await this.reload();
    }));
  }
}


class EventsList extends PanicElement {
  async connect() {
    this.query = '';
    this.showPast = false;
    const sortPref = getAppUser()?.events_sort;
    this.sort = { key: 'date', dir: (sortPref === 'asc' || sortPref === 'desc') ? sortPref : 'asc' };
    subscribe('events.search', ({ query }) => { this.query = query.toLowerCase(); this.render(this.data); }, this.abort.signal);
    this.setLoading('Loading events');
    try {
      this.data = await api('/events');
      this.render(this.data);
    } catch (error) {
      this.showError(error);
    }
  }

  toggleSort(key) {
    if (this.sort.key === key) {
      this.sort = { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' };
    } else {
      // New column: dates start newest-first, everything else A→Z.
      this.sort = { key, dir: key === 'date' ? 'desc' : 'asc' };
    }
    this.render(this.data);
  }

  render(data) {
    if (!data) return;
    // Default view hides shows more than two weeks in the past. Undated (TBA)
    // events are always kept since they have no date to fall behind the cutoff.
    const cutoff = isoDate(addDays(new Date(), -14));
    const all = data.events || [];
    const events = all
      .filter((event) => !this.query || String(event.title).toLowerCase().includes(this.query))
      .filter((event) => this.showPast || !event.date || event.date >= cutoff);
    const hiddenPast = all.filter((event) => event.date && event.date < cutoff).length;
    this.innerHTML = `<div class="page-head"><div><h1>Events</h1><p class="subtle">Search, open, and advance every show.</p></div>${data.capabilities?.manage_templates ? '<a class="button" href="#templates">Create Event</a>' : ''}</div><article class="panel"><div class="list-controls"><label class="checkbox-inline"><input type="checkbox" data-show-past ${this.showPast ? 'checked' : ''}> Show past events${hiddenPast && !this.showPast ? ` <span class="muted">(${hiddenPast} hidden)</span>` : ''}</label></div>${table(events, this.sort)}</article>`;
    $$('[data-sort-key]', this).forEach((button) => button.addEventListener('click', () => this.toggleSort(button.dataset.sortKey)));
    $('[data-show-past]', this)?.addEventListener('change', (event) => { this.showPast = event.target.checked; this.render(this.data); });
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


function factCell(label, value) {
  return `<div class="fact"><label>${esc(label)}</label><strong>${value}</strong></div>`;
}


// Base for read-only workspace cards that re-render whenever fresh event data
// arrives — pushed via `.data` from the workspace on first render, or broadcast
// on the page bus as `event.changed` after any in-section edit/add/autosave.
// Subclasses implement render(); the host uses `display: contents` so its inner
// markup lays out exactly where the card sits.
class EventBusCard extends PanicElement {
  connect() {
    subscribe('event.changed', ({ data }) => { this.data = data; }, this.abort.signal);
    if (this._data) this.render();
  }

  set data(value) {
    this._data = value;
    if (this.abort) this.render();
  }

  get data() {
    return this._data;
  }
}


// At-a-glance facts + live counts for an event.
class EventSummary extends EventBusCard {
  render() {
    const data = this._data;
    if (!data?.event) return;
    const event = data.event;
    const openItems = (data.blockers || []).filter((item) => ['open', 'waiting'].includes(item.status)).length;
    const tasksLeft = (data.tasks || []).filter((task) => !['done', 'canceled'].includes(task.status)).length;
    this.innerHTML = `<article class="event-summary">
      <div class="flyer">${esc(event.title)}</div>
      <div class="facts-grid">
        ${factCell('Date', shortDate(eventDate(event)))}
        ${factCell('Doors', timeLabel(event.doors_time))}
        ${factCell('Show', timeLabel(event.show_time))}
        ${factCell('Status', badge(event.status))}
        ${factCell('Owner', esc(event.owner_name || 'Unassigned'))}
        ${factCell('Public Page', Number(event.public_visibility) ? 'Live' : 'Hidden')}
      </div>
      <div class="event-stats">
        <div class="event-stat">Open Items<strong>${openItems}</strong><a href="#open-items">View</a></div>
        <div class="event-stat">Tasks Left<strong>${tasksLeft}</strong><a href="#tasks">View</a></div>
      </div>
    </article>`;
  }
}


// Readiness checklist (derived server-side from tasks, assets, blockers, …).
class EventReadiness extends EventBusCard {
  render() {
    const data = this._data;
    if (!data) return;
    const readiness = data.readiness || [];
    this.innerHTML = `<article class="panel"><div class="section-head padded"><h2>Readiness ${helpLink('overview', 'Overview &amp; Readiness')}</h2></div><div class="health-row">${readiness.map((item) => `<div class="health-item">${item.ok ? '<span class="check">OK</span>' : '<span class="warn-mark">!</span>'}<span><strong>${esc(item.label)}</strong><br>${esc(item.state)}</span></div>`).join('')}</div></article>`;
  }
}


// "Next Recommended Action" card. Its Refresh button re-broadcasts fresh data
// (recomputing this card, readiness, and the summary) without a page reload.
class EventNextAction extends EventBusCard {
  render() {
    const data = this._data;
    if (!data) return;
    this.innerHTML = `<article class="next-action"><span class="icon-bubble amber">!</span><span><strong>Next Recommended Action</strong><p>${esc(data.nextAction)}</p></span><button class="secondary small" data-next-action>Refresh</button></article>`;
    $('[data-next-action]', this).addEventListener('click', () => this.refresh());
  }

  async refresh() {
    const id = this._data?.event?.id;
    if (!id) return;
    broadcastEventData(await api(`/events/${id}`));
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
    if (can(data, 'view_contracts')) tabs.splice(tabs.indexOf('assets') + 1, 0, 'contracts');
    if (can(data, 'manage_ticketing')) tabs.splice(tabs.length - 1, 0, 'ticketing');
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
            <button type="button" data-print="one-sheet">One Sheet</button>
            <button type="button" data-print="contract">Contract</button>
            <button type="button" data-print="master">Master Event Packet</button>
          </div>
        </details>` : ''}
        ${can(data, 'publish_event') ? `<button class="danger" data-publish>${Number(event.public_visibility) ? 'Hide Public Page' : 'Publish Public Page'}</button>` : ''}
      </div>
    </section>
    <nav class="workspace-tabs tabs">${tabs.map((tab, index) => `<a class="${index === 0 ? 'active' : ''}" href="#${tab}">${esc(titleCase(tab))}</a>`).join('')}</nav>
    <pb-event-summary></pb-event-summary>
    <pb-event-next-action></pb-event-next-action>
    <section id="overview" class="overview-grid">
      <pb-event-readiness></pb-event-readiness>
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
    ${can(data, 'view_contracts') ? '<pb-event-contracts id="contracts"></pb-event-contracts>' : ''}
    ${can(data, 'manage_invites') ? '<pb-invite-manager id="invites"></pb-invite-manager>' : ''}
    ${can(data, 'view_settlement') ? '<pb-settlement-form id="settlement"></pb-settlement-form>' : ''}
    ${can(data, 'manage_ticketing') ? '<pb-ticketing-admin id="ticketing"></pb-ticketing-admin>' : ''}
    <section id="activity" class="panel"><div class="section-head padded"><h2>Activity ${helpLink('activity', 'Activity Log')}</h2></div><ul class="timeline">${data.activity.map((entry) => `<li><strong>${esc(entry.action)}</strong> by ${esc(entry.user_name || 'system')} <span class="muted">${esc(entry.created_at)}</span></li>`).join('')}</ul></section>`;
    $('pb-event-summary', this).data = data;
    $('pb-event-next-action', this).data = data;
    $('pb-event-readiness', this).data = data;
    $('pb-event-details-form', this).data = data;
    $('pb-task-list', this).data = data;
    $('pb-lineup-editor', this).data = data;
    $('pb-run-sheet', this).data = data;
    $('pb-staffing-manager', this).data = data;
    $('pb-guest-list-manager', this).data = data;
    $('pb-open-items', this).data = data;
    $('pb-asset-manager', this).data = data;
    if ($('pb-event-contracts', this)) $('pb-event-contracts', this).data = data;
    if ($('pb-invite-manager', this)) $('pb-invite-manager', this).data = data;
    if ($('pb-settlement-form', this)) $('pb-settlement-form', this).data = data;
    if ($('pb-ticketing-admin', this)) $('pb-ticketing-admin', this).data = data;
    $('[data-publish]', this)?.addEventListener('click', () => this.togglePublic());
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

  async togglePublic() {
    const event = this.data.event;
    const body = { ...event, public_visibility: Number(event.public_visibility) ? 0 : 1 };
    await api(`/events/${event.id}`, { method: 'PATCH', body: JSON.stringify(body) });
    // Update in place via the bus instead of re-mounting the workspace.
    this.data.event.public_visibility = body.public_visibility;
    const publishButton = $('[data-publish]', this);
    if (publishButton) publishButton.textContent = body.public_visibility ? 'Hide Public Page' : 'Publish Public Page';
    broadcastEventData(this.data);
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
      ${editable ? '<p class="save-status wide" data-save-status data-state="saved" aria-live="polite">All changes saved</p>' : ''}
    </form></section>`;
    if (!editable) return;
    const form = $('form', this);
    const statusEl = $('[data-save-status]', this);
    const setStatus = (state, text) => { if (statusEl) { statusEl.dataset.state = state; statusEl.textContent = text; } };
    // Autosave: PATCH the whole detail form whenever a field changes. Text and
    // number inputs fire `change` on blur; checkboxes and selects fire
    // immediately. We deliberately do NOT publish `event.saved` here, so the
    // section is never torn down/reloaded while the user is working in it.
    // Fields mirrored in the workspace summary (pb-event-summary). When one of
    // these changes we re-broadcast fresh event data on the bus so the summary
    // facts update live; other fields skip the extra round-trip.
    const summaryFields = new Set(['title', 'date', 'doors_time', 'show_time', 'status', 'owner_user_id', 'public_visibility']);
    const save = async (changedName) => {
      const body = formData(form);
      body.public_visibility = form.public_visibility.checked ? 1 : 0;
      body.walkthrough_done  = form.walkthrough_done.checked ? 1 : 0;
      setStatus('saving', 'Saving…');
      try {
        await api(`/events/${event.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        setStatus('saved', 'All changes saved');
        if (!changedName || summaryFields.has(changedName)) {
          broadcastEventData(await api(`/events/${event.id}`));
        }
      } catch (err) {
        setStatus('error', err.message || 'Save failed — change a field to retry');
        publish('toast.show', { message: err.message || 'Save failed.', tone: 'error' });
      }
    };
    $$('input, select, textarea', form).forEach((field) => field.addEventListener('change', () => save(field.name)));
    // Pressing Enter in a field still saves, but never reloads the page.
    form.addEventListener('submit', (submitEvent) => { submitEvent.preventDefault(); save(); });
  }
}


// ---- Editable record lists: read-only review tables with hover-to-edit ----
// These power the Tasks / Lineup / Run Sheet / Staffing / Guest / Open Items
// panels. Existing items render as plain text rows; an edit pencil fades in on
// row hover and swaps the row for its inline edit form. A "+" in the panel
// header reveals the (otherwise hidden) add form. After any save the parent
// component re-renders via refreshSection(), collapsing everything back to the
// clean review view.

// A small pill for status/category values; blank input renders as an em dash.
function chip(value, tone) {
  if (value === '' || value == null) return '';
  return `<span class="chip${tone ? ` chip-${esc(tone)}` : ''}">${esc(titleCase(value))}</span>`;
}


// Format a YYYY-MM-DD value as a short, localized date (blank stays blank).
function dateLabel(value) {
  if (!value) return '';
  const date = new Date(`${value}T12:00:00`);
  return Number.isNaN(date.getTime()) ? esc(value) : esc(shortDate(date));
}


const editAffordance = '<button type="button" class="record-edit" data-edit aria-label="Edit" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>';


// Render a read-only review table whose rows reveal an inline edit form.
//   items     – array of records
//   cols      – [{ label, grid?, cell:(item)=>html }] (cell returns safe HTML)
//   formFor   – (item)=>'<form class="row-form record-form" …>' edit form markup
//   editable  – when false, rows are plain text with no pencil/form
//   empty     – empty-state message (optional)
//   opts.labeled  – grouped lists: skip the column header, keep per-cell labels
//   opts.rowClass – (item)=>extra class string for the record wrapper
function recordList(items, cols, formFor, editable, empty, opts = {}) {
  if (!items.length) return empty ? emptyState(empty) : '';
  const labeled = Boolean(opts.labeled);
  const tpl = cols.map((c) => c.grid || 'minmax(110px, 1fr)').join(' ') + (editable ? ' 44px' : '');
  const head = labeled ? '' : `<div class="record-head" style="grid-template-columns:${tpl}">${cols.map((c) => `<span>${esc(c.label)}</span>`).join('')}${editable ? '<span aria-hidden="true"></span>' : ''}</div>`;
  const rows = items.map((item) => {
    const cells = cols.map((c) => {
      const value = c.cell(item);
      const empty = value === '' || value == null;
      return `<div class="record-cell"><span class="record-label">${esc(c.label)}</span><span class="record-value">${empty ? '<span class="record-empty">—</span>' : value}</span></div>`;
    }).join('');
    const rowClass = opts.rowClass ? opts.rowClass(item) : '';
    const view = `<div class="record-view" style="grid-template-columns:${tpl}">${cells}${editable ? editAffordance : ''}</div>`;
    return `<div class="record${rowClass ? ` ${rowClass}` : ''}" data-record>${view}${editable ? formFor(item) : ''}</div>`;
  }).join('');
  return `<div class="record-table${labeled ? ' record-table--labeled' : ''}">${head}${rows}</div>`;
}


// Wire up read<->edit toggling and the "+ add" reveal inside a list component.
function bindRecords(root) {
  $$('[data-edit]', root).forEach((btn) => btn.addEventListener('click', () => {
    const rec = btn.closest('[data-record]');
    if (!rec) return;
    rec.classList.add('editing');
    $$('input, select, textarea', rec).find((el) => !el.disabled && el.type !== 'hidden')?.focus();
  }));
  $$('[data-cancel]', root).forEach((btn) => btn.addEventListener('click', () => {
    btn.closest('[data-record]')?.classList.remove('editing');
  }));
  const addBtn = $('[data-add]', root);
  const addForm = $('[data-add-form]', root);
  if (addBtn && addForm) {
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
}


class TaskList extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const tasks = data.tasks || [];
    const editable = can(data, 'manage_tasks');
    const users = data.users || [];
    const userName = (id) => { const u = users.find((x) => String(x.id) === String(id)); return u ? esc(u.name) : ''; };
    const cols = [
      { label: 'Task', grid: 'minmax(150px, 2fr)', cell: (t) => esc(t.title) },
      { label: 'Status', grid: 'minmax(110px, 1fr)', cell: (t) => chip(t.status) },
      { label: 'Assigned', grid: 'minmax(110px, 1fr)', cell: (t) => userName(t.assigned_user_id) },
      { label: 'Due', grid: 'minmax(90px, 0.8fr)', cell: (t) => dateLabel(t.due_date) },
      { label: 'Priority', grid: 'minmax(90px, 0.8fr)', cell: (t) => chip(t.priority) },
      { label: 'Details', grid: 'minmax(140px, 2fr)', cell: (t) => esc(t.description || '') },
    ];
    const editForm = (task) => `<form data-api="/events/${data.event.id}/tasks/${task.id}" data-method="PATCH" class="row-form record-form"><label>Task<input name="title" value="${esc(task.title)}"></label><label>Status${select('status', ['todo','in_progress','blocked','done','canceled'], task.status)}</label><label>Assigned${userSelect(users, task.assigned_user_id)}</label><label>Due<input type="date" name="due_date" value="${esc(task.due_date || '')}"></label><label>Priority${select('priority', ['low','normal','high','urgent'], task.priority)}</label><label>Details<input name="description" value="${esc(task.description || '')}"></label><button>Save</button><button type="button" class="secondary" data-complete="${esc(task.id)}">Done</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/tasks" data-method="POST" class="row-form" data-add-form hidden><label>Task<input name="title" required placeholder="Confirm door count"></label><label>Assigned${userSelect(users)}</label><label>Due<input type="date" name="due_date"></label><label>Priority${select('priority', ['low','normal','high','urgent'], 'normal')}</label><input type="hidden" name="status" value="todo"><label>Details<input name="description" placeholder="Details"></label><button>Add task</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Tasks ${helpLink('tasks', 'Tasks')}</h2><div class="section-head-actions">${addToggle('Add task', editable)}</div></div><div class="record-body">${addForm}${recordList(tasks, cols, editForm, editable, 'No tasks for this event.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Task saved.' });
    }));
    $$('[data-complete]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'done';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      await refreshSection(this);
      publish('toast.show', { message: 'Task completed.' });
    }));
  }
}


class LineupEditor extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const lineup = data.lineup || [];
    const editable = can(data, 'manage_lineup');
    const cols = [
      { label: '#', grid: '46px', cell: (i) => esc(i.billing_order ?? '') },
      { label: 'Artist', grid: 'minmax(140px, 2fr)', cell: (i) => esc(i.display_name) },
      { label: 'Set', grid: 'minmax(80px, 1fr)', cell: (i) => i.set_time ? esc(timeLabel(i.set_time)) : '' },
      { label: 'Length', grid: 'minmax(70px, 0.8fr)', cell: (i) => i.set_length_minutes ? `${esc(i.set_length_minutes)} min` : '' },
      { label: 'Status', grid: 'minmax(100px, 1fr)', cell: (i) => chip(i.status) },
      { label: 'Payout', grid: 'minmax(100px, 1fr)', cell: (i) => esc(i.payout_terms || '') },
      { label: 'Notes', grid: 'minmax(120px, 2fr)', cell: (i) => esc(i.notes || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/lineup/${item.id}" data-method="PATCH" class="row-form record-form"><label>#<input name="billing_order" type="number" value="${esc(item.billing_order)}"></label><label>Artist<input name="display_name" value="${esc(item.display_name)}"></label><label>Set<input name="set_time" type="time" value="${esc(item.set_time || '')}"></label><label>Length<input name="set_length_minutes" type="number" value="${esc(item.set_length_minutes || '')}"></label><label>Status${select('status', ['invited','tentative','confirmed','canceled'], item.status)}</label><label>Payout<input name="payout_terms" value="${esc(item.payout_terms || '')}"></label><label>Notes<input name="notes" value="${esc(item.notes || '')}"></label><button>Save</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/lineup" data-method="POST" class="row-form" data-add-form hidden><label>Artist<input name="band_name" placeholder="Band/artist"></label><label>Display name<input name="display_name" placeholder="Display name"></label><label>#<input name="billing_order" type="number" placeholder="Order"></label><label>Set<input name="set_time" type="time"></label><label>Length<input name="set_length_minutes" type="number" placeholder="Minutes"></label><label>Status${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}</label><label>Payout<input name="payout_terms" placeholder="Payout"></label><button>Add lineup</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Lineup ${helpLink('lineup', 'Lineup &amp; Bands')}</h2><div class="section-head-actions">${addToggle('Add lineup', editable)}</div></div><div class="record-body">${addForm}${recordList(lineup, cols, editForm, editable, 'No lineup yet.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Lineup saved.' });
    }));
  }
}


class RunSheet extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const schedule = data.schedule || [];
    const editable = can(data, 'manage_schedule');
    const types = ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'];
    const cols = [
      { label: 'Item', grid: 'minmax(140px, 2fr)', cell: (i) => esc(i.title) },
      { label: 'Type', grid: 'minmax(110px, 1fr)', cell: (i) => chip(i.item_type) },
      { label: 'Start', grid: 'minmax(80px, 1fr)', cell: (i) => i.start_time ? esc(timeLabel(i.start_time)) : '' },
      { label: 'End', grid: 'minmax(80px, 1fr)', cell: (i) => i.end_time ? esc(timeLabel(i.end_time)) : '' },
      { label: 'Notes', grid: 'minmax(120px, 2fr)', cell: (i) => esc(i.notes || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/schedule/${item.id}" data-method="PATCH" class="row-form record-form"><label>Item<input name="title" value="${esc(item.title)}"></label><label>Type${select('item_type', types, item.item_type)}</label><label>Start<input type="time" name="start_time" value="${esc(item.start_time || '')}"></label><label>End<input type="time" name="end_time" value="${esc(item.end_time || '')}"></label><label>Notes<input name="notes" value="${esc(item.notes || '')}"></label><button>Save</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/schedule" data-method="POST" class="row-form" data-add-form hidden><label>Item<input name="title" required placeholder="Schedule item"></label><label>Type${select('item_type', types, 'other')}</label><label>Start<input type="time" name="start_time"></label><label>End<input type="time" name="end_time"></label><label>Notes<input name="notes" placeholder="Notes"></label><button>Add item</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Run Sheet ${helpLink('schedule', 'Schedule &amp; Run Sheet')}</h2><div class="section-head-actions">${addToggle('Add run sheet item', editable)}</div></div><div class="record-body">${addForm}${recordList(schedule, cols, editForm, editable, 'No run sheet items yet.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
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

    const cols = [
      { label: 'Staff', grid: 'minmax(130px, 1.4fr)', cell: (s) => s.staff_name ? esc(s.staff_name) : '<span class="muted">TBD</span>' },
      { label: 'Role', grid: 'minmax(100px, 1fr)', cell: (s) => chip(s.role) },
      { label: 'Call', grid: 'minmax(70px, 0.8fr)', cell: (s) => s.call_time ? esc(timeLabel(s.call_time)) : '' },
      { label: 'End', grid: 'minmax(70px, 0.8fr)', cell: (s) => s.end_time ? esc(timeLabel(s.end_time)) : '' },
      { label: 'Rate', grid: 'minmax(80px, 0.8fr)', cell: (s) => s.hourly_rate ? `${esc(money(s.hourly_rate))}/hr` : '' },
      { label: 'Status', grid: 'minmax(100px, 1fr)', cell: (s) => chip(s.status) },
      { label: 'Contact', grid: 'minmax(120px, 1.4fr)', cell: (s) => [s.staff_phone, s.staff_email].filter(Boolean).map(esc).join(' &middot; ') },
      { label: 'Notes', grid: 'minmax(120px, 1.4fr)', cell: (s) => esc(s.notes || '') },
    ];

    const editForm = (shift) => `<form data-shift="${esc(shift.id)}" class="row-form record-form staffing-row"><label>Staff <select name="staff_member_id">${rosterOptions(shift.staff_member_id)}</select></label><label>Role ${select('role', roles, shift.role)}</label><label>Call <input type="time" name="call_time" value="${esc(shift.call_time || '')}"></label><label>End <input type="time" name="end_time" value="${esc(shift.end_time || '')}"></label><label>Rate <input type="number" step="0.01" name="hourly_rate" value="${esc(shift.hourly_rate || '')}" placeholder="$/hr"></label><label>Status ${select('status', statuses, shift.status)}</label><label>Notes <input name="notes" value="${esc(shift.notes || '')}"></label><button>Save</button><button type="button" class="small danger" data-delete="${esc(shift.id)}">Remove</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;

    const groupSections = roleOrder
      .filter((role) => grouped[role])
      .map((role) => `<div class="staffing-section"><h3 class="guest-section-head">${esc(titleCase(role))} <span class="muted">${grouped[role].length} shift${grouped[role].length === 1 ? '' : 's'}</span></h3>${recordList(grouped[role], cols, editForm, editable, '', { labeled: true })}</div>`)
      .join('');

    const rosterHint = roster.length
      ? ''
      : (editable
        ? '<p class="muted padded">No active staff in the roster yet. Open <a href="#admin-staff">Admin &rarr; Staff</a> to add bartenders, security, sound, etc.</p>'
        : '');

    const addForm = editable ? `<form data-form="add" data-add-form hidden class="row-form staffing-add">
      <label>Staff <select name="staff_member_id">${rosterOptions(null)}</select></label>
      <label>Role ${select('role', roles, 'security')}</label>
      <label>Call <input type="time" name="call_time"></label>
      <label>End <input type="time" name="end_time"></label>
      <label>Rate <input type="number" step="0.01" name="hourly_rate" placeholder="$/hr"></label>
      <label>Status ${select('status', statuses, 'scheduled')}</label>
      <label>Notes <input name="notes" placeholder="Door area, late call, etc."></label>
      <button>Add shift</button>
      <button type="button" class="secondary small" data-cancel-add>Cancel</button>
    </form>` : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Staffing ${helpLink('staffing', 'Staffing')}</h2>
        <div class="section-head-actions">
          <div class="staffing-totals muted">${totalShifts} shift${totalShifts === 1 ? '' : 's'} &middot; ${confirmed} confirmed${tbd ? ` &middot; ${tbd} TBD` : ''}</div>
          ${addToggle('Add shift', editable)}
        </div>
      </div>
      <div class="record-body staffing-body">
        ${rosterHint}
        ${addForm}
        ${shifts.length ? groupSections : emptyState('No shifts assigned yet. Add bartenders, security, sound, door staff, etc.')}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
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
        await refreshSection(this);
        publish('toast.show', { message: 'Shift saved.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      if (!confirm('Remove this shift?')) return;
      try {
        await api(`/events/${eventId}/staffing/${button.dataset.delete}`, { method: 'DELETE' });
        await refreshSection(this);
        publish('toast.show', { message: 'Shift removed.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $('[data-form="add"]', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${eventId}/staffing`, { method: 'POST', body: JSON.stringify(buildBody(event.target)) });
        publish('toast.show', { message: 'Shift added.' });
        await refreshSection(this);
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
    const cols = [
      { label: 'Item', grid: 'minmax(150px, 2fr)', cell: (i) => esc(i.title) },
      { label: 'Status', grid: 'minmax(110px, 1fr)', cell: (i) => chip(i.status) },
      { label: 'Due', grid: 'minmax(90px, 0.8fr)', cell: (i) => dateLabel(i.due_date) },
      { label: 'Details', grid: 'minmax(150px, 2fr)', cell: (i) => esc(i.description || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/open-items/${item.id}" data-method="PATCH" class="row-form record-form"><label>Item<input name="title" value="${esc(item.title)}"></label><label>Status${select('status', ['open','waiting','resolved','canceled'], item.status)}</label><label>Due<input type="date" name="due_date" value="${esc(item.due_date || '')}"></label><label>Details<input name="description" value="${esc(item.description || '')}"></label><input type="hidden" name="owner_user_id" value="${esc(item.owner_user_id || '')}"><button>Save</button><button type="button" class="secondary" data-resolve="${esc(item.id)}">Mark Complete</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/open-items" data-method="POST" class="row-form" data-add-form hidden><label>Item<input name="title" required placeholder="Waiting on ticket link"></label><label>Details<input name="description" placeholder="Details"></label><input type="hidden" name="status" value="open"><label>Due<input type="date" name="due_date"></label><button>Add open item</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Open Items ${helpLink('open-items', 'Open Items')}</h2><div class="section-head-actions">${addToggle('Add open item', editable)}</div></div><div class="record-body">${addForm}${recordList(items, cols, editForm, editable, 'No open items for this event.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Open item saved.' });
    }));
    $$('[data-resolve]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'resolved';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      await refreshSection(this);
      publish('toast.show', { message: 'Open item completed.' });
    }));
  }
}


class GuestListManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const guests = data.guests || [];
    const editable = can(data, 'manage_guest_list');
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

    // The check-in toggle stays live in the read-only row — it's the primary
    // door-night action — while the pencil reveals the full edit form.
    const cols = [
      { label: 'In', grid: '64px', cell: (g) => `<label class="guest-check"><input type="checkbox" data-checkin="${esc(g.id)}" ${Number(g.checked_in) ? 'checked' : ''}${editable ? '' : ' disabled'}><span>${Number(g.checked_in) ? 'In' : 'Out'}</span></label>` },
      { label: 'Name', grid: 'minmax(120px, 1.6fr)', cell: (g) => esc(g.name) },
      { label: 'Party', grid: '70px', cell: (g) => esc(g.party_size || 1) },
      { label: 'Type', grid: 'minmax(90px, 1fr)', cell: (g) => chip(g.list_type) },
      { label: 'Guest of', grid: 'minmax(110px, 1.2fr)', cell: (g) => esc(g.guest_of || '') },
      { label: 'Notes', grid: 'minmax(120px, 1.4fr)', cell: (g) => esc(g.notes || '') },
    ];

    const editForm = (guest) => `<form data-api="/events/${data.event.id}/guest-list/${guest.id}" data-method="PATCH" class="row-form record-form guest-row"><label>Name<input name="name" value="${esc(guest.name)}"></label><label>Party<input name="party_size" type="number" min="1" value="${esc(guest.party_size || 1)}"></label><label>Type${select('list_type', listTypes, guest.list_type)}</label><label>Guest of<input name="guest_of" placeholder="Guest of" value="${esc(guest.guest_of || '')}"></label><label>Notes<input name="notes" placeholder="Notes" value="${esc(guest.notes || '')}"></label><button>Save</button><button type="button" class="small danger" data-delete="${esc(guest.id)}">Delete</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;

    const sections = sectionOrder
      .filter((key) => grouped[key])
      .map((key) => {
        const subtotalEntries = grouped[key].length;
        const subtotalSeats = grouped[key].reduce((sum, g) => sum + Number(g.party_size || 1), 0);
        return `<div class="guest-section"><h3 class="guest-section-head">${esc(titleCase(key))} <span class="muted">${subtotalEntries} entries &middot; ${subtotalSeats} seats</span></h3>${recordList(grouped[key], cols, editForm, editable, '', { labeled: true, rowClass: (g) => Number(g.checked_in) ? 'checked-in' : '' })}</div>`;
      }).join('');

    const addForm = editable ? `<form data-api="/events/${data.event.id}/guest-list" data-method="POST" data-add-form hidden class="row-form guest-add">
      <label>Name<input name="name" required placeholder="Guest name"></label>
      <label>Party<input name="party_size" type="number" min="1" value="1"></label>
      <label>Type${select('list_type', listTypes, 'guest')}</label>
      <label>Guest of<input name="guest_of" placeholder="Guest of (band/promoter)"></label>
      <label>Notes<input name="notes" placeholder="Notes"></label>
      <button>Add guest</button>
      <button type="button" class="secondary small" data-cancel-add>Cancel</button>
    </form>` : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Door / Guest List ${helpLink('guest-list', 'Guest List')}</h2>
        <div class="section-head-actions">
          <div class="guest-totals muted">${totalEntries} entries &middot; ${totalSeats} seats &middot; ${checkedIn} checked in (${checkedSeats} seats)</div>
          ${addToggle('Add guest', editable)}
        </div>
      </div>
      <div class="record-body guest-list-body">
        ${addForm}
        ${guests.length ? sections : emptyState('No guest list entries yet.')}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Guest list saved.' });
    }));
    $$('[data-checkin]', this).forEach((checkbox) => checkbox.addEventListener('change', async () => {
      const id = checkbox.dataset.checkin;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ checked_in: checkbox.checked ? 1 : 0 }),
      });
      await refreshSection(this);
      publish('toast.show', { message: checkbox.checked ? 'Checked in.' : 'Check-in cleared.' });
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      const id = button.dataset.delete;
      if (!confirm('Remove this guest from the list?')) return;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, { method: 'DELETE' });
      await refreshSection(this);
      publish('toast.show', { message: 'Guest removed.' });
    }));
  }
}


// Full-page image viewer. Shows the image contained at the largest size that
// preserves its aspect ratio; tap/click anywhere or press Escape to dismiss.
function openImageLightbox(src, alt = '') {
  if (!src) return;
  const dialog = document.createElement('div');
  dialog.className = 'lightbox-backdrop';
  dialog.innerHTML = `<button class="lightbox-close" type="button" aria-label="Close">&times;</button><img class="lightbox-img" src="${esc(src)}" alt="${esc(alt)}">`;
  document.body.appendChild(dialog);
  document.body.classList.add('lightbox-open');
  const close = () => {
    dialog.remove();
    document.body.classList.remove('lightbox-open');
    document.removeEventListener('keydown', onEsc);
  };
  function onEsc(e) { if (e.key === 'Escape') close(); }
  dialog.addEventListener('click', close);
  document.addEventListener('keydown', onEsc);
}


class AssetManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const assets = data.assets || [];
    const canManage = can(data, 'manage_assets');
    const canUpload = can(data, 'upload_assets');
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Assets ${helpLink('assets', 'Assets &amp; Flyers')}</h2><div class="section-head-actions">${addToggle('Upload asset', canUpload)}</div></div>${canUpload ? `<form id="asset-form" class="row-form" data-add-form hidden><label>Title<input name="title" placeholder="Asset title"></label><label>Type${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}</label><label>File<input type="file" name="asset" accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,.pdf" required></label><label>Notes<input name="notes" placeholder="Notes"></label><button>Upload asset</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : ''}<div class="asset-grid">${assets.map((asset) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp|svg)$/i.test(asset.filename) ? `<img class="asset-image" src="${esc(assetUrl(asset.file_path))}" alt="${esc(asset.title)}" tabindex="0" role="button" aria-label="View ${esc(asset.title)} full size">` : '<span class="asset-thumb">PDF</span>'}<strong>${esc(asset.title)}</strong><span>${esc(titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span><div class="inline-actions"><a class="button small secondary" href="${esc(assetUrl(asset.file_path))}" download>Download</a>${canManage ? `<button class="small" data-approve="${esc(asset.id)}">Approve</button><button class="small secondary" data-reject="${esc(asset.id)}">Reject</button><button class="small danger" data-delete="${esc(asset.id)}">Delete</button>` : ''}</div></article>`).join('') || emptyState('No assets uploaded yet.')}</div></section>`;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('img.asset-image', this).forEach((img) => {
      const open = () => openImageLightbox(img.src, img.alt);
      img.addEventListener('click', open);
      img.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
    $('#asset-form', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${this.eventData.event.id}/assets`, { method: 'POST', body: new FormData(event.target) });
        publish('toast.show', { message: 'Asset uploaded.' });
        await refreshSection(this);
      } catch (err) {
        publish('toast.show', { message: err.message || 'Upload failed.', tone: 'error' });
      }
    });
    $$('[data-approve],[data-reject]', this).forEach((button) => button.addEventListener('click', async () => {
      const status = button.dataset.approve ? 'approved' : 'rejected';
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.approve || button.dataset.reject}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
        await refreshSection(this);
        publish('toast.show', { message: `Asset ${status}.` });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Action failed.', tone: 'error' });
      }
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.delete}`, { method: 'DELETE' });
        await refreshSection(this);
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
        publish('toast.show', {
          message: result.emailed
            ? `Invite emailed to ${body.email}.`
            : `Invite link created: ${appUrl(result.url)}`,
        });
        await refreshSection(this);
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
      await refreshSection(this);
      publish('toast.show', { message: 'Settlement saved.' });
    });
    $('form[data-form="doc"]', this).addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventData.event.id}`, { method: 'PATCH', body: JSON.stringify({ settlement_doc_url: formData(e.target).settlement_doc_url }) });
      await refreshSection(this);
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
      this.innerHTML = `<main class="public-container"><article class="public-event">${data.flyer ? `<img class="public-flyer" src="${esc(assetUrl(data.flyer.file_path))}" alt="">` : `<div class="public-flyer flyer">${esc(event.title)}</div>`}<div class="public-copy"><p class="eyebrow">${esc(shortDate(eventDate(event)))} - ${esc(event.venue_name)}</p><h1>${esc(event.title)}</h1><p><strong>Doors</strong> ${esc(timeLabel(event.doors_time))} - <strong>Show</strong> ${esc(timeLabel(event.show_time))}</p><p>${esc(event.age_restriction || 'All ages unless noted')} - ${Number(event.ticket_price) > 0 ? money(event.ticket_price) : 'Free / door'}</p>${event.ticket_url ? `<a class="button" href="${esc(event.ticket_url)}">Tickets</a>` : ''}<pb-ticket-purchase event-id="${esc(String(event.id))}"></pb-ticket-purchase><p>${esc(event.description_public || '')}</p><h2>Lineup</h2><ul class="plain-list">${data.lineup.map((item) => `<li>${esc(item.display_name)} ${item.set_time ? `<span>${esc(timeLabel(item.set_time))}</span>` : ''}</li>`).join('')}</ul><p class="muted">${esc(event.address)}, ${esc(event.city)}, ${esc(event.state)}</p></div></article></main>`;
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
customElements.define('pb-dashboard', DashboardView);
customElements.define('pb-event-calendar', EventCalendar);
customElements.define('pb-pipeline-board', PipelineBoard);
customElements.define('pb-events-list', EventsList);
customElements.define('pb-template-picker', TemplatePicker);
customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-event-summary', EventSummary);
customElements.define('pb-event-readiness', EventReadiness);
customElements.define('pb-event-next-action', EventNextAction);
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

export { openEventQuickCreate };
