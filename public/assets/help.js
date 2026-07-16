import { esc, statuses, publish, api, money, badge, option, can, table, PanicElement, $, $$ } from './core.js';


// ── Help page ────────────────────────────────────────────────────────────────
// Long-form documentation for the backstage app. Sections are anchored so the
// small "?" icons next to each event section can deep-link via #help-<slug>.

export const HELP_SECTIONS = [
  {
    group: 'Getting Started',
    key: 'getting-started',
    icon: 'fa-solid fa-flag-checkered',
    items: [
      { slug: 'welcome',      title: 'Welcome' },
      { slug: 'sign-in',      title: 'Signing in' },
      { slug: 'account',      title: 'Account &amp; passkeys' },
      { slug: 'roles',        title: 'Roles &amp; permissions' },
      { slug: 'onboarding',   title: 'Onboarding collaborators' },
    ],
  },
  {
    group: 'Lead Pipeline',
    key: 'leads',
    icon: 'fa-solid fa-funnel',
    items: [
      { slug: 'leads',         title: 'Leads inbox' },
      { slug: 'lead-evaluation', title: 'Deal evaluator' },
      { slug: 'lead-convert', title: 'Converting a lead to an event' },
    ],
  },
  {
    group: 'Working with the App',
    key: 'working',
    icon: 'fa-solid fa-compass',
    items: [
      { slug: 'navigation',   title: 'Main navigation' },
      { slug: 'dashboard',    title: 'Dashboard' },
      { slug: 'calendar',     title: 'Calendar' },
      { slug: 'pipeline',     title: 'Pipeline board' },
      { slug: 'events-list',  title: 'Events list &amp; search' },
      { slug: 'events-upcoming', title: 'Upcoming events (card view)' },
      { slug: 'asset-library', title: 'Asset library' },
      { slug: 'templates',    title: 'Templates' },
    ],
  },
  {
    group: 'Running an Event',
    key: 'running',
    icon: 'fa-solid fa-calendar-check',
    items: [
      { slug: 'event-create',    title: 'Creating an event' },
      { slug: 'event-wizard',    title: 'Event creation wizard' },
      { slug: 'private-events',  title: 'Private events &amp; rentals' },
      { slug: 'overview',        title: 'Overview &amp; readiness' },
      { slug: 'details',         title: 'Event details' },
      { slug: 'scheduling',      title: 'Scheduling (day-by-day &amp; recurrence)' },
      { slug: 'multi-day-events', title: 'Multi-day events' },
      { slug: 'recurring-events', title: 'Recurring events' },
      { slug: 'tasks',        title: 'Tasks' },
      { slug: 'lineup',       title: 'Lineup &amp; bands' },
      { slug: 'schedule',     title: 'Schedule &amp; run sheet' },
      { slug: 'staffing',     title: 'Staffing' },
      { slug: 'vendors',      title: 'Vendors &amp; COI tracking' },
      { slug: 'open-items',   title: 'Open items' },
      { slug: 'guest-list',   title: 'Guest list &amp; door' },
      { slug: 'execution',    title: 'Live execution records' },
      { slug: 'assets',       title: 'Assets &amp; flyers' },
      { slug: 'invites',      title: 'Invites &amp; collaborators' },
      { slug: 'contracts',    title: 'Contracts &amp; deal builder' },
      { slug: 'deposit-gate', title: 'Deposit gate &amp; payments' },
      { slug: 'payments',     title: 'The Payments tab' },
      { slug: 'e-signatures', title: 'Electronic signatures' },
      { slug: 'ticketing',    title: 'Ticketing &amp; door' },
      { slug: 'settlement',   title: 'Settlement' },
      { slug: 'publish',      title: 'Publishing the public page' },
      { slug: 'print',        title: 'Printable packets' },
      { slug: 'activity',     title: 'Activity log' },
    ],
  },
  {
    group: 'Closeout &amp; Billing',
    key: 'closeout',
    icon: 'fa-solid fa-file-invoice-dollar',
    items: [
      { slug: 'closeout',          title: 'Closeout overview' },
      { slug: 'closeout-ledger',   title: 'The financial ledger' },
      { slug: 'closeout-finalize', title: 'Finalizing closeout' },
    ],
  },
  {
    group: 'Administration',
    key: 'administration',
    icon: 'fa-solid fa-user-shield',
    items: [
      { slug: 'admin',        title: 'Admin overview' },
      { slug: 'admin-venue',  title: 'Venue &amp; rooms' },
      { slug: 'admin-users',  title: 'Managing login accounts' },
      { slug: 'contacts',     title: 'Contacts (CRM)' },
      { slug: 'admin-staff',  title: 'Staff roster' },
      { slug: 'admin-templates', title: 'Editing event templates' },
      { slug: 'admin-contracts', title: 'Contract library &amp; templates' },
      { slug: 'admin-payments', title: 'Payment providers' },
      { slug: 'admin-wizard-defaults', title: 'Wizard defaults' },
      { slug: 'admin-db',       title: 'Database browser' },
      { slug: 'admin-db-history', title: 'Database history &amp; undo' },
      { slug: 'admin-navigation', title: 'Navigation manager' },
    ],
  },
  {
    group: 'Messages',
    key: 'messages',
    icon: 'fa-solid fa-envelope',
    items: [
      { slug: 'msg-campaigns', title: 'Campaigns' },
      { slug: 'msg-lists',     title: 'Mailing Lists' },
      { slug: 'listmaster',    title: 'ListMaster' },
    ],
  },
  {
    group: 'Panic Promote',
    key: 'promote',
    icon: 'fa-solid fa-bullhorn',
    items: [
      { slug: 'promote-overview',     title: 'What is Panic Promote?' },
      { slug: 'promote-campaigns',    title: 'Campaigns' },
      { slug: 'promote-posts',        title: 'Posts &amp; copy generation' },
      { slug: 'promote-broadcasting', title: 'Broadcasting to platforms' },
      { slug: 'promote-manual',       title: 'Manual submission destinations' },
      { slug: 'promote-health',       title: 'Campaign health checklist' },
    ],
  },
  {
    group: 'Promote Administration',
    key: 'promote-admin',
    icon: 'fa-solid fa-plug',
    items: [
      { slug: 'promote-setup',     title: 'Setup &amp; credentials overview' },
      { slug: 'promote-eventbrite',title: 'Connecting Eventbrite' },
      { slug: 'promote-facebook',  title: 'Connecting Facebook &amp; Instagram' },
      { slug: 'promote-luma',      title: 'Connecting Luma' },
      { slug: 'promote-email-cfg', title: 'Connecting email lists' },
      { slug: 'promote-manual-cfg',title: 'Manual destinations &amp; copy' },
    ],
  },
  {
    group: 'Reference',
    key: 'reference',
    icon: 'fa-solid fa-book',
    items: [
      { slug: 'statuses',     title: 'Event status reference' },
      { slug: 'workflow',     title: 'End-to-end show workflow' },
      { slug: 'faq',          title: 'FAQ' },
      { slug: 'troubleshooting', title: 'Troubleshooting' },
    ],
  },
];


const HELP_CONTENT = {
  welcome: `
    <h2>Welcome to Panic Backstage</h2>
    <p>Panic Backstage helps a venue run a show from the first hold through final settlement. It keeps the lineup, run sheet, flyers, ticketing notes, open items, door list, and money in one place so a small team can hand off cleanly between bookers, promoters, designers, and night-of-show staff.</p>
    <figure class="help-shot">
      <img src="./assets/help/dashboard.png" alt="The Panic Backstage dashboard: a left navigation sidebar and a grid of readiness counters and upcoming-show cards" loading="lazy">
      <figcaption>The dashboard — the left sidebar navigates the app; the cards summarize the next two weeks of shows.</figcaption>
    </figure>
    <p>If this is your first visit, start with <a href="#help-sign-in">Signing in</a>, then <a href="#help-navigation">Main navigation</a>, then <a href="#help-event-create">Creating an event</a>. Section "?" icons inside each event open the relevant help page in a new tab so you do not lose your place.</p>
  `,

  'sign-in': `
    <h2 id="help-sign-in-h">Signing in</h2>
    <p>The login page is <strong>email-first</strong>: enter your email and Backstage shows only the sign-in methods your account actually has. No more guessing which option to click.</p>

    <h3>Step 1 — Enter your email</h3>
    <p>Type your address and click <em>Continue</em>. Backstage looks up what's on file and takes you to step 2 with only the options that apply to you.</p>
    <p>If your browser has a passkey saved for any Backstage account, it may offer to fill the email field for you — accept the suggestion and you'll skip straight past steps 1 and 2.</p>
    <p>On the same screen you can also click <em>Sign in with passkey</em> as a shortcut. This works without typing an email when the passkey is already registered to this device.</p>

    <h3>Step 2 — Pick a method</h3>
    <p>The page now shows your name and the methods available on your account:</p>
    <ul>
      <li><strong>Passkey.</strong> Click <em>Sign in with passkey</em> and approve with Face ID, Touch ID, Windows Hello, or a hardware key.</li>
      <li><strong>Password.</strong> Type your password and submit.</li>
      <li><strong>Email me a login link.</strong> Always offered as a fallback. We send a one-time link to your address that's valid for <strong>24 hours</strong>. For brand-new accounts (no passkey, no password) this is the primary path and the button is highlighted.</li>
    </ul>
    <p>If you landed on the wrong account, click <em>change</em> next to your name to go back to step 1.</p>

    <h3>Using a magic-link email</h3>
    <p>When you click the link in the email, Backstage shows a <em>Continue to your account</em> screen before signing you in. This is intentional: message previewers in iMessage, SMS, Slack, and corporate scanners often "click" links in the background, and we don't want them to burn your one-time token before you ever see it. The token is only consumed when you actually click the button.</p>
    <p>If the link is invalid or already used, you'll see an error with a quick path to request a fresh one.</p>

    <h3>After a first sign-in</h3>
    <p>If you signed in via an email link and your account has no passkey or password yet, Backstage offers a one-time <em>Make future sign-ins faster</em> modal:</p>
    <ul>
      <li><em>Add a passkey for this device</em> — fastest. Stored in your OS / password manager and unlocked with biometrics.</li>
      <li><em>Set a password</em> — works everywhere. At least 8 characters.</li>
      <li><em>Skip for now</em> — dismiss once. You'll see the prompt again next time.</li>
      <li><em>Don't show this again</em> — opt out permanently. You can still set up either method later from <a href="#help-account">Account</a>.</li>
    </ul>

    <h3>Sessions</h3>
    <p>Sessions persist via access + refresh tokens stored in your browser. If a session expires mid-use, the app silently refreshes; if the refresh fails you're bounced back to the login page with your email pre-filled.</p>

    <p class="muted small">Demo admin (when seeded): <code>admin@venue.local</code> / <code>changeme</code>.</p>
  `,

  account: `
    <h2>Account &amp; passkeys</h2>
    <p>Open <em>Account</em> from the topbar to manage how you sign in. Anything you set up here is the same set of options the login page's <a href="#help-sign-in">email-first flow</a> will offer next time you (or anyone signing into your address) authenticates.</p>
    <h3>Passkeys</h3>
    <p>Click <em>+ Add passkey for this device</em> and approve the prompt with Face ID, Touch ID, Windows Hello, or a hardware key. The device name is stored along with the date added and last-used date so you can spot stale devices. Remove a passkey any time; the next sign-in on that device falls back to password or email link.</p>
    <p>Passkeys are scoped to the device that created them, but a passkey in a synced password manager (1Password, iCloud Keychain, Google Password Manager) will follow you across devices automatically.</p>
    <h3>Password</h3>
    <p>Set or change a password. New passwords must be at least 8 characters. If you already have a password, the current one is required before saving a new one.</p>
    <h3>The "Make future sign-ins faster" prompt</h3>
    <p>If you logged in via email link and have no credentials on file, Backstage shows a one-time setup modal right after sign-in (see <a href="#help-sign-in">Signing in</a>). If you ticked <em>Don't show this again</em> there and changed your mind, just come to this page and set up a passkey or password manually — the prompt won't reappear, but Account always works.</p>
    <p>You can mix and match all three methods on the same account. Most venues recommend a passkey on the daily-driver laptop plus a password as a fallback for new devices.</p>
  `,

  roles: `
    <h2>Roles &amp; permissions</h2>
    <p>Authorization is enforced server-side based on your global role plus per-event collaborator rows.</p>
    <h3>Global roles</h3>
    <ul>
      <li><strong>Venue admin.</strong> Full access to every event, template, asset, settlement, invite, and user. Can create events from templates and manage the venue.</li>
      <li><strong>Staff user.</strong> Sees only events they own or where they appear in <code>event_collaborators</code>.</li>
    </ul>
    <h3>Per-event collaborator roles</h3>
    <ul>
      <li><strong>Event owner.</strong> Full access to that event except global user/template administration.</li>
      <li><strong>Promoter.</strong> Read the event, edit lineup, tasks, schedule, and open items, view and copy the public page. <em>Settlement is hidden.</em></li>
      <li><strong>Band / Artist.</strong> Read the event, upload their own assets, see tasks assigned directly to them.</li>
      <li><strong>Designer.</strong> Read the event and upload/manage assets. Settlement is hidden.</li>
      <li><strong>Staff.</strong> Read the event and edit tasks, schedule, and open items.</li>
      <li><strong>Viewer.</strong> Read-only access.</li>
    </ul>
    <p>If a control is greyed out or missing, your role does not have permission for it. Ask the event owner or a venue admin to elevate your role if you need more.</p>
  `,

  onboarding: `
    <h2>Onboarding collaborators</h2>
    <p>Bring a promoter, designer, band, or staffer onto a single event with an invite link.</p>
    <ol>
      <li>Open the event and scroll to <a href="#help-invites">Invites</a>.</li>
      <li>Enter their email, pick the role, and click <em>Create invite link</em>.</li>
      <li>Copy the generated URL with the <em>Copy link</em> button and share it via your usual channel (email, Slack, SMS). Backstage does not send the email itself.</li>
      <li>The recipient opens the link, sets a name, and is signed in directly to the event workspace.</li>
    </ol>
    <p>Invites are scoped to a single event and role. Create a new invite for each additional event. Existing accounts can also accept a new invite to add a second event to their workspace.</p>
  `,

  navigation: `
    <h2>Main navigation</h2>
    <p>The left sidebar (or bottom bar on mobile) is the primary navigation.</p>
    <ul>
      <li><strong>Dashboard</strong> — the next-two-weeks operational view.</li>
      <li><strong>Calendar</strong> — month grid of confirmed and held dates.</li>
      <li><strong>Pipeline</strong> — Kanban board grouped by event status.</li>
      <li><strong>Events</strong> — searchable list of every event you can see.</li>
      <li><strong>Templates</strong> — venue admins only, used to spawn new events.</li>
      <li><strong>Help</strong> — this page.</li>
    </ul>
    <p>The topbar holds:</p>
    <ul>
      <li><strong>Search</strong> — type to filter the Events list by title.</li>
      <li><strong>Account</strong> — passkey and password management.</li>
      <li><strong>Logout</strong> — clears tokens and returns to the login page.</li>
    </ul>
  `,

  dashboard: `
    <h2>Dashboard</h2>
    <p>The dashboard summarises your venue's show operations for the next two weeks.</p>
    <ul>
      <li><strong>Next Show</strong> — top-of-fold card with doors and show times and current status.</li>
      <li><strong>Open Items / Empty / Needs Flyer / Unsettled</strong> — counters that link straight to the relevant work.</li>
      <li><strong>Next 14 Days</strong> — table of upcoming events with main issue and owner.</li>
      <li><strong>Needs Attention</strong> — events with primary blockers or unapproved flyers.</li>
    </ul>
    <p>Click any event row to jump into its workspace. The cards refresh whenever you save changes in any event.</p>
  `,

  calendar: `
    <h2>Calendar</h2>
    <p>The calendar shows a six-week window. Use the <code>&lt;</code> and <code>&gt;</code> buttons to move months, or <em>Today</em> to snap back. Dates without an event show an <em>Available</em> chip; dates with events show a colored status dot and the event title. Click any event chip to open the workspace.</p>

    <h3>Room colour code</h3>
    <p>The coloured dot on each chip indicates which room the event is booked into. Rooms are configured under <a href="#help-admin-venue">Admin &rarr; Venue &amp; rooms</a> — for the current venue, that's:</p>
    <ul>
      <li><strong>Blue dot</strong> — Upstairs</li>
      <li><strong>Red dot</strong> — Downstairs (21+)</li>
      <li><strong>Green dot</strong> — Both Rooms</li>
    </ul>
    <p>The legend below the calendar toolbar always reflects whatever rooms are actually configured, so it updates automatically if a room is renamed, added, or archived. An event with no room selected shows in the default (red/"down") slot.</p>

    <h3>Times on chips</h3>
    <p>Each calendar chip shows the Doors time (or Show time if no Doors time is set) as a small badge on the right. Hovering the chip shows a tooltip with Status · Room · Doors time · Load-In time.</p>

    <h3>Private events</h3>
    <p>Private venue rentals (Type = Private Event) are shown on the calendar with a 🔒 lock icon and a subtle grey background so staff can distinguish them from publicly promoted shows at a glance. Private events are never announced publicly and will never appear on the public calendar or event page.</p>

    <h3>Cancelled events</h3>
    <p>Cancelled events are hidden from the calendar entirely. They remain in the database and are queryable via the Events list, but they do not occupy a date cell on the calendar view. This keeps the calendar clean while preserving the historical record.</p>

    <h3>Creating events from the calendar</h3>
    <p>Venue admins can click any day cell to open the quick-create modal. Pick a template (or Blank event), confirm the date, title, and times, and click <em>Create event</em> to jump straight into the new event workspace.</p>

    <h3>Multi-day events</h3>
    <p>An event with an <a href="#help-multi-day-events">End Date</a> set spans every day from its Date through its End Date as one continuous chip on the calendar and agenda views, instead of a single-day dot. The room is treated as booked for the entire range — a room-conflict check blocks any other booking that overlaps any day in that span, not just the start day.</p>

    <h3>Recurring events</h3>
    <p>A <a href="#help-recurring-events">recurring series</a> is <em>not</em> one chip repeating — every occurrence is its own separate event with its own date, so each one shows up as its own normal chip on whatever day it falls on. Open any occurrence's Scheduling tab to see the rest of the series and jump between them.</p>

    <p>The dashboard, pipeline, and calendar all read from the same <code>/api/events</code> data, so adding or moving a show updates all three.</p>
  `,

  pipeline: `
    <h2>Pipeline board</h2>
    <p>The pipeline groups events by status into columns. To advance an event, choose the new status in its card's inline dropdown and click <em>Move</em>. Open the card to jump into the full event workspace. The pipeline is the fastest way to move several events forward at once.</p>

    <h3>Private events on the pipeline</h3>
    <p>Private venue rentals appear on the pipeline with a 🔒 prefix and a subtle left border tint. Their inline status dropdown is filtered to only show the statuses valid for private events (Hold, Intake Complete, Booked, Archived, Settled, Cancelled), so staff can't accidentally move a rental into a public-promo stage.</p>

    <p>See <a href="#help-statuses">Event status reference</a> for what each column means.</p>
  `,

  'events-list': `
    <h2>Events list &amp; search</h2>
    <p>The Events page shows every event you have access to. Use the topbar search to filter by title. Click any row to open the workspace. Admins see a <em>Create Event</em> button that links to <a href="#help-templates">Templates</a>.</p>
  `,

  'events-upcoming': `
    <h2>Upcoming events (card view)</h2>
    <p><strong>Events &rarr; Upcoming</strong> is a card-based, ticketing-aware alternative to the plain <a href="#help-events-list">Events list</a> — built for scanning what's coming up and how ticket sales are trending, rather than searching for a specific show. It reads the same event data as the List, Dashboard, and Calendar views, so anything you see here is always in sync with the rest of the app.</p>

    <h3>The card list</h3>
    <p>Each card shows the event's date, poster thumbnail, support-act line (or a short description if there's no lineup), venue, and a ticket-sales badge:</p>
    <ul>
      <li><strong>On Sale</strong> — tickets are available and moving normally.</li>
      <li><strong>Low Tickets</strong> — 75% or more of capacity is sold.</li>
      <li><strong>Sold Out</strong> — sold count has reached capacity.</li>
      <li><strong>Free Event</strong> — every ticket tier is $0, or it's externally ticketed with no price/link set.</li>
      <li><strong>Canceled</strong> — shown only if the Canceled filter is checked (unchecked by default).</li>
    </ul>
    <p>Sales badges only appear once an event has been announced or later in its lifecycle — early-pipeline holds and drafts show their ordinary status badge instead.</p>
    <p>Click anywhere on a card to open that event's workspace, or use the <strong>&hellip;</strong> menu at the right of a card for <em>Open event</em> or <em>Promote</em> without leaving the list.</p>

    <h3>Filters sidebar</h3>
    <ul>
      <li><strong>Search</strong> — narrows the visible cards by title, support act, or venue name as you type.</li>
      <li><strong>Date Range</strong> — Next 7/30/90 Days, This Month, All Upcoming, or a Custom Range picked from the mini calendar below it (click a start day, then an end day).</li>
      <li><strong>Event Type</strong> — filter to one event type.</li>
      <li><strong>Status</strong> — checkboxes for On Sale, Low Tickets, Sold Out, Free Events, and Canceled; uncheck any bucket to hide it. <em>Clear all</em> resets every filter back to its default.</li>
    </ul>
    <p>Search and Status filter the already-loaded cards instantly with no network round-trip; changing the Date Range or Event Type reloads from the server.</p>

    <h3>Stats footer</h3>
    <p>Below the cards, four tiles summarize the currently selected date range: Upcoming Events, Tickets Sold, Est. Gross Revenue, and Avg. Capacity. These reflect the date-range/event-type filter only, not the client-side search or status checkboxes.</p>

    <h3>Export</h3>
    <p><em>Export Events</em> downloads a CSV of whatever's currently visible (after search and status filters are applied) — date, time, title, venue, status, tickets sold, capacity, and price range.</p>
  `,

  'asset-library': `
    <h2>Asset library</h2>
    <p>The Asset Library is a single, cross-event gallery of every file that has been uploaded to any event's <a href="#help-assets">Assets tab</a> — flyers, band photos, contracts, whatever. It's the fastest way to find a specific file without remembering which event it was uploaded to.</p>
    <p>You see the same events here as everywhere else in the app: venue admins and global viewers see assets from every event, everyone else sees assets only from events they own or collaborate on.</p>
    <h3>Browsing</h3>
    <ul>
      <li><strong>Images</strong> (jpg, png, gif, webp, svg) show as thumbnails. Click one to view it full-size in a modal.</li>
      <li><strong>Everything else</strong> (PDFs, etc.) shows as a plain document icon. Click it to open the file in a new browser tab.</li>
      <li>Each card names the event it belongs to — click the event name to jump straight into that event's workspace.</li>
    </ul>
    <h3>Filtering</h3>
    <p>Use the search box to match an asset's title/filename or its event's title. The Type and Status dropdowns narrow by <code>asset_type</code> (flyer, poster, band photo, etc.) and <code>approval_status</code> (draft, needs review, approved, rejected). This page is read-only — to upload, approve, or delete an asset, do it from that event's own <a href="#help-assets">Assets tab</a>.</p>
  `,

  templates: `
    <h2>Templates</h2>
    <p>Templates are pre-built event blueprints. Only venue admins see this page. Each template captures the venue, event type, default title, default tasks, default schedule blocks, and standard open items for a kind of show (for example a three-band local show or a swing dancing night).</p>
    <h3>Creating an event from a template</h3>
    <ol>
      <li>Open <em>Templates</em>.</li>
      <li>On the template card, pick a date and adjust doors/show/title.</li>
      <li>Click <em>Create event</em>. You are taken straight into the new event.</li>
    </ol>
    <p>The event is created with all of the template's seeded tasks, schedule items, and open items already in place, so you only have to fill in lineup-specific details.</p>
  `,

  'event-create': `
    <h2>Creating an event</h2>
    <p>Every show in Backstage follows the same arc: create the event and lock in the deal, get the key pieces <strong>approved</strong> (the contract and the flyer), announce it to the public, run it on the night, and settle the money afterward. The event workspace is a set of tabs that roughly follow that arc, and the <a href="#help-overview">Overview</a> tab keeps score — its <em>Readiness</em> checks and <em>Next Recommended Action</em> banner always point you at the next thing the show needs.</p>

    <h3>The two ways to create an event</h3>
    <p>Click the <strong>+ New event</strong> button in the top bar to open the <a href="#help-event-wizard">Event Creation Wizard</a> — a 7-step guided flow that walks you from title and date through deal structure, financial terms, production requirements, and promotion, then creates both the event <em>and</em> a pre-populated contract draft in a single click. This is the recommended path for any show that will have a booking agreement.</p>
    <p>If you need to spin up a simple show quickly and deal with the contract separately, use the <strong>Quick Create instead</strong> button in the wizard's sidebar. It opens a compact modal: pick a template (or choose "Blank event"), fill in date and doors/show times, and click <em>Create event</em>. The new event opens immediately in its workspace.</p>
    <p class="help-tip">📋 <strong>Private event / venue rental?</strong> See <a href="#help-private-events">Private events &amp; rentals</a> — the workflow is shorter and the wizard handles the rental deal type automatically.</p>

    <h3>Approvals and status</h3>
    <p>Approvals happen in two places as the show progresses. A <a href="#help-contracts">contract</a> moves through its own status workflow (<em>draft &rarr; needs review &rarr; approved &rarr; sent &rarr; signed</em>) and can't be marked sent or signed until its required terms are filled. A flyer is uploaded as <em>pending</em> and a promoter or admin marks it <em>approved</em> before it appears publicly. The event's own <a href="#help-statuses">status</a> (Hold, Intake Complete, Booked, Needs Assets, Published, Advanced, Settled…) is the high-level signal to the rest of the team about where the show stands.</p>

    <h3>Working through the event workspace</h3>
    <p>After creation, the event workspace opens with a set of tabs. Work through them roughly in this order:</p>
    <ol>
      <li><a href="#help-details">Event details</a> — set venue, type, status, owner, ticket price, capacity, and age restriction.</li>
      <li><a href="#help-lineup">Lineup</a> — add the bands or performers, capture payout terms, and confirm them.</li>
      <li><a href="#help-contracts">Contracts</a> — capture the deal as structured terms, generate the agreement, and walk it through approval to signed. (The wizard pre-creates a contract draft for you.)</li>
      <li><a href="#help-ticketing">Ticketing &amp; door</a> — link an external ticket URL, or sell in-house: set up tiers, comps, and door-scanner links.</li>
      <li><a href="#help-schedule">Run sheet</a> — set load-in, soundcheck, set times, changeovers, and curfew.</li>
      <li><a href="#help-staffing">Staffing</a> — schedule security, bar, door, sound, and other night-of-show crew.</li>
      <li><a href="#help-tasks">Tasks</a> — assign anything your team has to do before doors.</li>
      <li><a href="#help-open-items">Open items</a> — track external blockers you're waiting on (a contract back, an insurance cert, a ticket link).</li>
      <li><a href="#help-assets">Assets</a> — collect flyers and art, and approve the primary flyer.</li>
      <li><a href="#help-invites">Invites</a> — bring promoters, designers, or bands onto the event as collaborators.</li>
      <li><a href="#help-publish">Publish</a> — flip the public page on once the show is approved and ready to announce.</li>
      <li><a href="#help-guest-list">Guest list</a> — close to show day, build the comp / will-call / VIP door list.</li>
      <li><a href="#help-settlement">Settlement</a> — after the show, reconcile the numbers and close the books.</li>
    </ol>
    <p>You don't have to do these strictly in order, and not every show needs every tab. A couple of supporting tools run alongside: the <a href="#help-print">Print</a> menu produces night-of packets (run sheet, staffing, guest list, or a combined master packet), and the <a href="#help-activity">Activity log</a> at the bottom of the event records who changed what, so hand-offs between bookers and night-of staff stay clean.</p>
  `,

  'event-wizard': `
    <h2>Event creation wizard</h2>
    <p>The <strong>Event Creation Wizard</strong> is a 7-step guided form that collects everything needed to spin up a new show — event basics, deal structure, contact details, financial terms, production requirements, and promotion settings — then creates both the event <em>and</em> a pre-populated contract draft in one go.</p>
    <p>Open it by clicking <strong>+ New event</strong> in the top bar, or navigating to <strong>#new-event</strong>. A live <strong>Summary</strong> sidebar on the right updates in real-time as you fill in each field.</p>
    <p class="help-tip">⌨️ <strong>Keyboard shortcut:</strong> <kbd>Ctrl+Enter</kbd> (or <kbd>Cmd+Enter</kbd> on Mac) advances to the next step from any field.</p>

    <h3>Step 1 — Event Basics</h3>
    <p>Core details that every event needs. These fields map directly onto the event record:</p>
    <ul>
      <li><strong>Event Title</strong> (required) — the public-facing name, e.g. "Friday Night Live with The Slackers".</li>
      <li><strong>Date</strong> (required) — show date. Defaults to today.</li>
      <li><strong>End Date</strong> — optional, only for events spanning more than one calendar day. See <a href="#help-multi-day-events">Multi-day events</a>.</li>
      <li><strong>Recurring Event</strong> — optional. Check this to spin off a whole series (weekly, every other week, monthly by weekday or by date) instead of a single show — see <a href="#help-recurring-events">Recurring events</a>.</li>
      <li><strong>Venue</strong> (required) — which venue the show is at. Only shown once your account has more than one venue to choose from; a single-venue install skips straight to Room.</li>
      <li><strong>Room</strong> — which space within that venue is being used (e.g. Downstairs 21+, Upstairs, Both Rooms). Optional — leave it blank if the venue has no separate rooms defined. See <a href="#help-admin-venue">Venue &amp; rooms</a>.</li>
      <li><strong>Event Type</strong> (required) — Live Music, Karaoke, Open Mic, Promoter Night, DJ Night, Comedy, Private Event, or Special Event.</li>
      <li><strong>Doors Open / Show Time / End / Curfew</strong> — defaults to 7 pm / 8 pm / 11 pm.</li>
      <li><strong>Age Restriction</strong> — All Ages, 18+, or 21+.</li>
      <li><strong>Capacity</strong> — maximum attendance.</li>
      <li><strong>Public Description</strong> — short blurb for the public page and event listings. Supports Markdown.</li>
    </ul>

    <h3>Step 2 — Deal Structure</h3>
    <p>Choose how the event is structured financially. Your selection gates which financial fields appear on the Deal Terms step and which contract template is auto-suggested:</p>
    <table class="help-table">
      <thead><tr><th>Deal type</th><th>When to use it</th></tr></thead>
      <tbody>
        <tr><td><strong>Talent Buy</strong></td><td>You pay the artist a guarantee plus a share of the door. Standard for most live music bookings.</td></tr>
        <tr><td><strong>Promoter Deal</strong></td><td>A promoter rents the room and keeps the door receipts. Venue takes a rental or share.</td></tr>
        <tr><td><strong>Venue Rental</strong></td><td>Flat rental fee for the space; no door deal or artist guarantee.</td></tr>
        <tr><td><strong>Private Event</strong></td><td>Buyout or private occasion not listed publicly. Use this for corporate events, parties, film shoots, etc.</td></tr>
        <tr><td><strong>Residency</strong></td><td>Recurring engagement (weekly, monthly) with a revenue-split structure over a defined term.</td></tr>
        <tr><td><strong>Free / Internal</strong></td><td>No booking fee or formal contract required — staff event, volunteer night, or similar.</td></tr>
      </tbody>
    </table>
    <p>A matching <strong>Contract Template</strong> is auto-suggested based on the deal type. You can override it with any template in your library. Smart clause selection runs automatically after the event is created.</p>

    <h3>Step 3 — Artist / Promoter</h3>
    <p><em>Skipped for Free / Internal events.</em> Enter the booking counterparty — the artist, band, promoter, or client:</p>
    <ul>
      <li><strong>Contact Name</strong> — start typing to search your Contacts list. Selecting a match auto-fills the organization and email. You can also type a name not in the system.</li>
      <li><strong>Band / Organization</strong> — label, agency, promoter company, or group name.</li>
      <li><strong>Contact Email</strong> — used for contract delivery.</li>
      <li><strong>Artist / Act Name</strong> (Talent Buy and Promoter Deal only) — the performing name if it differs from the contact name.</li>
    </ul>

    <h3>Step 4 — Deal Terms</h3>
    <p><em>Skipped for Free / Internal events.</em> Financial terms that will be carried directly into the contract draft. Fields shown depend on the deal type chosen in step 2:</p>
    <table class="help-table">
      <thead><tr><th>Field</th><th>Applies to</th></tr></thead>
      <tbody>
        <tr><td>Guarantee ($)</td><td>Talent Buy, Promoter Deal</td></tr>
        <tr><td>Artist Door Split (%)</td><td>Talent Buy, Promoter Deal</td></tr>
        <tr><td>Venue Door Split (%)</td><td>Talent Buy, Promoter Deal</td></tr>
        <tr><td>Promoter Door Split (%)</td><td>Promoter Deal</td></tr>
        <tr><td>Rental Fee ($)</td><td>Venue Rental, Private Event</td></tr>
        <tr><td>House / Producer Revenue Split (%)</td><td>Residency</td></tr>
        <tr><td>Advance Ticket ($) / Door Ticket ($)</td><td>All except Rental, Private, Free</td></tr>
        <tr><td>Deposit Required ($)</td><td>All deal types</td></tr>
        <tr><td>Balance Due Date</td><td>All deal types</td></tr>
        <tr><td>Bar Minimum ($)</td><td>All deal types</td></tr>
        <tr><td>Venue Merch Cut (%)</td><td>All deal types</td></tr>
      </tbody>
    </table>

    <h3>Step 5 — Production &amp; Security</h3>
    <p>Tech rider and night-of logistics:</p>
    <ul>
      <li><strong>Sound Tech Included</strong> / <strong>Lighting Tech Included</strong> — yes/no toggles that map onto the contract's tech clause.</li>
      <li><strong># Security Guards</strong> / <strong>Rate ($/hr)</strong> / <strong>Paid By</strong> — venue, artist, or promoter.</li>
      <li><strong>Tech Rider Notes</strong> — backline, PA specs, load-in time, stage plot — anything the tech team needs to know.</li>
    </ul>

    <h3>Step 6 — Promotion</h3>
    <p>Public listing and marketing settings:</p>
    <ul>
      <li><strong>Public Listing?</strong> — whether the event appears on the public-facing page immediately after creation. You can always turn this on later via the <a href="#help-publish">Publish</a> tab.</li>
      <li><strong>Announce / On-Sale Date</strong> — when tickets go on sale and social posts should go out. Informs the Panic Promote campaign calendar.</li>
      <li><strong>Promotion Notes</strong> — social handles, preferred platforms, priority campaigns, ticket link — anything marketing needs.</li>
    </ul>

    <h3>Step 7 — Review &amp; Create</h3>
    <p>A summary of every field filled in across all previous steps, grouped by section. Each section has an <strong>Edit</strong> button that jumps you back to that step, and any missing required fields are listed at the top as clickable links that focus the relevant input.</p>
    <p>When everything looks good, click <strong>Create Event &amp; Draft Contract</strong>. Backstage:</p>
    <ol>
      <li>Creates the event (<code>POST /events</code>).</li>
      <li>Creates a contract draft linked to the event (<code>POST /events/{id}/contracts</code>), titled <em>"[Event Title] — [Deal Type]"</em>, pre-filled with the counterparty details and the selected template.</li>
      <li>Patches the deal terms onto the contract (<code>PATCH /contracts/{id}</code>) so the guarantee, splits, deposit, and other financial fields are already in the draft when you open it.</li>
      <li>Runs smart clause re-evaluation to auto-select relevant clauses from your contract library based on the deal type and terms.</li>
      <li>Redirects to the new event workspace and shows a success toast.</li>
    </ol>

    <h3>Quick Create fallback</h3>
    <p>The <strong>⚡ Quick Create instead</strong> button in the wizard sidebar switches to the compact quick-create modal — just template, date, title, and times. Use it when you want to stub out a placeholder event and fill in the contract separately later. Any date you've entered in the wizard carries over.</p>

    <h3>What the wizard does not do</h3>
    <p>The wizard creates the event and the contract draft. It does not schedule the lineup, build the run sheet, add staffing, or publish the public page — those live in the event workspace tabs you'll work through after creation. See <a href="#help-event-create">Creating an event</a> for the full post-creation workflow.</p>
  `,

  'private-events': `
    <h2>Private events &amp; rentals</h2>
    <p>A <strong>private event</strong> is a venue rental where a client books your venue for their own occasion — a corporate event, private party, wedding reception, album release, film shoot, or similar. Private events are never publicly listed and follow a different workflow from public shows.</p>

    <h3>How a rental inquiry comes in</h3>
    <ol>
      <li>A staff member creates a new event and sets <strong>Type → Private Event</strong>. The form immediately switches to the private event layout.</li>
      <li>Backstage automatically assigns <strong>a venue admin</strong> as the event owner and sends an inquiry notification email to all venue admins listing the client details, date, estimated guests, and AV/catering requirements.</li>
      <li>The event starts at <em>Hold</em> status. The date is informally blocked on the calendar while the rental is being worked out.</li>
    </ol>

    <h3>The private event form</h3>
    <p>When Type is <em>Private Event</em>, the Details tab shows a rental-specific form instead of the standard public-show form:</p>
    <ul>
      <li><strong>Client / Primary Contact</strong> — the person making the booking (name, email, phone). Required to advance to Hold.</li>
      <li><strong>Organization</strong> — company, family name, band, or group renting the space.</li>
      <li><strong>Estimated guests</strong> — expected headcount (distinct from the hard-cap Capacity). Required for Intake Complete.</li>
      <li><strong>Capacity (max)</strong> — the fire-code maximum for the space.</li>
      <li><strong>AV / Tech requirements</strong> — what the client needs (sound system, lighting, projector, microphones, etc.).</li>
      <li><strong>Catering / Bar notes</strong> — bar service preferences, catering vendors, alcohol restrictions.</li>
      <li><strong>Internal notes</strong> — staff-only notes not shared with the client.</li>
      <li><strong>Paid deposit</strong> — deposit received; required before moving to Intake Complete.</li>
    </ul>
    <p class="muted small">💰 For rental pricing, contact venue management.</p>
    <p>The public-show fields (ticket price, ticket URL, public description, Booker section) are hidden for private events. The Promote, Public Page, and Publish Public Page buttons are also hidden — private events are never publicly announced through Backstage.</p>

    <h3>Status workflow</h3>
    <p>Private events move through a shorter pipeline that skips all public-promotion stages:</p>
    <table class="help-table">
      <thead><tr><th>Status</th><th>What it means</th><th>What's required to reach it</th></tr></thead>
      <tbody>
        <tr><td><strong>Hold</strong></td><td>Inquiry received; date informally held.</td><td>Title, date, venue, door time, end time, client name/email/phone.</td></tr>
        <tr><td><strong>Intake Complete</strong></td><td>All client details confirmed; contract being built.</td><td>Age restriction, estimated guests, deposit amount.</td></tr>
        <tr><td><strong>Booked</strong></td><td>Contract approved/signed; deposit confirmed.</td><td>Contract in Approved, Sent, or Signed status (or contract URL on file).</td></tr>
        <tr><td><strong>Archived</strong></td><td>Event happened; settlement pending.</td><td>Auto-set by nightly script if still active past the event date.</td></tr>
        <tr><td><strong>Settled</strong></td><td>Settlement filed; books closed.</td><td>Manual advance.</td></tr>
        <tr><td><strong>Cancelled</strong></td><td>Rental cancelled.</td><td>Manual advance.</td></tr>
      </tbody>
    </table>

    <h3>Contacts and notifications</h3>
    <ul>
      <li><strong>On creation</strong> — all venue admins receive a <em>New Private Event Inquiry</em> email with the full client details, AV requirements, and a direct link to the event.</li>
      <li><strong>When Intake Complete</strong> — venue admins receive an <em>Intake Complete — Contract Needed</em> email with the event details and a numbered checklist: venue admin drafts the contract → management co-signs → contract sent to client for signature → upload signed copy → advance to Booked.</li>
      <li><strong>When booked</strong> — the client receives a confirmation email that their event is confirmed. All venue admins are also notified of the status change.</li>
    </ul>

    <h3>Calendar and pipeline display</h3>
    <p>Private events are distinguishable from public shows throughout the app:</p>
    <ul>
      <li>On the <strong>calendar</strong>, private event chips show a 🔒 lock icon and a subtle grey background.</li>
      <li>On the <strong>pipeline board</strong>, private event cards show a 🔒 prefix and a left-side colour border. Their status dropdown is filtered to the private-valid statuses only, so a rental can't accidentally be moved to Needs Assets or Published.</li>
      <li>In the <strong>workspace header</strong>, the event title shows a 🔒 badge, and the Promote / Public Page / Publish buttons are not shown.</li>
    </ul>

    <h3>Contracts for private events</h3>
    <p>Use the <a href="#help-contracts">Contracts</a> tab to build a rental agreement. Pick the <em>Private Event Rental</em> contract template — it includes the venue rental fee, deposit terms, security requirements, bar minimum, and force-majeure clause out of the box. Walk it through Draft → Approved → Sent → Signed. The Booked status check looks for a contract in the contracts table or a contract URL on file.</p>
  `,

  overview: `
    <h2>Overview &amp; readiness</h2>
    <figure class="help-shot">
      <img src="./assets/help/event.png" alt="An event workspace: the compact event header, the row of section tabs below it, and the Overview tab's card-grid dashboard" loading="lazy">
      <figcaption>An event workspace — a compact header sits above the tab row; the Overview tab (the default landing tab) is a card-grid dashboard with a link out of every card to its full section.</figcaption>
    </figure>
    <p>Every event workspace opens with a compact header above the tab row: a small flyer panel, a facts grid (Date, Doors, Show, Status, Owner, Public Page state, and — for in-house ticketed events — Tickets Sold), and two counters that jump straight to the matching tab:</p>
    <ul>
      <li><strong>Open Items</strong> count — blockers that are still <em>open</em> or <em>waiting</em>.</li>
      <li><strong>Tasks Left</strong> count — tasks not yet marked <em>done</em> or <em>canceled</em>.</li>
    </ul>
    <p>Below the header sits a <strong>Next Recommended Action</strong> banner suggesting the most important next step (sign the artist, approve the flyer, build the run sheet, etc.). It refreshes when you click <em>Refresh</em> or save something anywhere in the event. The <strong>&times;</strong> button collapses it to a slim "dismissed for now" strip (click <em>Show</em> to bring it back) — this is per-visit, not permanent: reopening the event shows it again, and if the recommendation itself changes (a new, different next step) it reappears automatically even while collapsed, so dismissing today's task can't accidentally hide tomorrow's.</p>
    <p>The <strong>Overview</strong> tab itself is a read-only, at-a-glance dashboard: a grid of cards for Schedule/Timeline, Promoter/Contacts, Performer Lineup, Venue Ops/Logistics, Financial/Ticketing, Notes/Tasks, and Documents/Attachments. Each card summarizes that part of the event and links out (e.g. "Full Run Sheet", "Manage Lineup") to the matching tab for the full editable view.</p>
    <ul>
      <li>The <strong>Financial/Ticketing</strong> card shows ticket price, capacity, estimated guests, deposit amount and status, and — for in-house ticketed events — tickets sold so far; externally-ticketed events get a link out to the ticket URL instead. If you can view <a href="#help-settlement">Settlement</a>, a filed settlement's gross ticket sales, bar sales, and venue net are summarized here too.</li>
      <li>The <strong>Notes/Tasks</strong> card shows the event's internal notes (the staff-only field on the <a href="#help-details">Details</a> tab — nothing here ever appears on the public page) and the first several open tasks.</li>
    </ul>
    <p>Also on the Overview tab, the <strong>Readiness</strong> panel lists the gates we check before a show is "ready" (lineup confirmed, flyer approved, public page on, run sheet built, settlement filed, and so on) with a clear OK / not-OK mark.</p>
  `,

  details: `
    <h2>Event details</h2>
    <p>The Event Details form holds the core facts of the show. Fields auto-save on blur — you will see "Saving…" and then "All changes saved" in the bottom-left of the form as each field persists.</p>

    <h3>Common fields (all events)</h3>
    <ul>
      <li><strong>Title</strong> — the marquee name. Used everywhere: dashboard, calendar chips, public page, print packets.</li>
      <li><strong>Date</strong> — show date.</li>
      <li><strong>End Date</strong> — optional. Only set this for an event that spans more than one calendar day (a festival, a weekend rental, a multi-day workshop). Leave it blank for a normal single-day show. See <a href="#help-multi-day-events">Multi-day events</a>.</li>
      <li><strong>Venue</strong> — which venue the show is at. Only shown when your account has more than one venue to choose from.</li>
      <li><strong>Room</strong> — which space within that venue, if any are defined (see <a href="#help-admin-venue">Venue &amp; rooms</a>). Drives the calendar's room colour-coding and room-conflict checks.</li>
      <li><strong>Type</strong> — live music, karaoke, open mic, promoter night, DJ night, comedy, private event, or special event. Changing to or from <em>private event</em> changes the entire form layout.</li>
      <li><strong>Status</strong> — see <a href="#help-statuses">Event status reference</a>. Private events show a filtered dropdown.</li>
      <li><strong>Owner</strong> — the staff member responsible. Owners get implicit access to the event.</li>
      <li><strong>Load-In / Tech</strong> — when the crew and gear arrive for load-in and soundcheck. Shown on calendar chips as a tooltip and in the print run-sheet header.</li>
      <li><strong>Doors / Show / End</strong> — the public-facing show times. Setting one will auto-fill reasonable defaults for the others if they're empty.</li>
      <li><strong>Age restriction</strong> — shown on the public page and in the run sheet (e.g. 21+, All Ages).</li>
      <li><strong>Paid deposit</strong> — the deposit amount confirmed received. Required to advance past Intake Complete.</li>
    </ul>

    <h3>Public show fields</h3>
    <ul>
      <li><strong>Ticket price</strong> — base ticket price. Used on the public page and for in-house ticketing setup.</li>
      <li><strong>Capacity</strong> — fire-code max; the ticketing system won't oversell past this.</li>
      <li><strong>Potential revenue</strong> — estimated upside (capacity × ticket price). Used in settlement projection.</li>
      <li><strong>Walk-through happened</strong> — checkbox to confirm the pre-show walk-through is done.</li>
    </ul>

    <h3>Producer / Artist section (public shows)</h3>
    <p>The producer or primary artist contact — required to advance to <strong>Hold</strong> and above. This is the person Backstage sends the <em>Promo Materials Needed</em> email to when the event reaches Needs Assets.</p>
    <ul>
      <li><strong>Booker</strong> — the talent buyer or agent. Also required for Hold and above on public shows.</li>
    </ul>

    <h3>Private event form</h3>
    <p>When Type is set to <strong>Private Event</strong>, the form changes completely. Public-only fields (ticket price, ticket URL, ticket system, public description, the Booker section) are hidden and replaced with rental-specific fields. See <a href="#help-private-events">Private events &amp; rentals</a> for the full workflow.</p>
    <ul>
      <li><strong>Estimated guests</strong> — expected headcount. Distinct from the hard-cap Capacity.</li>
      <li><strong>Client / Primary Contact</strong> — the person booking the rental (replaces Producer / Artist).</li>
      <li><strong>Organization</strong> — company, family name, band, or group renting the space.</li>
      <li><strong>AV / Tech requirements</strong> — what the client needs for sound, lighting, projection, etc.</li>
      <li><strong>Catering / Bar notes</strong> — bar service, vendor access, alcohol requirements.</li>
    </ul>
    <p>Private events are never publicly listed. The Publish / Public Page / Promote buttons are hidden, and <code>public_visibility</code> is permanently set to 0 regardless of what the form sends.</p>

    <h3>Publish state (public shows only)</h3>
    <ul>
      <li><strong>Public page visible</strong> — toggles the publish state from inside the form. The <em>Publish Public Page</em> button at the top of the workspace does the same thing in one click.</li>
    </ul>

    <p>Day-by-day session blocks and recurring-series setup have moved to their own <a href="#help-scheduling">Scheduling</a> tab.</p>
  `,

  scheduling: `
    <h2>Scheduling</h2>
    <p>The Scheduling tab holds the two things that reshape an event's dates: per-day session blocks for a multi-day event, and turning an event into a recurring series. Both are optional — most events use neither.</p>

    <h3>Day-by-Day Schedule</h3>
    <p>Optional — only needed for something like a multi-day workshop where each day has its own time block (e.g. Sat 1&ndash;5pm, Sun 1&ndash;4pm). Adding days here keeps the event's Date/End Date in sync automatically. Leave empty for a normal single- or continuous-multi-day event. See <a href="#help-multi-day-events">Multi-day events</a> for how a date range shows up elsewhere in the app.</p>

    <h3>Recurrence</h3>
    <p>If this event isn't part of a series yet, the Recurrence panel shows a picker for turning it into one — see <a href="#help-recurring-events">Recurring events</a>. If it's already part of a series, the panel instead lists every sibling event with a link to jump to each one.</p>
  `,

  'multi-day-events': `
    <h2>Multi-day events</h2>
    <p>Most shows are a single-day thing, but some aren't — a festival, a weekend private rental, a multi-day workshop or artist residency. For those, set an <strong>End Date</strong> alongside the regular Date and the event spans every calendar day from Date through End Date instead of just one.</p>

    <h3>Where to set it</h3>
    <ul>
      <li>The <a href="#help-event-wizard">event wizard</a>'s Step 1 — Event Basics.</li>
      <li>The Quick Create modal.</li>
      <li>The <a href="#help-details">Event Details</a> tab, any time after creation — the End Date field there is bounded to never be earlier than Date.</li>
    </ul>
    <p>Leave End Date blank (or set it equal to Date) for a normal single-day event — that's the default and the vast majority of shows.</p>

    <h3>How it shows up</h3>
    <ul>
      <li><strong>Calendar &amp; agenda</strong> — instead of a single dot, the event renders as one continuous chip across every day in its range. See <a href="#help-calendar">Calendar</a>.</li>
      <li><strong>Room conflicts</strong> — the room is considered booked for the <em>entire</em> range. Trying to book that same room — or, for a "Both Rooms"-type booking, any of the individual rooms it spans — on any day inside that range will be blocked as a conflict, not just the first day. See <a href="#help-admin-venue">Venue &amp; rooms</a> for how rooms relate to each other.</li>
      <li><strong>Staffing</strong> — the Staffing tab groups shifts by day first, then role, and each shift carries its own date within the event's range (defaulting to the start date). "Auto-fill from capacity" applies a full crew tier to <em>every</em> day in the range rather than once for the whole run, and both the payroll CSV export and the printed staffing schedule break out one section per day.</li>
      <li><strong>Everything else</strong> (contract, ticketing, guest list, settlement) stays attached to the single event record exactly like a normal show — a multi-day event is still one event, just with a wider footprint on the calendar. The contract renderer also shows the full date range (e.g. "August 14 – August 16, 2026") anywhere it would otherwise print just the start date, and auto-includes a Multi-Day Event clause covering exclusivity, load-in/strike scheduling, and overnight-gear risk.</li>
    </ul>
    <p class="help-tip">💡 Multi-day events are a different thing from <a href="#help-recurring-events">recurring events</a>. Multi-day is <em>one</em> event spanning several consecutive days (a festival). Recurring is <em>several separate</em> events on a repeating schedule (a weekly karaoke night) — each with its own date, contract, and staffing.</p>
  `,

  'recurring-events': `
    <h2>Recurring events</h2>
    <p>A recurring series is <strong>not</strong> a single event that magically shows up on multiple dates — every occurrence is created as its own fully independent event, with its own contract, staffing, ticketing, guest list, and status. Occurrences are linked only so the app can show you the rest of the series and let you jump between them. Editing, cancelling, or rebooking one occurrence never touches any of the others.</p>

    <h3>Starting a series</h3>
    <p>There are two ways to spin one up:</p>
    <ul>
      <li><strong>From the event wizard</strong> — check <strong>Recurring Event</strong> on Step 1 (Event Basics). The pattern you configure there is applied right after the first event is created, so the whole series exists by the time you finish the wizard.</li>
      <li><strong>From an existing event</strong> — open its <a href="#help-details">Event Details</a> tab and use the <strong>Recurrence</strong> panel underneath it. This turns that single event into the first occurrence of a brand-new series (it must not already be part of one).</li>
    </ul>

    <h3>Picking a pattern</h3>
    <p>The weekday (for weekly patterns) or day-of-month (for monthly patterns) is always taken from the date you're starting from — you don't pick it separately, so the pattern can never disagree with the date. Supported patterns:</p>
    <ul>
      <li><strong>Weekly</strong> — every week, every other week, every 3rd week, or every 4th week, on whichever day of the week your starting date falls on (e.g. "Every other Tuesday").</li>
      <li><strong>Monthly by weekday</strong> — the same ordinal weekday every month (e.g. "First Thursday of the month", or "Last Friday of the month").</li>
      <li><strong>Monthly by date</strong> — the same day-of-month every month (e.g. "the 15th").</li>
    </ul>
    <p>You must also choose an end condition — <strong>after N occurrences</strong> or <strong>on a specific date</strong> — and the total is capped at 52 occurrences per series. A live preview lists the dates that will be created so you can double-check before committing.</p>

    <h3>Conflicts</h3>
    <p>Every generated date is checked against existing bookings at that venue, exactly like a normal event create. If <em>any</em> date in the pattern conflicts with an existing booking, nothing is created — you'll see an error listing which date(s) collided so you can adjust the pattern (a different day, a shorter range, or fewer occurrences) and try again.</p>

    <h3>Managing an existing series</h3>
    <p>Open any occurrence's <a href="#help-scheduling">Scheduling</a> tab — the Recurrence panel lists every sibling in the series with its date and status, and links to jump straight to any of them. If one occurrence needs to drop out of the series (it was cancelled, moved to a different venue, whatever), use <strong>Remove this event from the series</strong> on that occurrence — it becomes a fully standalone event again and the rest of the series is unaffected.</p>
    <p class="help-tip">💡 There's no bulk edit across a series (no "change this and all future occurrences"). Each occurrence is a completely normal event once created — update its time, venue, staffing, or anything else the same way you would for any other show.</p>
  `,

  tasks: `
    <h2>Tasks</h2>
    <p>Tasks are anything a person has to do before the show. They appear on the dashboard's open-items metric and feed the "Next Recommended Action" hint.</p>
    <h3>Adding a task</h3>
    <p>Fill in the form at the bottom of the Tasks panel: a title (required), an assignee, a due date, a priority (low / normal / high / urgent), and details. Click <em>Add task</em>.</p>
    <h3>Updating a task</h3>
    <p>Each row is an inline form. Change any field and click <em>Save</em>, or use the <em>Done</em> shortcut to mark it complete in one click. Statuses are <em>todo</em>, <em>in_progress</em>, <em>blocked</em>, <em>done</em>, <em>canceled</em>.</p>
    <h3>Who sees what</h3>
    <p>Promoters and staff can edit all tasks. Bands and artists see tasks assigned directly to them. Viewers see tasks but cannot edit them.</p>
  `,

  lineup: `
    <h2>Lineup &amp; bands</h2>
    <p>The lineup captures who is playing the show.</p>
    <h3>Adding a band or artist</h3>
    <p>Use the add form at the bottom of the lineup panel:</p>
    <ul>
      <li><strong>Band / artist</strong> — internal record name. Bands you re-book are reused across events.</li>
      <li><strong>Display name</strong> — what appears on the public page and the flyer (e.g. "The Examples ft. Special Guest").</li>
      <li><strong>Billing order</strong> — 1 is headliner. Schedule defaults are sorted by this number.</li>
      <li><strong>Set time / Set length minutes</strong> — used to build the run sheet.</li>
      <li><strong>Status</strong> — <em>invited</em>, <em>tentative</em>, <em>confirmed</em>, <em>canceled</em>.</li>
      <li><strong>Payout terms</strong> — short text like "$200 guarantee", "70/30 after $400", or "door split". Surfaced in the print packet and settlement.</li>
      <li><strong>Notes</strong> — backline, hospitality, anything the booker needs to remember.</li>
    </ul>
    <h3>Editing</h3>
    <p>Edit any field inline and click <em>Save</em> on that row. Re-ordering is done by editing the billing order numbers.</p>
    <h3>Band assets</h3>
    <p>Press photos, logos, and band-supplied artwork live in <a href="#help-assets">Assets</a>. Bands with their own backstage account can upload assets directly without needing the booker to relay files.</p>
  `,

  schedule: `
    <h2>Schedule &amp; run sheet</h2>
    <p>The run sheet is the minute-by-minute night-of-show plan.</p>
    <h3>Item types</h3>
    <ul>
      <li><strong>load_in</strong> — when crew/bands arrive and gear comes in.</li>
      <li><strong>soundcheck</strong> — per-band soundcheck blocks.</li>
      <li><strong>doors</strong> — when the public is admitted. Should match the public doors time on <a href="#help-details">Event details</a>.</li>
      <li><strong>set</strong> — a performance set. Create one per band; the lineup's billing order suggests the order.</li>
      <li><strong>changeover</strong> — buffer between sets.</li>
      <li><strong>curfew</strong> — hard stop time.</li>
      <li><strong>staff_call</strong> — when each staff member should arrive.</li>
      <li><strong>other</strong> — anything else (vendor arrival, photographer arrival, VIP arrival).</li>
    </ul>
    <h3>Adding items</h3>
    <p>Use the add form with title, type, start, end, and notes. Save and the row joins the schedule. Edit times inline; save each row when you change it.</p>
    <p><strong>Populate from event data</strong> fills in items automatically from what's already entered elsewhere on the event: load-in, doors, and curfew from <a href="#help-details">Event details</a>, one set per band from the <a href="#help-lineup">Lineup</a>, and staff call times from <a href="#help-staffing">Staffing</a>. It only adds items that aren't already on the run sheet, so it's safe to click again later as those times change.</p>
    <p>The <strong>Add preset</strong> dropdown stamps in a standard run-sheet shape (<em>3 Bands</em>, <em>4 Bands</em>, or <em>Staff Only</em>) timed relative to the doors time, as a starting point you can then edit. Unlike Populate, presets don't check for duplicates — use them on an empty run sheet or expect to clean up if the sheet already has items.</p>
    <h3>Printing</h3>
    <p>The run-of-show printout (see <a href="#help-print">Printable packets</a>) prints the schedule as a single-sheet timeline that staff and bands can keep on hand night of show.</p>
  `,

  staffing: `
    <h2>Staffing</h2>
    <p>The Staffing tab is where you schedule night-of-show personnel — security, bartenders, barbacks, door staff, sound, lighting, stagehands, runners, cleaners, manager-on-duty, and anyone else assigned a shift. It is separate from the <a href="#help-lineup">Lineup</a> (which is for performers) and from <a href="#help-invites">Invites</a> (which gives someone backstage app access).</p>
    <h3>Roles</h3>
    <p>The role dropdown offers a fixed list: <em>Manager, Security, Bartender, Barback, Door, Sound, Lighting, Stagehand, Runner, Cleaner, Other.</em> Use <em>Other</em> for anything unusual and put the specifics in the Notes field.</p>
    <h3>Adding a shift</h3>
    <p>Use the form at the bottom of the panel. Pick the staff member from the roster (or leave as TBD), set the role, call time, end time, hourly rate, status, and any notes. The roster is managed under <a href="#help-admin-staff">Admin &rarr; Staff</a>.</p>
    <p>When you pick a staff member from the dropdown, their default role and hourly rate prefill automatically — you can override either before saving.</p>
    <p>For a <a href="#help-multi-day-events">multi-day event</a>, a Date field also appears on the shift form, bounded to the event's date range and defaulting to the first day — a 3-day festival needs separate shifts (and separate call times) for each day's crew, not one shift shared across the whole run.</p>
    <h3>Shift statuses</h3>
    <ul>
      <li><strong>scheduled</strong> — assigned but not confirmed.</li>
      <li><strong>confirmed</strong> — staff member has confirmed.</li>
      <li><strong>declined</strong> — staff member can't make it; reassign or leave as TBD.</li>
      <li><strong>no_show</strong> — recorded after the fact.</li>
      <li><strong>completed</strong> — shift finished as scheduled.</li>
      <li><strong>canceled</strong> — shift no longer needed.</li>
    </ul>
    <h3>Night-of-show</h3>
    <p>Shifts are grouped by role for a clean read at the door — for a multi-day event, they're grouped by day first, then role, so each day's crew reads as its own call sheet. Print the staffing schedule from the <em>Print</em> menu — it lists call times, role, staff name and phone, and shift status, alongside the run sheet's staff_call times for cross-reference (a multi-day event prints one table per day).</p>
    <h3>TBD shifts</h3>
    <p>You can save a shift without picking a staff member — it appears as <em>TBD</em>. Useful when you know you need (say) two security at 7:30 PM but haven't picked who yet.</p>
  `,

  'open-items': `
    <h2>Open items</h2>
    <p>Open items are external blockers — things waiting on someone or some other system. Examples: "Waiting on ticket link from promoter", "Need signed contract from headliner", "Insurance certificate pending".</p>
    <h3>Statuses</h3>
    <ul>
      <li><strong>open</strong> — actively blocking.</li>
      <li><strong>waiting</strong> — assigned to someone, ticking down.</li>
      <li><strong>resolved</strong> — done.</li>
      <li><strong>canceled</strong> — no longer needed.</li>
    </ul>
    <p>Open items contribute to the dashboard's <em>Open Items</em> count and the readiness signal. Use <em>Mark Complete</em> on a row to resolve it in one click.</p>
    <p>Use <a href="#help-tasks">Tasks</a> for things <em>your team</em> needs to do, and open items for things you are waiting on someone else for. Both feed the same dashboard metric.</p>
  `,

  'guest-list': `
    <h2>Guest list &amp; door</h2>
    <p>The guest list is the door's source of truth — comps, will-call, VIP holds, press, and industry. It is grouped by list type and gives you a live check-in count.</p>
    <h3>List types</h3>
    <ul>
      <li><strong>VIP</strong> — venue or owner VIPs.</li>
      <li><strong>Press</strong> — reviewers, photographers.</li>
      <li><strong>Industry</strong> — promoters, agents, label reps.</li>
      <li><strong>Comp</strong> — free entries the venue is comping.</li>
      <li><strong>Guest</strong> — band and promoter guests (count against their guest allowance).</li>
      <li><strong>Will call</strong> — paid tickets to be picked up at door.</li>
    </ul>
    <h3>Adding a guest</h3>
    <p>Use the add form with name, party size (defaults to 1), list type, optional <em>guest of</em> (e.g. "Headliner"), and notes. Save.</p>
    <h3>Night of show</h3>
    <p>At the door, click the check-in toggle on each row as guests arrive. The header shows total entries, total seats, checked-in entries, and checked-in seats. The row turns muted when checked in so you can see at a glance who has and has not arrived.</p>
    <h3>Printing</h3>
    <p>Use the <em>Print</em> menu at the top of the event to print a door/guest list packet sorted by list type. See <a href="#help-print">Printable packets</a>.</p>
  `,

  assets: `
    <h2>Assets &amp; flyers</h2>
    <p>Assets are flyers, band photos, logos, social cards, and other files attached to the event.</p>
    <h3>Uploading</h3>
    <p>Use the form at the bottom of the Assets panel. Give the file a title, pick a type, choose a file (PNG, JPG, GIF, WEBP, or PDF), add notes, and click <em>Upload asset</em>. Uploads are stored in the per-tenant client directory and served via the <code>/files/</code> URL prefix.</p>
    <h3>Asset types</h3>
    <ul>
      <li><strong>Flyer</strong> — the primary show flyer. The first approved flyer is shown on the public event page and on print packets.</li>
      <li><strong>Poster</strong> — print poster for the venue wall.</li>
      <li><strong>Band photo / Press photo</strong> — used for press kits and social.</li>
      <li><strong>Logo</strong> — band or sponsor mark.</li>
      <li><strong>Social square / Social story</strong> — sized for IG feed and IG/FB stories.</li>
      <li><strong>QR code</strong> — auto-generated, links to the event's public page. See below.</li>
      <li><strong>Other</strong> — anything else.</li>
    </ul>
    <h3>Approval flow</h3>
    <p>Each asset has an approval status: <em>pending</em>, <em>approved</em>, or <em>rejected</em>. Promoters and admins click <em>Approve</em> or <em>Reject</em>. The dashboard's "Needs Flyer" counter watches the count of <em>approved</em> flyers per event.</p>
    <h3>Bands uploading their own assets</h3>
    <p>Bands with a backstage account and a band/artist invite on this event can upload their own press photos and stage plot PDFs without round-tripping through the booker.</p>
    <h3>QR code</h3>
    <p>Every public event automatically shows a scannable QR code in its own header (click <em>QR Code</em> next to <em>Public Page</em>) and directly on the public page itself, so a printed flyer or a phone held up at the door can hand off straight to the event listing. Click <em>Generate QR code</em> in this panel — or <em>Save to Assets</em> in the header's QR panel — to save it as a downloadable PNG asset for flyers and print. Regenerating replaces the existing QR asset rather than piling up duplicates.</p>
  `,

  invites: `
    <h2>Invites &amp; collaborators</h2>
    <p>Invites add another person to a single event as a specific role (see <a href="#help-roles">Roles &amp; permissions</a>).</p>
    <h3>Creating an invite</h3>
    <ol>
      <li>Scroll to the Invites panel on the event.</li>
      <li>Enter the collaborator's email and pick the role.</li>
      <li>Leave <em>Send invitation email</em> checked to have Backstage email the link directly, or uncheck it to generate the link silently (useful when you want to share it via Slack, SMS, or a calendar invite).</li>
      <li>Click <em>Create invite</em>.</li>
      <li>Use <em>Copy link</em> to copy the URL, or — for pending invites — click <em>Email invite</em> later to (re-)send the link.</li>
    </ol>
    <h3>Accepting an invite</h3>
    <p>When the recipient opens the link they see an acceptance page with the event title and role. They enter their name and are signed straight into the event workspace. If they already have an account, the invite is attached to it.</p>
    <h3>Expiration</h3>
    <p>Invite links show their expiry date and last 14 days. Once used they switch to <em>Accepted</em> and the <em>Email invite</em> button disappears. Create a fresh invite if a link expires before it is used.</p>
    <h3>Email delivery</h3>
    <p>Backstage hands invitation emails to the server's <code>sendmail</code> (Exim) for delivery and writes a copy to <code>storage/mail/</code> for local inspection. Delivery problems are logged but never block the API response — if a message fails to send, your link is still valid and you can resend with the <em>Email invite</em> button.</p>
  `,

  contracts: `
    <h2>Contracts &amp; deal builder</h2>
    <p>The contract tool is a <strong>deal builder</strong>, not just a document editor. You capture the deal as structured terms — the money, the dates, the responsibilities — and Backstage assembles the written contract from approved clause modules. The same structured terms can later feed settlement and reporting, so the contract and the operations stay in sync.</p>
    <figure class="help-shot">
      <img src="./assets/help/contract.png" alt="The contract builder's three columns: the deal-terms form on the left, the live document preview in the center, and the status workflow, review warnings, and clause list on the right" loading="lazy">
      <figcaption>The contract builder — deal terms on the left, a live document preview in the center, and the status workflow, missing-term checks, and clause list on the right.</figcaption>
    </figure>

    <h3>Where contracts live</h3>
    <ul>
      <li><strong>On an event</strong> — open an event and use the <em>Contracts</em> tab to create and list contracts tied to that show (rentals, single shows, promoter nights).</li>
      <li><strong>Venue-level</strong> — recurring residencies (e.g. a weekly swing night) aren't tied to one date. A venue admin creates these from <a href="#help-admin-contracts">Admin &rarr; Contracts</a>.</li>
    </ul>

    <h3>Creating a contract</h3>
    <ol>
      <li>From an event's <em>Contracts</em> tab, pick a <strong>deal type</strong> (template) — Private Event Rental, Promoter / Production Show, Artist / Band Performance, Recurring Night, Famous / High-Draw Artist, Fundraiser, or House-Produced Show.</li>
      <li>Optionally enter the counterparty (artist, promoter, or client), then <em>Create contract</em>. You land in the contract builder.</li>
    </ol>

    <h3>The builder, left to right</h3>
    <ul>
      <li><strong>Deal terms (left).</strong> Grouped, collapsible fields: counterparty, money &amp; splits, security &amp; production, recurring/residency terms, and any other variables a clause needs. Fill in what applies and click <em>Save deal terms</em>.</li>
      <li><strong>Preview (center).</strong> A live render of the contract. Blank required values show as highlighted <span class="contract-token-missing">[ placeholders ]</span> so you can see what's still unfilled. Use <em>Generate version</em> to snapshot it, and <em>Download PDF</em> to produce a file (generated right in your browser).</li>
      <li><strong>Status, checks &amp; clauses (right).</strong> The workflow status, missing-term and risk warnings, the clause list, and version history.</li>
    </ul>

    <h3>Smart clause selection</h3>
    <p>Each template starts with a sensible set of clauses. As you change the deal terms, Backstage automatically adds or removes condition-based clauses — for example an all-ages event pulls in the <em>All-Ages Alcohol Control</em> and <em>Security</em> clauses; a bar minimum greater than zero pulls in the <em>Bar Minimum</em> clause. Auto-selected clauses are tagged <span class="auto-tag">auto</span>. Use <em>Smart re-check</em> to re-run the rules after big changes.</p>
    <p>You can always override: toggle any clause on/off, reorder with ↑/↓, edit a clause's text with ✎, add another clause from the library, or rebuild from a template. Clauses marked with a <i class="fa-solid fa-lock"></i> lock (indemnification, governing law, force majeure) are legal language that only venue admins can edit or remove.</p>

    <h3>Missing terms &amp; risk warnings</h3>
    <p>The <em>Review</em> panel lists required terms that are still blank (grouped by clause) and flags deal risks — no deposit on a rental, an all-ages event with no security clause, a guarantee with no cancellation terms, and so on. You cannot mark a contract <em>Sent</em> or <em>Signed</em> until every required term is filled and at least one version has been generated.</p>

    <h3>Status workflow</h3>
    <p>Contracts move through two broad phases. The <strong>draft phase</strong> is internal: <strong>Draft &rarr; Needs Review &rarr; Approved &rarr; Ready to Send</strong>. Once you send the contract out for signature, it enters the <strong>e-sign phase</strong>: <strong>Sent &rarr; Viewed &rarr; Partially Signed &rarr; Signed by Client &rarr; Countersigned &rarr; Fully Executed</strong>. When a contract reaches <em>Fully Executed</em>, the linked event automatically advances to <em>Booked</em> (if it was still in a pre-booked status). Terminal statuses — <em>Voided</em>, <em>Declined</em>, <em>Expired</em>, <em>Cancelled</em>, and <em>Superseded</em> — can occur at any point and end the workflow. Approving requires the <em>approve contracts</em> permission.</p>

    <h3>Versions &amp; PDF</h3>
    <p>Every <em>Generate version</em> stores an immutable snapshot you can re-open from <em>Version history</em>. <em>Download PDF</em> renders the current preview to a PDF for your own records. Once a contract is fully executed via electronic signature, a <strong>Final Executed PDF</strong> is generated server-side with the signature blocks and a one-page audit certificate appended; this is the legally authoritative copy. See <a href="#help-e-signatures">Electronic signatures</a> for the complete send-to-executed workflow.</p>

    <p class="muted small">Who sees what: venue admins and event owners can manage and approve event contracts; promoters can view them; bands, designers, and viewers do not see contracts. Clause text is starter language — have counsel review your clause library before sending real contracts.</p>
  `,

  'e-signatures': `
    <h2>Electronic signatures</h2>
    <p>Panic Backstage has a built-in e-signature flow — no DocuSign or third-party account required. Once a contract is approved, send it for signature with one click. Each signer receives a secure, time-limited link by email and signs right in their browser. When everyone has signed, the system generates a tamper-evident <strong>Final Executed PDF</strong> with embedded signature blocks and an audit certificate, stores a SHA-256 hash of that file, and automatically advances the linked event to <em>Booked</em>.</p>

    <h3>The contract signing status ladder</h3>
    <p>A contract that goes through the full e-sign workflow passes through these statuses:</p>
    <ol>
      <li><strong>Draft</strong> — initial contract in the builder.</li>
      <li><strong>Needs Review</strong> — submitted for admin review.</li>
      <li><strong>Approved</strong> — reviewed and approved; ready to send.</li>
      <li><strong>Ready to Send</strong> — queued up; signers identified. Click <em>Send for Signature</em>.</li>
      <li><strong>Sent</strong> — signing-link emails are delivered; awaiting action.</li>
      <li><strong>Viewed</strong> — at least one signer has opened their link.</li>
      <li><strong>Partially Signed</strong> — one or more signers have signed but at least one is still pending.</li>
      <li><strong>Signed by Client</strong> — the counterparty signer(s) have all signed; awaiting your countersignature.</li>
      <li><strong>Countersigned</strong> — venue has countersigned; generating the final PDF.</li>
      <li><strong>Fully Executed</strong> — all parties have signed. Final PDF is sealed and the linked event moves to <em>Booked</em>.</li>
    </ol>
    <p><strong>Terminal statuses</strong> (can happen at any stage): <em>Voided</em> — admin explicitly voided the contract; <em>Declined</em> — a signer clicked "Decline"; <em>Expired</em> — signing links timed out before everyone signed; <em>Cancelled</em> — contract manually cancelled before or during the signing process; <em>Superseded</em> — contract replaced by a newer version.</p>

    <h3>Who can sign</h3>
    <p>Contracts support multiple signers:</p>
    <ul>
      <li><strong>Renter / Counterparty</strong> — the promoter, artist, or client on the other side of the deal. Their email is taken from the contract's counterparty fields.</li>
      <li><strong>Venue</strong> — the staff member countersigning on behalf of the venue. This is the admin who clicks <em>Countersign</em> inside the app.</li>
      <li>Additional signers (guarantor, artist rep) can be added manually when sending.</li>
    </ul>

    <h3>Sending a contract for signature</h3>
    <ol>
      <li>In the contract editor, make sure the contract is in <strong>Approved</strong> or <strong>Ready to Send</strong> status and all required terms are filled.</li>
      <li>Click <strong>Send for Signature</strong>. A dialog shows the signers — their names and email addresses are pre-filled from the counterparty fields. Add or edit signers if needed.</li>
      <li>Click <strong>Confirm &amp; Send</strong>. Each signer receives an email with a personalised, time-limited signing link.</li>
      <li>The contract status advances to <em>Sent</em> and the <em>Signers</em> panel in the right rail shows each signer's current status (Pending, Viewed, Signed, Declined).</li>
    </ol>

    <div class="help-callout warn">
      <strong>Link expiry.</strong> By default signing links expire after 7 days (168 hours). If a signer says their link stopped working, use <em>Resend Link</em> from the Signers panel to regenerate it.
    </div>

    <h3>What the signer experiences</h3>
    <ol>
      <li>The signer receives an email with a <strong>Review &amp; Sign</strong> button.</li>
      <li>Clicking the link opens <code>/sign.html</code> — a simple, clean page showing the contract for review. The signer does <em>not</em> need a Backstage account.</li>
      <li>After reading, they choose one of two methods:
        <ul>
          <li><strong>Type signature</strong> — their name is rendered in a cursive font as a legal signature.</li>
          <li><strong>Draw signature</strong> — a canvas lets them draw with mouse or touch.</li>
        </ul>
      </li>
      <li>They tick the consent checkbox and click <strong>Sign Agreement</strong>. The signature is recorded immediately; the link is invalidated after use and cannot be reused.</li>
      <li>If they choose to decline, they click <strong>Decline</strong> and can optionally leave a note explaining why.</li>
    </ol>

    <h3>Managing signers — resend, void, countersign</h3>
    <p>These actions are available from the <strong>Signers</strong> panel on the right side of the contract editor (visible once the contract has been sent):</p>
    <ul>
      <li><strong>Resend link.</strong> Generates a fresh signing link for a specific signer and re-sends the email. Use when a signer's original link has expired or they deleted the email.</li>
      <li><strong>Void contract.</strong> Cancels the entire signing process, invalidates all outstanding links, and records a reason. The contract status becomes <em>Voided</em>. Use if terms change substantially or the deal falls through. Void is permanent — start a new contract to re-engage.</li>
      <li><strong>Countersign.</strong> Available to venue admins once all external signers have signed (status = <em>Signed by Client</em>). Click <em>Countersign</em>, provide your typed or drawn signature and consent, and Backstage finalises the contract, generates the PDF, and advances the event.</li>
    </ul>

    <div class="help-callout note">
      <strong>Skip countersignature?</strong> If your venue does not require a countersignature, you can configure the workflow to automatically fully-execute once the counterparty signs. Ask your Backstage administrator to check the workflow settings.
    </div>

    <h3>The audit log</h3>
    <p>Every action on a contract — sent, viewed, signed, declined, voided, countersigned — is recorded in an immutable <strong>Audit log</strong>. Open it from the contract editor's right rail by clicking <em>Audit log</em>. Each entry shows:</p>
    <ul>
      <li>The <strong>action</strong> taken (e.g. <em>contract_sent</em>, <em>contract_signed</em>).</li>
      <li>Which <strong>signer</strong> performed it (name + email), or "Admin" for venue-side actions.</li>
      <li>The <strong>timestamp</strong>, <strong>IP address</strong>, and browser user-agent.</li>
    </ul>
    <p>The audit log is append-only — no entry can be edited or deleted. It is reproduced in full on the last page of the Final Executed PDF as the audit certificate.</p>

    <h3>The Final Executed PDF</h3>
    <p>When a contract reaches <em>Fully Executed</em>, Backstage generates a single locked PDF that contains:</p>
    <ul>
      <li>The full contract body as it was approved.</li>
      <li>A <strong>signature block</strong> for each signer showing their typed or drawn signature, full name, title, the date and time of signing, and their IP address.</li>
      <li>A <strong>one-page audit certificate</strong> at the end listing every event in the lifecycle from draft to execution.</li>
      <li>A <strong>SHA-256 hash</strong> of the document, stored in the database so you can verify the file has not been altered after the fact.</li>
    </ul>
    <p>To download the final PDF, open the contract and click <strong>Download Final PDF</strong> in the right rail. The button is only present once the contract is in <em>Fully Executed</em> status.</p>

    <h3>Security</h3>
    <ul>
      <li>Signing tokens are single-use and expire after the configured TTL (default 7 days). Only the SHA-256 <em>hash</em> of the token is stored in the database; the raw token exists only in the email link.</li>
      <li>The token is invalidated the moment the signer clicks <em>Sign Agreement</em> or <em>Decline</em>. Refreshing the page or forwarding the link will no longer open the signing form.</li>
      <li>A voided contract's outstanding tokens are invalidated immediately — signers who follow an old link see an "this link is no longer valid" message.</li>
      <li>If a signer's link is resent, the previous token is invalidated and only the new one works.</li>
    </ul>

    <p class="muted small">Who sees what: venue admins can send, void, resend, countersign, and download contracts. Event owners can view contract status. The signing page (<code>/sign.html</code>) is publicly accessible — signers need no Backstage account.</p>
  `,

  ticketing: `
    <h2>Ticketing &amp; door</h2>
    <p>Backstage can sell tickets for a show directly — no third-party platform required — and then scan them at the door. The whole flow lives on the event's <em>Ticketing</em> tab. Before you can take money, a venue admin must pick a payment processor under <a href="#help-admin-payments">Admin &rarr; Payments</a>.</p>
    <figure class="help-shot">
      <img src="./assets/help/ticketing.png" alt="The Ticketing tab: an in-house ticketing badge, a live sales summary (sold, available, redeemed, gross), the mode toggle, and the ticket-tier form" loading="lazy">
      <figcaption>The Ticketing tab in in-house mode — live sales totals up top, the external/internal mode toggle, and the ticket-tier editor below.</figcaption>
    </figure>

    <h3>External vs. in-house ticketing</h3>
    <p>Every event is in one of two ticketing modes, shown as a badge at the top of the tab:</p>
    <ul>
      <li><strong>External ticketing.</strong> The default. You sell elsewhere (Eventbrite, DICE, a promoter's link) and just paste the link into the <em>Ticket URL</em> field on <a href="#help-details">Event details</a>. Backstage doesn't track inventory.</li>
      <li><strong>In-house ticketing.</strong> Backstage sells the tickets, holds the inventory, emails each buyer a QR ticket, and lets you scan at the door. Switch the mode toggle to turn this on.</li>
    </ul>
    <p>The first time you switch a fresh event to in-house, Backstage seeds a ready-to-run setup so you are not starting from a blank slate: a <strong>General Admission</strong> tier at the event's ticket price, sized to capacity minus a 20-ticket house hold; a <strong>Comps</strong> allocation of those 20 (free and off-sale, but issuable from the comp flow); and a <strong>Door</strong> scanner link. All three are starting points — edit, rename, or delete any of them.</p>

    <h3>Ticket tiers</h3>
    <p>In in-house mode, create one or more <strong>ticket tiers</strong> (Advance, Door, VIP, and so on). Each tier has:</p>
    <ul>
      <li><strong>Name &amp; price.</strong> What the buyer sees, in your configured currency.</li>
      <li><strong>Quantity.</strong> Total inventory for the tier. Backstage tracks sold vs. available live and won't oversell.</li>
      <li><strong>Sales window.</strong> Optional start/end dates for when the tier is buyable.</li>
      <li><strong>Status.</strong> <em>draft</em> (hidden), <em>on sale</em> (buyable), <em>paused</em>, <em>sold out</em> (set automatically when inventory runs out), or <em>closed</em>.</li>
    </ul>
    <p>The dashboard at the top of the tab shows live sales — sold, available, redeemed, and gross revenue — across all tiers.</p>

    <h3>How a sale works</h3>
    <ol>
      <li>Once the event is <a href="#help-publish">published</a> and a tier is <em>on sale</em>, the public event page shows a <em>Buy tickets</em> panel.</li>
      <li>The buyer picks quantities and checks out. Backstage holds that inventory for <strong>15 minutes</strong> and sends them to the processor's hosted checkout page — card details never touch Backstage.</li>
      <li>When the processor confirms payment, Backstage issues the tickets, emails each one as a QR code, and updates the sold count. If payment fails or times out, the hold is released and the inventory comes back.</li>
    </ol>

    <h3>Comp tickets</h3>
    <p>Use the <em>Comp tickets</em> section to issue free tickets (guests, press, trade) without a payment. Enter the recipient's name, email, tier, and quantity, and Backstage emails them a real scannable QR just like a paid ticket. Comps still count against the tier's inventory, so they can't push you into an oversell.</p>
    <p>You can also comp straight from the <a href="#help-guest-list">guest list</a>: give a guest an email and click <em>Issue comp</em>, and Backstage issues one ticket per seat in their party, emails the QR, and links the tickets back to that guest so you can re-view or resend them later.</p>

    <h3>Issued tickets</h3>
    <p>The <em>Issued tickets</em> list shows every ticket for the event — sold and comped — with its holder, tier, and live status. From each row you can <strong>View</strong> the QR (the same page the buyer got), <strong>Resend</strong> the link to the holder's email, or <strong>Void</strong> a ticket to invalidate it. The status is the source of truth at the door: a <em>Valid</em> ticket admits once and then flips to <em>Scanned in</em>, so the same QR can never get two people in; a <em>Void</em> ticket won't scan at all.</p>

    <h3>Refunds</h3>
    <p>The <em>Refund / cancel</em> action is the cancel-the-show path: it refunds buyers through the original processor and voids every ticket for the event so none of them scan at the door. (Per-ticket partial refunds aren't in this version.)</p>

    <h3>Door scanner links</h3>
    <p>Door staff don't need a Backstage login. Instead you create a <strong>scanner link</strong> in the <em>Door scanner links</em> section:</p>
    <ol>
      <li>Click <em>New scanner link</em>, give it a label (e.g. "Front door iPad"), and optionally set a PIN and/or expiry.</li>
      <li>Backstage shows the secret link <strong>once</strong> — copy it then. Open it on the scanning device.</li>
      <li>The link opens a mobile camera scanner. Staff point it at each ticket's QR; an admit / already-used / void / not-found result shows instantly.</li>
      <li>Every scan is logged. Revoke a link any time to immediately cut off that device without affecting tickets or other doors.</li>
    </ol>
    <p>Redemption is one-and-done: a ticket flips from <em>issued</em> to <em>redeemed</em> on the first valid scan, so a screenshotted or forwarded QR can't get a second person in.</p>

    <p class="muted small">Who sees what: the Ticketing tab (tiers, comps, refunds, scanner links) is limited to venue admins and event owners — promoters and other collaborators don't see it. Selecting the payment processor is a venue-admin task under <a href="#help-admin-payments">Admin &rarr; Payments</a>.</p>
  `,

  settlement: `
    <h2>Settlement</h2>
    <p>Settlement is the night-of-show or next-day reconciliation. It is visible to venue admins and event owners and hidden from promoters, designers, bands, and viewers.</p>
    <h3>Fields</h3>
    <ul>
      <li><strong>Gross ticket sales</strong> — total ticket revenue (Stripe export or manual).</li>
      <li><strong>Tickets sold</strong> — paid tickets, excluding comps.</li>
      <li><strong>Bar sales</strong> — bar take.</li>
      <li><strong>Expenses</strong> — production, hospitality, security, etc.</li>
      <li><strong>Band payouts</strong> — total paid to performers (sum of all lineup payouts).</li>
      <li><strong>Promoter payout</strong> — paid to outside promoter if applicable.</li>
      <li><strong>Venue net</strong> — the venue's take. Click <em>Calculate venue net</em> to derive: <code>gross + bar − expenses − band − promoter</code>.</li>
      <li><strong>Notes</strong> — anything else (cash float, discrepancies, comp count).</li>
    </ul>
    <p>Save the form to record the settlement. Once filed, the event drops off the dashboard's <em>Unsettled</em> count.</p>
  `,

  publish: `
    <h2>Publishing the public page</h2>
    <p>Every event has a public-facing page at <code>/event.html?id=&lt;event id&gt;</code> that shows the title, date, doors/show, age restriction, ticket link, public description, lineup, and the approved flyer. The link is keyed by the event's stable id rather than its title-derived slug, so it keeps working even after the event is renamed or rescheduled.</p>
    <h3>Toggling publish</h3>
    <p>Click <em>Publish Public Page</em> at the top of the event workspace to make it live, or <em>Hide Public Page</em> to take it offline. The same toggle exists as a checkbox in <a href="#help-details">Event details</a>.</p>
    <h3>Previewing</h3>
    <p>Click <em>Public Page</em> in the event header to open the public page in a new tab. It is fetched anonymously from <code>/api/public/events/&lt;id&gt;</code> (old <code>&lt;slug&gt;</code> links still resolve too); if the event is hidden the API returns an error.</p>
  `,

  print: `
    <h2>Printable packets</h2>
    <p>The <em>Print</em> menu at the top right of the event opens a self-contained print window with five layouts:</p>
    <ul>
      <li><strong>Band Lineup</strong> — billing order, set times, set lengths, payout terms.</li>
      <li><strong>Staffing Schedule</strong> — staff call times pulled from the run sheet.</li>
      <li><strong>Run of Show</strong> — full run sheet with timeline.</li>
      <li><strong>Door / Guest List</strong> — guest list grouped by list type with check-in columns.</li>
      <li><strong>Master Event Packet</strong> — every section combined into one printable packet for the production binder.</li>
    </ul>
    <p>Use Cmd/Ctrl+P or click the <em>Print</em> button inside the new window. Layouts are sized for US Letter with 0.5 inch margins.</p>
  `,

  activity: `
    <h2>Activity log</h2>
    <p>The Activity panel at the bottom of every event lists every meaningful change — who saved what and when. Use it for forensic questions ("when did the doors time change?") and as a hand-off log between bookers and night-of-show staff.</p>
  `,

  admin: `
    <h2>Admin overview</h2>
    <p>The Admin nav item is visible only to venue admins. It groups these management tools as tabs on a single page:</p>
    <ul>
      <li><a href="#help-admin-users">Users</a> — create, edit, and delete backstage login accounts; reset passwords; change roles.</li>
      <li><a href="#help-admin-venue">Venue</a> — the venue's own profile (name, address, timezone) and its rooms.</li>
      <li><a href="#help-admin-staff">Staff</a> — keep the roster of bartenders, security, door, sound, etc. used in event staffing.</li>
      <li><a href="#help-admin-templates">Templates</a> — edit run-sheet and checklist templates used to create new events.</li>
      <li><a href="#help-admin-contracts">Contracts</a> — the contract clause library, contract templates, and a venue-wide list of all contracts.</li>
      <li><a href="#help-admin-payments">Payments</a> — choose the payment processor and currency used for in-house ticket sales.</li>
      <li><a href="#help-admin-wizard-defaults">Wizard defaults</a> — pre-fill values for the event creation wizard.</li>
      <li><a href="#help-admin-db">Database browser</a> — read-only SQL browser over the app's own database.</li>
      <li><a href="#help-admin-db-history">Database history</a> — row-level change history and one-click undo.</li>
      <li><a href="#help-admin-navigation">Navigation manager</a> — edit the app shell's own sidebar.</li>
    </ul>
    <p>Each tab has a stable deep link: <code>#admin-users</code>, <code>#admin-venue</code>, <code>#admin-staff</code>, <code>#admin-templates</code>, <code>#admin-contracts</code>, <code>#admin-payments</code>, <code>#admin-wizard-defaults</code>, <code>#admin-db</code>, <code>#admin-db-history</code>, <code>#admin-navigation</code>.</p>
    <p class="muted small">A "Duplicates" tool for merging duplicate user accounts also exists but is currently hidden from the tab bar — the one-time cleanup it was for is done.</p>
  `,

  'admin-venue': `
    <h2>Venue &amp; rooms</h2>
    <p>The <strong>Venue</strong> admin tab has two parts: the venue's own profile, and the rooms within it.</p>

    <h3>Venue details</h3>
    <p>Name, address, city/state, timezone, phone, and website. These appear on contracts, emails, and the public event page — fill them in early (the onboarding checklist links here).</p>

    <h3>Rooms</h3>
    <p>Rooms are the actual bookable spaces within the venue — e.g. a room per stage, floor, or sub-space. An event's <a href="#help-details">Room</a> field picks one of these. Each room has:</p>
    <ul>
      <li><strong>Name</strong> — shown on the event's Room field, the calendar legend, and room-conflict messages.</li>
      <li><strong>Capacity</strong> — optional; falls back to the event's own Capacity field when set.</li>
      <li><strong>Zone</strong> — <em>Primary</em> (a standalone room with no special relationship to others), <em>Up</em> / <em>Down</em> (one of two paired halves of a building), or <em>Both</em> (a whole-building booking that spans the Up/Down pair). Zone drives two things: the calendar's up/down/both colour-coded split, and room-conflict detection — a <em>Both</em> booking blocks every other room in the venue for that date range, and a specific room booking is blocked by any existing <em>Both</em> booking.</li>
      <li><strong>Sort order</strong> — display order in the Room dropdown and the rooms table.</li>
      <li><strong>Active / Archived</strong> — archiving a room (rather than deleting it) hides it from the Room picker for new bookings while keeping it on any past event that already used it. Archived rooms can be restored at any time.</li>
    </ul>
    <p class="help-tip">💡 On a single-venue install, the event Venue field is hidden everywhere (Details tab, Quick Create, the event wizard) since there's nothing to choose — only Room shows. Add a second venue and the Venue picker reappears automatically across all three.</p>
  `,

  'admin-users': `
    <h2>Managing login accounts</h2>
    <p>Admin &rarr; Users lists every account that can log into backstage. The table shows name, email, role, authentication methods (password and registered passkeys), and how many events each user owns or collaborates on.</p>
    <h3>Creating a user</h3>
    <p>Use the <em>Create User</em> form. Required: name, email, role. Password is optional — if you leave it blank, the user can still sign in via passkey or by requesting an email login link from the login page.</p>
    <h3>Editing a user</h3>
    <p>Click <em>Edit</em> on any row. The dialog lets you change name, email, role, and reset the password. To leave the password unchanged, leave the password field blank. Existing passkeys are listed by count; users remove individual passkeys themselves from their <em>Account</em> page.</p>
    <h3>Roles</h3>
    <p>A user's global role determines what they can do across the whole app (admins see every event; others only see what they own or collaborate on). Per-event collaborator roles are managed from each event's <a href="#help-invites">Invites</a> panel. See <a href="#help-roles">Roles &amp; permissions</a> for the full breakdown.</p>
    <h3>Deleting a user</h3>
    <p>You cannot delete yourself. You cannot delete a user who currently owns events — reassign their events first (via each event's <em>Owner</em> field). Deleting a user removes their <code>event_collaborators</code> rows; their authored activity-log entries remain but show as orphaned.</p>
  `,

  contacts: `
    <h2>Contacts (CRM)</h2>
    <p>The <strong>Contacts</strong> page is the venue's audience list — the people who have bought tickets and can receive event email. It is a separate top-level section (not the same as login <a href="#help-admin-users">Users</a> or the <a href="#help-admin-staff">Staff roster</a>) and is visible to venue admins only.</p>
    <figure class="help-shot">
      <img src="./assets/help/contacts.png" alt="The Contacts page: four summary cards across the top, then a search bar and a table of people with email, phone, tickets, spend, and a marketing opt-in badge" loading="lazy">
      <figcaption>Contacts — summary cards up top, then a searchable, sortable, paged table of the audience.</figcaption>
    </figure>
    <h3>Where the data comes from</h3>
    <p>Contacts are seeded from the ticketing provider's <em>Fan View</em> export. An admin runs <code>php scripts/import-fanview.php</code> with the CSV; the importer keys on each person's provider ID, so re-running it updates existing contacts (ticket counts, spend, last interaction) instead of creating duplicates. You can also add people by hand. The raw export holds real customer details and is never committed to the codebase.</p>
    <h3>The summary cards</h3>
    <p>The four cards at the top read the whole list, independent of any search or filter: total <strong>Contacts</strong>, how many are <strong>Opted in</strong> to marketing email (with the percentage), <strong>Tickets sold</strong>, and <strong>Lifetime spend</strong>.</p>
    <h3>Searching, sorting, and segmenting</h3>
    <p>The search box matches across name, email, and phone. The <em>Marketing</em> dropdown segments the list to <em>Opted in</em> or <em>Not opted in</em>. Click a column header (Name, Email, Tickets, Spend, Last seen) to sort, and click again to flip the direction. Results are paged 50 at a time — use <em>Prev</em> / <em>Next</em> below the table.</p>
    <h3>Marketing opt-in</h3>
    <p>The <em>Opted in</em> badge reflects whether the person agreed to receive marketing email. Only contacts opted in should be included when you send a promotional blast; respect the segment. Editing a contact and turning opt-in on stamps an opt-in date automatically.</p>
    <h3>Adding and editing</h3>
    <p>Use <em>Add contact</em> for a one-off, or <em>Edit</em> on any row to correct a name, email, phone, birthday, marketing status, or notes. Provider-sourced figures (tickets, spend, last seen) are shown for context on the edit form but are maintained by the import, not edited here. <em>Delete</em> removes a contact permanently.</p>
  `,

  'admin-staff': `
    <h2>Staff roster</h2>
    <p>The staff roster is the master list of people who work events — security, bartenders, barbacks, door, sound engineers, lighting, stagehands, runners, cleaners, and on-duty managers. It is intentionally separate from the Users table: most night-of-show staff don't need a backstage login.</p>
    <h3>Adding a staff member</h3>
    <p>Use the <em>Add Staff</em> form. Required: name and default role. Email, phone, hourly rate, and notes are optional. If the staff member also has a backstage login (e.g. a manager), pick their user account in the <em>Link to login</em> dropdown so the two records stay connected.</p>
    <h3>Default role and rate</h3>
    <p>The default role and hourly rate prefill into new shift forms when you pick the staff member, but you can override either per-shift. Useful when (for example) a bartender occasionally picks up a barback shift.</p>
    <h3>Active vs inactive</h3>
    <p>Toggle <em>Active</em> off when someone leaves or stops picking up shifts. Inactive staff stop appearing in the event Staffing dropdowns but stay in the roster so historical shifts continue to show their name.</p>
    <h3>Deleting a staff member</h3>
    <p>Deleting removes them from the roster permanently. Past shifts they were assigned to remain in the database — the shift's staff_member link is cleared and the shift shows as <em>TBD</em> on the historical record.</p>
  `,

  'admin-templates': `
    <h2>Editing event templates</h2>
    <p>Templates are pre-built event blueprints used by <a href="#help-templates">Templates</a> to spawn new events with pre-loaded tasks and schedule blocks. The Admin &rarr; Templates tab is where you create, edit, and delete them.</p>
    <h3>Anatomy of a template</h3>
    <ul>
      <li><strong>Name</strong> — what staff see when picking a template.</li>
      <li><strong>Type</strong> — the event type the template produces.</li>
      <li><strong>Venue</strong> — which venue this template is for.</li>
      <li><strong>Default title, ticket price, age, public description</strong> — values pre-filled into new events.</li>
      <li><strong>Checklist</strong> — one task per line. Each line becomes a Task on every new event created from this template.</li>
      <li><strong>Schedule</strong> — one line per item in the form <code>HH:MM | type | title</code>. The type must be one of <em>load_in, soundcheck, doors, set, changeover, curfew, staff_call, other</em>. Each line becomes a schedule row on the new event's run sheet.</li>
    </ul>
    <h3>Editing existing schedules</h3>
    <p>Open the template, edit the text, save. The new format will be used by future events created from this template; existing events keep their current run sheets unchanged.</p>
    <h3>Deleting a template</h3>
    <p>Deletes the template only. Events that were already created from it continue to exist and behave normally.</p>
  `,

  'admin-contracts': `
    <h2>Contract library &amp; templates</h2>
    <p>Admin &rarr; Contracts is where venue admins manage the building blocks behind every contract. It has three sub-tabs: <strong>All Contracts</strong>, <strong>Clause Library</strong>, and <strong>Templates</strong>. For how staff build a contract from these pieces, see <a href="#help-contracts">Contracts &amp; deal builder</a>.</p>

    <h3>All Contracts</h3>
    <p>A venue-wide list of every contract — event-bound and venue-level — with type, who it's for, counterparty, status, and last update. Click any row to open it in the builder. This tab also has a <em>New venue-level contract</em> form for residencies and other deals that aren't tied to a single event (pick a venue + template + counterparty).</p>

    <h3>Clause Library</h3>
    <p>Clauses (called <em>modules</em>) are the reusable paragraphs contracts are assembled from. Each clause has:</p>
    <ul>
      <li><strong>Name</strong> and a stable <strong>key</strong> (auto-generated from the name; used internally).</li>
      <li><strong>Category</strong> — base, financial, operational, legal, or risk (for grouping).</li>
      <li><strong>Risk level</strong> — none / low / medium / high, shown as a badge on the contract.</li>
      <li><strong>Required variables</strong> — comma-separated token keys the clause needs (e.g. <code>rental_fee, deposit_amount</code>). Any blank required variable on an included clause becomes a "missing term" warning and blocks sending.</li>
      <li><strong>Locked</strong> — locked clauses (indemnification, governing law, force majeure) can only be edited or removed by admins, even on an individual contract.</li>
      <li><strong>Active</strong> — inactive clauses stay on existing contracts but aren't offered for new ones.</li>
      <li><strong>Body</strong> — the clause text. Use <code>{{variable}}</code> tokens; they're filled from the contract's deal terms (e.g. <code>{{rental_fee}}</code>, <code>{{recurrence_rule}}</code>) and a few built-ins like <code>{{venue_name}}</code>, <code>{{counterparty_display}}</code>, and <code>{{event_date}}</code>. Money, percent, and date tokens are formatted automatically.</li>
    </ul>

    <h3>Templates</h3>
    <p>A template is an ordered set of clauses for a deal type. Backstage ships seven — Recurring Night, Private Event Rental, Promoter / Production Show, Artist / Band Performance, Famous / High-Draw Artist, Fundraiser / Charity Event, and House-Produced Show — and you can add your own.</p>
    <p>In the template editor, check the clauses to include and order them. For each clause you choose how it's included:</p>
    <ul>
      <li><strong>Required</strong> — always included, can't be auto-removed.</li>
      <li><strong>Condition</strong> — a small JSON rule that auto-includes the clause only when the deal matches. Examples:
        <br><code>{"all":[{"field":"age_policy","op":"eq","value":"all_ages"}]}</code> — include for all-ages events.
        <br><code>{"any":[{"field":"expected_attendance","op":"gte","value":200},{"field":"age_policy","op":"eq","value":"all_ages"}]}</code> — include when the crowd is large <em>or</em> all-ages.
        <br><code>{"all":[{"field":"bar_minimum","op":"gt","value":0}]}</code> — include when there's a bar minimum.</li>
      <li><strong>Neither</strong> — included by default but removable on the contract.</li>
    </ul>
    <p>Supported condition operators: <code>eq</code>, <code>ne</code>, <code>in</code>, <code>nin</code>, <code>gt</code>, <code>gte</code>, <code>lt</code>, <code>lte</code>, <code>set</code>, <code>truthy</code>, <code>falsy</code>. Fields can be any deal-term column (<code>bar_minimum</code>, <code>guarantee_amount</code>, …), any contract variable, or the derived helpers <code>age_policy</code>, <code>expected_attendance</code>, and <code>room</code>.</p>

    <h3>Editing safely</h3>
    <p>Editing a clause or template changes what <em>future</em> contracts pick up. Existing contracts keep the snapshot they were built with — applying a template again on a contract rebuilds its clauses (and warns first). The seeded library is starter language; have legal review it before it's used for real agreements.</p>
  `,

  'admin-payments': `
    <h2>Payment providers</h2>
    <p>Admin &rarr; Payments (<code>#admin-payments</code>) is where a venue admin chooses how in-house ticket sales are processed. It's only needed if you sell tickets through Backstage — see <a href="#help-ticketing">Ticketing &amp; door</a> for the selling and scanning flow. The tab is gated to venue admins.</p>

    <h3>Choosing a processor</h3>
    <p>Backstage supports <strong>Stripe</strong> and <strong>Square</strong>. Pick the <em>active provider</em> and the <em>currency</em> used for all ticket prices, then save. Only one provider is active at a time; switching it changes which processor new checkouts are sent to (tickets already sold are unaffected).</p>

    <h3>Where the keys live</h3>
    <p>For security, the actual API secret keys are <strong>never entered or shown in the app</strong> — they live in the server's environment configuration (<code>.env</code>), set once during deployment. This screen only shows, per provider, whether its keys are present, so you can confirm a processor is fully configured before going live. If a provider shows as not configured, ask whoever runs the server to add its keys.</p>
    <p>Required environment keys are <code>STRIPE_SECRET_KEY</code> and <code>STRIPE_WEBHOOK_SECRET</code> for Stripe, and <code>SQUARE_ACCESS_TOKEN</code>, <code>SQUARE_LOCATION_ID</code>, <code>SQUARE_WEBHOOK_SIGNATURE_KEY</code>, <code>SQUARE_ENV</code>, and <code>SQUARE_WEBHOOK_URL</code> for Square.</p>

    <h3>Webhooks</h3>
    <p>Each processor confirms payment by calling back to Backstage (a "webhook") at <code>/api/webhooks/stripe</code> or <code>/api/webhooks/square</code>. Register that URL in the Stripe/Square dashboard during setup. Backstage verifies every callback's signature and is safe against duplicate notifications, so a buyer is never double-charged tickets or double-emailed.</p>

    <h3>Going live checklist</h3>
    <ol>
      <li>Server keys for your chosen processor are present (this screen confirms it).</li>
      <li>Active provider and currency are set here.</li>
      <li>The webhook URL is registered in the processor's dashboard.</li>
      <li>On an event, switch <a href="#help-ticketing">Ticketing</a> to in-house mode, add an <em>on sale</em> tier, and publish the page.</li>
    </ol>
  `,

  'admin-wizard-defaults': `
    <h2>Wizard defaults</h2>
    <p>Admin &rarr; Wizard (<code>#admin-wizard-defaults</code>) sets the values that pre-fill every new event in the <a href="#help-event-wizard">event creation wizard</a> — Venue, Event type, Age restriction, Capacity, Doors/Show/End times, default Deal type, Deposit, Bar minimum, Venue merch cut, and whether sound/lighting tech and security are typically included. Restricted to venue admins.</p>
    <p>Leave any field set to <em>"— no default —"</em> to leave it blank in the wizard instead of pre-filling it. Staff can always override any default while filling out the wizard for a specific show — these are starting points, not requirements.</p>
  `,

  'admin-navigation': `
    <h2>Navigation manager</h2>
    <p>Admin &rarr; Navigation (<code>#admin-navigation</code>) edits the app shell's own sidebar — the same nav you're using right now. Restricted to venue admins.</p>
    <h3>Layout</h3>
    <p>Three panes: a draggable, nestable list of nav items on the left, an edit form for whichever item is selected in the middle, and a live preview on the right built from the exact same rendering code the real sidebar uses — so the preview can never drift from what actually ships once you save.</p>
    <h3>Editing an item</h3>
    <p>Each item has a label, an icon (pick from a curated FontAwesome list, or supply any class string via "Custom…"), a link target, and a visibility toggle. Drag items to reorder them or nest one under another to create a submenu.</p>
    <p class="help-tip">💡 Changes take effect immediately for everyone once saved — there's no separate "publish" step, so double-check the live preview before saving anything that affects how staff navigate the app.</p>
  `,

  'admin-db': `
    <h2>Database browser</h2>
    <p>Admin &rarr; DB Browser (<code>#admin-db</code>) is a read-only inspector for every table in this venue's database. It's meant for admins tracking down a data question — "what's actually in this row?" — without needing direct database access. Restricted to venue admins.</p>
    <h3>Browsing</h3>
    <p>Pick a table on the left. The rows pane supports per-column filtering (the search row under the headers) and sorting (click a column header). Click any row to open a full field-by-field detail card below it.</p>
    <h3>Downloading</h3>
    <p>The Download button exports the current filtered/sorted view as CSV, Excel, or a <code>.sql</code> file of ready-to-run <code>INSERT</code> statements — capped at 20,000 rows.</p>
    <p>This tool is read-only by design; it has no way to edit or delete a row. To actually correct bad data (and see who/what wrote it), use <a href="#help-admin-db-history">Database history &amp; undo</a> instead.</p>
  `,

  'admin-db-history': `
    <h2>Database history &amp; undo</h2>
    <p>Admin &rarr; DB History (<code>#admin-db-history</code>) is an audit trail of every insert, update, and delete on this venue's database — no matter whether it came from the app, a background sync job, or someone at a terminal — with a one-click undo for any entry. Restricted to venue admins; this is more sensitive than the read-only <a href="#help-admin-db">Database browser</a>, so it has its own permission.</p>

    <h3>Why this exists</h3>
    <p>Most edits already show up in an event's own activity log. But some writes — an automated sync, a data-fix script, a stray edit run directly against the database — never go through the app at all, so nothing records them anywhere else. Database history catches <em>every</em> write at the database level, so nothing that changes your data is invisible.</p>

    <h3>Reading the list</h3>
    <p>Each row is one write: when it happened, which table and row id, whether it was an insert/update/delete, who or what did it, and — for updates — which fields actually changed. Filter by table, row id, actor, action, or whether an entry has already been undone.</p>
    <p>The <strong>Actor</strong> column shows who made the change: <code>user #12</code> for something done by a logged-in person, or a script name (e.g. <code>sync-mabevents.py</code>) for an automated job. An entry with no actor recorded is labeled <em>unattributed</em> — this happens for writes made directly against the database outside the app (for example, from a <code>mysql</code> command line), which have no session to tag.</p>

    <h3>Undo — and redo</h3>
    <p>Click any entry to open its detail panel: the full before/after values (or the full row, for an insert or delete) and an <strong>Undo</strong> button. Undo runs a stored, ready-made reverse SQL statement immediately — deleting an inserted row, restoring a deleted row, or reverting an update's fields back to what they were.</p>
    <p>There's no separate "redo" feature, and none is needed: undoing a change is itself a real write, so it creates its own new history entry — the exact reverse of the one you just undid. To redo something you undid by mistake, open that new entry and click Undo on <em>it</em>. The detail panel always shows a link between paired entries ("this undid entry #123" / "undone → see entry #456") so you can follow the chain either direction.</p>
    <p>An entry can only be undone once; the button is replaced with a confirmation once it has been. Undoing always asks you to confirm first, since it runs immediately — there's no draft or preview step.</p>

    <h3>What it can't do</h3>
    <p>History only covers the database itself — writes to disk (uploaded files, generated PDFs, sent email) aren't touched by an undo, so reverting a database change doesn't un-send an email or restore a deleted file. Entries older than 30 days are automatically pruned to keep the table from growing without bound; for recovery beyond that window, or for a whole-database point-in-time restore, ask whoever runs the server about the separate 5-minute snapshot backups.</p>
  `,

  // ── Messages: Campaigns & Lists ─────────────────────────────────────────────

  'msg-campaigns': `
    <h2>Campaigns</h2>
    <p>An email campaign is a one-off marketing email — a newsletter, a "shows this week" blast, a single-event push — sent to your mailing lists and/or hand-picked contacts. Find it under <strong>Messages &rarr; Campaigns</strong> in the sidebar.</p>
    <div class="note"><strong>Note:</strong> This is a different tool from <a href="#help-promote-campaigns">Panic Promote campaigns</a>, which coordinate an event's social/listing-site promotion. An email Campaign is only the email side of marketing, and can exist with or without a Promote campaign on the same event.</div>
    <h3>Starting a campaign</h3>
    <ul>
      <li><strong>New Blank Email</strong> — name it and start writing; both the HTML and plain-text bodies begin from a blank starter template you fill in yourself.</li>
      <li><strong>Generate from Event(s)</strong> — pick one or more upcoming public events from a filterable list and Backstage builds a ready-to-send "lineup" email from them (the same event-card rendering used by the weekly "shows this week" digest). Only events that are public and already <em>Published</em> or <em>Advanced</em> are offered; anything else is silently skipped and you're told how many were dropped.</li>
    </ul>
    <h3>Editing</h3>
    <p>A campaign can only be edited while it's a <strong>draft</strong>. Toggle between the <strong>HTML</strong> and <strong>Plain text</strong> views to edit each body — they're saved independently and both go out together, so keep them in sync yourself. Name and subject are editable right in the detail header.</p>
    <h3>Picking recipients</h3>
    <p>Click <strong>Recipients</strong> to open the picker: check any mailing lists to include, and search opted-in contacts to add individually. The panel shows a live recipient count as you adjust the selection.</p>
    <div class="warn"><strong>Important:</strong> Only contacts with marketing email turned on are ever counted or sent to — picking a list or a contact that isn't opted in doesn't add them to the send; they're simply excluded (and recorded as skipped) rather than blocking the whole send.</div>
    <h3>Sending</h3>
    <ul>
      <li><strong>Send Test&hellip;</strong> — email the current draft to any single address (yourself, a coworker) to proof it before it goes out. Test sends don't lock the draft or affect recipient counts.</li>
      <li><strong>Send Campaign</strong> — sends to every resolved, opted-in recipient and moves the campaign out of draft status for good (it can no longer be edited or deleted). The campaign list shows sent vs. failed counts per campaign; one recipient's delivery failure never stops the rest of the send.</li>
    </ul>
    <div class="tip"><strong>Tip:</strong> Only venue admins can see or use Campaigns and Lists — both are gated by the same <code>manage_campaigns</code> capability as the Contacts CRM.</div>
  `,

  'msg-lists': `
    <h2>Mailing Lists</h2>
    <p>Mailing lists are reusable, named audiences built on top of <a href="#help-contacts">Contacts</a> — build one once (a VIP list, a genre-specific list, "opted-in regulars") and reuse it across campaigns instead of re-picking recipients by hand every time. Manage them under <strong>Messages &rarr; Lists</strong>, or from a contact's own record.</p>
    <h3>Static vs. Smart lists</h3>
    <p>Every list is one of two types, chosen at creation and <strong>fixed for the life of the list</strong> — there's no converting one type to the other, since there's no good answer for what should happen to existing members on a type change. Make a new list instead.</p>
    <ul>
      <li><strong>Static</strong> — you choose members by hand: checkbox picker, "add all matching" a filter, or CSV import. Membership only changes when you change it.</li>
      <li><strong>Smart (segment)</strong> — membership is computed from saved rules (opted-in, minimum spend, minimum events attended, minimum tickets bought) and kept in sync only when you click <strong>Refresh now</strong> — there's no background job re-running it automatically.</li>
    </ul>
    <h3>Adding members to a static list</h3>
    <ul>
      <li><strong>Search &amp; check</strong> — search contacts by name or email, optionally filter to <em>Opted in only</em>, check the ones you want, and click <strong>Add selected</strong>.</li>
      <li><strong>Add all matching</strong> — instead of checking boxes one page at a time, this adds every contact matching your current search/filter in one shot, even beyond what's visible on screen.</li>
      <li><strong>Import CSV</strong> — upload a CSV with an <code>email</code> column (required) plus optional <code>first_name</code>, <code>last_name</code>, <code>phone</code>, and <code>opted_in</code> columns. Rows are matched to existing contacts by email or create new ones; a bad row (missing/invalid email) is reported individually rather than failing the whole upload.</li>
    </ul>
    <div class="note"><strong>Note:</strong> Uploading a CSV without an <code>opted_in</code> column never changes anyone's opt-in status — a list of names and emails is not itself marketing consent. Opt-in is only touched when the column is explicitly present.</div>
    <h3>Segment rules</h3>
    <p>A smart list's rules panel lets you combine opted-in status with minimum spend, minimum events attended, and minimum tickets purchased. Click <strong>Refresh now</strong> any time to re-run the rules and sync membership — the refresh only ever adds or removes contacts it added itself via those rules, so a member you (or a CSV import) added by hand to the same list is never evicted by a refresh.</p>
    <h3>From the contact side</h3>
    <p>Open any contact's record and scroll to its <strong>Mailing Lists</strong> section to see every list they belong to, toggle their membership on individual lists, or add them to a new one — handy when you're already looking at one person rather than building a list from scratch.</p>
    <div class="tip"><strong>Tip:</strong> Removing someone from a list only affects that list. To stop <em>all</em> marketing email to a contact, turn off their <em>Opted in</em> flag on the <a href="#help-contacts">Contacts</a> page — campaigns never send to anyone who isn't opted in, regardless of list membership.</div>
    <div class="tip"><strong>Prefer a denser, all-in-one view?</strong> <a href="#help-listmaster">ListMaster</a> is a newer sidebar-of-lists page that manages these same lists with a member table, bulk actions, tags, and an audit trail, alongside this classic page.</div>
  `,

  listmaster: `
    <h2>ListMaster</h2>
    <p><strong>Messages &rarr; ListMaster</strong> is a denser, all-in-one alternative to the classic <a href="#help-msg-lists">Mailing Lists</a> page — same lists, same underlying contacts, a different layout built for working a big list quickly: a sidebar of every list, stat cards, a filterable member table with bulk actions, a contact detail slide-over, and tools for tags, segments, and import/export history. It doesn't replace the classic Lists page; use whichever layout you prefer, or both.</p>
    <figure class="help-shot">
      <img src="./assets/help/listmaster.png" alt="The ListMaster page: a sidebar of mailing lists and tools, stat cards for the selected list (Total Members, Active, Unsubscribed, Bounced), a filter bar, and a member table with name, email, status, tags, joined date, and list-membership columns" loading="lazy">
      <figcaption>ListMaster — a sidebar of every list on the left, the selected list's stats and member table on the right.</figcaption>
    </figure>

    <h3>Layout</h3>
    <ul>
      <li><strong>Sidebar</strong> — every list (static and smart), with a live member count, plus a <strong>Tools</strong> section for Import History, Export History, Tags, and Segments. A storage meter at the bottom shows contacts used against your venue's contact cap, with an <em>Edit limit</em> control.</li>
      <li><strong>Main pane</strong> — the selected list: stat cards (Total Members, Active, Unsubscribed, Bounced, Last Updated), a filter bar (status, tag, and a column-visibility picker for Tags / Joined / Lists columns), and the member table itself.</li>
      <li><strong>Detail slide-over</strong> — click any member row to open their record without leaving the list: <strong>Details</strong> (tags and a checklist of every list they belong to, toggle-able right there), <strong>Activity</strong> (a per-contact audit trail — joined/left a list, status changes, tags added/removed), and <strong>Notes</strong>.</li>
    </ul>

    <h3>Member status</h3>
    <p>Members are Active (subscribed), Unsubscribed, or <strong>Bounced</strong>. Bounced is set manually here — Backstage has no email-provider bounce-webhook feed, so if a delivery fails, mark the member Bounced yourself (individually from their detail panel, or in bulk from the member table).</p>

    <h3>Bulk actions</h3>
    <p>Check one or more rows in the member table (or "select all on this page") to reveal a bulk action bar:</p>
    <ul>
      <li><strong>Add Members</strong> — search and add contacts, or add everyone already opted in, in one click.</li>
      <li><strong>Remove from List</strong> — deletes the selected members' membership in the current list only.</li>
      <li><strong>Move</strong> — adds the selection to a different list and removes them from this one.</li>
      <li><strong>Assign Tags</strong> — apply an existing tag or create and assign a new one on the fly.</li>
      <li><strong>Mark Active / Unsubscribed / Bounced</strong> — bulk status change.</li>
    </ul>
    <p>Smart (segment) lists disable manual add/remove/tag actions here since their membership is computed from rules — a <strong>Refresh</strong> button re-runs those rules instead. Edit a segment's rules from the classic Lists page.</p>

    <h3>Tags</h3>
    <p>Free-form, color-coded labels you can put on any contact — open the <strong>Tags</strong> tool to create, rename their color, see how many contacts use each one, or delete one entirely (removing it from every contact). Filter the member table by tag, or assign tags in bulk from the member table.</p>

    <h3>Import / Export</h3>
    <p><strong>Import CSV</strong> works the same as the classic Lists page (an <code>email</code> column required, plus optional <code>first_name</code>, <code>last_name</code>, <code>phone</code>, <code>opted_in</code>) but only targets static lists — smart lists compute their own membership. <strong>Export List</strong> downloads the currently selected list (respecting any active search/status/tag filter) as a CSV. Every import and export run is logged with who ran it, when, and the row counts, browsable from the <strong>Import History</strong> / <strong>Export History</strong> tools in the sidebar.</p>

    <div class="tip"><strong>Tip:</strong> <kbd>&#8984;/Ctrl</kbd>+<kbd>K</kbd> jumps to the search box from anywhere on the page — it searches both the list sidebar and, once a list is open, its members.</div>
  `,

  statuses: `
    <h2>Event status reference</h2>
    <p>Events move through these statuses, roughly left to right on the pipeline. The vocabulary is shared with any connected Google Sheet so both tools stay in sync:</p>

    <h3>Public show statuses</h3>
    <ol>
      <li><strong>Empty</strong> — the date slot exists but nothing is confirmed for it yet.</li>
      <li><strong>Hold</strong> — a show idea or inquiry is live; the band/promoter deal is not yet confirmed. The date is informally held. Requires title, date, venue, door/end times, and a producer/artist contact.</li>
      <li><strong>Intake Complete</strong> — deal structure is agreed and a contract is being built. Age restriction, ticket price, capacity, and a deposit amount must be set. When this status is set, venue admins and management are automatically emailed with next steps for the contract.</li>
      <li><strong>Booked</strong> — a signed contract (or approved contract in the contract builder) plus a confirmed deposit. The show is locked.</li>
      <li><strong>Needs Assets</strong> — booked but blocked on flyer, artist photos, bio, or social content. An automatic email is sent to the producer/artist when this status is set.</li>
      <li><strong>Assets Approved</strong> — public description, ticket link, and an approved poster/flyer are all in. Andres and Colleen are notified to add the show to the website and newsletter; Molly gets a dedicated email with the full promo packet for the linktree and Instagram.</li>
      <li><strong>Ready To Announce</strong> — the approved flyer is in, ticketing is set up, and the event is ready to flip public.</li>
      <li><strong>Published</strong> — the public event page is live and the show is announced.</li>
      <li><strong>Advanced</strong> — production has been advanced with the bands; final logistics are locked for night of show.</li>
      <li><strong>Archived</strong> — the show happened; settlement has not yet been filed.</li>
      <li><strong>Settled</strong> — post-show settlement is filed and the books are closed.</li>
      <li><strong>Cancelled</strong> — the show was cancelled. Cancelled events are hidden from the calendar view but remain queryable for reporting.</li>
    </ol>

    <h3>Private event statuses</h3>
    <p>Private events (venue rentals) use a shorter path that skips the public-promotion stages:</p>
    <ol>
      <li><strong>Hold</strong> — inquiry received; client contact info is being collected.</li>
      <li><strong>Intake Complete</strong> — client details confirmed, AV/catering requirements noted, deposit amount set.</li>
      <li><strong>Booked</strong> — contract signed or approved, deposit confirmed.</li>
      <li><strong>Archived</strong> — event happened.</li>
      <li><strong>Settled</strong> — settlement filed.</li>
      <li><strong>Cancelled</strong> — rental cancelled.</li>
    </ol>
    <p>Private events are automatically blocked from reaching Needs Assets, Assets Approved, Ready To Announce, Published, and Advanced — those stages exist only for publicly promoted shows.</p>

    <h3>Notes</h3>
    <p>Status transitions are validated by the server — some forward moves require certain fields to be filled (e.g. you cannot advance to Booked without a contract). The pipeline and calendar display the current status for every event.</p>
  `,

  workflow: `
    <h2>End-to-end show workflow</h2>

    <h3>Public shows</h3>
    <p>A typical public show moves through these phases:</p>
    <ol>
      <li><strong>Program the night</strong> — pick a template (Templates page), set the date, and create the event. Status: <em>Empty</em>.</li>
      <li><strong>Lock in the deal</strong> — add the producer/artist contact and the booker contact. Status advances to <em>Hold</em>. The date is informally held while terms are confirmed.</li>
      <li><strong>Intake complete</strong> — set age restriction, ticket price, capacity, and deposit amount. Build the contract and walk it through approval. Status advances to <em>Intake Complete</em>.</li>
      <li><strong>Book it</strong> — once the contract is approved and a deposit is confirmed, status advances to <em>Booked</em>.</li>
      <li><strong>Collect assets</strong> — status advances to <em>Needs Assets</em>; an automatic email is sent to the producer/artist requesting the flyer, photos, bio, and social handles. Upload and approve the primary flyer.</li>
      <li><strong>Mark assets approved</strong> — once the public description, ticket link, and approved poster/flyer are all set, advance to <em>Assets Approved</em>. Andres/Colleen get the standard status email; Molly gets a dedicated linktree/Instagram packet.</li>
      <li><strong>Get ready to announce</strong> — flyer approved, ticket URL or in-house ticketing set up. Status becomes <em>Ready to Announce</em>.</li>
      <li><strong>Announce</strong> — flip the public page on. Status becomes <em>Published</em>.</li>
      <li><strong>Advance</strong> — close out open items, confirm hospitality, share the run sheet with bands. Status becomes <em>Advanced</em>.</li>
      <li><strong>Night of show</strong> — print the master event packet, use the guest list for door, check guests in.</li>
      <li><strong>Settle</strong> — file settlement next-day. Status becomes <em>Settled</em>.</li>
    </ol>

    <h3>Private events (venue rentals)</h3>
    <p>Private rentals skip all public-promotion stages. See <a href="#help-private-events">Private events &amp; rentals</a> for the dedicated workflow.</p>

    <h3>Automated archival</h3>
    <p>A nightly script automatically archives past events that are still in an active status (Hold through Advanced). If a show's date has passed and it has not been advanced to Settled, the script moves it to <em>Archived</em> and notifies the venue admins via email. Check the Activity log if an event's status changed unexpectedly.</p>
  `,

  faq: `
    <h2>FAQ</h2>
    <h3>Why can't I see settlement on this event?</h3>
    <p>Settlement is hidden from promoter, band/artist, designer, and viewer roles. Only venue admins and event owners see it.</p>
    <h3>Why is a tab missing on my event?</h3>
    <p>Tabs are filtered by your capabilities. For example, the <em>Invites</em> tab only appears if you can manage invites for the event.</p>
    <h3>Why didn't my collaborator get an email?</h3>
    <p>Backstage generates an invite URL but does not send the invite email itself. Copy the link and share it via your usual channel.</p>
    <h3>Someone's login-link email never arrived</h3>
    <p>Login links <em>are</em> sent by Backstage via the configured mail relay, but Gmail and other providers occasionally swallow them silently (especially for new addresses). If a user can't find their link in spam/promotions either, a venue admin can mint a fresh single-use link directly from the database and hand it over out-of-band. The link format is <code>/backstage/login.html?token=&lt;hex&gt;</code> and the token row goes into <code>magic_link_tokens</code>.</p>
    <h3>How do I move a show to a new date?</h3>
    <p>Open <a href="#help-details">Event details</a> and change the date. Calendar, dashboard, and pipeline all update.</p>
    <h3>How do I delete an event?</h3>
    <p>Events are not deleted in the MVP. Move them to <em>canceled</em> instead — they drop off the calendar and active dashboard cards but stay queryable for reporting.</p>
    <h3>Where are uploaded files stored?</h3>
    <p>Uploaded files are stored in the per-tenant client directory at <code>clients/{tenant-slug}/assets/events/{id}/</code>. They are served over HTTP via the <code>/files/</code> URL prefix, which routes through a PHP gateway that validates tenant ownership before streaming the file.</p>
  `,

  troubleshooting: `
    <h2>Troubleshooting</h2>
    <h3>"Session expired" or you keep getting bounced to login</h3>
    <p>Your access and refresh tokens both expired. Sign in again. If it happens often, your browser may be clearing local storage; check your privacy settings.</p>
    <h3>Passkey button does nothing</h3>
    <p>Your browser may not support WebAuthn, or you have no passkey registered for that hostname. Use password or email-link login and add a passkey from <em>Account</em>.</p>
    <h3>"This login link is invalid or has already been used"</h3>
    <p>Login links are single-use and expire after 24 hours. The most common cause of a "fresh" link appearing burned is a message previewer (iMessage, Slack, corporate URL scanners) silently visiting the link to render a preview — which used to consume the token. Backstage now shows a <em>Continue to your account</em> interstitial that only burns the token on a real click, so previewers should no longer be a problem; but if you've already followed an older-flow link, just request a new one from the login page.</p>
    <h3>Public page shows "Something went wrong"</h3>
    <p>Either the event is hidden (toggle <em>Publish Public Page</em> on) or the <code>id</code>/<code>slug</code> in the link doesn't match any event. The public page only returns data for events with public visibility enabled.</p>
    <h3>Upload failed</h3>
    <p>Check that the file is under the server's <code>upload_max_filesize</code> and is one of the accepted types (PNG, JPG, GIF, WEBP, PDF). The server enforces type by both extension and MIME via <code>finfo</code>.</p>
    <h3>Asset won't approve</h3>
    <p>Only promoters and admins can approve assets. Bands and designers can upload but not approve.</p>
  `,

  // ── Lead Pipeline ──────────────────────────────────────────────────────────

  leads: `
    <h2>Leads Inbox</h2>
    <p>The Leads Inbox is the first stop for new booking inquiries. It lives at the top of the sidebar under the <strong>Leads</strong> nav item and collects every inbound request before it becomes a confirmed event.</p>

    <h3>Status pipeline</h3>
    <p>Leads move through a defined status pipeline:</p>
    <ol>
      <li><strong>New</strong> — just arrived; not yet reviewed.</li>
      <li><strong>Triage</strong> — someone is looking at it and deciding whether to pursue.</li>
      <li><strong>Evaluating</strong> — the deal is being assessed (see <a href="#help-lead-evaluation">Deal evaluator</a>).</li>
      <li><strong>Needs Review</strong> — evaluation is complete but a decision-maker hasn't approved it yet.</li>
      <li><strong>Approved</strong> — approved to convert to an event.</li>
      <li><strong>Declined</strong> — the inquiry was declined.</li>
      <li><strong>Canceled</strong> — the lead was withdrawn or abandoned.</li>
      <li><strong>Converted</strong> — successfully converted to a real event.</li>
    </ol>
    <div class="warn"><strong>Important:</strong> A lead in <strong>Needs Review</strong> status gets a ⚠️ badge in the sidebar. These leads need attention before they stall — venue admins see a "Leads Needing Review" count on the dashboard.</div>

    <h3>Where leads come from</h3>
    <ul>
      <li><strong>Website form</strong> — submitted through the venue's booking inquiry form.</li>
      <li><strong>Promoter outreach</strong> — a promoter reached out directly.</li>
      <li><strong>Peerspace</strong> — inquiry originating from Peerspace.</li>
      <li><strong>Eventective</strong> — inquiry originating from Eventective.</li>
      <li><strong>Giggster</strong> — inquiry originating from Giggster.</li>
      <li><strong>Phone</strong> — someone called in.</li>
      <li><strong>Email</strong> — direct email inquiry.</li>
      <li><strong>Manual entry</strong> — created by staff directly in Backstage.</li>
    </ul>

    <h3>Creating a lead</h3>
    <ol>
      <li>Click <strong>New Lead</strong> in the Leads section.</li>
      <li>Fill in the title, event type, venue, contact info, source, and requested date.</li>
      <li>Save. The lead is created with status <em>New</em>.</li>
    </ol>

    <h3>Moving a lead through statuses</h3>
    <p>Open a lead to see its detail panel. The status buttons along the top of the detail panel advance or revert the lead's status. Click the appropriate button to move it forward — for example from <em>Triage</em> to <em>Evaluating</em> once you've begun the deal evaluation.</p>
    <div class="tip"><strong>Tip:</strong> Run the <a href="#help-lead-evaluation">Deal Evaluator</a> before moving a lead to <em>Approved</em>. The evaluation results are preserved for audit even after the lead is converted.</div>
  `,

  'lead-evaluation': `
    <h2>Deal Evaluator</h2>
    <p>The Deal Evaluator is in the right section of the Lead detail panel. It helps you model the financial feasibility of a booking before committing to it. All math is performed server-side — never trust numbers computed locally in the browser.</p>

    <h3>Input fields</h3>
    <ul>
      <li><strong>Room Capacity</strong> — the legal maximum occupancy of the space.</li>
      <li><strong>Expected Attendance</strong> — your projected headcount for this event.</li>
      <li><strong>Ticket Price</strong> — the price charged per ticket.</li>
      <li><strong>Ticket Fee / ticket</strong> — any per-ticket fee added on top (e.g. processing fee).</li>
      <li><strong>Rental Fee</strong> — flat room rental fee, if applicable.</li>
      <li><strong>Artist Guarantee</strong> — the minimum payout guaranteed to the artist regardless of ticket sales.</li>
      <li><strong>Projected Bar Spend</strong> — your estimate of total bar revenue for the night.</li>
      <li><strong>Bar Minimum</strong> — the minimum bar spend required by the contract. If the client's bar spend is below this, the minimum kicks in.</li>
      <li><strong>Labor Forecast</strong> — projected labor costs (staff, security, sound, etc.).</li>
      <li><strong>Production Costs</strong> — AV, staging, lighting, and related production expenses.</li>
      <li><strong>Facility Costs</strong> — cleaning, utilities, permit fees, and other facility overhead.</li>
      <li><strong>Other Costs</strong> — any additional costs not captured above.</li>
    </ul>

    <h3>Running the evaluation</h3>
    <p>Fill in the fields and click <strong>Calculate</strong>. The server computes all results and returns them immediately.</p>

    <h3>Results</h3>
    <ul>
      <li><strong>Gross Revenue</strong> — total of ticket sales + ticket fees + bar revenue + rental fee.</li>
      <li><strong>Estimated Cost</strong> — sum of all cost inputs.</li>
      <li><strong>Venue Net</strong> — Gross Revenue minus Estimated Cost.</li>
      <li><strong>Margin %</strong> — Venue Net as a percentage of Gross Revenue.</li>
      <li><strong>Break-even Tickets</strong> — how many tickets must sell to cover all fixed costs.</li>
      <li><strong>Minimum Tickets to Cover Guarantee</strong> — the ticket count at which the artist guarantee is covered by door receipts.</li>
    </ul>

    <h3>Risk flags</h3>
    <p>The evaluator automatically highlights financial risks. Each flag has a severity:</p>
    <ul>
      <li><span style="color:#dc2626;font-weight:600">&#x1F534; negative_margin</span> — costs exceed revenue at projected attendance. The show loses money as modeled.</li>
      <li><span style="color:#dc2626;font-weight:600">&#x1F534; venue_net_negative_with_guarantee</span> — the venue loses money even with the artist guarantee factored in.</li>
      <li><span style="color:#d97706;font-weight:600">&#x1F7E1; low_margin_under_15_pct</span> — a margin exists but is thin (under 15%). Proceed carefully.</li>
      <li><span style="color:#d97706;font-weight:600">&#x1F7E1; attendance_below_break_even</span> — projected attendance won't cover fixed costs.</li>
      <li><span style="color:#d97706;font-weight:600">&#x1F7E1; bar_spend_below_minimum</span> — the client's bar estimate is below the bar minimum; the minimum will kick in regardless.</li>
      <li><span style="color:#ea580c;font-weight:600">&#x1F7E0; projected_attendance_exceeds_capacity</span> — the headcount assumption exceeds the room's legal limit.</li>
    </ul>
    <div class="note"><strong>Note:</strong> Risk flags are advisory — they help you spot problems early but do not block approval. Document your reasoning in the lead notes if you proceed despite a flag.</div>
  `,

  'lead-convert': `
    <h2>Converting a Lead to an Event</h2>
    <p>When a lead has been approved (or is in <em>Evaluating</em> status), it can be promoted into a real event in Backstage.</p>

    <h3>How to convert</h3>
    <ol>
      <li>Open the lead in the Leads Inbox.</li>
      <li>Confirm the lead is in <strong>Approved</strong> or <strong>Evaluating</strong> status.</li>
      <li>Click <strong>Convert to Event</strong> in the detail panel.</li>
      <li>Backstage creates the event atomically and links it to the lead.</li>
      <li>You are taken directly to the new event workspace.</li>
    </ol>

    <h3>What happens to the lead</h3>
    <p>The lead is marked <strong>Converted</strong> and locked. Its evaluation results and all notes are preserved as a permanent audit record — you can always return to the lead to see the financial modeling that informed the booking decision.</p>

    <div class="note"><strong>Note:</strong> A converted lead cannot be edited or re-converted. If the event falls through, mark the lead <em>Canceled</em> separately after voiding or canceling the event.</div>
    <div class="tip"><strong>Tip:</strong> After conversion, open the new event and use the <a href="#help-event-wizard">Event Creation Wizard</a> or the <a href="#help-contracts">Contracts</a> tab to build the formal booking agreement.</div>
  `,

  // ── Vendors ─────────────────────────────────────────────────────────────────

  vendors: `
    <h2>Vendors &amp; COI Tracking</h2>
    <p>The Vendors panel tracks every external vendor hired for an event: caterers, photographers, security firms, AV companies, DJs, decorators, and any other outside party brought in for the show.</p>

    <h3>Vendor columns</h3>
    <ul>
      <li><strong>Vendor Name</strong> — the company or individual's name.</li>
      <li><strong>Service Category</strong> — what they're providing (Catering, Photography, Security, AV, DJ, Décor, etc.).</li>
      <li><strong>Contact</strong> — the vendor's contact name and/or email.</li>
      <li><strong>Quote</strong> — the original quoted amount.</li>
      <li><strong>Approved</strong> — the amount approved by the venue for billing purposes.</li>
      <li><strong>Actual</strong> — the final actual cost once the work is done.</li>
      <li><strong>COI Status</strong> — Certificate of Insurance status (see below).</li>
      <li><strong>Payment</strong> — payment status.</li>
      <li><strong>Confirmed</strong> — whether the vendor has confirmed they'll be on-site.</li>
    </ul>

    <h3>Adding and editing vendors</h3>
    <p>Click <strong>Add Vendor</strong> to open the vendor form. Fill in the name, category, contact info, and quote amount, then save. The new vendor appears in the table.</p>
    <p>To edit any vendor, click <strong>Edit</strong> on its row. You can update all fields inline, including the approved and actual amounts and all COI details.</p>

    <h3>COI tracking</h3>
    <p>Certificates of Insurance (COI) are often required before a vendor is allowed to work at your venue. The COI status values are:</p>
    <ul>
      <li><span style="color:#6b7280;font-weight:600">not_required</span> (Grey) — no COI is needed for this vendor.</li>
      <li><span style="color:#d97706;font-weight:600">pending</span> (Yellow) — COI has been requested but not yet received.</li>
      <li><span style="color:#16a34a;font-weight:600">received</span> (Green) — COI is on file; vendor is cleared to work.</li>
      <li><span style="color:#dc2626;font-weight:600">expired</span> (Red) — COI was received but is past its expiry date. The vendor cannot work until they provide a current certificate.</li>
    </ul>
    <div class="warn"><strong>Important:</strong> Never allow a vendor with an <em>expired</em> COI to work the event. Request an updated certificate and change the status to <em>received</em> once it's on file.</div>

    <h3>Payment status</h3>
    <p>Track vendor payments through their lifecycle:</p>
    <ul>
      <li><strong>Unpaid</strong> — no payment has been made yet.</li>
      <li><strong>Invoiced</strong> — an invoice has been received; payment is pending.</li>
      <li><strong>Paid</strong> — vendor has been paid in full.</li>
      <li><strong>Refunded</strong> — a refund was issued.</li>
    </ul>

    <h3>Confirmed badge</h3>
    <p>When a vendor confirms they'll be on-site for the event, mark them confirmed. The row displays a green <strong>✓ Confirmed</strong> badge so the event manager can see at a glance which vendors are locked in.</p>

    <h3>Totals row</h3>
    <p>The bottom of the Vendors panel shows a totals row summing the <strong>Quote</strong>, <strong>Approved</strong>, and <strong>Actual</strong> columns across all vendors.</p>

    <div class="tip"><strong>Tip:</strong> The Vendors panel feeds into Closeout Billing — actual amounts flow into vendor cost ledger entries automatically. Keep <em>Actual</em> amounts current so your closeout P&amp;L is accurate.</div>
  `,

  // ── Execution Records ────────────────────────────────────────────────────────

  execution: `
    <h2>Live Execution Records</h2>
    <p>The Execution tab captures real-time records during the event: incidents, change orders, bar notes, property damage, overages, and other notable occurrences. These records create a factual, timestamped account of what actually happened night-of-show.</p>

    <h3>Record types</h3>
    <ul>
      <li><span style="color:#dc2626;font-weight:600">&#x1F534; Incident</span> — something went wrong (fight, medical emergency, property damage). <strong>Restricted:</strong> only staff with the <code>view_incidents</code> or <code>manage_incidents</code> capability can see incident records.</li>
      <li><span style="color:#2563eb;font-weight:600">&#x1F535; Change Order</span> — a last-minute change to the agreed scope (extra set, different equipment, additional service). If a dollar amount is attached, a ledger entry is auto-created in Closeout.</li>
      <li><span style="color:#16a34a;font-weight:600">&#x1F7E2; Bar Note</span> — observations about bar performance: early or late close, slow service, high-volume periods, staffing notes.</li>
      <li><span style="color:#ea580c;font-weight:600">&#x1F7E0; Damage</span> — property damage observed during or after the event. The amount field captures a repair estimate; a ledger cost entry is auto-created.</li>
      <li><span style="color:#7c3aed;font-weight:600">&#x1F7E3; Overage</span> — costs that ran over the agreed amount. Auto-creates a ledger cost entry in Closeout.</li>
      <li><strong>Other types</strong> — Checklist note, Deviation from plan, Safety note, General note.</li>
    </ul>

    <h3>Adding a record</h3>
    <ol>
      <li>Click <strong>+ Add Record</strong>.</li>
      <li>Pick the record type from the dropdown.</li>
      <li>Enter a summary line (required) and optional detail body.</li>
      <li>Enter an optional financial impact amount if applicable.</li>
      <li>For sensitive records, check <strong>Restrict to incident managers</strong> to limit visibility.</li>
      <li>Save. The record is timestamped automatically.</li>
    </ol>

    <h3>Filter tabs</h3>
    <p>Use the filter tabs to focus on one record type at a time: <strong>All</strong> | <strong>Change Orders</strong> | <strong>Bar Notes</strong> | <strong>Damage</strong> | <strong>Incidents</strong> | <strong>Other</strong>. Filtering is client-side — all records are loaded; the tabs just control what's shown.</p>

    <div class="note"><strong>Note:</strong> Records become read-only after the event is settled. Keep entries factual and timestamped — they may be referenced in post-event disputes, insurance claims, or audit reviews.</div>
    <div class="tip"><strong>Tip:</strong> Change orders and damage records that carry a dollar amount automatically create matching entries in the Closeout ledger, saving a manual step during financial reconciliation.</div>
  `,

  // ── Deposit Gate ─────────────────────────────────────────────────────────────

  'deposit-gate': `
    <h2>Deposit Gate &amp; Payments</h2>
    <p>An event cannot advance to <strong>Booked</strong> status without two things in place: a fully executed contract and a deposit in an accepted state. This two-key gate prevents events from being marked booked before the financial commitment is secured.</p>

    <h3>The two requirements</h3>
    <ul>
      <li><strong>Contract</strong> — must be in <em>signed</em> or <em>fully_executed</em> status. A contract that is only <em>sent</em> or <em>approved</em> does not satisfy the gate.</li>
      <li><strong>Deposit</strong> — must be in <em>received</em>, <em>waived</em>, or <em>not_required</em> state. A deposit that is requested but not yet received will block the transition.</li>
    </ul>

    <h3>The Payments tab</h3>
    <p>The <a href="#help-payments">Payments tab</a> (inside the event workspace) lists every deposit, balance payment, refund, credit, and adjustment on file for the event, and can generate a Stripe invoice link for an outstanding one. See that page for the full rundown of what it shows and how "Send Invoice Link" works.</p>
    <p>When a deposit payment is marked <strong>received</strong>, the event's <code>deposit_status</code> updates automatically and the gate check is re-evaluated.</p>

    <h3>Waiving a deposit</h3>
    <p>Some events don't need a deposit at all — a longtime promoter, a house-produced show, a venue-discretion booking. Backstage supports a <code>waived</code> deposit state that satisfies the gate the same way a received deposit does. On the <a href="#help-payments">Payments tab</a>, click <strong>Waive Deposit</strong> in the deposit summary bar (only shown if the deposit is still outstanding and you have the <code>waive_deposit</code> capability, venue admin by default) and enter a reason — it's mandatory and permanently audited alongside who waived it and when.</p>

    <h3>Not required</h3>
    <p>If an event has no deposit amount set, the deposit status defaults to <code>not_required</code> and the gate passes automatically. This ensures backward compatibility with events created before the deposit gate was introduced.</p>

    <h3>Error messages</h3>
    <p>When an event is blocked from advancing to Booked, you'll see a specific error message explaining why:</p>
    <ul>
      <li><em>"Deposit requested but not yet received"</em> — a deposit amount is set but no received payment exists.</li>
      <li><em>"Deposit partially received"</em> — the received amount is less than the required deposit.</li>
      <li><em>"Deposit waived"</em> — the deposit was waived; this is informational, not an error.</li>
    </ul>
    <div class="tip"><strong>Tip:</strong> Use the <a href="#help-contracts">Contracts</a> tab's e-signature workflow to get the contract to <em>fully_executed</em> status. Both gates (contract + deposit) must pass before Booked is available.</div>
  `,

  payments: `
    <h2>The Payments tab</h2>
    <p>The <strong>Payments</strong> tab in the event workspace (visible with the <code>manage_payments</code> capability) is the money-movement record for a single event — deposits, balance payments, refunds, credits, and adjustments. It works alongside the <a href="#help-deposit-gate">deposit gate</a>: a deposit payment marked <em>received</em> here is what actually clears that gate.</p>

    <h3>What the table shows</h3>
    <p>Each row is one payment record: <strong>Type</strong> (Deposit, Balance Payment, Refund, Credit, Adjustment, Promoter Payment, Client Payment, or Other), <strong>Amount</strong> and currency, <strong>Status</strong>, <strong>Method</strong> (check, wire, ACH, cash, card, Stripe, Square, Venmo, Zelle, other), and <strong>Due date</strong>.</p>

    <h3>Adding a payment</h3>
    <p>Click <strong>+ Add Payment</strong> to log one — a check or cash deposit handed over in person, a wire that landed, a refund you issued, anything. Set the type and direction (money in vs. money out), the amount, a status (defaults to <em>Received</em>, since the most common case is logging money already in hand — switch it to <em>Pending</em> if you're recording something still owed), and optionally a method, due date, and notes. If the type is <strong>Deposit</strong> and status is <strong>Received</strong>, the event's deposit status updates immediately.</p>

    <h3>Status meanings</h3>
    <ul>
      <li><strong>Pending</strong> — expected but not yet paid.</li>
      <li><strong>Invoiced</strong> — a Stripe payment link has been sent for it.</li>
      <li><strong>Received</strong> — paid. For a deposit-type record, this is what advances <code>deposit_status</code>.</li>
      <li><strong>Failed</strong> — a payment attempt didn't go through.</li>
      <li><strong>Refunded</strong> — closed out, but stays in the list.</li>
    </ul>

    <h3>Editing and voiding</h3>
    <p>Click <strong>Edit</strong> on any row to change its amount, status, method, due date, or notes (the type and direction are fixed once a record is created — void it and add a corrected one instead if those need to change). Click <strong>Void</strong> to retire a mistake entirely: the record drops off this list right away — so it stops counting toward totals and toward the deposit gate — but it isn't deleted, just excluded from view; the underlying row (and who voided it, and when) is preserved for the audit trail.</p>

    <h3>Deposit summary bar</h3>
    <p>When an event has a deposit amount set, a summary block above the table shows <strong>Deposit Required</strong>, <strong>Deposit Received</strong>, and <strong>Deposit Outstanding</strong> (highlighted when money is still owed) — a quick read on where the deposit stands without adding up rows yourself. If the deposit is still outstanding and you have the <code>waive_deposit</code> capability, a <strong>Waive Deposit</strong> button appears in this bar — see <a href="#help-deposit-gate">the deposit gate</a> for when and why you'd use it.</p>

    <h3>Send Invoice Link</h3>
    <p>For any <em>pending</em> or <em>invoiced</em> payment, click <strong>Send Invoice Link</strong> to generate a one-time Stripe Payment Link for that exact amount. Backstage creates the link, copies it to your clipboard, and marks the record <em>invoiced</em> — from there you paste it into an email, text, or however you reach the payer. This requires <code>STRIPE_SECRET_KEY</code> to be configured for the venue (see <a href="#help-admin-payments">Payment providers</a>); if it isn't, the button will show an error explaining Stripe isn't set up.</p>
  `,

  // ── Closeout &amp; Billing ───────────────────────────────────────────────────

  closeout: `
    <h2>Closeout Overview</h2>
    <p>After a show ends, the Closeout panel (a tab in the event workspace) replaces the old flat Settlement form with a full financial ledger workflow. It gives you a structured, auditable way to reconcile every dollar that came in and went out.</p>

    <h3>Layout</h3>
    <p>The Closeout panel has a two-column layout:</p>
    <ul>
      <li><strong>Left panel</strong> — the financial ledger with all line items (revenue, costs, payments).</li>
      <li><strong>Right panel</strong> — the P&amp;L summary (Gross Revenue, Total Costs, Venue Net, Margin %) and the closeout checklist.</li>
    </ul>

    <h3>Event types</h3>
    <p>Public events and private venue rentals have different revenue categories suited to their billing structure:</p>
    <ul>
      <li><strong>Public events</strong> — Tickets, Ticket Fees, Bar Sales, Merch Share, Sponsorship, and more.</li>
      <li><strong>Private events (rentals)</strong> — Rental Fee, Hosted Bar, Equipment Rental, Overtime Charge, and other rental-specific categories.</li>
    </ul>

    <h3>Why the ledger workflow</h3>
    <p>The closeout workflow prevents an event from being marked <em>Settled</em> until all financial loose ends are tied off. A 7-point checklist gates the Finalize button, ensuring nothing is missed. See <a href="#help-closeout-finalize">Finalizing closeout</a> for the checklist details.</p>

    <div class="tip"><strong>Tip:</strong> Vendor actual amounts (from the <a href="#help-vendors">Vendors panel</a>) and execution records with financial impact (from the <a href="#help-execution">Execution tab</a>) automatically generate ledger entries, reducing manual data entry during closeout.</div>
  `,

  'closeout-ledger': `
    <h2>The Financial Ledger</h2>
    <p>The ledger is the core of the Closeout panel. It records every financial transaction for the event in a structured, auditable format.</p>

    <div class="warn"><strong>Important:</strong> The ledger is <strong>append-only</strong>. You add entries; you do not edit amounts. To correct a mistake, void the incorrect entry and add a corrected one. This preserves the complete financial history.</div>

    <h3>Entry types and categories</h3>
    <p><strong>Revenue categories:</strong></p>
    <ul>
      <li>Tickets, Ticket Fees, Bar Sales, Rental Fee, Hosted Bar, Merch Share, Sponsorship, Equipment Rental, Overtime Charge, Other Revenue</li>
    </ul>
    <p><strong>Cost categories:</strong></p>
    <ul>
      <li>Artist Guarantee, Promoter Settlement, Labor, Sound/Production, Security, Cleaning, Rentals, Catering, Vendor Cost, Processing Fees, Taxes, Refunds, Other Cost</li>
    </ul>
    <p><strong>Payment categories:</strong></p>
    <ul>
      <li>Deposit Received, Invoice Payment, Credit, Outstanding Balance, Artist Payout, Promoter Payout, Vendor Payout, Staff Payout, Adjustment</li>
    </ul>
    <div class="tip"><strong>Note:</strong> These are ledger line-item categories — manual entries in the Closeout P&amp;L — separate from the structured payment records (Deposit, Balance Payment, Refund, Credit, and more) tracked on the event's <a href="#help-payments">Payments tab</a>. The two aren't automatically reconciled with each other.</div>

    <h3>Adding a ledger entry</h3>
    <ol>
      <li>Pick the <strong>line type</strong>: Revenue, Cost, or Payment.</li>
      <li>Pick the <strong>category</strong> from the dropdown (filtered by type).</li>
      <li>Enter the <strong>amount</strong> and a <strong>description</strong>.</li>
      <li>Click <strong>Save</strong>. The entry is added immediately.</li>
    </ol>

    <h3>Voiding an entry</h3>
    <p>Click the <strong>Void</strong> button next to any entry. You'll be prompted to enter a reason for the void. Voided entries remain visible in the ledger with strikethrough text — there is no delete. The complete record is preserved for audit.</p>

    <h3>The P&amp;L Summary</h3>
    <p>The right panel shows a live P&amp;L summary: <strong>Gross Revenue</strong>, <strong>Total Costs</strong>, <strong>Venue Net</strong>, and <strong>Margin %</strong>. These figures are always calculated server-side — the app never trusts a submitted total.</p>
    <p>Click <strong>Refresh</strong> to recalculate after adding entries. The summary updates to reflect all non-voided entries.</p>

    <div class="note"><strong>Note:</strong> The P&amp;L is a running total, not a snapshot. It reflects the current state of all active (non-voided) ledger entries at any given time.</div>
  `,

  'closeout-finalize': `
    <h2>Finalizing Closeout</h2>
    <p>Before an event can be marked <strong>Settled</strong>, all items on the 7-point Closeout Checklist must be checked off. This gate ensures no financial or operational loose ends are left open.</p>

    <h3>The 7-point checklist</h3>
    <ol>
      <li><strong>Contract Signed</strong> — a fully executed contract is on file.</li>
      <li><strong>Deposit Received</strong> — the deposit was received, waived, or marked not required.</li>
      <li><strong>Vendors Confirmed</strong> — all vendors are confirmed for the event.</li>
      <li><strong>Staffing Confirmed</strong> — all staff shifts are confirmed.</li>
      <li><strong>Bar Closed</strong> — bar has been officially closed and the count reconciled.</li>
      <li><strong>Cash Reconciled</strong> — cash float and door take are balanced.</li>
      <li><strong>All Invoices Collected</strong> — all vendor invoices are received and entered in the ledger.</li>
    </ol>
    <p>Each checkbox immediately PATCHes the server when clicked — there's no separate save button for the checklist.</p>

    <h3>Finalizing</h3>
    <p>Once all 7 boxes are checked, the <strong>Finalize</strong> button becomes active. Click it to:</p>
    <ul>
      <li>Set the event status to <strong>settled</strong>.</li>
      <li>Lock the ledger — no new entries can be added and existing entries cannot be voided.</li>
    </ul>

    <h3>Reopening a settled event</h3>
    <p>If something was missed after finalization, click <strong>Reopen</strong> and enter a reason. The ledger unlocks and the event reverts to an active state.</p>
    <div class="warn"><strong>Important:</strong> Reopening requires the <code>finalize_closeout</code> capability (venue admin by default). The reason and timestamp of every reopen are stored permanently — reopening is fully audited.</div>

    <div class="tip"><strong>Tip:</strong> Work through the ledger entries and checklist in parallel as the night wraps up. The sooner costs and revenues are entered, the more accurate your P&amp;L will be when you hit Finalize.</div>
  `,

  // ── Panic Promote — user guide ──────────────────────────────────────────────

  'promote-overview': `
    <h2>What is Panic Promote?</h2>
    <p>Panic Promote is the built-in marketing engine for your venue. Once you have an event in Backstage, Promote helps you write, approve, and push announcements to every platform you use — Facebook, Instagram, Eventbrite, Luma, Foopee, Funcheap, your email list, and more — without leaving the app.</p>
    <h3>The core workflow</h3>
    <ol>
      <li><strong>Open the event</strong> in the event workspace and click the <em>Promote</em> button in the top-right action bar.</li>
      <li><strong>Create a campaign</strong> — one campaign per event. Backstage creates it the first time you click Promote.</li>
      <li><strong>Write a post</strong> — a title, a master description, and an optional ticket URL. Backstage generates channel-specific copy for all 9 channels from that one description.</li>
      <li><strong>Approve the variants</strong> you're happy with. Only Approved variants can be broadcast.</li>
      <li><strong>Broadcast</strong> — pick destinations, choose Send now or Schedule, and fire.</li>
      <li>Watch the <strong>campaign health score</strong> in the right rail tick upward as each checklist item is completed.</li>
    </ol>
    <h3>Campaigns vs posts vs broadcasts</h3>
    <ul>
      <li>A <strong>campaign</strong> is the container for one event's entire marketing effort — goal, posts, assets, and broadcast history.</li>
      <li>A <strong>post</strong> is a piece of source content (title + description + link) that spawns up to 9 channel-specific variants.</li>
      <li>A <strong>broadcast</strong> is a single send action: one post sent to one or more destinations at a point in time.</li>
    </ul>
  `,

  'promote-campaigns': `
    <h2>Campaigns</h2>
    <p>Every promoted event has exactly one campaign, created the first time you click <em>Promote</em> from the event workspace.</p>
    <h3>Opening a campaign</h3>
    <p>From any event, click the pink <em>Promote</em> button in the event action bar. If no campaign exists yet, Backstage creates one automatically. You can also browse all campaigns via the <strong>Promote</strong> nav item — it shows a card grid of upcoming events sorted by date with a health badge on each.</p>
    <h3>Campaign overview layout</h3>
    <ul>
      <li><strong>Main column</strong> — event hero (flyer, date, venue), metric tiles, and the posts list.</li>
      <li><strong>Rail column</strong> — health checklist, assets card, and analytics.</li>
    </ul>
    <h3>Goal tickets</h3>
    <p>Set a numeric ticket-sales goal in the campaign header. This populates the health score and gives the analytics tile a target to track against.</p>
  `,

  'promote-posts': `
    <h2>Posts &amp; copy generation</h2>
    <p>A post is the source material for all your channel variants. Write it once, generate nine versions automatically.</p>
    <h3>Creating a post</h3>
    <ol>
      <li>In the campaign overview click <em>+ New Post</em>.</li>
      <li>Enter a <strong>Title</strong> (internal label), <strong>Master description</strong> (the core blurb), and an optional <strong>Target URL</strong> (ticket link).</li>
      <li>Click <em>Generate variants</em> — Backstage creates channel-specific copy for all 9 channels.</li>
    </ol>
    <h3>The 9 channels</h3>
    <ul>
      <li><strong>Instagram</strong> — up to 2,200 chars; links not clickable; ends with hashtags. Direct followers to link in bio.</li>
      <li><strong>Facebook</strong> — key info above the ~477-char "See more" fold.</li>
      <li><strong>TikTok</strong> — punchy caption under 150 chars; pair with vertical video.</li>
      <li><strong>Email</strong> — subject line + full body; personalise the greeting before sending.</li>
      <li><strong>Eventbrite</strong> — structured listing: title, description, venue, age restriction, ticket link.</li>
      <li><strong>Luma</strong> — concise listing format.</li>
      <li><strong>Funcheap</strong> — under 500 chars; paste into their web form.</li>
      <li><strong>Foopee</strong> — Bay Area calendar listing; paste into their web form.</li>
      <li><strong>Press</strong> — "FOR IMMEDIATE RELEASE" format with contact placeholder; attach a hi-res flyer before sending.</li>
    </ul>
    <h3>Approving variants</h3>
    <p>Click a variant tab in the post editor, read and edit the copy, then set the status to <strong>Approved</strong>. Regenerating overwrites Draft variants but leaves Approved ones untouched.</p>
    <h3>Post statuses</h3>
    <ul>
      <li><strong>Draft</strong> — in progress.</li>
      <li><strong>Approved</strong> — signed off; eligible for broadcast.</li>
      <li><strong>Scheduled / Sent / Archived</strong> — lifecycle states after broadcasting.</li>
    </ul>
  `,

  'promote-broadcasting': `
    <h2>Broadcasting to platforms</h2>
    <p>A broadcast takes an approved post and sends it to one or more destinations in a single action.</p>
    <h3>Opening the broadcast modal</h3>
    <p>From a post card click <em>Broadcast</em>. The modal groups destinations into four sections:</p>
    <ul>
      <li><strong>Direct Posts</strong> — Facebook Page, Instagram, TikTok (require OAuth credentials in Settings → Promote).</li>
      <li><strong>Event Platforms</strong> — Eventbrite, Luma, Bandsintown.</li>
      <li><strong>Editorial Submissions</strong> — Funcheap, Foopee, Press List (manual — Backstage prepares copy; you paste it into the site).</li>
      <li><strong>Email</strong> — General Email List, Press Email List (require an ESP connected in Settings → Promote).</li>
    </ul>
    <h3>Destination status badges</h3>
    <ul>
      <li><span style="color:#0c7a3c;font-weight:600">Connected</span> — posts automatically.</li>
      <li><span style="color:#d97706;font-weight:600">Needs auth</span> — go to Settings → Promote to connect.</li>
      <li><span style="color:#1466bd;font-weight:600">Manual</span> — no API; Backstage prepares the copy and you submit the form.</li>
    </ul>
    <h3>Result statuses</h3>
    <ul>
      <li><strong>Sent</strong> — posted; click the external link to view it live.</li>
      <li><strong>Queued</strong> — scheduled for later.</li>
      <li><strong>Manual required</strong> — copy is ready; submit at the platform's form.</li>
      <li><strong>Needs auth</strong> — no credentials; visit Settings → Promote.</li>
      <li><strong>Failed</strong> — API error; error message shown inline.</li>
    </ul>
  `,

  'promote-manual': `
    <h2>Manual submission destinations</h2>
    <p>When no public write API exists, Backstage prepares copy and records the broadcast — you paste the text into the platform's web form.</p>
    <h3>How it works</h3>
    <ol>
      <li>Select the manual destination(s) in the broadcast modal and click <em>Send</em>.</li>
      <li>Backstage marks the result <em>Manual required</em> and saves it to broadcast history.</li>
      <li>Use the <em>Copy</em> button on the variant tab to grab the formatted copy.</li>
      <li>Open the submission link below and paste.</li>
    </ol>
    <h3>Submission links</h3>
    <ul>
      <li><strong>Foopee</strong> — <a href="https://foopee.com" target="_blank" rel="noreferrer">foopee.com</a></li>
      <li><strong>Funcheap SF</strong> — <a href="https://funcheap.com/submit-event" target="_blank" rel="noreferrer">funcheap.com/submit-event</a></li>
      <li><strong>Bandsintown</strong> — <a href="https://manager.bandsintown.com" target="_blank" rel="noreferrer">manager.bandsintown.com</a></li>
      <li><strong>SF Chronicle / Datebook</strong> — <a href="https://datebook.sfchronicle.com" target="_blank" rel="noreferrer">datebook.sfchronicle.com</a></li>
      <li><strong>SongKick</strong> — <a href="https://tourbox.songkick.com" target="_blank" rel="noreferrer">tourbox.songkick.com</a></li>
      <li><strong>JamBase</strong> — <a href="https://www.jambase.com/submit" target="_blank" rel="noreferrer">jambase.com/submit</a></li>
      <li><strong>SF Station</strong> — <a href="https://www.sfstation.com/submit-event" target="_blank" rel="noreferrer">sfstation.com/submit-event</a></li>
      <li><strong>DoTheBay</strong> — <a href="https://dothebay.com/submit" target="_blank" rel="noreferrer">dothebay.com/submit</a></li>
    </ul>
    <h3>Tips</h3>
    <ul>
      <li>Submit 2–4 weeks before the show date for best placement on editorial calendars.</li>
      <li>The Press variant includes a "FOR IMMEDIATE RELEASE" header — personalise it for each outlet and attach a hi-res flyer.</li>
    </ul>
  `,

  'promote-health': `
    <h2>Campaign health checklist</h2>
    <p>The health card scores a campaign from 0–100% across 12 items. It's a quick at-a-glance view of what's done and what still needs attention before the show.</p>
    <h3>The 12 items</h3>
    <ul>
      <li><strong>Panic event page published</strong> — Public visibility is on (event Details tab).</li>
      <li><strong>Approved flyer</strong> — an asset of type Flyer with status Approved in the event Assets section.</li>
      <li><strong>Instagram post approved</strong> — an Instagram variant marked Approved.</li>
      <li><strong>Facebook post approved</strong> — a Facebook variant marked Approved.</li>
      <li><strong>Eventbrite listing prepared</strong> — any broadcast to Eventbrite created.</li>
      <li><strong>Luma listing prepared</strong> — any broadcast to Luma created.</li>
      <li><strong>Funcheap submitted</strong> — any broadcast to Funcheap created.</li>
      <li><strong>Foopee submitted</strong> — any broadcast to Foopee created.</li>
      <li><strong>Press email prepared</strong> — a Press variant marked Approved.</li>
      <li><strong>Email blast scheduled</strong> — a broadcast to the General Email List created.</li>
      <li><strong>At least one post</strong> — any post exists in the campaign.</li>
      <li><strong>Ticket goal set</strong> — a numeric goal saved on the campaign.</li>
    </ul>
    <h3>Severity colours</h3>
    <ul>
      <li><span style="color:#16a34a;font-weight:600">Green</span> — done.</li>
      <li><span style="color:#d97706;font-weight:600">Amber</span> — important but not blocking.</li>
      <li><span style="color:#9ca3af;font-weight:600">Grey</span> — nice to have.</li>
      <li><span style="color:#dc2626;font-weight:600">Red</span> — blocking (e.g. event page not published).</li>
    </ul>
  `,

  // ── Promote Administration ──────────────────────────────────────────────────

  'promote-setup': `
    <h2>Promote setup &amp; credentials overview</h2>
    <p>Platform credentials are stored in the <code>promote_credentials</code> database table, scoped per venue. Manage them at <strong>Settings → Promote</strong> in the sidebar.</p>
    <h3>Destination types</h3>
    <ul>
      <li><strong>Connected (API)</strong> — credentials saved; Backstage posts automatically.</li>
      <li><strong>Manual submission</strong> — no write API; Backstage prepares copy and records the broadcast, you submit the form.</li>
    </ul>
    <h3>What is stored</h3>
    <ul>
      <li><code>access_token</code> — primary secret (API key or OAuth token). Never returned by the API after saving.</li>
      <li><code>refresh_token</code> — for OAuth flows that support token refresh.</li>
      <li><code>config</code> — JSON for platform-specific IDs (org ID, page ID, list ID, etc.).</li>
    </ul>
    <h3>Credential lookup order</h3>
    <p>When dispatching a broadcast, Backstage first checks <code>promote_credentials</code> for a <em>connected</em> row matching the venue + destination key. If nothing is found it falls back to <code>.env</code> variables — useful for initial setup before using the Settings UI.</p>
    <h3>Adding a new adapter (for developers)</h3>
    <ol>
      <li>Create <code>src/Promote/Adapters/FooAdapter.php</code> with a <code>dispatch(array $event, array $post, string $sendMode): array</code> method.</li>
      <li>Add a <code>match</code> case in <code>BroadcastAdapters::dispatch()</code> calling your new adapter.</li>
      <li>Add field definitions to <code>PLATFORM_FIELDS</code> in <code>promote.js</code> — the Settings UI picks them up automatically.</li>
    </ol>
  `,

  'promote-eventbrite': `
    <h2>Connecting Eventbrite</h2>
    <p>When connected, broadcasting to Eventbrite automatically creates and publishes a live Eventbrite event with title, description, start/end times, doors time, age restriction, and a ticket class pointing to your ticket URL.</p>
    <h3>Step 1 — Create an Organizer on eventbrite.com</h3>
    <ol>
      <li>Log in to <a href="https://www.eventbrite.com" target="_blank" rel="noreferrer">eventbrite.com</a> with the account whose API key you have.</li>
      <li>Click <em>Create event</em> — Eventbrite prompts you to create an Organizer profile on the first use. Name it <strong>your venue name</strong>.</li>
    </ol>
    <h3>Step 2 — Save credentials in Backstage</h3>
    <ol>
      <li>Go to <strong>Settings → Promote</strong>.</li>
      <li>In the <strong>Eventbrite</strong> card, enter the API Key (private token from <a href="https://www.eventbrite.com/account-settings/apps" target="_blank" rel="noreferrer">eventbrite.com/account-settings/apps</a>).</li>
      <li>Click <em>Fetch Org ID</em> — Backstage calls the API and auto-fills the Organizer ID.</li>
      <li>Optionally enter a pre-created Eventbrite Venue ID for your venue.</li>
      <li>Click <em>Save</em>.</li>
    </ol>
    <h3>Troubleshooting</h3>
    <ul>
      <li><em>"EVENTBRITE_ORG_ID not configured"</em> — complete step 1 and re-fetch.</li>
      <li><em>"NOT_AUTHORIZED"</em> — the account has no Organizer; follow step 1.</li>
      <li><em>"Event is missing a date"</em> — set a date in the event Details tab.</li>
    </ul>
  `,

  'promote-facebook': `
    <h2>Connecting Facebook &amp; Instagram</h2>
    <p>Both platforms use the same Facebook Developer App. Facebook posts to your Page; Instagram publishes image + caption to your Business account.</p>
    <h3>Requirements</h3>
    <ul>
      <li>A <strong>Facebook Page</strong> for your venue (not a personal profile).</li>
      <li>An <strong>Instagram Business or Creator account</strong> linked to that Page.</li>
      <li>A <strong>Facebook Developer App</strong> at <a href="https://developers.facebook.com" target="_blank" rel="noreferrer">developers.facebook.com</a>.</li>
    </ul>
    <h3>Required App permissions</h3>
    <p><code>pages_manage_posts</code>, <code>pages_read_engagement</code>, <code>instagram_basic</code>, <code>instagram_content_publish</code></p>
    <h3>Getting a Page Access Token</h3>
    <ol>
      <li>In the Developer App → <em>Tools → Graph API Explorer</em>, generate an access token with the permissions above.</li>
      <li>Exchange for a long-lived token: <code>GET /oauth/access_token?grant_type=fb_exchange_token&amp;…</code></li>
      <li>Fetch the Page token: <code>GET /me/accounts?access_token=&lt;long-lived&gt;</code> — copy the token for your Page.</li>
    </ol>
    <h3>Saving in Backstage</h3>
    <p>Go to <strong>Settings → Promote</strong>. In the Facebook card paste the Page Access Token and Page ID. In the Instagram card paste the same User Access Token and your Instagram Business Account ID (found via <code>GET /{page-id}?fields=instagram_business_account</code>). Save each card separately.</p>
    <h3>Instagram image requirement</h3>
    <p>Instagram requires images at a public HTTPS URL at post time. Backstage will use the approved flyer from the event's Assets section — make sure the flyer is uploaded and marked Approved before broadcasting to Instagram.</p>
  `,

  'promote-luma': `
    <h2>Connecting Luma</h2>
    <h3>Getting an API key</h3>
    <ol>
      <li>Log in to <a href="https://lu.ma" target="_blank" rel="noreferrer">lu.ma</a>.</li>
      <li>Go to <em>Dashboard → Settings → API</em> and generate a key.</li>
    </ol>
    <h3>Saving in Backstage</h3>
    <ol>
      <li>Go to <strong>Settings → Promote</strong>, find the <strong>Luma</strong> card.</li>
      <li>Paste the API key and click <em>Save</em>.</li>
    </ol>
    <p class="muted small">The Luma adapter is on the roadmap. The key is saved and ready; until the adapter ships, use the Luma copy variant and submit manually at <a href="https://lu.ma/create" target="_blank" rel="noreferrer">lu.ma/create</a>.</p>
  `,

  'promote-email-cfg': `
    <h2>Connecting email lists</h2>
    <p>Backstage supports <strong>Mailchimp</strong> and <strong>SendGrid</strong> for the General and Press email lists.</p>
    <h3>Mailchimp</h3>
    <ol>
      <li><em>Account → Extras → API keys</em> — create a key.</li>
      <li><em>Audience → Manage Audience → Settings</em> — note the Audience ID.</li>
      <li>In Backstage <strong>Settings → Promote</strong> → email card: Provider = <code>mailchimp</code>, paste the API key, Audience ID, and From Name. Save.</li>
    </ol>
    <h3>SendGrid</h3>
    <ol>
      <li><em>Settings → API Keys</em> — create a key with Mail Send access.</li>
      <li><em>Marketing → Contacts → Lists</em> — note the List ID.</li>
      <li>In Backstage: Provider = <code>sendgrid</code>, paste API key, List ID, and From Name. Save.</li>
    </ol>
    <p class="muted small">The email adapter is on the roadmap. Credentials are saved and ready; until it ships, export the Email variant copy from the post editor and paste it into your ESP manually.</p>
  `,

  'promote-manual-cfg': `
    <h2>Manual destinations &amp; copy</h2>
    <p>Destinations with no public write API are marked <em>manual_submission</em> in the database. Backstage prepares copy and records the broadcast; you submit the web form.</p>
    <h3>Adding a new manual destination</h3>
    <ol>
      <li>Insert a row:<br>
        <code>INSERT INTO promote_destinations (destination_key, destination_group, label, status)<br>
        VALUES ('sf_chronicle', 'editorial_submission', 'SF Chronicle', 'manual_submission');</code></li>
      <li>Add a copy variant to <code>CopyGenerator.php</code> — add the key to <code>CHANNELS</code> and a <code>match</code> case returning <code>title</code>, <code>body</code>, and <code>warnings</code>.</li>
      <li>Optionally add a health-check item in <code>PromotionHealth.php</code>.</li>
      <li>Optionally add config field definitions to <code>PLATFORM_FIELDS</code> in <code>promote.js</code>.</li>
    </ol>
    <h3>Upgrading a manual destination to a connected one</h3>
    <ol>
      <li>Create <code>src/Promote/Adapters/FooAdapter.php</code>.</li>
      <li>Add a <code>match</code> case in <code>BroadcastAdapters::dispatch()</code>.</li>
      <li>Save credentials via Settings → Promote — the destination status flips to <em>connected</em> automatically.</li>
    </ol>
  `,

};


class HelpPage extends PanicElement {
  set anchor(value) {
    this._anchor = value || '';
    if (this.isConnected) this.afterRender();
  }

  connect() {
    this._anchor = this._anchor || '';
    this.render();
    this.afterRender();
  }

  render() {
    const toc = HELP_SECTIONS.map((group) => `
      <div class="help-toc-group">
        <h4>${group.group}</h4>
        <ul>${group.items.map((item) => `<li><a data-toc href="#help-${esc(item.slug)}">${item.title}</a></li>`).join('')}</ul>
      </div>
    `).join('');

    const sections = HELP_SECTIONS.flatMap((g) => g.items).map((item) => {
      const body = HELP_CONTENT[item.slug] || `<h2>${item.title}</h2><p class="muted">Documentation coming soon.</p>`;
      return `<section class="help-section" id="help-${esc(item.slug)}">${body}<p class="help-back"><a href="#help-welcome">&uarr; Back to top</a></p></section>`;
    }).join('');

    publish('page.context', { title: 'Backstage Help', blurb: 'How the app works — onboarding, events, lineup, assets, settlement, and everything in between.' });
    this.innerHTML = `
      <section class="page-head">
        <a class="button secondary" href="#dashboard">Back to Dashboard</a>
      </section>
      <div class="help-layout">
        <aside class="help-toc" aria-label="Help topics">${toc}</aside>
        <article class="help-content panel padded">${sections}</article>
      </div>
    `;

    $$('[data-toc]', this).forEach((link) => link.addEventListener('click', (event) => {
      // Let the browser scroll, but also highlight the active TOC item.
      const slug = (link.getAttribute('href') || '').replace('#help-', '');
      this.highlight(slug);
    }));
  }

  afterRender() {
    const slug = this._anchor || 'welcome';
    // Defer to next frame so layout is settled before scrolling.
    requestAnimationFrame(() => {
      const target = this.querySelector(`#help-${CSS.escape(slug)}`);
      if (target) target.scrollIntoView({ behavior: 'auto', block: 'start' });
      this.highlight(slug);
    });
  }

  highlight(slug) {
    $$('[data-toc]', this).forEach((link) => {
      link.classList.toggle('active', link.getAttribute('href') === `#help-${slug}`);
    });
  }
}
customElements.define('pb-help-page', HelpPage);
