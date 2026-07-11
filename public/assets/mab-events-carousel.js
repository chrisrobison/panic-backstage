/**
 * <mab-events-carousel>
 *
 * Drop-in replacement for themab.org's static "Upcoming events" carousel
 * (<section id="events" class="mab-hero-events">). Fetches events from
 * GET /api/feed/events.json (see src/Feed.php) and renders them using the
 * *exact same markup/class names* the WordPress theme's hand-written HTML
 * used — mab-hero-events, mab-filter-scroller, uk-subnav, mab-unified-filter,
 * mab-coverflow, mab-cover-item, mab-cover-info, mab-date-block,
 * mab-event-subtitle, mab-event-body-text, mab-outline-btn, etc. — so the
 * site's existing theme CSS (and any UIkit styling already loaded on the
 * page) applies to it with zero changes.
 *
 * Deliberately renders into **light DOM** (no shadow root): a shadow root
 * would isolate all of the classes above from the page's stylesheet and
 * defeat the entire "drop-in" premise. Filtering is handled by this
 * component's own vanilla JS rather than depending on UIkit's `uk-filter`
 * JS behavior, so the widget works whether or not UIkit is present on the
 * host page — it only *borrows* UIkit's CSS when available.
 *
 * Usage:
 *   <section id="events" class="mab-hero-events" aria-label="Upcoming events">
 *     <mab-events-carousel feed="https://panicbooking.com/backstage/api/feed/events.json"></mab-events-carousel>
 *   </section>
 *
 * Attributes (all optional):
 *   feed    Full URL of the JSON feed. Defaults to Panic Backstage's
 *           production feed for Mabuhay Gardens, so it works with zero
 *           configuration when dropped into themab.org.
 *   venue   Venue slug, passed through as ?venue= on the feed request.
 *   days    Only show events within the next N days.
 *   limit   Cap the number of events fetched.
 *
 * Emits:
 *   "mab-events-loaded" (bubbles)  detail: { count }        — after a
 *       successful render.
 *   "mab-events-error"  (bubbles)  detail: { error }         — on fetch
 *       failure (the component also renders an inline message).
 */
(function () {
  'use strict';

  // Auto-detect the app's own base URL from this <script>'s own src, the same
  // way public/assets/core.js resolves apiUrl()/appUrl() — so the widget
  // works unmodified whether Panic Backstage is mounted at the domain root
  // or under a path prefix (e.g. https://panicbooking.com/backstage/), and
  // whether the embedding page is same-origin (the demo page) or a totally
  // different domain (themab.org). Captured at module-load time: outside a
  // synchronously-executing top-level <script>, document.currentScript is
  // null, which is exactly why this must run here and not inside a method.
  const scriptEl = document.currentScript
    || Array.from(document.querySelectorAll('script[src*="mab-events-carousel.js"]')).pop();
  const scriptUrl = new URL((scriptEl && scriptEl.src) || location.href, location.href);
  const appBaseUrl = new URL('..', scriptUrl); // assets/mab-events-carousel.js -> app root
  const DEFAULT_FEED = new URL('api/feed/events.json', appBaseUrl).toString();

  const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  const CATEGORY_ORDER = ['live-music', 'comedy', 'dance'];

  /** Only allow http(s) URLs into href/src attributes — never javascript:, data:, etc. */
  function safeUrl(url) {
    if (typeof url !== 'string') return '';
    try {
      const parsed = new URL(url, window.location.href);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
    } catch (e) {
      return '';
    }
  }

  function el(tag, props, children) {
    const node = document.createElement(tag);
    if (props) {
      for (const key of Object.keys(props)) {
        if (key === 'class') node.className = props[key];
        else if (key === 'text') node.textContent = props[key];
        else if (key.startsWith('data-')) node.setAttribute(key, props[key]);
        else node.setAttribute(key, props[key]);
      }
    }
    (children || []).forEach((c) => c && node.appendChild(c));
    return node;
  }

  function monthToken(dateStr) {
    // "2026-07-15" -> "jul-26" (matches the WP theme's own month-jul-26 class scheme)
    const [y, m] = dateStr.split('-');
    const abbr = MONTH_NAMES[parseInt(m, 10) - 1].slice(0, 3).toLowerCase();
    return abbr + '-' + y.slice(2);
  }

  function monthLabel(dateStr) {
    const [y, m] = dateStr.split('-');
    return MONTH_NAMES[parseInt(m, 10) - 1] + ' ' + y;
  }

  class MabEventsCarousel extends HTMLElement {
    static get observedAttributes() {
      return ['feed', 'venue', 'days', 'limit', 'past'];
    }

    constructor() {
      super();
      this._events = [];
      this._activeCategory = null; // null = ALL
      this._activeMonth = null; // null = ALL
      this._activeView = 'coverflow';
      this._modal = null;
      this._loaded = false;
    }

    connectedCallback() {
      if (!this._loaded) {
        this._renderLoading();
        this.refresh();
        this._loaded = true;
      }
    }

    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue === newValue || !this._loaded) return;
      this.refresh();
    }

    feedUrl() {
      const base = this.getAttribute('feed') || DEFAULT_FEED;
      const url = new URL(base, window.location.href);
      ['venue', 'days', 'limit', 'past'].forEach((attr) => {
        const value = this.getAttribute(attr);
        if (value) url.searchParams.set(attr, value);
      });
      return url.toString();
    }

    async refresh() {
      try {
        const res = await fetch(this.feedUrl(), { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const payload = await res.json();
        this._events = Array.isArray(payload.events) ? payload.events : [];
        this._render();
        this.dispatchEvent(new CustomEvent('mab-events-loaded', {
          bubbles: true,
          detail: { count: this._events.length },
        }));
      } catch (err) {
        this._renderError(err);
        this.dispatchEvent(new CustomEvent('mab-events-error', {
          bubbles: true,
          detail: { error: String(err) },
        }));
      }
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    _renderLoading() {
      this.innerHTML = '';
      this.appendChild(el('div', { class: 'mab-events-loading' }, [
        document.createTextNode('Loading upcoming events…'),
      ]));
    }

    _renderError(err) {
      this.innerHTML = '';
      this.appendChild(el('div', { class: 'mab-events-error' }, [
        document.createTextNode('Unable to load events right now. Please try again shortly.'),
      ]));
      if (window.console) console.error('[mab-events-carousel]', err);
    }

    _render() {
      this.innerHTML = '';
      this.appendChild(this._buildControls());
      this.appendChild(this._buildDisplay());
      this._applyFilters();
    }

    _categoriesPresent() {
      const found = new Set();
      this._events.forEach((e) => (e.tags || []).forEach((t) => found.add(t)));
      const ordered = CATEGORY_ORDER.filter((c) => found.has(c));
      const extra = [...found].filter((c) => !CATEGORY_ORDER.includes(c)).sort();
      return ordered.concat(extra);
    }

    _monthsPresent() {
      const seen = new Map(); // token -> {label, order}
      this._events.forEach((e) => {
        const token = monthToken(e.date);
        if (!seen.has(token)) {
          seen.set(token, { token, label: monthLabel(e.date), date: e.date });
        }
      });
      return [...seen.values()].sort((a, b) => (a.date < b.date ? -1 : 1));
    }

    _buildControls() {
      const wrapper = el('div', { class: 'mab-events-controls-wrapper uk-margin-large-bottom' });
      const scroller = el('div', { class: 'mab-filter-scroller' });
      const track = el('div', { class: 'mab-filter-track uk-flex uk-flex-nowrap uk-flex-center@m uk-flex-middle' });

      // View switcher — SHOWS is fully functional; CALENDAR is a labeled
      // placeholder (a full month-grid calendar is a separate component).
      const viewList = el('ul', { class: 'uk-subnav mab-unified-filter uk-margin-remove uk-flex-nowrap', 'aria-label': 'View mode' });
      const showsLi = el('li', { class: 'uk-active' }, [el('a', { role: 'button', tabindex: '0', text: 'SHOWS' })]);
      const calLi = el('li', {}, [el('a', { role: 'button', tabindex: '0', text: 'CALENDAR' })]);
      showsLi.addEventListener('click', () => this._setView('coverflow', showsLi, calLi));
      calLi.addEventListener('click', () => this._setView('calendar', showsLi, calLi));
      viewList.appendChild(showsLi);
      viewList.appendChild(calLi);
      track.appendChild(viewList);
      track.appendChild(el('div', { class: 'mab-nav-divider', 'aria-hidden': 'true', text: '/' }));

      // Category filter pills
      const categories = this._categoriesPresent();
      if (categories.length) {
        const catList = el('ul', { class: 'uk-subnav mab-unified-filter uk-margin-remove uk-flex-nowrap', 'aria-label': 'Filter by genre' });
        const allLi = el('li', { class: 'uk-active' }, [el('a', { role: 'button', tabindex: '0', text: 'ALL' })]);
        allLi.addEventListener('click', () => this._setCategory(null, catList));
        catList.appendChild(allLi);
        categories.forEach((cat) => {
          const li = el('li', {}, [el('a', { role: 'button', tabindex: '0', text: cat.replace('-', ' ').toUpperCase() })]);
          li.addEventListener('click', () => this._setCategory(cat, catList));
          catList.appendChild(li);
        });
        track.appendChild(catList);
        track.appendChild(el('div', { class: 'mab-nav-divider', 'aria-hidden': 'true', text: '/' }));
      }

      // Month filter pills
      const months = this._monthsPresent();
      if (months.length > 1) {
        const monthList = el('ul', { class: 'uk-subnav mab-unified-filter uk-margin-remove uk-flex-nowrap', 'aria-label': 'Filter by month' });
        months.forEach((m) => {
          const li = el('li', {}, [el('a', { role: 'button', tabindex: '0', text: m.label.slice(0, 3).toUpperCase() })]);
          li.addEventListener('click', () => this._setMonth(m.token, monthList));
          monthList.appendChild(li);
        });
        track.appendChild(monthList);
      }

      scroller.appendChild(track);
      wrapper.appendChild(scroller);
      return wrapper;
    }

    _setView(view, showsLi, calLi) {
      this._activeView = view;
      showsLi.classList.toggle('uk-active', view === 'coverflow');
      calLi.classList.toggle('uk-active', view === 'calendar');
      const notice = this.querySelector('.mab-calendar-notice');
      if (notice) notice.style.display = view === 'calendar' ? '' : 'none';
      const list = this.querySelector('.mab-coverflow');
      if (list) list.style.display = view === 'calendar' ? 'none' : '';
    }

    _setCategory(cat, listEl) {
      this._activeCategory = cat;
      [...listEl.children].forEach((li, i) => li.classList.toggle('uk-active', (cat === null && i === 0) || li.textContent.trim().toLowerCase().replace(/\s+/g, '-') === cat));
      this._applyFilters();
    }

    _setMonth(token, listEl) {
      // Clicking the already-active month pill clears the month filter back to ALL.
      this._activeMonth = this._activeMonth === token ? null : token;
      [...listEl.children].forEach((li) => li.classList.remove('uk-active'));
      if (this._activeMonth) {
        const idx = this._monthsPresent().findIndex((m) => m.token === token);
        if (listEl.children[idx]) listEl.children[idx].classList.add('uk-active');
      }
      this._applyFilters();
    }

    _buildDisplay() {
      const display = el('div', { id: 'mab-events-display', class: 'mab-view-coverflow' });
      display.appendChild(el('p', {
        class: 'mab-calendar-notice',
        style: 'display:none; text-align:center; opacity:.75;',
        text: 'Calendar view isn’t available in this embedded widget — showing Shows view below.',
      }));

      const list = el('ul', { class: 'mab-coverflow' });
      let lastToken = null;
      this._events.forEach((event) => {
        const token = monthToken(event.date);
        if (token !== lastToken) {
          list.appendChild(el('li', { class: 'mab-grid-month-header month-' + token }, [
            el('h3', { text: monthLabel(event.date) }),
          ]));
          lastToken = token;
        }
        list.appendChild(this._buildCard(event, token));
      });
      display.appendChild(list);
      return display;
    }

    _buildCard(event, token) {
      const tagClasses = (event.tags || []).map((t) => 'tag-' + t).join(' ');
      const li = el('li', {
        class: ['month-' + token, tagClasses, 'status-upcoming'].filter(Boolean).join(' '),
        'data-date': event.date,
        'data-category': (event.tags || []).join(' '),
      });

      const article = el('article', { class: 'mab-cover-item' });

      if (event.image) {
        article.appendChild(el('img', {
          decoding: 'async',
          src: safeUrl(event.image),
          alt: event.title || '',
          loading: 'lazy',
        }));
      }

      const info = el('div', { class: 'mab-cover-info' });
      info.appendChild(el('h3', { text: (event.title || '').toUpperCase() }));

      const dateBlock = el('div', { class: 'mab-date-block' }, [
        el('span', { class: 'mab-month', text: event.month || '' }),
        el('span', { class: 'mab-day', text: event.day || '' }),
        el('span', { class: 'mab-weekday', text: event.weekday || '' }),
      ]);
      info.appendChild(dateBlock);

      if (event.subtitle) {
        info.appendChild(el('div', { class: 'mab-event-subtitle', text: event.subtitle }));
      }
      if (event.description) {
        info.appendChild(el('p', { class: 'mab-event-body-text', text: event.description }));
      }
      if (event.schedule_pricing && Array.isArray(event.schedule_pricing.sections)) {
        info.appendChild(this._buildSchedulePricing(event.schedule_pricing));
      }

      const ticketEl = this._buildTicketButton(event);
      if (ticketEl) info.appendChild(ticketEl);

      article.appendChild(info);
      li.appendChild(article);
      return li;
    }

    _buildSchedulePricing(schedule) {
      const details = el('details', { class: 'mab-schedule-pricing' });
      details.appendChild(el('summary', { text: 'Schedule & Pricing' }));
      const body = el('div', { class: 'mab-schedule-pricing-body' });
      schedule.sections.forEach((section) => {
        const sec = el('div', { class: 'mab-schedule-section' });
        if (section.heading) {
          sec.appendChild(el('span', { class: 'mab-schedule-heading', text: section.heading }));
        }
        (section.lines || []).forEach((line) => {
          sec.appendChild(document.createTextNode(line));
          sec.appendChild(document.createElement('br'));
        });
        body.appendChild(sec);
      });
      details.appendChild(body);
      return details;
    }

    _buildTicketButton(event) {
      const ticket = event.ticket || { mode: 'none' };
      if (ticket.mode === 'external' && ticket.url) {
        const url = safeUrl(ticket.url);
        if (!url) return null;
        return el('a', { class: 'mab-outline-btn', href: url, target: '_blank', rel: 'noopener', text: 'TICKETS' });
      }
      if (ticket.mode === 'internal' && ticket.checkout_url) {
        const btn = el('a', { class: 'mab-outline-btn', href: '#', role: 'button', text: 'TICKETS' });
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          this._openTicketModal(event.title || 'Tickets', ticket.checkout_url);
        });
        return btn;
      }
      return null;
    }

    // ── Filtering ────────────────────────────────────────────────────────────

    _applyFilters() {
      const items = this.querySelectorAll('.mab-coverflow > li:not(.mab-grid-month-header)');
      const visibleTokens = new Set();
      items.forEach((li) => {
        const cats = (li.getAttribute('data-category') || '').split(' ').filter(Boolean);
        const date = li.getAttribute('data-date') || '';
        const token = monthToken(date);
        const matchesCategory = !this._activeCategory || cats.includes(this._activeCategory);
        const matchesMonth = !this._activeMonth || token === this._activeMonth;
        const visible = matchesCategory && matchesMonth;
        li.style.display = visible ? '' : 'none';
        if (visible) visibleTokens.add(token);
      });
      this.querySelectorAll('.mab-grid-month-header').forEach((header) => {
        const token = [...header.classList].find((c) => c.startsWith('month-'))?.slice(6);
        header.style.display = visibleTokens.has(token) ? '' : 'none';
      });
    }

    // ── Ticket modal (internal ticketing_mode events) ──────────────────────────
    // themab.org's own static markup already does this per-event (see the
    // hand-authored #modal-snail block for "I Am A Snail"), pointed at our
    // public event page. We build one shared modal on demand instead of
    // requiring the host page to pre-declare a modal per event.

    _openTicketModal(title, checkoutUrl) {
      const url = safeUrl(checkoutUrl);
      if (!url) return;
      const modal = this._ensureModal();
      modal.querySelector('.mab-ticket-modal-title').textContent = title;
      const iframe = modal.querySelector('iframe');
      iframe.src = url;
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
    }

    _closeTicketModal() {
      if (!this._modal) return;
      this._modal.style.display = 'none';
      this._modal.setAttribute('aria-hidden', 'true');
      this._modal.querySelector('iframe').src = 'about:blank';
    }

    _ensureModal() {
      if (this._modal) return this._modal;
      const modal = el('div', {
        class: 'uk-modal uk-flex-top mab-ticket-modal',
        'aria-hidden': 'true',
        style: 'display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,.85); align-items:flex-start; justify-content:center; padding:5vh 16px;',
      });
      modal.addEventListener('click', (e) => {
        if (e.target === modal) this._closeTicketModal();
      });

      const dialog = el('div', {
        class: 'uk-modal-dialog mab-ticket-modal-dialog',
        style: 'background:#fff; width:100%; max-width:640px; border:4px solid #000; box-shadow:10px 10px 0 #000;',
      });

      const closeBtn = el('button', {
        type: 'button',
        class: 'uk-modal-close-outside uk-icon uk-close',
        'aria-label': 'Close',
        style: 'float:right; background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:8px 12px; color:inherit;',
        text: '×',
      });
      closeBtn.addEventListener('click', () => this._closeTicketModal());

      const header = el('div', {
        class: 'uk-modal-header',
        style: 'background:#000; color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between;',
      }, [
        el('h2', { class: 'uk-modal-title mab-ticket-modal-title', style: 'margin:0; font-size:1rem; text-transform:uppercase; letter-spacing:.05em;', text: 'Tickets' }),
      ]);
      header.appendChild(closeBtn);

      const body = el('div', { class: 'uk-modal-body', style: 'padding:0;' }, [
        el('iframe', {
          src: 'about:blank',
          width: '100%',
          height: '600',
          style: 'border:none; display:block; background:#fff;',
          title: 'Ticket checkout',
        }),
      ]);

      dialog.appendChild(header);
      dialog.appendChild(body);
      modal.appendChild(dialog);
      document.body.appendChild(modal);

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display !== 'none') this._closeTicketModal();
      });

      this._modal = modal;
      return modal;
    }
  }

  if (!window.customElements.get('mab-events-carousel')) {
    window.customElements.define('mab-events-carousel', MabEventsCarousel);
  }
})();
