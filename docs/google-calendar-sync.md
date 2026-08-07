# Google Calendar sync

One-way mirror of Backstage events onto a shared Google Calendar, so staff see
the booking schedule — holds included — next to the rest of their day without
opening Backstage.

**Direction: Backstage → Google only.** Nothing is ever read back. A staff
member who edits a mirrored event in Google will have their edit overwritten on
the next sweep. Backstage stays the source of truth.

## Moving parts

| Piece | What it does |
|---|---|
| `src/GoogleCalendar.php` | Service-account auth + create/patch/delete/list against the Calendar API. Same JWT flow as `GoogleSheets.php`; only the scope and token-cache path differ. |
| `scripts/sync-google-calendar.php` | The reconcile sweep. `--dry-run` to preview, `--verbose` for per-event detail. |
| `EventRowHelpers::pushToCalendar()` | Inline push on every event save, so a change lands in seconds. |
| `scripts/cron-google-calendar.sh` | Cron wrapper: flock, explicit PATH, log trim, kill switch. |
| `database/migrations/093_add_gcal_sync_columns.sql` | Adds `events.gcal_event_id` + `events.gcal_synced_at`. |

Runs nightly at 04:45 via crontab. Logs to `storage/logs/calendar-sync.log`.

## Configuration

```
GOOGLE_SA_KEY_FILE=/home/cdr/domains/panicbooking.com/secrets/panic-booking-13c987ea62ae.json
GOOGLE_CALENDAR_ID=bookings@themab.org
GCAL_SYNC_ENABLED=1
```

`GCAL_SYNC_ENABLED=0` makes the whole thing inert without editing crontab.

The service account (`backstage@panic-booking.iam.gserviceaccount.com`) reuses
the **same key file as the Sheets integration** — only the OAuth scope differs.

### One-time Google setup

1. GCP project `panic-booking` → enable **Google Calendar API**.
2. Target calendar → Settings → *Share with specific people* → add the service
   account's `client_email` with **"Make changes to events"**.

The service account is *external* to the `themab.org` Workspace domain. If an
admin policy restricts off-domain calendar sharing, step 2 either won't offer
"Make changes to events" or will silently downgrade to free/busy. Symptom: the
API returns **404** (not 403) for a calendar the caller can't see.

## What gets synced

Everything dated within the last 7 days or later, **except**:

- `status = 'empty'` — placeholder shells from the grid UI, not bookings.
- Titles starting `PB UI TEST` — the repo's throwaway-fixture convention
  (`tests/ui` runs against this same production DB, and leaks happen).

Title prefixes by status:

| Status | Renders as |
|---|---|
| `proposed` | `HOLD — Title` |
| `canceled` | *removed from the calendar* |
| everything else | `Title` |

**Canceled events are deleted from the calendar and unlinked**, freeing the
night. Driven by `GoogleCalendar::shouldRemove()` / `REMOVE_STATUSES`, which
both the sweep and the inline push consult.

> An earlier revision kept a visible `CANCELED — ` entry instead. Deletion is
> the deliberate current policy — please don't "fix" it back.

In the sweep, the removal branch runs **before** the staleness check. It has to:
an event already synced under the old policy has `gcal_synced_at >= updated_at`,
so a staleness gate would skip it and the stale entry would linger forever. The
branch is self-terminating — once deleted, `gcal_event_id` is NULL and later
runs skip the row for free.

### Times

Holds legitimately carry no times (see commit `f469666`, "stop inventing
times"), so an event with no `doors_time`/`show_time`/`end_time` becomes an
**all-day** entry rather than a fabricated 7pm slot.

With times: start = `show_time` (falling back to `doors_time`), end =
`end_time` (falling back to +3h). An `end_time` earlier than the start rolls to
the next day — shows running past midnight are normal. An `end_time` exactly
*equal* to the start is treated as "no end recorded" and gets the +3h default,
rather than becoming a 24-hour block.

Times are sent as local `dateTime` + `timeZone` from the venue's `timezone`
column, not pre-converted to UTC.

## Inline push on save

Every event save also pushes immediately — `EventRowHelpers::pushToCalendar()`,
called next to the existing `pushToSheet()` at each write site (`Events.php`
create / status-change / single-field PATCH / full update / from-template, and
`Series.php`). A save costs roughly one extra Google round trip (~0.7s), on top
of the Sheets push that already happens.

Best-effort and never-throw, exactly like `pushToSheet()`. **There is no outbox
table**, and that's deliberate: because the sweep is a stateless reconcile, the
retry mechanism is simply *not* setting `gcal_synced_at` — tonight's run
recomputes and repairs anything the inline push missed. Nothing can silently
fall behind the way a queue can.

Only *saves* route through the inline path. A hard `DELETE /api/events/{id}`
cascades the row away and is cleaned up by the sweep's orphan reconcile.

## Design: reconcile sweep, not a write queue

The sweep recomputes desired state from the DB on every run. It is **not** a
write-hook plus retry queue — that's what the Sheets integration does, and its
`sheet_sync_queue` is currently paused in crontab over exactly the kind of
drift a stateless sweep can't accumulate.

Consequences:

- Safe to run repeatedly. A second run immediately after a first reports
  all-unchanged.
- Freshness target is "within a day", matching the nightly cron. Anything
  needing minutes wants an inline push on event write instead — don't just run
  this sweep more often.
- Steady-state runs make ~0 API calls: the sweep only pushes rows where
  `gcal_event_id IS NULL OR updated_at > gcal_synced_at`.

### Not clobbering human entries

Every event the sweep creates carries a private extended property
`panicApp=1` plus `panicEventId=<id>`. The orphan-reconcile step lists **only**
events matching `panicApp=1`, so entries staff create by hand on the shared
calendar are invisible to it and can never be modified or deleted.

Orphan cleanup exists because `DELETE /api/events/{id}` cascades the row away,
taking `gcal_event_id` with it — without the reconcile step the Google entry
would linger forever with nothing pointing at it. Before deleting, the sweep
double-checks the app row really is gone.

If someone hand-deletes a mirrored event in Google, the next patch returns
404/410; the sweep clears the stored id and re-creates it.

## Troubleshooting

Run the sweep by hand and read the log:

```
php scripts/sync-google-calendar.php --dry-run     # preview, no writes
php scripts/sync-google-calendar.php --verbose
tail -f storage/logs/calendar-sync.log
```

| Symptom | Cause |
|---|---|
| `HTTP 403 accessNotConfigured` | Calendar API not enabled in the GCP project. |
| `HTTP 404` on a calendar you can see | Calendar not shared with the service account (or shared read-only). |
| `HTTP 403` on write, read works | Shared as "See all event details" instead of "Make changes to events". |
| Everything skipped | `GCAL_SYNC_ENABLED=0`, or `GOOGLE_CALENDAR_ID` unset. |

To force a full re-push (e.g. after changing the title format):

```sql
UPDATE events SET gcal_synced_at = NULL WHERE date >= CURDATE() - INTERVAL 7 DAY;
```

To start completely fresh, also `SET gcal_event_id = NULL` — but delete the old
Google entries first, or the orphan reconcile will be the only thing that
cleans them up.
