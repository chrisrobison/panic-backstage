// ── Top-level routed views ───────────────────────────────────────────────────
// Dashboard, Calendar, Pipeline, Events list, Template picker, and the two
// public/unauthenticated pages (public event page + invite acceptance). Also
// owns the quick-create event modal (shared by the calendar and the topbar).
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, setAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, eventDate, shortDate, eventDateRangeLabel, isoDate, addDays, timeLabel, money, statusTone, roomTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, mdToHtml, $, $$ } from './core.js';
// Registers <paint-splat> — reused here as the generative "no flyer yet"
// placeholder on the public event page (same treatment the event workspace
// summary card uses for the same situation).
import './paint-splat.js';

// Default dashboard metric cards, in display order, shown when a user has not
// customized their selection. Keys must match the DASHBOARD_METRIC_KEYS list in
// src/AuthEndpoint.php and the catalog in DashboardView._metricCatalog().
const DEFAULT_DASHBOARD_METRICS = ['newLeads', 'nextShow', 'openItems', 'empty', 'needsFlyer', 'unsettled', 'utilized'];

// Statuses valid for private events (no public-promo stages).
const PRIVATE_EVENT_STATUSES = ['empty', 'proposed', 'confirmed', 'booked', 'completed', 'settled', 'canceled'];

// On the calendar a day cell is split by room zone: a room with zone='up'
// renders in the top half, zone='down' in the bottom, zone='both' straddles
// the divider. Rooms live in the `resources` table (a venue can have several)
// so this keys off each event's resource_id, not venue_id — a venue is no
// longer assumed to be a single room. An event with no resource_id (a venue
// with no rooms defined, or a legacy event predating rooms) falls back to
// 'down' so it still renders somewhere instead of disappearing.
function resourceZoneMap(resources) {
  const map = new Map();
  (resources || []).forEach((resource) => {
    const zone  = resource.zone || 'down';
    const label = resource.name || 'Room';
    map.set(Number(resource.id), { zone, label });
  });
  return map;
}

// True when `iso` (a YYYY-MM-DD string) falls anywhere in an event's date
// range — `[event.date, event.end_date || event.date]` inclusive. A single-day
// event (no end_date) only ever spans its own date, so this is a drop-in
// replacement for the old `event.date === iso` check everywhere on the
// calendar.
function eventSpansDay(event, iso) {
  return event.date <= iso && (event.end_date || event.date) >= iso;
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

  let templates, venues, resources, types;
  try {
    const data = (await api('/templates')) || {};
    templates = data.templates || [];
    venues    = data.venues    || [];
    resources = data.resources || [];
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
    <label>End Date <span class="field-hint muted small">Optional — multi-day events</span><input type="date" name="end_date" min="${esc(startDate)}"></label>
    <label class="wide">Title <input name="title" required value="${esc(defaultTemplate?.default_title || defaultTemplate?.name || '')}" placeholder="Event title"></label>
    <label>Doors <input type="time" name="doors_time" value="19:00"></label>
    <label>Show <input type="time" name="show_time" value="20:00"></label>

    <fieldset class="quick-create-blank-fields" hidden>
      ${venues.length > 1
        ? `<label>Venue <select name="venue_id">${venues.map((v) => `<option value="${esc(v.id)}">${esc(v.name)}</option>`).join('')}</select></label>`
        : `<input type="hidden" name="venue_id" value="${esc(venues[0]?.id || '')}">`}
      <label>Type <select name="event_type">${types.map((t) => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('')}</select></label>
    </fieldset>

    <label>Room <select name="resource_id"><option value="">— No specific room —</option></select></label>

    <p class="info-note wide">Venue costs (full day): <strong>Downstairs (21+)</strong> — $2,000 · <strong>Upstairs</strong> — $3,000. Events must not lose money.</p>
    <div class="wide quick-create-actions">
      <button type="submit" class="primary">Create event</button>
      <button type="button" class="secondary" data-close>Cancel</button>
    </div>
    <p class="error-text wide" data-error></p>
  </form>`;

  // Make the second "Cancel" close button work, too.
  $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));

  const form           = $('[data-form="quick-create"]', dialog);
  const templateSelect = $('select[name="template_id"]', form);
  const titleInput     = $('input[name="title"]', form);
  const blankFields    = $('.quick-create-blank-fields', form);
  // A single-venue install renders venue_id as a hidden input instead of a
  // <select> (nothing to pick) — form.venue_id resolves either way.
  const venueSelect    = form.venue_id;
  const roomSelect     = $('select[name="resource_id"]', form);
  const dateInput      = $('input[name="date"]', form);
  const endDateInput   = $('input[name="end_date"]', form);

  // Keep the End Date picker's min in sync with Date, and drop a now-invalid
  // End Date rather than let the user submit a backwards range.
  dateInput.addEventListener('change', () => {
    endDateInput.min = dateInput.value;
    if (endDateInput.value && endDateInput.value < dateInput.value) endDateInput.value = '';
  });
  // `min` is a soft UI hint only — an arrow-key nudge or mouse-wheel scroll
  // over the focused End Date field can still land it below the start date
  // with no visual warning. Catch that directly on the field too.
  endDateInput.addEventListener('change', () => {
    if (endDateInput.value && endDateInput.value < dateInput.value) {
      endDateInput.value = '';
      publish('toast.show', { message: 'End date can’t be before the start date — cleared.', tone: 'error' });
    }
  });

  // The Room picker (bookable sub-space, e.g. Green Room / Patio) depends on
  // which venue is in play: the selected template's venue when a template is
  // chosen, or the Venue dropdown's value for a blank event.
  function currentVenueId() {
    if (!blankFields.hidden) return venueSelect.value;
    const chosen = templates.find((t) => String(t.id) === templateSelect.value);
    return chosen ? String(chosen.venue_id) : '';
  }
  function refreshRoomOptions() {
    const venueId = currentVenueId();
    const prev = roomSelect.value;
    const rooms = resources.filter((r) => String(r.venue_id) === venueId);
    roomSelect.innerHTML = `<option value="">— No specific room —</option>`
      + rooms.map((r) => `<option value="${esc(r.id)}" ${String(r.id) === prev ? 'selected' : ''}>${esc(r.name)}</option>`).join('');
  }

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
    refreshRoomOptions();
  });
  venueSelect.addEventListener('change', refreshRoomOptions);
  refreshRoomOptions();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = $('button[type="submit"]', form);
    submit.disabled = true;
    submit.textContent = 'Creating…';
    $('[data-error]', form).textContent = '';
    if (endDateInput.value && endDateInput.value < dateInput.value) {
      $('[data-error]', form).textContent = 'End Date cannot be before Date.';
      submit.disabled = false;
      submit.textContent = 'Create event';
      return;
    }
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
    publish('page.context', { title: 'Dashboard', blurb: 'Your show operations for the next two weeks.' });
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
    const utilPct = dashboard.cards.utilizationPct ?? 0;
    const utilDays = dashboard.cards.utilizedDays ?? 0;
    const utilTone = utilPct >= 75 ? 'green' : utilPct >= 40 ? 'amber' : 'red';

    // Build the full catalog of metric cards once, then render only those the
    // user has chosen to show (defaulting to DEFAULT_DASHBOARD_METRICS). The
    // gear menu lets the user toggle each one and persists the choice.
    this._catalog = this._metricCatalog({ dashboard, today, capabilities, oldest, utilPct, utilDays, utilTone });
    this._selected = this._selectedMetrics();

    this.innerHTML = `
      ${this.onboardingCard(dashboard.onboarding)}
      ${capabilities.manage_templates ? '<div class="page-head"><a class="button" href="#templates">Create From Template</a></div>' : ''}
      ${this._metricSectionHtml()}
      <section class="dashboard-grid">
        <article class="panel"><div class="section-head padded"><h2>Next 14 Days</h2><a class="button secondary small" href="#calendar">Calendar</a></div>${table(events)}</article>
        <article class="panel"><div class="section-head padded"><h2>Needs Attention</h2><a class="button secondary small" href="#events">All Events</a></div>
          <div class="attention-list">${attention.length ? attention.map((event) => `<a class="attention-card ${event.primary_blocker ? '' : 'amber'}" href="#event-${esc(event.id)}"><span class="icon-bubble ${event.primary_blocker ? 'red' : 'amber'}">!</span><span><strong>${esc(event.title)}</strong><p>${esc(event.primary_blocker || 'Flyer or publish step needs review')}</p><small>${esc(eventDateRangeLabel(event))}</small></span><span class="arrow"></span></a>`).join('') : emptyState('No urgent items in the next two weeks.')}</div>
        </article>
      </section>`;

    // Wire dismiss button after render
    const dismissBtn = this.querySelector('[data-dismiss-onboarding]');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', () => this._dismissOnboarding());
    }

    this._wireMetricMenu();
  }

  // ── Customizable top metrics ───────────────────────────────────────────────

  // The full set of metric cards, in display order. Each entry carries the
  // pre-rendered card markup plus an `available` flag (capability gate). Keys
  // must stay in sync with DASHBOARD_METRIC_KEYS in src/AuthEndpoint.php.
  _metricCatalog({ dashboard, today, capabilities, oldest, utilPct, utilDays, utilTone }) {
    const c = dashboard.cards || {};
    const hasLeads = !!(capabilities.view_leads || capabilities.manage_leads);
    const isAdmin = !!capabilities.manage_users;
    return [
      { key: 'newLeads', label: 'New Leads', available: hasLeads,
        html: this.metricLink('#leads', '<i class="fa-solid fa-inbox" aria-hidden="true"></i>', 'New Leads', c.newLeads ?? 0, `${c.leadsNeedingReview ?? 0} in pipeline`, (c.newLeads ?? 0) > 0 ? 'amber' : '') },
      { key: 'nextShow', label: 'Next Show', available: true,
        html: `<article class="metric-card"><span class="icon-bubble"><i class="fa-solid fa-microphone" aria-hidden="true"></i></span><h3>Next Show<br>${esc(today.title || 'No event')}</h3><p>Doors ${esc(timeLabel(today.doors_time))}<br>Starts ${esc(timeLabel(today.show_time))}</p>${badge(today.status || 'empty')}</article>` },
      { key: 'openItems', label: 'Open Items', available: true,
        html: this.metric('!', 'Open Items', c.blockers, `${c.urgentItems || 0} due soon`, 'red') },
      { key: 'empty', label: 'Empty / Hold', available: true,
        html: this.metric('', 'Empty / Hold', c.empty, dashboard.highlights?.next_empty_date ? shortDate(eventDate({ date: dashboard.highlights.next_empty_date })) : 'No holds soon', '') },
      { key: 'needsFlyer', label: 'Needs Flyer', available: true,
        html: this.metric('', 'Needs Flyer', c.needsAssets, `${c.ready || 0} ready to announce`, 'amber') },
      { key: 'unsettled', label: 'Unsettled', available: true,
        html: this.metric('$', 'Unsettled', c.unsettled, oldest ? oldest.title : 'All settled', 'red') },
      { key: 'utilized', label: 'Utilized', available: true,
        html: this.metric('%', 'Utilized', `${utilPct}%`, `${utilDays} of 14 days booked`, utilTone) },
      { key: 'readyToAnnounce', label: 'Ready to Announce', available: true,
        html: this.metric('', 'Ready to Announce', c.ready ?? 0, 'Awaiting publish', (c.ready ?? 0) > 0 ? 'green' : '') },
      { key: 'published', label: 'Published', available: true,
        html: this.metric('', 'Published', c.published ?? 0, 'Live & upcoming', '') },
      { key: 'contractsAwaitingSignature', label: 'Contracts to Sign', available: true,
        html: this.metric('', 'Contracts to Sign', c.contractsAwaitingSignature ?? 0, 'Awaiting signature', (c.contractsAwaitingSignature ?? 0) > 0 ? 'amber' : '') },
      { key: 'depositsOverdue', label: 'Deposits Overdue', available: true,
        html: this.metric('$', 'Deposits Overdue', c.depositsOverdue ?? 0, 'Past due date', (c.depositsOverdue ?? 0) > 0 ? 'red' : '') },
      { key: 'eventsAwaitingCloseout', label: 'Awaiting Closeout', available: true,
        html: this.metric('', 'Awaiting Closeout', c.eventsAwaitingCloseout ?? 0, 'Completed, not finalized', (c.eventsAwaitingCloseout ?? 0) > 0 ? 'amber' : '') },
      { key: 'overdueFollowups', label: 'Overdue Follow-ups', available: isAdmin,
        html: this.metric('!', 'Overdue Follow-ups', c.overdueFollowups ?? 0, 'Tasks past due', (c.overdueFollowups ?? 0) > 0 ? 'red' : '') },
    ];
  }

  // The user's chosen metric keys as a Set. A stored array (even empty) wins;
  // only a missing preference (null) falls back to the default set.
  _selectedMetrics() {
    const pref = getAppUser()?.dashboard_metrics;
    return new Set(Array.isArray(pref) ? pref : DEFAULT_DASHBOARD_METRICS);
  }

  // Toolbar (gear button + checkbox menu) above the metric grid.
  _metricSectionHtml() {
    const menuItems = this._catalog.filter((d) => d.available).map((d) => `
      <li><label class="metric-menu-item"><input type="checkbox" data-metric-key="${esc(d.key)}" ${this._selected.has(d.key) ? 'checked' : ''}> <span>${esc(d.label)}</span></label></li>`).join('');
    return `<section class="metric-section">
      <div class="metric-toolbar">
        <button class="icon-btn metric-customize" data-metric-toggle type="button" aria-haspopup="true" aria-expanded="false" title="Customize metrics" aria-label="Customize dashboard metrics"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
        <div class="metric-menu" data-metric-menu hidden role="menu" aria-label="Choose dashboard metrics">
          <p class="metric-menu-head">Show metrics</p>
          <ul class="metric-menu-list">${menuItems}</ul>
        </div>
      </div>
      <div class="metric-grid" data-metric-grid>${this._metricGridHtml()}</div>
    </section>`;
  }

  // The visible cards: available AND selected, in catalog order.
  _metricGridHtml() {
    const cards = this._catalog.filter((d) => d.available && this._selected.has(d.key)).map((d) => d.html).join('');
    return cards || emptyState('No metrics selected. Use the slider button above to choose some.');
  }

  _wireMetricMenu() {
    const toggle = $('[data-metric-toggle]', this);
    const menu = $('[data-metric-menu]', this);
    if (!toggle || !menu) return;
    const setOpen = (open) => {
      menu.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
    };
    toggle.addEventListener('click', (event) => { event.stopPropagation(); setOpen(menu.hidden); });
    // Dismiss on outside click or Escape.
    document.addEventListener('click', (event) => {
      if (!menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
    }, { signal: this.abort.signal });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !menu.hidden) setOpen(false);
    }, { signal: this.abort.signal });
    $$('[data-metric-key]', menu).forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) this._selected.add(checkbox.dataset.metricKey);
        else this._selected.delete(checkbox.dataset.metricKey);
        const grid = $('[data-metric-grid]', this);
        if (grid) grid.innerHTML = this._metricGridHtml();
        this._saveMetrics();
      });
    });
  }

  // Persist the selection to the user's preferences. Optimistic: the cached
  // app user is updated immediately so a re-mount reflects the choice even if
  // the request is still in flight or fails.
  async _saveMetrics() {
    const metrics = this._catalog.filter((d) => d.available && this._selected.has(d.key)).map((d) => d.key);
    const user = getAppUser();
    if (user) { user.dashboard_metrics = metrics; setAppUser(user); }
    try {
      await api('/auth/preferences', { method: 'POST', body: JSON.stringify({ dashboard_metrics: metrics }) });
    } catch { /* best-effort — UI already reflects the change */ }
  }

  onboardingCard(onboarding) {
    if (!onboarding) return '';
    const { steps, completed, total } = onboarding;
    const allDone = completed === total;
    const pct = Math.round((completed / total) * 100);

    const stepItems = steps.map((s) => `
      <li class="onboarding-step${s.done ? ' is-done' : ''}">
        <span class="onboarding-check" aria-hidden="true">
          ${s.done ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-regular fa-circle"></i>'}
        </span>
        <span class="onboarding-step-body">
          <strong>${esc(s.label)}</strong>
          <span class="onboarding-step-note">${esc(s.note)}</span>
        </span>
        ${!s.done ? `<a class="button secondary small" href="${esc(s.href)}">Go →</a>` : ''}
      </li>`).join('');

    return `
      <article class="panel onboarding-card" aria-label="Getting started checklist">
        <div class="onboarding-header">
          <div class="onboarding-title">
            <h2>${allDone ? '🎉 You\'re all set!' : 'Get started with Backstage'}</h2>
            <p class="muted">${allDone ? 'Everything is configured. Dismiss this card whenever you\'re ready.' : `${completed} of ${total} steps complete`}</p>
          </div>
          <button class="button secondary small" data-dismiss-onboarding type="button" aria-label="Dismiss setup checklist">
            Dismiss
          </button>
        </div>
        <div class="onboarding-progress" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100">
          <div class="onboarding-progress-bar" style="width:${pct}%"></div>
        </div>
        <ul class="onboarding-steps">${stepItems}</ul>
      </article>`;
  }

  async _dismissOnboarding() {
    try {
      await api('/auth/preferences', { method: 'POST', body: JSON.stringify({ onboarding_dismissed: true }) });
    } catch { /* best-effort */ }
    // Remove the card from the DOM immediately without a full re-render
    this.querySelector('.onboarding-card')?.remove();
  }

  metric(symbol, label, value, note, tone) {
    return `<article class="metric-card ${esc(tone)}"><span class="icon-bubble ${esc(tone)}">${symbol ? esc(symbol) : '<i class="fa-solid fa-calendar-days" aria-hidden="true"></i>'}</span><h3>${esc(label)}</h3><strong>${esc(value)}</strong><p>${esc(note)}</p></article>`;
  }

  // Same shape as metric() but renders as a navigable link. `icon` is trusted
  // markup (an <i> tag), not user data — callers pass a fixed icon string.
  metricLink(href, icon, label, value, note, tone) {
    return `<a class="metric-card metric-link ${esc(tone)}" href="${esc(href)}"><span class="icon-bubble ${esc(tone)}">${icon}</span><h3>${esc(label)}</h3><strong>${esc(value)}</strong><p>${esc(note)}</p></a>`;
  }
}


class EventCalendar extends PanicElement {
  async connect() {
    this.month = new Date();
    this.selectedDate = isoDate(new Date());
    // Default to agenda view on mobile, grid on desktop.
    this.viewMode = window.matchMedia('(max-width: 860px)').matches ? 'agenda' : 'grid';
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
      this._events = data.events || [];
      this._venues = data.venues || [];
      this._resources = data.resources || [];
      this._start  = start;
      this._zoneMap = resourceZoneMap(this._resources);
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  // ── Shared helpers ────────────────────────────────────────────────────────

  _fmtTime(t) {
    if (!t) return '';
    const [h, m] = t.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${ampm}`;
  }

  _zoneOf(event) {
    return this._zoneMap.get(Number(event.resource_id)) || { zone: 'down', label: 'Unassigned' };
  }

  _legend() {
    // Build legend from the actual zones present in the room list — no hardcoded labels.
    const seen = new Map();
    (this._resources || []).forEach((r) => {
      const zone = r.zone || 'down';
      if (!seen.has(zone)) seen.set(zone, r.name || 'Room');
    });
    if (!seen.size) return '';
    const items = [...seen.entries()]
      .map(([zone, label]) => `<span class="legend-item"><span class="status-dot ${roomTone(zone)}"></span>${esc(label)}</span>`)
      .join('');
    return `<div class="calendar-legend" aria-label="Room colour key">${items}</div>`;
  }

  // ── Top-level render ──────────────────────────────────────────────────────

  render() {
    publish('page.context', { title: 'Calendar', blurb: `Booking calendar.${this.canCreate ? ' Click any day to create an event.' : ''}` });
    const monthLabel = this.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    this.innerHTML = `<section class="calendar-page">
      <article class="panel calendar-shell">
        <div class="calendar-toolbar">
          <div class="calendar-controls">
            <button class="secondary small" data-prev>&lt;</button>
            <button class="secondary small" data-next>&gt;</button>
            <button class="secondary small" data-today>Today</button>
          </div>
          <h2>${esc(monthLabel)}</h2>
          <div class="calendar-actions">
            <div class="cal-view-toggle" role="group" aria-label="Calendar view">
              <button class="secondary small${this.viewMode === 'grid' ? ' active' : ''}" data-view="grid" title="Month grid">
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                <span class="cal-view-label"> Grid</span>
              </button>
              <button class="secondary small${this.viewMode === 'agenda' ? ' active' : ''}" data-view="agenda" title="Agenda view">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
                <span class="cal-view-label"> Agenda</span>
              </button>
            </div>
            <a class="button secondary small" href="#pipeline">Pipeline</a>
            ${this.canCreate ? '<button class="button small" data-action="quick-new" type="button" title="New event" aria-label="New event"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>' : ''}
          </div>
        </div>
        ${this.viewMode === 'grid' ? this._renderGrid() : this._renderAgenda()}
      </article>
    </section>`;

    // Common navigation
    $('[data-prev]', this).addEventListener('click', () => {
      this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1);
      this.load();
    });
    $('[data-next]', this).addEventListener('click', () => {
      this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1);
      this.load();
    });
    $('[data-today]', this).addEventListener('click', () => {
      this.month = new Date();
      this.selectedDate = isoDate(new Date());
      this.load();
    });
    $('[data-action="quick-new"]', this)?.addEventListener('click', () => openEventQuickCreate());

    // View toggle
    $$('[data-view]', this).forEach((btn) => {
      btn.addEventListener('click', () => { this.viewMode = btn.dataset.view; this.render(); });
    });

    if (this.viewMode === 'grid') {
      this._wireGrid();
    } else {
      this._wireAgenda();
    }
  }

  // ── Grid view ────────────────────────────────────────────────────────────

  _renderGrid() {
    const events = this._events;
    const days   = Array.from({ length: 42 }, (_, i) => addDays(this._start, i));
    const createable = this.canCreate ? ' calendar-clickable' : '';
    const today  = isoDate(new Date());

    // `iso` is the day cell this mini-event chip is being rendered into — for
    // a multi-day event that's every day from event.date through event.end_date,
    // so we can tell the start cell (full title + time) from a continuation
    // cell (muted, arrow-prefixed, no time — the time already showed on day 1).
    const miniEvent = (event, iso) => {
      const meta = this._zoneOf(event);
      const isMultiDay = Boolean(event.end_date && event.end_date !== event.date);
      const isContinuation = isMultiDay && iso !== event.date;
      const time = this._fmtTime(event.doors_time || event.show_time);
      const loadIn = event.load_in_time ? `Load-in ${this._fmtTime(event.load_in_time)}` : '';
      const isPrivate = event.event_type === 'private_event';
      const rangeLabel = isMultiDay ? `${shortDate(new Date(event.date + 'T12:00:00'))} – ${shortDate(new Date(event.end_date + 'T12:00:00'))}` : null;
      const tip = [isPrivate ? '🔒 Private' : null, statusLabel(event.status), meta.label, rangeLabel, time, loadIn].filter(Boolean).join(' · ');
      return `<a class="mini-event${isPrivate ? ' mini-event-private' : ''}${isContinuation ? ' mini-event-continued' : ''}" href="#event-${esc(event.id)}" title="${esc(tip)}">`
        + `<span class="status-dot ${roomTone(meta.zone)}"></span>`
        + (isPrivate ? '<span class="mini-event-lock" aria-hidden="true">🔒</span>' : '')
        + (isContinuation ? '<span class="mini-event-continues" aria-hidden="true">&#8618;</span>' : '')
        + `<span class="mini-event-title">${esc(event.title)}</span>`
        + (time && !isContinuation ? `<span class="mini-event-time">${esc(time)}</span>` : '')
        + `</a>`;
    };

    const dayCellBody = (dayEvents, iso) => {
      const visible = dayEvents.filter((e) => e.status !== 'canceled');
      if (!visible.length) return `<div class="program-night">${this.canCreate ? '+ Available' : 'Available'}</div>`;
      const up   = visible.filter((e) => this._zoneOf(e).zone === 'up');
      const both = visible.filter((e) => this._zoneOf(e).zone === 'both');
      const down = visible.filter((e) => this._zoneOf(e).zone === 'down');
      const render = (e) => miniEvent(e, iso);
      return `<div class="cell-zone zone-up" data-floor="Upstairs">${up.map(render).join('')}</div>`
        + (both.length ? `<div class="zone-both">${both.map(render).join('')}</div>` : '')
        + `<div class="cell-zone zone-down" data-floor="Downstairs (21+)">${down.map(render).join('')}</div>`;
    };

    return `${this._legend()}
      <div class="calendar-grid calendar-split">
        ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((d) => `<div class="weekday">${d}</div>`).join('')}
        ${days.map((date) => {
          const iso = isoDate(date);
          const dayEvents = events.filter((e) => eventSpansDay(e, iso));
          const isToday = iso === today ? ' cal-today' : '';
          const clickAttr = this.canCreate ? ` data-create-date="${esc(iso)}" role="button" tabindex="0"` : '';
          return `<div class="calendar-day${createable}${isToday}"${clickAttr}><span class="day-num">${date.getDate()}</span>${dayCellBody(dayEvents, iso)}</div>`;
        }).join('')}
      </div>`;
  }

  _wireGrid() {
    if (!this.canCreate) return;
    $$('[data-create-date]', this).forEach((cell) => {
      const open = () => openEventQuickCreate({ date: cell.dataset.createDate });
      cell.addEventListener('click', (e) => { if (e.target.closest('a, button')) return; open(); });
      cell.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
  }

  // ── Agenda view ──────────────────────────────────────────────────────────

  _renderAgenda() {
    const events = this._events;
    const days   = Array.from({ length: 42 }, (_, i) => addDays(this._start, i));
    const today  = isoDate(new Date());

    const miniGrid = `<div class="cal-mini">
      <div class="cal-mini-weekdays">
        ${['S','M','T','W','T','F','S'].map((d) => `<span>${d}</span>`).join('')}
      </div>
      <div class="cal-mini-grid">
        ${days.map((date) => {
          const iso = isoDate(date);
          const dayEvts = events.filter((e) => eventSpansDay(e, iso) && e.status !== 'canceled');
          const isToday   = iso === today;
          const isSel     = iso === this.selectedDate;
          const isCurMo   = date.getMonth() === this.month.getMonth();
          const zones     = [...new Set(dayEvts.map((e) => this._zoneOf(e).zone))];
          const dots      = zones.map((z) => `<i class="cal-dot cal-dot-${z}"></i>`).join('');
          return `<button class="cal-mini-day${isToday ? ' is-today' : ''}${isSel ? ' is-selected' : ''}${!isCurMo ? ' other-month' : ''}" data-select-date="${esc(iso)}" type="button" aria-label="${iso}${isToday ? ' (today)' : ''}${isSel ? ' (selected)' : ''}">
            <span class="cal-mini-num">${date.getDate()}</span>
            <span class="cal-mini-dots">${dots}</span>
          </button>`;
        }).join('')}
      </div>
    </div>`;

    return `${miniGrid}${this._legend()}<div class="cal-day-agenda" data-day-agenda>${this._renderDayContent()}</div>`;
  }

  _renderDayContent() {
    const events       = this._events;
    const selectedDate = this.selectedDate;
    const dayEvents    = events.filter((e) => eventSpansDay(e, selectedDate) && e.status !== 'canceled');
    const dateObj      = new Date(selectedDate + 'T12:00:00');
    const dayLabel     = dateObj.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    const up   = dayEvents.filter((e) => this._zoneOf(e).zone === 'up');
    const both = dayEvents.filter((e) => this._zoneOf(e).zone === 'both');
    const down = dayEvents.filter((e) => this._zoneOf(e).zone === 'down');

    const agendaRow = (event) => {
      const isMultiDay = Boolean(event.end_date && event.end_date !== event.date);
      const isContinuation = isMultiDay && selectedDate !== event.date;
      const time = isContinuation ? '' : this._fmtTime(event.doors_time || event.show_time);
      const isPrivate = event.event_type === 'private_event';
      const dayNum = isMultiDay
        ? Math.round((new Date(selectedDate + 'T12:00:00') - new Date(event.date + 'T12:00:00')) / 86400000) + 1
        : null;
      const dayCount = isMultiDay
        ? Math.round((new Date(event.end_date + 'T12:00:00') - new Date(event.date + 'T12:00:00')) / 86400000) + 1
        : null;
      return `<a class="cal-agenda-row${isContinuation ? ' cal-agenda-row-continued' : ''}" href="#event-${esc(event.id)}">
        <span class="cal-agenda-time">${esc(time)}</span>
        <span class="cal-agenda-title">${isPrivate ? '<span aria-hidden="true">🔒</span> ' : ''}${esc(event.title)}${isMultiDay ? ` <span class="cal-agenda-daynum muted">(Day ${dayNum}/${dayCount})</span>` : ''}</span>
        ${badge(event.status)}
      </a>`;
    };

    const section = (title, evts, zone) => `<div class="cal-venue-section">
      <div class="cal-venue-head">
        <span class="status-dot room-${zone}"></span>
        <h3>${esc(title)}</h3>
        ${this.canCreate ? `<button class="small secondary cal-new-btn" data-quick-create data-date="${esc(selectedDate)}" type="button">+ New</button>` : ''}
      </div>
      ${evts.length
        ? evts.map(agendaRow).join('')
        : `<div class="cal-available"><span class="program-night">${this.canCreate ? '+ Available' : 'Available'}</span></div>`}
    </div>`;

    return `<div class="cal-day-header">
      <button class="icon-btn" data-day-prev type="button" aria-label="Previous day"><i class="fa-solid fa-chevron-left"></i></button>
      <h2>${esc(dayLabel)}</h2>
      <button class="icon-btn" data-day-next type="button" aria-label="Next day"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
    ${section('Upstairs', up, 'up')}
    ${both.length ? section('Both Rooms', both, 'both') : ''}
    ${section('Downstairs', down, 'down')}`;
  }

  _wireAgenda() {
    // Mini-calendar day selection
    $$('[data-select-date]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.selectedDate = btn.dataset.selectDate;
        this._refreshAgenda();
      });
    });
    this._wireAgendaDayNav();
    this._wireQuickCreate();
  }

  _wireAgendaDayNav() {
    $('[data-day-prev]', this)?.addEventListener('click', () => this._navigateDay(-1));
    $('[data-day-next]', this)?.addEventListener('click', () => this._navigateDay(1));
  }

  _navigateDay(delta) {
    const d = new Date(this.selectedDate + 'T12:00:00');
    d.setDate(d.getDate() + delta);
    this.selectedDate = isoDate(d);
    if (d.getMonth() !== this.month.getMonth() || d.getFullYear() !== this.month.getFullYear()) {
      // Crossed a month boundary — reload so the mini-grid covers the new month.
      this.month = new Date(d.getFullYear(), d.getMonth(), 1);
      this.load();
    } else {
      this._refreshAgenda();
    }
  }

  _refreshAgenda() {
    // Update mini-calendar selection state without a full re-render.
    $$('[data-select-date]', this).forEach((b) => {
      b.classList.toggle('is-selected', b.dataset.selectDate === this.selectedDate);
      b.setAttribute('aria-label', `${b.dataset.selectDate}${b.classList.contains('is-today') ? ' (today)' : ''}${b.classList.contains('is-selected') ? ' (selected)' : ''}`);
    });
    $(`[data-select-date="${this.selectedDate}"]`, this)?.scrollIntoView({ block: 'nearest' });

    // Swap only the day-agenda content.
    const agenda = $('[data-day-agenda]', this);
    if (agenda) {
      agenda.innerHTML = this._renderDayContent();
      this._wireAgendaDayNav();
      this._wireQuickCreate();
    }
  }

  _wireQuickCreate() {
    $$('[data-quick-create]', this).forEach((btn) => {
      btn.addEventListener('click', () => openEventQuickCreate({ date: btn.dataset.date || this.selectedDate }));
    });
  }
}


class PipelineBoard extends PanicElement {
  async connect() {
    // Default to a focused "next two weeks" window so the board reflects what
    // is actually coming up instead of every far-future hold ever created.
    this.showAll = false;
    this.start = isoDate(new Date());
    this.end = isoDate(addDays(new Date(), 14));
    publish('page.context', { title: 'Pipeline', blurb: 'Move events from holds to settlement.' });
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
      ${controls}
      <section class="pipeline-board">${statuses.filter((s) => !['settled', 'canceled'].includes(s)).map((status) => {
        const items = events.filter((event) => event.status === status);
        return `<article class="pipe-col"><h3>${esc(statusLabel(status))} <span class="pipe-count">${items.length}</span></h3>${items.map((event) => {
          const editable = Boolean(event.capabilities?.edit_event);
          const isPrivate = event.event_type === 'private_event';
          const pipeStatuses = isPrivate
            ? PRIVATE_EVENT_STATUSES.filter((s) => !['settled', 'canceled'].includes(s))
            : statuses;
          return `<article class="pipe-card${isPrivate ? ' pipe-card-private' : ''}"><strong>${isPrivate ? '🔒 ' : ''}${esc(event.title)}</strong><span>${esc(eventDateRangeLabel(event))}</span><small>${esc(event.owner_name || 'Unassigned')}</small><small>${esc(event.open_items || 0)} open items / ${esc(event.incomplete_tasks || 0)} tasks</small>${editable ? `<form data-event="${esc(event.id)}" class="inline-status">${select('status', pipeStatuses, event.status, statusLabel)}<button class="small">Move</button><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></form>` : `<div class="inline-status"><a class="button secondary small" href="#event-${esc(event.id)}">Open</a></div>`}</article>`;
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
    publish('page.context', { title: 'Events', blurb: 'Search, open, and advance every show.' });
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
    this.innerHTML = `${data.capabilities?.manage_templates ? '<div class="page-head"><a class="button" href="#templates">Create Event</a></div>' : ''}<article class="panel"><div class="list-controls"><label class="checkbox-inline"><input type="checkbox" data-show-past ${this.showPast ? 'checked' : ''}> Show past events${hiddenPast && !this.showPast ? ` <span class="muted">(${hiddenPast} hidden)</span>` : ''}</label></div>${table(events, this.sort)}</article>`;
    $$('[data-sort-key]', this).forEach((button) => button.addEventListener('click', () => this.toggleSort(button.dataset.sortKey)));
    $('[data-show-past]', this)?.addEventListener('change', (event) => { this.showPast = event.target.checked; this.render(this.data); });
  }
}


class TemplatePicker extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Templates', blurb: 'Start the demo by programming a repeatable Mabuhay night.' });
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
    this.innerHTML = `
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

// Header price for the public page: when this event sells tickets here
// (ticketing_mode='internal') and at least one tier is currently on sale,
// reflect the tiers instead of the flat event.ticket_price — otherwise a VIP
// tier or a cheaper advance-sale tier would never show up above the fold.
// price_cents (tiers) vs ticket_price (dollars) — normalize both to dollars
// before formatting with money().
// "A", "A & B", "A, B & C" — used for the public page's "with ..." lineup line.
function joinNames(names) {
  if (names.length <= 1) return names[0] || '';
  if (names.length === 2) return `${names[0]} & ${names[1]}`;
  return `${names.slice(0, -1).join(', ')} & ${names[names.length - 1]}`;
}

function publicTicketPriceLabel(event, ticketTypes) {
  if (event.ticketing_mode === 'internal' && Array.isArray(ticketTypes) && ticketTypes.length) {
    const prices = ticketTypes.map((t) => Number(t.price_cents || 0) / 100);
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    if (min <= 0 && max <= 0) return 'Free';
    return min === max ? money(min) : `From ${money(min)}`;
  }
  return Number(event.ticket_price) > 0 ? money(event.ticket_price) : 'Free / door';
}

class PublicEventPage extends PanicElement {
  async connect() {
    this.setLoading('Loading public event');
    const params = new URLSearchParams(location.search);
    // Current links use ?id=<event id>; ?slug=<slug> is kept working for
    // links minted before this page switched off slugs (which changed
    // whenever an event's title/date was edited, breaking shared links).
    const idOrSlug = params.get('id') || params.get('slug');
    try {
      const data = await api(`/public/events/${encodeURIComponent(idOrSlug || '')}`);
      const event = data.event;
      document.title = event.title ? `${event.title} - Panic Backstage` : 'Panic Backstage Event';
      // Canonical share URL — not location.href, which may carry transient
      // ?order=&checkout= params from a just-completed purchase. Keyed by id
      // (not slug) so a shared/bookmarked link never goes stale if the event
      // is later renamed or rescheduled.
      const publicUrl = appUrl(`event.html?id=${encodeURIComponent(event.id)}`);
      const priceLabel = publicTicketPriceLabel(event, data.ticket_types);
      const lineup = data.lineup || [];
      const tags = String(event.public_tags || '').split(',').map((t) => t.trim()).filter(Boolean);
      const eyebrow = event.public_subtitle
        || (event.city ? `Live in ${event.city}` : `Live at ${event.venue_name}`);
      const withLine = lineup.length ? `with ${joinNames(lineup.map((item) => item.display_name))}` : '';
      const mapQuery = [event.address, event.city, event.state].filter(Boolean).join(', ') || event.venue_name;
      const mapsHref = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(mapQuery)}`;

      this.innerHTML = `<div class="pev">
        <header class="pev-topbar">
          <span class="pev-brand"><span class="pev-brand-panic">Panic</span><span class="pev-brand-chip">Backstage</span></span>
        </header>
        <main class="pev-main">
          <section class="pev-hero">
            <div class="pev-media">
              ${data.flyer
                ? `<img class="pev-flyer" src="${esc(assetUrl(data.flyer.file_path))}" alt="${esc(event.title)} flyer">`
                : `<div class="pev-flyer pev-flyer-placeholder"><paint-splat width="640" height="800" bg-color="#141a22" interactive="false"></paint-splat><span class="pev-flyer-placeholder-title">${esc(event.title)}</span></div>`}
            </div>

            <div class="pev-copy">
              <p class="pev-eyebrow">${esc(eyebrow)}</p>
              <h1 class="pev-title">${esc(event.title)}</h1>
              ${withLine ? `<p class="pev-with">${esc(withLine)}</p>` : ''}

              <ul class="pev-facts">
                <li><i class="fa-solid fa-calendar-day" aria-hidden="true"></i><span>${esc(eventDateRangeLabel(event))}</span></li>
                <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>${esc(event.venue_name)}</span></li>
                <li><i class="fa-solid fa-clock" aria-hidden="true"></i><span>Doors ${esc(timeLabel(event.doors_time))} &middot; Show ${esc(timeLabel(event.show_time))}</span></li>
                <li><i class="fa-solid fa-id-card" aria-hidden="true"></i><span>${esc(event.age_restriction || 'All ages')}</span></li>
                <li><i class="fa-solid fa-tag" aria-hidden="true"></i><span>${esc(priceLabel)}</span></li>
              </ul>

              ${tags.length ? `<div class="pev-tags">${tags.map((t) => `<span class="pev-tag">${esc(t)}</span>`).join('')}</div>` : ''}

              <div class="pev-share-card">
                <p class="pev-share-title">Share This Event</p>
                <div class="pev-share-buttons">
                  <a class="pev-share-btn" href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(publicUrl)}" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>
                  <a class="pev-share-btn" href="https://twitter.com/intent/tweet?url=${encodeURIComponent(publicUrl)}&text=${encodeURIComponent(event.title)}" target="_blank" rel="noopener" aria-label="Share on X"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i></a>
                  <a class="pev-share-btn" href="https://www.reddit.com/submit?url=${encodeURIComponent(publicUrl)}&title=${encodeURIComponent(event.title)}" target="_blank" rel="noopener" aria-label="Share on Reddit"><i class="fa-brands fa-reddit-alien" aria-hidden="true"></i></a>
                  <a class="pev-share-btn" href="https://api.whatsapp.com/send?text=${encodeURIComponent(`${event.title} ${publicUrl}`)}" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
                  <button type="button" class="pev-share-btn" data-share-instagram aria-label="Share on Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></button>
                  <button type="button" class="pev-share-btn" data-copy-link aria-label="Copy link"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
                </div>
              </div>

              ${event.ticket_url ? `<a class="pev-external-tickets" href="${esc(event.ticket_url)}">Get Tickets <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>` : ''}
            </div>

            <aside class="pev-ticket-card">
              <pb-ticket-purchase event-id="${esc(String(event.id))}"></pb-ticket-purchase>
            </aside>
          </section>

          <section class="pev-info-grid">
            ${lineup.length ? `
            <article class="pev-info-card">
              <h2><i class="fa-solid fa-star" aria-hidden="true"></i> The Lineup</h2>
              <ul class="pev-lineup-list">
                ${lineup.map((item, i) => `<li>
                  <span class="pev-lineup-avatar">${esc((item.display_name || '?').trim().charAt(0).toUpperCase())}</span>
                  <span class="pev-lineup-body"><strong>${esc(item.display_name)}</strong><span>${item.set_time ? esc(timeLabel(item.set_time)) : (i === 0 && lineup.length > 1 ? 'Headliner' : 'Performer')}</span></span>
                </li>`).join('')}
              </ul>
            </article>` : ''}

            <article class="pev-info-card">
              <h2><i class="fa-solid fa-bolt" aria-hidden="true"></i> About the Show</h2>
              ${event.description_public ? `<div class="event-description">${mdToHtml(event.description_public)}</div>` : `<p class="muted">More details coming soon.</p>`}
            </article>

            <article class="pev-info-card">
              <h2><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> The Venue</h2>
              <p class="pev-venue-name">${esc(event.venue_name)}</p>
              ${event.address ? `<p class="muted">${esc(event.address)}<br>${esc([event.city, event.state].filter(Boolean).join(', '))}</p>` : ''}
              <a class="pev-directions" href="${esc(mapsHref)}" target="_blank" rel="noopener">Get Directions <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
              ${event.venue_phone ? `<p class="muted"><a href="tel:${esc(event.venue_phone)}">${esc(event.venue_phone)}</a></p>` : ''}
              ${event.venue_website ? `<p class="muted"><a href="${esc(event.venue_website)}" target="_blank" rel="noopener">Visit website</a></p>` : ''}
            </article>
          </section>
        </main>

        <footer class="pev-footer">
          <p>Powered by <strong>Panic Booking</strong> &mdash; Independent shows. Real music.</p>
        </footer>
      </div>`;

      const flashCopied = (btn) => {
        btn.classList.add('is-copied');
        setTimeout(() => btn.classList.remove('is-copied'), 1500);
      };
      this.querySelector('[data-copy-link]')?.addEventListener('click', async (event2) => {
        try {
          await navigator.clipboard.writeText(publicUrl);
          flashCopied(event2.currentTarget);
        } catch { /* clipboard unavailable — quietly ignore */ }
      });
      // Instagram has no web share-intent URL (unlike Facebook/X/Reddit/
      // WhatsApp), so lean on the native OS share sheet where it's available
      // (mobile browsers — that sheet includes Instagram) and fall back to
      // copying the link for the visitor to paste in manually.
      this.querySelector('[data-share-instagram]')?.addEventListener('click', async (event2) => {
        if (navigator.share) {
          try { await navigator.share({ title: event.title, url: publicUrl }); return; } catch { /* canceled — fall through to copy */ }
        }
        try {
          await navigator.clipboard.writeText(publicUrl);
          flashCopied(event2.currentTarget);
        } catch { /* clipboard unavailable — quietly ignore */ }
      });
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
