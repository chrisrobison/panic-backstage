// ── Event workspace shell ────────────────────────────────────────────────────
// The event workspace (tabs, print menu, publish toggle) plus the read-only
// summary/readiness/next-action bus cards and the autosaving details form.
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, formData, broadcastEventData, refreshSection, shortDate, eventDateRangeLabel, isoDate, addDays, timeLabel, money, statusTone, roomTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';
import { openPrintWindow } from './print.js';
import './paint-splat.js';
import './event-vendors.js';
import './event-execution.js';
import './event-closeout.js';
import './recurrence.js'; // registers <pb-recurrence-fields>, used by EventRecurrencePanel below

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
        ${factCell('Date', eventDateRangeLabel(event))}
        ${factCell('Doors', timeLabel(event.doors_time))}
        ${factCell('Show', timeLabel(event.show_time))}
        ${factCell('Status', badge(event.status))}
        ${factCell('Owner', esc(event.owner_name || 'Unassigned'))}
        ${factCell('Public Page', Number(event.public_visibility) ? 'Live' : 'Hidden')}
        ${event.ticketing_mode === 'internal' ? factCell('Tickets Sold', event.tickets_sold ?? 0) : ''}
      </div>
      <div class="event-stats">
        <div class="event-stat">Open Items<strong>${openItems}</strong><button type="button" data-goto-tab="open-items">View</button></div>
        <div class="event-stat">Tasks Left<strong>${tasksLeft}</strong><button type="button" data-goto-tab="tasks">View</button></div>
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
//
// The recommendation itself (data.nextAction) is computed fresh server-side
// on every load from live event state (see Events::nextAction()) — there's
// no stored "task" behind it to delete, so dismissing it can't mean deleting
// anything. Instead the close button collapses the banner down to a slim
// strip for the rest of this visit to the event, tracked in memory on this
// element instance (not persisted — a fresh page load always shows it again,
// same as the onboarding-tip precedent of "you'll see it again next time",
// just scoped to a page load rather than a day). If a save anywhere in the
// event changes what the recommendation actually is, the new text no longer
// matches what was dismissed and the full banner reappears automatically —
// dismissing "Complete settlement" should never quietly suppress a
// *different* blocker that shows up after.
class EventNextAction extends EventBusCard {
  render() {
    const data = this._data;
    if (!data) return;
    if (this._dismissedText && this._dismissedText === data.nextAction) {
      this.innerHTML = `<article class="next-action next-action-collapsed"><span>Next Recommended Action dismissed for now</span><button class="linklike small" data-next-action-restore>Show</button></article>`;
      $('[data-next-action-restore]', this).addEventListener('click', () => { this._dismissedText = null; this.render(); });
      return;
    }
    this.innerHTML = `<article class="next-action"><span class="icon-bubble amber">!</span><span><strong>Next Recommended Action</strong><p>${esc(data.nextAction)}</p></span><span class="next-action-buttons"><button class="secondary small" data-next-action>Refresh</button><button class="icon-btn small" data-next-action-dismiss type="button" title="Dismiss for now" aria-label="Dismiss Next Recommended Action">&times;</button></span></article>`;
    $('[data-next-action]', this).addEventListener('click', () => this.refresh());
    $('[data-next-action-dismiss]', this).addEventListener('click', () => { this._dismissedText = data.nextAction; this.render(); });
  }

  async refresh() {
    const id = this._data?.event?.id;
    if (!id) return;
    broadcastEventData(await api(`/events/${id}`));
  }
}

// ── Overview dashboard (card-grid summary) ──────────────────────────────────
// A read-only, at-a-glance dashboard shown on the Overview tab: schedule
// timeline, contacts, lineup, venue logistics (vendors), financial snapshot,
// notes/tasks, and documents — each card links back to its full tab via a
// `data-goto-tab` button that EventWorkspace intercepts (see connect()).
// Mirrors the design of the other EventBusCard subclasses above: it re-renders
// whenever fresh event data is broadcast on the bus.

function ovIcon(name) {
  return `<i class="fa-solid fa-${esc(name)}" aria-hidden="true"></i>`;
}

function ovCard({ icon, title, help, body, footerLabel, footerTab, wide }) {
  const footer = footerLabel
    ? `<div class="ov-footer"><button type="button" data-goto-tab="${esc(footerTab)}">${esc(footerLabel)} &rarr;</button></div>`
    : '';
  return `<article class="panel overview-card${wide ? ' span-3' : ''}">
    <div class="section-head padded"><h2>${ovIcon(icon)} ${esc(title)}</h2>${help ? helpLink(help) : ''}</div>
    <div class="ov-body">${body}</div>
    ${footer}
  </article>`;
}

function ovStat(label, value) {
  if (value === '' || value == null) return '';
  return `<div class="ov-stat"><label>${esc(label)}</label><strong>${value}</strong></div>`;
}

const DEPOSIT_STATUS_TONE = { received: 'green', partially_received: 'amber', requested: 'amber', waived: 'gray', refunded: 'red', not_required: 'gray' };

class EventOverview extends EventBusCard {
  connect() {
    this._vendors = null;
    this._vendorsEventId = null;
    super.connect();
  }

  render() {
    const data = this._data;
    if (!data?.event) return;
    const event = data.event;
    const isPrivate = event.event_type === 'private_event';

    // Vendors aren't part of the main event payload — fetch them lazily
    // (once per event id) for the Venue Ops / Logistics card, same pattern
    // EventRecurrencePanel uses for its own per-event data.
    const eventId = event.id;
    if (this._vendorsEventId !== eventId) {
      this._vendorsEventId = eventId;
      this._vendors = null;
      api(`/events/${eventId}/vendors`).then((res) => {
        if (this._vendorsEventId !== eventId) return; // stale response — event changed since
        this._vendors = res.vendors || [];
        this.render();
      }).catch(() => {
        if (this._vendorsEventId !== eventId) return;
        this._vendors = [];
        this.render();
      });
    }

    this.innerHTML = `<div class="overview-dashboard">
      ${this._scheduleCard(data)}
      ${this._contactsCard(event, isPrivate)}
      ${isPrivate ? '' : this._lineupCard(data)}
      ${this._logisticsCard(event)}
      ${this._financialCard(data, isPrivate)}
      ${this._notesTasksCard(data)}
      ${this._documentsCard(data)}
    </div>`;
  }

  _scheduleCard(data) {
    const items = data.schedule || [];
    const shown = items.slice(0, 7);
    const body = shown.length
      ? `<ul class="timeline-list">${shown.map((item) => `<li class="timeline-row">
          <span class="timeline-time">${item.start_time ? esc(timeLabel(item.start_time)) : 'TBA'}</span>
          <span class="timeline-dot" aria-hidden="true"></span>
          <span class="timeline-label">${esc(item.title)}${item.notes ? `<br><span class="muted small">${esc(item.notes)}</span>` : ''}</span>
        </li>`).join('')}</ul>${items.length > shown.length ? `<p class="muted small">+${items.length - shown.length} more</p>` : ''}`
      : emptyState('No schedule items yet.');
    return ovCard({ icon: 'clock', title: 'Schedule / Timeline', help: 'schedule', body, footerLabel: 'Full Run Sheet', footerTab: 'schedule' });
  }

  _contactsCard(event, isPrivate) {
    const rows = [];
    const contactBlock = (heading, name, email, phone) => {
      if (!name && !email && !phone) return '';
      return `<div class="ov-contact-block">
        <p class="ov-contact-heading">${esc(heading)}</p>
        ${name ? `<p class="ov-contact-name">${esc(name)}${event.client_org ? ` <span class="muted">/ ${esc(event.client_org)}</span>` : ''}</p>` : ''}
        <div class="ov-contact-meta">
          ${phone ? `<span>${esc(phone)}</span>` : ''}
          ${email ? `<span><a href="mailto:${esc(email)}">${esc(email)}</a></span>` : ''}
        </div>
      </div>`;
    };
    rows.push(contactBlock(isPrivate ? 'Client / Primary Contact' : 'Promoter / Artist', event.promoter_name, event.promoter_email, event.promoter_phone));
    if (!isPrivate) rows.push(contactBlock('Booker', event.booker_name, event.booker_email, event.booker_phone));
    const body = rows.filter(Boolean).join('') || emptyState('No contacts on file yet.');
    return ovCard({ icon: 'address-book', title: 'Promoter / Contacts', help: 'details', body, footerLabel: 'Edit Details', footerTab: 'details' });
  }

  _lineupCard(data) {
    const lineup = data.lineup || [];
    const body = lineup.length
      ? `<ul class="ov-list ov-lineup-list">${lineup.map((item, index) => `<li>
          <span class="ov-role">#${esc(item.billing_order ?? index + 1)}</span>
          <span class="ov-name">${esc(item.display_name)}</span>
          ${index === 0 ? '<span class="chip chip-accent">Headliner</span>' : ''}
          ${item.set_length_minutes ? `<span class="ov-time muted">${esc(item.set_length_minutes)} min</span>` : ''}
        </li>`).join('')}</ul>`
      : emptyState('No lineup yet.');
    return ovCard({ icon: 'music', title: 'Band Lineup', help: 'lineup', body, footerLabel: 'Manage Lineup', footerTab: 'lineup' });
  }

  _logisticsCard(event) {
    const RELEVANT = ['security', 'bar_service', 'sound_production'];
    let body;
    if (this._vendors === null) {
      body = '<p class="muted small">Loading vendors…</p>';
    } else {
      const vendors = this._vendors;
      const relevant = vendors.filter((v) => RELEVANT.includes(v.service_category));
      const shown = relevant.length ? relevant : vendors.slice(0, 5);
      const vendorList = shown.length
        ? `<ul class="ov-list">${shown.map((v) => `<li>
            <span class="ov-role">${esc(titleCase((v.service_category || '').replace(/_/g, ' ')))}</span>
            <span class="ov-name">${esc(v.vendor_name)}${v.contact_name ? ` <span class="muted">(${esc(v.contact_name)})</span>` : ''}</span>
          </li>`).join('')}</ul>`
        : emptyState('No vendors on file yet.');
      const notes = [];
      if (event.av_requirements) notes.push(`<p class="ov-note"><strong>AV / Tech:</strong> ${esc(event.av_requirements)}</p>`);
      if (event.catering_notes) notes.push(`<p class="ov-note"><strong>Catering / Bar:</strong> ${esc(event.catering_notes)}</p>`);
      body = vendorList + notes.join('');
    }
    return ovCard({ icon: 'building', title: 'Venue Ops / Logistics', help: 'vendors', body, footerLabel: 'Manage Vendors', footerTab: 'vendors' });
  }

  _financialCard(data, isPrivate) {
    const event = data.event;
    const stats = [];
    if (!isPrivate) stats.push(ovStat('Ticket Price', money(event.ticket_price)));
    // "Capacity" here is the room's physical/fire-code max (from the resource
    // record on the venue), shown for reference only. The number actually
    // enforced as a hard cap on ticketing/staffing is event.capacity, which
    // staff can set lower than the room max — that stays editable on the
    // Event Details form and isn't duplicated here to avoid confusion.
    stats.push(ovStat('Capacity', event.room_capacity || '—'));
    stats.push(ovStat('Est. Guests', event.estimated_guests || '—'));
    if (event.deposit_amount != null && event.deposit_amount !== '') {
      const tone = DEPOSIT_STATUS_TONE[event.deposit_status] || 'gray';
      stats.push(ovStat('Deposit', `${money(event.deposit_amount)} <span class="chip chip-${esc(tone)}">${esc(statusLabel(event.deposit_status || 'not_required'))}</span>`));
    }
    if (event.potential_revenue != null && event.potential_revenue !== '') {
      stats.push(ovStat('Potential Revenue', money(event.potential_revenue)));
    }
    if (!isPrivate) {
      const isInternalTicketing = event.ticketing_mode === 'internal';
      stats.push(ovStat('Ticketing', isInternalTicketing
        ? '<span class="chip chip-green">In-house</span>'
        : '<span class="chip chip-gray">External</span>'));
      if (isInternalTicketing) {
        stats.push(ovStat('Tickets Sold', event.tickets_sold ?? 0));
      } else {
        const ticketing = event.ticket_url
          ? `<a href="${esc(event.ticket_url)}" target="_blank" rel="noopener noreferrer">On Sale <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>`
          : '<span class="muted">Not listed</span>';
        stats.push(ovStat('Tickets', ticketing));
      }
    }
    // The Settlement tab only exists for non-private events with the
    // capability — match that exact gate here so the footer link never
    // points at a tab that isn't actually mounted.
    const canViewSettlement = can(data, 'view_settlement');
    const hasSettlementTab = canViewSettlement && !isPrivate;
    let settlementBlock = '';
    if (canViewSettlement && data.settlement) {
      const s = data.settlement;
      settlementBlock = `<div class="ov-divider"></div><div class="ov-stat-grid">
        ${ovStat('Gross Ticket Sales', money(s.gross_ticket_sales))}
        ${ovStat('Bar Sales', money(s.bar_sales))}
        ${ovStat('Venue Net', money(s.venue_net))}
      </div>`;
    }
    const body = `<div class="ov-stat-grid">${stats.join('')}</div>${settlementBlock}`;
    return ovCard({ icon: 'dollar-sign', title: 'Financial / Ticketing', help: 'settlement', body, footerLabel: hasSettlementTab ? 'View Settlement' : 'Event Details', footerTab: hasSettlementTab ? 'settlement' : 'details' });
  }

  _notesTasksCard(data) {
    const event = data.event;
    const tasks = (data.tasks || []).slice(0, 6);
    const notesHtml = event.description_internal
      ? `<p class="ov-note">${esc(event.description_internal)}</p>`
      : '';
    const taskList = tasks.length
      ? `<ul class="ov-task-list">${tasks.map((t) => `<li class="ov-task${t.status === 'done' ? ' done' : ''}">
          <span class="ov-check">${t.status === 'done' ? '<i class="fa-solid fa-check" aria-hidden="true"></i>' : ''}</span>
          <span class="ov-task-title">${esc(t.title)}</span>
        </li>`).join('')}</ul>`
      : emptyState('No tasks yet.');
    const body = `${notesHtml}${notesHtml ? '<div class="ov-divider"></div>' : ''}${taskList}`;
    return ovCard({ icon: 'list-check', title: 'Notes / Tasks', help: 'tasks', body, footerLabel: 'View All Tasks', footerTab: 'tasks' });
  }

  _documentsCard(data) {
    const assets = data.assets || [];
    const iconFor = (filename) => /\.(png|jpe?g|gif|webp|svg)$/i.test(filename) ? { label: 'IMG', color: '#f2994a' } : /\.pdf$/i.test(filename) ? { label: 'PDF', color: '#eb5757' } : /\.(xlsx?|csv)$/i.test(filename) ? { label: 'XLS', color: '#219653' } : { label: 'DOC', color: '#556' };
    const body = assets.length
      ? `<div class="ov-doc-grid">${assets.slice(0, 8).map((a) => {
          const kind = iconFor(a.filename || '');
          return `<div class="ov-doc-card">
            <span class="ov-doc-icon" style="background:${kind.color}">${esc(kind.label)}</span>
            <span class="ov-doc-meta"><strong title="${esc(a.title)}">${esc(a.title)}</strong><span>${esc(shortDate(new Date(String(a.created_at).replace(' ', 'T'))))}</span></span>
          </div>`;
        }).join('')}</div>`
      : emptyState('No documents uploaded yet.');
    return ovCard({ icon: 'paperclip', title: 'Documents / Attachments', help: 'assets', body, footerLabel: 'Manage Assets', footerTab: 'assets', wide: true });
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

  // Wire up "Sections" dropdown checkbox changes — hiding a section also
  // removes its tab from the nav; if the hidden section was the active tab,
  // fall back to Overview. All actual show/hide happens via real tabs now
  // (see setActiveTab / _applySectionVisibility), not scroll position.
  _bindSectionToggles(userId, eventId, prefs) {
    $$('[data-section-toggle]', this).forEach(checkbox => {
      checkbox.addEventListener('change', () => {
        prefs[checkbox.dataset.sectionToggle] = checkbox.checked;
        this._savePrefs(userId, eventId, prefs);
        if (!checkbox.checked && this._activeTab === checkbox.dataset.sectionToggle) {
          this._activeTab = 'overview';
        }
        this._renderTabNav();
        this._applySectionVisibility();
      });
    });
  }

  /** Rebuild just the tab nav (visible tabs = not hidden via prefs, plus overview/details always shown). */
  _renderTabNav() {
    const navEl = $('.workspace-tabs', this);
    if (!navEl || !this._tabs) return;
    const visibleTabs = this._tabs.filter((t) => t === 'overview' || t === 'details' || this._prefs[t] !== false);
    if (!visibleTabs.includes(this._activeTab)) this._activeTab = 'overview';
    navEl.innerHTML = visibleTabs.map((tab) => `<a class="${tab === this._activeTab ? 'active' : ''}" href="#${esc(tab)}" data-tab="${esc(tab)}">${esc(SECTION_LABELS[tab] || titleCase(tab))}</a>`).join('');
    $$('a', navEl).forEach((a) => a.addEventListener('click', (event) => {
      event.preventDefault();
      this.setActiveTab(a.dataset.tab);
    }));
    // The set of visible tabs (and so whether the bar overflows at all) can
    // change here — e.g. a Sections toggle hides a tab. Re-check the edge
    // markers; this is just a recompute, not a re-wire (see _wireTabScrollEdges).
    this._updateTabEdges?.();
  }

  /**
   * Show/hide the "<<"/">>" edge markers on .workspace-tabs based on scroll
   * position, and let clicking them scroll the bar. The tab bar's own text
   * happens to wrap almost exactly at the visible width in common cases, so
   * without this there's no visual hint at all that scrolling further reveals
   * more tabs — it just looks like the last tab got cut off mid-word. Wired
   * once (from connect(), after the first _renderTabNav()); _renderTabNav()
   * re-runs on every tab-set change and just calls _updateTabEdges() above,
   * since navEl itself (and so this listener) survives those re-renders.
   */
  _wireTabScrollEdges() {
    const navEl = $('.workspace-tabs', this);
    const wrap = navEl?.closest('.workspace-tabs-wrap');
    const leftBtn = $('.tab-scroll-left', wrap);
    const rightBtn = $('.tab-scroll-right', wrap);
    if (!navEl || !wrap || !leftBtn || !rightBtn) return;

    const update = () => {
      // 1px slop: fractional scroll widths (browser zoom, device pixel ratio)
      // can leave scrollLeft a hair short of the true max.
      const maxScroll = navEl.scrollWidth - navEl.clientWidth;
      leftBtn.hidden = navEl.scrollLeft <= 1;
      rightBtn.hidden = navEl.scrollLeft >= maxScroll - 1;
    };
    this._updateTabEdges = update;

    navEl.addEventListener('scroll', update, { passive: true });
    new ResizeObserver(update).observe(navEl);
    [[leftBtn, -1], [rightBtn, 1]].forEach(([btn, dir]) => {
      btn.addEventListener('click', () => {
        navEl.scrollBy({ left: dir * Math.round(navEl.clientWidth * 0.6), behavior: 'smooth' });
      });
    });

    update();
  }

  /** Show only the active tab's section; every other tracked section is hidden. */
  _applySectionVisibility() {
    if (!this._tabs) return;
    for (const tab of this._tabs) {
      const el = this.querySelector(`#${CSS.escape(tab)}`);
      if (el) el.style.display = tab === this._activeTab ? '' : 'none';
    }
  }

  /** Switch the active tab — cheap, since every section is already mounted; only visibility changes. */
  setActiveTab(tabId) {
    if (!this._tabs?.includes(tabId) || tabId === this._activeTab) return;
    this._activeTab = tabId;
    this._renderTabNav();
    this._applySectionVisibility();
    $('.workspace-tabs', this)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    // Overview cards (and any future in-panel link) can jump to another tab
    // by rendering a `<button data-goto-tab="lineup">` — no custom events,
    // no shadow DOM to cross, just a delegated click listener.
    this.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-goto-tab]');
      if (!trigger) return;
      event.preventDefault();
      this.setActiveTab(trigger.dataset.gotoTab);
    }, { signal: this.abort.signal });
  }

  /** Re-publish the topbar page context and patch the publish button after an in-place event update. */
  _updateHeader(data) {
    const event = data.event;
    const isPrivate = event.event_type === 'private_event';
    publish('page.context', {
      title: `${event.title}${isPrivate ? ' 🔒' : ''}`,
      blurb: `${eventDateRangeLabel(event)} at ${event.venue_name}`,
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
    this._tabs = tabs;
    this._prefs = prefs;
    if (!this._activeTab) this._activeTab = 'overview';
    const sectionsDropdown = `<details class="print-menu sections-menu">
      <summary class="button secondary">Sections &#9662;</summary>
      <div class="print-menu-items">
        ${toggleableTabs.map(t => `<label class="section-toggle-item"><input type="checkbox" data-section-toggle="${esc(t)}"${prefs[t] !== false ? ' checked' : ''}> ${esc(SECTION_LABELS[t] || titleCase(t))}</label>`).join('')}
      </div>
    </details>`;
    publish('page.context', {
      title: `${event.title}${isPrivate ? ' 🔒' : ''}`,
      blurb: `${eventDateRangeLabel(event)} at ${event.venue_name}`,
    });
    this.innerHTML = `<section class="event-top">
      <div>
        <a class="back-link" href="#events">&lt;- Back to Events</a>
      </div>
      <div class="event-actions">
        ${isPrivate ? '' : `<a class="button promote-accent" href="#promote-event-${esc(String(event.id))}"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Promote</a>`}
        ${isPrivate ? '' : `<a class="button secondary" href="${esc(appUrl(data.links.public_page))}" target="_blank" rel="noreferrer">Public Page</a>`}
        ${isPrivate ? '' : `<button class="secondary" data-qr-toggle title="Show a QR code linking to this event's public page"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> QR Code</button>`}
        ${can(data, 'edit_event') ? `<a class="button secondary" href="#new-event-${esc(String(event.id))}" title="Re-run this event through the guided setup wizard, pre-filled with its current details"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Wizard</a>` : ''}
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
        ${can(data, 'manage_contracts') ? `<button class="secondary" data-portal-toggle title="Generate a read-only portal link for a promoter or client"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Share</button>` : ''}
        <!-- "Set as POS Event" hidden for now: Square is used for online ticketing only,
             not an in-venue POS terminal, so there is no pos_location_map to route to.
             Restore the button below (handler + setPosEvent are still wired) once a POS
             register is in use:
             ${'' /* can(data, 'manage_ledger') ? `<button class="secondary" data-pos-set title="Route Square POS bar sales to this event"><i class="fa-solid fa-cash-register" aria-hidden="true"></i> Set as POS Event</button>` : '' */} -->
      </div>
    </section>
    <pb-portal-panel id="portalPanel"></pb-portal-panel>
    <pb-qr-panel id="qrPanel"></pb-qr-panel>
    <pb-event-summary></pb-event-summary>
    <div class="workspace-tabs-wrap">
      <button type="button" class="tab-scroll-edge tab-scroll-left" data-tab-scroll="-1" aria-label="Scroll tabs left" hidden>&laquo;</button>
      <nav class="workspace-tabs tabs"></nav>
      <button type="button" class="tab-scroll-edge tab-scroll-right" data-tab-scroll="1" aria-label="Scroll tabs right" hidden>&raquo;</button>
    </div>
    <pb-event-next-action></pb-event-next-action>
    <section id="overview">
      <pb-event-readiness></pb-event-readiness>
      <pb-event-overview></pb-event-overview>
    </section>
    <section id="details">
      <pb-event-details-form></pb-event-details-form>
      <pb-event-recurrence></pb-event-recurrence>
    </section>
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
    $('pb-event-overview', this).data = data;
    $('pb-event-details-form', this).data = data;
    $('pb-event-recurrence', this).data = data;
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
    const qrPanel = $('pb-qr-panel', this);
    if (qrPanel) {
      qrPanel.data = data;
      $('[data-qr-toggle]', this)?.addEventListener('click', () => qrPanel.toggle());
    }
    $('[data-pos-set]', this)?.addEventListener('click', () => this.setPosEvent(event.id));
    $$('[data-print]', this).forEach((button) => button.addEventListener('click', () => {
      button.closest('details.print-menu')?.removeAttribute('open');
      openPrintWindow(button.dataset.print, this.data);
    }));
    this._renderTabNav();
    this._applySectionVisibility();
    this._bindSectionToggles(userId, event.id, prefs);
    this._wireTabScrollEdges();
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
    // Archived/Settled events are locked to everyone except a venue admin /
    // event owner (edit_settlement) once the nightly auto-complete script or
    // a manual settlement flips the status — see issue #19/#11.
    const isArchivedLocked = ['completed', 'settled'].includes(event.status) && !can(data, 'edit_settlement');
    const editable = can(data, 'edit_event') && !isArchivedLocked;
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
          <label>End Date <input type="date" name="end_date" value="${esc(event.end_date || '')}" min="${esc(event.date)}"${disabled}></label>
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
        <label>End Date <input type="date" name="end_date" value="${esc(event.end_date || '')}" min="${esc(event.date)}"${disabled}></label>
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
        <label>Estimated guests <input type="number" name="estimated_guests" value="${esc(event.estimated_guests || '')}" placeholder="Expected headcount"${disabled}></label>
        <label>Capacity (max) <input type="number" name="capacity" value="${esc(event.capacity || '')}"${disabled}></label>
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
      // Keep the End Date picker's min in sync with Date, and drop a
      // now-invalid End Date rather than silently saving a backwards range.
      if (field.name === 'date') {
        const endDateInput = form.end_date;
        if (endDateInput) {
          endDateInput.min = field.value;
          if (endDateInput.value && endDateInput.value < field.value) endDateInput.value = '';
        }
      }
      save(field.name);
    }));
    // Pressing Enter in a field still saves, but never reloads the page.
    form.addEventListener('submit', (submitEvent) => { submitEvent.preventDefault(); save(); });
  }
}

// ── Recurrence panel (Event Details tab) ──────────────────────────────────────
// Two states: not yet part of a series — embeds <pb-recurrence-fields> plus a
// "Create recurring events" action that POSTs /events/{id}/series — or already
// part of one — a read-only summary + sibling list + "Remove from series".
// Occurrences are fully independent once created; this panel never edits
// siblings, only lists them.
class EventRecurrencePanel extends PanicElement {
  connect() {
    this._eventId = null;
    this._series = undefined; // undefined = not loaded yet
    this._siblings = [];
  }

  set data(data) {
    const event = data.event;
    this._canEdit = can(data, 'edit_event');
    this._anchorDate = event.date;
    const isNewEvent = this._eventId !== event.id;
    this._eventId = event.id;
    if (isNewEvent) {
      this._series = undefined;
      this.load();
    } else if (this._series !== undefined) {
      this.render();
    }
  }

  async load() {
    try {
      const res = await api(`/events/${this._eventId}/series`);
      this._series = res.series || null;
      this._siblings = res.events || [];
    } catch (_) {
      this._series = null;
      this._siblings = [];
    }
    this.render();
  }

  render() {
    if (this._series === undefined) return; // still loading — nothing to show yet
    if (!this._series && !this._canEdit) { this.innerHTML = ''; return; }

    if (this._series) {
      this.innerHTML = `<section class="panel">
        <div class="section-head padded"><h2>Recurrence ${helpLink('recurring-events', 'Recurrence')}</h2></div>
        <div class="padded">
          <p>Part of a series — <strong>${esc(this._series.description || 'Recurring')}</strong> (${this._siblings.length} events).</p>
          <ul class="recurrence-siblings">
            ${this._siblings.map((sibling) => {
              const isThis = Number(sibling.id) === Number(this._eventId);
              const dateLabel = esc(shortDate(new Date(`${sibling.date}T12:00:00`)));
              return `<li>${isThis ? dateLabel : `<a href="#event-${esc(String(sibling.id))}">${dateLabel}</a>`} ${badge(sibling.status)}${isThis ? ' <span class="muted small">(this event)</span>' : ''}</li>`;
            }).join('')}
          </ul>
          ${this._canEdit ? '<button type="button" class="secondary" data-remove-series>Remove this event from the series</button>' : ''}
        </div>
      </section>`;
      $('[data-remove-series]', this)?.addEventListener('click', () => this.removeFromSeries());
      return;
    }

    if (!this._canEdit) { this.innerHTML = ''; return; }

    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Recurrence ${helpLink('recurring-events', 'Recurrence')}</h2></div>
      <div class="grid-form padded">
        <pb-recurrence-fields></pb-recurrence-fields>
        <button type="button" class="wide secondary" data-create-series disabled>Create recurring events</button>
      </div>
    </section>`;

    const fields = $('pb-recurrence-fields', this);
    fields.anchorDate = this._anchorDate;
    const createBtn = $('[data-create-series]', this);
    let current = null;
    fields.addEventListener('change', (event) => {
      current = event.detail;
      createBtn.disabled = !current;
      createBtn.textContent = current
        ? `Create ${current.dates.length} recurring event${current.dates.length === 1 ? '' : 's'}`
        : 'Create recurring events';
    });
    createBtn.addEventListener('click', () => this.createSeries(current, createBtn));
  }

  async createSeries(value, button) {
    if (!value) return;
    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Creating…';
    try {
      const res = await api(`/events/${this._eventId}/series`, {
        method: 'POST',
        body: JSON.stringify({
          pattern: value.pattern,
          description: value.description,
          end_type: value.pattern.endType,
          end_date: value.pattern.endDate || null,
          occurrence_count: value.pattern.occurrenceCount || null,
          dates: value.dates,
        }),
      });
      publish('toast.show', { message: `Created ${res.created_event_ids.length} recurring events.`, tone: 'success' });
      this._series = undefined;
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not create the series.', tone: 'error' });
      button.disabled = false;
      button.textContent = originalLabel;
    }
  }

  async removeFromSeries() {
    if (!confirm('Remove this event from its recurring series? The other events are not affected.')) return;
    try {
      await api(`/events/${this._eventId}/series`, { method: 'DELETE' });
      publish('toast.show', { message: 'Removed from series.', tone: 'success' });
      this._series = undefined;
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not remove from series.', tone: 'error' });
    }
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

/**
 * Collapsible panel (toggled from the "QR Code" header button) showing a
 * scannable QR code for the event's public page, a copy-link action, a
 * straight-to-file PNG download, and a "Save to Assets" button that persists
 * the same code as a downloadable event_assets row (Events\GenerateQr).
 */
class QrPanel extends PanicElement {
  connect() {
    this._open = false;
    this._url  = '';
    this._eventId = null;
    this.render();
  }

  set data(data) {
    this._eventId = data.event.id;
    this._url = appUrl(data.links.public_page);
    if (this._open) this.render();
  }

  toggle() {
    this._open = !this._open;
    this.render();
  }

  render() {
    if (!this._open) {
      this.innerHTML = '';
      return;
    }
    const encoded = encodeURIComponent(this._url);
    const qrImage = appUrl(`assets/qr.svg?text=${encoded}&size=240`);
    const qrDownload = appUrl(`assets/qr.png?text=${encoded}&size=600`);
    this.innerHTML = `<div class="qr-panel card">
      <div class="qr-panel-head">
        <strong>QR Code — Public Page</strong>
        <button class="secondary small" data-qr-close>Close</button>
      </div>
      <p class="qr-panel-blurb">Scans straight to this event's public page. Share it on flyers, table tents, or at the door.</p>
      <div class="qr-panel-body">
        <img class="qr-panel-image" src="${esc(qrImage)}" width="180" height="180" alt="QR code linking to the public event page">
        <div class="qr-panel-actions">
          <input class="qr-panel-url" type="text" readonly value="${esc(this._url)}" onclick="this.select()">
          <div class="inline-actions">
            <button class="secondary small" data-qr-copy>Copy Link</button>
            <a class="button secondary small" href="${esc(qrDownload)}" download="qr-code.png">Download PNG</a>
            <button class="small" data-qr-save-asset>Save to Assets</button>
          </div>
        </div>
      </div>
    </div>`;
    $('[data-qr-close]', this)?.addEventListener('click', () => this.toggle());
    $('[data-qr-copy]', this)?.addEventListener('click', () => {
      navigator.clipboard.writeText(this._url).then(() => publish('toast.show', { message: 'Link copied!' }));
    });
    $('[data-qr-save-asset]', this)?.addEventListener('click', async (event) => {
      const btn = event.currentTarget;
      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span>Saving…';
      try {
        await api(`/events/${this._eventId}/assets/generate-qr`, { method: 'POST' });
        publish('toast.show', { message: 'QR code saved to this event’s Assets tab.' });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Could not save QR code as an asset.', tone: 'error' });
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
      }
    });
  }
}

customElements.define('pb-event-workspace', EventWorkspace);
customElements.define('pb-event-summary', EventSummary);
customElements.define('pb-event-readiness', EventReadiness);
customElements.define('pb-event-next-action', EventNextAction);
customElements.define('pb-event-overview', EventOverview);
customElements.define('pb-event-details-form', EventDetailsForm);
customElements.define('pb-event-recurrence', EventRecurrencePanel);
customElements.define('pb-portal-panel', PortalPanel);
customElements.define('pb-qr-panel', QrPanel);

// ── Event Payments panel ─────────────────────────────────────────────────────
// Lists event_payments rows, and lets a manage_payments user add/edit/void a
// payment record, generate a Stripe "Send Invoice Link" for a pending or
// invoiced one, or (waive_deposit capability) waive the event's deposit.
//
// Fallback enum lists — overwritten from the live GET response's payment_types
// / methods, but used as select-option sources before the first load resolves.
const PAYMENT_TYPES_FALLBACK = ['deposit','balance_payment','refund','credit','adjustment','promoter_payment','client_payment','other'];
const PAYMENT_METHODS_FALLBACK = ['cash','check','ach','wire','credit_card','stripe','square','venmo','zelle','other'];
const PAYMENT_STATUS_TONE = { pending: 'amber', received: 'green', invoiced: 'gray', failed: 'red', refunded: 'gray', voided: 'gray' };
const PAYMENT_METHOD_LABEL = { ach: 'ACH' };
const paymentMethodLabel = (m) => PAYMENT_METHOD_LABEL[m] || titleCase(m);

class EventPayments extends PanicElement {
  set eventId(id) { this._eventId = id; }
  set data(d)     { this._data = d; this._load(); }

  // Lets the shared refreshSection() helper (which reads component.eventData
  // .event.id, re-fetches /events/{id}, reassigns .data — retriggering _load()
  // below — and broadcasts the fresh payload to sibling cards) work here too,
  // same as it does for the record-list panels in event-panels.js.
  get eventData() { return { event: { id: this._eventId } }; }

  async _load() {
    const id = this._eventId;
    if (!id) return;
    try {
      const res = await api(`/events/${id}/payments`);
      this._paymentTypes = res.payment_types || PAYMENT_TYPES_FALLBACK;
      this._methods      = res.methods       || PAYMENT_METHODS_FALLBACK;
      this._render(res);
    } catch (err) {
      this.innerHTML = `<section class="panel" id="payments"><div class="section-head padded"><h2>Payments</h2></div><p class="muted padded">Could not load payments.</p></section>`;
    }
  }

  _render(res) {
    const id        = this._eventId;
    const payments  = res.payments || [];
    const summary   = res.summary  || {};
    const canManage = can(this._data, 'manage_payments');
    const canWaive  = can(this._data, 'waive_deposit');

    const statusChip = (s) => {
      const tone = PAYMENT_STATUS_TONE[s] || '';
      return `<span class="chip${tone ? ` chip-${esc(tone)}` : ''}">${esc(titleCase(s))}</span>`;
    };

    const rows = payments.length
      ? payments.map(p => {
          const sendBtn = canManage && ['pending','invoiced'].includes(p.status)
            ? `<button class="small secondary" data-send-link="${esc(String(p.id))}" title="Create or re-send a Stripe payment link">Send Invoice Link</button>`
            : '';
          const editBtn = canManage
            ? `<button class="small secondary" data-edit-payment="${esc(String(p.id))}" title="Edit this payment record">Edit</button>`
            : '';
          const voidBtn = canManage
            ? `<button class="small danger" data-void-payment="${esc(String(p.id))}" title="Void this payment record">Void</button>`
            : '';
          const linkCell = p.external_ref
            ? `<span class="muted small" title="${esc(p.external_ref)}">Link sent</span>`
            : '';
          return `<tr>
            <td>${esc(titleCase(p.payment_type))}</td>
            <td>${esc(money(p.amount))} ${esc(p.currency || 'USD')}</td>
            <td>${statusChip(p.status)}</td>
            <td>${p.method ? esc(paymentMethodLabel(p.method)) : '—'}</td>
            <td>${esc(p.due_date || '—')}</td>
            <td class="row-actions">${linkCell}${sendBtn}${editBtn}${voidBtn}</td>
          </tr>`;
        }).join('')
      : `<tr><td colspan="6" class="muted">${emptyState('No payment records yet.')}</td></tr>`;

    // Only offer waiving while the deposit is actually outstanding — once it's
    // received or already waived, re-waiving has no meaningful effect.
    const showWaive = canWaive && summary.deposit_required > 0
      && !['received', 'waived'].includes(summary.deposit_status);

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
      </div>
      ${showWaive ? `<span class="summary-spacer"></span><button type="button" class="small secondary" data-waive-deposit>Waive Deposit</button>` : ''}` : '';

    this.innerHTML = `
      <section class="panel" id="payments">
        <div class="section-head padded">
          <h2>Payments ${helpLink('payments', 'Payments')}</h2>
          <div class="section-head-actions">
            ${canManage ? `<button type="button" class="small" data-add-payment><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Payment</button>` : ''}
          </div>
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

    $$('[data-send-link]', this).forEach(btn => {
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

    $('[data-add-payment]', this)?.addEventListener('click', () => this._openPaymentForm());
    $$('[data-edit-payment]', this).forEach(btn => {
      btn.addEventListener('click', () => {
        const payment = payments.find(p => p.id === parseInt(btn.dataset.editPayment, 10));
        if (payment) this._openPaymentForm(payment);
      });
    });
    $$('[data-void-payment]', this).forEach(btn => {
      btn.addEventListener('click', () => this._voidPayment(parseInt(btn.dataset.voidPayment, 10)));
    });
    $('[data-waive-deposit]', this)?.addEventListener('click', () => this._waiveDeposit());
  }

  // ── Add / Edit payment modal ─────────────────────────────────────────────
  // Type and Direction are set at creation only — the update endpoint doesn't
  // accept them, so an edit only ever touches amount/status/method/due/notes.
  _openPaymentForm(existing) {
    const isEdit  = Boolean(existing);
    const id      = this._eventId;
    const types   = this._paymentTypes || PAYMENT_TYPES_FALLBACK;
    const methods = this._methods      || PAYMENT_METHODS_FALLBACK;

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded">
        <h2>${isEdit ? 'Edit Payment' : 'Add Payment'}</h2>
        <button type="button" class="small secondary" data-close>Close</button>
      </div>
      <form class="grid-form padded" data-payment-form>
        ${isEdit ? `<p class="muted wide" style="margin:0 0 4px">${esc(titleCase(existing.payment_type))} &middot; ${esc(titleCase(existing.direction))}</p>` : `
        <label>Type
          <select name="payment_type">${types.map(t => `<option value="${esc(t)}" ${t === 'deposit' ? 'selected' : ''}>${esc(titleCase(t))}</option>`).join('')}</select>
        </label>
        <label>Direction
          <select name="direction">
            <option value="received" selected>Received (inbound)</option>
            <option value="paid_out">Paid out (outbound)</option>
          </select>
        </label>`}
        <label>Amount
          <input type="number" name="amount" step="0.01" min="0.01" required value="${existing ? esc(existing.amount) : ''}">
        </label>
        <label>Status
          <select name="status">
            ${['pending','invoiced','received','failed','refunded','voided'].map(s => `<option value="${esc(s)}" ${(existing ? existing.status : 'received') === s ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}
          </select>
        </label>
        <label>Method <span class="muted small">(optional)</span>
          <select name="method">
            <option value="">&mdash; none &mdash;</option>
            ${methods.map(m => `<option value="${esc(m)}" ${existing?.method === m ? 'selected' : ''}>${esc(paymentMethodLabel(m))}</option>`).join('')}
          </select>
        </label>
        <label>Due date <span class="muted small">(optional)</span>
          <input type="date" name="due_date" value="${existing?.due_date ? esc(String(existing.due_date).slice(0, 10)) : ''}">
        </label>
        <label class="wide">Notes <span class="muted small">(optional)</span>
          <textarea name="notes" rows="3">${existing?.notes ? esc(existing.notes) : ''}</textarea>
        </label>
        <div class="wide form-actions">
          <button type="submit" class="primary">${isEdit ? 'Save changes' : 'Add payment'}</button>
          <button type="button" class="secondary" data-close>Cancel</button>
        </div>
        <p class="error-text wide" data-error></p>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach(b => b.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('input[name="amount"]', dialog)?.focus();

    $('[data-payment-form]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const form  = e.target;
      const errEl = $('[data-error]', form);
      errEl.textContent = '';
      const fd = formData(form);
      const body = {
        amount:   Number(fd.amount),
        status:   fd.status,
        method:   fd.method || null,
        due_date: fd.due_date || null,
        notes:    fd.notes || null,
      };
      if (!isEdit) {
        body.payment_type = fd.payment_type;
        body.direction    = fd.direction;
      }
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      try {
        if (isEdit) {
          await api(`/events/${id}/payments/${existing.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Payment updated.' });
        } else {
          await api(`/events/${id}/payments`, { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Payment added.' });
        }
        close();
        await refreshSection(this);
      } catch (err) {
        errEl.textContent = err.message || 'Something went wrong.';
        btn.disabled = false;
      }
    });
  }

  // ── Void ──────────────────────────────────────────────────────────────────
  async _voidPayment(pid) {
    if (!confirm('Void this payment record? Voided records drop off this list (they no longer count toward totals) but stay in the audit trail.')) return;
    try {
      await api(`/events/${this._eventId}/payments/${pid}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Payment voided.' });
      await refreshSection(this);
    } catch (err) {
      publish('toast.show', { message: err.message || 'Failed to void payment.', tone: 'error' });
    }
  }

  // ── Waive deposit ─────────────────────────────────────────────────────────
  async _waiveDeposit() {
    const reason = prompt('Reason for waiving the deposit (required):');
    if (reason === null) return;
    if (!reason.trim()) {
      publish('toast.show', { message: 'A reason is required to waive the deposit.', tone: 'error' });
      return;
    }
    try {
      // The paymentId path segment is required by the router but unused by
      // the handler — waiving is an event-level action, not tied to a row.
      await api(`/events/${this._eventId}/payments/0/waive-deposit`, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.trim() }),
      });
      publish('toast.show', { message: 'Deposit waived.' });
      await refreshSection(this);
    } catch (err) {
      publish('toast.show', { message: err.message || 'Failed to waive deposit.', tone: 'error' });
    }
  }
}

customElements.define('pb-event-payments', EventPayments);
