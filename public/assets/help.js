import { esc, statuses, publish, api, money, badge, option, can, table, PanicElement, $, $$ } from './core.js';


// ── Help page ────────────────────────────────────────────────────────────────
// Long-form documentation for the backstage app. Sections are anchored so the
// small "?" icons next to each event section can deep-link via #help-<slug>.

const HELP_SECTIONS = [
  {
    group: 'Getting Started',
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
    items: [
      { slug: 'event-create', title: 'Creating an event' },
      { slug: 'overview',     title: 'Overview &amp; readiness' },
      { slug: 'details',      title: 'Event details' },
      { slug: 'tasks',        title: 'Tasks' },
      { slug: 'lineup',       title: 'Lineup &amp; bands' },
      { slug: 'schedule',     title: 'Schedule &amp; run sheet' },
      { slug: 'staffing',     title: 'Staffing' },
      { slug: 'open-items',   title: 'Open items' },
      { slug: 'guest-list',   title: 'Guest list &amp; door' },
      { slug: 'assets',       title: 'Assets &amp; flyers' },
      { slug: 'invites',      title: 'Invites &amp; collaborators' },
      { slug: 'contracts',    title: 'Contracts &amp; deal builder' },
      { slug: 'settlement',   title: 'Settlement' },
      { slug: 'publish',      title: 'Publishing the public page' },
      { slug: 'print',        title: 'Printable packets' },
      { slug: 'activity',     title: 'Activity log' },
    ],
  },
  {
    group: 'Administration',
    items: [
      { slug: 'admin',        title: 'Admin overview' },
      { slug: 'admin-users',  title: 'Managing login accounts' },
      { slug: 'admin-staff',  title: 'Staff roster' },
      { slug: 'admin-templates', title: 'Editing event templates' },
      { slug: 'admin-contracts', title: 'Contract library &amp; templates' },
    ],
  },
  {
    group: 'Reference',
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
    <p>The calendar shows a six-week window. Use the <code>&lt;</code> and <code>&gt;</code> buttons to move months, or <em>Today</em> to snap back. Dates without an event show an <em>Available</em> chip; dates with events show a colored status dot and the event title. Click any event to open it.</p>
    <p>The dashboard, pipeline, and calendar all read from the same <code>/api/events</code> data, so adding or moving a show updates all three.</p>
  `,

  pipeline: `
    <h2>Pipeline board</h2>
    <p>The pipeline groups events by status into columns. To advance an event, choose the new status in its card's inline dropdown and click <em>Move</em>. Open the card to jump into the full event workspace. The pipeline is the fastest way to move several events forward at once.</p>
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
    <p>Every show starts from a template. Open <a href="#help-templates">Templates</a>, pick a template that matches the kind of night you are programming, fill in date and doors/show times, and click <em>Create event</em>.</p>
    <p>From there, work top to bottom in the event workspace:</p>
    <ol>
      <li><a href="#help-details">Event details</a> — set venue, type, status, owner, ticket price, capacity, age restriction.</li>
      <li><a href="#help-lineup">Lineup</a> — add the bands or performers.</li>
      <li><a href="#help-schedule">Run sheet</a> — set load-in, soundcheck, set times, curfew.</li>
      <li><a href="#help-tasks">Tasks</a> — assign anything that has to be done before doors.</li>
      <li><a href="#help-assets">Assets</a> — collect and approve flyers.</li>
      <li><a href="#help-publish">Publish</a> — flip the public page on when the show is ready to announce.</li>
      <li><a href="#help-guest-list">Guest list</a> — close to show day, build the door list.</li>
      <li><a href="#help-settlement">Settlement</a> — after the show, reconcile the numbers.</li>
    </ol>
  `,

  overview: `
    <h2>Overview &amp; readiness</h2>
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
    <p>The Event Details form holds the facts of the show. Edits save with the <em>Save details</em> button.</p>
    <ul>
      <li><strong>Title</strong> — the marquee name of the show. Used everywhere (dashboard, calendar, public page, print packets).</li>
      <li><strong>Date</strong> — show date.</li>
      <li><strong>Venue</strong> — choose from the venues your account can see.</li>
      <li><strong>Type</strong> — live music, karaoke, open mic, promoter night, DJ night, comedy, private event, or special event.</li>
      <li><strong>Status</strong> — see <a href="#help-statuses">Event status reference</a>.</li>
      <li><strong>Owner</strong> — the staff member responsible. Owners get implicit access to the event.</li>
      <li><strong>Doors / Show / End</strong> — set the public-facing times.</li>
      <li><strong>Age restriction</strong> — shown on the public page (e.g. 21+, All Ages).</li>
      <li><strong>Ticket price / Capacity / Ticket URL</strong> — used for ticketing handoff and public page.</li>
      <li><strong>Public description</strong> — copy that appears on the public event page.</li>
      <li><strong>Internal notes</strong> — only visible to staff and collaborators.</li>
      <li><strong>Public page visible</strong> — toggles the publish state from inside the form. The big <em>Publish</em> button at the top of the workspace does the same thing.</li>
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
    <p>The Admin nav item is visible only to venue admins. It groups four management tools as tabs on a single page:</p>
    <ul>
      <li><a href="#help-admin-users">Users</a> — create, edit, and delete backstage login accounts; reset passwords; change roles.</li>
      <li><a href="#help-admin-staff">Staff</a> — keep the roster of bartenders, security, door, sound, etc. used in event staffing.</li>
      <li><a href="#help-admin-templates">Templates</a> — edit run-sheet and checklist templates used to create new events.</li>
      <li><a href="#help-admin-contracts">Contracts</a> — the contract clause library, contract templates, and a venue-wide list of all contracts.</li>
    </ul>
    <p>Each tab has a stable deep link: <code>#admin-users</code>, <code>#admin-staff</code>, <code>#admin-templates</code>, <code>#admin-contracts</code>.</p>
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

  statuses: `
    <h2>Event status reference</h2>
    <p>Events move through these statuses, roughly left to right on the pipeline. Labels match the MabEvents Google Sheet so the vocabulary is consistent across both tools:</p>
    <ol>
      <li><strong>Empty</strong> — the date is held but nothing is booked.</li>
      <li><strong>Prospect</strong> — a show idea exists but is not confirmed.</li>
      <li><strong>In Negotiations</strong> — soft hold with a band/promoter; terms still being worked out.</li>
      <li><strong>Booked</strong> — show is on (includes deposits paid), but assets and announcement are pending.</li>
      <li><strong>Needs Assets</strong> — booked, blocked on flyer/social art.</li>
      <li><strong>Ready To Announce</strong> — flyer approved, ticketing ready; just needs to flip public on.</li>
      <li><strong>Published</strong> — public page is live.</li>
      <li><strong>Advanced</strong> — production advanced; ready for night-of-show.</li>
      <li><strong>Archived</strong> — show happened, waiting on settlement.</li>
      <li><strong>Settled</strong> — books closed.</li>
      <li><strong>Cancelled</strong> — show was cancelled.</li>
    </ol>
    <p>Statuses do not enforce hard transitions — you can move between any of them. They are signals to the rest of the team and to the dashboard.</p>
  `,

  workflow: `
    <h2>End-to-end show workflow</h2>
    <p>A typical Mabuhay show moves through these phases:</p>
    <ol>
      <li><strong>Program the night</strong> — pick a template (Templates page), set the date, and create the event.</li>
      <li><strong>Sign the artists</strong> — add bands to the lineup, capture payout terms, mark them <em>tentative</em> then <em>confirmed</em>.</li>
      <li><strong>Set the times</strong> — fill in doors, show, set times, and curfew on the run sheet.</li>
      <li><strong>Collect assets</strong> — invite the band's designer if needed; upload flyers; approve the primary flyer.</li>
      <li><strong>Announce</strong> — set ticket URL, public description, and flip the public page on. Status becomes <em>published</em>.</li>
      <li><strong>Advance</strong> — close out open items, confirm hospitality, share run sheet with bands. Status becomes <em>advanced</em>.</li>
      <li><strong>Night of show</strong> — print the master event packet, use the guest list for door, check guests in.</li>
      <li><strong>Settle</strong> — file settlement next-day, mark <em>settled</em>.</li>
    </ol>
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
