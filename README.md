# Panic Backstage

Panic Backstage helps venues track every show from hold to settlement, including lineup, schedule, artwork, ticketing, open items, and event readiness.

The app is intentionally boring to run:

- PHP 8, served from `public/`
- no Composer runtime dependencies
- no npm, bundler, or frontend build step
- MySQL with native PDO prepared statements
- native PHP sessions, password hashing, CSRF tokens, and file uploads
- static HTML/CSS/native Web Components that call JSON endpoints under `/api`
- LARC/PAN loaded from a pinned CDN module for component coordination

## Current Architecture

Panic Backstage is API-first. PHP endpoint classes return JSON only; HTML pages are static and use browser-native Web Components, `fetch()`, and LARC/PAN topic events to load and mutate data.

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
```

Each endpoint receives a `Request`, returns a `Response`, and uses shared services such as `Database` and `Auth`.

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
  assets/app.js           Web Components client
  uploads -> ../storage/uploads

src/
  bootstrap.php           Autoloader and shared function include
  Kernel.php              API path resolver and request dispatch
  Request.php             HTTP request wrapper
  Response.php            JSON response wrapper
  Database.php            PDO wrapper
  Auth.php                Native session auth and CSRF
  Support.php             Small helper functions
  Dashboard.php
  Events.php
  Templates.php
  PublicEvents.php
  Invites.php
  Events/
    Tasks.php
    Blockers.php
    Lineup.php
    Schedule.php
    Assets.php
    Settlement.php
    Invites.php

database/
  schema.sql
  seed.php

storage/
  uploads/events/

schema.sql                Root copy of database schema
.env.example              Environment variable template
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
email: admin@mabuhay.local
password: changeme
```

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

The seeded Mabuhay Gardens demo is designed for a venue operations walkthrough.

Reset and launch locally:

```bash
php database/seed.php
php -S localhost:8000 -t public public/router.php
```

Login:

```text
email: admin@mabuhay.local
password: changeme
```

Suggested flow:

1. Open the dashboard and call out the next show, open items, empty holds, flyer needs, and settlement signals.
2. Open `Local Band Showcase`, resolve the open flyer/ticketing items, approve the seeded flyer, and review the run sheet.
3. Use the public page button after publishing the event to show the guest-facing event page.
4. Open Templates, create a new show from `Three-Band Local Show`, then show how tasks and schedule items come preloaded.
5. Open `Legacy Benefit Night` and show the completed settlement fields.
6. Open Calendar and Pipeline to show the same events by date and operational status.

Example public event URL after seeding:

```text
http://localhost:8000/event.html?slug=local-band-showcase
```

Endpoint smoke test against a running local or staging server:

```bash
php scripts/endpoint-smoke.php http://localhost:8000
```

The smoke script logs in, loads dashboard data, creates an event from a template, updates an open item, saves settlement data, publishes the event, and verifies the public event API.

## Deployment Notes

Use `public/` as the web root.

For Apache, the included `public/.htaccess` routes API requests to `public/api/index.php`.

For Nginx/PHP-FPM, route `/api/*` to `public/api/index.php` and serve static files from `public/`. If the app is mounted under a subdirectory such as `/backstage`, route that prefix to `public/` and set `APP_BASE_PATH=/backstage` if the server does not expose the prefix through `SCRIPT_NAME`. Uploads should resolve through the `public/uploads` symlink to `storage/uploads`.

Keep `.env` outside version control. It is ignored by `.gitignore`.

Staging checklist:

- PHP 8.2+, PDO MySQL, and fileinfo enabled.
- `.env` uses staging database credentials and `APP_BASE_PATH` when mounted below `/`.
- `storage/uploads/events` is writable by the PHP process.
- The server can reach `https://cdn.jsdelivr.net/npm/@larcjs/core@3.0.1/pan.mjs`, or LARC should be vendored in a future offline-demo pass.
- Run `php database/seed.php` only when resetting demo data is acceptable.

## Core Workflow

- `/` shows the staff dashboard after login.
- Staff can create events from scratch or from templates.
- Each event workspace manages overview, lineup, tasks, open items, run sheet, assets, settlement, and activity.
- Public event pages are loaded by `public/event.html?slug=event-slug`.
- Public event API responses only include events with `public_visibility` enabled.
- Web Components publish PAN-compatible topics such as `app.route.changed`, `events.loaded`, `event.saved`, `event.assetUploaded`, `event.openItemResolved`, `event.publicationChanged`, `toast.show`, and `api.error`.

## Verification

Useful checks:

```bash
find src public database -name '*.php' -print -exec php -l {} \;
node --check public/assets/app.js
php database/seed.php
php -S localhost:8000 -t public public/router.php
php scripts/endpoint-smoke.php http://localhost:8000
```

`node --check` is optional and only validates the plain JavaScript file. The app does not require Node to run.

## MVP Limitations

- Stripe is represented by ticket fields only.
- Permissions are basic and should be tightened before exposing to untrusted users.
- Invite links are placeholders and do not send email.
- Uploads use local disk storage under `storage/uploads/events/:eventId`.
- The frontend is intentionally browser-native Web Components, optimized for hackability over framework features.
