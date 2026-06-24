import { esc, titleCase, publish, api, formData, badge, option, select, can, emptyState, money, PanicElement, $, $$ } from './core.js';

// ── Leads Inbox ───────────────────────────────────────────────────────────────
// Components:
//   pb-leads-page   — tabbed list view with inline create form
//   pb-lead-modal   — tabbed modal dialog: Details / Status Flow / Deal Evaluator
//   pb-lead-form    — inline create form rendered inside pb-leads-page


const LEAD_TABS = [
  { key: 'all',          label: 'All' },
  { key: 'new',          label: 'New' },
  { key: 'triage',       label: 'Triage' },
  { key: 'evaluating',   label: 'Evaluating' },
  { key: 'needs_review', label: 'Needs Review' },
  { key: 'approved',     label: 'Approved' },
];

const LEAD_STATUSES = ['new', 'triage', 'evaluating', 'needs_review', 'approved', 'declined'];

const LEAD_SOURCES = ['website', 'referral', 'social_media', 'cold_outreach', 'repeat_client', 'other'];

const EVENT_TYPES = ['concert', 'private_event', 'festival', 'comedy_show', 'other'];

const EVAL_FIELDS = [
  { key: 'room_capacity',          label: 'Room Capacity' },
  { key: 'expected_attendance',    label: 'Expected Attendance' },
  { key: 'ticket_price',           label: 'Ticket Price' },
  { key: 'ticket_fee_per',         label: 'Ticket Fee Per' },
  { key: 'rental_fee',             label: 'Rental Fee' },
  { key: 'artist_guarantee',       label: 'Artist Guarantee' },
  { key: 'projected_bar_spend',    label: 'Projected Bar Spend' },
  { key: 'bar_minimum',            label: 'Bar Minimum' },
  { key: 'labor_forecast',         label: 'Labor Forecast' },
  { key: 'production_costs',       label: 'Production Costs' },
  { key: 'facility_costs',         label: 'Facility Costs' },
  { key: 'other_costs',            label: 'Other Costs' },
];


// ── Status badge for leads (different vocabulary from events) ─────────────────
function leadBadge(status) {
  return `<span class="badge status-${esc(status)}">${esc(titleCase(status))}</span>`;
}


// ── pb-leads-page ─────────────────────────────────────────────────────────────
class LeadsPage extends PanicElement {
  async connect() {
    this.tab = 'all';
    this.showForm = false;
    this.leads = [];
    this.capabilities = {};
    publish('page.context', { title: 'Leads Inbox', blurb: 'Track inbound inquiries through the evaluation pipeline.' });
    this.setLoading('Loading leads');
    try {
      const data = await api('/leads');
      this.leads = data.leads || [];
      this.capabilities = data.capabilities || {};
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  filteredLeads() {
    if (this.tab === 'all') return this.leads;
    return this.leads.filter((l) => l.status === this.tab);
  }

  render() {
    if (Object.keys(this.capabilities).length > 0 && !this.capabilities['view_leads']) {
      this.innerHTML = `<div class="panel padded">${emptyState('Access denied — you need view_leads capability.')}</div>`;
      return;
    }

    const leads = this.filteredLeads();
    const canManage = Boolean(this.capabilities['manage_leads']);

    const tabBar = `<nav class="workspace-tabs tabs leads-tabs">
      ${LEAD_TABS.map((t) => {
        const count = t.key === 'all' ? this.leads.length : this.leads.filter((l) => l.status === t.key).length;
        return `<button type="button" data-leads-tab="${esc(t.key)}" class="${t.key === this.tab ? 'active' : ''}">${esc(t.label)}${count > 0 ? ` <span class="nav-badge" style="position:static;display:inline">${esc(String(count))}</span>` : ''}</button>`;
      }).join('')}
    </nav>`;

    const tableRows = leads.map((lead) => {
      const eventDate = lead.desired_date ? new Date(`${lead.desired_date}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
      const margin = lead.margin_pct != null ? `${Number(lead.margin_pct).toFixed(1)}%` : '—';
      return `<tr class="leads-table-row" data-lead-id="${esc(lead.id)}" tabindex="0" role="button" aria-label="Open lead: ${esc(lead.event_name || lead.contact_name || 'Untitled')}">
        <td><span class="lead-title-link">${esc(lead.event_name || lead.contact_name || 'Untitled')}</span></td>
        <td>${esc(titleCase(lead.source || ''))}</td>
        <td>${esc(lead.contact_name || '—')}</td>
        <td>${esc(eventDate)}</td>
        <td>${leadBadge(lead.status)}</td>
        <td>${esc(margin)}</td>
      </tr>`;
    }).join('') || `<tr><td colspan="6">${emptyState('No leads in this category.')}</td></tr>`;

    this.innerHTML = `
      <div class="leads-full-layout">
        ${tabBar}
        <article class="panel leads-list-panel">
          <div class="section-head padded">
            <h2>Leads</h2>
            ${canManage ? '<button class="primary" data-action="new-lead">+ New Lead</button>' : ''}
          </div>
          ${this.showForm ? '<div class="leads-form-slot padded"><pb-lead-form></pb-lead-form></div>' : ''}
          <table class="data-table">
            <thead><tr><th>Title</th><th>Source</th><th>Contact</th><th>Event Date</th><th>Status</th><th>Margin %</th></tr></thead>
            <tbody>${tableRows}</tbody>
          </table>
        </article>
      </div>`;

    this.bind();
  }

  bind() {
    $$('[data-leads-tab]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.tab = btn.dataset.leadsTab;
        this.render();
      });
    });

    $$('.leads-table-row[data-lead-id]', this).forEach((row) => {
      const open = () => this._openModal(Number(row.dataset.leadId));
      row.addEventListener('click', open);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });

    const newBtn = $('[data-action="new-lead"]', this);
    if (newBtn) {
      newBtn.addEventListener('click', () => {
        this.showForm = !this.showForm;
        this.render();
        if (this.showForm) {
          const form = $('pb-lead-form', this);
          if (form) {
            form.onCreated = (lead) => {
              this.leads = [lead, ...this.leads];
              this.showForm = false;
              this.render();
              this._openModal(lead.id);
            };
          }
        }
      });
    }
  }

  _openModal(leadId) {
    // Remove any existing modal
    document.querySelector('pb-lead-modal')?.remove();

    const modal = document.createElement('pb-lead-modal');
    document.body.appendChild(modal);
    modal._capabilities = this.capabilities;

    modal.onUpdated = (updatedLead) => {
      const idx = this.leads.findIndex((l) => l.id === updatedLead.id);
      if (idx !== -1) this.leads[idx] = updatedLead;
      else this.leads = [updatedLead, ...this.leads];
      this.render();
    };

    modal.onDeleted = (deletedId) => {
      this.leads = this.leads.filter((l) => l.id !== deletedId);
      this.render();
    };

    // Setting leadId last triggers the load
    modal.leadId = leadId;
  }
}


// ── pb-lead-modal ─────────────────────────────────────────────────────────────
// Tabbed modal dialog: Details | Status Flow | Deal Evaluator
class LeadModal extends PanicElement {
  set leadId(value) {
    this._leadId = Number(value);
    this._tab = 'details';
    if (this.isConnected) this._load();
  }

  get leadId() { return this._leadId; }

  async connect() {
    document.body.classList.add('lead-modal-open');
    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this._close();
    }, { signal: this.abort.signal });

    if (this._leadId) this._load();
    else this._render();
  }

  disconnectedCallback() {
    document.body.classList.remove('lead-modal-open');
    super.disconnectedCallback();
  }

  async _load() {
    this._render(); // show skeleton/loading inside modal
    try {
      const data = await api(`/leads/${this._leadId}`);
      this.lead       = data.lead || data;
      this.evaluation = data.evaluation || null;
      this.notes      = data.notes || [];
      this._render();
    } catch (error) {
      this.innerHTML = `<div class="lead-modal-backdrop"><div class="lead-modal-card"><div class="lead-modal-header"><h2>Error</h2><button class="lead-modal-close" data-action="close">×</button></div><div class="lead-modal-body" style="padding:20px"><p class="error-text">${esc(error.message)}</p></div></div></div>`;
      $('[data-action="close"]', this)?.addEventListener('click', () => this._close());
    }
  }

  _render() {
    const lead  = this.lead  || {};
    const ev    = this.evaluation || {};
    const canManage = Boolean(this._capabilities?.['manage_leads']);

    const displayDate = lead.desired_date
      ? new Date(`${lead.desired_date.slice(0, 10)}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
      : null;

    const title = lead.event_name || lead.contact_name || (this._leadId ? 'Loading…' : 'Lead');

    // Tab content
    let body = '';
    if (!this.lead) {
      body = `<div style="padding:32px;text-align:center"><span class="spinner"></span></div>`;
    } else {
      switch (this._tab) {
        case 'details':   body = this._tabDetails();   break;
        case 'status':    body = this._tabStatus();    break;
        case 'evaluator': body = this._tabEvaluator(); break;
      }
    }

    // Footer: context-aware save label
    const saveLabel = this._tab === 'evaluator' ? 'Calculate & Save' : 'Save Changes';
    const saveHidden = (this._tab === 'status') ? ' style="visibility:hidden"' : '';

    this.innerHTML = `
      <div class="lead-modal-backdrop" data-backdrop>
        <div class="lead-modal-card" role="dialog" aria-modal="true" aria-label="Lead: ${esc(title)}">

          <div class="lead-modal-header">
            <div class="lead-modal-title-block">
              <h2 class="lead-modal-title">${esc(title)}</h2>
              ${displayDate ? `<span class="lead-modal-date">${esc(displayDate)}</span>` : ''}
            </div>
            ${lead.status ? leadBadge(lead.status) : ''}
            <button class="lead-modal-close" data-action="close" aria-label="Close">×</button>
          </div>

          <nav class="lead-modal-tabs">
            <button class="${this._tab === 'details'   ? 'active' : ''}" data-modal-tab="details">Details</button>
            <button class="${this._tab === 'status'    ? 'active' : ''}" data-modal-tab="status">Status Flow</button>
            <button class="${this._tab === 'evaluator' ? 'active' : ''}" data-modal-tab="evaluator">Deal Evaluator</button>
          </nav>

          <div class="lead-modal-body">
            ${body}
          </div>

          <div class="lead-modal-footer">
            ${canManage ? `<button class="small danger" data-action="delete">Delete Lead</button>` : ''}
            <span style="flex:1"></span>
            <button class="primary" data-action="save"${saveHidden}>${esc(saveLabel)}</button>
          </div>

        </div>
      </div>`;

    this._bind();
  }

  // ── Tab: Details ────────────────────────────────────────────────────────────
  _tabDetails() {
    const lead  = this.lead  || {};
    const notes = this.notes || [];
    const eventDate = lead.desired_date ? lead.desired_date.slice(0, 10) : '';

    const notesList = notes.length
      ? notes.slice().reverse().map((n) => `
          <div class="lead-note">
            <span class="muted note-meta">${esc(n.author_name || 'Unknown')} &middot; ${esc(n.created_at ? new Date(n.created_at).toLocaleDateString() : '')}</span>
            <p>${esc(n.body)}</p>
          </div>`).join('')
      : `<p class="muted" style="font-size:13px">No notes yet.</p>`;

    const showConvert = ['approved', 'evaluating'].includes(lead.status);

    return `
      <div class="lead-modal-section">
        <div class="form-grid-2">
          <label class="field-label">Event Name
            <input name="event_name" value="${esc(lead.event_name || '')}">
          </label>
          <label class="field-label">Event Type
            <select name="event_type">
              ${EVENT_TYPES.map((t) => `<option value="${esc(t)}" ${lead.event_type === t ? 'selected' : ''}>${esc(titleCase(t))}</option>`).join('')}
            </select>
          </label>
          <label class="field-label">Desired Date
            <input type="date" name="desired_date" value="${esc(eventDate)}">
          </label>
          <label class="field-label">Source
            <select name="source">
              ${LEAD_SOURCES.map((s) => `<option value="${esc(s)}" ${lead.source === s ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}
            </select>
          </label>
          <label class="field-label">Contact Name
            <input name="contact_name" value="${esc(lead.contact_name || '')}">
          </label>
          <label class="field-label">Contact Email
            <input type="email" name="contact_email" value="${esc(lead.contact_email || '')}">
          </label>
        </div>
        <label class="field-label" style="display:flex;flex-direction:column;margin-bottom:16px">Notes
          <textarea name="notes" rows="3">${esc(lead.notes || '')}</textarea>
        </label>

        ${showConvert ? `<div style="margin-bottom:16px"><button type="button" class="small primary" data-action="convert">Convert to Event ↗</button></div>` : ''}

        <details class="lead-notes-details">
          <summary class="section-label" style="cursor:pointer;margin-bottom:10px">Activity Notes (${notes.length})</summary>
          <div class="lead-notes-list" style="margin-bottom:12px">${notesList}</div>
          <label class="field-label" style="display:flex;flex-direction:column">Add Note
            <textarea name="new_note" rows="2" placeholder="Add a note…"></textarea>
          </label>
          <div style="margin-top:6px"><button class="small" data-action="add-note">Add Note</button></div>
        </details>
      </div>`;
  }

  // ── Tab: Status Flow ────────────────────────────────────────────────────────
  _tabStatus() {
    const lead = this.lead || {};
    return `
      <div class="lead-modal-section">
        <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Click a status to move this lead through the pipeline. Changes apply immediately.</p>
        <div class="lead-status-flow">
          ${LEAD_STATUSES.map((s) => `
            <button type="button" class="status-flow-btn${lead.status === s ? ' active' : ''}" data-set-status="${esc(s)}">
              ${esc(titleCase(s))}
            </button>`).join('')}
        </div>
        <p style="margin:18px 0 0;font-size:13px">Current: ${leadBadge(lead.status)}</p>
      </div>`;
  }

  // ── Tab: Deal Evaluator ─────────────────────────────────────────────────────
  _tabEvaluator() {
    const lead = this.lead  || {};
    const ev   = this.evaluation || {};

    // Risk flags
    let riskHtml = '';
    if (ev.flags) {
      const f = ev.flags;
      if (f.negative_margin || f.venue_net_negative_with_guarantee) {
        riskHtml = `<div class="eval-risk"><span class="badge status-declined">High Risk — Negative margin or venue net</span></div>`;
      } else if (f.projected_attendance_exceeds_capacity) {
        riskHtml = `<div class="eval-risk"><span class="badge status-triage">Attendance exceeds capacity</span></div>`;
      } else if (f.low_margin_under_15_pct || f.attendance_below_break_even || f.bar_spend_below_minimum) {
        riskHtml = `<div class="eval-risk"><span class="badge status-needs_review">Low margin / attendance / bar risk</span></div>`;
      }
    }

    // Results grid
    const resultsHtml = ev.gross_revenue != null ? `
      <div class="eval-results" style="margin-top:20px">
        <h4 class="section-label" style="margin:0 0 10px">Results</h4>
        <div class="eval-result-grid">
          <div class="eval-result-item"><span class="muted">Gross Revenue</span><strong>${esc(money(ev.gross_revenue))}</strong></div>
          <div class="eval-result-item"><span class="muted">Estimated Cost</span><strong>${esc(money(ev.estimated_cost))}</strong></div>
          <div class="eval-result-item"><span class="muted">Venue Net</span><strong>${esc(money(ev.venue_net))}</strong></div>
          <div class="eval-result-item"><span class="muted">Margin %</span><strong>${esc(Number(ev.margin_pct || 0).toFixed(1))}%</strong></div>
          <div class="eval-result-item"><span class="muted">Break-even Tickets</span><strong>${esc(String(ev.breakeven_tickets ?? '—'))}</strong></div>
          <div class="eval-result-item"><span class="muted">Min Guarantee Tickets</span><strong>${esc(String(ev.min_guarantee_tickets ?? '—'))}</strong></div>
        </div>
        ${riskHtml}
      </div>` : '';

    return `
      <div class="lead-modal-section">
        <p style="margin:0 0 14px;font-size:13px;color:var(--muted)">Enter figures and click Calculate & Save to evaluate the deal.</p>
        <div class="eval-fields-compact">
          ${EVAL_FIELDS.map((f) => `
            <label class="eval-field-item">
              <span>${esc(f.label)}</span>
              <input type="number" step="any" name="${esc(f.key)}" value="${esc(String(ev[f.key] ?? lead[f.key] ?? ''))}">
            </label>`).join('')}
        </div>
        ${resultsHtml}
      </div>`;
  }

  // ── Event binding ───────────────────────────────────────────────────────────
  _bind() {
    // Backdrop click closes (click on card does not)
    $('[data-backdrop]', this)?.addEventListener('click', (e) => {
      if (e.target === e.currentTarget) this._close();
    });

    // Close button
    $('[data-action="close"]', this)?.addEventListener('click', () => this._close());

    // Tab switching
    $$('[data-modal-tab]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this._tab = btn.dataset.modalTab;
        this._render();
      });
    });

    // Save / Calculate
    $('[data-action="save"]', this)?.addEventListener('click', () => {
      if (this._tab === 'evaluator') this._calculate();
      else this._saveDetails();
    });

    // Delete
    $('[data-action="delete"]', this)?.addEventListener('click', () => this._delete());

    // Status flow
    $$('[data-set-status]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const data = await api(`/leads/${this._leadId}`, { method: 'PATCH', body: JSON.stringify({ status: btn.dataset.setStatus }) });
          this.lead = data.lead || data;
          publish('toast.show', { tone: 'success', message: `Status: ${titleCase(btn.dataset.setStatus)}` });
          if (typeof this.onUpdated === 'function') this.onUpdated(this.lead);
          this._render();
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    });

    // Convert
    $('[data-action="convert"]', this)?.addEventListener('click', async () => {
      try {
        const data = await api(`/leads/${this._leadId}/convert`, { method: 'POST' });
        publish('toast.show', { tone: 'success', message: 'Converted to event.' });
        this._close();
        if (data.event_id) location.hash = `#event-${data.event_id}`;
      } catch (error) {
        publish('toast.show', { tone: 'error', message: error.message });
      }
    });

    // Add note
    $('[data-action="add-note"]', this)?.addEventListener('click', async () => {
      const textarea = $('[name="new_note"]', this);
      const body = textarea?.value?.trim();
      if (!body) return;
      try {
        const data = await api(`/leads/${this._leadId}/notes`, { method: 'POST', body: JSON.stringify({ body }) });
        this.notes = data.notes || [...(this.notes || []), data.note].filter(Boolean);
        textarea.value = '';
        publish('toast.show', { tone: 'success', message: 'Note added.' });
        this._render();
      } catch (error) {
        publish('toast.show', { tone: 'error', message: error.message });
      }
    });
  }

  // ── Actions ─────────────────────────────────────────────────────────────────
  async _saveDetails() {
    const payload = {
      event_name:    $('[name="event_name"]',    this)?.value,
      event_type:    $('[name="event_type"]',    this)?.value,
      desired_date:  $('[name="desired_date"]',  this)?.value || null,
      source:        $('[name="source"]',        this)?.value,
      contact_name:  $('[name="contact_name"]',  this)?.value,
      contact_email: $('[name="contact_email"]', this)?.value,
      notes:         $('[name="notes"]',         this)?.value,
    };
    // Drop undefined keys (fields not in DOM on this tab)
    Object.keys(payload).forEach((k) => payload[k] === undefined && delete payload[k]);

    try {
      const data = await api(`/leads/${this._leadId}`, { method: 'PATCH', body: JSON.stringify(payload) });
      this.lead = data.lead || data;
      publish('toast.show', { tone: 'success', message: 'Lead saved.' });
      if (typeof this.onUpdated === 'function') this.onUpdated(this.lead);
      this._render();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async _calculate() {
    const payload = {};
    EVAL_FIELDS.forEach((f) => {
      const val = $(`[name="${f.key}"]`, this)?.value;
      if (val !== '' && val != null) payload[f.key] = Number(val);
    });
    try {
      const data = await api(`/leads/${this._leadId}/evaluation`, { method: 'POST', body: JSON.stringify(payload) });
      this.evaluation = data.evaluation || data;
      publish('toast.show', { tone: 'success', message: 'Evaluation saved.' });
      this._render();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async _delete() {
    if (!confirm('Permanently delete this lead?')) return;
    try {
      await api(`/leads/${this._leadId}`, { method: 'DELETE' });
      publish('toast.show', { tone: 'success', message: 'Lead deleted.' });
      if (typeof this.onDeleted === 'function') this.onDeleted(this._leadId);
      this._close();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  _close() {
    this.remove();
  }
}


// ── pb-lead-form ──────────────────────────────────────────────────────────────
class LeadForm extends PanicElement {
  async connect() {
    this.render();
  }

  render() {
    this.innerHTML = `
      <form class="lead-create-form grid-form" data-form="create-lead">
        <h3>New Lead</h3>
        <div class="form-row"><label>Event Name* <input name="event_name" required placeholder="e.g. The Midnight – Saturday Show"></label></div>
        <div class="form-row"><label>Event Type
          <select name="event_type">
            ${EVENT_TYPES.map((t) => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('')}
          </select>
        </label></div>
        <div class="form-row"><label>Contact Name <input name="contact_name" placeholder="Promoter or artist name"></label></div>
        <div class="form-row"><label>Contact Email <input type="email" name="contact_email" placeholder="contact@example.com"></label></div>
        <div class="form-row"><label>Source
          <select name="source">
            ${LEAD_SOURCES.map((s) => `<option value="${esc(s)}">${esc(titleCase(s))}</option>`).join('')}
          </select>
        </label></div>
        <div class="form-row"><label>Desired Date <input type="date" name="desired_date"></label></div>
        <div class="form-row"><label>Notes <textarea name="notes" rows="3" placeholder="Brief overview of the inquiry"></textarea></label></div>
        <div class="form-row row-actions">
          <button type="submit" class="primary">Create Lead</button>
          <button type="button" data-action="cancel">Cancel</button>
        </div>
      </form>`;

    $('[data-form="create-lead"]', this).addEventListener('submit', (event) => this.submit(event));
    $('[data-action="cancel"]', this)?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('lead.cancel', { bubbles: true }));
    });
  }

  async submit(event) {
    event.preventDefault();
    const form = event.target;
    const payload = formData(form);
    if (!payload.desired_date) delete payload.desired_date;
    try {
      const data = await api('/leads', { method: 'POST', body: JSON.stringify(payload) });
      const lead = data.lead || data;
      publish('toast.show', { tone: 'success', message: 'Lead created.' });
      this.dispatchEvent(new CustomEvent('lead.created', { bubbles: true, detail: { lead } }));
      if (typeof this.onCreated === 'function') this.onCreated(lead);
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }
}


customElements.define('pb-leads-page',  LeadsPage);
customElements.define('pb-lead-modal',  LeadModal);
customElements.define('pb-lead-form',   LeadForm);
