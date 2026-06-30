# Changelog

All notable changes to **Panic Backstage** are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

New entries are added automatically by the post-commit hook. To promote
[Unreleased] items to a dated release, rename the section heading and add
a new empty [Unreleased] block above it.

---

## [Unreleased]
- **Fixed** — Remove redundant 'save as flyer.png' from prompt to stop double image generation
- **Fixed** — Search recursively for codex-generated PNG instead of assuming flyer.png
- **Fixed** — Create codex temp dirs as 0755 so PHP can read generated image
- **Fixed** — Give codex a writable per-request CODEX_HOME in /tmp
- **Fixed** — Simplify codex invocation to exec() and open .codex permissions
- **Fixed** — Correct codex exec sandbox flag and supply explicit env for OAuth
- **Added** — AI flyer generation via Codex from the Assets panel
- **Added** — Pre-populate booker fields from the creating user on event creation
- **Changed** — Style external Tickets link as gold CTA on public event page
- **Changed** — Tighten public event ticket widget: number-input quantity and clear CTA button
- **Fixed** `ledger` — Correct ticket sales query in P&L summary
- **Chore** `nav` — Hide Duplicates and add Venue under Admin nav
- **Chore** `workspace` — Hide "Set as POS Event" button for now
- **Added** `admin` — Manage venue rooms (capacity, zone, archive)
- **Fixed** `leads` — Parse US-format dates and headline event titles
- **Added** `admin` — Venue details tab with edit form and PATCH API
- **Fixed** `migrations` — Drop CHECK constraint incompatible with MariaDB 10.7
- **Added** `dashboard` — Customizable top metrics via gear menu
- **Added** `gdpr` — Enforce cookie consent on non-essential preference storage
- **Added** `gdpr` — Data export/erasure endpoints, privacy policy, consent banner, self-hosted Font Awesome
- **Added** `dashboard` — Add clickable New Leads card linking to the Leads inbox
- **Fixed** `leads
validator rejects enum + nullable type` — Drop enum from event_type LLM schema (validator rejects enum + nullable type)
- **Added** `leads` — Import booking emails into the leads pipeline
- **Added** `leads` — Add BandBrief tab, band/attendance fields, and status notes
- **Added** `dashboard` — Add percent utilized metric for next 14 days
- **Fixed** `leads` — Fix modal not opening on row click
- **Added** `leads` — Replace split-pane detail with tabbed modal dialog
- **Documentation** `ops-manual` — Add comprehensive print/book CSS
- **Fixed** `nav` — Replace nonexistent fa-funnel with fa-filter for Leads icon
- **Fixed** `leads` — Fix can() misuse — capabilities is already the flat object, not a wrapper
- **Fixed** `leads` — Wire up New Lead button and align field names with DB schema
- **Fixed** `promote` — Generate Bandsintown variant + add per-platform submission links
- **Fixed** — Guard eventId in closeout and execution connect() to prevent load before workspace wires the property
- **Fixed** `pos` — Explicit active_event_id override — staff pin POS to a specific event instead of date-guessing
- **Added** `QBO/Xero stubs` — Accounting integration framework (QBO/Xero stubs) and Stripe payment link generation
- **Fixed** — Add Portal route and isPublic() registration to Kernel.php
- **Added** — Auto-publish event to Promote destinations when status reaches 'published'
- **Added** — Client portal — token-gated read-only event view for promoters and clients
- **Added** — CRM follow-up task email delivery and daily reminder endpoint
- **Added** — Incident resolution workflow with venue admin email notifications
- **Added** — Payroll export CSV for per-event and batch date-range staffing hours
- **Documentation** `ops-manual` — Add lead pipeline, deposit gate, vendors, execution, closeout, and venue policy chapters
- **Documentation** `help` — Add in-app help for leads, vendors, closeout, execution, and deposit gate
- **Added** `ui` — Closeout and billing ledger panel for event workspace
- **Added** `ui` — Execution records panel for event workspace
- **Added** `ui` — Vendors panel for event workspace
- **Added** `ui` — Leads inbox and deal evaluator front-end
- **Added** — Venue operating system upgrade — lifecycle, security, and financial controls
- **Fixed** `outbox` — Show email body in detail pane
- **Documentation** — Rename traing.md to training.md
- **Documentation** — Add staff training lesson plan for initial app intro session
- **Fixed** — Resolve three server errors breaking dashboard and venues endpoint
- **Documentation** — Sync all docs with current feature state; remove venue-specific names
- **Documentation** — Remove venue-specific proper names from help and ops manual
- **Added** `promote` — Public ICS + RSS event syndication feeds
- **Added** `dashboard` — Getting-started onboarding checklist for venue admins
- **Fixed** `venues` — Remove sort_order from ORDER BY — column not on venues table
- **Added** `multitenancy` — De-Mabuhay, resources table, layered .env, template inheritance
- **Fixed** `calendar` — Use local date parts in isoDate to avoid UTC offset showing wrong day
- **Fixed** `messages` — Iframe fills detail pane via flexbox — resizes with drag
- **Fixed** `messages` — Proper flexbox split — lock shell height, use flex-basis for detail pane
- **Added** `calendar` — Move New event button into toolbar as '+' icon after Pipeline
- **Added** `shell` — Add pb-page-header component — move page titles to topbar
- **Added** `messages` — Drag-to-resize divider between message list and detail panes
- **Fixed** `messages` — Reveal detail pane when a message is opened
- **Fixed** `messages` — Add missing CSS so inbox/archive/sent messages are visible
- **Added** `messages` — System welcome message in every staff inbox
- **Documentation** — Bring README up to date with multi-tenant SaaS, JWT auth, and Messages
- **Added** `messages
Inbox / Archive / Outbox` — In-app staff messaging (Inbox / Archive / Outbox)
- **Fixed** `routing` — Route /t/{token} ticket-view URLs to PHP kernel
- **Added** `outbox` — Add backfill script to inline cid: images in historical rows
- **Fixed** `outbox` — Inline cid: images as data URIs so they render in admin outbox
- **Added** `email` — Embed QR codes as MIME inline + attachment for maximum client compatibility
- **Added** `admin` — Show provider env vars on payments settings page
- **Added** `admin` — Show provider env vars on payments settings page
- **Added** `changelog` — Add CHANGELOG.md and automated post-commit hook

---

## [2026-06-20]

### Added
- Per-user email notification preferences — each user can now opt in/out of
  individual notification types (status changes, contract events, settlement
  reminders) from their Account page.

### Documentation
- Ops manual and in-app help brought to parity: updated event-creation section
  to describe the Event Creation Wizard as the primary flow (with Quick Create
  as the sidebar fallback); aligned contract terminal-status lists across both
  documents (added Cancelled and Superseded where missing).

---

## [2026-06-19]

### Added
- **E-signatures** — built-in electronic contract signing with no third-party
  account required. Signers receive a personalised, time-limited email link;
  sign via typed or drawn signature in their browser; and trigger automatic
  generation of a tamper-evident Final Executed PDF with SHA-256 hash and full
  audit certificate. Linked event auto-advances to Booked on full execution.
- Promoters can now edit event details (title, date, times, description) without
  needing an admin role.
- Admin notifications on all event status changes, not only Booked/Confirmed.

### Fixed
- Contract PDF rendering issues (blank PDF, margin sizing, base-path URL,
  print/save dialog).
- Activity log `created_at` date parsing crash.
- Missing `events.js` import dropped in a merge.

### Documentation
- Digital e-signature workflow documented in both the ops manual and in-app
  help: sending, what signers see, resend/void/countersign, audit log, and the
  Final Executed PDF.
- User Guide link added to the Help navigation.

---

## [2026-06-18]

### Added
- **Event Creation Wizard** — 7-step guided form that creates both the event
  and a pre-populated contract draft in one action. Steps: Event Basics, Deal
  Structure, Artist/Promoter, Deal Terms, Production & Security, Promotion,
  Review & Create. Admin-configurable defaults for venue, times, and deal type.
- **Sent-mail outbox** — browsable log of every email dispatched by the system.
- **Mini-calendar agenda view** — day-by-day venue breakdown with event cards.
- Capacity-based auto-staffing for event templates.

### Fixed
- SaaS: `APP_URL` now derived per-tenant from the request hostname.
- Ticketing: DB-level email deduplication guard; QR codes embedded in ticket
  emails; duplicate sends on Stripe/Square webhook retries eliminated.
- Calendar: date text colour restored in mini-calendar day buttons.

---

## [2026-06-17]

### Added
- Task delete button for admins and event owners.
- Promote: all manual-submission URLs now displayed in the action modal.

### Fixed
- Sheet sync: EVT codes now assigned to sheet-imported events that lacked them.
- Events list default cutoff changed from 14 days ago to yesterday.

---

## [2026-06-16]

### Added
- **Multi-tenant SaaS infrastructure** — per-tenant database routing, tenant
  provisioning, and isolated credential storage.
- Staff employment type field (Employee / Contractor).

### Fixed
- Event workspace header now updates live when event data changes.
- Contract PDF: `pdf-holder` positioning corrected (fixed → absolute).

---

## [2026-06-15]

### Documentation
- Full operations manual published: Chapter 4 (Panic Promote), Chapter 5
  (In-House Ticketing), Chapter 6 (Admin & Users), and Roles quick reference.

---

## [2026-06-14]

### Added
- **Private venue rental workflow** — dedicated form, shorter pipeline (skips
  all public-promotion stages), Colleen auto-assigned as owner, automatic
  inquiry/intake/booked email notifications, 🔒 badge throughout the UI.
- Global Viewer role — read-only access to all events without admin privileges.
- Field-level diffs on "event updated" activity log entries.
- Public event descriptions rendered as Markdown on the public page.
- Notifications: Colleen + Tom Watson emailed automatically on Intake Complete.
- Private events hide lineup, settlement, and ticketing sections.

### Documentation
- In-app help updated for booking workflow redesign and private event workflow.

---

## [2026-06-13]

### Added
- **Email notification templates** — HTML + plain-text emails for all key
  events: status changes, contract actions, settlement reminders, etc.
- Multipart MIME (HTML + text fallback) for all outgoing mail.
- Panic Promote adapters: TikTok photo posts, Facebook Pages, Instagram.
- Luma event-creation adapter.
- Real DB-derived analytics replacing stub metrics in Promote.
- Additional Promote destinations: email_adhoc broadcasts.

### Changed
- Promote redesigned to work directly from events — campaign abstraction removed
  for a simpler, more direct workflow.

### Fixed
- Promote: `data.post.variants` path corrected in `loadVariants`.
- Duplicate `DEST_GROUP_LABELS` declaration removed.

---

## [2026-06-12]

### Added
- Promote email adapter supporting Mailchimp and SendGrid.
- New Promote destinations: SF Chronicle, SF Station, DoTheBay, SongKick, JamBase.
- Promote credentials table, settings UI, and per-venue credential dispatch.
- Eventbrite adapter wired into the broadcast pipeline.
- In-app help: Panic Promote user guide and admin setup sections.

### Fixed
- Nav icon corrected (`fa-megaphone` → `fa-bullhorn`; Pro-only icon replaced).
- Booking workflow migrations made idempotent.

---

## [2026-06-11]

### Added
- **Panic Promote** — event marketing module: write a post once, generate
  channel-specific copy for 9 platforms, broadcast to connected destinations.
- Contract deal builder: auto-save on field change, drag-to-reorder clauses,
  inline editing, token click-to-field, review click-to-field.
- Task template dropdown in the event Tasks section.
- Booking workflow: room colour-coding, double-booking guard, structured contact fields.

### Fixed
- Contract clause checkbox alignment (CSS grid).

---

## [2026-06-10]

### Added
- CRM Contacts list seeded from the ticketing Fan View export — name, email,
  phone, ticket count, lifetime spend, marketing opt-in.
- Ticketing: view/print/resend QR tickets, void, and issue comps from the guest
  list; auto-seed Comps allocation + Door scanner link on first in-house setup.
- Calendar: events colour-coded by venue; status encoded by dot colour.
- Event: generative paint-splat background behind event title.

### Changed
- Ticket and contract fields dropped from the Event Details form (moved to
  their dedicated Contracts and Ticketing tabs).

### Fixed
- Venue ID derivation from the sheet-driven room field.
- Booked/Confirmed status now shown in green (was amber/grey).
- Duplicate staff records from repeated MabEvents import runs removed.

---

## [2026-06-09]

### Added
- Mobile: slide-in navigation drawer and de-cluttered bottom bar.
- Events: auto-fill Doors / Show / End times from any single time entry.
- Ticketing: auto-seed General Admission tier when switching to in-house mode.
- Scanner: re-display link + QR for existing scanner links.
- Nav: title tooltips on collapsed sidebar items.
- Zero-dependency headless UI test harness.
- Help: UI screenshots added to all major help sections.
- Tooling: `scripts/screenshots.mjs` to regenerate help screenshots.

### Changed
- `events.js` split into focused ESM domain modules.

### Fixed
- Scanner: correct URL (no doubled base path); serve `scanner.html` not the SPA.
- Payments: Square webhook-to-order matching via Square order ID.

### Security
- Uploaded files blocked from server-side execution.

---

## [2026-06-08]

### Added
- In-app help: ticketing section and expanded help nav with categories.
- Operator guide for the ticketing system.
- Sheet sync: create local events from new sheet rows with precise ID write-back;
  push app-created named events back to the sheet; honour `SHEET_INSERT_NEW` env flag.
- Self-service access requests with admin email approval.

---

## [2026-06-07]

### Added
- **In-house event ticketing** — Stripe and Square payment processing, ticket
  purchase flow, per-ticket QR codes emailed to buyers, door scanner.
- Multi-email identity — verified alias login, alias add/verify/primary
  endpoints, duplicate-detection, and atomic account merge.
- Staff: `hire_date` field added to roster.
- Sheet sync: two-way Staff Contact ↔ `staff_members` sync; self-heal events
  marked done but missing from the sheet; insert new rows in date order.

### Fixed
- Square checkout retried without buyer-email prefill on rejection.
- Missing foreign keys on `ticket_scans` added.
- MariaDB compatibility for multi-email identity queries.

---

## [2026-06-06]

### Added
- **Contract Deal Builder** — structured deal terms, smart auto-selection of
  clauses (all-ages, bar minimum, security, etc.), drag-to-reorder, locked
  legal clauses, PDF export. Templates: Private Event Rental, Promoter/Production,
  Artist/Band, Recurring Night, High-Draw Artist, Fundraiser, House-Produced.
- Collapsible grouped navigation + Account / Preferences / Contracts sections.
- Brand assets: favicons, web manifest, logo files.

### Changed
- Frontend split from monolithic `app.js` into ESM domain modules.

### Documentation
- Contracts operator/dev guide + in-app help for contracts and admin.

---

## [2026-06-05]

### Added
- Staff added via header "+" modal instead of inline form.
- Pipeline board focused on next two weeks with date-range filter.
- Events list sorted oldest-first by default.
- Polished read-only review UI for event detail lists.

### Fixed
- WebAuthn: base path stripped from passkey origin check.

---

## [2026-06-04]

### Added
- Fixed-height desktop app shell with collapsible icon-rail navigation.
- Events: hide shows more than 2 weeks past by default; "Show Past Events" toggle.
- Asset lightbox — tap any flyer or photo to view full-size.

### Fixed
- HTML shell now sends `no-cache` headers so asset version bumps reach clients.
- Quick-create modal stuck on "Loading templates" resolved.

---

## [2026-06-03]

### Added
- **Autosave** — event details save on blur with a "Saving…/Saved" indicator.
- Mabuhay Upstairs added as a bookable venue.
- Human-friendly sequential `EVT-N` event codes shown in table and detail header.
- Sortable events table + One Sheet view.
- Readiness and Next Recommended Action cards as reactive web components.
- Asset lightbox for flyers and photos.

### Fixed
- Google Sheet sync: column alignment, App-ID linking, and field-level merge corrected.
- Calendar quick-create dialog now loads templates correctly.

---

## [2026-05-30]

### Added
- Two-way Google Sheet sync — Backstage edits push to the sheet in real time;
  sheet changes pull back on a 5-minute cron.
- `MAIL_BCC` envelope blind-copy support in the mailer.
- Login-link CLI tool to mint a magic link without triggering an email send.

### Documentation
- Google Sheet sync setup and operation guide.

---

## [2026-05-22]

### Added
- Seven sheet columns promoted from notes blob to structured event fields.
- Pipeline status labels aligned with Google Sheet vocabulary; `deposit_amount` added.

### Documentation
- In-app sign-in and Account help pages updated for the email-first auth flow.

---

## [2026-05-20]

### Added
- Email-first login flow — Backstage detects available credentials per address
  before showing sign-in options.
- WebAuthn passkey support — sign in with Face ID, Touch ID, or a hardware key.
- `hide_credential_setup_prompt` user preference.
- Quick-create modal accessible from the topbar "+" and by clicking any calendar day.
- In-app help center, admin panel, and event staffing module.
- Invite system: optional email send on create; resend button for pending invites.
- Nightly cron to auto-archive past confirmed events.
- General Event template; Punk Rock Karaoke template renamed.

---

## [2026-05-19]

### Added
- Font Awesome icons throughout the UI.
- Per-event guest list / door list with check-in tracking.
- Swing Dancing Night event template seeded.
- MabEvents legacy importer + 5-minute cron sync with the Google Sheet.

### Security
- Uploaded files blocked from server-side execution.

---

## [2026-05-01–03]

### Added
- JWT magic-link authentication replacing the original password system.
- Collaborator-scoped event access enforcement.
- Subdirectory app mount support (`APP_BASE_PATH` env).
- Responsive layouts for mobile and tablet.
- Web Components–based venue demo.

---

## [2026-04-30] — Initial release

### Added
- Panic Backstage MVP: PHP API kernel, event management, show pipeline,
  lineup, run sheet, staffing, assets, open items, settlement, and
  Mabuhay Gardens branding.
