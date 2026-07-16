// ── Upcoming Events (Events ▸ Upcoming) ──────────────────────────────────────
// A card-based, ticketing-aware alternative to the plain Events ▸ List table:
// poster thumbnail + support-act line per show, a live filters sidebar (date
// range w/ mini calendar, event type, ticket-sales-state checkboxes, search),
// and a stats footer (upcoming count / tickets sold / est. gross / avg
// capacity). Reuses the same `/events` endpoint as List/Dashboard/Calendar —
// see Events::index()'s attachListExtras()/upcomingStats() on the backend —
// just opts into the extra per-event ticketing fields via `with_stats=1`.
import { esc, titleCase, assetUrl, publish, subscribe, api, eventDate, longDate, isoDate, addDays, timeLabel, money, badge, statusLabel, emptyState, PanicElement, $, $$ } from './core.js';
import { openEventQuickCreate } from './event-views.js';

const SALES_LABELS = { on_sale: 'On Sale', low_tickets: 'Low Tickets', sold_out: 'Sold Out', free: 'Free Event' };

// Status-filter checkboxes in the sidebar. Each maps either to a computed
// `sales_state` bucket or (for "canceled") directly to event.status — see
// bucketFor() below. Canceled defaults unchecked so canceled shows don't
// clutter an "upcoming" view.
const STATUS_FILTERS = [
  { key: 'on_sale', label: 'On Sale', tone: 'green', defaultOn: true },
  { key: 'low_tickets', label: 'Low Tickets', tone: 'amber', defaultOn: true },
  { key: 'sold_out', label: 'Sold Out', tone: 'red', defaultOn: true },
  { key: 'free', label: 'Free Events', tone: 'blue', defaultOn: true },
  { key: 'canceled', label: 'Canceled', tone: 'gray', defaultOn: false },
];

const RANGE_PRESETS = [
  { key: '7', label: 'Next 7 Days' },
  { key: '30', label: 'Next 30 Days' },
  { key: '90', label: 'Next 90 Days' },
  { key: 'month', label: 'This Month' },
  { key: 'all', label: 'All Upcoming' },
  { key: 'custom', label: 'Custom Range' },
];

const CARD_PAGE_SIZE = 6;

// Strip light markdown/whitespace down to a single-line teaser for the card
// subtitle when an event has no lineup to summarize ("with X, Y").
function teaser(text, max = 80) {
  if (!text) return '';
  const plain = String(text).replace(/[#*_`>[\]]/g, '').replace(/\s+/g, ' ').trim();
  return plain.length > max ? `${plain.slice(0, max - 1)}…` : plain;
}

function bucketFor(event) {
  return event.status === 'canceled' ? 'canceled' : event.sales_state;
}

class EventsUpcoming extends PanicElement {
  async connect() {
    this.rangePreset = '30';
    this.customStart = null;
    this.customEnd = null;
    this.calMonth = new Date();
    this.pendingRangeStart = null;
    this.visibleCount = CARD_PAGE_SIZE;
    this.filters = {
      search: '',
      eventType: '',
      status: Object.fromEntries(STATUS_FILTERS.map((f) => [f.key, f.defaultOn])),
    };
    subscribe('events.search', ({ query }) => { this.filters.search = query || ''; this.visibleCount = CARD_PAGE_SIZE; this.renderList(); }, this.abort.signal);
    document.addEventListener('click', (e) => this.closeMenus(e), { signal: this.abort.signal });
    await this.load();
  }

  // ── Data loading ───────────────────────────────────────────────────────────

  resolveRange() {
    const today = isoDate(new Date());
    switch (this.rangePreset) {
      case '7': return [today, isoDate(addDays(new Date(), 6))];
      case '90': return [today, isoDate(addDays(new Date(), 89))];
      case 'month': {
        const now = new Date();
        return [today, isoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0))];
      }
      case 'all': return [today, null];
      case 'custom': return [this.customStart || today, this.customEnd || isoDate(addDays(new Date(), 29))];
      default: return [today, isoDate(addDays(new Date(), 29))]; // '30'
    }
  }

  rangeLabel() {
    if (!this._rangeStart) return '';
    const fmt = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    return this._rangeEnd ? `${fmt(this._rangeStart)} – ${fmt(this._rangeEnd)}` : `${fmt(this._rangeStart)} onward`;
  }

  async load() {
    this.setLoading('Loading events');
    const [start, end] = this.resolveRange();
    this._rangeStart = start;
    this._rangeEnd = end;
    const params = new URLSearchParams({ with_stats: '1', start_date: start });
    if (end) params.set('end_date', end);
    if (this.filters.eventType) params.set('event_type', this.filters.eventType);
    try {
      this.data = await api(`/events?${params.toString()}`);
      publish('events.loaded', this.data);
      this.visibleCount = CARD_PAGE_SIZE;
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────

  render() {
    const venues = this.data.venues || [];
    const venuePrimary = venues[0];
    const blurb = venues.length === 1 && venuePrimary
      ? `${venuePrimary.name}${venuePrimary.city ? ` • ${[venuePrimary.city, venuePrimary.state].filter(Boolean).join(', ')}` : ''}`
      : `${venues.length} venues`;
    publish('page.context', { title: 'Upcoming Events', blurb });
    const canCreate = Boolean(this.data.capabilities?.create_events);

    this.innerHTML = `<section class="upcoming-page">
      <div class="upcoming-toolbar">
        <select class="upcoming-range-select" data-range aria-label="Date range preset">
          ${RANGE_PRESETS.map((p) => `<option value="${p.key}" ${this.rangePreset === p.key ? 'selected' : ''}>${esc(p.label)}</option>`).join('')}
        </select>
        <div class="upcoming-toolbar-actions">
          <div class="cal-view-toggle" role="group" aria-label="Events view">
            <button class="secondary small active" type="button" tabindex="-1"><i class="fa-solid fa-list" aria-hidden="true"></i><span class="cal-view-label"> List</span></button>
            <a class="secondary small button" href="#calendar"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span class="cal-view-label"> Calendar</span></a>
          </div>
          ${canCreate ? '<button class="button" type="button" data-action="new-event"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Event</button>' : ''}
        </div>
      </div>
      <div class="upcoming-layout">
        <div class="upcoming-main">
          <div class="upcoming-cards" data-cards></div>
          <div class="upcoming-footer" data-stats></div>
        </div>
        <aside class="upcoming-sidebar panel">
          <div class="section-head padded"><h2>Filters</h2><button class="upcoming-clear" type="button" data-clear-filters>Clear all</button></div>
          <div class="padded upcoming-filters-body">
            <label class="upcoming-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" data-filter-search placeholder="Search filters…" value="${esc(this.filters.search)}" aria-label="Search events"></label>

            <div class="upcoming-filter-group">
              <h3>Date Range</h3>
              <p class="upcoming-range-label" data-range-label>${esc(this.rangeLabel())}</p>
              <div class="upcoming-minical" data-minical></div>
            </div>

            <div class="upcoming-filter-group">
              <h3>Event Type</h3>
              <select data-filter-type>
                <option value="">All Types</option>
                ${(this.data.types || []).map((t) => `<option value="${esc(t)}" ${this.filters.eventType === t ? 'selected' : ''}>${esc(titleCase(t))}</option>`).join('')}
              </select>
            </div>

            <div class="upcoming-filter-group">
              <h3>Status</h3>
              <ul class="upcoming-status-list">
                ${STATUS_FILTERS.map((f) => `<li><label class="checkbox-inline"><input type="checkbox" data-filter-status="${f.key}" ${this.filters.status[f.key] ? 'checked' : ''}> <span class="status-dot ${f.tone}"></span>${esc(f.label)}</label></li>`).join('')}
              </ul>
            </div>

            ${venues.length ? `<div class="upcoming-venue-card">
              ${venues.map((v) => `<strong>${esc(v.name)}</strong><p class="muted">${esc([v.address, [v.city, v.state].filter(Boolean).join(', ')].filter(Boolean).join(' • '))}</p>`).join('')}
              ${venues.length === 1 ? '<p class="muted small">Single venue view</p>' : ''}
            </div>` : ''}

            <button class="button secondary upcoming-export" type="button" data-export><i class="fa-solid fa-download" aria-hidden="true"></i> Export Events</button>
          </div>
        </aside>
      </div>
    </section>`;

    $('[data-range]', this).addEventListener('change', (e) => {
      this.rangePreset = e.target.value;
      this.pendingRangeStart = null;
      if (this.rangePreset === 'custom') {
        this.calMonth = new Date();
        $('[data-range-label]', this).textContent = 'Pick a start and end date below';
        this.renderMiniCal();
      } else {
        this.load();
      }
    });
    $('[data-action="new-event"]', this)?.addEventListener('click', () => openEventQuickCreate());
    $('[data-filter-search]', this).addEventListener('input', (e) => { this.filters.search = e.target.value; this.visibleCount = CARD_PAGE_SIZE; this.renderList(); });
    $('[data-filter-type]', this).addEventListener('change', (e) => { this.filters.eventType = e.target.value; this.load(); });
    $$('[data-filter-status]', this).forEach((input) => {
      input.addEventListener('change', () => { this.filters.status[input.dataset.filterStatus] = input.checked; this.visibleCount = CARD_PAGE_SIZE; this.renderList(); });
    });
    $('[data-clear-filters]', this).addEventListener('click', () => {
      this.filters.search = '';
      this.filters.eventType = '';
      this.filters.status = Object.fromEntries(STATUS_FILTERS.map((f) => [f.key, f.defaultOn]));
      this.visibleCount = CARD_PAGE_SIZE;
      const typeChanged = $('[data-filter-type]', this).value !== '';
      if (typeChanged) { this.load(); } else { this.render(); }
    });
    $('[data-export]', this).addEventListener('click', () => this.exportCsv());

    this.renderMiniCal();
    this.renderStats();
    this.renderList();
  }

  // ── Stats footer (scoped to the fetched date/type range; independent of the
  // client-side search/status filters below, which only narrow what's shown) ─

  renderStats() {
    const el = $('[data-stats]', this);
    if (!el) return;
    const s = this.data.stats || {};
    const range = esc(this.rangeLabel());
    el.innerHTML = `
      <article class="metric-card"><span class="icon-bubble"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span><h3>Upcoming Events</h3><strong>${esc(s.upcoming_count ?? 0)}</strong><p>${range}</p></article>
      <article class="metric-card"><span class="icon-bubble"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span><h3>Tickets Sold</h3><strong>${esc(s.tickets_sold ?? 0)}</strong><p>${range}</p></article>
      <article class="metric-card green"><span class="icon-bubble green"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span><h3>Est. Gross Revenue</h3><strong>${esc(money(s.gross_revenue ?? 0))}</strong><p>${range}</p></article>
      <article class="metric-card"><span class="icon-bubble"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i></span><h3>Avg. Capacity</h3><strong>${esc(s.avg_capacity_pct ?? 0)}%</strong><p>${range}</p></article>`;
  }

  // ── Card list (client-side search/status filter + "view more" paging) ─────

  filteredEvents() {
    const events = (this.data.events || []).slice()
      .sort((a, b) => `${a.date || ''} ${a.show_time || ''}`.localeCompare(`${b.date || ''} ${b.show_time || ''}`));
    const q = this.filters.search.trim().toLowerCase();
    return events.filter((event) => {
      const bucket = bucketFor(event);
      if (bucket && !this.filters.status[bucket]) return false;
      if (q) {
        const haystack = [event.title, ...(event.support_acts || []), event.venue_name].filter(Boolean).join(' ').toLowerCase();
        if (!haystack.includes(q)) return false;
      }
      return true;
    });
  }

  renderList() {
    const cardsEl = $('[data-cards]', this);
    if (!cardsEl) return;
    const filtered = this.filteredEvents();
    const visible = filtered.slice(0, this.visibleCount);
    const remaining = filtered.length - visible.length;
    cardsEl.innerHTML = visible.length
      ? visible.map((event) => this.card(event)).join('')
      : emptyState('No events match these filters.');
    cardsEl.insertAdjacentHTML('beforeend', remaining > 0
      ? `<button class="upcoming-more" type="button" data-view-more>View ${remaining} more event${remaining === 1 ? '' : 's'} <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>`
      : '');
    $('[data-view-more]', cardsEl)?.addEventListener('click', () => { this.visibleCount += CARD_PAGE_SIZE; this.renderList(); });
    $$('[data-menu-toggle]', cardsEl).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const menu = btn.nextElementSibling;
        const willOpen = menu.hidden;
        this.closeMenus();
        menu.hidden = !willOpen;
      });
    });
    // Whole card opens the event, matching the calendar grid's day-cell
    // pattern — but not when the click landed on a link/button (title link,
    // the "..." menu toggle, or a menu item), which handle their own action.
    $$('[data-event-card]', cardsEl).forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('a, button')) return;
        location.hash = `event-${card.dataset.eventCard}`;
      });
      card.addEventListener('keydown', (e) => {
        if (e.target.closest('a, button')) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); location.hash = `event-${card.dataset.eventCard}`; }
      });
    });
  }

  card(event) {
    const d = eventDate(event);
    const dow = d ? d.toLocaleDateString(undefined, { weekday: 'short' }).toUpperCase() : '';
    const mon = d ? d.toLocaleDateString(undefined, { month: 'short' }).toUpperCase() : '';
    const day = d ? d.getDate() : '—';
    const thumb = event.flyer_path
      ? `<img src="${esc(assetUrl(event.flyer_path))}" alt="" loading="lazy">`
      : '<span class="upcoming-thumb-fallback"><i class="fa-solid fa-image" aria-hidden="true"></i></span>';
    const subtitle = event.support_acts?.length
      ? `with ${event.support_acts.join(', ')}`
      : (teaser(event.description_public) || titleCase(event.event_type));
    const bucket = bucketFor(event);
    const stateBadge = bucket === 'canceled'
      ? badge('canceled')
      : (bucket ? `<span class="badge sales-${esc(bucket)}">${esc(SALES_LABELS[bucket])}</span>` : `<span class="badge status-${esc(event.status)}">${esc(statusLabel(event.status))}</span>`);
    const soldLine = event.tickets_sold !== null && event.tickets_sold !== undefined
      ? `${event.tickets_sold} / ${event.capacity ?? '—'} sold`
      : (event.capacity ? `Capacity ${event.capacity}` : '');
    const priceLine = event.price_min !== null && event.price_min !== undefined
      ? (event.price_min === event.price_max ? money(event.price_min) : `${money(event.price_min)} – ${money(event.price_max)}`)
      : '';
    const venueLine = [event.venue_name, [event.venue_city, event.venue_state].filter(Boolean).join(', ')].filter(Boolean).join(' • ');

    return `<article class="upcoming-card" data-event-card="${esc(event.id)}" tabindex="0" role="link" aria-label="Open ${esc(event.title)}">
      <div class="upcoming-date" title="${esc(longDate(d))}"><span class="upcoming-dow">${esc(dow)}</span><span class="upcoming-mon">${esc(mon)}</span><span class="upcoming-day">${esc(day)}</span></div>
      <div class="upcoming-thumb">${thumb}</div>
      <div class="upcoming-body">
        <div class="upcoming-time muted small">${esc(timeLabel(event.show_time))}</div>
        <h3><a href="#event-${esc(event.id)}">${esc(event.title)}</a></h3>
        ${subtitle ? `<p class="muted upcoming-sub">${esc(subtitle)}</p>` : ''}
        ${venueLine ? `<p class="upcoming-venue muted small"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> ${esc(venueLine)}</p>` : ''}
      </div>
      <div class="upcoming-meta">
        ${stateBadge}
        ${soldLine ? `<p class="upcoming-sold">${esc(soldLine)}</p>` : ''}
        ${priceLine ? `<p class="upcoming-price">${esc(priceLine)}</p>` : ''}
      </div>
      <div class="upcoming-actions">
        <button class="icon-btn" data-menu-toggle type="button" aria-haspopup="true" aria-label="More actions for ${esc(event.title)}"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
        <div class="upcoming-menu" hidden role="menu">
          <a href="#event-${esc(event.id)}" role="menuitem">Open event</a>
          <a href="#promote-event-${esc(event.id)}" role="menuitem">Promote</a>
        </div>
      </div>
    </article>`;
  }

  closeMenus(e) {
    $$('.upcoming-menu:not([hidden])', this).forEach((menu) => {
      if (!e || (!menu.contains(e.target) && menu.previousElementSibling !== e.target && !menu.previousElementSibling?.contains(e.target))) {
        menu.hidden = true;
      }
    });
  }

  // ── Mini calendar (date-range picker for the Custom Range preset) ─────────

  renderMiniCal() {
    const el = $('[data-minical]', this);
    if (!el) return;
    const month = this.calMonth;
    const label = month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const startPad = first.getDay();
    const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
    const todayIso = isoDate(new Date());
    const rangeStart = this._rangeStart;
    const rangeEnd = this._rangeEnd;
    const cells = [];
    for (let i = 0; i < startPad; i++) cells.push('<span class="mini-cal-day is-pad"></span>');
    for (let day = 1; day <= daysInMonth; day++) {
      const iso = isoDate(new Date(month.getFullYear(), month.getMonth(), day));
      const inRange = this.rangePreset === 'custom' && rangeStart && iso >= rangeStart && (!rangeEnd || iso <= rangeEnd);
      const isEdge = iso === rangeStart || iso === rangeEnd;
      const classes = ['mini-cal-day'];
      if (inRange) classes.push('in-range');
      if (isEdge && this.rangePreset === 'custom') classes.push('is-edge');
      if (iso === todayIso) classes.push('is-today');
      cells.push(`<button type="button" class="${classes.join(' ')}" data-day="${iso}">${day}</button>`);
    }
    el.innerHTML = `
      <div class="mini-cal-head">
        <button type="button" class="icon-btn" data-cal-prev aria-label="Previous month"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
        <strong>${esc(label)}</strong>
        <button type="button" class="icon-btn" data-cal-next aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
      </div>
      <div class="mini-cal-weekdays">${['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((w) => `<span>${w}</span>`).join('')}</div>
      <div class="mini-cal-grid">${cells.join('')}</div>`;
    $('[data-cal-prev]', el).addEventListener('click', () => { this.calMonth = new Date(month.getFullYear(), month.getMonth() - 1, 1); this.renderMiniCal(); });
    $('[data-cal-next]', el).addEventListener('click', () => { this.calMonth = new Date(month.getFullYear(), month.getMonth() + 1, 1); this.renderMiniCal(); });
    $$('[data-day]', el).forEach((btn) => btn.addEventListener('click', () => this.pickDay(btn.dataset.day)));
  }

  // First click on the mini calendar starts a fresh custom range (switching
  // the preset to Custom); the second click completes it and refetches.
  pickDay(iso) {
    if (this.rangePreset !== 'custom' || !this.pendingRangeStart) {
      this.rangePreset = 'custom';
      this.pendingRangeStart = iso;
      this._rangeStart = iso;
      this._rangeEnd = null;
      const select = $('[data-range]', this);
      if (select) select.value = 'custom';
      const label = $('[data-range-label]', this);
      if (label) label.textContent = 'Pick an end date…';
      this.renderMiniCal();
      return;
    }
    const start = this.pendingRangeStart;
    this.customStart = iso < start ? iso : start;
    this.customEnd = iso < start ? start : iso;
    this.pendingRangeStart = null;
    this.load();
  }

  // ── Export (client-side CSV of the currently filtered set — no export
  // endpoint on the backend to reuse, and everything needed is already
  // loaded) ──────────────────────────────────────────────────────────────────

  exportCsv() {
    const rows = this.filteredEvents();
    const header = ['Date', 'Time', 'Title', 'Venue', 'Status', 'Tickets Sold', 'Capacity', 'Price Min', 'Price Max'];
    const csvRow = (cells) => cells.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',');
    const lines = [csvRow(header)].concat(rows.map((event) => csvRow([
      event.date || '', event.show_time || '', event.title || '', event.venue_name || '',
      bucketFor(event) || event.status, event.tickets_sold ?? '', event.capacity ?? '', event.price_min ?? '', event.price_max ?? '',
    ])));
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `upcoming-events-${isoDate(new Date())}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }
}
customElements.define('pb-events-upcoming', EventsUpcoming);
