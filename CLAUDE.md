# Panic Backstage — orientation map

Read this first in any fresh session. It's a map, not the territory — it
points at the README, `docs/`, and the project's Claude memory files rather
than duplicating them. Keep it short; if you're tempted to paste in a large
block of detail, it probably belongs in one of those instead.

## What this is

Venue operating system for Mabuhay Gardens: inquiry → qualified booking →
event → contract/payment → execution → settlement, plus ticketing, CRM,
promotion (Panic Promote), messaging, and reporting hung off that spine.
PHP 8.2, **zero build step** (no Composer runtime deps, no npm/bundler),
static HTML + native Web Components calling `/api/...` JSON endpoints,
MySQL via PDO. Full pitch: `README.md` (top ~200 lines = architecture +
route table + directory layout — worth a skim once per session if you're
touching architecture).

## ⚠️ The one gotcha that matters most

**This checkout is the live production site — there is no staging.**
`/home/cdr/domains/panicbooking.com/www/backstage` is the real Apache
DocumentRoot for `https://panicbooking.com/backstage`. Editing a file here
changes production immediately, and whatever branch is checked out is what's
live. Full detail (second docroot sharing the same DB, credentials,
housekeeping) is in the `dev-environment` memory file — **read it before
making changes**, not just this file.

## Where things actually live

| Looking for... | Go to |
|---|---|
| Route table, endpoint-class mapping, directory layout | `README.md` (top half) |
| Full API surface (routes/schemas/auth/capabilities) | `docs/openapi.yaml` |
| Router internals, event-bundle shape, panel conventions, testing/DB gotchas, auth-token minting | memory: `dev-environment.md` |
| Git/commit conventions | memory: `git-workflow.md` |
| Add/edit/view form pattern (modal vs inline) | memory: `ui-conventions.md` |
| Ticketing (tiers, orders, door scanner, physical/pre-printed tickets + batch PDF printing) | `docs/ticketing.md` |
| Realtime invalidation layer (Web Worker, SSE, `data.invalidated`) | `docs/realtime-data.md` |
| Contracts / deal builder / e-signature | `docs/contracts.md` |
| Panic Promote (15-channel campaign tool) | `README.md` § Panic Promote, `docs/PROMOTE-*.md` |
| Product naming/boundaries ("Backstage" vs legacy `CenterStage` internals) | `docs/PRODUCT-BOUNDARIES.md` |
| Push notifications | `docs/push-notifications.md` |
| Google Calendar / Sheets sync | `docs/google-calendar-sync.md`, `docs/google-sheet-sync.md` |
| Multi-tenant / SaaS mode | `README.md` § Multi-Tenant / SaaS Mode |
| Booking Inbox / email import | `docs/booking-inbox.md`, `docs/booking-email-import.md` |

`docs/` has 30+ other files beyond this table — `ls docs/*.md` and grep by
keyword when the table doesn't cover it. Files named `*-PLAN.md` /
`*-plan.md` (e.g. `SAAS-PLAN.md`, `PRIVATE-EVENT-PLAN.md`,
`realtime-broker-rust-plan.md`) are **design/roadmap docs — proposed, not
necessarily built**. Don't assume their contents are live behavior; check
the code or the corresponding non-plan doc.

## Testing — quick reference (see `dev-environment.md` memory for the why)

Everything shares the one production MySQL database — no separate test DB.

- `php -l <file>` — always, before considering a PHP change done.
- `./tests/run-php-tests.sh` — hermetic PHP assertions, no server/DB needed.
- `./run-tests.sh` — shell smoke tests against a running app + real auth.
- `node tests/ui/run.mjs` — headless-Chromium UI tests; the right one for
  frontend/full-stack changes. Spins up its own dev PHP server but still
  points at the production DB — throwaway fixtures only, clean up in
  `finally`.

## Git

Trunk-based: commit small, finished, verified work straight to `main`
(Conventional Commits; a post-commit hook updates `CHANGELOG.md`
automatically — don't hand-edit it). Branch only for genuinely risky or
multi-session work, squash-merge back. Full rationale: `git-workflow.md`
memory.

## Keeping this file useful

This file is read every session at near-zero cost, so keep it a map, not an
encyclopedia. When a change adds a whole new subsystem (a new top-level
`docs/*.md`, a new architectural layer like the realtime worker), add one
row to the table above. Deeper "how it works" detail and stuff that changes
per-task belongs in the memory files instead — update those when you learn
something a future session would otherwise have to rediscover.
