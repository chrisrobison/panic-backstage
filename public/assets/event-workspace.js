// ── Event workspace shell ────────────────────────────────────────────────────
// The event workspace (tabs, print menu, publish toggle) plus the read-only
// summary/readiness/next-action bus cards and the autosaving details form.
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, eventDate, shortDate, isoDate, addDays, timeLabel, money, statusTone, roomTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';
import { openPrintWindow } from './print.js';
import './paint-splat.js';
import './event-vendors.js';
import './event-execution.js';

function factCell(label, value) {
  return `<div class="fact"><label>${esc(label)}</label><strong>${value}</strong></div>`;
}

/**
 * Clip a value to `max` chars.  If the full value is longer it is truncated
 * with an ellipsis and the full text is placed in a `title` attribute so the
 * user can hover to see the complete value.
 */
function clip(raw, max = 32) {
  const s = String(raw ?? '').trim();
  if (!s) return '<span class="log-empty">(empty)</span>';
  const escaped = esc(s);
  if (s.length <= max) return `<span class="log-val">“${escaped}”</span>`;
  return `<span class="log-val log-clipped" title="${escaped}">“${esc(s.slice(0, max))}…”</span>`;
}

/** Render one activity-log entry, including an optional diff for 'event updated' / 'status changed'. */
function activityEntry(entry) {
  let changes = [];
  try {
    const parsed = entry.details_json ? JSON.parse(entry.details_json) : null;
    if (parsed && Array.isArray(parsed.changes) && parsed.changes.length) {
      changes = parsed.changes;
    }
  } catch (_) { /* malformed JSON — skip diff */ }

  const user = esc(entry.user_name || 'system');
  // created_at arrives as a MySQL datetime string ("2026-06-17 15:30:00"); replace
  // the space with 'T' so the Date constructor parses it reliably across browsers.
  const parsedDate = entry.created_at ? new Date(String(entry.created_at).replace(' ', 'T')) : null;
  const date = `<span class="log-date">${esc(parsedDate ? shortDate(parsedDate) : '')}</span>`;
  const byLine = `<span class="log-meta"> by ${user} ${date}</span>`;

  if (changes.length === 1) {
    const c = changes[0];
    // Single-field change: inline as "status changed from "hold" to "booked" by User Date"
    return `<li><span class="log-action"><strong>${esc(entry.action)}</strong> from ${clip(c.from)} to ${clip(c.to)}</span>${byLine}</li>`;
  }

  // Multi-field change: action header + indented list of field diffs
  const diffList = changes.length
    ? `<ul class="log-changes">${changes.map(c =>
        `<li><span class="log-field">${esc(c.field)}</span> from ${clip(c.from)} to ${clip(c.to)}</li>`
      ).join('')}</ul>`
    : '';

  return `<li><strong>${esc(entry.action)}</strong>${byLine}${diffList}</li>`;
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
      <div class="flyer">
        <paint-splat class="flyer-splat" width="520" height="320" bg-color="#141414" interactive="false" wall-texture="false"></paint-splat>
        <span class="flyer-title">${esc(event.title)}</span>
      </div>
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

// Human-readable labels for each section in the visibility dropdown.
const SECTION_LABELS = {
  overview:     'Overview',
  assets:       'Assets',
  tasks:        'Tasks',
  lineup:       'Lineup',
  schedule:     'Run Sheet',
  staffing:     'Staffing',
  'guest-list': 'Guest List',
  'open-items': 'Open Items',
  contracts:    'Contracts',
  invites:      'Invites',
  vendors:      'Vendors',
  settlement:   'Settlement',
  ticketing:    'Ticketing',
  execution:    'Execution',
  activity:     'Activity',
};

class EventWorkspace extends PanicElement {
  // ── Per-event, per-user section visibility prefs (localStorage) ─────────────
  _prefsKey(userId, eventId) {
    return `pb_sections_${userId}_${eventId}`;
  }

  _loadPrefs(userId, eventId, tabs) {
    try {
      const stored = JSON.parse(localStorage.getItem(this._prefsKey(userId, eventId)) || '{}');
      const prefs = {};
      for (const tab of tabs) prefs[tab] = stored[tab] !== false; // default visible
      return prefs;
    } catch (_) {
      return Object.fromEntries(tabs.map(t => [t, true]));
    }
  }

  _savePrefs(userId, eventId, prefs) {
    try { localStorage.setItem(this._prefsKey(userId, eventId), JSON.stringify(prefs)); }
    catch (_) { /* ignore quota / private-mode errors */ }
  }

  _bindSectionToggles(userId, eventId, prefs) {
    // Apply initial hidden state from loaded prefs
    for (const [sectionId, visible] of Object.entries(prefs)) {
      const el = this.querySelector(`#${CSS.escape(sectionId)}`);
      if (el) el.style.display = visible ? '' : 'none';
    }
    // Wire up checkbox changes
    $$('[data-section-toggle]', this).forEach(checkbox => {
      checkbox.addEventListener('change', () => {
        prefs[checkbox.dataset.sectionToggle] = checkbox.checked;
        this._savePrefs(userId, eventId, prefs);
        const el = this.querySelector(`#${CSS.escape(checkbox.dataset.sectionToggle)}`);
        if (el) el.style.display = checkbox.checked ? '' : 'none';
      });
    });
  }

  async connect() {
    await this.load();
    // Keep the page header in sync with any field edits broadcast on the bus
    // (e.g. title, date, venue, publish toggle).  We patch only the header
    // elements in-place so the full workspace — tabs, sub-components, scroll
    // position — is never torn down.
    subscribe('event.changed', ({ data }) => {
      this.data = data;
      this._updateHeader(data);
    }, this.abort.signal);
  }

  /** Re-publish the topbar page context and patch the publish button after an in-place event update. */
  _updateHeader(data) {
    const event = data.event;
    const isPrivate = event.event_type === 'private_event';
    publish('page.context', {
      title: `${event.title}${isPrivate ? ' 🔒' : ''}`,
      blurb: `${shortDate(eventDate(event))} at ${event.venue_name}`,
    });
    const publishBtn = $('[data-publish]', this);
    if (publishBtn) publishBtn.textContent = Number(event.public_visibility) ? 'Hide Public Page' : 'Publish Public Page';
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
    const isPrivate = event.event_type === 'private_event';
    const tabs = ['overview', 'details', 'assets', 'tasks', ...(isPrivate ? [] : ['lineup']), 'schedule', 'staffing', 'vendors', 'guest-list', 'open-items', 'execution', 'activity'];
    if (can(data, 'view_contracts')) tabs.splice(tabs.length - 1, 0, 'contracts');
    if (can(data, 'manage_invites')) tabs.splice(tabs.length - 1, 0, 'invites');
    if (can(data, 'view_settlement') && !isPrivate) tabs.splice(tabs.length - 1, 0, 'settlement');
    if (can(data, 'manage_ticketing') && !isPrivate) tabs.splice(tabs.length - 1, 0, 'ticketing');
    const user = getAppUser();
    const userId = user?.id ?? 'anon';
    const toggleableTabs = tabs.filter(t => t !== 'details');
    const prefs = this._loadPrefs(userId, event.id, toggleableTabs);
    const sectionsDropdown = `<details class="print-menu sections-menu">
      <summary class="button secondary">Sections &#9662;</summary>
      <div class="print-menu-items">
        ${toggleableTabs.map(t => `<label class="section-toggle-item"><input type="checkbox" data-section-toggle="${esc(t)}"${prefs[t] !== false ? ' checked' : ''}> ${esc(SECTION_LABELS[t] || titleCase(t))}</label>`).join('')}
      </div>
    </details>`;
    publish('page.context', {
      title: `${event.title}${isPrivate ? ' 🔒' : ''}`,
      blurb: `${shortDate(eventDate(event))} at ${event.venue_name}`,
    });
    this.innerHTML = `<section class="event-top">
      <div>
        <a class="back-link" href="#events">&lt;- Back to Events</a>
      </div>
      <div class="event-actions">
        ${isPrivate ? '' : `<a class="button promote-accent" href="#promote-event-${esc(String(event.id))}"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Promote</a>`}
        ${isPrivate ? '' : `<a class="button secondary" href="${esc(appUrl(data.links.public_page))}" target="_blank" rel="noreferrer">Public Page</a>`}
        ${sectionsDropdown}
        ${can(data, 'read_event') ? `<details class="print-menu">
          <summary class="button secondary">Print &#9662;</summary>
          <div class="print-menu-items">
            ${isPrivate ? '' : '<button type="button" data-print="lineup">Band Lineup</button>'}
            <button type="button" data-print="staffing">Staffing Schedule</button>
            <button type="button" data-print="run-of-show">Run of Show</button>
            <button type="button" data-print="guest-list">Door / Guest List</button>
            ${isPrivate ? '' : '<button type="button" data-print="one-sheet">One Sheet</button>'}
            <button type="button" data-print="contract">Contract</button>
            <button type="button" data-print="master">Master Event Packet</button>
          </div>
        </details>` : ''}
        ${(!isPrivate && can(data, 'publish_event')) ? `<button class="danger" data-publish>${Number(event.public_visibility) ? 'Hide Public Page' : 'Publish Public Page'}</button>` : ''}
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
    <pb-asset-manager id="assets"></pb-asset-manager>
    <pb-task-list id="tasks"></pb-task-list>
    ${isPrivate ? '' : '<pb-lineup-editor id="lineup"></pb-lineup-editor>'}
    <pb-run-sheet id="schedule"></pb-run-sheet>
    <pb-staffing-manager id="staffing"></pb-staffing-manager>
    <pb-event-vendors id="vendors"></pb-event-vendors>
    <pb-guest-list-manager id="guest-list"></pb-guest-list-manager>
    <pb-open-items id="open-items"></pb-open-items>
    ${can(data, 'view_contracts') ? '<pb-event-contracts id="contracts"></pb-event-contracts>' : ''}
    ${can(data, 'manage_invites') ? '<pb-invite-manager id="invites"></pb-invite-manager>' : ''}
    ${(!isPrivate && can(data, 'view_settlement')) ? '<pb-settlement-form id="settlement"></pb-settlement-form>' : ''}
    ${(!isPrivate && can(data, 'manage_ticketing')) ? '<pb-ticketing-admin id="ticketing"></pb-ticketing-admin>' : ''}
    <pb-event-execution id="execution"></pb-event-execution>
    <section id="activity" class="panel"><div class="section-head padded"><h2>Activity ${helpLink('activity', 'Activity Log')}</h2></div><ul class="timeline">${data.activity.map(activityEntry).join('')}</ul></section>`;
    $('pb-event-summary', this).data = data;
    $('pb-event-next-action', this).data = data;
    $('pb-event-readiness', this).data = data;
    $('pb-event-details-form', this).data = data;
    $('pb-task-list', this).data = data;
    if ($('pb-lineup-editor', this)) $('pb-lineup-editor', this).data = data;
    $('pb-run-sheet', this).data = data;
    $('pb-staffing-manager', this).data = data;
    $('pb-event-vendors', this).data = data;
    $('pb-guest-list-manager', this).data = data;
    $('pb-open-items', this).data = data;
    $('pb-asset-manager', this).data = data;
    if ($('pb-event-contracts', this)) $('pb-event-contracts', this).data = data;
    if ($('pb-invite-manager', this)) $('pb-invite-manager', this).data = data;
    if ($('pb-settlement-form', this)) $('pb-settlement-form', this).data = data;
    if ($('pb-ticketing-admin', this)) $('pb-ticketing-admin', this).data = data;
    const execEl = $('pb-event-execution', this);
    if (execEl) {
      execEl.eventId             = event.id;
      execEl.canEdit             = can(data, 'manage_execution');
      execEl.canManageIncidents  = can(data, 'manage_incidents');
    }
    $('[data-publish]', this)?.addEventListener('click', () => this.togglePublic());
    $$('[data-print]', this).forEach((button) => button.addEventListener('click', () => {
      button.closest('details.print-menu')?.removeAttribute('open');
      openPrintWindow(button.dataset.print, this.data);
    }));
    $$('.workspace-tabs a', this).forEach((tab) => tab.addEventListener('click', (event) => {
      event.preventDefault();
      const target = (tab.getAttribute('href') || '').slice(1);
      const section = target ? this.querySelector(`#${CSS.escape(target)}`) : null;
      if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      $$('.workspace-tabs a', this).forEach((other) => other.classList.toggle('active', other === tab));
    }));
    this._bindSectionToggles(userId, event.id, prefs);
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

// Convenience: when one of the Doors / Show / End time fields is set, fill in
// whichever of the others are still empty using a sensible default running
// order — Show is 1h after Doors, End is 5h after Doors (so Doors 6:00pm →
// Show 7:00pm, End 11:00pm). Works from any field (e.g. setting Show back-fills
// Doors). Existing values are never overwritten. Times are <input type="time">
// 24h "HH:MM" strings; End wraps past midnight (e.g. 10:00pm → 3:00am).
const TIME_OFFSETS = { doors_time: 0, show_time: 60, end_time: 300 }; // minutes after doors
function autofillEventTimes(form, changed) {
  const valueOf = (name) => (form[name]?.value || '').trim();
  const toMinutes = (value) => { const m = /^(\d{1,2}):(\d{2})/.exec(value); return m ? Number(m[1]) * 60 + Number(m[2]) : null; };
  const toTime = (mins) => { const m = ((mins % 1440) + 1440) % 1440; return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`; };

  const base = toMinutes(valueOf(changed));
  if (base === null) return;
  const doorsBaseline = base - TIME_OFFSETS[changed];

  Object.keys(TIME_OFFSETS).forEach((name) => {
    if (name === changed) return;
    const field = form[name];
    if (!field || field.disabled || valueOf(name) !== '') return; // keep existing values
    field.value = toTime(doorsBaseline + TIME_OFFSETS[name]);
  });
}

// Exported for the UI test suite (tests/ui) to unit-test the pure time logic
// against a detached form. Inert for the running app.
export { autofillEventTimes, TIME_OFFSETS };

// Statuses available to private events — skips all public-promo stages.
const PRIVATE_EVENT_STATUSES = ['empty', 'proposed', 'confirmed', 'booked', 'completed', 'settled', 'canceled'];

class EventDetailsForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const event    = data.event;
    const editable = can(data, 'edit_event');
    const disabled = editable ? '' : ' disabled';
    const isPrivate = event.event_type === 'private_event';

    // Status dropdown is filtered for private events to prevent invalid transitions.
    const availableStatuses = isPrivate ? PRIVATE_EVENT_STATUSES : statuses;
    const statusSelect = select('status', availableStatuses, event.status, statusLabel)
      .replace('<select ', `<select${disabled} `);

    // ── Private event form ───────────────────────────────────────────────────
    if (isPrivate) {
      this.innerHTML = `<section class="panel">
        <div class="section-head padded">
          <h2>Event Details ${helpLink('details', 'Event Details')}</h2>
          <span class="badge status-private" title="Private venue rental — not publicly listed">🔒 Private Event</span>
        </div>
        <form class="grid-form padded">
          <label>Title <input name="title" required value="${esc(event.title)}"${disabled}></label>
          <label>Date <input type="date" name="date" required value="${esc(event.date)}"${disabled}></label>
          <label>Location <select name="venue_id"${disabled}>${data.venues.map((venue) => option(venue.id, event.venue_id, venue.name)).join('')}</select></label>
          <label>Type ${select('event_type', ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'], event.event_type).replace('<select ', `<select${disabled} `)}</label>
          <label>Status ${statusSelect}</label>
          <label>Owner ${ownerSelect(data.users, event.owner_user_id).replace('<select ', `<select${disabled} `)}</label>
          <label>Load-In / Tech <input type="time" name="load_in_time" value="${esc(event.load_in_time || '')}"${disabled}></label>
          <label>Doors <input type="time" name="doors_time" value="${esc(event.doors_time || '')}"${disabled}></label>
          <label>Show <input type="time" name="show_time" value="${esc(event.show_time || '')}"${disabled}></label>
          <label>End <input type="time" name="end_time" value="${esc(event.end_time || '')}"${disabled}></label>
          <label>Age restriction <input name="age_restriction" value="${esc(event.age_restriction || '')}"${disabled}></label>
          <label>Estimated guests <input type="number" name="estimated_guests" value="${esc(event.estimated_guests || '')}" placeholder="Expected headcount"${disabled}></label>
          <label>Capacity (max) <input type="number" name="capacity" value="${esc(event.capacity || '')}"${disabled}></label>
          <label>Paid deposit <input type="number" step="0.01" min="0" name="deposit_amount" value="${esc(event.deposit_amount ?? '')}" placeholder="0.00"${disabled}></label>
          <label class="check-label"><input type="checkbox" name="walkthrough_done" value="1" ${Number(event.walkthrough_done) ? 'checked' : ''}${disabled}> Walk-through happened</label>
          <p class="form-section-head wide">Client / Primary Contact <span class="form-section-note">Required for Hold and above</span></p>
          <label>Name <input name="promoter_name" value="${esc(event.promoter_name || '')}" placeholder="Client full name"${disabled}></label>
          <label>Email <input type="email" name="promoter_email" value="${esc(event.promoter_email || '')}" placeholder="email@example.com"${disabled}></label>
          <label>Phone <input type="tel" name="promoter_phone" value="${esc(event.promoter_phone || '')}" placeholder="415-555-0100"${disabled}></label>
          <label class="wide">Organization <input name="client_org" value="${esc(event.client_org || '')}" placeholder="Company, band, family name…"${disabled}></label>
          <p class="form-section-head wide">Event Requirements</p>
          <label class="wide">AV / Tech requirements <textarea name="av_requirements" placeholder="Sound system, lighting, projector, microphones…"${disabled}>${esc(event.av_requirements || '')}</textarea></label>
          <label class="wide">Catering / Bar notes <textarea name="catering_notes" placeholder="Bar service, catering vendors, alcohol requirements…"${disabled}>${esc(event.catering_notes || '')}</textarea></label>
          <label class="wide">Internal notes <textarea name="description_internal" placeholder="Staff-only notes about this event"${disabled}>${esc(event.description_internal || '')}</textarea></label>
          <p class="form-section-note wide">💰 For rental pricing, contact <strong>Tom Watson</strong>: <a href="mailto:tom@themab.org">tom@themab.org</a></p>
          <input type="hidden" name="public_visibility" value="0">
          ${editable ? '<p class="save-status wide" data-save-status data-state="saved" aria-live="polite">All changes saved</p>' : ''}
        </form>
      </section>`;
    } else {
      // ── Standard (public) event form ───────────────────────────────────────
      this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Event Details ${helpLink('details', 'Event Details')}</h2></div><form class="grid-form padded">
        <label>Title <input name="title" required value="${esc(event.title)}"${disabled}></label>
        <label>Date <input type="date" name="date" required value="${esc(event.date)}"${disabled}></label>
        <label>Location <select name="venue_id"${disabled}>${data.venues.map((venue) => option(venue.id, event.venue_id, venue.name)).join('')}</select></label>
        <label>Type ${select('event_type', ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'], event.event_type).replace('<select ', `<select${disabled} `)}</label>
        <label>Status ${statusSelect}</label>
        <label>Owner ${ownerSelect(data.users, event.owner_user_id).replace('<select ', `<select${disabled} `)}</label>
        <label>Load-In / Tech <input type="time" name="load_in_time" value="${esc(event.load_in_time || '')}"${disabled}></label>
        <label>Doors <input type="time" name="doors_time" value="${esc(event.doors_time || '')}"${disabled}></label>
        <label>Show <input type="time" name="show_time" value="${esc(event.show_time || '')}"${disabled}></label>
        <label>End <input type="time" name="end_time" value="${esc(event.end_time || '')}"${disabled}></label>
        <label>Age <input name="age_restriction" value="${esc(event.age_restriction || '')}"${disabled}></label>
        <label>Ticket price <input type="number" step="0.01" name="ticket_price" value="${esc(event.ticket_price || 0)}"${disabled}></label>
        <label>Paid deposit <input type="number" step="0.01" min="0" name="deposit_amount" value="${esc(event.deposit_amount ?? '')}" placeholder="0.00"${disabled}></label>
        <label>Potential revenue <input type="number" step="0.01" min="0" name="potential_revenue" value="${esc(event.potential_revenue ?? '')}" placeholder="0.00"${disabled}></label>
        <label>Capacity <input type="number" name="capacity" value="${esc(event.capacity || '')}"${disabled}></label>
        <label class="check-label"><input type="checkbox" name="walkthrough_done" value="1" ${Number(event.walkthrough_done) ? 'checked' : ''}${disabled}> Walk-through happened</label>
        <p class="form-section-head wide">Producer / Artist <span class="form-section-note">Required for Hold and above</span></p>
        <label>Name <input name="promoter_name" value="${esc(event.promoter_name || '')}" placeholder="Full name"${disabled}></label>
        <label>Email <input type="email" name="promoter_email" value="${esc(event.promoter_email || '')}" placeholder="email@example.com"${disabled}></label>
        <label>Phone <input type="tel" name="promoter_phone" value="${esc(event.promoter_phone || '')}" placeholder="415-555-0100"${disabled}></label>
        <p class="form-section-head wide">Booker <span class="form-section-note">Required for Hold and above</span></p>
        <label>Name <input name="booker_name" value="${esc(event.booker_name || '')}" placeholder="Full name"${disabled}></label>
        <label>Email <input type="email" name="booker_email" value="${esc(event.booker_email || '')}" placeholder="email@example.com"${disabled}></label>
        <label>Phone <input type="tel" name="booker_phone" value="${esc(event.booker_phone || '')}" placeholder="415-555-0100"${disabled}></label>
        <label class="wide">Public description <textarea name="description_public"${disabled}>${esc(event.description_public || '')}</textarea></label>
        <label class="wide">Internal notes <textarea name="description_internal"${disabled}>${esc(event.description_internal || '')}</textarea></label>
        <label class="check-label"><input type="checkbox" name="public_visibility" value="1" ${Number(event.public_visibility) ? 'checked' : ''}${disabled}> Public page visible</label>
        ${editable ? '<p class="save-status wide" data-save-status data-state="saved" aria-live="polite">All changes saved</p>' : ''}
      </form></section>`;
    }

    if (!editable) return;
    const form     = $('form', this);
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
      // Private events are never publicly visible — the hidden input sends 0,
      // but we double-enforce here in case of DOM manipulation.
      body.public_visibility = isPrivate ? 0 : (form.public_visibility?.checked ? 1 : 0);
      body.walkthrough_done  = form.walkthrough_done?.checked ? 1 : 0;
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
    $$('input, select, textarea', form).forEach((field) => field.addEventListener('change', () => {
      // Setting any one show-time back-fills the empty others before we save,
      // so all three persist in the same PATCH.
      if (field.name in TIME_OFFSETS) autofillEventTimes(form, field.name);
      save(field.name);
    }));
    // Pressing Enter in a field still saves, but never reloads the page.
    form.addEventListener('submit', (submitEvent) => { submitEvent.preventDefault(); save(); });
  }
}

customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-event-summary', EventSummary);
customElements.define('pb-event-readiness', EventReadiness);
customElements.define('pb-event-next-action', EventNextAction);
customElements.define('pb-event-details-form', EventDetailsForm);
