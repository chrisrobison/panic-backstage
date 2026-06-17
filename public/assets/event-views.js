// ── Top-level routed views ───────────────────────────────────────────────────
// Dashboard, Calendar, Pipeline, Events list, Template picker, and the two
// public/unauthenticated pages (public event page + invite acceptance). Also
// owns the quick-create event modal (shared by the calendar and the topbar).
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, eventDate, shortDate, isoDate, addDays, timeLabel, money, statusTone, roomTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, mdToHtml, $, $$ } from './core.js';

// Statuses valid for private events (no public-promo stages).
const PRIVATE_EVENT_STATUSES = ['empty', 'proposed', 'confirmed', 'booked', 'completed', 'settled', 'canceled'];

// On the calendar a day cell is split by floor: Upstairs sits in the top half,
// Downstairs in the bottom half, and a whole-building "Both Rooms" booking
// straddles the divider. The dot colour denotes the venue floor (see legend).
// Zones are keyed by stable slug; an unrecognised venue falls to downstairs.
const VENUE_ZONE = {
  'mabuhay-upstairs': { zone: 'up',   label: 'Upstairs' },
  'mabuhay-gardens':  { zone: 'down', label: 'Downstairs (21+)' },
  'mabuhay-both':     { zone: 'both', label: 'Both Rooms' },
};
function venueZoneMap(venues) {
  const map = new Map();
  (venues || []).forEach((venue) => {
    const cfg = VENUE_ZONE[venue.slug] || { zone: 'down', label: String(venue.name || 'Venue') };
    map.set(Number(venue.id), cfg);
  });
  return map;
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

    <p class="info-note wide">Venue costs (full day): <strong>Downstairs (21+)</strong> — $2,000 · <strong>Upstairs</strong> — $3,000. Events must not lose money.</p>
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
      this.render(data.events || [], start, data.venues || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(events, start, venues = []) {
    const days = Array.from({ length: 42 }, (_, index) => addDays(start, index));
    const createable = this.canCreate ? ' calendar-clickable' : '';
    const zoneMap = venueZoneMap(venues);
    const zoneOf = (event) => zoneMap.get(Number(event.venue_id)) || { zone: 'down', label: 'Unassigned' };

    // The dot colour denotes venue floor — legend is always visible.
    const legend = `<div class="calendar-legend" aria-label="Venue floor colour key">`
      + `<span class="legend-item"><span class="status-dot room-up"></span>Upstairs</span>`
      + `<span class="legend-item"><span class="status-dot room-down"></span>Downstairs (21+)</span>`
      + `<span class="legend-item"><span class="status-dot room-both"></span>Both Rooms</span>`
      + `</div>`;

    // Format a raw HH:MM:SS time string into "7:00 PM"
    const fmtTime = (t) => {
      if (!t) return '';
      const [h, m] = t.split(':').map(Number);
      const ampm = h >= 12 ? 'PM' : 'AM';
      return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${ampm}`;
    };

    const miniEvent = (event) => {
      const meta = zoneOf(event);
      const time = fmtTime(event.doors_time || event.show_time);
      const loadIn = event.load_in_time ? `Load-in ${fmtTime(event.load_in_time)}` : '';
      const isPrivate = event.event_type === 'private_event';
      const tip = [isPrivate ? '🔒 Private' : null, statusLabel(event.status), meta.label, time, loadIn].filter(Boolean).join(' · ');
      return `<a class="mini-event${isPrivate ? ' mini-event-private' : ''}" href="#event-${esc(event.id)}" title="${esc(tip)}">`
        + `<span class="status-dot ${roomTone(meta.zone)}"></span>`
        + (isPrivate ? '<span class="mini-event-lock" aria-hidden="true">🔒</span>' : '')
        + `<span class="mini-event-title">${esc(event.title)}</span>`
        + (time ? `<span class="mini-event-time">${esc(time)}</span>` : '')
        + `</a>`;
    };
    // Vertical position = floor: upstairs above the divider, downstairs below,
    // whole-building bookings straddling it. Canceled events are hidden.
    const dayCellBody = (dayEvents) => {
      const visible = dayEvents.filter((event) => event.status !== 'canceled');
      if (!visible.length) return `<div class="program-night">${this.canCreate ? '+ Available' : 'Available'}</div>`;
      const up = visible.filter((event) => zoneOf(event).zone === 'up');
      const both = visible.filter((event) => zoneOf(event).zone === 'both');
      const down = visible.filter((event) => zoneOf(event).zone === 'down');
      return `<div class="cell-zone zone-up" data-floor="Upstairs">${up.map(miniEvent).join('')}</div>`
        + (both.length ? `<div class="zone-both">${both.map(miniEvent).join('')}</div>` : '')
        + `<div class="cell-zone zone-down" data-floor="Downstairs (21+)">${down.map(miniEvent).join('')}</div>`;
    };

    this.innerHTML = `<section class="calendar-page">
      <div class="page-head"><div><h1>Calendar</h1><p class="subtle">Dynamic booking window for Mabuhay Gardens.${this.canCreate ? ' <span class="muted small">Click any day to create.</span>' : ''}</p></div>${this.canCreate ? '<button class="button" data-action="quick-new" type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> New event</button>' : ''}</div>
      <article class="panel calendar-shell">
        <div class="calendar-toolbar">
          <div class="calendar-controls"><button class="secondary small" data-prev>&lt;</button><button class="secondary small" data-next>&gt;</button><button class="secondary small" data-today>Today</button></div>
          <h2>${esc(this.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))}</h2>
          <div class="calendar-actions"><a class="button secondary small" href="#pipeline">Pipeline</a></div>
        </div>
        ${legend}
        <div class="calendar-grid calendar-split">
          ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<div class="weekday">${day}</div>`).join('')}
          ${days.map((date) => {
            const iso = isoDate(date);
            const dayEvents = events.filter((event) => event.date === iso);
            const clickAttr = this.canCreate ? ` data-create-date="${esc(iso)}" role="button" tabindex="0"` : '';
            return `<div class="calendar-day${createable}"${clickAttr}><span class="day-num">${date.getDate()}</span>${dayCellBody(dayEvents)}</div>`;
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
      <section class="pipeline-board">${statuses.filter((s) => !['settled', 'canceled'].includes(s)).map((status) => {
        const items = events.filter((event) => event.status === status);
        return `<article class="pipe-col"><h3>${esc(statusLabel(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => {
          const editable = Boolean(event.capabilities?.edit_event);
          const isPrivate = event.event_type === 'private_event';
          const pipeStatuses = isPrivate
            ? PRIVATE_EVENT_STATUSES.filter((s) => !['settled', 'canceled'].includes(s))
            : statuses;
          return `<article class="pipe-card${isPrivate ? ' pipe-card-private' : ''}"><strong>${isPrivate ? '🔒 ' : ''}${esc(event.title)}</strong><span>${esc(shortDate(eventDate(event)))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small>${editable ? `<form data-event="${esc(event.id)}" class="inline-status">${select('status', pipeStatuses, event.status, statusLabel)}<button class="small">Move</button><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></form>` : `<div class="inline-status"><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></div>`}</article>`;
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
    // Default view hides events older than yesterday. Undated (TBA)
    // events are always kept since they have no date to fall behind the cutoff.
    const cutoff = isoDate(addDays(new Date(), -1));
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

class PublicEventPage extends PanicElement {
  async connect() {
    this.setLoading('Loading public event');
    const slug = new URLSearchParams(location.search).get('slug');
    try {
      const data = await api(`/public/events/${encodeURIComponent(slug || '')}`);
      const event = data.event;
      this.innerHTML = `<main class="public-container"><article class="public-event">${data.flyer ? `<img class="public-flyer" src="${esc(assetUrl(data.flyer.file_path))}" alt="">` : `<div class="public-flyer flyer">${esc(event.title)}</div>`}<div class="public-copy"><p class="eyebrow">${esc(shortDate(eventDate(event)))} - ${esc(event.venue_name)}</p><h1>${esc(event.title)}</h1><p><strong>Doors</strong> ${esc(timeLabel(event.doors_time))} - <strong>Show</strong> ${esc(timeLabel(event.show_time))}</p><p>${esc(event.age_restriction || 'All ages unless noted')} - ${Number(event.ticket_price) > 0 ? money(event.ticket_price) : 'Free / door'}</p>${event.ticket_url ? `<a class="button" href="${esc(event.ticket_url)}">Tickets</a>` : ''}<pb-ticket-purchase event-id="${esc(String(event.id))}"></pb-ticket-purchase>${event.description_public ? `<div class="event-description">${mdToHtml(event.description_public)}</div>` : ''}<h2>Lineup</h2><ul class="plain-list">${data.lineup.map((item) => `<li>${esc(item.display_name)} ${item.set_time ? `<span>${esc(timeLabel(item.set_time))}</span>` : ''}</li>`).join('')}</ul><p class="muted">${esc(event.address)}, ${esc(event.city)}, ${esc(event.state)}</p></div></article></main>`;
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
customElements.define('pb-public-event-page', PublicEventPage);
customElements.define('pb-invite-acceptance', InviteAcceptance);

export { openEventQuickCreate };
