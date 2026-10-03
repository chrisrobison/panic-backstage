# Staff Handbook & Compliance — Content Library

This directory is the shared, git-authored **seed template** for the Staff Handbook & Compliance
system — genericized so it's a sane starting point for any venue, not just Mabuhay Gardens. It
holds the content and its frontmatter (`slug`, `document_type`, `requires_acknowledgment`,
`status`, etc.), which `Panic\StaffDocs::syncFromDisk()`/`publishFromFile()` read and seed from.

For the single-tenant install (this Mabuhay checkout), these files *are* the live content:
`scripts/sync-staff-docs.php` + `scripts/seed-staff-doc-defaults.php` publish them, and edits here
go live the normal git-commit way. For a multi-tenant SaaS install, this same template is copied
into each new tenant's own database at provisioning time (`Panic\Tenant\TenantProvisioner`, via
`database/seed_staff_docs.php`) — after that, a venue admin can customize their own copy entirely
through the app (`PUT /api/staff-docs/{slug}/draft` + `POST .../publish-draft`, no git/file access
needed) without ever touching this shared template. See `src/StaffDocs.php`'s docblock for the
full file-vs-db content-source split.

Version `0.2` of the handbook, core policies, and affected SOPs replaces the original policy
blanks with role-based operating defaults. The remaining `VERIFY` notes are facts that require a
site walk, private payroll details, or legal/regulatory review. See
[`knowledge-audit.md`](knowledge-audit.md) for the source and decision record, and
[`management-interview.md`](management-interview.md) for the validation questions to work through
before publication.

## Core handbook and policies

| File | What it is |
|---|---|
| [`handbook.md`](handbook.md) | The main Staff Handbook — venue history/culture, org structure and chain of command, employment basics, standards of conduct, safety summary, guest/event policy, alcohol/cash summaries, reporting routes, and acknowledgment. |
| [`emergency.md`](emergency.md) | Fast, phone-readable emergency quick reference (fire, earthquake, medical, active threat, evacuation, 911). |
| [`venue-safety.md`](venue-safety.md) | Full-depth safety reference expanding every topic in the emergency doc, plus day-to-day hazards (spills, ladders, electrical, hearing protection, injury reporting). |
| [`alcohol-service.md`](alcohol-service.md) | Alcohol service policy — age verification, refusing service, RBS certification, comps, tampering, incident escalation. |

## Standard Operating Procedures (`sop/`)

| File | What it is |
|---|---|
| [`sop/opening.md`](sop/opening.md) | Building-opening checklist and safety walkthrough. |
| [`sop/closing.md`](sop/closing.md) | End-of-night closing, reconciliation, and sign-off. |
| [`sop/house-manager.md`](sop/house-manager.md) | On-the-floor event authority and escalation point. |
| [`sop/bartender.md`](sop/bartender.md) | Exact bar service steps — ID checks, cutoffs, comps, cash. |
| [`sop/barback.md`](sop/barback.md) | Bar stocking, cleanliness, and hazard control. |
| [`sop/door.md`](sop/door.md) | Ticket/guest-list verification, ID checks, capacity, refusals. |
| [`sop/security.md`](sop/security.md) | De-escalation, guest removal, incident response. |
| [`sop/sound-engineer.md`](sop/sound-engineer.md) | PA/monitor setup, mixing, equipment/electrical safety. |
| [`sop/stagehand.md`](sop/stagehand.md) | Load-in/out, stage setup, backstage access control. |
| [`sop/booking.md`](sop/booking.md) | Full inquiry-to-handoff workflow: Booking Inbox, holds, deals, contracts, e-signature, deposits, advancing to production. |
| [`sop/cash-handling.md`](sop/cash-handling.md) | Two-person drawer counts, drops, discrepancies, and the event-level ledger boundary. |
| [`sop/artist-settlement.md`](sop/artist-settlement.md) | The real closeout workflow: payee balances, the 422 finalize gate, door sales & settlement doc. |
| [`sop/event-coordinator.md`](sop/event-coordinator.md) | Logistics bridge split between event Owner and House Manager unless separately assigned. |
| [`sop/cafe.md`](sop/cafe.md) | Café activation requirements and minimum controls; operation remains inactive until completed. |
| [`sop/kitchen.md`](sop/kitchen.md) | Kitchen activation requirements and minimum controls; operation remains inactive until completed. |
| [`sop/cleaning.md`](sop/cleaning.md) | Facility cleaning role template. |

## Audit and interview materials

| File | What it is |
|---|---|
| [`knowledge-audit.md`](knowledge-audit.md) | What's confirmed by the app vs. probable vs. missing vs. open — the honesty ledger for this whole draft. |
| [`management-interview.md`](management-interview.md) | Questions for validating the version 0.2 defaults and collecting remaining site facts. |
| [`interviews/house-manager.md`](interviews/house-manager.md) | Reusable interview worksheet for the House Manager function. |
| [`interviews/bartender.md`](interviews/bartender.md) | Reusable interview worksheet for bartenders. |
| [`interviews/door.md`](interviews/door.md) | Reusable interview worksheet for door staff. |
| [`interviews/security.md`](interviews/security.md) | Reusable interview worksheet for security staff. |
| [`interviews/sound-engineer.md`](interviews/sound-engineer.md) | Reusable interview worksheet for sound engineers. |
| [`interviews/booker.md`](interviews/booker.md) | Reusable interview worksheet for booking staff. |
| [`interviews/cafe-kitchen.md`](interviews/cafe-kitchen.md) | Reusable interview worksheet for café/kitchen staff (once operations exist). |
| [`interviews/cleaning.md`](interviews/cleaning.md) | Reusable interview worksheet for cleaning staff. |
