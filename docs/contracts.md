# Contract / Deal Builder

A structured **deal builder** for venue contracts. The guiding principle is
**capture the deal as structured terms first, render the document second** — so
the same source of truth can drive the contract, settlement, and reporting.

- End-user help lives in the app: **Help → Contracts & deal builder** and
  **Help → Contract library & templates** (`#help-contracts`, `#help-admin-contracts`).
- This document covers the data model, API, smart-selection engine, and how to
  operate/extend the system.

> The seeded clause text is **starter boilerplate, not legal advice.** Have
> counsel review the clause library before sending real agreements.

---

## Concepts

| Thing | What it is |
| --- | --- |
| **Module (clause)** | A reusable paragraph with `{{variable}}` tokens, a category, risk level, required-field list, and an optional `locked` flag. The clause library. |
| **Template** | An ordered set of modules for a deal type, each wired as *required*, *conditional* (smart auto-select), or *default-on*. |
| **Contract** | One deal. Carries the money "spine" as real columns + a `variables_json` long tail. `event_id` is **nullable** so recurring residencies can be venue-bound. |
| **Section** | A module placed on a contract — an editable snapshot (so editing the library never rewrites existing contracts). |
| **Version** | An immutable rendered snapshot (HTML + text + the variable values at render time). |

Contract types (`contract_type` enum): `private_event`, `promoter_show`,
`artist_performance`, `recurring_night`, `fundraiser`, `house_show`, `other`.

Status workflow: `draft → needs_review → approved → sent → signed`
(plus `canceled`, `superseded`). Marking **sent/signed** is blocked until every
required term on an included section is filled **and** at least one version exists.

---

## Data model

The baseline `database/schema.sql` creates six contract tables:

- `contract_modules` — clause library (`module_key` unique, `body_template`,
  `required_fields_json`, `risk_level`, `is_locked`, `is_active`).
- `contract_templates` — name, `contract_type`, `intro_text`, `is_active`.
- `contract_template_modules` — wiring: `template_id`, `module_id`, `sort_order`,
  `is_required`, `condition_json`.
- `contracts` — deal-term columns + `variables_json` + workflow metadata.
  `event_id`, `venue_id`, `template_id` are all nullable (FKs `ON DELETE SET NULL`).
- `contract_sections` — per-contract editable clause snapshots (`included`,
  `auto_selected`, `is_locked`, `sort_order`).
- `contract_versions` — immutable rendered snapshots.

**Deal-term columns** (the queryable spine, `ContractRenderer::DEAL_COLUMNS`):
`rental_fee`, `deposit_amount`, `balance_due_date`, `bar_minimum`,
`guarantee_amount`, `door_split_artist|venue|promoter`,
`advance_ticket_price`, `door_ticket_price`, `security_count`, `security_rate`,
`security_paid_by`, `sound_tech_included`, `lighting_tech_included`,
`merch_venue_percent`, `recurrence_rule`, `term_start`, `term_end`,
`trial_period_weeks`, `termination_notice_days`, `review_cadence`,
`revenue_split_house`, `revenue_split_producer`. Everything else a clause needs
lives in `variables_json` (e.g. `marketing_deadline`, `ticket_platform`,
`insurance_amount`, `beneficiary`, `radius_miles`).

---

## Backend

PHP, no framework, PSR-4 autoload (`Panic\Foo` → `src/Foo.php`).

| File | Responsibility |
| --- | --- |
| `src/ContractRenderer.php` | Token substitution, condition engine, missing-field detection, deal summary. Pure/static. |
| `src/ContractService.php` | Create a contract; build sections from a template; re-evaluate smart selection. Shared by both endpoints. |
| `src/Contracts.php` | `/api/contracts` — list/create/show/update/delete + render, status, apply-template, reevaluate, sections, versions. |
| `src/ContractModules.php` | `/api/contract-modules` — clause library CRUD (admin). |
| `src/ContractTemplates.php` | `/api/contract-templates` — template CRUD + wiring (admin). |
| `src/Events/Contracts.php` | `/api/events/{id}/contracts` — list/create contracts for an event. |

### Capabilities (`src/BaseEndpoint.php`)

- Event-scoped: `view_contracts`, `manage_contracts`, `approve_contracts`
  (admin + event owner manage/approve; promoter views).
- Global: `manage_contract_library`, `view_all_contracts` (venue admin).
- Standalone (no `event_id`) contracts are managed by a venue admin or the creator.
- `locked` clauses can only be edited/removed on a contract by a venue admin.

### Routes

```
GET    /api/contracts                      list (admin: all; else own/event-scoped)
POST   /api/contracts                      create (standalone = admin only)
GET    /api/contracts/{id}                 full contract incl. preview + missing terms + risk flags
PATCH  /api/contracts/{id}                 update deal terms / counterparty / variables (re-evaluates auto sections)
DELETE /api/contracts/{id}
POST   /api/contracts/{id}/render          render a new immutable version
GET    /api/contracts/{id}/versions/{vid}  fetch a past version
POST   /api/contracts/{id}/status          change workflow status (gated)
POST   /api/contracts/{id}/apply-template  (re)build sections from a template
POST   /api/contracts/{id}/reevaluate      re-run smart selection on auto sections
POST   /api/contracts/{id}/sections        add a section (module or custom)
PATCH  /api/contracts/{id}/sections        bulk update (include / order / title / body)
DELETE /api/contracts/{id}/sections/{sid}  remove a section
GET/POST/PATCH/DELETE /api/contract-modules[/{id}]      clause library (admin)
GET/POST/PATCH/DELETE /api/contract-templates[/{id}]    templates (admin)
GET/POST              /api/events/{id}/contracts        per-event list/create
```

---

## Rendering & tokens

`ContractRenderer::context($contract, $event, $venue)` builds two maps:

- `tokens` — formatted display strings for `{{...}}` substitution.
- `cond` — raw values for the condition engine.

Token sources: deal-term columns, `variables_json`, and built-ins
(`venue_name`, `venue_address`, `venue_city`, `venue_state`,
`counterparty_display`, `title`, `event_title`, `event_date`, `event_room`,
`age_restriction`, `capacity`, `doors_time`, `show_time`, `end_time`).

Formatting heuristics by key: `*_fee|_amount|_minimum|_rate|_price` and
`guarantee_amount` → money; keys containing `split`/`percent` → `NN%`;
`*_included|_required|_sold` → Yes/No; `*_date|term_start|term_end` → long date.
A blank token renders as a highlighted `[ Label ]` placeholder (HTML) /
`[[ Label ]]` (text) so reviewers can see what's unfilled.

Output is plain semantic HTML under `.contract-doc`; the in-app preview and PDF
reuse `app.css`, and popped-out version views use an inline stylesheet.

---

## Smart selection (`condition_json`)

Recursive rule object evaluated against the `cond` context:

```jsonc
{ "all": [ <rule>, ... ] }          // every rule must pass
{ "any": [ <rule>, ... ] }          // at least one
{ "not": <rule> }
{ "field": "age_policy", "op": "eq", "value": "all_ages" }   // leaf
```

Operators: `eq`, `ne`, `in`, `nin`, `gt`, `gte`, `lt`, `lte`, `set`, `truthy`,
`falsy`. Fields: any deal-term column, any variable, or derived helpers
`age_policy` (`all_ages` vs `21_plus`, from `age_restriction`),
`expected_attendance` (from `capacity`), and `room`.

Template-module inclusion semantics:

- `is_required = 1` → always included.
- condition present → included only when it passes; tagged **auto**.
- neither → included by default (removable on the contract).

`PATCH /contracts/{id}` and `POST /contracts/{id}/reevaluate` re-run this over
the **auto** sections only — manual toggles are preserved.

`ContractRenderer::missingFields()` collects required fields from included
sections whose token value is blank; this both warns the user and gates
sent/signed.

---

## Seeding

`database/seed_contracts.php` (idempotent) seeds the clause library + starter
templates. Modules upsert on `module_key`; templates are matched by name and
their wiring is rebuilt each run. Run it standalone after applying migration 017:

```bash
php database/seed_contracts.php
```

`database/seed.php` (full demo reset) also calls `seed_contract_library()` and
truncates the contract tables.

Ships **26 clauses** across **7 templates**: Recurring Night Agreement,
Private Event Rental, Promoter / Production Show, Artist / Band Performance,
Famous / High-Draw Artist, Fundraiser / Charity Event, House-Produced Show.

---

## Frontend

The frontend is vanilla web components split into ES modules under
`public/assets/` (no build step; loaded via native ESM). `app.js` is the entry
(shell + routing) and imports the rest; `core.js` holds the shared kit
(`api`, `publish`/`subscribe`, `PanicElement`, `esc`, formatting helpers) that
every module imports. Contract UI lives in `contracts.js`:

- `pb-event-contracts` — the event-workspace **Contracts** tab (list + create).
- `pb-contract-editor` — full builder at route `#contract-<id>` (deal-terms
  form, live preview, clause toggles/reorder/edit, status workflow, version
  history, warnings).
- `pb-admin-contracts` — **Admin → Contracts**: All Contracts / Clause Library /
  Templates (with the smart-condition wiring editor).

PDF is **client-side** via `html2pdf.js` (CDN `<script>` in `index.html`, sets
`window.html2pdf`) — no build step, no server-side PDF dependency. The current
preview HTML is rendered to a Letter-size PDF in the browser.

---

## Extending

- **New clause:** Admin → Contracts → Clause Library → *New clause*. Set the
  body with `{{tokens}}`, list required variables, pick category/risk, and
  mark `locked` for legal language.
- **New template:** Admin → Contracts → Templates → *New template*. Check
  clauses, order them, and set each as required / conditional (paste the
  `condition_json`).
- **New deal-term column:** add the column to `contracts` (a new migration under
  `database/migrations/`, plus the baseline `database/schema.sql`), append it to
  `ContractRenderer::DEAL_COLUMNS`, and add an input
  to the deal-terms form group in `ContractEditor.dealFormHtml()`. Free-form
  terms don't need a column — use a contract variable instead.

## Not yet built (future)

E-signature integration, redline/diff history, an AI "suggest clauses" helper,
and flowing accepted deal terms into `event_settlements` (the money columns are
already queryable for this).
