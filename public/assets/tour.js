import { $, esc, publish, subscribe, getAppCapabilities, PanicElement } from './core.js';

// ── Self-guided in-app tours ────────────────────────────────────────────────
// A lightweight, dependency-free product-tour engine: a dimmed backdrop, a
// highlight ring around one real UI element at a time, and a tooltip card
// with Back/Next/Skip. Deliberately hand-rolled (no Shepherd.js/Intro.js) to
// match this repo's zero-build-step, no-npm-runtime-dep convention (see
// src/QrCode.php / src/Pdf.php for the same philosophy applied to encoders).
//
// Steps intentionally target stable "chrome" — sidebar nav links, topbar
// controls, dashboard cards — rather than the insides of feature panels,
// which change shape often. A step whose `selector` can't be found (an
// admin customized the sidebar via Navigation Manager, or the signed-in
// user's role hides that nav item) is skipped automatically rather than
// shown broken. See docs/self-guided-tour.md for how to add a tour or step.
//
// Each tour: { key, label, description, icon, capability?, steps }
//   capability — if set, the tour is hidden from the picker unless
//   getAppCapabilities()[capability] is true (mirrors core.js's can()).
// Each step: { route?, selector?, title, body, help? }
//   route    — hash to navigate to before locating the target (omit to
//              stay on the current page)
//   selector — CSS selector for the element to spotlight (omit for a
//              centered, un-anchored card — used for intro/outro steps)
//   body     — trusted static HTML (not user input — not escaped, same as
//              help.js's HELP_CONTENT)
//   help     — optional help.js slug rendered as a "Learn more" link

export const TOURS = [
  {
    key: 'welcome',
    label: 'Welcome to Backstage',
    description: 'A quick lap around the sidebar and topbar — where everything lives.',
    icon: 'fa-solid fa-compass',
    steps: [
      { title: 'Welcome to Panic Backstage', body: 'This is a quick lap around the app — <em>where</em> things live, not everything they do. Use <strong>Help</strong> any time for the full reference, and you can replay this tour later from the same menu.' },
      { route: 'dashboard', selector: '[data-nav="dashboard"]', title: 'Your home base', body: 'Dashboard is where every session starts — what’s coming up, what needs attention, and (for venue admins) a setup checklist.', help: 'dashboard' },
      { selector: '[data-nav="calendar"]', title: 'Calendar', body: 'Every date at a glance — holds, booked shows, empty nights. Click a date to start a booking.', help: 'calendar' },
      { selector: '[data-nav="events"]', title: 'Events list', body: 'Every event ever booked, in one sortable table — the record of the venue’s whole history.', help: 'events-list' },
      { selector: '[data-group-toggle="booking-inbox"]', title: 'Booking Inbox', body: 'Every inbound inquiry — email, web form, phone — lands here as one shared queue. Claim one to work it; nothing gets buried in a personal inbox.', help: 'booking-inbox' },
      { selector: '[data-nav="tasks"]', title: 'Tasks', body: 'A cross-event to-do list — board, timeline, or calendar view — for anything that isn’t tied to one specific show.', help: 'tasks-app' },
      { selector: '[data-search]', title: 'Search', body: 'Jump straight to an event, contact, or inquiry by name or ID — no need to know which section it lives in.', help: 'search' },
      { selector: '[data-action="new-event"]', title: 'Start a booking', body: 'The fastest way to create a new event — a short wizard walks you through the essentials.', help: 'event-create' },
      { selector: '[data-user-pill]', title: 'Your account', body: 'Manage your name, password, and passkey sign-in here. Your role decides what you can see and do elsewhere in the app.', help: 'roles' },
      { selector: '[data-group-toggle="help"]', title: 'Help, whenever you need it', body: 'A full reference for every screen in the app lives under Help — and you can relaunch this tour, or any other, from the same menu.' },
      { title: 'That’s the map', body: 'Go build a great show. Anything that isn’t obvious probably has a Help topic — look for the <i class="fa-regular fa-circle-question" aria-hidden="true"></i> next to a section heading.' },
    ],
  },
  {
    key: 'venue-setup',
    label: 'Venue setup',
    description: 'The five things a new venue needs to configure, and where to find them.',
    icon: 'fa-solid fa-building',
    capability: 'manage_settings',
    steps: [
      { title: 'Set up your venue', body: 'Five things to configure before your first real show — the same five as the checklist on your dashboard. This just shows you where each one lives.' },
      { route: 'admin-venue', selector: '[data-nav="admin-venue"]', title: 'Venue details', body: 'Name, address, and timezone — these show up on contracts, emails, and the public event page.', help: 'admin-venue' },
      { route: 'admin-contracts', selector: '[data-nav="admin-contracts"]', title: 'Contract template', body: 'Build the standard agreement you’ll send every artist, so you’re not writing one from scratch each time.', help: 'admin-contracts' },
      { route: 'admin-payments', selector: '[data-nav="admin-payments"]', title: 'Payment processing', body: 'Connect Square or Stripe — required before you can sell tickets through the app.', help: 'admin-payments' },
      { route: 'admin-staff', selector: '[data-nav="admin-staff"]', title: 'Invite your team', body: 'Add bookers, managers, and door staff, and set what each of them can see and do.', help: 'admin-staff' },
      { route: 'calendar', selector: '[data-nav="calendar"]', title: 'Book your first show', body: 'Once the basics are in place, head to the calendar and create your first real event.', help: 'event-create' },
      { title: 'That’s the whole setup', body: 'The checklist card on your dashboard tracks your progress on these same five steps — dismiss it once you’re done.' },
    ],
  },
  {
    key: 'booking-inbox',
    label: 'Booking Inbox basics',
    description: 'How an inquiry gets claimed, worked, and handed off without two people replying to the same person.',
    icon: 'fa-solid fa-inbox',
    capability: 'view_booking_inbox',
    steps: [
      { title: 'How the Booking Inbox works', body: 'Every inquiry belongs to the venue, not whoever happens to see it first. Here’s the shared workflow.' },
      { route: 'inbox-unassigned', selector: '[data-nav="inbox-unassigned"]', title: 'Unassigned queue', body: 'New inquiries land here until someone claims them. Anyone with access can pick one up.', help: 'booking-inbox' },
      { selector: '[data-nav="inbox-mine"]', title: 'My Inquiries', body: 'Once you claim an inquiry, it moves here — yours to work until you reply, hand it off, or it times out back to the queue.', help: 'booking-inbox-claim' },
      { selector: '[data-nav="inbox-followup"]', title: 'Follow Up', body: 'Inquiries you’ve replied to but are still waiting on a response — nothing quietly falls through the cracks.', help: 'booking-inbox-conversation' },
      { selector: '[data-nav="inbox-all"]', title: 'All Inquiries', body: 'The full pipeline across every teammate — useful for a manager checking overall load.', help: 'booking-inbox-routing' },
      { title: 'That’s the whole loop', body: 'Claim → reply → onboard or archive. Full detail — routing, quarantine, duplicate-reply protection — is one click away in Help.', help: 'booking-inbox-onboard' },
    ],
  },
  {
    key: 'running-an-event',
    label: 'Running an event',
    description: 'From a blank calendar date to a fully staffed, ticketed show.',
    icon: 'fa-solid fa-calendar-check',
    steps: [
      { title: 'From blank date to a fully staffed show', body: 'A quick look at how a booking moves through the app, start to finish.' },
      { selector: '[data-action="new-event"]', title: 'Create an event', body: 'Start with the wizard — artist, date, room, and a starting status of Hold or Proposed. You can always fill in the rest later.', help: 'event-create' },
      { route: 'events', selector: '[data-nav="events"]', title: 'Track it in the list', body: 'Every event lives here with its status, date, and owner — sort or filter to find what needs attention.', help: 'events-list' },
      { route: 'calendar', selector: '[data-nav="calendar"]', title: 'See it on the calendar', body: 'Drag to adjust dates, spot conflicts, and get a feel for how booked-up a month is.', help: 'calendar' },
      { title: 'Inside an event', body: 'Open any event and you’ll find tabs for Lineup, Schedule, Staffing, Contracts, Tickets, and more — each one focused on a single part of getting the show ready.', help: 'overview' },
      { title: 'Hold → Settled', body: 'Everything about that show lives in one place, the whole way from first hold to final settlement. Explore an event’s tabs to see the rest.' },
    ],
  },
];

const DONE_KEY = 'pb.toursDone';

function readDone() {
  try { return new Set(JSON.parse(localStorage.getItem(DONE_KEY) || '[]')); } catch { return new Set(); }
}

function markDone(key) {
  const done = readDone();
  done.add(key);
  try { window.PBConsent?.savePref(DONE_KEY, JSON.stringify([...done])); } catch { /* storage blocked */ }
}

/** Launch a specific tour by key. Anywhere in the app: `startTour('welcome')`. */
export function startTour(key) {
  publish('tour.start', { key });
}

/** Open the tour picker (list of all tours available to this user). */
export function openTourPicker() {
  publish('tour.start', { key: null });
}

// Poll for a selector to appear (covers the moment between a hash-navigation
// and the new page finishing its async mount) rather than assuming it's
// there immediately. Resolves null on timeout so the caller can skip the
// step instead of spotlighting nothing.
function waitFor(selector, timeout = 4000) {
  return new Promise((resolve) => {
    const found = document.querySelector(selector);
    if (found) return resolve(found);
    const start = performance.now();
    const tick = () => {
      const el = document.querySelector(selector);
      if (el) return resolve(el);
      if (performance.now() - start > timeout) return resolve(null);
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
}

class TourElement extends PanicElement {
  connect() {
    this.tour = null;
    this.stepIndex = 0;
    this.mode = null; // 'picker' | 'step'
    this._openedDrawer = false;
    subscribe('tour.start', ({ key } = {}) => { key ? this.begin(key) : this.showPicker(); }, this.abort.signal);
    window.addEventListener('hashchange', () => this.onRouteChanged(), { signal: this.abort.signal });
    window.addEventListener('resize', () => this.reposition(), { signal: this.abort.signal });
    window.addEventListener('scroll', () => this.reposition(), { capture: true, signal: this.abort.signal });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.mode) this.close();
    }, { signal: this.abort.signal });
    // Single delegated handler survives every innerHTML re-render below.
    this.addEventListener('click', (event) => {
      const tourKeyBtn = event.target.closest('[data-tour-key]');
      if (tourKeyBtn) return this.begin(tourKeyBtn.dataset.tourKey);
      if (event.target.closest('[data-tour-next]')) return this.next();
      if (event.target.closest('[data-tour-prev]')) return this.prev();
      if (event.target.closest('[data-tour-skip]')) return this.close();
      if (event.target.closest('[data-tour-close]')) return this.close();
      if (event.target.hasAttribute('data-tour-backdrop')) return this.close();
    });
  }

  showPicker() {
    this.tour = null;
    this.mode = 'picker';
    const caps = getAppCapabilities() || {};
    const available = TOURS.filter((t) => !t.capability || caps[t.capability]);
    const done = readDone();
    this.innerHTML = `<div class="pb-tour-overlay">
      <div class="pb-tour-backdrop" data-tour-backdrop></div>
      <div class="pb-tour-picker" role="dialog" aria-modal="true" aria-label="Take a tour">
        <div class="pb-tour-tip-head">
          <span class="pb-tour-tip-eyebrow"><i class="fa-solid fa-signs-post" aria-hidden="true"></i> Self-guided tours</span>
          <button type="button" class="icon-btn" data-tour-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <p class="muted">Guided walkthroughs of the app, using your own screen — replay any of these whenever you like.</p>
        <ul class="pb-tour-list">
          ${available.map((t) => `
            <li>
              <button type="button" class="pb-tour-card" data-tour-key="${esc(t.key)}">
                <span class="pb-tour-card-icon"><i class="${esc(t.icon)}" aria-hidden="true"></i></span>
                <span class="pb-tour-card-body">
                  <strong>${esc(t.label)}${done.has(t.key) ? ' <span class="pb-tour-done"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Completed</span>' : ''}</strong>
                  <span class="muted small">${esc(t.description)}</span>
                </span>
                <span class="pb-tour-card-count">${t.steps.length} steps</span>
              </button>
            </li>`).join('')}
        </ul>
      </div>
    </div>`;
  }

  begin(key) {
    const tour = TOURS.find((t) => t.key === key);
    if (!tour) return;
    this.tour = tour;
    this.goToStep(0);
  }

  async goToStep(index) {
    if (!this.tour) return;
    if (index < 0) return;
    if (index >= this.tour.steps.length) return this.finish();
    this.stepIndex = index;
    this.mode = 'step';
    const step = this.tour.steps[index];

    const currentRoute = location.hash.replace(/^#/, '');
    if (step.route && step.route !== currentRoute) {
      location.hash = step.route;
      await new Promise((resolve) => setTimeout(resolve, 30));
    }

    let target = null;
    if (step.selector) {
      target = await waitFor(step.selector);
      // A missing target (customized nav, or a capability-gated control the
      // signed-in user doesn't have) means this step doesn't apply — skip
      // forward rather than spotlighting nothing.
      if (!target) return this.goToStep(index + 1);
      this.revealAncestors(target);
    }
    this.renderStep(step, target);
  }

  // Auto-open a collapsed sidebar group (and, on mobile, the off-canvas
  // drawer) that owns the step's target, mirroring what pb-app-shell's own
  // route() does for the active nav link.
  revealAncestors(target) {
    const group = target.closest('.nav-group');
    if (group && !group.classList.contains('open')) {
      group.classList.add('open');
      $('[data-group-toggle]', group)?.setAttribute('aria-expanded', 'true');
    }
    const shell = document.querySelector('.app-shell');
    const wantsDrawer = !!target.closest('.sidebar') && window.matchMedia('(max-width: 860px)').matches;
    if (wantsDrawer && shell && !shell.classList.contains('drawer-open')) {
      shell.classList.add('drawer-open');
      this._openedDrawer = true;
    } else if (!wantsDrawer && this._openedDrawer) {
      shell?.classList.remove('drawer-open');
      this._openedDrawer = false;
    }
  }

  renderStep(step, target) {
    this._lastTarget = target || null;
    const total = this.tour.steps.length;
    const index = this.stepIndex;
    const isLast = index === total - 1;
    const learnMore = step.help
      ? `<a class="pb-tour-learn-more" href="#help-${esc(step.help)}" target="_blank" rel="noopener noreferrer">Learn more in Help <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>`
      : '';
    this.innerHTML = `
      <div class="pb-tour-overlay" role="dialog" aria-modal="true" aria-label="${esc(step.title)}">
        <div class="pb-tour-backdrop" data-tour-backdrop></div>
        ${target ? '<div class="pb-tour-spotlight" data-tour-spotlight></div>' : ''}
        <div class="pb-tour-tip${target ? '' : ' pb-tour-tip-center'}" data-tour-tip>
          <div class="pb-tour-tip-head">
            <span class="pb-tour-tip-eyebrow"><i class="${esc(this.tour.icon)}" aria-hidden="true"></i> ${esc(this.tour.label)} — step ${index + 1} of ${total}</span>
            <button type="button" class="icon-btn" data-tour-close aria-label="Close tour"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          </div>
          <h3>${esc(step.title)}</h3>
          <div class="pb-tour-tip-body">${step.body}</div>
          ${learnMore}
          <div class="pb-tour-tip-foot">
            <button type="button" class="button secondary small" data-tour-skip>Skip tour</button>
            <div class="pb-tour-tip-nav">
              ${index > 0 ? '<button type="button" class="button secondary small" data-tour-prev>Back</button>' : ''}
              <button type="button" class="button small" data-tour-next>${isLast ? 'Finish' : 'Next'}</button>
            </div>
          </div>
        </div>
      </div>`;
    this.reposition();
  }

  reposition() {
    if (this.mode !== 'step') return;
    const tip = $('[data-tour-tip]', this);
    if (!tip) return;
    const spotlight = $('[data-tour-spotlight]', this);
    const target = this._lastTarget;
    if (!spotlight || !target || !target.isConnected) return;
    const rect = target.getBoundingClientRect();
    const pad = 8;
    Object.assign(spotlight.style, {
      top: `${Math.max(rect.top - pad, 0)}px`,
      left: `${Math.max(rect.left - pad, 0)}px`,
      width: `${rect.width + pad * 2}px`,
      height: `${rect.height + pad * 2}px`,
    });
    this.placeTip(tip, rect, pad);
  }

  // Prefers below the target, then above, then right, then left — clamped
  // to stay fully on-screen either way.
  placeTip(tip, rect, pad) {
    const margin = 14;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const tw = tip.offsetWidth;
    const th = tip.offsetHeight;
    let top;
    let left;
    if (rect.bottom + pad + margin + th <= vh) {
      top = rect.bottom + pad + margin;
    } else if (rect.top - pad - margin - th >= 0) {
      top = rect.top - pad - margin - th;
    } else if (rect.right + pad + margin + tw <= vw) {
      top = Math.min(Math.max(rect.top, margin), vh - th - margin);
      left = rect.right + pad + margin;
    } else {
      top = Math.min(Math.max(rect.top, margin), vh - th - margin);
      left = rect.left - pad - margin - tw;
    }
    if (left === undefined) {
      left = Math.min(Math.max(rect.left + rect.width / 2 - tw / 2, margin), vw - tw - margin);
    }
    tip.style.top = `${Math.max(top, margin)}px`;
    tip.style.left = `${Math.max(left, margin)}px`;
  }

  onRouteChanged() {
    if (this.mode === 'step') setTimeout(() => this.reposition(), 50);
  }

  next() { this.goToStep(this.stepIndex + 1); }

  prev() { this.goToStep(this.stepIndex - 1); }

  finish() {
    if (this.tour) markDone(this.tour.key);
    this.close();
  }

  close() {
    if (this._openedDrawer) {
      document.querySelector('.app-shell')?.classList.remove('drawer-open');
      this._openedDrawer = false;
    }
    this.tour = null;
    this.mode = null;
    this.innerHTML = '';
  }
}
customElements.define('pb-tour', TourElement);
