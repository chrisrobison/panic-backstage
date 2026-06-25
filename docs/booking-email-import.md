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

2. **Configure the Anthropic key** (optional but recommended for freeform mail) —
   set `ANTHROPIC_API_KEY` (or `ANTHROPIC_API_KEY_FILE`) in `.env`. Without it,
   structured emails still import perfectly and freeform emails import via
   heuristics. See `.env.example` → *Booking-email importer*.

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
label parsing, and heuristic extraction against fixtures in
`tests/fixtures/booking-emails/` (no API key or DB required).
