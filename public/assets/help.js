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
    group: 'Working with the App',
    key: 'working',
    icon: 'fa-solid fa-compass',
    items: [
      { slug: 'navigation',   title: 'Main navigation' },
      { slug: 'dashboard',    title: 'Dashboard' },
      { slug: 'calendar',     title: 'Calendar' },
      { slug: 'pipeline',     title: 'Pipeline board' },
      { slug: 'events-list',  title: 'Events list &amp; search' },
      { slug: 'templates',    title: 'Templates' },
    ],
  },
  {
    group: 'Running an Event',
    key: 'running',
    icon: 'fa-solid fa-calendar-check',
    items: [
      { slug: 'event-create',    title: 'Creating an event' },
      { slug: 'private-events',  title: 'Private events &amp; rentals' },
      { slug: 'overview',        title: 'Overview &amp; readiness' },
      { slug: 'details',         title: 'Event details' },
      { slug: 'tasks',        title: 'Tasks' },
      { slug: 'lineup',       title: 'Lineup &amp; bands' },
      { slug: 'schedule',     title: 'Schedule &amp; run sheet' },
      { slug: 'staffing',     title: 'Staffing' },
      { slug: 'open-items',   title: 'Open items' },
      { slug: 'guest-list',   title: 'Guest list &amp; door' },
      { slug: 'assets',       title: 'Assets &amp; flyers' },
      { slug: 'invites',      title: 'Invites &amp; collaborators' },
      { slug: 'contracts',    title: 'Contracts &amp; deal builder' },
      { slug: 'ticketing',    title: 'Ticketing &amp; door' },
      { slug: 'settlement',   title: 'Settlement' },
      { slug: 'publish',      title: 'Publishing the public page' },
      { slug: 'print',        title: 'Printable packets' },
      { slug: 'activity',     title: 'Activity log' },
    ],
  },
  {
    group: 'Administration',
    key: 'administration',
    icon: 'fa-solid fa-user-shield',
    items: [
      { slug: 'admin',        title: 'Admin overview' },
      { slug: 'admin-users',  title: 'Managing login accounts' },
      { slug: 'contacts',     title: 'Contacts (CRM)' },
      { slug: 'admin-staff',  title: 'Staff roster' },
      { slug: 'admin-templates', title: 'Editing event templates' },
      { slug: 'admin-contracts', title: 'Contract library &amp; templates' },
      { slug: 'admin-payments', title: 'Payment providers' },
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

    <p class="muted small">Demo admin (when seeded): <code>admin@mabuhay.local</code> / <code>changeme</code>.</p>
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
    <p>The dashboard summarises Mabuhay show operations for the next two weeks.</p>
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

    <h3>Venue floor colour code</h3>
    <p>The coloured dot on each chip indicates which part of the venue is booked:</p>
    <ul>
      <li><strong>Blue dot</strong> — Upstairs</li>
      <li><strong>Red dot</strong> — Downstairs (21+)</li>
      <li><strong>Green dot</strong> — Both Rooms</li>
    </ul>
    <p>The legend below the calendar toolbar shows the colour key at a glance.</p>

    <h3>Times on chips</h3>
    <p>Each calendar chip shows the Doors time (or Show time if no Doors time is set) as a small badge on the right. Hovering the chip shows a tooltip with Status · Venue floor · Doors time · Load-In time.</p>

    <h3>Private events</h3>
    <p>Private venue rentals (Type = Private Event) are shown on the calendar with a 🔒 lock icon and a subtle grey background so staff can distinguish them from publicly promoted shows at a glance. Private events are never announced publicly and will never appear on the public calendar or event page.</p>

    <h3>Cancelled events</h3>
    <p>Cancelled events are hidden from the calendar entirely. They remain in the database and are queryable via the Events list, but they do not occupy a date cell on the calendar view. This keeps the calendar clean while preserving the historical record.</p>

    <h3>Creating events from the calendar</h3>
    <p>Venue admins can click any day cell to open the quick-create modal. Pick a template (or Blank event), confirm the date, title, and times, and click <em>Create event</em> to jump straight into the new event workspace.</p>

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
    <p>Every show in Backstage follows the same arc: spin the event up from a template, lock in the deal and the details, get the key pieces <strong>approved</strong> (the contract and the flyer), announce it to the public, run it on the night, and settle the money afterward. The event workspace is a set of tabs that roughly follow that arc, and the <a href="#help-overview">Overview</a> tab keeps score — its <em>Readiness</em> checks and <em>Next Recommended Action</em> banner always point you at the next thing the show needs.</p>
    <p>Approvals happen in two places as you go. A <a href="#help-contracts">contract</a> moves through a status workflow (<em>draft &rarr; needs review &rarr; approved &rarr; sent &rarr; signed</em>) and can't be marked sent or signed until its required terms are filled. A flyer is uploaded as <em>pending</em> and a promoter or admin marks it <em>approved</em> before it appears publicly. The event's own <a href="#help-statuses">status</a> (Hold, Intake Complete, Booked, Needs Assets, Published, Advanced, Settled…) is the high-level signal to the rest of the team about where the show stands.</p>
    <p class="help-tip">📋 <strong>Private event / venue rental?</strong> See <a href="#help-private-events">Private events &amp; rentals</a> — the workflow is shorter and uses a different form.</p>
    <p>Every public show starts from a template. Open <a href="#help-templates">Templates</a>, pick one that matches the kind of night you are programming, fill in date and doors/show times, and click <em>Create event</em>. From there, work through the tabs — roughly in this order:</p>
    <ol>
      <li><a href="#help-details">Event details</a> — set venue, type, status, owner, ticket price, capacity, and age restriction.</li>
      <li><a href="#help-lineup">Lineup</a> — add the bands or performers, capture payout terms, and confirm them.</li>
      <li><a href="#help-contracts">Contracts</a> — capture the deal as structured terms, generate the agreement, and walk it through approval to signed.</li>
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

  'private-events': `
    <h2>Private events &amp; rentals</h2>
    <p>A <strong>private event</strong> is a venue rental where a client books Mabuhay Gardens for their own occasion — a corporate event, private party, wedding reception, album release, film shoot, or similar. Private events are never publicly listed and follow a different workflow from public shows.</p>

    <h3>How a rental inquiry comes in</h3>
    <ol>
      <li>A staff member creates a new event and sets <strong>Type → Private Event</strong>. The form immediately switches to the private event layout.</li>
      <li>Backstage automatically assigns <strong>Colleen</strong> as the event owner and sends an inquiry notification email to all venue admins listing the client details, date, estimated guests, and AV/catering requirements.</li>
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
    <p class="muted small">💰 For rental pricing, contact <strong>Tom Watson</strong>: <a href="mailto:tom@themab.org">tom@themab.org</a></p>
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
      <li><strong>When Intake Complete</strong> — Colleen and Tom Watson both receive an <em>Intake Complete — Contract Needed</em> email with the event details and a numbered checklist: Colleen drafts the contract → Tom co-signs → contract sent to client for signature → upload signed copy → advance to Booked.</li>
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
      <img src="./assets/help/event.png" alt="An event workspace: the row of section tabs, the event header with flyer thumbnail and facts, the Next Recommended Action banner, and the Readiness panel" loading="lazy">
      <figcaption>An event workspace — the tab row jumps between sections; below it sit the event facts, Next Recommended Action, and the Readiness checklist.</figcaption>
    </figure>
    <p>The top of every event workspace shows a flyer thumbnail, the event facts (date, doors, show, status, owner, public-page state), and two counters that link straight to the matching tabs:</p>
    <ul>
      <li><strong>Open Items</strong> count — blockers that are still <em>open</em> or <em>waiting</em>.</li>
      <li><strong>Tasks Left</strong> count — tasks not yet marked <em>done</em> or <em>canceled</em>.</li>
    </ul>
    <p>Below that is a <strong>Next Recommended Action</strong> banner suggesting the most important next step (sign the artist, approve the flyer, build the run sheet, etc.). It refreshes when you click <em>Refresh</em> or save something.</p>
    <p>The <strong>Readiness</strong> panel lists the gates we check before a show is "ready" (lineup confirmed, flyer approved, public page on, run sheet built, settlement filed, and so on) with a clear OK / not-OK mark. The <strong>Internal Notes</strong> panel is the place for anything you do not want on the public page — green-room arrangements, transport, dietary notes, comp commitments.</p>
  `,

  details: `
    <h2>Event details</h2>
    <p>The Event Details form holds the core facts of the show. Fields auto-save on blur — you will see "Saving…" and then "All changes saved" in the bottom-left of the form as each field persists.</p>

    <h3>Common fields (all events)</h3>
    <ul>
      <li><strong>Title</strong> — the marquee name. Used everywhere: dashboard, calendar chips, public page, print packets.</li>
      <li><strong>Date</strong> — show date.</li>
      <li><strong>Venue</strong> — choose from the venues your account can see.</li>
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
    <p>Shifts are grouped by role for a clean read at the door. Print the staffing schedule from the <em>Print</em> menu — it lists call times, role, staff name and phone, and shift status, alongside the run sheet's staff_call times for cross-reference.</p>
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
    <p>Use the form at the bottom of the Assets panel. Give the file a title, pick a type, choose a file (PNG, JPG, GIF, WEBP, or PDF), add notes, and click <em>Upload asset</em>. Uploads go to local disk under <code>storage/uploads/events/&lt;id&gt;</code>.</p>
    <h3>Asset types</h3>
    <ul>
      <li><strong>Flyer</strong> — the primary show flyer. The first approved flyer is shown on the public event page and on print packets.</li>
      <li><strong>Poster</strong> — print poster for the venue wall.</li>
      <li><strong>Band photo / Press photo</strong> — used for press kits and social.</li>
      <li><strong>Logo</strong> — band or sponsor mark.</li>
      <li><strong>Social square / Social story</strong> — sized for IG feed and IG/FB stories.</li>
      <li><strong>Other</strong> — anything else.</li>
    </ul>
    <h3>Approval flow</h3>
    <p>Each asset has an approval status: <em>pending</em>, <em>approved</em>, or <em>rejected</em>. Promoters and admins click <em>Approve</em> or <em>Reject</em>. The dashboard's "Needs Flyer" counter watches the count of <em>approved</em> flyers per event.</p>
    <h3>Bands uploading their own assets</h3>
    <p>Bands with a backstage account and a band/artist invite on this event can upload their own press photos and stage plot PDFs without round-tripping through the booker.</p>
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
    <p>Contracts move through: <strong>Draft &rarr; Needs Review &rarr; Approved &rarr; Sent &rarr; Signed</strong> (plus <em>Cancelled</em> and <em>Superseded</em>). Approving requires the <em>approve contracts</em> permission. The buttons available depend on the current status and your role.</p>

    <h3>Versions &amp; PDF</h3>
    <p>Every <em>Generate version</em> stores an immutable snapshot you can re-open from <em>Version history</em>. <em>Download PDF</em> renders the current preview to a PDF in the browser — no e-signature step is required for the MVP; a generated PDF plus manual signing is enough.</p>

    <p class="muted small">Who sees what: venue admins and event owners can manage and approve event contracts; promoters can view them; bands, designers, and viewers do not see contracts. Clause text is starter language — have counsel review your clause library before sending real contracts.</p>
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
    <p>Every event has a public-facing page at <code>/event.html?slug=&lt;slug&gt;</code> that shows the title, date, doors/show, age restriction, ticket link, public description, lineup, and the approved flyer.</p>
    <h3>Toggling publish</h3>
    <p>Click <em>Publish Public Page</em> at the top of the event workspace to make it live, or <em>Hide Public Page</em> to take it offline. The same toggle exists as a checkbox in <a href="#help-details">Event details</a>.</p>
    <h3>Previewing</h3>
    <p>Click <em>Public Page</em> in the event header to open the public page in a new tab. It is fetched anonymously from <code>/api/public/events/&lt;slug&gt;</code>; if the event is hidden the API returns an error.</p>
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
      <li><a href="#help-admin-staff">Staff</a> — keep the roster of bartenders, security, door, sound, etc. used in event staffing.</li>
      <li><a href="#help-admin-templates">Templates</a> — edit run-sheet and checklist templates used to create new events.</li>
      <li><a href="#help-admin-contracts">Contracts</a> — the contract clause library, contract templates, and a venue-wide list of all contracts.</li>
      <li><a href="#help-admin-payments">Payments</a> — choose the payment processor and currency used for in-house ticket sales.</li>
    </ul>
    <p>Each tab has a stable deep link: <code>#admin-users</code>, <code>#admin-staff</code>, <code>#admin-templates</code>, <code>#admin-contracts</code>, <code>#admin-payments</code>.</p>
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

  statuses: `
    <h2>Event status reference</h2>
    <p>Events move through these statuses, roughly left to right on the pipeline. The vocabulary is shared with the MabEvents Google Sheet so both tools stay in sync:</p>

    <h3>Public show statuses</h3>
    <ol>
      <li><strong>Empty</strong> — the date slot exists but nothing is confirmed for it yet.</li>
      <li><strong>Hold</strong> — a show idea or inquiry is live; the band/promoter deal is not yet confirmed. The date is informally held. Requires title, date, venue, door/end times, and a producer/artist contact.</li>
      <li><strong>Intake Complete</strong> — deal structure is agreed and a contract is being built. Age restriction, ticket price, capacity, and a deposit amount must be set. When this status is set, Colleen and Tom Watson are automatically emailed with next steps for the contract.</li>
      <li><strong>Booked</strong> — a signed contract (or approved contract in the contract builder) plus a confirmed deposit. The show is locked.</li>
      <li><strong>Needs Assets</strong> — booked but blocked on flyer, artist photos, bio, or social content. An automatic email is sent to the producer/artist when this status is set.</li>
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
    <p>Private events are automatically blocked from reaching Needs Assets, Ready To Announce, Published, and Advanced — those stages exist only for publicly promoted shows.</p>

    <h3>Notes</h3>
    <p>Status transitions are validated by the server — some forward moves require certain fields to be filled (e.g. you cannot advance to Booked without a contract). The pipeline and calendar display the current status for every event.</p>
  `,

  workflow: `
    <h2>End-to-end show workflow</h2>

    <h3>Public shows</h3>
    <p>A typical Mabuhay public show moves through these phases:</p>
    <ol>
      <li><strong>Program the night</strong> — pick a template (Templates page), set the date, and create the event. Status: <em>Empty</em>.</li>
      <li><strong>Lock in the deal</strong> — add the producer/artist contact and the booker contact. Status advances to <em>Hold</em>. The date is informally held while terms are confirmed.</li>
      <li><strong>Intake complete</strong> — set age restriction, ticket price, capacity, and deposit amount. Build the contract and walk it through approval. Status advances to <em>Intake Complete</em>.</li>
      <li><strong>Book it</strong> — once the contract is approved and a deposit is confirmed, status advances to <em>Booked</em>.</li>
      <li><strong>Collect assets</strong> — status advances to <em>Needs Assets</em>; an automatic email is sent to the producer/artist requesting the flyer, photos, bio, and social handles. Upload and approve the primary flyer.</li>
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
    <p>Local disk under <code>storage/uploads/events/&lt;event id&gt;</code>. The web server serves them via the <code>public/uploads</code> symlink.</p>
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
    <p>Either the event is hidden (toggle <em>Publish Public Page</em> on) or the slug is wrong. The public page only returns data for events with public visibility enabled.</p>
    <h3>Upload failed</h3>
    <p>Check that the file is under the server's <code>upload_max_filesize</code> and is one of the accepted types (PNG, JPG, GIF, WEBP, PDF). The server enforces type by both extension and MIME via <code>finfo</code>.</p>
    <h3>Asset won't approve</h3>
    <p>Only promoters and admins can approve assets. Bands and designers can upload but not approve.</p>
  `,

  // ── Panic Promote — user guide ──────────────────────────────────────────────

  'promote-overview': `
    <h2>What is Panic Promote?</h2>
    <p>Panic Promote is the built-in marketing engine for Mabuhay Gardens. Once you have an event in Backstage, Promote helps you write, approve, and push announcements to every platform you use — Facebook, Instagram, Eventbrite, Luma, Foopee, Funcheap, your email list, and more — without leaving the app.</p>
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
      <li>Click <em>Create event</em> — Eventbrite prompts you to create an Organizer profile on the first use. Name it <strong>Mabuhay Gardens</strong>.</li>
    </ol>
    <h3>Step 2 — Save credentials in Backstage</h3>
    <ol>
      <li>Go to <strong>Settings → Promote</strong>.</li>
      <li>In the <strong>Eventbrite</strong> card, enter the API Key (private token from <a href="https://www.eventbrite.com/account-settings/apps" target="_blank" rel="noreferrer">eventbrite.com/account-settings/apps</a>).</li>
      <li>Click <em>Fetch Org ID</em> — Backstage calls the API and auto-fills the Organizer ID.</li>
      <li>Optionally enter a pre-created Eventbrite Venue ID for Mabuhay Gardens.</li>
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
      <li>A <strong>Facebook Page</strong> for Mabuhay Gardens (not a personal profile).</li>
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

    this.innerHTML = `
      <section class="page-head">
        <div><h1>Backstage Help</h1><p class="subtle">How the app works — onboarding, events, lineup, assets, settlement, and everything in between.</p></div>
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
