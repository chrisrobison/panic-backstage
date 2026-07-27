# Booking-email import

Booking requests sent to **bookings@themab.org** arrive as freeform or
semi-structured email. This pipeline parses each message and creates a row in
the `leads` table (`source = email`, `status = new`) when it contains booking
signals. Mailing-list and unrelated automated messages are retained in the
intake quarantine without entering the active inquiry queue.

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
   IntakeGate
        ├─ inquiry   → leads + lead_messages + lead_intake_emails
        └─ noise     → lead_intake_emails (skipped; raw retained)
```

The `unseen` keyword means the message is **also delivered to the inbox as
normal** — the importer only gets a copy. The filter never calls `finish`, so
the rest of the mailbox's rules still run.

## Threading (replies fold into the original conversation)

A reply to a booking inquiry doesn't create a second lead. Before creating a
new `leads` row, `src/Leads/ThreadMatcher.php` checks whether the inbound
email is obviously a continuation of one we already have:

1. **Threading headers** — the email's `In-Reply-To`/`References` ids are
   checked against both `lead_messages.external_message_id` and the indexed
   ancestry in `lead_message_references`. The latter matters when the original
   parent email never reached this inbox: two replies that cite the same
   missing parent still share an exact RFC thread identity. Inbound messages
   carry their source `Message-ID`; outbound sends carry a generated
   `Message-ID` (`LeadsInbox.php` and `Leads/Acknowledgment.php`).
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
recoverable subject/sender match) falls through to the intake gate before any
new lead is created.

## Intake quarantine

`src/Leads/IntakeGate.php` rejects list mail, bulk/automated senders without
booking signals, newsletter content fingerprints (unsubscribe/browser-view
language, campaign-provider links, marketing subjects, and high link density),
automated non-inquiry notifications, and messages with no
event/date/attendance signal. Jotform submissions always pass, and thread
matching runs before this gate so a short reply to an existing inquiry is
never filtered.

Skipped mail keeps its raw RFC 822 payload and parsed metadata in
`lead_intake_emails`. Venue administrators can review it from the shield
button in the Booking Inbox queue. Promoting a false positive creates an
unread conversation, then runs classification and deterministic routing.

Existing untouched leads can be rechecked after filter improvements:

```
php scripts/quarantine-booking-inbox-noise.php          # dry run
php scripts/quarantine-booking-inbox-noise.php --apply  # quarantine safe matches
```

The recheck refuses to change assigned, claimed, owned, answered, annotated,
task-linked, attachment-bearing, or event-linked inquiries.

## Backfilling threads for already-imported email

`scripts/backfill-lead-threads.php` builds connected components from each
stored email's own Message-ID and complete References ancestry. It therefore
recovers direct reply chains and siblings that share an unimported parent,
then populates `lead_message_references` for future matching. It remains
deliberately header-only (no subject+sender fallback) because exact RFC
ancestry is safe to apply across history while repeated subject lines are not.

```
php scripts/backfill-lead-threads.php            # dry run (default) — reports, writes nothing
php scripts/backfill-lead-threads.php --apply     # writes the safe merges
```

A merge only ever runs automatically when the later lead shows no conflicting
ownership or business work (unassigned, unclaimed, no owner, no real reply
sent, no human note/attachment/linked task, not converted). Legacy active
pipeline statuses may converge, and two spam records may converge when their
canonical thread is also spam.
Anything else is printed under "needs manual review" and left completely
untouched. Applied merges move the conversation (messages/notes/attachments)
onto the earliest lead and mark the duplicate `status = 'duplicate'` —
nothing is deleted, and it is idempotent.

## Auto-acknowledgment gating

`Leads/Acknowledgment.php` sends a neutral "we got your inquiry" receipt at
most once per lead, but only when it's actually a first contact through one
of our own intake channels:

- **Never for a freeform email.** A person emailing bookings@ directly in
  their own words (parse_method `llm`/`heuristic`/`none`) doesn't get a
  form-receipt-sounding auto-reply — only a Jotform submission forwarded as
  email (parse_method `jotform`/`jotform+llm`) or the website's own inquiry
  form (`source = website`, no email parsing involved) counts as "one of our
  forms."
- **Never for anything that looks like a reply**, even when it landed here as
  a "new" lead because ThreadMatcher couldn't resolve it to a specific prior
  lead — an `In-Reply-To` header, or a `Re:`/`Fwd:`/`Fw:`/`Aw:` subject
  prefix, is enough to skip it. Sending a first-contact receipt to something
  that says right in its own headers/subject that it's a continuation of a
  conversation reads as ignoring what the person just wrote.

Both checks are independent of the threading match above and run every time,
so a genuinely new Jotform lead still gets acknowledged normally.

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
- **Historical rebuild:** `php scripts/backfill-booking-inbox.php` is a dry-run
  report; add `--apply` to rebuild legacy conversation rows and quarantine
  untouched obvious noise.
- **Log:** `storage/logs/booking-intake.log` (override with `BOOKING_INTAKE_LOG`).
- **Re-import / debug:** the raw message is kept in
  `lead_intake_emails.raw_email`; pipe it back through the script to re-parse.

## Manual use

```
# Parse only, print the extracted JSON (no DB write):
php scripts/ingest-booking-email.php --dry-run --file=message.eml

# Import a saved message:
php scripts/ingest-booking-email.php --file=message.eml

# Override the intake gate for a verified inquiry:
php scripts/ingest-booking-email.php --force-inquiry --file=message.eml

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
