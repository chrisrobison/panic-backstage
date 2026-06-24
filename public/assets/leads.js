import { esc, titleCase, publish, api, formData, badge, option, select, can, emptyState, money, PanicElement, $, $$ } from './core.js';

// ── Leads Inbox ───────────────────────────────────────────────────────────────
// Three components:
//   pb-leads-page   — tabbed list view with inline create form
//   pb-lead-detail  — detail / edit panel with evaluator + notes
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
    this.selectedId = null;
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
    if (!can(this.capabilities, 'view_leads') && Object.keys(this.capabilities).length > 0) {
      this.innerHTML = `<div class="panel padded">${emptyState('Access denied — you need view_leads capability.')}</div>`;
      return;
    }

    const leads = this.filteredLeads();
    const canManage = can(this.capabilities, 'manage_leads');

    const tabBar = `<nav class="workspace-tabs tabs leads-tabs">
      ${LEAD_TABS.map((t) => {
        const count = t.key === 'all' ? this.leads.length : this.leads.filter((l) => l.status === t.key).length;
        return `<button type="button" data-leads-tab="${esc(t.key)}" class="${t.key === this.tab ? 'active' : ''}">${esc(t.label)}${count > 0 ? ` <span class="nav-badge" style="position:static;display:inline">${esc(String(count))}</span>` : ''}</button>`;
      }).join('')}
    </nav>`;

    const tableRows = leads.map((lead) => {
      const eventDate = lead.desired_date ? new Date(`${lead.desired_date}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
      const margin = lead.margin_pct != null ? `${Number(lead.margin_pct).toFixed(1)}%` : '—';
      return `<tr${this.selectedId === lead.id ? ' class="selected"' : ''}>
        <td><a href="#leads" data-review="${esc(lead.id)}">${esc(lead.event_name || lead.contact_name || 'Untitled')}</a></td>
        <td>${esc(titleCase(lead.source || ''))}</td>
        <td>${esc(lead.contact_name || '—')}</td>
        <td>${esc(eventDate)}</td>
        <td>${leadBadge(lead.status)}</td>
        <td>${esc(margin)}</td>
        <td class="row-actions">
          <button class="small" data-review="${esc(lead.id)}">Review</button>
          ${canManage ? `<button class="small danger" data-delete="${esc(lead.id)}">Delete</button>` : ''}
        </td>
      </tr>`;
    }).join('') || `<tr><td colspan="7">${emptyState('No leads in this category.')}</td></tr>`;

    const listPanel = `
      <article class="panel leads-list-panel">
        <div class="section-head padded">
          <h2>Leads</h2>
          ${canManage ? '<button class="primary" data-action="new-lead">+ New Lead</button>' : ''}
        </div>
        ${this.showForm ? '<div class="leads-form-slot padded"><pb-lead-form></pb-lead-form></div>' : ''}
        <table class="data-table">
          <thead><tr><th>Title</th><th>Source</th><th>Contact</th><th>Event Date</th><th>Status</th><th>Margin %</th><th></th></tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </article>`;

    if (this.selectedId) {
      this.innerHTML = `
        <div class="leads-split-layout">
          <div class="leads-split-list">
            ${tabBar}
            ${listPanel}
          </div>
          <div class="leads-split-detail">
            <pb-lead-detail></pb-lead-detail>
          </div>
        </div>`;
      const detail = $('pb-lead-detail', this);
      if (detail) detail.leadId = this.selectedId;
    } else {
      this.innerHTML = `
        <div class="leads-full-layout">
          ${tabBar}
          ${listPanel}
        </div>`;
    }

    this.bind();
  }

  bind() {
    $$('[data-leads-tab]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.tab = btn.dataset.leadsTab;
        this.selectedId = null;
        this.render();
      });
    });

    $$('[data-review]', this).forEach((el) => {
      el.addEventListener('click', (event) => {
        event.preventDefault();
        this.selectedId = Number(el.dataset.review);
        this.render();
      });
    });

    $$('[data-delete]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this lead?')) return;
        try {
          await api(`/leads/${btn.dataset.delete}`, { method: 'DELETE' });
          this.leads = this.leads.filter((l) => String(l.id) !== String(btn.dataset.delete));
          if (this.selectedId === Number(btn.dataset.delete)) this.selectedId = null;
          this.render();
          publish('toast.show', { tone: 'success', message: 'Lead deleted.' });
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
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
              this.selectedId = lead.id;
              this.render();
            };
          }
        }
      });
    }
  }
}


// ── pb-lead-detail ────────────────────────────────────────────────────────────
class LeadDetail extends PanicElement {
  set leadId(value) {
    this._leadId = Number(value);
    if (this.isConnected) this._load();
  }

  get leadId() { return this._leadId; }

  async connect() {
    if (this._leadId) this._load();
  }

  async _load() {
    this.setLoading('Loading lead');
    try {
      const data = await api(`/leads/${this._leadId}`);
      this.lead = data.lead || data;
      this.evaluation = data.evaluation || null;
      this.notes = data.notes || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const lead = this.lead || {};
    const ev = this.evaluation || {};
    const notes = this.notes || [];

    const eventDate = lead.desired_date ? lead.desired_date.slice(0, 10) : '';
    const displayDate = eventDate ? new Date(`${eventDate}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : '—';

    // Status flow buttons
    const statusBtns = LEAD_STATUSES.map((s) => `<button type="button" class="small${lead.status === s ? ' primary' : ''}" data-set-status="${esc(s)}">${esc(titleCase(s))}</button>`).join(' ');

    // Convert button
    const showConvert = ['approved', 'evaluating'].includes(lead.status);
    const convertBtn = showConvert ? `<button type="button" class="primary" data-action="convert">Convert to Event</button>` : '';

    // Risk flags
    let riskHtml = '';
    if (ev.flags) {
      const flags = ev.flags;
      if (flags.negative_margin || flags.venue_net_negative_with_guarantee) {
        riskHtml = `<div class="eval-risk"><span class="badge status-declined">High Risk — Negative margin or venue net</span></div>`;
      } else if (flags.projected_attendance_exceeds_capacity) {
        riskHtml = `<div class="eval-risk"><span class="badge status-triage">Attendance exceeds capacity</span></div>`;
      } else if (flags.low_margin_under_15_pct || flags.attendance_below_break_even || flags.bar_spend_below_minimum) {
        riskHtml = `<div class="eval-risk"><span class="badge status-needs_review">Low margin / attendance / bar risk</span></div>`;
      }
    }

    // Evaluation results
    const evalResults = ev.gross_revenue != null ? `
      <div class="eval-results">
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

    // Notes list
    const notesList = notes.length
      ? notes.slice().reverse().map((n) => `<div class="lead-note">
          <span class="muted note-meta">${esc(n.author_name || 'Unknown')} &middot; ${esc(n.created_at ? new Date(n.created_at).toLocaleDateString() : '')}</span>
          <p>${esc(n.body)}</p>
        </div>`).join('')
      : `<p class="muted">No notes yet.</p>`;

    this.innerHTML = `
      <article class="panel lead-detail-panel">
        <div class="section-head padded">
          <h2>${esc(lead.event_name || lead.contact_name || 'Lead Detail')}</h2>
          ${displayDate !== '—' ? `<p class="muted" style="margin:0 0 0 auto;padding-right:0.5rem">${esc(displayDate)}</p>` : ''}
          <div class="row-actions">${convertBtn}</div>
        </div>

        <section class="padded lead-info-section">
          <h3 class="section-label">Details</h3>
          <div class="form-row"><label>Event Name <input name="event_name" value="${esc(lead.event_name || '')}"></label></div>
          <div class="form-row"><label>Event Type
            <select name="event_type">
              ${EVENT_TYPES.map((t) => `<option value="${esc(t)}" ${lead.event_type === t ? 'selected' : ''}>${esc(titleCase(t))}</option>`).join('')}
            </select>
          </label></div>
          <div class="form-row"><label>Desired Date <input type="date" name="desired_date" value="${esc(eventDate)}"></label></div>
          <div class="form-row"><label>Source
            <select name="source">
              ${LEAD_SOURCES.map((s) => `<option value="${esc(s)}" ${lead.source === s ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}
            </select>
          </label></div>
          <div class="form-row"><label>Contact Name <input name="contact_name" value="${esc(lead.contact_name || '')}"></label></div>
          <div class="form-row"><label>Contact Email <input type="email" name="contact_email" value="${esc(lead.contact_email || '')}"></label></div>
          <div class="form-row"><label>Notes <textarea name="notes" rows="3">${esc(lead.notes || '')}</textarea></label></div>
          <div class="form-row"><button class="primary" data-action="save">Save Changes</button></div>
        </section>

        <section class="padded lead-status-section">
          <h3 class="section-label">Status Flow</h3>
          <div class="lead-status-btns">${statusBtns}</div>
          <p class="muted">Current: ${leadBadge(lead.status)}</p>
        </section>

        <section class="padded lead-eval-section">
          <h3 class="section-label">Deal Evaluator</h3>
          <div class="eval-fields-grid">
            ${EVAL_FIELDS.map((f) => `
              <div class="form-row">
                <label>${esc(f.label)} <input type="number" step="any" name="${esc(f.key)}" value="${esc(String(ev[f.key] ?? lead[f.key] ?? ''))}"></label>
              </div>`).join('')}
          </div>
          <div class="form-row"><button class="primary" data-action="calculate">Calculate</button></div>
          ${evalResults}
        </section>

        <section class="padded lead-notes-section">
          <h3 class="section-label">Notes</h3>
          <div class="lead-notes-list">${notesList}</div>
          <div class="form-row"><textarea name="new_note" rows="3" placeholder="Add a note..."></textarea></div>
          <div class="form-row"><button class="small" data-action="add-note">Add Note</button></div>
        </section>
      </article>`;

    this.bindDetail();
  }

  bindDetail() {
    const lead = this.lead || {};

    // Save button
    const saveBtn = $('[data-action="save"]', this);
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const section = saveBtn.closest('.lead-info-section');
        const payload = {
          event_name:    $('[name="event_name"]', section)?.value,
          event_type:    $('[name="event_type"]', section)?.value,
          desired_date:  $('[name="desired_date"]', section)?.value || null,
          source:        $('[name="source"]', section)?.value,
          contact_name:  $('[name="contact_name"]', section)?.value,
          contact_email: $('[name="contact_email"]', section)?.value,
          notes:         $('[name="notes"]', section)?.value,
        };
        try {
          const data = await api(`/leads/${this._leadId}`, { method: 'PATCH', body: JSON.stringify(payload) });
          this.lead = data.lead || data;
          publish('toast.show', { tone: 'success', message: 'Lead saved.' });
          this.render();
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    }

    // Status flow buttons
    $$('[data-set-status]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const data = await api(`/leads/${this._leadId}`, { method: 'PATCH', body: JSON.stringify({ status: btn.dataset.setStatus }) });
          this.lead = data.lead || data;
          publish('toast.show', { tone: 'success', message: `Status set to ${titleCase(btn.dataset.setStatus)}.` });
          this.render();
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    });

    // Convert to Event
    const convertBtn = $('[data-action="convert"]', this);
    if (convertBtn) {
      convertBtn.addEventListener('click', async () => {
        try {
          const data = await api(`/leads/${this._leadId}/convert`, { method: 'POST' });
          publish('toast.show', { tone: 'success', message: 'Converted to event.' });
          if (data.event_id) location.hash = `#event-${data.event_id}`;
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    }

    // Calculate evaluator
    const calcBtn = $('[data-action="calculate"]', this);
    if (calcBtn) {
      calcBtn.addEventListener('click', async () => {
        const section = calcBtn.closest('.lead-eval-section');
        const payload = {};
        EVAL_FIELDS.forEach((f) => {
          const val = $(`[name="${f.key}"]`, section)?.value;
          if (val !== '' && val != null) payload[f.key] = Number(val);
        });
        try {
          const data = await api(`/leads/${this._leadId}/evaluation`, { method: 'POST', body: JSON.stringify(payload) });
          this.evaluation = data.evaluation || data;
          publish('toast.show', { tone: 'success', message: 'Evaluation updated.' });
          this.render();
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    }

    // Add Note
    const noteBtn = $('[data-action="add-note"]', this);
    if (noteBtn) {
      noteBtn.addEventListener('click', async () => {
        const textarea = $('[name="new_note"]', this);
        const body = textarea?.value?.trim();
        if (!body) return;
        try {
          const data = await api(`/leads/${this._leadId}/notes`, { method: 'POST', body: JSON.stringify({ body }) });
          this.notes = data.notes || [...this.notes, data.note].filter(Boolean);
          textarea.value = '';
          publish('toast.show', { tone: 'success', message: 'Note added.' });
          this.render();
        } catch (error) {
          publish('toast.show', { tone: 'error', message: error.message });
        }
      });
    }
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
customElements.define('pb-lead-detail', LeadDetail);
customElements.define('pb-lead-form',   LeadForm);
