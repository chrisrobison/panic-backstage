# Mabuhay Show Pipeline

Mabuhay Show Pipeline is an internal venue-operations MVP for moving shows from idea to confirmed, announced, ticketed, staffed, performed, and settled.

It is intentionally server-rendered and hackable: Node.js, Express, MySQL, EJS, vanilla JavaScript, session auth, bcrypt, and local file uploads.

## Setup

```bash
npm install
cp .env.example .env
```

Create a MySQL database user that can create/use the configured database, then update `.env` as needed.

## Database

Load the schema manually:

```bash
mysql -u root -p < schema.sql
```

Or run the seed script, which applies `schema.sql`, truncates MVP tables, and inserts sample data:

```bash
npm run seed
```

Seed admin login:

```text
email: admin@mabuhay.local
password: changeme
```

## Development

```bash
npm run dev
```

Open `http://localhost:3000`.

## Core Workflow

- Use `/dashboard` to see the next 14 days, empty/hold nights, blockers, missing flyers, ready-to-announce shows, published events, and completed-but-unsettled events.
- Create shows from `/events/new` or from default templates at `/templates`.
- Use each event workspace to manage overview details, lineup, tasks, blockers, run sheet, assets, public page status, settlement, activity, and collaborator invites.
- Public pages live at `/e/:slug` and only render when `public_visibility` is enabled.

## Environment Variables

- `PORT`
- `NODE_ENV`
- `SESSION_SECRET`
- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

## MVP Limitations

- Stripe is represented by ticket fields only; no live Stripe API integration is included.
- RBAC is intentionally basic and route-level. The service structure is ready for stricter per-field permissions.
- File uploads use local disk storage under `/uploads/events/:eventId/`.
- Invite links are placeholder magic links. They create or associate a user by email, but do not send email.
- CSRF protection is enabled for standard form posts.
