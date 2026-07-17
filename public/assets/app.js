import { getRefreshToken, clearTokens, appUrl, setAppUser, esc, publish, subscribe, api, broadcastEventData, refreshSection, table, PanicElement, $, $$ } from './core.js';
import { openCredentialSetupModal } from './auth.js';
import './core.js';
import './print.js';
import './contracts.js';
import './admin.js';
import './db-browser.js';
import './db-history.js';
import './contacts.js';
import './user-emails.js';
import './ticketing-admin.js';
import './tickets-public.js';
import './help.js';
import { HELP_SECTIONS } from './help.js';
import './promote.js';
import './outbox.js';
import './messages.js';
import './campaigns.js';
import './mailing-lists.js';
import './listmaster.js';
import './events.js';
import './event-upcoming.js';
import './event-wizard.js';
import './leads.js';
import './asset-library.js';
import './reports.js';
import './event-report.js';
import './nav-manager.js';
import { buildNavTree, filterNavTree, renderNavHtml } from './nav-shared.js';


class AppShell extends PanicElement {
  async connect() {
    this.classList.add('app-shell');
    this.renderShell();
    // Full workspace re-mount only for create/navigation flows. In-section
    // edits/adds now update their own section + the summary in place via the
    // `event.changed` bus (see refreshSection / broadcastEventData).
    subscribe('event.saved', () => this.refreshCurrent(), this.abort.signal);
    window.addEventListener('hashchange', () => this.route(), { signal: this.abort.signal });
    try {
      const [me, navData] = await Promise.all([api('/me'), api('/nav-items')]);
      this.user = me.user;
      this.capabilities = me.capabilities || {};
      this.navItems = navData.items || [];
      setAppUser(me.user);
      publish('auth.changed', me);
      if (!this.user) {
        location.href = appUrl('login.html');
        return;
      }
      this.renderNav();
      this.applyCapabilities();
      this.applyUserPrefs();
      this._loadVenueName();
      this._loadBrand();
      subscribe('app-settings.updated', (data) => this.applyBrand(data.settings || {}), this.abort.signal);
      subscribe('messages.changed', () => this.refreshUnread(), this.abort.signal);
      this.refreshUnread();
      await this.route();
      this.maybeShowCredentialSetup();
    } catch {
      location.href = appUrl('login.html');
    }
  }

  /** Fetch the primary venue name from the API and update the sidebar label. */
  _loadVenueName() {
    api('/venues').then((data) => {
      const name = data?.venues?.[0]?.name;
      if (!name) return;
      const span = $('[data-venue-label] .venue-name', this);
      if (span) span.textContent = name;
    }).catch(() => { /* non-critical — label stays as 'Venue' */ });
  }

  /**
   * Fetch the configured app-shell brand (Admin > App Settings) and apply it.
   * Falls back to the hardcoded "Panic Backstage" markup/title already in
   * renderShell()/index.html when nothing has been configured. See
   * applyBrand() — also called live when the settings page saves, so a
   * change shows up immediately without a reload.
   */
  _loadBrand() {
    api('/app-settings').then((data) => this.applyBrand(data?.settings || {}))
      .catch(() => { /* non-critical — brand stays the default */ });
  }

  applyBrand(settings) {
    const name = (settings.brand_name || '').trim();
    const logo = (settings.logo_url || '').trim();
    if (name) {
      $$('.brand, .mobile-brand', this).forEach((el) => {
        const label = $('span:last-child', el);
        if (label) label.textContent = name;
        el.setAttribute('aria-label', `${name} home`);
      });
      document.title = name;
    }
    if (logo) {
      $$('.brand-mark', this).forEach((el) => { el.style.backgroundImage = `url("${logo.replace(/["\\]/g, '')}")`; });
    }
  }

  /**
   * Show the credential-setup modal when the user has no password AND no
   * passkey AND has not opted out via hide_credential_setup_prompt.
   */
  maybeShowCredentialSetup() {
    const u = this.user || {};
    if (u.hide_credential_setup_prompt) return;
    if (u.has_password || u.has_passkey) return;

    openCredentialSetupModal(this.user, (updatedUser) => {
      // Modal calls back with the latest user state after setup or skip.
      this.user = updatedUser;
      const pill = $('[data-user-pill]', this);
      if (pill) pill.textContent = updatedUser.name || updatedUser.email || 'Account';
    });
  }

  renderShell() {
    this.innerHTML = `<aside class="sidebar">
      <button class="drawer-close" data-drawer-close type="button" aria-label="Close navigation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      <a class="brand" href="#dashboard" aria-label="Panic Backstage home"><span class="brand-mark" aria-hidden="true"></span><span>Panic Backstage</span></a>
      <nav class="side-nav" aria-label="Main navigation"></nav>
      <div class="side-card"><span class="bolt"></span><strong>Good shows.<br><span>No surprises.</span></strong></div>
      <button class="venue-switch" type="button" data-venue-label><i class="fa-solid fa-building" aria-hidden="true"></i><span class="venue-name">Venue</span></button>
      <p class="copyright">&copy; 2026 Panic Backstage</p>
    </aside>
    <header class="topbar">
      <button class="drawer-toggle" data-drawer-open type="button" aria-label="Open navigation menu" aria-expanded="false" title="Menu"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
      <button class="nav-toggle" data-nav-toggle type="button" aria-label="Toggle navigation" aria-expanded="true" title="Toggle navigation"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
      <a class="mobile-brand" href="#dashboard"><span class="brand-mark"></span><span>Panic Backstage</span></a>
      <pb-page-header></pb-page-header>
      <label class="search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-search placeholder="Search events..." aria-label="Search events"></label>
      <button class="topbar-create" data-action="new-event" type="button" title="Create event" aria-label="Create event"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>New event</span></button>
      <span class="session-pill" data-user-pill>…</span>
      <a href="#account" class="logout" style="text-decoration:none">Account</a>
      <button id="logout" class="logout">Logout</button>
    </header>
    <main id="app" class="workspace"><pb-loading-state></pb-loading-state></main>
    <footer class="app-footer"><span></span><strong><span class="bolt small-bolt"></span>Built for venues. Run by humans.</strong><span>Demo-ready local and staging paths</span></footer>
    <nav class="mobile-tabs" aria-label="Mobile navigation">
      <a data-nav="dashboard" href="#dashboard"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i>Dashboard</a>
      <a data-nav="calendar" href="#calendar"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Calendar</a>
      <a data-nav="events" href="#events"><i class="fa-solid fa-ticket" aria-hidden="true"></i>Events</a>
      <a data-nav="admin-users" href="#admin-users" data-nav-admin><i class="fa-solid fa-user-shield" aria-hidden="true"></i>Admin</a>
      <a data-nav="help" href="#help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i>Help</a>
    </nav>
    <div class="drawer-backdrop" data-drawer-close aria-hidden="true"></div>
    <pb-toast-stack></pb-toast-stack>`;
    $('#logout', this).addEventListener('click', async () => {
      const refreshToken = getRefreshToken();
      if (refreshToken) {
        await api('/auth/logout', { method: 'POST', body: JSON.stringify({ refresh_token: refreshToken }) }).catch(() => {});
      }
      clearTokens();
      location.href = appUrl('login.html');
    });
    $('[data-search]', this).addEventListener('input', (event) => publish('events.search', { query: event.target.value }));
    $('[data-action="new-event"]', this).addEventListener('click', () => { location.hash = 'new-event'; });
    this.setupNavCollapse();
    this.setupMobileDrawer();
  }

  // Renders the whole <nav class="side-nav"> — the DB-driven groups/links
  // from nav_items, followed by the static Help group (content-driven from
  // HELP_SECTIONS, not part of nav_items — see helpNavGroup()) — into the
  // empty <nav> left by renderShell(). Deferred until nav_items has loaded
  // (see connect()), and binds the collapsible-group behavior once, here,
  // rather than in renderShell(), since the group buttons don't exist yet
  // at that point.
  renderNav() {
    const nav = $('.side-nav', this);
    if (!nav) return;
    const tree = filterNavTree(buildNavTree(this.navItems || []), this.capabilities || {});
    nav.innerHTML = renderNavHtml(tree) + this.helpNavGroup();
    this.setupNavGroups();
  }

  // Mobile slide-in navigation drawer. The drawer IS the desktop sidebar
  // (same markup, same collapsible groups + active states) — on mobile it is
  // positioned off-screen and slid in. Opened by the topbar menu button; closed
  // by the backdrop, the close button, Escape, or any navigation.
  setupMobileDrawer() {
    const setOpen = (open) => {
      this.classList.toggle('drawer-open', open);
      $('[data-drawer-open]', this)?.setAttribute('aria-expanded', String(open));
    };
    this._setDrawer = setOpen;
    $('[data-drawer-open]', this)?.addEventListener('click', () => setOpen(true));
    $$('[data-drawer-close]', this).forEach((el) => el.addEventListener('click', () => setOpen(false)));
    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.classList.contains('drawer-open')) setOpen(false);
    }, { signal: this.abort.signal });
  }

  closeDrawer() {
    this._setDrawer?.(false);
  }

  // Collapsible parent nav groups (Events / Settings / Admin). Each parent
  // button toggles its group open/closed; the choice is remembered per group
  // in localStorage. The group containing the active route is auto-opened on
  // every route() so deep links land expanded.
  setupNavGroups() {
    const KEY = 'pb.navGroups';
    let state = {};
    try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { state = {}; }
    const persist = () => { window.PBConsent?.savePref(KEY, JSON.stringify(state)); };

    $$('[data-group-toggle]', this).forEach((btn) => {
      const key = btn.dataset.groupToggle;
      const group = btn.closest('.nav-group');
      const open = state[key] !== false; // default open unless explicitly collapsed
      group.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
      btn.addEventListener('click', () => {
        const nowOpen = !group.classList.contains('open');
        group.classList.toggle('open', nowOpen);
        btn.setAttribute('aria-expanded', String(nowOpen));
        state[key] = nowOpen;
        persist();
      });
    });
  }

  // Apply server-side UI preferences that the shell owns. Sidebar-collapsed
  // default only applies when the user has not made a local choice yet.
  applyUserPrefs() {
    const u = this.user || {};
    try {
      if (localStorage.getItem('pb.navCollapsed') === null && u.nav_collapsed) {
        this.classList.add('nav-collapsed');
        $('[data-nav-toggle]', this)?.setAttribute('aria-expanded', 'false');
      }
    } catch { /* storage blocked */ }
  }

  // Desktop collapsible sidebar. Toggles an icon-only rail and remembers the
  // choice in localStorage. Mobile uses the bottom tab bar and ignores this.
  setupNavCollapse() {
    const toggle = $('[data-nav-toggle]', this);
    if (!toggle) return;
    const KEY = 'pb.navCollapsed';
    const apply = (collapsed) => {
      this.classList.toggle('nav-collapsed', collapsed);
      toggle.setAttribute('aria-expanded', String(!collapsed));
    };
    let stored = false;
    try { stored = localStorage.getItem(KEY) === '1'; } catch { /* storage blocked */ }
    apply(stored);
    toggle.addEventListener('click', () => {
      const collapsed = !this.classList.contains('nav-collapsed');
      apply(collapsed);
      window.PBConsent?.savePref(KEY, collapsed ? '1' : '0');
    });
  }

  // Nav-link capability gating now happens once in filterNavTree() (see
  // renderNav()), sourced from each nav_items row's `capability` column
  // instead of a hand-maintained list of DOM selectors here. What's left is
  // the one piece of shell chrome that isn't a nav item: the topbar's
  // "+ New event" button.
  applyCapabilities() {
    if (!this.capabilities?.create_events) {
      $$('[data-action="new-event"]', this).forEach((btn) => btn.remove());
    }
    const pill = $('[data-user-pill]', this);
    if (pill && this.user) pill.textContent = this.user.name || this.user.email || 'Account';
  }

  // Build the collapsible Help nav group from the shared HELP_SECTIONS table
  // (the single source of truth that also drives the Help page's TOC). Each
  // category deep-links to its first topic; "All topics" opens the help home.
  helpNavGroup() {
    const children = HELP_SECTIONS.map((g) => {
      const first = g.items[0]?.slug || '';
      const icon = g.icon || 'fa-solid fa-circle-question';
      return `<a data-nav="help-${g.key}" href="#help-${first}" title="${g.group}"><i class="${icon}" aria-hidden="true"></i>${g.group}</a>`;
    }).join('');
    return `<div class="nav-group" data-group="help">
          <button class="nav-parent" type="button" data-group-toggle="help" aria-expanded="false" title="Help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i><span class="nav-parent-label">Help</span><i class="nav-chevron fa-solid fa-chevron-right" aria-hidden="true"></i></button>
          <div class="nav-children">
            <a href="${appUrl('docs/ops-manual.html')}" target="_blank" rel="noopener" title="User Guide"><i class="fa-solid fa-book" aria-hidden="true"></i>User Guide</a>
            <a data-nav="help" href="#help" title="All topics"><i class="fa-solid fa-bookmark" aria-hidden="true"></i>All topics</a>
            ${children}
          </div>
        </div>`;
  }

  // Map a help anchor (#help-<slug>) to the nav key of the category that owns
  // it, so the right Help child highlights and the group auto-opens.
  helpNavKey(route) {
    if (route === 'help' || route === 'help/') return 'help';
    const slug = route.replace(/^help[-/]/, '');
    const group = HELP_SECTIONS.find((g) => g.items.some((it) => it.slug === slug));
    return group ? `help-${group.key}` : 'help';
  }

  // Resolve the hash into the leaf nav key used for active-state matching.
  // Event-workspace deep links (event-<id>) light up the Events ▸ List leaf.
  navKeyForRoute(route) {
    if (route === 'new-event') return 'events';
    if (route.startsWith('event-')) return 'events';
    if (route === 'promote' || route.startsWith('promote-event-')) return 'promote';
    if (route === 'promote-settings') return 'promote-settings';
    if (route === 'admin' || route.startsWith('admin-') || route.startsWith('admin/')) {
      const tab = route === 'admin' ? 'users' : route.replace(/^admin[-/]/, '');
      return `admin-${tab}`;
    }
    if (route.startsWith('help')) return this.helpNavKey(route);
    if (route === 'outbox') return 'outbox';
    if (route === 'new-event') return 'events';
    return route;
  }

  parsePromoteRoute(route) {
    const match = route.match(/^promote-event-(\d+)(?:-(assets|broadcasts|analytics))?$/);
    if (!match) return null;
    return {
      eventId: Number(match[1]),
      section: match[2] || '',
    };
  }

  async route() {
    const homeRoute = (this.navItems || []).find((item) => item.is_home)?.link || 'dashboard';
    const route = location.hash.replace(/^#/, '') || this.user?.default_landing || homeRoute;
    publish('app.route.changed', { route });
    this.closeDrawer();
    const promoteRoute = this.parsePromoteRoute(route);
    const activeKey = this.navKeyForRoute(route);
    $$('[data-nav]', this).forEach((link) => link.classList.toggle('active', link.dataset.nav === activeKey));
    // Mark + auto-open the group that owns the active leaf.
    $$('.nav-group', this).forEach((group) => {
      const owns = !!$(`[data-nav="${activeKey}"]`, group);
      group.classList.toggle('active', owns);
      if (owns) {
        group.classList.add('open');
        $('[data-group-toggle]', group)?.setAttribute('aria-expanded', 'true');
      }
    });
    const outlet = $('#app', this);
    if (route === 'new-event') return this.mount(outlet, 'pb-event-wizard');
    const wizardEditMatch = route.match(/^new-event-(\d+)$/);
    if (wizardEditMatch) return this.mount(outlet, 'pb-event-wizard', { sourceEventId: Number(wizardEditMatch[1]) });
    if (route === 'promote') return this.mount(outlet, 'pb-promote-campaign-list');
    if (route === 'promote-settings') return this.mount(outlet, 'pb-promote-settings');
    if (promoteRoute) {
      return this.mount(outlet, 'pb-promote-campaign-overview', promoteRoute);
    }
    if (route.startsWith('event-')) return this.mount(outlet, 'pb-event-workspace', { eventId: Number(route.slice(6)) });
    if (route.startsWith('contract-')) return this.mount(outlet, 'pb-contract-editor', { contractId: Number(route.slice(9)) });
    if (route === 'reports')    return this.mount(outlet, 'pb-reports-page');
    if (route === 'calendar')    return this.mount(outlet, 'pb-event-calendar');
    if (route === 'pipeline')    return this.mount(outlet, 'pb-pipeline-board');
    if (route === 'events')      return this.mount(outlet, 'pb-events-list');
    if (route === 'upcoming')    return this.mount(outlet, 'pb-events-upcoming');
    // "Upcoming" is the default landing page (see homeRoute above, which
    // resolves to the "dashboard" nav link's route); the old metrics/cards
    // dashboard view lives on under "dashboard-metrics", linked from Reports.
    if (route === 'dashboard')   return this.mount(outlet, 'pb-events-upcoming');
    if (route === 'dashboard-metrics') return this.mount(outlet, 'pb-dashboard');
    if (route === 'asset-library') return this.mount(outlet, 'pb-asset-library');
    if (route === 'leads')       return this.mount(outlet, 'pb-leads-page');
    if (route === 'contacts')    return this.mount(outlet, 'pb-contacts-page');
    if (route === 'templates')   return this.mount(outlet, 'pb-template-picker');
    if (route === 'account')     return this.mount(outlet, 'pb-account-settings');
    if (route === 'preferences') return this.mount(outlet, 'pb-preferences');
    if (route === 'admin' || route.startsWith('admin-') || route.startsWith('admin/')) {
      const tab = route === 'admin' ? '' : route.replace(/^admin[-/]/, '');
      return this.mount(outlet, 'pb-admin-page', { initialTab: tab });
    }
    if (route === 'help' || route.startsWith('help-') || route.startsWith('help/')) {
      const anchor = route === 'help' ? '' : route.replace(/^help[-/]/, '');
      return this.mount(outlet, 'pb-help-page', { anchor });
    }
    if (route === 'outbox') return this.mount(outlet, 'pb-outbox-page');
    if (route === 'inbox') return this.mount(outlet, 'pb-messages-inbox');
    if (route === 'archive') return this.mount(outlet, 'pb-messages-archive');
    if (route === 'sent') return this.mount(outlet, 'pb-messages-sent');
    if (route === 'campaigns') return this.mount(outlet, 'pb-msg-campaigns');
    if (route === 'lists') return this.mount(outlet, 'pb-msg-lists');
    if (route === 'listmaster') return this.mount(outlet, 'pb-listmaster');
    if (route === 'new-event') return this.mount(outlet, 'pb-event-wizard');
    return this.mount(outlet, 'pb-dashboard');
  }

  // Fetch the inbox unread count and reflect it in the Messages nav badges.
  // Refreshed on load and whenever a component publishes `messages.changed`.
  async refreshUnread() {
    try {
      const { unread } = await api('/messages/unread-count');
      const n = Number(unread) || 0;
      $$('[data-inbox-badge]', this).forEach((badge) => {
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.hidden = n === 0;
      });
    } catch { /* messaging unavailable — leave badges hidden */ }
  }

  mount(outlet, tagName, props = {}) {
    publish('page.context', { title: '', blurb: '' }); // clear topbar header before new page mounts
    const element = document.createElement(tagName);
    Object.assign(element, props);
    outlet.replaceChildren(element);
  }

  refreshCurrent() {
    if (location.hash.startsWith('#event-')) this.route();
  }
}
customElements.define('pb-app-shell', AppShell);

// ── pb-page-header ────────────────────────────────────────────────────────────
// Lives inside the topbar. Listens for `page.context` events published by each
// page component and renders the current page title and blurb compactly.
class PageHeaderElement extends PanicElement {
  connect() {
    this.render('', '');
    subscribe('page.context', ({ title = '', blurb = '' } = {}) => {
      this.render(title, blurb);
    }, this.abort.signal);
  }

  render(title, blurb) {
    if (!title) { this.innerHTML = ''; return; }
    this.innerHTML = `<span class="ph-title">${esc(title)}</span>${blurb ? `<span class="ph-blurb">${esc(blurb)}</span>` : ''}`;
  }
}
customElements.define('pb-page-header', PageHeaderElement);
