# Panic Backstage

Panic Backstage is an internal venue-operations tool for moving live events from idea to confirmed, announced, ticketed, staffed, performed, and settled.

The app is intentionally boring to run:

- PHP 8, served from `public/`
- no Composer runtime dependencies
- no npm, bundler, or frontend build step
- MySQL with native PDO prepared statements
- native PHP sessions, password hashing, CSRF tokens, and file uploads
- static HTML/CSS/vanilla JS pages that call JSON endpoints under `/api`

## Current Architecture

Panic Backstage is API-first. PHP endpoint classes return JSON only; HTML pages are static and use `fetch()` to load and mutate data.

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
  assets/app.js           Vanilla JS client
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

## Deployment Notes

Use `public/` as the web root.

For Apache, the included `public/.htaccess` routes API requests to `public/api/index.php`.

For Nginx/PHP-FPM, route `/api/*` to `public/api/index.php` and serve static files from `public/`. Uploads should resolve through the `public/uploads` symlink to `storage/uploads`.

Keep `.env` outside version control. It is ignored by `.gitignore`.

## Core Workflow

- `/` shows the staff dashboard after login.
- Staff can create events from scratch or from templates.
- Each event workspace manages overview, lineup, tasks, blockers, run sheet, assets, settlement, and activity.
- Public event pages are loaded by `public/event.html?slug=event-slug`.
- Public event API responses only include events with `public_visibility` enabled.

## Verification

Useful checks:

```bash
find src public database -name '*.php' -print -exec php -l {} \;
node --check public/assets/app.js
php database/seed.php
php -S localhost:8000 -t public public/router.php
```

`node --check` is optional and only validates the plain JavaScript file. The app does not require Node to run.

## MVP Limitations

- Stripe is represented by ticket fields only.
- Permissions are basic and should be tightened before exposing to untrusted users.
- Invite links are placeholders and do not send email.
- Uploads use local disk storage under `storage/uploads/events/:eventId`.
- The frontend is intentionally simple vanilla JS, optimized for hackability over framework features.
