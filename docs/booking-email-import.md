# Booking-email import

Booking requests sent to **bookings@themab.org** arrive as freeform or
semi-structured email. This pipeline parses each one and creates a row in the
`leads` table (`source = email`, `status = new`) so they show up in the Leads
inbox alongside manually-entered leads — with as much detail carried over as the
message allows.

## How it flows

```
bookings@themab.org  ──(Google Workspace forward)──▶  the server mailbox
        │
        ▼   ~/.forward  (Exim user filter)
   matches To/Cc/Delivered-To/X-Forwarded-To contains "bookings@themab.org"
        │
        ▼   unseen pipe  (message still lands in the inbox)
   scripts/ingest-booking-email.php   ← raw RFC822 on STDIN
        │
        ├─▶  src/LeadEmailParser.php  — MIME decode + field extraction
        │
        ▼
   leads (new)  +  lead_intake_emails (raw + dedup + audit)  +  lead_notes (audit)
```

The `unseen` keyword means the message is **also delivered to the inbox as
normal** — the importer only gets a copy. The filter never calls `finish`, so
the rest of the mailbox's rules still run.

## Threading (replies fold into the original conversation)

A reply to a booking inquiry doesn't create a second lead. Before creating a
new `leads` row, `src/Leads/ThreadMatcher.php` checks whether the inbound
email is obviously a continuation of one we already have:

1. **Threading headers** — the email's `In-Reply-To`/`References` ids are
   checked against `lead_messages.external_message_id`, which every message
   on a lead carries: its own `Message-ID` for inbound mail, and (since this
   feature landed) a real generated `Message-ID` for every outbound send too
   — staff replies (`LeadsInbox.php`) and the auto-acknowledgment
   (`Leads/Acknowledgment.php`). A hit is exact.
2. **Subject + sender fallback** — for webmail clients that drop
   `References` on forward: if the subject, normalized (`Re:`/`Fwd:`/`Fw:`/
   `Aw:` prefixes stripped — `LeadEmailParser::normalizeSubject()`), matches
   a prior message from the same `contact_email` within the last 180 days,
   it's treated as the same thread.

A match attaches a new `lead_intake_emails` row and `lead_messages` row to
the **existing** lead (plus an audit `lead_notes` entry) instead of running
the full new-lead pipeline — classification, routing, and the
auto-acknowledgment only ever run once, when the lead is first created, so a
reply never re-triggers them or re-sends the acknowledgment. Staff see the
new message on the lead's Conversation tab via the existing Inbox polling
(`Inbox::changes()`) — no separate notification needed.

No match (a first inquiry, or a reply to a pre-threading message with no
recoverable subject/sender match) falls through to lead creation exactly as
before.

## Parsing strategy (hybrid)

| Email shape | How it's parsed |
|---|---|
| **Structured** — Jotform "NEW Booking ALERT" with `Who's Calling:` / `The Vibe:` / `The Date:` / `Expected Crowd:` / `The Vision:` label blocks | Deterministic label parsing. Free, exact. The real requester comes from the `Reply-To` header (Jotform sends `From: noreply@jotform.com`). |
| **Freeform prose** — a human writing a paragraph | Claude (Anthropic Messages API, structured JSON output) when a key is configured; regex heuristics otherwise. |

The two are combined: deterministic label values win, and the LLM/heuristics
fill the gaps (band names, dates, attendance, private-vs-public, alcohol plan)
and add a one-line summary. The **full original message is always stored** in
`leads.notes` and `lead_intake_emails.raw_email`, so nothing is lost even when
extraction is imperfect.

Fields populated where present: `contact_name`, `contact_email`, `contact_org`,
`contact_phone`, `event_name`, `event_type` (mapped to the Leads UI set:
`concert` / `private_event` / `festival` / `comedy_show` / `other`), `band_name`,
`desired_date` (+ alt), `projected_attendance`, `is_private`, `alcohol_plan`.

## Setup

1. **Run the migration** (creates `lead_intake_emails`):

   ```
   php scripts/migrate.php          # single-tenant
   ```

2. **Confirm the local `claude` CLI is usable** (optional but recommended for
   freeform mail) — `CLAUDE_CLI_BIN` in `.env` (default
   `/home/cdr/.local/bin/claude`), authenticated via its own OAuth/
   subscription login, no billed API key needed. This script must run as the
   same OS user that ran `claude login` (true by default: Exim invokes it as
   the mailbox owner via `~/.forward`). Without a working CLI, structured
   emails still import perfectly and freeform emails import via heuristics.
   See `.env.example` → *Booking-email importer*.

3. **Add the Exim filter rule.** Because the mailbox already uses an Exim user
   filter (`~/.forward` beginning with `# Exim filter`), add this rule near the
   top so it always fires:

   ```
   # Rule: FAME booking intake
   if "$h_to:$h_cc:$h_delivered-to:$h_x-forwarded-to:" contains "bookings@themab.org"
   then
   logwrite "$tod_log [$message_id] booking intake -> FAME lead importer"
   unseen pipe "/usr/local/bin/php /home/cdr/domains/panicbooking.com/www/backstage/scripts/ingest-booking-email.php"
   endif
   ```

   Validate without delivering anything:

   ```
   exim -bf ~/.forward < some-message.eml
   ```

   It should report `Unseen pipe message to: …ingest-booking-email.php` and
   `Normal delivery will occur.`

## Operating notes

- **Safety:** the importer is a mail-delivery pipe, so it **always exits 0** —
  a parse or DB error is logged and recorded with `status = error` in
  `lead_intake_emails` (raw message retained) rather than bouncing the email.
- **Deduplication:** re-delivery of the same `Message-ID` is detected and
  skipped (it won't create a second lead).
- **Log:** `storage/logs/booking-intake.log` (override with `BOOKING_INTAKE_LOG`).
- **Re-import / debug:** the raw message is kept in
  `lead_intake_emails.raw_email`; pipe it back through the script to re-parse.

## Manual use

```
# Parse only, print the extracted JSON (no DB write):
php scripts/ingest-booking-email.php --dry-run --file=message.eml

# Import a saved message:
php scripts/ingest-booking-email.php --file=message.eml

# From stdin (how Exim invokes it):
php scripts/ingest-booking-email.php < message.eml
```

## Tests

`php tests/booking_email_parser_test.php` — exercises MIME decoding, Jotform
label parsing, heuristic extraction, and threading-header/subject
normalization against fixtures in `tests/fixtures/booking-emails/` (no API key
or DB required).

`RUN_DB_TESTS=1 php tests/leads_thread_matcher_test.php` — exercises
`ThreadMatcher`'s header and subject+sender matching against real
`leads`/`lead_messages` rows (throwaway, cleaned up after). Needs a real DB,
so it's opt-in — see `tests/run-php-tests.sh`.
