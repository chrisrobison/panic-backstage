// ── Event workspace shell ────────────────────────────────────────────────────
// The event workspace (tabs, print menu, publish toggle) plus the read-only
// summary/readiness/next-action bus cards and the autosaving details form.
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, eventDate, shortDate, isoDate, addDays, timeLabel, money, statusTone, roomTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';
import { openPrintWindow } from './print.js';
import './paint-splat.js';
import './event-vendors.js';
import './event-execution.js';
import './event-closeout.js';

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
  payments:     'Payments',
  closeout:     'Closeout',
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
    // Non-essential preference: only persist if the user accepted preference cookies.
    window.PBConsent?.savePref(this._prefsKey(userId, eventId), JSON.stringify(prefs));
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
    if (can(data, 'manage_payments')) tabs.splice(tabs.length - 1, 0, 'payments');
    if (can(data, 'view_settlement') && !isPrivate) tabs.splice(tabs.length - 1, 0, 'settlement');
    if (can(data, 'manage_ticketing') && !isPrivate) tabs.splice(tabs.length - 1, 0, 'ticketing');
    if (can(data, 'manage_ledger') || can(data, 'finalize_closeout')) tabs.splice(tabs.length - 1, 0, 'closeout');
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
        ${can(data, 'manage_contracts') ? `<button class="secondary" data-portal-toggle title="Generate a read-only portal link for a promoter or client"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Share Portal Link</button>` : ''}
        ${can(data, 'manage_ledger') ? `<button class="secondary" data-pos-set title="Route Square POS bar sales to this event"><i class="fa-solid fa-cash-register" aria-hidden="true"></i> Set as POS Event</button>` : ''}
      </div>
    </section>
    <pb-portal-panel id="portalPanel"></pb-portal-panel>
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
    ${can(data, 'manage_payments') ? '<pb-event-payments id="payments"></pb-event-payments>' : ''}
    ${(!isPrivate && can(data, 'view_settlement')) ? '<pb-settlement-form id="settlement"></pb-settlement-form>' : ''}
    ${(!isPrivate && can(data, 'manage_ticketing')) ? '<pb-ticketing-admin id="ticketing"></pb-ticketing-admin>' : ''}
    <pb-event-execution id="execution"></pb-event-execution>
    ${(can(data, 'manage_ledger') || can(data, 'finalize_closeout')) ? '<pb-event-closeout id="closeout"></pb-event-closeout>' : ''}
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
    const paymentsEl = $('pb-event-payments', this);
    if (paymentsEl) { paymentsEl.eventId = data.event.id; paymentsEl.data = data; }
    if ($('pb-settlement-form', this)) $('pb-settlement-form', this).data = data;
    if ($('pb-ticketing-admin', this)) $('pb-ticketing-admin', this).data = data;
    const closeoutEl = $('pb-event-closeout', this);
    if (closeoutEl) { closeoutEl.eventId = data.event.id; closeoutEl.canEdit = can(data, 'manage_ledger'); closeoutEl.canFinalize = can(data, 'finalize_closeout'); }
    const execEl = $('pb-event-execution', this);
    if (execEl) {
      execEl.eventId             = event.id;
      execEl.canEdit             = can(data, 'manage_execution');
      execEl.canManageIncidents  = can(data, 'manage_incidents');
    }
    $('[data-publish]', this)?.addEventListener('click', () => this.togglePublic());
    const portalPanel = $('pb-portal-panel', this);
    if (portalPanel) {
      portalPanel.eventId = event.id;
      $('[data-portal-toggle]', this)?.addEventListener('click', () => portalPanel.toggle());
    }
    $('[data-pos-set]', this)?.addEventListener('click', () => this.setPosEvent(event.id));
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

  /**
   * Pin the Square POS terminal to this event so all incoming POS sales
   * are posted here — no date-guessing. Staff click this once when doors open.
   * Finds the first active pos_location_map row for this event's venue.
   */
  async setPosEvent(eventId) {
    const btn = $('[data-pos-set]', this);
    if (btn) { btn.disabled = true; btn.textContent = 'Setting…'; }
    try {
      // Find the location map for this venue
      const mapData = await api('/pos-location-map');
      const venueId = this.data.event?.venue_id;
      const mapping = (mapData.mappings || []).find(m => Number(m.venue_id) === Number(venueId) && m.is_active);
      if (!mapping) {
        publish('toast.show', { message: 'No POS location mapping found for this venue. Set one up in Admin → Payments.', type: 'warn' });
        return;
      }
      await api(`/pos-location-map/${mapping.id}/set-active`, {
        method: 'POST',
        body: JSON.stringify({ event_id: eventId }),
      });
      publish('toast.show', { message: `✓ POS sales will now post to this event.` });
      if (btn) { btn.textContent = '✓ POS Active'; btn.classList.add('success'); }
    } catch (e) {
      publish('toast.show', { message: 'Failed to set POS event: ' + (e.message || e), type: 'error' });
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Set as POS Event'; }
    }
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

// ── Portal link panel ─────────────────────────────────────────────────────────
// Generates and lists time-limited read-only portal links for promoters/clients.
// Staff-only — the panel is hidden when manage_contracts capability is absent.
class PortalPanel extends PanicElement {
  connect() {
    this._eventId = null;
    this._links   = [];
    this._open    = false;
    this.render();
  }

  set eventId(id) {
    this._eventId = id;
  }

  /** Toggle the panel open/closed and load links on first open. */
  async toggle() {
    this._open = !this._open;
    this.render();
    if (this._open && this._links.length === 0) {
      await this.loadLinks();
    }
  }

  async loadLinks() {
    try {
      const res = await api(`/portal/${this._eventId}/list-links`);
      this._links = res.links || [];
    } catch (_) {
      this._links = [];
    }
    this.render();
  }

  async createLink(label, ttlDays) {
    try {
      const res = await api(`/portal/${this._eventId}/create-link`, {
        method: 'POST',
        body: JSON.stringify({ event_id: this._eventId, label, ttl_days: ttlDays }),
      });
      if (res.url) {
        await this.loadLinks();
        // Show the new URL in the copy box
        const newInput = $(`[data-portal-url="${res.token}"]`, this);
        if (newInput) newInput.select();
      }
    } catch (err) {
      publish('toast.show', { message: err.message || 'Failed to generate link.', tone: 'error' });
    }
  }

  async revokeLink(tokenId) {
    try {
      await api(`/portal/${tokenId}/revoke`, { method: 'POST' });
      await this.loadLinks();
      publish('toast.show', { message: 'Portal link revoked.' });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Failed to revoke link.', tone: 'error' });
    }
  }

  render() {
    if (!this._open) {
      this.innerHTML = ''; // collapsed — nothing to show (button is in the workspace toolbar)
      return;
    }

    const active = (this._links || []).filter(l => !Number(l.is_revoked) && new Date(l.expires_at) > new Date());
    const revoked = (this._links || []).filter(l => Number(l.is_revoked) || new Date(l.expires_at) <= new Date());

    const linkRows = active.map(l => `
      <div class="portal-link-row">
        <div class="portal-link-meta">
          <span class="portal-link-label">${esc(l.label || 'Portal link')}</span>
          <span class="portal-link-info">Used ${l.use_count}x &nbsp;·&nbsp; Expires ${shortDate(new Date(l.expires_at))}</span>
        </div>
        <input class="portal-link-url" type="text" readonly value="${esc(l.url)}" data-portal-url="${esc(l.token)}" onclick="this.select()">
        <div class="portal-link-actions">
          <button class="secondary small" onclick="navigator.clipboard.writeText(${JSON.stringify(l.url)}).then(()=>publish('toast.show',{message:'Link copied!'}))">Copy</button>
          <button class="danger small" data-revoke="${esc(String(l.id))}">Revoke</button>
        </div>
      </div>`).join('');

    const revokedNote = revoked.length
      ? `<p class="portal-revoked-note">${revoked.length} revoked / expired link${revoked.length === 1 ? '' : 's'} not shown.</p>`
      : '';

    this.innerHTML = `<div class="portal-panel card">
      <div class="portal-panel-head">
        <strong>Share Portal Link</strong>
        <button class="secondary small" data-portal-close>Close</button>
      </div>
      <p class="portal-panel-blurb">Generate a read-only link for a promoter or client. The link shows event details, contract status, payments, and invoice — no login required.</p>
      <form class="portal-create-form" data-create-form>
        <input type="text" name="label" placeholder="Label, e.g. &quot;Sent to Jane Smith&quot;" class="portal-label-input">
        <select name="ttl_days" class="portal-ttl-select">
          <option value="7">Expires in 7 days</option>
          <option value="14">Expires in 14 days</option>
          <option value="30" selected>Expires in 30 days</option>
          <option value="60">Expires in 60 days</option>
          <option value="90">Expires in 90 days</option>
        </select>
        <button type="submit" class="primary small">Generate Link</button>
      </form>
      ${active.length ? `<div class="portal-links-list">${linkRows}</div>` : '<p class="portal-empty">No active links yet.</p>'}
      ${revokedNote}
    </div>`;

    $('[data-portal-close]', this)?.addEventListener('click', () => this.toggle());
    $('[data-create-form]', this)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form    = e.target;
      const label   = form.label.value.trim();
      const ttlDays = parseInt(form.ttl_days.value, 10) || 30;
      form.querySelector('[type="submit"]').disabled = true;
      await this.createLink(label, ttlDays);
      form.querySelector('[type="submit"]').disabled = false;
      form.label.value = '';
    });
    $$('[data-revoke]', this).forEach(btn => btn.addEventListener('click', async () => {
      const id = parseInt(btn.dataset.revoke, 10);
      btn.disabled = true;
      await this.revokeLink(id);
    }));
  }
}

customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-event-summary', EventSummary);
customElements.define('pb-event-readiness', EventReadiness);
customElements.define('pb-event-next-action', EventNextAction);
customElements.define('pb-event-details-form', EventDetailsForm);
customElements.define('pb-portal-panel', PortalPanel);

// ── Event Payments panel ─────────────────────────────────────────────────────
// Lists event_payments rows and provides "Send Invoice Link" for pending or
// invoiced payments — creates a Stripe Payment Link without an SDK.

class EventPayments extends PanicElement {
  set eventId(id) { this._eventId = id; }
  set data(d)     { this._data = d; this._load(); }

  async _load() {
    const id = this._eventId;
    if (!id) return;
    try {
      const res = await api(`/events/${id}/payments`);
      this._render(res);
    } catch (err) {
      this.innerHTML = `<section class="panel" id="payments"><div class="section-head padded"><h2>Payments</h2></div><p class="muted padded">Could not load payments.</p></section>`;
    }
  }

  _render(res) {
    const id       = this._eventId;
    const payments = res.payments || [];
    const summary  = res.summary  || {};
    const canSend  = can(this._data, 'manage_payments');

    const statusChip = (s) => {
      const tone = { pending: 'yellow', received: 'green', invoiced: 'blue',
                     failed: 'red', refunded: 'gray', voided: 'gray' }[s] || '';
      return `<span class="chip${tone ? ` chip-${esc(tone)}` : ''}">${esc(s)}</span>`;
    };

    const rows = payments.length
      ? payments.map(p => {
          const sendBtn = canSend && ['pending','invoiced'].includes(p.status)
            ? `<button class="small secondary" data-send-link="${esc(String(p.id))}" title="Create or re-send a Stripe payment link">Send Invoice Link</button>`
            : '';
          const linkCell = p.external_ref
            ? `<span class="muted small" title="${esc(p.external_ref)}">Link sent</span>`
            : '';
          return `<tr>
            <td>${esc(p.payment_type)}</td>
            <td>${esc(money(p.amount))} ${esc(p.currency || 'USD')}</td>
            <td>${statusChip(p.status)}</td>
            <td>${esc(p.method || '—')}</td>
            <td>${esc(p.due_date || '—')}</td>
            <td>${linkCell}${sendBtn}</td>
          </tr>`;
        }).join('')
      : `<tr><td colspan="6" class="muted">${emptyState('No payment records yet.')}</td></tr>`;

    const depositBar = summary.deposit_required > 0 ? `
      <div class="summary-row">
        <span class="label">Deposit Required</span>
        <span class="value">${esc(money(summary.deposit_required))}</span>
      </div>
      <div class="summary-row">
        <span class="label">Deposit Received</span>
        <span class="value">${esc(money(summary.deposit_received))}</span>
      </div>
      <div class="summary-row">
        <span class="label">Deposit Outstanding</span>
        <span class="value${summary.deposit_outstanding > 0 ? ' value-warn' : ''}">${esc(money(summary.deposit_outstanding))}</span>
      </div>` : '';

    this.innerHTML = `
      <section class="panel" id="payments">
        <div class="section-head padded">
          <h2>Payments ${helpLink('payments', 'Payments')}</h2>
        </div>
        ${depositBar ? `<div class="summary-block">${depositBar}</div>` : ''}
        <div class="table-scroll">
          <table>
            <thead><tr>
              <th>Type</th><th>Amount</th><th>Status</th><th>Method</th><th>Due</th><th>Actions</th>
            </tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>`;

    this.querySelectorAll('[data-send-link]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const pid = parseInt(btn.dataset.sendLink, 10);
        btn.disabled = true;
        btn.textContent = 'Sending…';
        try {
          const result = await api(`/events/${id}/payments/${pid}/send-link`, { method: 'POST' });
          if (result.payment_link) {
            await navigator.clipboard.writeText(result.payment_link).catch(() => {});
            publish('toast.show', { message: 'Payment link created and copied to clipboard.' });
          }
          this._load();
        } catch (err) {
          publish('toast.show', { message: 'Failed to create payment link: ' + (err.message || 'Unknown error'), tone: 'error' });
          btn.disabled = false;
          btn.textContent = 'Send Invoice Link';
        }
      });
    });
  }
}

customElements.define('pb-event-payments', EventPayments);
