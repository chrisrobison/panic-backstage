# Panic Backstage

[![CI](https://github.com/chrisrobison/panic-backstage/actions/workflows/ci.yml/badge.svg)](https://github.com/chrisrobison/panic-backstage/actions/workflows/ci.yml)

Panic Backstage helps venues track every show from hold to settlement, including lineup, schedule, artwork, ticketing, open items, and event readiness.

The app is intentionally boring to run:

- PHP 8, served from `public/`
- no Composer runtime dependencies
- no npm, bundler, or frontend build step
- MySQL with native PDO prepared statements (one database, or one database per tenant in SaaS mode)
- stateless JWT auth (HS256, no library) with magic-link and passkey/WebAuthn sign-in — no server-side sessions in the tenant apps
- static HTML/CSS/native Web Components that call JSON endpoints under `/api`
- LARC/PAN loaded from a pinned CDN module for component coordination
- optional multi-tenant SaaS mode: per-venue database + hostname, resolved per request (off by default — see [Multi-Tenant / SaaS Mode](#multi-tenant--saas-mode))

## Current Architecture

Panic Backstage is API-first. PHP endpoint classes return JSON only; HTML pages are static and use browser-native Web Components, `fetch()`, and LARC/PAN topic events to load and mutate data.

The full API surface — every route, request/response schema, auth requirement, and required capability — is documented as an OpenAPI 3.0 spec at [`docs/openapi.yaml`](docs/openapi.yaml). Paste it into [Swagger Editor](https://editor.swagger.io/) or [Redocly](https://redocly.github.io/redoc/) for a browsable reference. It's maintained by hand alongside `src/Kernel.php` — update both together when routes change.

The custom kernel in `src/Kernel.php` resolves constrained API paths to endpoint classes:

```text
GET    /api/dashboard                  -> src/Dashboard.php
GET    /api/events                     -> src/Events.php
POST   /api/events                     -> src/Events.php
GET    /api/events/{id}                -> src/Events.php
PATCH  /api/events/{id}                -> src/Events.php

GET    /api/events/{id}/tasks          -> src/Events/Tasks.php
POST   /api/events/{id}/tasks          -> src/Events/Tasks.php
PATCH  /api/events/{id}/tasks/{taskId} -> src/Events/Tasks.php
DELETE /api/events/{id}/tasks/{taskId} -> src/Events/Tasks.php

GET    /api/events/{id}/assets         -> src/Events/Assets.php
POST   /api/events/{id}/assets         -> src/Events/Assets.php
PATCH  /api/events/{id}/assets/{id}    -> src/Events/Assets.php

GET    /api/public/events/{slug}       -> src/PublicEvents.php

GET    /api/promote/events/{id}        -> src/Promote/CampaignForEvent.php
POST   /api/promote/events/{id}/campaign
GET    /api/promote/campaigns/{id}     -> src/Promote.php
PATCH  /api/promote/campaigns/{id}
GET/POST/PATCH/DELETE /api/promote/campaigns/{id}/posts[/{postId}]
POST   /api/promote/campaigns/{id}/posts/{postId}/variants/generate
GET    /api/promote/campaigns/{id}/broadcasts -> src/Promote/Broadcasts.php
POST   /api/promote/campaigns/{id}/broadcasts
GET    /api/promote/campaigns/{id}/health     -> src/Promote/PromotionHealth.php
GET    /api/promote/campaigns/{id}/analytics  -> src/Promote/Analytics.php
GET    /api/promote/credentials               -> src/Promote/Credentials.php
POST/DELETE /api/promote/credentials[/{id}]

# Public contract signing (no JWT — authenticated by one-time token hash):
GET    /api/signing/{token}                  -> src/ContractSigningEndpoint.php
POST   /api/signing/{token}/viewed
POST   /api/signing/{token}/sign
POST   /api/signing/{token}/decline

# Public event syndication feeds (no JWT):
GET    /api/feed                             -> src/Feed.php  (discovery index)
GET    /api/feed/events.ics                  -> src/Feed.php  (iCalendar)
GET    /api/feed/events.rss                  -> src/Feed.php  (RSS 2.0)
GET    /api/feed/events.json                 -> src/Feed.php  (structured JSON, CORS-open; powers mab-events-carousel)
```

Each endpoint receives a `Request`, returns a `Response`, and uses shared services such as `Database` and `Auth`. Most endpoints extend `BaseEndpoint`, which centralises the current-user lookup and the role/capability checks.

The single entrypoint `public/api/index.php` runs in one of two modes decided purely by environment: **single-tenant** (default — one `DB_*` database) or **multi-tenant SaaS** (active when `SUPER_DB_NAME` is set — the tenant database is resolved from the request hostname and injected into `Database`, leaving every endpoint class unchanged). See [Multi-Tenant / SaaS Mode](#multi-tenant--saas-mode).

## Project Layout

```text
public/
  index.html              Main staff UI
  login.html              Login page
  event.html              Public event page shell
  invite.html             Invite acceptance shell
  router.php              Local dev router for PHP built-in server
  .htaccess               Apache rewrite for /api
  api/index.php           API entrypoint
  assets/app.css          Venue-ops UI styling
  assets/app.js           Web Components client (shell, events, dashboard)
  assets/promote.js       Panic Promote Web Components (15-channel editor, broadcast modal, settings)
  uploads -> ../storage/uploads

src/
  bootstrap.php           Autoloader and shared function include
  Kernel.php              API path resolver and request dispatch
  BaseEndpoint.php        Shared endpoint base: current user + role/capability checks
  Request.php             HTTP request wrapper
  Response.php            JSON response wrapper
  Database.php            PDO wrapper (single-tenant DB_*, or an injected tenant PDO)
  Auth.php                Stateless JWT auth (HS256) — Bearer tokens, magic links, refresh tokens
  Webauthn.php            Passkey / WebAuthn registration + login
  Identity.php            Email → user resolution across primary + verified aliases
  Support.php             Small helper functions
  Dashboard.php
  Events.php
  Templates.php
  PublicEvents.php
  Invites.php
  Messages.php            In-app staff messaging (Inbox / Archive / Outbox)
  Outbox.php              Admin log of every transactional email sent (manage_users)
  StaffMembers.php        Venue staff roster
  NotificationPreferences.php  Per-user email/notification opt-ins
  Mailer.php              Sendmail/MIME mailer; mirrors staff email into the inbox
  WizardDefaults.php      Admin-configurable event-wizard defaults
  Contracts.php / Contract*.php  Deal builder, clause library, rendering, e-signature
  Promote.php             Campaign CRUD top-level endpoint
  Tenant/
    TenantContext.php     Resolve HTTP_HOST → tenant row + tenant PDO (SaaS)
    TenantProvisioner.php Create tenant DB, apply tenant migrations, make clients/<slug>/
  Database/
    Connection.php        SUPER/TENANT/PROVISION PDO factory (SaaS credential tiers)
  Http/
    SuperController.php    Super-admin console: tenant fleet + domains (/super, /api/super)
  Events/
    Tasks.php
    Blockers.php
    Lineup.php
    Schedule.php
    Assets.php
    Settlement.php
    Invites.php
  Promote/
    Analytics.php         Broadcast metrics from DB + null platform placeholders
    Broadcasts.php        Broadcast creation + adapter dispatch
    BroadcastAdapters.php Routes destination_key → platform adapter
    CampaignForEvent.php  GET/POST campaign for a given event
    CopyGenerator.php     Deterministic 15-channel variant text generator
    Credentials.php       Per-venue platform credential store
    Destinations.php      Destination list endpoint
    Posts.php             Post CRUD + variant generation
    PromotionHealth.php   20-item promotion checklist
    Adapters/
      EmailAdapter.php      Mailchimp v3 + SendGrid v3 Marketing
      EventbriteAdapter.php Eventbrite API v3
      FacebookAdapter.php   Graph API v21.0 page posts
      InstagramAdapter.php  Graph API v21.0 container → publish
      LumaAdapter.php       Luma Public API event creation
      TikTokAdapter.php     TikTok Content Posting API v2

database/
  schema.sql              App schema baseline — shared by the single-tenant DB
                          AND every tenant DB (identical code runs against both)
  migrations/             Incremental changes on top of schema.sql (NNN_*.sql),
                          applied to the single-tenant DB and/or any tenant DB
  schema-super.sql        Super-admin registry baseline (tenants, tenant_domains)
  migrations/super/       Incremental changes on top of schema-super.sql
  seed.php

scripts/
  migrate.php             Scope-aware migration runner (single / super / tenant / tenants)

storage/                  Single-tenant data root (uploads, mail, logs)
  uploads/events/

clients/                  SaaS per-tenant data roots: clients/<slug>/{assets,logs,mail,contracts}

.env.example              Environment variable template (single-tenant + SaaS)
```

## Requirements

- PHP 8.2 or newer
- MySQL 8 or newer
- PHP PDO MySQL extension

No Composer or Node install is required.

The frontend uses this pinned LARC module directly in the browser:

```html
<script type="module" src="https://cdn.jsdelivr.net/npm/@larcjs/core@3.0.1/pan.mjs"></script>
```

## Local Setup

Copy the example environment file:

```bash
cp .env.example .env
```

Set the database credentials in `.env`:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=panic_backstage
DB_PASSWORD=your-local-password
DB_NAME=panic_backstage
```

If you need to create the local MySQL user manually:

```sql
CREATE DATABASE IF NOT EXISTS panic_backstage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'panic_backstage'@'localhost' IDENTIFIED BY 'your-local-password';
CREATE USER IF NOT EXISTS 'panic_backstage'@'127.0.0.1' IDENTIFIED BY 'your-local-password';
GRANT ALL PRIVILEGES ON panic_backstage.* TO 'panic_backstage'@'localhost';
GRANT ALL PRIVILEGES ON panic_backstage.* TO 'panic_backstage'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then seed the database:

```bash
php database/seed.php
```

Seed admin login:

```text
email: admin@venue.local
password: changeme
```

## Database Schema & Migrations

There is **one** schema source of truth: [`database/schema.sql`](database/schema.sql).
It is the complete schema for a fresh database (generated by dumping the live
MySQL structure) and is the baseline that all earlier numbered migrations have
been squashed into. `php database/seed.php` applies it.

Incremental changes after that baseline live in
[`database/migrations/`](database/migrations/) as `NNN_description.sql` and are
applied with the runner:

```bash
php scripts/migrate.php          # apply every migration not yet recorded
php scripts/migrate.php status   # list applied / pending, apply nothing
```

Applied filenames are recorded in the `schema_migrations` table, so the runner
is idempotent — a fresh database built from `schema.sql` starts with zero
pending migrations, and re-running only applies what is new. Write migrations
to be safe to re-run (`IF [NOT] EXISTS`, guarded `ALTER`s), since MySQL
auto-commits DDL and a half-failed migration cannot be rolled back.

The command above targets the single-tenant `DB_*` database, but `schema.sql`
and `database/migrations/` are also what every **tenant** database is built
from in multi-tenant mode (`migrate.php tenant <db>` / `tenants`) — the same
PHP endpoint classes run unchanged against a tenant DB, so it has to stay
structurally identical to the single-tenant one. There used to be a separate,
hand-maintained `database/migrations/tenant/` baseline; it was retired because
it silently drifted from the single-tenant migrations (four columns present in
the single-tenant DB were missing from newly-provisioned tenants). Now there's
one baseline and one migrations folder for both.

The super-admin registry is a genuinely separate schema (`tenants`,
`tenant_domains`, `super_admin_users` — not app data) with its own baseline,
[`database/schema-super.sql`](database/schema-super.sql), and its own
migrations folder, `database/migrations/super/`, run with `migrate.php super`.
See [Multi-Tenant / SaaS Mode](#multi-tenant--saas-mode).

When the migration list grows long, fold it back into the baseline: dump a
fully-migrated database's structure over `database/schema.sql` and clear
`database/migrations/`. Regenerate with:

```bash
mysqldump --no-data --single-transaction --add-drop-table --routines \
  --triggers --set-charset panic_backstage > database/schema.sql
```

Then trim the mysqldump header/footer boilerplate and per-table
`-- Table structure for table` comments so the file matches the existing
minimal style (see the top of `database/schema.sql` for the three `SET`
lines it starts with), delete the now-folded migration files, and bump
"Next number" in `database/migrations/README.md`.

### Contacts / CRM import

The **Contacts** page (top-level nav, `manage_contacts` capability) holds the
ticket-buying audience used for event email. Seed it from the ticketing
provider's **Fan View** CSV export:

```bash
php scripts/import-fanview.php [path/to/export.csv]   # defaults to database/fanview.csv
```

The importer keys on the provider's User ID, so re-running UPSERTs instead of
duplicating. The raw export contains real customer PII and is **git-ignored**
(`database/fanview.csv`) — keep it local; never commit it.

## Multi-Tenant / SaaS Mode

Panic Backstage runs in one of two modes, chosen entirely by environment
configuration. **Single-tenant** is the default and original behaviour: one
database configured via the `DB_*` vars (everything above this section assumes
it). **Multi-tenant (SaaS)** mode activates the moment `SUPER_DB_NAME` is set —
each venue ("tenant") gets its own database and its own hostname, resolved per
request. Stand-alone installs are unaffected: with `SUPER_DB_NAME` blank the
`DB_*` path is taken and nothing in this section applies.

### How a request is routed (SaaS)

`public/api/index.php` is the single entrypoint. When `SUPER_DB_NAME` is set:

1. `/super` and `/api/super/*` are handled first by `Panic\Http\SuperController`
   (the super-admin console) **before** any tenant is resolved, so they work
   from any allowed host — or a dedicated `SUPER_HOST` — without a tenant DB.
2. `GET /health` returns `{"ok":true}` without touching a tenant DB.
3. Every other request is resolved to a tenant by
   `Panic\Tenant\TenantContext::resolve()`: it reads `HTTP_HOST` (or
   `X-Forwarded-Host` when `TRUST_PROXY=true`), validates it against
   `ALLOWED_HOSTS` (supports `*.example.com` wildcard prefixes), and looks it up
   in the super registry (`tenant_domains JOIN tenants WHERE domain = ? AND
   status = 'active'`). An unrecognised host returns a branded "nothing here
   yet" page (JSON for API clients); a host with no active tenant returns 404.
4. The resolved tenant's PDO is injected into `Panic\Database`, and `APP_URL` is
   rewritten to the matched tenant domain so email links, scanner URLs, and
   WebAuthn origins all point at the correct host. Every endpoint class then
   runs unchanged against the tenant's database.

`TenantContext::current()` exposes the resolved context app-wide, and
`TenantContext::clientDir()` returns the per-tenant data root —
`clients/<slug>/` in SaaS, `storage/` single-tenant — used by the mailer, asset
storage, contract PDFs, and logs.

### Database credential tiers

SaaS mode uses three credential sets (`src/Database/Connection.php`) so the
runtime app user never holds DDL privileges:

| Prefix | Used for | Falls back to |
|---|---|---|
| `SUPER_DB_*` | super-admin registry (`tenants`, `tenant_domains`) | — |
| `TENANT_DB_*` | per-tenant runtime queries (SELECT/INSERT/UPDATE/DELETE) | `SUPER_DB_*` |
| `PROVISION_DB_*` | elevated DDL (CREATE DATABASE / TABLE) for provisioning + migrations | `SUPER_DB_*` |

All SaaS variables — plus `ALLOWED_HOSTS`, `TRUST_PROXY`, and `SUPER_HOST` — are
documented in `.env.example`.

### Super-admin console

`Panic\Http\SuperController` serves both a small HTML UI (`/super`) and a JSON
API (`/api/super/*`), authenticated by a super-admin **session cookie**
(separate from the tenant apps' JWT auth). It manages the tenant fleet:

```text
GET    /api/super/venues                        public venue directory (login picker)
POST   /api/super/login | /logout               super-admin session
GET    /api/super/tenants                        list tenants + domains
POST   /api/super/tenants                        create + provision a tenant
GET    /api/super/tenants/{id}                   fetch one
PATCH  /api/super/tenants/{id}                   update
POST   /api/super/tenants/{id}/provision         re-provision the tenant DB
POST   /api/super/tenants/{id}/domains           add a domain alias
DELETE /api/super/tenants/{id}/domains/{domId}   remove a domain alias
GET    /api/super/me                             current super admin
```

`POST /api/super/tenants` body: `{ slug, name, database_name?, admin_name?, admin_email? }`.
`slug` and `name` are required; `database_name` is derived from `slug` if
omitted. `admin_name`/`admin_email` (optional but recommended) seed that
person's login into the new tenant instead of a generic placeholder — see
below. When provisioning actually seeds demo data (a brand-new, empty
tenant), the response includes a `seeded: { admin_email, admin_password,
venue_id }` object with the one-time generated password; share it with the
venue out-of-band, it is never returned again.

Creating a tenant runs `Panic\Tenant\TenantProvisioner`: it `CREATE DATABASE`s
the tenant schema (idempotent), loads `database/schema.sql` (the same baseline
the single-tenant DB uses, applied in an `IF NOT EXISTS` form so re-running it
against a live tenant — the "Re-provision" action — never drops anything),
applies + records any `database/migrations/*.sql` files not yet folded into
that baseline, creates the `clients/<slug>/{assets,logs,mail,contracts}` data
tree — kept outside `public/` so tenant files are never directly
web-accessible — and, only if the tenant has no venues yet, seeds generic
demo data (`database/seed_demo_data.php`, the same generator `seed.php` uses
for local dev) personalized with the tenant's `name` and, when supplied,
`admin_name`/`admin_email`. Re-provisioning an already-active tenant skips
seeding entirely so it never inserts a second demo venue on top of real data.

### Migrations across scopes

The runner (`scripts/migrate.php`) is scope-aware; each scope keeps its own
`schema_migrations` ledger, so every command is idempotent:

```bash
php scripts/migrate.php                    # single-tenant DB (DB_*) — legacy default
php scripts/migrate.php super              # super registry  (database/schema-super.sql + migrations/super/)
php scripts/migrate.php tenant <database>  # one tenant DB   (database/schema.sql + migrations/)
php scripts/migrate.php tenants            # every tenant in the super registry
php scripts/migrate.php status [super | tenant <database> | tenants]
```

After adding a migration, run `php scripts/migrate.php tenants` (in addition to
the legacy single-tenant run) to roll it out to every existing venue; newly
provisioned tenants pick it up automatically since `TenantProvisioner` applies
the same `database/migrations/` folder.

## Running Locally

Use PHP's built-in server:

```bash
php -S localhost:8000 -t public public/router.php
```

Open:

```text
http://localhost:8000
```

The built-in router serves static files normally and dispatches `/api/*` to `public/api/index.php`.

To smoke-test the app under a subdirectory path, set `APP_BASE_PATH`:

```bash
APP_BASE_PATH=/backstage php -S localhost:8000 -t public public/router.php
```

Then open `http://localhost:8000/backstage/`. Static assets, API calls, invite links, and uploaded media are resolved relative to the app base path instead of the server document root.

## Venue Demo Walkthrough

The seeded demo is designed for a venue operations walkthrough.

Reset and launch locally:

```bash
php database/seed.php
php -S localhost:8000 -t public public/router.php
```

Login:

```text
email: admin@venue.local
password: changeme
```

Suggested flow:

1. Open the dashboard and call out the next show, open items, empty holds, flyer needs, and settlement signals.
2. Open `Local Band Showcase`, edit event details, assign tasks, resolve the open flyer/ticketing items, approve the seeded flyer, and review the run sheet.
3. Create an invite link from the event workspace and copy it for a collaborator.
4. Use the public page button after publishing the event to show the guest-facing event page.
5. Open Templates, create a new show from `Three-Band Local Show`, then show how tasks and schedule items come preloaded.
6. Open `Legacy Benefit Night`, calculate venue net, and show the completed settlement fields.
7. Open Calendar and Pipeline to show the same events by date and update operational status.

Example public event URL after seeding:

```text
http://localhost:8000/event.html?slug=local-band-showcase
```

Endpoint smoke test against a running local or staging server:

```bash
php scripts/endpoint-smoke.php http://localhost:8000
```

The smoke script logs in as admin, loads dashboard data, creates an event from a template, updates an open item, creates and accepts a viewer invite, verifies collaborator event access, verifies unrelated event access and viewer mutation are blocked, saves settlement data, publishes the event, and verifies the public event API. It triggers real invite and magic-link emails through the Mailer (each written to `storage/mail/` and piped to the system MTA); it does not exercise multipart asset upload.

## Regenerating Help Screenshots

The in-app Help pages embed screenshots from `public/assets/help/`. Regenerate
them after a UI change with:

```bash
node scripts/screenshots.mjs
```

The script is self-contained and has **no npm dependencies** — it uses Node's
built-in `fetch`/`WebSocket` (Node 21+) and the system Chromium/Chrome. It:

1. starts a local PHP dev server if one isn't already running,
2. mints a **non-destructive** magic-link token for an admin via
   `scripts/login-link.php` (no password is set or changed),
3. drives headless Chromium over the DevTools Protocol to log in and capture the
   dashboard, an event workspace, the ticketing panel, and the contract builder, then
4. writes the PNGs to `public/assets/help/` and cleans up.

Override the defaults via env vars when your data differs — e.g.:

```bash
SHOT_EMAIL=admin@venue.local SHOT_EVENT_ID=641027 SHOT_CONTRACT_ID=10 \
  node scripts/screenshots.mjs
```

The target event must have in-house ticketing enabled for the ticketing panel to
appear, and the contract id must exist. Other knobs: `SHOT_PORT`,
`SHOT_CDP_PORT`, `SHOT_BASE`, `SHOT_OUT`, `SHOT_SCALE` (see the file header).
`dashboard.png` shows date-relative data, so it changes on every run — only
re-commit it when the layout itself has changed.

## Deployment Notes

Use `public/` as the web root.

For Apache, the included `public/.htaccess` routes API requests to `public/api/index.php`.

For Nginx/PHP-FPM, route `/api/*` to `public/api/index.php` and serve static files from `public/`. If the app is mounted under a subdirectory such as `/backstage`, route that prefix to `public/` and set `APP_BASE_PATH=/backstage` if the server does not expose the prefix through `SCRIPT_NAME`. Uploads should resolve through the `public/uploads` symlink to `storage/uploads`.

### Uploads must never execute (security)

Uploaded files are untrusted. The upload endpoint already validates each file by
its detected MIME type and writes it under a server-generated name whose
extension is derived from that type (never from the client filename), so a
disguised script can't be stored as `.php`. Enforce the same rule at the web
server as defense-in-depth so a single bug can't become remote code execution:

- **Apache:** handled automatically by `storage/uploads/.htaccess`, which turns
  the PHP engine off and denies any scriptable file in the uploads tree. This
  requires the vhost to allow those overrides (`AllowOverride` including
  `Options` and `FileInfo` — the same overrides the app's rewrites already need).
- **Nginx/PHP-FPM:** `.htaccess` is ignored, so add a location block that serves
  the uploads path statically and **never** passes it to PHP-FPM, e.g.:

  ```nginx
  location ^~ /uploads/ {
      alias /path/to/app/storage/uploads/;
      add_header X-Content-Type-Options "nosniff" always;
      types { } default_type application/octet-stream;   # don't infer active types
      location ~ \.(php|phtml|phar|phps?|pht|cgi|pl|py|sh|s?html)$ { deny all; }
      # Critically: do NOT include fastcgi_pass in this block.
  }
  ```

Keep `.env` outside version control. It is ignored by `.gitignore`.

Staging checklist:

- PHP 8.2+, PDO MySQL, and fileinfo enabled.
- `.env` uses staging database credentials and `APP_BASE_PATH` when mounted below `/`.
- `storage/uploads/events` is writable by the PHP process.
- The server can reach `https://cdn.jsdelivr.net/npm/@larcjs/core@3.0.1/pan.mjs`, or LARC should be vendored in a future offline-demo pass.
- Run `php database/seed.php` only when resetting demo data is acceptable.

## Public Event Feeds

Events with `public_visibility = 1` are exposed as machine-readable syndication
feeds — no authentication required:

```text
GET /api/feed                → JSON discovery index (lists ICS + RSS + JSON URLs)
GET /api/feed/events.ics     → iCalendar (subscribe in Google / Apple Calendar)
GET /api/feed/events.rss     → RSS 2.0 (news aggregators, "what's on" widgets)
GET /api/feed/events.json    → structured JSON (embeddable widgets — see below)
```

Optional query params for all formats: `?venue={slug}`, `?days={N}`, `?past=1`,
`?limit={N}` (default 500, max 1000). Canceled events are excluded.
`events.json` additionally sends `Access-Control-Allow-Origin: *`, since it's
meant to be `fetch()`ed by browser JS running on a different origin (the
venue's own marketing site) rather than consumed server-side like the other
two formats.

See [`docs/public-calendar-api.md`](docs/public-calendar-api.md) for the full
reference (response shapes, field-by-field notes, and how this relates to the
single-event `/public/events/{idOrSlug}` endpoint and to ticket purchasing).

### Embeddable events widget (`<mab-events-carousel>`)

`public/assets/mab-events-carousel.js` is a dependency-free web component that
fetches `GET /api/feed/events.json` and renders it as a drop-in replacement
for themab.org's hand-authored "Upcoming events" carousel — same markup, same
class names (`mab-hero-events`, `mab-cover-item`, `mab-date-block`,
`mab-outline-btn`, …), so the site's own theme CSS styles it unchanged. It
renders into light DOM (no shadow root) for exactly that reason. Category and
month filter pills are derived from the fetched data; tickets either link out
(`ticketing_mode = external`) or open a self-contained modal that iframes the
event's public page (`ticketing_mode = internal`), the same pattern
themab.org already hand-codes per-event for in-house-ticketed shows.

Zero config to embed — the component reads its own `<script src>` (the same
trick `public/assets/core.js`'s `apiUrl()`/`appUrl()` use) to find the API,
so it works unmodified whether Backstage is mounted at the domain root or
under a path prefix (e.g. this app's own `/backstage/`):

```html
<section id="events" class="mab-hero-events" aria-label="Upcoming events">
  <mab-events-carousel></mab-events-carousel>
</section>
<script src="https://panicbooking.com/backstage/assets/mab-events-carousel.js"></script>
```

Pass a `feed="..."` attribute only to point a given instance at a different
backend/venue than the one implied by the script's own URL.

See `public/mab-events-demo.html` for a live, working demo page (also serves
as the component's smoke test).

## Google Sheet Sync

Events stay in sync with a Google Sheet in both directions: an inbound cron
imports the sheet every 5 minutes, and app edits are pushed back up to the sheet
in real time (with a cron-based retry fallback). Outbound writes authenticate as
a Google service account.

See [`docs/google-sheet-sync.md`](docs/google-sheet-sync.md) for setup
(service-account key, sharing, permissions), the field/column mapping, and
troubleshooting.

## Contracts / Deal Builder

A structured contract system: capture the deal as queryable terms, auto-assemble
the document from reusable clause modules via templates with smart
(condition-based) clause selection, render to PDF, and send for electronic
signature — no DocuSign or third-party account required. Ships a clause library
and seven starter templates covering every contract type.

**Built-in e-signature workflow** (`src/ContractSigningEndpoint.php`): once a
contract is approved, staff click *Send for Signature*. Each signer receives a
personalised, time-limited one-time link by email. They review the contract HTML,
type or draw their signature, tick a consent checkbox, and sign. Status
progresses through `sent → viewed → partially_signed → signed_by_client →
fully_executed`. On full execution the system generates a tamper-evident Final
Executed PDF (contract body + signature blocks + audit certificate), stores its
SHA-256 hash, and automatically advances the linked event to *Booked*. An
optional `SIGNATURE_PROVIDER=dropbox_sign` stub is wired for future Dropbox Sign
integration (API skeleton present; HTTP calls are TODO).

The contract tables are part of the baseline `database/schema.sql`; apply
migration `017_contract_signatures.sql` for the e-signature tables, then seed the
clause library with `php database/seed_contracts.php`. See
[`docs/contracts.md`](docs/contracts.md) for the data model, signing API,
condition engine, and how to extend it. End-user help is in the app under
**Help → Contracts & deal builder** and **Help → Electronic signatures**.

## Email

Transactional email is handled by `src/Mailer.php`. It builds an RFC 5322
message and pipes it to the system `/usr/sbin/sendmail` interface (the
sendmail-compatible front end to the host MTA, e.g. Exim). Every message is
also written to `*.eml` for local inspection — `storage/mail/` single-tenant, or
`clients/<slug>/mail/` in SaaS mode — and any delivery failure is appended to
`_delivery-errors.log` rather than thrown, so a mail problem never breaks an
auth or invite flow. Every sent message is also recorded in the `outbox` table
(the admin **Outbox** / "All Email" view), and emails addressed to a staff user
are mirrored into that user's in-app [Inbox](#in-app-messaging-messages).

Email is sent for:

- Collaborator invites (with a per-invite resend action).
- Login / magic links and the admin welcome link.
- Event status changes, private-event inquiries, and Intake-Complete hand-offs.
- Contract send / signed / fully-executed / voided notifications.
- Ticket purchase confirmations and comps.
- Staff messages composed in the in-app Messages center.

Relevant environment variables:

```text
APP_URL=https://panicbooking.com/backstage   # base used to build invite/login links
MAIL_FROM_ADDRESS=support@panicbooking.com
MAIL_FROM_NAME=Backstage
MAIL_BCC=                                     # optional, comma/semicolon-separated blind copies
```

Creating an invite sends the email by default. The Invites form exposes a
**Send invitation email** checkbox (checked by default); unchecking it generates
the invite link without sending, so an admin can copy and share it manually. The
`POST /api/events/{id}/invites` endpoint mirrors this with a `send_email` flag.

Deliverability to external inboxes depends on the host MTA plus SPF/DKIM/DMARC
for the sending domain. Inspect `storage/mail/` (or `clients/<slug>/mail/`) and
the host MTA log to confirm a given message was generated and accepted.

## In-App Messaging (Messages)

The **Messages** nav group gives staff an in-app view of the notifications and
messages they would otherwise only see by email, reusing the Outbox split-pane
interface. It has three boxes:

- **Inbox** — messages addressed to you: staff-to-staff messages plus system
  notifications. Any outgoing email whose recipient matches a staff user account
  is mirrored here by `Mailer` (pure account-auth templates like `magic-link`
  and `confirm-email` are excluded). Tracks read/unread state.
- **Archive** — inbox messages you've filed away.
- **Outbox** — messages you've sent. Composing or replying creates the message
  and also emails the recipient.

Backed by the `messages` table (migration `019_messages.sql` single-tenant /
`tenant/007_messages.sql`). A single row serves two views — `recipient_user_id`
drives the Inbox, `sender_user_id` drives the Outbox. The frontend lives in
`public/assets/messages.js`; the shell shows an unread badge on the Inbox link.

```text
GET    /api/messages?box=inbox|archive|sent     list (?q= &page= &limit=)
GET    /api/messages/{id}                        one message (marks it read)
POST   /api/messages                             compose / reply (+ emails the recipient)
POST   /api/messages/{id}/archive | /unarchive   file / restore
POST   /api/messages/{id}/read | /unread         toggle read state
GET    /api/messages/recipients                  addressable users
GET    /api/messages/unread-count                inbox unread count (nav badge)
```

The admin **Outbox** (`#outbox`, labelled "All Email", gated by `manage_users`)
remains the global log of every transactional email the system has sent.

## Core Workflow

- `/` shows the staff dashboard after login.
- Venue admins can create events from templates and see all events.
- Each event workspace manages overview, lineup, tasks, open items, run sheet, assets, settlement, and activity.
- Public event pages are loaded by `public/event.html?slug=event-slug`.
- Public event API responses only include events with `public_visibility` enabled.
- Web Components publish PAN-compatible topics such as `app.route.changed`, `events.loaded`, `event.saved`, `event.assetUploaded`, `event.openItemResolved`, `event.publicationChanged`, `toast.show`, and `api.error`.

## Roles And Collaborator Access

Server-side authorization is enforced by global user role plus event ownership or `event_collaborators` rows. Venue admins retain full access to every event, template, invite, asset, settlement, and user list. Non-admin users only see events they own or events where they have a collaborator row.

Event collaborator roles:

- `event_owner`: full access to assigned/collaborating events except global user or template administration.
- `promoter`: read event data, edit lineup, tasks, schedule, and open items, and view/copy the public page. Settlement is hidden.
- `band` / `artist`: read the collaborating event, upload assets, and view tasks assigned directly to them.
- `designer`: read the collaborating event and upload/manage event assets. Settlement is hidden.
- `staff`: read the collaborating event and edit tasks, schedule, and open items.
- `viewer`: read-only access to the collaborating event.

Creating an invite emails the recipient an accept link by default, and each
pending invite can be re-sent. The link is also shown in the UI to copy and
share manually (see [Email](#email)).

## Multi-Email Identity

A login account can own more than one email address. Each user has a primary
`users.email` plus a set of verified secondary addresses (aliases) stored as
JSON in `users.alt_emails`. This schema is part of the baseline
`database/schema.sql`: the `alt_emails` column, its multi-valued uniqueness
index, the `email_verification_tokens` table, and the `user_merges` audit table.

- **Identity resolution.** All authentication-time email lookups route through
  `Panic\Identity::resolveUserByEmail()`. It tries the exact `users.email`
  first — so signing in with a primary email behaves exactly as before — and
  only then falls back to a user whose `alt_emails` contains the address with a
  non-null `verified_at`. A verified alias is strictly an *added* sign-in path,
  never a replacement. Magic-link, password, and magic-link-request flows all
  resolve through it, and magic-link verify resolves before auto-creating a
  viewer so an alias never spawns a duplicate account.

- **Aliases (add / verify / resend / remove / promote).** Admins (or a user
  acting on their own account) manage addresses from the user Edit modal
  (`<pb-user-emails>` in `public/assets/user-emails.js`). Adding an alias mints
  a 7-day hashed verification token (`email_verification_tokens`) and emails a
  confirmation link of the form `{APP_URL}/login.html?verify_email=<token>`.
  `login.html` handles that parameter by calling `POST /api/auth/verify-email`
  (public, no JWT) and showing an "email confirmed" state; only verified
  aliases may authenticate. Endpoints:
  - `POST   /api/users/{id}/emails`         — add an unverified alias
  - `POST   /api/users/{id}/emails/resend`  — re-mint and re-send the link
  - `DELETE /api/users/{id}/emails`         — remove an alias (never the primary)
  - `POST   /api/users/{id}/emails/primary` — promote a verified alias to primary
    (the old primary is demoted into `alt_emails` as verified)
  - `GET|POST /api/auth/verify-email`       — confirm an alias from its token

  Every address stays globally unique across all primaries and aliases via
  `Identity::emailIsTaken()`.

- **Duplicate detection + account merge.** The Admin ▸ Duplicates tab
  (`<pb-user-duplicates>`) lists likely duplicate account pairs surfaced by
  three signals: matching normalized name, a shared phone, and a shared
  gmail-canonical address (dots/`+suffix` normalized for *detection only* — never
  for login matching). Merging is atomic: every `REFERENCES users(id)` row is
  repointed from the loser to the survivor, passkeys move over, refresh tokens
  are revoked, the loser's primary and aliases fold into the survivor's
  `alt_emails` as verified entries, the loser is hard-deleted, and a
  `user_merges` audit row records the per-table counts, folded emails, role
  decision, and signals. Endpoints (both gated on `manage_users`):
  - `GET  /api/users/duplicates` — suggested pairs, strongest match first
  - `POST /api/users/merge`      — `{survivor_id, loser_id, confirm:true,
    override_role?}`; a cross-role merge returns `409` unless `override_role`
    is set.

## Collaborator Demo Flow

After logging in as the seeded admin:

1. Open an event workspace and select the Invites tab.
2. Create a viewer, staff, designer, promoter, artist, or band invite. Leave **Send invitation email** checked to email the link, or uncheck it to just generate a copyable link.
3. Use the emailed link, or copy the generated invite link, and open it in a separate browser session or private window.
4. Accept the invite with a name and password.
5. Confirm the collaborator can open that event from the dashboard or direct event URL.
6. Confirm unrelated events are not listed and direct access to unrelated event IDs is rejected.
7. For a viewer invite, confirm event detail fields, settlement, invite creation, and destructive asset controls are unavailable.
8. For a designer invite, manually verify asset upload/manage controls on that event. The smoke script does not exercise multipart uploads.

## Verification

Useful checks:

```bash
find src public database scripts -name '*.php' -print -exec php -l {} \;
node --check public/assets/app.js
php database/seed.php
php -S localhost:8000 -t public public/router.php
php scripts/endpoint-smoke.php http://localhost:8000
```

`node --check` is optional and only validates the plain JavaScript file. The app does not require Node to run.

### Test suites

Three zero-dependency suites (no npm, no build):

```bash
# Hermetic PHP unit tests (no DB, no server) — pure logic, JWT claims, encryption, parsing:
./tests/run-php-tests.sh
#   RUN_DB_TESTS=1 ./tests/run-php-tests.sh   also runs the MySQL-backed ones
#                                              (point .env at a throwaway DB first)

# API integration tests (curl + php) against a running app + seeded DB:
./run-tests.sh
#   TEST_BASE_URL=... TEST_EMAIL=... ./run-tests.sh   to target something other
#                                                      than the local dev install

# UI tests — headless Chromium over the DevTools Protocol against the live DOM:
node tests/ui/run.mjs
```

The UI runner starts a local PHP dev server, logs in via a non-destructive
magic-link token, and asserts client-side behaviour (panel reveals, the
ticketing mode toggle, the Doors/Show/End autofill, …) without persisting
changes. It shares its browser/CDP/login machinery with
`scripts/screenshots.mjs`. See [`tests/ui/README.md`](tests/ui/README.md).

### CI

`.github/workflows/ci.yml` runs on every push to `main` and every pull
request, four jobs: a `php -l` sweep of every source file; the hermetic PHP
suite; the API integration suite; and the headless-Chromium UI suite — the
latter two each against their own MariaDB service container, seeded fresh
every run (`php database/seed.php && php scripts/migrate.php`,
`admin@venue.local` / `changeme`). `seed_demo_data()` returns
`primary_event_id` (the richly-populated "Local Band Showcase" demo event —
`database/seed.php` also prints it as `UI_EVENT_ID=<id>`), which the UI job
captures and passes to `tests/ui/run.mjs` so event-scoped tests run for real
instead of skipping. ubuntu-latest ships Chrome preinstalled; the job fails
fast with a clear message if a future runner image ever drops it.

## Ticketing And Payments

Events can sell tickets directly ("internal" ticketing mode) through a pluggable
payment layer with **Stripe** and **Square** providers. There is no vendored SDK —
both providers talk to their HTTP APIs over raw cURL with zero Composer
dependencies.

- **Provider configuration (admin):** Admin → Payments (`#admin-payments`) selects
  the active provider and currency (the `pb-payment-settings` panel, gated by
  `manage_users`). API keys live only in `.env` and are never returned by the API.
- **Per-event ticketing (event workspace):** the **Ticketing** tab
  (`pb-ticketing-admin`, gated by the new `manage_ticketing` event capability —
  granted to `venue_admin` and `event_owner` only) configures tiers, inventory,
  sales windows, comps, refunds, and door-scanner links.
- **Public purchase:** the public event page mounts `<pb-ticket-purchase>` which
  lists on-sale tiers, reserves a 15-minute inventory hold, and redirects the
  buyer to the provider's hosted checkout.
  - `GET  /api/public/tickets/{eventId}` — on-sale tiers + live availability
  - `POST /api/public/tickets/{eventId}/checkout` — create a checkout session
- **Webhooks:** providers confirm payment via signed webhooks
  (`POST /api/webhooks/stripe`, `POST /api/webhooks/square`). These are public
  routes authenticated by signature verification (HMAC), not JWT; fulfillment is
  idempotent so retries never double-issue or double-email.
- **Tickets and QR:** each fulfilled unit gets a one-time plaintext token (only
  its `sha256` hash is stored). The holder's ticket page is `GET /t/{token}`, and
  the scannable QR is generated on the fly by a from-scratch encoder at
  `GET /assets/qr.svg?text=<token>` (byte mode, ECC level M; verified scannable
  with OpenCV/ZBar).
- **Door scanner:** `public/scanner.html` is a mobile camera scanner. It posts the
  decoded token to `POST /api/scan/redeem` using a scanner-link token (not a JWT);
  redemption is an atomic single-row flip with a mandatory `ticket_scans` audit
  row. Scanner-link management lives under
  `/api/events/{id}/scanner-links[/{linkId}]` (JWT + `manage_ticketing`).

Required `.env` keys (see `.env.example`): `APP_URL`, `STRIPE_SECRET_KEY`,
`STRIPE_WEBHOOK_SECRET`, `SQUARE_ACCESS_TOKEN`, `SQUARE_LOCATION_ID`,
`SQUARE_WEBHOOK_SIGNATURE_KEY`, `SQUARE_ENV`, `SQUARE_WEBHOOK_URL`. The ticketing
schema is part of the baseline `database/schema.sql`.

See [`docs/ticketing.md`](docs/ticketing.md) for the full data model, API,
payment/fulfillment flow, door-scanner details, and an operating checklist.

## Panic Promote

Panic Promote is a campaign command center for event promotion. Each event can have one campaign. A campaign organises marketing posts, channel-specific post variants, broadcast destinations, broadcast history, a 20-item promotion-health checklist, and real broadcast analytics.

### Concepts

- **Campaign** — one per event; inherits event title, date, times, venue, and ticket URL. Optional `goal_tickets` override.
- **Post** — master copy belonging to a campaign; statuses: `draft`, `approved`, `scheduled`, `sent`, `archived`.
- **Post variant** — platform-specific version of a post for one of 15 channels (see [Channels](#channels) below).
- **Destination** — a place a post can be sent or tracked, grouped into Direct Posts, Event Platforms, Editorial Submissions, and Email (17 destinations; see [Destinations](#destinations)).
- **Broadcast** — records an attempt to send one post to one or more destinations; per-destination results are stored as `promote_broadcast_results` rows.
- **Promotion Health** — a computed 20-item checklist (`score`, `complete`, `total`, `items[]`) derived from event visibility, approved assets, approved variants, broadcast history, and scheduled reminders.
- **Analytics** — real broadcast metrics from the DB (destinations reached, live listings, manual to-dos, failures); platform-specific metrics (ticket sales, email opens, RSVPs) are `null` pending future API read-back integrations.

### Endpoint Reference

```text
GET    /api/promote/campaigns                                     → list all campaigns
POST   /api/promote/campaigns                                     → create a campaign directly
GET    /api/promote/campaigns/{campaignId}                        → fetch one campaign + posts + health + analytics
PATCH  /api/promote/campaigns/{campaignId}                        → update campaign fields

GET    /api/promote/events/{eventId}                              → fetch or describe campaign for an event
POST   /api/promote/events/{eventId}/campaign                     → create campaign for event (idempotent)

GET    /api/promote/campaigns/{campaignId}/posts                  → list posts
POST   /api/promote/campaigns/{campaignId}/posts                  → create post
GET    /api/promote/campaigns/{campaignId}/posts/{postId}         → fetch one post + variants
PATCH  /api/promote/campaigns/{campaignId}/posts/{postId}         → update post
DELETE /api/promote/campaigns/{campaignId}/posts/{postId}         → delete post

POST   /api/promote/campaigns/{campaignId}/posts/{postId}/variants/generate  → generate all 15 variants (local, no AI)
PATCH  /api/promote/campaigns/{campaignId}/posts/{postId}/variants/{variantId} → update a single variant

GET    /api/promote/campaigns/{campaignId}/destinations           → list destinations with current status
GET    /api/promote/campaigns/{campaignId}/health                 → promotion-health score + 20-item checklist
GET    /api/promote/campaigns/{campaignId}/analytics              → real broadcast metrics + null platform placeholders

GET    /api/promote/campaigns/{campaignId}/broadcasts             → list broadcasts + results
POST   /api/promote/campaigns/{campaignId}/broadcasts             → create broadcast (fires real adapters)
GET    /api/promote/campaigns/{campaignId}/broadcasts/{broadcastId} → fetch one broadcast + results

GET    /api/promote/credentials                                   → list per-venue platform credentials
POST   /api/promote/credentials                                   → save / update a credential
DELETE /api/promote/credentials/{id}                             → remove a credential
```

All routes require an authenticated user. Access is gated the same way as event sub-resources: venue admins have full access; event owners and collaborators can read and create; `viewer` collaborators get 403 on mutating requests.

### Channels

The copy generator produces a tailored variant for each of these 15 channels when `POST …/variants/generate` is called (deterministic local text — no AI or third-party API):

| Channel | Type | Notes |
|---|---|---|
| `instagram` | Social | Caption ≤ 2,200 chars, hashtags, "link in bio" reminder |
| `facebook` | Social | Up to 63,206 chars; warn if > 477 (truncated in feed) |
| `tiktok` | Social | Short punchy caption, portrait-crop note |
| `email` | Email | Subject + body for Mailchimp / SendGrid list blast |
| `email_adhoc` | Email | Same format, intended for manual BCC send to custom recipients |
| `eventbrite` | Platform | Event description + metadata |
| `luma` | Platform | Markdown description + venue address JSON |
| `funcheap` | Editorial | Calendar listing ≤ 500 chars |
| `foopee` | Editorial | Concise Bay Area calendar copy |
| `press` | Editorial | FOR IMMEDIATE RELEASE press blurb |
| `sf_chronicle` | Editorial | Datebook pitch with media contact line |
| `sf_station` | Editorial | Listing ≤ 400 chars |
| `dothebay` | Editorial | Listing ≤ 500 chars, "Music" category note |
| `songkick` | Platform | Artist-centric show format |
| `jambase` | Platform | Live music database format |

### Destinations

17 destinations are seeded across four groups. Real API adapters are implemented for the six marked **live**; the rest produce the appropriate stub status with no external calls.

| Key | Group | Default status | Adapter |
|---|---|---|---|
| `facebook_page` | Direct Posts | `needs_auth` | **live** — Graph API v21.0, `/photos` or `/feed` |
| `instagram` | Direct Posts | `needs_auth` | **live** — Graph API v21.0, container → publish flow |
| `tiktok` | Direct Posts | `needs_auth` | **live** — Content Posting API v2, photo PULL_FROM_URL |
| `eventbrite` | Event Platforms | `needs_auth` | **live** — Eventbrite API v3, creates listing |
| `luma` | Event Platforms | `needs_auth` | **live** — Luma Public API, creates event |
| `bandsintown` | Event Platforms | `manual_submission` | stub → `manual_required` |
| `songkick` | Event Platforms | `manual_submission` | stub → `manual_required` |
| `jambase` | Event Platforms | `manual_submission` | stub → `manual_required` |
| `funcheap` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `foopee` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `press_list` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `sf_chronicle` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `sf_station` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `dothebay` | Editorial Submissions | `manual_submission` | stub → `manual_required` |
| `email_general` | Email | `connected` | **live** — Mailchimp v3 or SendGrid v3 Marketing |
| `email_press` | Email | `connected` | **live** — same email adapter, separate list/credentials |
| `email_adhoc` | Email | `manual_submission` | stub → `manual_required` (copy-and-BCC workflow) |

#### Broadcast result statuses

| Status | Meaning |
|---|---|
| `sent` | Real adapter call succeeded; `external_url` may be set |
| `queued` | Adapter accepted a scheduled send |
| `needs_auth` | No credentials configured, or adapter returned an auth error |
| `manual_required` | Destination is `manual_submission`; copy was generated for staff to send |
| `failed` | Adapter attempted a call and received an error |
| `skipped` | Destination was `disabled` at broadcast time |

### Platform Credentials

Platform credentials are stored per-venue in the `promote_credentials` table (never in `.env`). Connect platforms from the staff app under **Panic Promote → Settings** (`#promote-settings`).

Required fields per platform:

| Platform | Required | Optional |
|---|---|---|
| Facebook Page | Page Access Token, Page ID | — |
| Instagram | User Access Token, IG Business Account ID | — |
| TikTok | OAuth Access Token | Privacy Level (default `PUBLIC_TO_EVERYONE`) |
| Eventbrite | Private API Key, Organizer ID | Eventbrite Venue ID |
| Luma | API Key | — |
| Email (general / press) | Provider (`mailchimp` or `sendgrid`), API Key, List / Audience ID, From Name, From Email | Sender ID (SendGrid only) |

Notes:
- Facebook and Instagram share a Graph API app; the access token is a **Page token** (Facebook) or a **User token with `instagram_content_publish` scope** (Instagram).
- TikTok access tokens expire; re-authenticate in Settings if a broadcast fails with `access_token_expired`.
- Luma cover images cannot be served from external URLs; after a Luma broadcast, upload the flyer manually in the Luma dashboard.
- Mailchimp datacenter is parsed from the API key suffix (e.g. `-us21`).
- SendGrid uses a single-send (Marketing Campaigns) flow; `sender_id` must match a verified Sender Identity.

### Promotion Health

Health is computed from 20 deterministic checklist items. Items with `severity: 'info'` are informational and do not heavily penalise the score when missing.

| # | Key | Checks |
|---|---|---|
| 1 | `panic_page_published` | `events.public_visibility = 1` |
| 2 | `approved_flyer` | Approved flyer asset exists |
| 3 | `instagram_approved` | Approved Instagram variant exists |
| 4 | `facebook_approved` | Approved Facebook variant exists |
| 5 | `eventbrite_listing` | Any Eventbrite broadcast result exists |
| 6 | `luma_listing` | Any Luma broadcast result exists |
| 7 | `funcheap_submitted` | Any Funcheap broadcast result exists |
| 8 | `foopee_submitted` | Any Foopee broadcast result exists |
| 9 | `press_email_prepared` | Approved press variant exists |
| 10 | `email_blast` | Any `email_general` broadcast result exists |
| 11 | `posts_created` | At least one post exists |
| 12 | `goal_set` | `goal_tickets` is set on the campaign |
| 13 | `sf_chronicle_submitted` | Any SF Chronicle broadcast result exists |
| 14 | `sf_station_submitted` | Any SF Station broadcast result exists |
| 15 | `dothebay_submitted` | Any DoTheBay broadcast result exists |
| 16 | `songkick_submitted` | Any SongKick broadcast result exists |
| 17 | `jambase_submitted` | Any JamBase broadcast result exists |
| 18 | `email_adhoc_sent` | Any `email_adhoc` broadcast result exists |
| 19 | `day_before_reminder` | Any scheduled broadcast within 36 h of the show date |
| 20 | `band_assets_collected` | Approved `band_photo` or `logo` asset exists |

### Database

Apply all migrations with:

```bash
php scripts/migrate.php
```

Promote-specific migrations:

| File | What it adds |
|---|---|
| `006_panic_promote.sql` | Core tables + seeds 11 original destinations |
| `007_promote_credentials.sql` | `promote_credentials` per-venue credential store |
| `008_add_missing_destinations.sql` | Adds `sf_chronicle`, `sf_station`, `dothebay`, `songkick`, `jambase` |
| `009_add_email_adhoc_destination.sql` | Adds `email_adhoc` (17th destination) |

Tables:

| Table | Purpose |
|---|---|
| `promote_campaigns` | One campaign per event |
| `promote_posts` | Marketing posts belonging to a campaign |
| `promote_post_variants` | Per-channel variant of a post (15 channels) |
| `promote_destinations` | Broadcast target registry (seeded, 17 rows) |
| `promote_broadcasts` | One broadcast attempt per post/send action |
| `promote_broadcast_results` | Per-destination result row within a broadcast |
| `promote_credentials` | Platform API keys and config, per venue |

### Local Smoke Test

Start the server:

```bash
php -S localhost:8000 -t public public/router.php
```

Run the promote smoke script:

```bash
php scripts/promote-smoke.php http://localhost:8000 storage/mail
```

The script logs in via magic-link, creates an event and campaign, creates a post, generates all 15 variants, fires a broadcast across all four destination groups, verifies per-destination result statuses, fetches health and analytics, asserts all analytics keys are present, verifies a viewer gets 403 on mutations, and asserts 401 for unauthenticated requests.

> If you run both `endpoint-smoke.php` and `promote-smoke.php` back-to-back, wait about 2 seconds between them — both scripts mint magic-link tokens and a rapid second request can hit a token-table race.

### Known Limitations / Future Work

- **Platform analytics read-back** — Eventbrite ticket sales, Mailchimp/SendGrid email opens/clicks, and Luma RSVPs are returned as `null`. Wiring these up requires scheduled API polling or webhook handlers per platform.
- **Scheduled broadcast runner** — broadcasts created with `send_mode = 'scheduled'` are stored as `queued` but no cron/queue worker exists yet to process them at the scheduled time.
- **Luma cover image** — the Luma API only accepts images hosted on `images.lumacdn.com`; upload the event flyer manually in the Luma dashboard after an automated broadcast creates the listing.
- **TikTok token refresh** — TikTok access tokens expire; re-authenticate in Promote Settings when a broadcast fails with `access_token_expired`.

### Frontend

The Promote workspace is loaded by `public/assets/promote.js` (native Web Components, no framework). Navigation routes:

- `#promote` — campaigns list, showing upcoming events with health score and days out.
- `#promote-event-{id}` — campaign overview: hero, promotion health, posts, assets, analytics, and broadcast modal.
- `#promote-settings` — platform credentials manager; connect and test each destination.

A **Panic Promote** nav item is added to the staff shell navigation alongside the existing top-level sections.

## MVP Limitations

- Email delivery relies on the host MTA via `/usr/sbin/sendmail`; there is no queue, retry, or bounce handling beyond what the MTA provides.
- Uploads use local disk storage under `storage/uploads/events/:eventId`.
- The frontend is intentionally browser-native Web Components, optimized for hackability over framework features.
