// ── Event + Contract Creation Wizard ─────────────────────────────────────────
// A JSON-driven, multi-step wizard that guides users through creating a fully
// configured event with an automatically pre-populated contract draft.
//
// Route:   #new-event          create mode (also opens from topbar "+ New event")
//          #new-event-{id}     edit mode — re-runs an existing event through the
//                               same guided flow, pre-filled with its current
//                               event + contract values (see `sourceEventId`)
// Element: <pb-event-wizard>
//
// Architecture
// ────────────
//  WIZARD_FLOW           JSON config — the single source of truth for every
//                        step, field, condition, and default value. Edit this
//                        object to extend the wizard without touching logic.
//
//  EventContractWizard   PanicElement that owns wizard state, renders steps
//                        from the flow config, validates, and on finish:
//                          Create mode (no sourceEventId):
//                            1. POST /events                     → create event
//                            2. POST /events/{id}/series         → (optional) spin off a
//                                                                   recurring series, if set
//                            3. POST /events/{id}/contracts      → create contract
//                            4. PATCH /contracts/{id}            → fill deal terms
//                            5. POST /contracts/{id}/reevaluate  → smart clauses
//                          Edit mode (sourceEventId set):
//                            1. PATCH /events/{sourceEventId}                  → update event
//                            2. PATCH /contracts/{id} (or create if none yet)  → update deal terms
//                            3. POST /contracts/{id}/reevaluate                → smart clauses
//
// Edit mode note: `deal_type` (the wizard's talent_buy/promoter_deal/rental/…
// picker) has no matching column on `contracts` — it only drives which fields
// are shown. When pre-filling from an existing contract we infer the closest
// deal_type from which deal-term columns are populated (see `_inferDealType`);
// it's a best-effort guess, not a stored value.
//
// PAN integration
// ───────────────
//  Publishes  'event.saved'       { id }       → causes workspace to mount
//  Publishes  'wizard.completed'  { event, contract }
//  Publishes  'toast.show'        { message, tone }
//
// Usage notes
// ───────────
//  To add a step:     push a new entry into WIZARD_FLOW.steps
//  To add a field:    push into a step's `fields` array
//  To gate a step:    add a `condition` expression (see typedef below)
//  To gate a field:   same — add a `condition` on the field object
//  Field types:       text | email | number | date | time | textarea |
//                     select | bool | contact_search | deal_type_picker |
//                     recurrence_picker
// ─────────────────────────────────────────────────────────────────────────────

import {
  esc,
  titleCase,
  shortDate,
  isoDate,
  money,
  publish,
  subscribe,
  api,
  PanicElement,
  $,
  $$,
} from './core.js';
import './recurrence.js'; // registers <pb-recurrence-fields>, used by the recurrence_picker field type

// ── Wizard flow configuration ─────────────────────────────────────────────────
//
// Condition shape:
//   { field: 'deal_type', in: ['talent_buy', 'promoter_deal'] }
//   { field: 'deal_type', notIn: ['free_event', 'internal'] }
//
// A step or field with no `condition` is always shown.
// Steps/fields that fail their condition are fully skipped (not just hidden).

const WIZARD_FLOW = {
  id: 'event_wizard',
  version: 2,
  steps: [
    // ── Step 1: Event Basics ─────────────────────────────────────────────────
    {
      id: 'basics',
      title: 'Event Basics',
      icon: 'fa-solid fa-calendar-days',
      description: 'Core details that every event needs. The rest can be filled in later.',
      fields: [
        {
          id: 'title',
          label: 'Event Title',
          type: 'text',
          required: true,
          wide: true,
          placeholder: 'e.g. Friday Night Live with The Slackers',
        },
        {
          id: 'date',
          label: 'Date',
          type: 'date',
          required: true,
        },
        {
          id: 'end_date',
          label: 'End Date',
          type: 'date',
          minField: 'date',
          help: 'Optional — only for events spanning more than one day (e.g. a comedy workshop or weekend rental). Leave blank for single-day events.',
        },
        {
          id: 'recurrence',
          label: 'Recurring Event',
          type: 'recurrence_picker',
          wide: true,
          condition: { field: 'editing_mode', notIn: ['1'] },
          help: 'Creates additional independent events on a repeating schedule — each occurrence gets its own contract, staffing, and ticketing.',
        },
        {
          id: 'venue_id',
          label: 'Room / Venue',
          type: 'select',
          required: true,
          source: 'venues',
        },
        {
          id: 'event_type',
          label: 'Event Type',
          type: 'select',
          required: true,
          source: 'event_types',
        },
        {
          id: 'doors_time',
          label: 'Doors Open',
          type: 'time',
          default: '19:00',
        },
        {
          id: 'show_time',
          label: 'Show Time',
          type: 'time',
          default: '20:00',
        },
        {
          id: 'end_time',
          label: 'End / Curfew',
          type: 'time',
          default: '23:00',
        },
        {
          id: 'age_restriction',
          label: 'Age Restriction',
          type: 'select',
          options: [
            { value: '',         label: '— Not specified —' },
            { value: 'all_ages', label: 'All Ages' },
            { value: '18+',      label: '18+' },
            { value: '21+',      label: '21+' },
          ],
        },
        {
          id: 'capacity',
          label: 'Capacity',
          type: 'number',
          placeholder: 'Max attendance',
        },
        {
          id: 'public_description',
          label: 'Public Description',
          type: 'textarea',
          wide: true,
          placeholder: 'Short blurb for event listings and the public page…',
        },
      ],
    },

    // ── Step 2: Deal Structure ───────────────────────────────────────────────
    {
      id: 'deal_type',
      title: 'Deal Structure',
      icon: 'fa-solid fa-file-signature',
      description: 'Choose how this event is structured financially. This determines which contract clauses are auto-selected.',
      fields: [
        {
          id: 'deal_type',
          label: 'Deal Type',
          type: 'deal_type_picker',
          required: true,
          wide: true,
        },
        {
          id: 'contract_template_id',
          label: 'Contract Template',
          type: 'select',
          source: 'contract_templates',
          wide: true,
          help: 'A matching template will be auto-suggested. Smart clause selection runs after creation.',
        },
      ],
    },

    // ── Step 3: Artist / Promoter ────────────────────────────────────────────
    // Skipped for free / internal events that have no counterparty.
    {
      id: 'counterparty',
      title: 'Artist / Promoter',
      icon: 'fa-solid fa-user-group',
      description: 'Who is performing or booking this show? Type a name or search your contacts.',
      condition: { field: 'deal_type', notIn: ['free_event', 'internal', ''] },
      fields: [
        {
          id: 'counterparty_name',
          label: 'Contact Name',
          type: 'contact_search',
          required: true,
          wide: true,
          placeholder: 'Search contacts or type a name…',
        },
        {
          id: 'counterparty_org',
          label: 'Band / Organization',
          type: 'text',
          placeholder: 'Label, agency, or group name',
        },
        {
          id: 'counterparty_email',
          label: 'Contact Email',
          type: 'email',
        },
        {
          id: 'artist_name',
          label: 'Artist / Act Name',
          type: 'text',
          placeholder: 'Performing name, if different from contact name',
          condition: { field: 'deal_type', in: ['talent_buy', 'promoter_deal'] },
        },
      ],
    },

    // ── Step 4: Deal Terms ───────────────────────────────────────────────────
    // Fields are individually gated on deal type to avoid irrelevant clutter.
    {
      id: 'deal_terms',
      title: 'Deal Terms',
      icon: 'fa-solid fa-dollar-sign',
      description: 'Financial terms that will be carried into the contract draft.',
      condition: { field: 'deal_type', notIn: ['free_event', 'internal', ''] },
      fields: [
        {
          id: 'guarantee_amount',
          label: 'Guarantee ($)',
          type: 'number',
          condition: { field: 'deal_type', in: ['talent_buy', 'promoter_deal'] },
        },
        {
          id: 'door_split_artist',
          label: 'Artist Door Split (%)',
          type: 'number',
          condition: { field: 'deal_type', in: ['talent_buy', 'promoter_deal'] },
        },
        {
          id: 'door_split_venue',
          label: 'Venue Door Split (%)',
          type: 'number',
          condition: { field: 'deal_type', in: ['talent_buy', 'promoter_deal'] },
        },
        {
          id: 'door_split_promoter',
          label: 'Promoter Door Split (%)',
          type: 'number',
          condition: { field: 'deal_type', in: ['promoter_deal'] },
        },
        {
          id: 'rental_fee',
          label: 'Rental Fee ($)',
          type: 'number',
          condition: { field: 'deal_type', in: ['rental', 'private_event'] },
        },
        {
          id: 'revenue_split_house',
          label: 'House Revenue Split (%)',
          type: 'number',
          condition: { field: 'deal_type', in: ['residency'] },
        },
        {
          id: 'revenue_split_producer',
          label: 'Producer Revenue Split (%)',
          type: 'number',
          condition: { field: 'deal_type', in: ['residency'] },
        },
        {
          id: 'deposit_amount',
          label: 'Deposit Required ($)',
          type: 'number',
        },
        {
          id: 'balance_due_date',
          label: 'Balance Due Date',
          type: 'date',
        },
        {
          id: 'bar_minimum',
          label: 'Bar Minimum ($)',
          type: 'number',
        },
        {
          id: 'advance_ticket_price',
          label: 'Advance Ticket ($)',
          type: 'number',
          condition: { field: 'deal_type', notIn: ['rental', 'private_event', 'free_event', 'internal'] },
        },
        {
          id: 'door_ticket_price',
          label: 'Door Ticket ($)',
          type: 'number',
          condition: { field: 'deal_type', notIn: ['rental', 'private_event', 'free_event', 'internal'] },
        },
        {
          id: 'merch_venue_percent',
          label: 'Venue Merch Cut (%)',
          type: 'number',
        },
      ],
    },

    // ── Step 5: Production & Security ────────────────────────────────────────
    {
      id: 'production',
      title: 'Production & Security',
      icon: 'fa-solid fa-headphones',
      description: 'Tech rider, production, and security requirements for this event.',
      fields: [
        {
          id: 'sound_tech_included',
          label: 'Sound Tech Included?',
          type: 'bool',
        },
        {
          id: 'lighting_tech_included',
          label: 'Lighting Tech Included?',
          type: 'bool',
        },
        {
          id: 'security_count',
          label: '# Security Guards',
          type: 'number',
        },
        {
          id: 'security_rate',
          label: 'Security Rate ($/hr)',
          type: 'number',
        },
        {
          id: 'security_paid_by',
          label: 'Security Paid By',
          type: 'select',
          options: [
            { value: '',         label: '—' },
            { value: 'venue',    label: 'Venue' },
            { value: 'artist',   label: 'Artist' },
            { value: 'promoter', label: 'Promoter' },
          ],
        },
        {
          id: 'tech_rider_notes',
          label: 'Tech Rider Notes',
          type: 'textarea',
          wide: true,
          placeholder: 'Backline, PA specs, load-in time, stage plot notes…',
        },
      ],
    },

    // ── Step 6: Promotion ────────────────────────────────────────────────────
    {
      id: 'promotion',
      title: 'Promotion',
      icon: 'fa-solid fa-bullhorn',
      description: 'Public listing and marketing settings for this event.',
      fields: [
        {
          id: 'public_visibility',
          label: 'Public Listing?',
          type: 'bool',
          default: '',
        },
        {
          id: 'announce_date',
          label: 'Announce / On-Sale Date',
          type: 'date',
        },
        {
          id: 'promo_notes',
          label: 'Promotion Notes',
          type: 'textarea',
          wide: true,
          placeholder: 'Social handles, platforms, priority campaigns, ticket link…',
        },
      ],
    },

    // ── Step 7: Review & Create ──────────────────────────────────────────────
    {
      id: 'review',
      title: 'Review & Create',
      icon: 'fa-solid fa-circle-check',
      description: 'Confirm the details below, then create the event and contract draft.',
      isReview: true,
    },
  ],
};

// ── Deal type option cards ─────────────────────────────────────────────────────
// Shown as a card grid on the Deal Structure step. Value maps to `deal_type`
// field — which drives conditional gating on the Deal Terms step's fields.

const DEAL_TYPES = [
  {
    value: 'talent_buy',
    label: 'Talent Buy',
    icon: 'fa-solid fa-music',
    desc: 'Guarantee + door splits. You pay the artist directly.',
  },
  {
    value: 'promoter_deal',
    label: 'Promoter Deal',
    icon: 'fa-solid fa-handshake',
    desc: 'Promoter rents the room and keeps the door.',
  },
  {
    value: 'rental',
    label: 'Venue Rental',
    icon: 'fa-solid fa-building',
    desc: 'Flat rental fee for the space; no door deal.',
  },
  {
    value: 'private_event',
    label: 'Private Event',
    icon: 'fa-solid fa-lock',
    desc: 'Buyout or private event not listed publicly.',
  },
  {
    value: 'residency',
    label: 'Residency',
    icon: 'fa-solid fa-rotate',
    desc: 'Recurring engagement over a defined term.',
  },
  {
    value: 'free_event',
    label: 'Free / Internal',
    icon: 'fa-solid fa-star',
    desc: 'No booking fee or formal contract required.',
  },
];

// ── Numeric + string contract deal columns (mirrors ContractEditor's list) ────
const NUMERIC_DEAL_FIELDS = [
  'guarantee_amount', 'deposit_amount', 'bar_minimum', 'rental_fee',
  'door_split_artist', 'door_split_venue', 'door_split_promoter',
  'advance_ticket_price', 'door_ticket_price', 'merch_venue_percent',
  'security_count', 'security_rate', 'revenue_split_house', 'revenue_split_producer',
];
const STRING_DEAL_FIELDS  = ['balance_due_date', 'security_paid_by', 'tech_rider_notes'];
const BOOL_DEAL_FIELDS    = ['sound_tech_included', 'lighting_tech_included'];


// ── Component ─────────────────────────────────────────────────────────────────

class EventContractWizard extends PanicElement {

  // ── Lifecycle ───────────────────────────────────────────────────────────────

  async connect() {
    this.stepIndex     = 0;
    this.wizardData    = {};      // accumulated form data, keyed by field id
    // Synthetic flag (not a real event column) used purely to gate the
    // recurrence_picker field's `condition` — recurring series are only ever
    // spun off from a brand-new event (see _finishCreate), never when editing.
    this.wizardData.editing_mode = this.sourceEventId ? '1' : '0';
    this.meta          = null;    // { venues, event_types, contract_templates }
    this._searchTimer  = null;    // debounce handle for contact typeahead
    this._submitting   = false;   // prevent double-submit
    this._sourceEvent    = null;  // full event row, set when sourceEventId is passed (edit mode)
    this._sourceContract = null;  // full contract row for the source event, if one exists

    this.setLoading(this.sourceEventId ? 'Loading event into wizard…' : 'Loading wizard…');

    // Seed default values from flow config before first render
    WIZARD_FLOW.steps.forEach((step) => {
      (step.fields || []).forEach((f) => {
        if (f.default !== undefined && this.wizardData[f.id] === undefined) {
          this.wizardData[f.id] = f.default;
        }
      });
    });

    try {
      // Load event templates (venues + types + admin wizard defaults) and
      // contract templates in parallel to keep the wizard snappy.
      const [tplData, ctplData] = await Promise.all([
        api('/templates'),
        api('/contract-templates').catch(() => ({ templates: [] })),
      ]);

      this.meta = {
        venues:             tplData.venues    || [],
        event_types:        tplData.types     || [
          'live_music', 'karaoke', 'open_mic', 'promoter_night',
          'dj_night', 'comedy', 'private_event', 'special_event',
        ],
        event_templates:    tplData.templates || [],       // event setup templates
        contract_templates: ctplData.templates || ctplData || [],  // contract clause templates
      };

      // Apply admin-configured defaults. These override the WIZARD_FLOW field-level
      // defaults seeded above (admin's venue-specific values are more authoritative
      // than hardcoded fallbacks). Empty / missing keys are skipped.
      const adminDefaults = tplData.wizard_defaults || {};
      Object.entries(adminDefaults).forEach(([key, val]) => {
        if (val !== null && val !== undefined && val !== '') {
          this.wizardData[key] = String(val);
        }
      });

      // Pre-fill today as the default date if none set
      if (!this.wizardData.date) {
        this.wizardData.date = isoDate(new Date());
      }

      // Edit mode: overlay the source event's (and its contract's) real values
      // on top of the defaults set above, so the wizard opens pre-filled.
      if (this.sourceEventId) {
        await this._loadSourceEvent();
      }

      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  /** Edit mode: fetch the source event + its most recently updated contract (if any) and seed wizardData from them. */
  async _loadSourceEvent() {
    try {
      const eventResp = await api(`/events/${this.sourceEventId}`);
      this._sourceEvent = eventResp.event;

      let contract = null;
      try {
        const listResp = await api(`/events/${this.sourceEventId}/contracts`);
        const first = (listResp.contracts || [])[0]; // ordered by updated_at DESC
        if (first) {
          const fullResp = await api(`/contracts/${first.id}`);
          contract = fullResp.contract;
        }
      } catch {
        // Contracts are optional context for pre-fill — proceed without one.
      }
      this._sourceContract = contract;

      this._prefillFromSource(this._sourceEvent, this._sourceContract);
    } catch (error) {
      publish('toast.show', {
        message: `Could not load event #${this.sourceEventId} into the wizard: ${error.message || error}`,
        tone: 'error',
      });
    }
  }

  /** Overlay wizardData with values read from an existing event (+ its contract, if any). */
  _prefillFromSource(event, contract) {
    if (!event) return;
    const d = this.wizardData;

    d.title              = event.title || '';
    d.date                = event.date || d.date;
    d.end_date            = event.end_date || '';
    d.venue_id            = event.venue_id != null ? String(event.venue_id) : '';
    d.event_type          = event.event_type || '';
    if (event.doors_time) d.doors_time = String(event.doors_time).slice(0, 5);
    if (event.show_time)  d.show_time  = String(event.show_time).slice(0, 5);
    if (event.end_time)   d.end_time   = String(event.end_time).slice(0, 5);
    d.age_restriction     = event.age_restriction || '';
    d.capacity             = event.capacity != null ? String(event.capacity) : '';
    d.public_description   = event.description_public || '';
    d.public_visibility     = event.public_visibility != null ? String(Number(event.public_visibility)) : '';

    if (!contract) return;

    d.deal_type            = this._inferDealType(contract);
    d.contract_template_id = contract.template_id != null ? String(contract.template_id) : '';
    d.counterparty_name    = contract.counterparty_name || '';
    d.counterparty_org     = contract.counterparty_org  || '';
    d.counterparty_email   = contract.counterparty_email || '';

    NUMERIC_DEAL_FIELDS.forEach((k) => {
      if (contract[k] !== null && contract[k] !== undefined) d[k] = String(contract[k]);
    });
    STRING_DEAL_FIELDS.forEach((k) => {
      if (contract[k] !== null && contract[k] !== undefined) d[k] = String(contract[k]);
    });
    BOOL_DEAL_FIELDS.forEach((k) => {
      if (contract[k] !== null && contract[k] !== undefined) d[k] = String(Number(contract[k]));
    });
  }

  /**
   * `deal_type` (talent_buy / promoter_deal / rental / …) only exists in the
   * wizard's UI — it has no column on `contracts`, so it can't be read back
   * directly. Infer the closest match from which deal-term columns are
   * populated. Best-effort only; the user can correct it on the Deal
   * Structure step.
   */
  _inferDealType(contract) {
    if (Number(contract.revenue_split_house) || Number(contract.revenue_split_producer)) return 'residency';
    if (Number(contract.rental_fee)) return 'rental';
    if (Number(contract.door_split_promoter)) return 'promoter_deal';
    if (Number(contract.guarantee_amount) || Number(contract.door_split_artist)) return 'talent_buy';
    if (contract.contract_type === 'private_event') return 'private_event';
    if (contract.counterparty_name) return 'talent_buy';
    return '';
  }

  // ── Flow helpers ─────────────────────────────────────────────────────────────

  /** Steps visible given current wizardData (conditions evaluated). */
  get visibleSteps() {
    return WIZARD_FLOW.steps.filter((s) => this._conditionMet(s.condition));
  }

  get currentStep() {
    return this.visibleSteps[this.stepIndex] || this.visibleSteps[0];
  }

  /** True when `condition` passes against current wizardData. */
  _conditionMet(condition) {
    if (!condition) return true;
    const value = String(this.wizardData[condition.field] ?? '');
    if (condition.in    && !condition.in.includes(value))    return false;
    if (condition.notIn &&  condition.notIn.includes(value)) return false;
    return true;
  }

  /** Fields for a step that are visible under current conditions. */
  _visibleFields(step) {
    return (step.fields || []).filter((f) => this._conditionMet(f.condition));
  }

  /** Convert a `source` key into [{value, label}] option pairs. */
  _sourceOptions(source) {
    if (!this.meta) return [];
    switch (source) {
      case 'venues':
        return this.meta.venues.map((v) => ({ value: String(v.id), label: v.name }));
      case 'event_types':
        return this.meta.event_types.map((t) => ({ value: t, label: titleCase(t) }));
      case 'contract_templates':
        return this.meta.contract_templates.map((t) => ({
          value: String(t.id),
          label: t.name + (t.contract_type ? ` (${titleCase(t.contract_type)})` : ''),
        }));
      default:
        return [];
    }
  }

  // ── Rendering ────────────────────────────────────────────────────────────────

  render() {
    const steps = this.visibleSteps;
    const total = steps.length;
    const pct   = total > 1 ? Math.round((this.stepIndex / (total - 1)) * 100) : 100;

    this.innerHTML = `
      <div class="wizard-shell" role="main" aria-label="New event wizard">
        ${this._headerHtml(steps, pct)}
        <div class="wizard-body">
          ${this._stepPanelHtml(this.currentStep)}
          ${this._sidebarHtml()}
        </div>
      </div>`;

    this.bind();
    this._scrollToTop();
  }

  _headerHtml(steps, pct) {
    const editing = !!this.sourceEventId;
    const exitHref = editing ? `#event-${esc(String(this.sourceEventId))}` : '#events';
    return `
      <header class="wizard-header">
        <div class="wizard-top-row">
          <div>
            <span class="wizard-eyebrow">${editing ? 'Editing Event' : 'New Event'}</span>
            <h1 class="wizard-main-title">${esc(this.currentStep.title)}</h1>
          </div>
          <div class="wizard-top-actions">
            <a href="${exitHref}" class="button secondary small">Exit wizard</a>
          </div>
        </div>
        <div class="wizard-progress-track"
             role="progressbar"
             aria-label="Wizard progress"
             aria-valuenow="${pct}"
             aria-valuemin="0"
             aria-valuemax="100">
          <div class="wizard-progress-bar" style="width:${pct}%"></div>
        </div>
        <ol class="wizard-step-list" aria-label="Wizard steps" role="list">
          ${steps.map((s, i) => this._stepBreadcrumbHtml(s, i)).join('')}
        </ol>
      </header>`;
  }

  _stepBreadcrumbHtml(step, index) {
    const active = index === this.stepIndex;
    const done   = index < this.stepIndex;
    const future = index > this.stepIndex;
    return `
      <li class="wizard-step-item ${active ? 'is-active' : ''} ${done ? 'is-done' : ''}"
          role="listitem"
          aria-current="${active ? 'step' : 'false'}">
        <button class="wizard-step-btn"
                data-goto="${index}"
                ${future ? 'disabled' : ''}
                title="${esc(step.title)}">
          <span class="wizard-step-num" aria-hidden="true">
            ${done
              ? '<i class="fa-solid fa-check"></i>'
              : `<span>${index + 1}</span>`}
          </span>
          <span class="wizard-step-label">${esc(step.title)}</span>
        </button>
      </li>`;
  }

  _stepPanelHtml(step) {
    const steps = this.visibleSteps;
    return `
      <section class="wizard-step-panel" aria-live="polite">
        ${step.description
          ? `<p class="wizard-step-desc">${esc(step.description)}</p>`
          : ''}
        <div class="wizard-step-fields" data-step-fields>
          ${step.isReview ? this._reviewHtml() : this._fieldsFormHtml(step)}
        </div>
        <div class="wizard-nav" data-wizard-nav>
          ${this.stepIndex > 0
            ? '<button class="button secondary" data-prev aria-label="Go to previous step">← Back</button>'
            : ''}
          ${this.stepIndex < steps.length - 1
            ? '<button class="button primary" data-next aria-label="Go to next step">Continue →</button>'
            : `<button class="button primary" data-finish>
                 <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                 ${this.sourceEventId ? 'Save Changes' : 'Create Event &amp; Draft Contract'}
               </button>`}
          <span class="wizard-step-hint muted small" aria-live="off">
            Step ${this.stepIndex + 1} of ${steps.length}
          </span>
        </div>
      </section>`;
  }

  _fieldsFormHtml(step) {
    const fields = this._visibleFields(step);
    if (!fields.length) return '<p class="muted">No fields for this step.</p>';
    return `<form class="grid-form" data-wizard-form novalidate>
      ${fields.map((f) => this._fieldHtml(f)).join('')}
    </form>`;
  }

  _fieldHtml(field) {
    const val  = this.wizardData[field.id] ?? field.default ?? '';
    const wide = field.wide ? ' class="wide"' : '';
    const req  = field.required ? ' required' : '';
    let control;

    switch (field.type) {

      case 'deal_type_picker':
        control = this._dealTypePickerHtml(String(val));
        break;

      case 'recurrence_picker':
        control = `<pb-recurrence-fields></pb-recurrence-fields>`;
        break;

      case 'select': {
        const opts = field.source
          ? this._sourceOptions(field.source)
          : (field.options || []);
        control = `
          <select name="${esc(field.id)}"${req}>
            <option value="">— Choose —</option>
            ${opts.map((o) =>
              `<option value="${esc(o.value)}"${String(val) === String(o.value) ? ' selected' : ''}>${esc(o.label)}</option>`
            ).join('')}
          </select>`;
        break;
      }

      case 'bool': {
        const bv = (val === null || val === undefined || val === '') ? '' : String(Number(val));
        control = `
          <select name="${esc(field.id)}">
            <option value=""${bv === '' ? ' selected' : ''}>—</option>
            <option value="1"${bv === '1' ? ' selected' : ''}>Yes</option>
            <option value="0"${bv === '0' ? ' selected' : ''}>No</option>
          </select>`;
        break;
      }

      case 'textarea':
        control = `<textarea name="${esc(field.id)}"
                             placeholder="${esc(field.placeholder || '')}"
                             rows="3">${esc(val)}</textarea>`;
        break;

      case 'date': {
        const min = field.minField ? String(this.wizardData[field.minField] || '') : '';
        control = `<input type="date"
                          name="${esc(field.id)}"
                          value="${esc(val)}"
                          ${min ? `min="${esc(min)}"` : ''}
                          ${req}>`;
        break;
      }

      case 'contact_search':
        control = `
          <div class="contact-search-wrap" data-contact-wrap>
            <input type="text"
                   name="${esc(field.id)}"
                   value="${esc(val)}"
                   placeholder="${esc(field.placeholder || '')}"
                   autocomplete="off"
                   data-contact-search
                   ${req}>
            <div class="contact-dropdown" data-contact-results hidden aria-live="polite"></div>
          </div>`;
        break;

      default:
        control = `<input type="${esc(field.type)}"
                          name="${esc(field.id)}"
                          value="${esc(val)}"
                          placeholder="${esc(field.placeholder || '')}"
                          ${req}>`;
    }

    const helpHtml = field.help
      ? `<span class="field-hint muted small">${esc(field.help)}</span>`
      : '';

    return `<label${wide}>${esc(field.label)}${helpHtml}${control}</label>`;
  }

  _dealTypePickerHtml(selectedValue) {
    return `
      <div class="deal-type-grid" data-deal-grid role="group" aria-label="Deal type options">
        ${DEAL_TYPES.map((dt) => `
          <button type="button"
                  class="deal-type-card${selectedValue === dt.value ? ' selected' : ''}"
                  data-deal-type="${esc(dt.value)}"
                  aria-pressed="${selectedValue === dt.value}">
            <i class="${esc(dt.icon)}" aria-hidden="true"></i>
            <strong>${esc(dt.label)}</strong>
            <span>${esc(dt.desc)}</span>
          </button>
        `).join('')}
      </div>
      <input type="hidden" name="deal_type" value="${esc(selectedValue)}">`;
  }

  // ── Review step ──────────────────────────────────────────────────────────────

  _reviewHtml() {
    const contentSteps = this.visibleSteps.filter((s) => !s.isReview);
    const missing      = this._getMissingRequired();

    return `
      <div class="wizard-review">
        ${missing.length
          ? `<div class="wizard-review-missing" role="alert">
               <strong>
                 <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                 Missing required fields
               </strong>
               <ul>
                 ${missing.map((m) => `
                   <li>
                     <button class="linklike"
                             data-goto-step="${m.stepIndex}"
                             data-goto-field="${esc(m.fieldId)}">
                       ${esc(m.label)}
                     </button>
                     <span class="muted small">(${esc(m.stepTitle)})</span>
                   </li>`).join('')}
               </ul>
             </div>`
          : `<div class="wizard-review-ok" role="status">
               <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
               All required fields filled in. Ready to create!
             </div>`
        }
        ${contentSteps.map((step, i) => this._reviewSectionHtml(step, i)).join('')}
      </div>`;
  }

  _reviewSectionHtml(step, visibleIndex) {
    const fields = this._visibleFields(step);
    // Skip the deal_type_picker pseudo-field from the table
    const rows = fields
      .filter((f) => f.type !== 'deal_type_picker')
      .map((f) => {
        const raw = this.wizardData[f.id];
        if (raw === undefined || raw === '' || raw === null) return null;
        let display;
        if (f.type === 'bool') {
          display = raw === '1' || raw === 1 ? 'Yes' : (raw === '0' || raw === 0 ? 'No' : '—');
        } else if (f.type === 'number' && raw !== '') {
          display = String(raw);
        } else {
          display = String(raw);
        }
        return `<tr>
          <td class="review-label">${esc(f.label)}</td>
          <td><strong>${esc(display)}</strong></td>
        </tr>`;
      })
      .filter(Boolean);

    // Deal type card selection is shown separately
    const dealType = DEAL_TYPES.find((dt) => dt.value === this.wizardData.deal_type);
    const dealRow  = step.id === 'deal_type' && dealType
      ? `<tr><td class="review-label">Deal Type</td><td><strong>${esc(dealType.label)}</strong></td></tr>`
      : '';

    if (!rows.length && !dealRow) return '';

    // Find this step's index in visibleSteps for the "Edit" button
    const stepIndex = this.visibleSteps.indexOf(step);

    return `
      <div class="wizard-review-section">
        <div class="wizard-review-section-head">
          <h3>${esc(step.title)}</h3>
          <button class="button small secondary" data-goto="${stepIndex}">Edit</button>
        </div>
        <table class="review-table">
          <tbody>
            ${dealRow}
            ${rows.join('')}
          </tbody>
        </table>
      </div>`;
  }

  // ── Sidebar summary ──────────────────────────────────────────────────────────

  _sidebarHtml() {
    const d        = this.wizardData;
    const venue    = this.meta?.venues?.find((v) => String(v.id) === String(d.venue_id));
    const dealType = DEAL_TYPES.find((dt) => dt.value === d.deal_type);
    const tmpl     = this.meta?.contract_templates?.find((t) => String(t.id) === String(d.contract_template_id));

    // One key financial fact to show
    let finFact = '';
    if (d.guarantee_amount) {
      finFact = `<div class="sidebar-fact"><span>Guarantee</span><strong>${esc(money(d.guarantee_amount))}</strong></div>`;
    } else if (d.rental_fee) {
      finFact = `<div class="sidebar-fact"><span>Rental fee</span><strong>${esc(money(d.rental_fee))}</strong></div>`;
    }

    // Format date nicely — show a range once an End Date is set.
    let dateStr = '';
    if (d.date) {
      try {
        dateStr = shortDate(new Date(d.date + 'T12:00:00'));
        if (d.end_date && d.end_date !== d.date) {
          dateStr += ` – ${shortDate(new Date(d.end_date + 'T12:00:00'))}`;
        }
      } catch { dateStr = d.date; }
    }

    return `
      <aside class="wizard-sidebar" aria-label="Event summary">
        <h2 class="wizard-sidebar-title">Summary</h2>
        <div class="wizard-sidebar-card">
          <div class="sidebar-event-name ${d.title ? '' : 'muted'}">
            ${esc(d.title || 'Event title…')}
          </div>
          ${dateStr         ? `<div class="sidebar-fact"><span>Date</span><strong>${esc(dateStr)}</strong></div>` : ''}
          ${venue           ? `<div class="sidebar-fact"><span>Venue</span><strong>${esc(venue.name)}</strong></div>` : ''}
          ${d.event_type    ? `<div class="sidebar-fact"><span>Type</span><strong>${esc(titleCase(d.event_type))}</strong></div>` : ''}
          ${dealType        ? `<div class="sidebar-fact"><span>Deal</span><strong>${esc(dealType.label)}</strong></div>` : ''}
          ${d.counterparty_name ? `<div class="sidebar-fact"><span>With</span><strong>${esc(d.counterparty_name)}</strong></div>` : ''}
          ${finFact}
          ${d.deposit_amount  ? `<div class="sidebar-fact"><span>Deposit</span><strong>${esc(money(d.deposit_amount))}</strong></div>` : ''}
          ${d.bar_minimum     ? `<div class="sidebar-fact"><span>Bar min</span><strong>${esc(money(d.bar_minimum))}</strong></div>` : ''}
          ${tmpl              ? `<div class="sidebar-fact"><span>Template</span><strong>${esc(tmpl.name)}</strong></div>` : ''}
        </div>
        <div class="wizard-sidebar-note muted small">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          ${this.sourceEventId
            ? 'Fill in the steps to review this event. Its event and contract details are saved in place on the last step.'
            : 'Fill in the steps to build your event. A contract draft is created automatically on the last step.'}
        </div>
        ${this.sourceEventId ? '' : `<div class="wizard-sidebar-quick">
          <button class="button secondary small wide" data-quick-create>
            <i class="fa-solid fa-bolt" aria-hidden="true"></i> Quick Create instead
          </button>
        </div>`}
      </aside>`;
  }

  // ── Validation ────────────────────────────────────────────────────────────────

  _validateStep(step) {
    if (step.isReview) return [];
    const errors = [];
    for (const field of this._visibleFields(step)) {
      if (!field.required) continue;
      if (field.type === 'deal_type_picker') {
        if (!this.wizardData.deal_type) errors.push('Please select a deal type.');
        continue;
      }
      const val = String(this.wizardData[field.id] ?? '').trim();
      if (!val) errors.push(`${field.label} is required.`);
    }
    if (this.wizardData.end_date && this.wizardData.date && this.wizardData.end_date < this.wizardData.date) {
      errors.push('End Date cannot be before the start Date.');
    }
    return errors;
  }

  _getMissingRequired() {
    const missing = [];
    this.visibleSteps.forEach((step, stepIndex) => {
      if (step.isReview) return;
      this._visibleFields(step).forEach((field) => {
        if (!field.required) return;
        if (field.type === 'deal_type_picker') {
          if (!this.wizardData.deal_type) {
            missing.push({ fieldId: 'deal_type', label: 'Deal Type', stepTitle: step.title, stepIndex });
          }
          return;
        }
        const val = String(this.wizardData[field.id] ?? '').trim();
        if (!val) {
          missing.push({ fieldId: field.id, label: field.label, stepTitle: step.title, stepIndex });
        }
      });
    });
    return missing;
  }

  // ── Event binding ─────────────────────────────────────────────────────────────

  bind() {
    // ── Step breadcrumb navigation (back-navigate only) ──────────────────────
    $$('[data-goto]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = Number(btn.dataset.goto);
        if (target <= this.stepIndex) {
          this._captureStep();
          this.stepIndex = target;
          this.render();
        }
      });
    });

    // ── Back button ──────────────────────────────────────────────────────────
    $('[data-prev]', this)?.addEventListener('click', () => {
      this._captureStep();
      this.stepIndex = Math.max(0, this.stepIndex - 1);
      this.render();
    });

    // ── Next / Continue ──────────────────────────────────────────────────────
    $('[data-next]', this)?.addEventListener('click', () => {
      this._captureStep();
      const errors = this._validateStep(this.currentStep);
      if (errors.length) {
        this._showStepError(errors[0]);
        return;
      }
      this._clearStepError();
      this.stepIndex = Math.min(this.visibleSteps.length - 1, this.stepIndex + 1);
      this.render();
    });

    // ── Finish ────────────────────────────────────────────────────────────────
    $('[data-finish]', this)?.addEventListener('click', () => {
      this._captureStep();
      this._finish();
    });

    // ── Jump to step from review (missing field link or "Edit" button) ────────
    $$('[data-goto-step]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.stepIndex = Number(btn.dataset.gotoStep);
        this.render();
        const fieldId = btn.dataset.gotoField;
        if (fieldId) {
          setTimeout(() => $(`[name="${CSS.escape(fieldId)}"]`, this)?.focus(), 60);
        }
      });
    });

    // ── Live sidebar update on field change ───────────────────────────────────
    $('[data-wizard-form]', this)?.addEventListener('change', (e) => {
      if (e.target.name) this.wizardData[e.target.name] = e.target.value;
      // Keep the End Date picker's min in sync as the Date field changes, so
      // the browser's own date widget can't be used to pick an earlier day.
      if (e.target.name === 'date') {
        const endDateInput = $('input[name="end_date"]', this);
        if (endDateInput) endDateInput.min = e.target.value;
        const recurrenceFields = $('pb-recurrence-fields', this);
        if (recurrenceFields) recurrenceFields.anchorDate = e.target.value;
      }
      this._refreshSidebar();
    });

    // ── Deal type card picker ─────────────────────────────────────────────────
    $$('[data-deal-type]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const val = btn.dataset.dealType;
        this.wizardData.deal_type = val;
        const hidden = $('input[name="deal_type"]', this);
        if (hidden) hidden.value = val;
        $$('[data-deal-type]', this).forEach((b) => {
          b.classList.toggle('selected', b.dataset.dealType === val);
          b.setAttribute('aria-pressed', String(b.dataset.dealType === val));
        });
        this._autoSelectContractTemplate(val);
        this._refreshSidebar();
      });
    });

    // ── Contact typeahead ─────────────────────────────────────────────────────
    const contactInput = $('[data-contact-search]', this);
    if (contactInput) {
      contactInput.addEventListener('input', () => {
        clearTimeout(this._searchTimer);
        this._searchTimer = setTimeout(() => this._runContactSearch(contactInput), 280);
      });
      // Close dropdown on blur (delay so result click fires first)
      contactInput.addEventListener('blur', () => {
        setTimeout(() => {
          const drop = $('[data-contact-results]', this);
          if (drop) drop.hidden = true;
        }, 160);
      });
      contactInput.addEventListener('focus', () => {
        if (contactInput.value.length >= 2) {
          clearTimeout(this._searchTimer);
          this._searchTimer = setTimeout(() => this._runContactSearch(contactInput), 280);
        }
      });
    }

    // ── Recurring event pattern picker ────────────────────────────────────────
    // Only meaningful in create mode — editing an existing event never spins
    // off a series here (see _finish/_finishEdit).
    const recurrenceFields = $('pb-recurrence-fields', this);
    if (recurrenceFields) {
      recurrenceFields.anchorDate = this.wizardData.date || '';
      recurrenceFields.addEventListener('change', (e) => {
        this.wizardData.recurrence = e.detail; // { pattern, dates, description } or null
      });
    }

    // ── Quick-create fallback ─────────────────────────────────────────────────
    $('[data-quick-create]', this)?.addEventListener('click', () => this._openQuickCreateFallback());

    // ── Keyboard shortcut: Ctrl/Cmd+Enter = Next / Finish ────────────────────
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        ($('[data-next]', this) || $('[data-finish]', this))?.click();
      }
    }, { signal: this.abort.signal });
  }

  /** Leaves the wizard and opens the simple quick-create modal instead. */
  _openQuickCreateFallback() {
    if (confirm('Leave the wizard and use the simple quick-create form?')) {
      // Import openEventQuickCreate lazily to avoid a circular dep
      import('./event-views.js').then(({ openEventQuickCreate }) => {
        location.hash = '#events';
        openEventQuickCreate({ date: this.wizardData.date || null });
      }).catch(() => {
        location.hash = '#events';
      });
    }
  }

  // ── Data capture ──────────────────────────────────────────────────────────────

  /** Reads the visible form on the current step into wizardData. */
  _captureStep() {
    const form = $('[data-wizard-form]', this);
    if (!form) return;
    const fd = new FormData(form);
    for (const [key, value] of fd.entries()) {
      this.wizardData[key] = value;
    }
  }

  // ── Smart defaults ────────────────────────────────────────────────────────────

  /** Heuristically pick a contract template for a given deal type. */
  _autoSelectContractTemplate(dealType) {
    if (!this.meta?.contract_templates?.length) return;
    if (this.wizardData.contract_template_id) return; // user already chose one

    const keywords = {
      talent_buy:    ['talent', 'performance', 'booking', 'artist'],
      promoter_deal: ['promoter', 'promo', 'promoter deal'],
      rental:        ['rental', 'rent'],
      private_event: ['private', 'buyout'],
      residency:     ['residency', 'recurring', 'resident'],
    };
    const hints  = keywords[dealType] || [];
    const match  = this.meta.contract_templates.find(
      (t) => hints.some((h) => t.name.toLowerCase().includes(h))
    );
    if (match) {
      this.wizardData.contract_template_id = String(match.id);
    }
  }

  // ── Contact search ────────────────────────────────────────────────────────────

  async _runContactSearch(input) {
    const q       = input.value.trim();
    const results = $('[data-contact-results]', this);
    if (!results) return;

    if (q.length < 2) {
      results.hidden = true;
      return;
    }

    results.hidden  = false;
    results.innerHTML = '<div class="contact-searching muted small">Searching…</div>';

    try {
      const data     = await api(`/contacts?q=${encodeURIComponent(q)}&limit=8`);
      const contacts = Array.isArray(data) ? data : (data.contacts || []);

      if (!contacts.length) {
        results.innerHTML = '<div class="contact-no-results muted small">No contacts found — name will be used as typed.</div>';
        return;
      }

      results.innerHTML = contacts.map((c) => `
        <button class="contact-result" type="button"
                data-name="${esc(c.name || '')}"
                data-org="${esc(c.org || c.organization || '')}"
                data-email="${esc(c.email || '')}">
          <strong>${esc(c.name || '')}</strong>
          ${c.org || c.organization
            ? `<span class="muted small">${esc(c.org || c.organization)}</span>`
            : ''}
          ${c.email ? `<span class="muted small">${esc(c.email)}</span>` : ''}
        </button>`).join('');

      $$('.contact-result', results).forEach((btn) => {
        btn.addEventListener('click', () => {
          const n = btn.dataset.name;
          const o = btn.dataset.org;
          const e = btn.dataset.email;

          input.value = n;
          this.wizardData.counterparty_name  = n;
          if (o) this.wizardData.counterparty_org   = o;
          if (e) this.wizardData.counterparty_email = e;

          // Update sibling inputs in the same form if present
          const orgInput   = $('[name="counterparty_org"]',   this);
          const emailInput = $('[name="counterparty_email"]', this);
          if (orgInput   && o) orgInput.value   = o;
          if (emailInput && e) emailInput.value = e;

          results.hidden = true;
          this._refreshSidebar();
        });
      });
    } catch {
      results.innerHTML = '<div class="muted small">Search unavailable.</div>';
    }
  }

  // ── Step error UI ─────────────────────────────────────────────────────────────

  _showStepError(message) {
    let err = $('.wizard-step-error', this);
    if (!err) {
      const nav = $('[data-wizard-nav]', this);
      if (!nav) return;
      err = document.createElement('p');
      err.className = 'wizard-step-error error-text';
      nav.appendChild(err);
    }
    err.textContent = message;
    err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  _clearStepError() {
    $('.wizard-step-error', this)?.remove();
  }

  // ── Sidebar refresh (cheap, in-place) ────────────────────────────────────────

  _refreshSidebar() {
    const existing = $('.wizard-sidebar', this);
    if (!existing) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = this._sidebarHtml();
    existing.replaceWith(tmp.firstElementChild);
    // Re-bind quick-create button since we replaced the element
    $('.wizard-sidebar [data-quick-create]', this)?.addEventListener('click', () => this._openQuickCreateFallback());
  }

  _scrollToTop() {
    this.scrollIntoView?.({ behavior: 'instant', block: 'start' });
    window.scrollTo({ top: 0, behavior: 'instant' });
  }

  // ── Finish: create event + contract ──────────────────────────────────────────

  async _finish() {
    if (this._submitting) return;

    const missing = this._getMissingRequired();
    if (missing.length) {
      publish('toast.show', {
        message: `Please fill in: ${missing.slice(0, 3).map((m) => m.label).join(', ')}${missing.length > 3 ? '…' : ''}`,
        tone: 'error',
      });
      return;
    }

    const editing = !!this.sourceEventId;
    const btn = $('[data-finish]', this);
    if (btn) { btn.disabled = true; btn.textContent = editing ? 'Saving…' : 'Creating…'; }
    this._submitting = true;

    try {
      const { event: savedEvent, contract: savedContract } = editing
        ? await this._finishEdit()
        : await this._finishCreate();

      // ── Notify and navigate ─────────────────────────────────────────────
      publish('toast.show', {
        message: editing
          ? `"${savedEvent.title}" updated via the wizard.`
          : `"${savedEvent.title}" created with contract draft.`,
        tone: 'success',
      });
      publish('event.saved',      { id: savedEvent.id });
      publish('wizard.completed', { event: savedEvent, contract: savedContract });

      location.hash = `event-${savedEvent.id}`;

    } catch (error) {
      publish('toast.show', { message: error.message || `Could not ${editing ? 'save' : 'create'} event.`, tone: 'error' });
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> ${editing ? 'Save Changes' : 'Create Event &amp; Draft Contract'}`;
      }
      this._submitting = false;
    }
  }

  /** Create mode: POST a brand-new event + contract draft. */
  async _finishCreate() {
    // 1 ── Create the event ────────────────────────────────────────────────
    const eventPayload = this._buildEventPayload();
    const createdEvent = await api('/events', {
      method: 'POST',
      body:   JSON.stringify(eventPayload),
    });

    // 2 ── Spin off a recurring series, if the Basics step set one up ──────
    // Best-effort: the primary event already exists at this point, so a
    // failure here (e.g. a room conflict on one of the generated dates)
    // surfaces as a warning toast rather than aborting the whole wizard.
    const recurrence = this.wizardData.recurrence;
    if (recurrence?.dates?.length) {
      try {
        const seriesRes = await api(`/events/${createdEvent.id}/series`, {
          method: 'POST',
          body: JSON.stringify({
            pattern: recurrence.pattern,
            description: recurrence.description,
            end_type: recurrence.pattern.endType,
            end_date: recurrence.pattern.endDate || null,
            occurrence_count: recurrence.pattern.occurrenceCount || null,
            dates: recurrence.dates,
          }),
        });
        publish('toast.show', { message: `Also created ${seriesRes.created_event_ids.length} recurring events.`, tone: 'success' });
      } catch (err) {
        publish('toast.show', { message: `Event created, but the recurring series could not be created: ${err.message || err}`, tone: 'error' });
      }
    }

    // 3 ── Create the contract draft ───────────────────────────────────────
    const contractPayload = this._buildContractPayload(createdEvent);
    const createdContract = await api(`/events/${createdEvent.id}/contracts`, {
      method: 'POST',
      body:   JSON.stringify(contractPayload),
    });

    // 4 ── Patch deal terms onto the contract ──────────────────────────────
    const dealTerms = this._buildDealTerms();
    if (Object.keys(dealTerms).length) {
      await api(`/contracts/${createdContract.id}`, {
        method: 'PATCH',
        body:   JSON.stringify(dealTerms),
      });
    }

    // 5 ── Smart clause re-evaluation (non-fatal if it fails) ─────────────
    await api(`/contracts/${createdContract.id}/reevaluate`, { method: 'POST' })
      .catch((err) => console.warn('[wizard] reevaluate skipped:', err.message));

    return { event: createdEvent, contract: createdContract };
  }

  /**
   * Edit mode: PATCH the existing event in place rather than creating a new
   * one. `_buildEventPayload()` only returns the columns the wizard collects,
   * so it's layered on top of the full original row (fetched in
   * `_loadSourceEvent`) — any event field the wizard doesn't ask about (e.g.
   * internal notes, ticketing links) is carried over unchanged instead of
   * being nulled out by the PATCH.
   */
  async _finishEdit() {
    const eventId = this.sourceEventId;

    // 1 ── Update the event ────────────────────────────────────────────────
    const eventPayload = { ...(this._sourceEvent || {}), ...this._buildEventPayload() };
    await api(`/events/${eventId}`, {
      method: 'PATCH',
      body:   JSON.stringify(eventPayload),
    });
    const savedEvent = { ...this._sourceEvent, ...eventPayload, id: eventId };

    // 2 ── Update (or create) the contract ─────────────────────────────────
    const dealTerms = this._buildDealTerms();
    const counterparty = {};
    if (this.wizardData.counterparty_name)  counterparty.counterparty_name  = this.wizardData.counterparty_name;
    if (this.wizardData.counterparty_org)   counterparty.counterparty_org   = this.wizardData.counterparty_org;
    if (this.wizardData.counterparty_email) counterparty.counterparty_email = this.wizardData.counterparty_email;

    let savedContract = this._sourceContract;
    if (this._sourceContract) {
      const patch = { ...dealTerms, ...counterparty };
      if (Object.keys(patch).length) {
        await api(`/contracts/${this._sourceContract.id}`, {
          method: 'PATCH',
          body:   JSON.stringify(patch),
        });
      }
      await api(`/contracts/${this._sourceContract.id}/reevaluate`, { method: 'POST' })
        .catch((err) => console.warn('[wizard] reevaluate skipped:', err.message));
      savedContract = { ...this._sourceContract, ...patch };
    } else if (this.wizardData.deal_type) {
      // No existing contract on this event yet — create one, same as create mode.
      const contractPayload = this._buildContractPayload(savedEvent);
      const createdContract = await api(`/events/${eventId}/contracts`, {
        method: 'POST',
        body:   JSON.stringify(contractPayload),
      });
      if (Object.keys(dealTerms).length) {
        await api(`/contracts/${createdContract.id}`, {
          method: 'PATCH',
          body:   JSON.stringify(dealTerms),
        });
      }
      await api(`/contracts/${createdContract.id}/reevaluate`, { method: 'POST' })
        .catch((err) => console.warn('[wizard] reevaluate skipped:', err.message));
      savedContract = createdContract;
    }

    return { event: savedEvent, contract: savedContract };
  }

  // ── Payload builders ──────────────────────────────────────────────────────────

  _buildEventPayload() {
    const d = this.wizardData;
    const payload = { title: d.title, date: d.date };
    // Always sent (even blank) so clearing the End Date field on an existing
    // multi-day event collapses it back to a single-day event instead of the
    // stale value silently surviving via the sourceEvent spread in _finishEdit.
    payload.end_date = d.end_date || '';
    // Only new events default to "proposed" — editing an existing event must
    // never silently reset a further-along status (confirmed, booked, etc.).
    if (!this.sourceEventId) payload.status = 'proposed';
    if (d.venue_id)           payload.venue_id           = Number(d.venue_id);
    if (d.event_type)         payload.event_type         = d.event_type;
    if (d.doors_time)         payload.doors_time         = d.doors_time;
    if (d.show_time)          payload.show_time          = d.show_time;
    if (d.end_time)           payload.end_time           = d.end_time;
    if (d.capacity)           payload.capacity           = Number(d.capacity);
    if (d.age_restriction)    payload.age_restriction    = d.age_restriction;
    if (d.public_description) payload.public_description = d.public_description;
    // Default to hidden for brand-new events; user sets visibility via the
    // Promote tab after creation. In edit mode this is pre-filled from the
    // event's current value, so an untouched toggle carries it forward as-is.
    payload.public_visibility = (d.public_visibility !== '' && d.public_visibility !== undefined)
      ? Number(d.public_visibility)
      : 0;
    return payload;
  }

  _buildContractPayload(event) {
    const d        = this.wizardData;
    const dealType = DEAL_TYPES.find((dt) => dt.value === d.deal_type);
    const payload  = {
      title:         `${event.title} — ${dealType?.label || 'Contract'}`,
      contract_type: d.deal_type || 'talent_buy',
    };
    if (d.counterparty_name)  payload.counterparty_name  = d.counterparty_name;
    if (d.counterparty_org)   payload.counterparty_org   = d.counterparty_org;
    if (d.counterparty_email) payload.counterparty_email = d.counterparty_email;
    if (d.contract_template_id) payload.template_id      = Number(d.contract_template_id);
    return payload;
  }

  _buildDealTerms() {
    const d     = this.wizardData;
    const terms = {};
    NUMERIC_DEAL_FIELDS.forEach((k) => {
      if (d[k] !== '' && d[k] !== undefined) terms[k] = Number(d[k]);
    });
    STRING_DEAL_FIELDS.forEach((k) => {
      if (d[k]) terms[k] = d[k];
    });
    BOOL_DEAL_FIELDS.forEach((k) => {
      if (d[k] !== '' && d[k] !== undefined) terms[k] = Number(d[k]);
    });
    return terms;
  }
}

customElements.define('pb-event-wizard', EventContractWizard);
export { EventContractWizard };
