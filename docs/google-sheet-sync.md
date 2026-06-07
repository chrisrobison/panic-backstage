# Google Sheet Sync

Panic Backstage keeps its events in sync with a Google Sheet (the "MabEvents
Tracker") in **both** directions:

- **Inbound (sheet → app):** a cron downloads the sheet every 5 minutes and
  UPSERTs rows into the database. This is the original, long-standing path.
- **Outbound (app → sheet):** edits made in Backstage are pushed back up to the
  matching row in real time, with a cron-based fallback for retries.

This document covers how both halves work, how authentication is set up, and
how to operate and troubleshoot the sync.

---

## At a glance

| | Inbound (sheet → app) | Outbound (app → sheet) |
|---|---|---|
| Trigger | cron, every 5 min | real-time on `PATCH /api/events/{id}`, plus cron fallback |
| Entry point | `scripts/sync-mabevents.py` | `src/Events.php` → `pushToSheet()` |
| Transport | public `…/export?format=xlsx` (read-only, no auth) | Sheets REST API (service-account auth) |
| Client | Python (`openpyxl`) | `src/GoogleSheets.php` (pure PHP) |
| Row key | event `slug` / `external_id` | `external_id` in **column A** |
| Retry | next cron tick | `sheet_sync_queue` outbox + `scripts/push-sheet-queue.php` |

The target spreadsheet is the **MabEvents Tracker**, tab **`Tracker`**, sheet id
`1STS6et19iDHxtLvK2HVfqmAzs1HUa9GgF25KqBikRRE`.

---

## Authentication (the important part)

Inbound reads need **no auth** — they hit the sheet's public `export` URL, which
works only because the sheet is shared "anyone with the link – viewer."

Outbound **writes cannot** use that URL. Google only allows writes via a
**service account** or OAuth, so Backstage authenticates as a service account:

1. A Google Cloud project (`panic-booking`) with the **Google Sheets API**
   enabled.
2. A **service account** whose email is
   `backstage@panic-booking.iam.gserviceaccount.com`.
3. A **JSON key** for that account, stored on the server **outside the web
   root**:
   ```
   /home/cdr/domains/panicbooking.com/secrets/panic-booking-<id>.json
   ```
4. The spreadsheet is **shared with the service-account email as an Editor.**

`src/GoogleSheets.php` signs a short-lived JWT with the key's private key
(RS256 via `ext-openssl`), exchanges it for an OAuth2 access token, caches the
token, and calls the Sheets REST API. No Composer packages are involved.

### Key file permissions (read this — it has bitten us)

The web server runs as **`www-data`**. The key file must be readable by that
user but **not world-readable**. Store it exactly like `.env`:

```bash
SECRETS=/home/cdr/domains/panicbooking.com/secrets
chown cdr:www-data "$SECRETS"/panic-booking-*.json
chmod 750 "$SECRETS"
chmod 640 "$SECRETS"/panic-booking-*.json
```

If `www-data` can't read the key, real-time pushes silently fail and the row
sits `pending` in the outbox until the next cron sweep (which runs as `cdr` and
*can* read it, so it will still succeed there). The log line to look for is
`FAIL: key file not readable`.

> The same class of permission bug once took the whole API down: the web user
> couldn't read `.env`, so the app fell back to a `root` DB login and every
> request 500'd. When something Google- or DB-related "works from the CLI but
> not the web," check file ownership/permissions for `www-data` first.

---

## Configuration

Set in `.env` (which is git-ignored). Outbound sync is **inert** until
`GOOGLE_SA_KEY_FILE` is present and the key loads — until then pushes no-op and
log a skip, so the feature is safe to deploy before the key exists.

```dotenv
# Two-way Google Sheet sync (DB -> sheet write-back)
GOOGLE_SA_KEY_FILE=/home/cdr/domains/panicbooking.com/secrets/panic-booking-<id>.json
GOOGLE_SHEET_ID=1STS6et19iDHxtLvK2HVfqmAzs1HUa9GgF25KqBikRRE
GOOGLE_SHEET_TAB=Tracker
```

---

## What gets written back, and where

Rows are matched by `external_id` (e.g. `EVT-1050`) in **column A** of the
Tracker tab. Only **app-owned** fields are pushed; identity columns
(`external_id`, referral, promoter, title, date, room) stay sheet-owned and are
never written.

| App field | Sheet column | Notes |
|---|---|---|
| `status` | **M** | written as a human label (see below) |
| `potential_revenue` | **F** | |
| `ticket_system` | **O** | |
| `contract_url` | **P** | |
| `walkthrough_done` | **Q** | written as `Yes` / empty |
| `ticket_url` | **R** | |
| `settlement_doc_url` | **S** | |
| `deposit_amount` | — | **no sheet column; cannot be pushed (app-only)** |

The field → column map lives in `GoogleSheets::FIELD_COLUMN`. If the sheet's
column layout changes, update that map (and the column indices in
`scripts/generate-import-sql.py` for the inbound side).

### Status labels

The app stores status as slugs (`proposed`, `confirmed`, …). On push these are
converted to human labels for the sheet via `GoogleSheets::STATUS_SHEET_LABEL`
(e.g. `proposed → Prospect`, `confirmed → Booked`, `canceled → Cancelled`).
This is the reverse of the inbound `STATUS_MAP` in
`scripts/generate-import-sql.py`. It is intentionally **not** a perfect inverse
(several sheet labels collapse onto `confirmed`), which is safe because status
is preserve-local on import — see "Conflicts & loops" below.

---

## How a write-back flows

1. A user edits an event: `PATCH /api/events/{id}` (any of the three update
   paths — status-only, single-field, or full row).
2. `Events::pushToSheet($id)` runs after the DB update:
   - Upserts one `pending` row into `sheet_sync_queue` (one row per event;
     repeated edits collapse into it).
   - If the event has an `external_id` and `GoogleSheets` is configured, it
     attempts an **immediate** push and, on success, marks the queue row
     `done`.
   - Any failure is swallowed (logged) so the edit's HTTP response is never
     affected — same never-throw contract as the `Mailer`.
3. The 5-minute cron (`scripts/cron-sync.sh`) runs
   `scripts/push-sheet-queue.php` after the inbound sync, retrying any rows
   still `pending`. Rows that keep failing past `MAX_ATTEMPTS` (20) flip to
   `failed` so they stop consuming each run but remain visible.

App-native events (created in Backstage, no `EVT-####`) have no sheet row, so
they're enqueued but skipped (logged). Appending them as new sheet rows is a
possible future enhancement.

### The outbox table

`sheet_sync_queue` (migration `database/migrations/012_sheet_sync_queue.sql`):

| column | meaning |
|---|---|
| `event_id` | FK to `events`, UNIQUE (one pending row per event) |
| `status` | `pending` / `done` / `failed` |
| `attempts` | retry counter |
| `last_error` | last failure note |
| `pushed_at` | timestamp of last successful push |

---

## Conflicts & loops

The inbound import reads most of these fields back every 5 minutes, but this is
**benign**: because the app writes its value *up* first, the next import reads
the same value back — a no-op. They converge.

- **`status`** is *preserve-local* on import (the importer never overwrites the
  DB's status), so the app is authoritative for it. That's why pushing a label
  that doesn't perfectly reverse-map is safe.
- The other six fields are pulled inbound, but the write-up-first ordering keeps
  them consistent.
- **Simultaneous edits** (a human edits a sheet cell *and* the app edits the
  same field between syncs) are **last-writer-wins**. Real-time pushes usually
  land within seconds, so app edits propagate quickly.

---

## Operating & troubleshooting

**Logs**
- Outbound: `storage/logs/sheet-sync.log` (every push attempt, ok/fail).
- Inbound + sweep: `storage/logs/sync-mabevents.log`.

**Manually run the fallback sweep**
```bash
php scripts/push-sheet-queue.php --verbose
```

**Inspect the queue**
```sql
SELECT event_id, status, attempts, last_error, pushed_at
FROM sheet_sync_queue ORDER BY updated_at DESC LIMIT 20;
```

**Force a re-push of one event** (re-enqueue, then sweep)
```sql
INSERT INTO sheet_sync_queue (event_id, status, attempts) VALUES (<id>, 'pending', 0)
ON DUPLICATE KEY UPDATE status='pending', attempts=0, updated_at=NOW();
```
```bash
php scripts/push-sheet-queue.php --verbose
```

**Common failures**
| Symptom (in `sheet-sync.log`) | Cause / fix |
|---|---|
| `skip: not configured` | `GOOGLE_SA_KEY_FILE` unset or key won't load. |
| `FAIL: key file not readable` | Permissions — make it `640 cdr:www-data` (see above). |
| `FAIL: token exchange -> HTTP 4xx` | Bad/expired key, or Sheets API not enabled on the project. |
| `FAIL: read column A -> HTTP 403` | Sheet not shared with the service-account email (needs Editor). |
| `skip: external_id … not found` | The event's `external_id` isn't in column A (app-native event, or id mismatch). |

---

## Appending app-created events

Outbound sync resolves each event in three steps (`GoogleSheets::syncEventRow`):

1. **App ID (col Z) match** → update the app-owned fields in place.
2. **`external_id` (col A) match** → write the App ID to link the row, then update.
3. **No match** → **append a complete new Tracker row** (identity + app fields +
   App ID), so an event created in the app shows up in the sheet instead of
   stranding as a permanent `pending` outbox row.

Step 2 is what prevents duplicates for events that originated in the sheet but
were never linked. Row lookups distinguish API errors from genuine not-found, so
a transient read failure can never trigger a spurious append.

## Staff Contact (two-way staff sync)

The `Staff Contact` tab syncs two-way with `staff_members` via
`scripts/sync-staff.php` (run from the same 5-minute cron, after the event
write-back). Set `GOOGLE_STAFF_TAB` to override the tab name (default
`Staff Contact`).

- **Link key:** a hidden **App ID column at `K`** holds `staff_members.id`
  (the tab is only 17 columns wide — A–Q — so the Tracker's `Z` is out of grid).
- **Column map** (header row 1, data row 2+): `A` Department · `B` Fname ·
  `C` Lname · `D` Pronoun · `E` Staffing Status · `F` Phone · `G` Email ·
  `H` Position · `I` Staffing Notes · `J` **Hire Date** · `K` App ID.
- **Department ↔ role** is mapped both ways (Bar↔bartender, Sound↔sound,
  Light↔lighting, …); `Fname`/`Lname` combine into `name` and split back on append.
- **Reconcile rules:** linked rows **gap-fill both ways** (a non-empty sheet cell
  wins into the app; a non-empty app value fills an *empty* sheet cell — nothing
  is ever blanked). Unlinked sheet rows are **adopted by email then name**, or
  **created** only when the row has a real email (not blank, not `*.local`).
  Real-email, active app staff with no sheet row are **appended**.

## Relevant files

| File | Role |
|---|---|
| `src/GoogleSheets.php` | Service-account auth + Sheets REST writer; event field/status maps; generic grid toolkit (`readGrid`/`appendGridRow`/`batchWriteCells`/`ensureGridColumn`) |
| `src/Events.php` (`pushToSheet`) | Real-time enqueue + `syncEventRow` on PATCH |
| `database/migrations/012_sheet_sync_queue.sql` | Outbox table |
| `scripts/push-sheet-queue.php` | Fallback retry sweep (events) |
| `scripts/sync-staff.php` | Two-way `Staff Contact` ↔ `staff_members` reconcile |
| `scripts/cron-sync.sh` | 5-min cron: inbound sync → outbound event sweep → staff sync |
| `scripts/sync-mabevents.py` | Inbound download + import orchestrator |
| `scripts/generate-import-sql.py` | Inbound column map + `STATUS_MAP` |
