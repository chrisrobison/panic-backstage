# Panic Backstage Product Boundaries

## Canonical identity

**Panic Backstage** is the product name. A venue may configure its own app-shell label and logo, and public venue pages may use the venue's name, but feature areas do not introduce separate product brands.

Use these names in user-facing copy:

- Panic Backstage — the product
- Booking Inbox — inquiry intake, conversation, ownership, and qualification
- Events — the operational record created by onboarding
- Contracts — deal terms, signature, and execution
- Promote — the campaign workspace inside Panic Backstage
- Automation — process definitions and runtime work
- Tasks, Messages, Contacts, Reports — supporting workspaces

`CenterStage`, `Centerstage OS`, and `Panic Signal` are not current user-facing product names. The `Panic\Processes\CenterStage` PHP namespace remains temporarily for compatibility; do not extend that name into UI, documentation, database labels, or new namespaces.

## Primary business spine

```text
Inquiry
  → Qualification and ownership
  → Event onboarding
  → Contract and payment commitment
  → Production and show execution
  → Settlement and closeout
```

There is one system of record at each stage:

| Stage | System of record | Transition out |
|---|---|---|
| Inquiry | `leads`, messages, classification, claims, audit | Atomic onboarding |
| Event | `events` plus event workspaces | Contract/payment gates |
| Commitment | contracts, signatures, event payments | Booked/ready event |
| Execution | schedule, staffing, tasks, assets, ticketing, guest list | Show complete |
| Settlement | ledger, settlement, reports | Finalized closeout |

Onboarding is the only normal lead-to-event conversion boundary. Supporting modules may link to a lead or event, but must not create a parallel booking record.

## Supporting modules

- Contacts and CRM supply identity/history to inquiry routing and campaigns.
- Promote distributes event information; it does not own event truth.
- Tasks and Automation coordinate work; they do not own booking status.
- Messages and Outbox provide communication history; lead conversations remain attached to the lead.
- Reporting reads lifecycle data; it does not introduce a separate settlement state.
- Ticketing, POS, guest list, and staffing are event execution capabilities.

## Change rules

1. New booking features must attach to the existing lead or event record.
2. New routes that change lifecycle state must use the existing status machines, capability checks, transactions, and audit logs.
3. A new top-level workspace needs a distinct system-of-record responsibility, not merely a new navigation label.
4. Product copy uses Panic Backstage; venue copy uses configured tenant/venue identity.
5. Route changes update `docs/openapi.yaml` and pass `scripts/check-openapi-routes.php`.
6. Slow or failure-prone external work belongs in the durable job queue, not a public request.
