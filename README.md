# Panic Backstage

Panic Backstage is a dependency-light internal venue-operations MVP for moving shows from idea to confirmed, announced, ticketed, staffed, performed, and settled.

The current implementation is PHP-first and API-first:

- no Composer runtime dependencies
- no npm or frontend build step
- PHP 8 with a small custom endpoint kernel
- PDO prepared statements for MySQL
- native PHP sessions, password hashing, uploads, and CSRF tokens
- static HTML/CSS/vanilla JS pages that call JSON endpoints under `/api`

## Layout

```text
public/
  index.html
  login.html
  event.html
  invite.html
  api/index.php
  assets/app.css
  assets/app.js

src/
  Kernel.php
  Request.php
  Response.php
  Database.php
  Auth.php
  Dashboard.php
  Events.php
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
```

## Setup

```bash
cp .env.example .env
```

Update `.env` with MySQL credentials.

Create and seed the database:

```bash
php database/seed.php
```

Seed admin login:

```text
email: admin@mabuhay.local
password: changeme
```

## Development

Use PHP's built-in server for local development:

```bash
php -S localhost:8000 -t public public/router.php
```

Open `http://localhost:8000`.

For PHP-FPM/Nginx or Apache, serve `public/` as the web root and route `/api/*` to `public/api/index.php`.

## API Routing

The custom kernel maps constrained API paths to endpoint classes:

```text
GET /api/dashboard              -> src/Dashboard.php
GET /api/events                 -> src/Events.php
GET /api/events/{id}            -> src/Events.php
POST /api/events/{id}/tasks     -> src/Events/Tasks.php
PATCH /api/events/{id}/assets   -> src/Events/Assets.php
GET /api/public/events/{slug}   -> src/PublicEvents.php
```

Endpoint classes return JSON only. Static pages render with vanilla JavaScript.

## Core Workflow

- `/` shows the operations dashboard after login.
- Events can be created from scratch or from templates.
- Each event workspace manages overview, lineup, tasks, blockers, run sheet, assets, settlement, and activity.
- Public event pages are served by `public/event.html?slug=event-slug` and only load visible events from the public API.

## MVP Limitations

- Stripe remains represented by ticket fields only.
- Permissions are still intentionally basic.
- Invite links are placeholders and do not send email.
- Uploads use local disk storage under `storage/uploads/events/:eventId`.
- The frontend is intentionally small vanilla JS; it is functional, not a full UI framework.
