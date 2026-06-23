// ── Event Closeout & Billing panel ───────────────────────────────────────────
// Ledger line items (revenue / costs / payments) and P&L summary with a
// closeout checklist and finalize / reopen workflow.

import { esc, titleCase, api, publish, money, PanicElement, $, $$ } from './core.js';

// ── Category lists by line_type ───────────────────────────────────────────────
const REVENUE_CATEGORIES = [
  'tickets', 'ticket_fees', 'bar_sales', 'rental_fee', 'hosted_bar',
  'merch_share', 'sponsorship', 'equipment_rental', 'overtime_charge', 'other_revenue',
];
const COST_CATEGORIES = [
  'artist_guarantee', 'promoter_settlement', 'labor', 'sound_production',
  'security', 'cleaning', 'rentals', 'catering', 'vendor_cost',
  'processing_fees', 'taxes', 'refunds', 'other_cost',
];
const PAYMENT_CATEGORIES = [
  'deposit_received', 'balance_payment', 'refund_issued', 'credit_applied', 'adjustment',
];

const CATEGORIES_BY_TYPE = {
  revenue: REVENUE_CATEGORIES,
  cost:    COST_CATEGORIES,
  payment: PAYMENT_CATEGORIES,
};

// ── Checklist fields and their display labels ─────────────────────────────────
const CHECKLIST_FIELDS = [
  ['contract_signed',        'Contract Signed'],
  ['deposit_received',       'Deposit Received'],
  ['vendors_confirmed',      'Vendors Confirmed'],
  ['staffing_confirmed',     'Staffing Confirmed'],
  ['bar_closed',             'Bar Closed'],
  ['cash_reconciled',        'Cash Reconciled'],
  ['all_invoices_collected', 'All Invoices Collected'],
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function categoryOptions(lineType, selected = '') {
  const cats = CATEGORIES_BY_TYPE[lineType] || REVENUE_CATEGORIES;
  return cats.map(c =>
    `<option value="${esc(c)}"${c === selected ? ' selected' : ''}>${esc(titleCase(c))}</option>`
  ).join('');
}

function formatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? esc(value) : esc(d.toLocaleDateString(undefined, { dateStyle: 'medium' }));
}

// ── Main component ────────────────────────────────────────────────────────────
class EventCloseout extends PanicElement {
  // Properties set by the workspace before mounting
  // Properties set by the workspace after DOM insertion:
  //   eventId, canEdit, canFinalize
  // Use a backing field + setter so load() fires when eventId is assigned,
  // not on connect() — connect() fires before the workspace sets the property.

  get eventId()  { return this._eventId; }
  set eventId(v) {
    this._eventId = v;
    if (v) this.load();
  }

  async connect() {
    // load() is triggered by set eventId() once the workspace wires us up.
    // Guard here handles the rare case where eventId was set before insertion.
    if (this._eventId) await this.load();
  }

  async load() {
    this.setLoading('Loading closeout data');
    try {
      const [ledger, summary] = await Promise.all([
        api(`/events/${this.eventId}/ledger`),
        api(`/events/${this.eventId}/ledger/summary`),
      ]);
      this._ledger  = ledger;
      this._summary = summary;
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  async reloadAll() {
    try {
      const [ledger, summary] = await Promise.all([
        api(`/events/${this.eventId}/ledger`),
        api(`/events/${this.eventId}/ledger/summary`),
      ]);
      this._ledger  = ledger;
      this._summary = summary;
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async refreshSummary() {
    try {
      this._summary = await api(`/events/${this.eventId}/ledger/summary`);
      this._renderSummary();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  render() {
    const entries   = this._ledger?.entries   || [];
    const closeout  = this._ledger?.closeout  || {};
    const finalized = Boolean(closeout.finalized_at);
    const editable  = Boolean(this.canEdit) && !finalized;

    // ── Partition entries by type ─────────────────────────────────────────────
    const revenue  = entries.filter(e => e.line_type === 'revenue');
    const costs    = entries.filter(e => e.line_type === 'cost');
    const payments = entries.filter(e => e.line_type === 'payment');

    const entryRow = (entry) => {
      const voided = Number(entry.is_void);
      const style  = voided ? ' style="text-decoration:line-through;opacity:0.4"' : '';
      const voidBtn = (editable && !voided)
        ? `<button type="button" class="small danger" data-void="${esc(String(entry.id))}">Void</button>`
        : '';
      return `<tr${style}>
        <td>${esc(titleCase(entry.category))}</td>
        <td>${esc(entry.description || '')}</td>
        <td class="amount">${esc(money(entry.amount))}</td>
        <td>${voidBtn}</td>
      </tr>`;
    };

    const subtotalRow = (label, total) =>
      `<tr class="subtotal-row">
        <td colspan="2"><strong>${esc(label)}</strong></td>
        <td class="amount"><strong>${esc(money(total))}</strong></td>
        <td></td>
      </tr>`;

    const groupSum = (arr) => arr.filter(e => !Number(e.is_void)).reduce((s, e) => s + Number(e.amount), 0);

    const groupTable = (label, arr, accent) => {
      if (!arr.length && !editable) return '';
      const rows = arr.length
        ? arr.map(entryRow).join('') + subtotalRow(`${label} subtotal`, groupSum(arr))
        : `<tr><td colspan="4" class="entry-empty">No ${label.toLowerCase()} entries yet.</td></tr>`;
      return `<div class="entry-group" style="--group-accent:${accent}">
        <h3 class="group-head" style="border-left:3px solid ${accent};padding-left:0.5rem">${esc(label)}</h3>
        <table class="entry-table">
          <thead><tr><th>Category</th><th>Description</th><th>Amount</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
    };

    // ── Add-entry inline form ─────────────────────────────────────────────────
    const addForm = editable ? `
      <div class="add-entry-wrap" id="add-entry-wrap" hidden>
        <form class="row-form add-entry-form" id="add-entry-form">
          <div class="form-row">
            <fieldset class="linetype-group">
              <legend>Type</legend>
              <label class="radio-label"><input type="radio" name="line_type" value="revenue" checked> Revenue</label>
              <label class="radio-label"><input type="radio" name="line_type" value="cost"> Cost</label>
              <label class="radio-label"><input type="radio" name="line_type" value="payment"> Payment</label>
            </fieldset>
          </div>
          <div class="form-row">
            <label>Category
              <select name="category" id="entry-category">
                ${categoryOptions('revenue')}
              </select>
            </label>
            <label>Amount
              <input type="number" name="amount" step="0.01" min="0" placeholder="0.00" required>
            </label>
            <label class="wide">Description
              <input type="text" name="description" placeholder="e.g. Door sales Saturday night">
            </label>
          </div>
          <div class="form-actions">
            <button type="submit">Add Entry</button>
            <button type="button" class="secondary small" id="cancel-add-entry">Cancel</button>
          </div>
        </form>
      </div>` : '';

    // ── All checklist items checked? ──────────────────────────────────────────
    const allChecked = CHECKLIST_FIELDS.every(([field]) => Boolean(closeout[field]));

    // ── Checklist HTML ────────────────────────────────────────────────────────
    const checklistDisabled = !editable ? ' disabled' : '';
    const checklistItems = CHECKLIST_FIELDS.map(([field, label]) => {
      const checked = Boolean(closeout[field]) ? ' checked' : '';
      return `<label class="check-label">
        <input type="checkbox" data-checklist="${esc(field)}"${checked}${checklistDisabled}>
        ${esc(label)}
      </label>`;
    }).join('');

    // ── Finalize / reopen controls ────────────────────────────────────────────
    let finalizeBlock = '';
    if (finalized) {
      finalizeBlock = `<div class="panel-success">Finalized on ${formatDate(closeout.finalized_at)}</div>`;
      if (this.canFinalize) {
        finalizeBlock += `
          <div class="reopen-block">
            <label class="wide">Reason for reopening
              <textarea id="reopen-reason" rows="2" placeholder="Explain why this closeout is being reopened…"></textarea>
            </label>
            <button type="button" class="danger small" id="btn-reopen">Reopen Closeout</button>
          </div>`;
      }
    } else if (this.canFinalize) {
      const disabled = allChecked ? '' : ' disabled';
      finalizeBlock = `<button type="button" class="primary" id="btn-finalize"${disabled}>Finalize Closeout</button>
        ${!allChecked ? '<p class="finalize-hint">Complete all checklist items to enable finalize.</p>' : ''}`;
    }

    this.innerHTML = `
      <section class="panel">
        <div class="section-head padded">
          <h2>Closeout &amp; Billing</h2>
          ${editable ? '<button type="button" class="secondary small" id="btn-add-entry"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Entry</button>' : ''}
        </div>
        <div class="closeout-layout">

          <!-- Left: Line Items -->
          <article class="panel closeout-panel-left">
            <h3 class="panel-subtitle">Line Items</h3>
            ${addForm}
            ${groupTable('Revenue', revenue, 'var(--green, #0f8f46)')}
            ${groupTable('Costs',   costs,   'var(--red,   #ef4338)')}
            ${groupTable('Payments', payments, 'var(--blue,  #1268c7)')}
          </article>

          <!-- Right: P&L Summary + Closeout Checklist -->
          <article class="panel closeout-panel-right">
            <div id="summary-card">
              ${this._summaryHTML()}
            </div>

            <div class="closeout-checklist">
              <h3 class="panel-subtitle">Closeout Checklist</h3>
              <div class="checklist-items">${checklistItems}</div>
            </div>

            <div class="finalize-block">
              ${finalizeBlock}
            </div>
          </article>

        </div>
      </section>

      <style>
        .closeout-layout {
          display: flex;
          gap: 1.5rem;
          padding: 1rem;
          align-items: flex-start;
        }
        .closeout-panel-left  { flex: 2 1 0; min-width: 0; }
        .closeout-panel-right { flex: 1 1 0; min-width: 260px; }
        .closeout-panel-left,
        .closeout-panel-right {
          padding: 1rem;
          border: 1px solid var(--line, #dfe3e8);
          border-radius: 10px;
          background: var(--panel, #fff);
        }
        .panel-subtitle {
          margin: 0 0 0.75rem;
          font-size: 0.95rem;
          font-weight: 700;
          color: var(--muted, #6f7582);
          text-transform: uppercase;
          letter-spacing: 0.04em;
        }
        .entry-group { margin-bottom: 1.5rem; }
        .group-head { margin: 0 0 0.5rem; font-size: 0.9rem; font-weight: 700; }
        .entry-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .entry-table th {
          text-align: left;
          padding: 4px 6px;
          font-size: 0.78rem;
          color: var(--muted, #6f7582);
          border-bottom: 1px solid var(--line, #dfe3e8);
        }
        .entry-table td { padding: 5px 6px; border-bottom: 1px solid var(--line, #dfe3e8); vertical-align: middle; }
        .entry-table td.amount { text-align: right; font-variant-numeric: tabular-nums; }
        .entry-table tr.subtotal-row td { background: var(--soft, #eef0f3); }
        .entry-empty { color: var(--muted, #6f7582); font-style: italic; text-align: center; padding: 1rem 0 !important; }
        .add-entry-wrap { margin-bottom: 1rem; padding: 0.75rem; background: var(--soft, #eef0f3); border-radius: 8px; }
        .add-entry-form .form-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; margin-bottom: 0.5rem; }
        .add-entry-form label { flex: 1 1 140px; font-size: 0.85rem; }
        .add-entry-form label.wide { flex: 2 1 240px; }
        .add-entry-form .form-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .linetype-group { border: none; padding: 0; display: flex; gap: 0.75rem; }
        .linetype-group legend { font-size: 0.8rem; font-weight: 600; color: var(--muted, #6f7582); margin-bottom: 4px; }
        .radio-label { display: flex; align-items: center; gap: 0.3rem; font-size: 0.85rem; font-weight: normal; }
        .radio-label input { width: auto; }
        .summary-card { font-size: 0.9rem; margin-bottom: 1.25rem; }
        .summary-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--line, #dfe3e8); }
        .summary-row:last-child { border-bottom: none; }
        .summary-row .label { color: var(--muted, #6f7582); }
        .summary-row .value { font-weight: 700; font-variant-numeric: tabular-nums; }
        .summary-actions { margin-top: 0.5rem; }
        .checklist-items { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1rem; }
        .check-label { display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; }
        .check-label input[type="checkbox"] { width: auto; accent-color: var(--blue, #1268c7); }
        .finalize-block { margin-top: 0.75rem; }
        .finalize-hint { font-size: 0.8rem; color: var(--muted, #6f7582); margin: 0.4rem 0 0; }
        button.primary { background: var(--blue, #1268c7); color: #fff; border-color: var(--blue, #1268c7); }
        button.primary:disabled { opacity: 0.45; cursor: not-allowed; }
        .panel-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 8px; padding: 0.6rem 0.9rem; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.75rem; }
        .reopen-block { margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .reopen-block label { font-size: 0.85rem; }
        @media (max-width: 860px) {
          .closeout-layout { flex-direction: column; }
          .closeout-panel-left,
          .closeout-panel-right { flex: none; width: 100%; }
        }
      </style>`;

    this._bind();
  }

  _summaryHTML() {
    const s = this._summary || {};
    const venueNet = Number(s.venueNet || 0);
    const netColor = venueNet >= 0 ? 'var(--green, #0f8f46)' : 'var(--red, #ef4338)';
    return `<div class="summary-card">
      <h3 class="panel-subtitle">P&amp;L Summary</h3>
      <div class="summary-row"><span class="label">Gross Revenue</span><span class="value">${esc(money(s.grossRevenue || 0))}</span></div>
      <div class="summary-row"><span class="label">Total Costs</span><span class="value">${esc(money(s.totalCosts || 0))}</span></div>
      <div class="summary-row"><span class="label">Venue Net</span><span class="value" style="color:${netColor}">${esc(money(venueNet))}</span></div>
      <div class="summary-row"><span class="label">Margin</span><span class="value">${esc(String(s.marginPct != null ? Number(s.marginPct).toFixed(1) : '0.0'))}%</span></div>
      <div class="summary-row"><span class="label">Payments Received</span><span class="value">${esc(money(s.totalPayments || 0))}</span></div>
      <div class="summary-actions">
        <button type="button" class="secondary small" id="btn-refresh-summary"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh</button>
      </div>
    </div>`;
  }

  _renderSummary() {
    const card = $('#summary-card', this);
    if (card) card.innerHTML = this._summaryHTML();
    this._bindSummary();
  }

  _bindSummary() {
    const refreshBtn = $('#btn-refresh-summary', this);
    if (refreshBtn) refreshBtn.addEventListener('click', () => this.refreshSummary(), { once: true });
  }

  _bind() {
    // Refresh summary button
    this._bindSummary();

    // Toggle add-entry form
    const btnAdd = $('#btn-add-entry', this);
    const addWrap = $('#add-entry-wrap', this);
    if (btnAdd && addWrap) {
      btnAdd.addEventListener('click', () => {
        addWrap.hidden = !addWrap.hidden;
      });
    }

    // Cancel add-entry
    const cancelAdd = $('#cancel-add-entry', this);
    if (cancelAdd && addWrap) {
      cancelAdd.addEventListener('click', () => { addWrap.hidden = true; });
    }

    // Update category select when line_type changes
    const form = $('#add-entry-form', this);
    if (form) {
      $$('input[name="line_type"]', form).forEach(radio => {
        radio.addEventListener('change', () => {
          const catSel = $('#entry-category', form);
          if (catSel) catSel.innerHTML = categoryOptions(radio.value);
        });
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        data.amount = parseFloat(data.amount) || 0;
        try {
          await api(`/events/${this.eventId}/ledger`, { method: 'POST', body: JSON.stringify(data) });
          publish('toast.show', { message: 'Entry added.' });
          await this.reloadAll();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }

    // Void buttons
    $$('[data-void]', this).forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Void this entry?')) return;
        const reason = prompt('Reason (optional):') || '';
        try {
          await api(`/events/${this.eventId}/ledger/${btn.dataset.void}`, {
            method: 'DELETE',
            body: JSON.stringify({ void_reason: reason }),
          });
          publish('toast.show', { message: 'Entry voided.' });
          await this.reloadAll();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    });

    // Checklist checkboxes
    $$('[data-checklist]', this).forEach(cb => {
      cb.addEventListener('change', async () => {
        const field = cb.dataset.checklist;
        try {
          await api(`/events/${this.eventId}/ledger`, {
            method: 'PATCH',
            body: JSON.stringify({ [field]: cb.checked ? 1 : 0 }),
          });
          // Re-evaluate finalize button state without a full reload
          this._updateFinalizeState();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
          cb.checked = !cb.checked; // revert
        }
      });
    });

    // Finalize button
    const btnFinalize = $('#btn-finalize', this);
    if (btnFinalize) {
      btnFinalize.addEventListener('click', async () => {
        if (!confirm('Finalize this closeout? This will lock all entries and checklist items.')) return;
        try {
          await api(`/events/${this.eventId}/ledger/finalize`, { method: 'POST' });
          publish('toast.show', { message: 'Closeout finalized.' });
          await this.reloadAll();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }

    // Reopen button
    const btnReopen = $('#btn-reopen', this);
    if (btnReopen) {
      btnReopen.addEventListener('click', async () => {
        const reason = ($('#reopen-reason', this)?.value || '').trim();
        if (!reason) { alert('Please enter a reason for reopening.'); return; }
        if (!confirm('Reopen this closeout?')) return;
        try {
          await api(`/events/${this.eventId}/ledger/reopen`, {
            method: 'POST',
            body: JSON.stringify({ reason }),
          });
          publish('toast.show', { message: 'Closeout reopened.' });
          await this.reloadAll();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }
  }

  // Re-check all boxes and enable/disable the finalize button without a reload.
  _updateFinalizeState() {
    const allChecked = CHECKLIST_FIELDS.every(([field]) => {
      const cb = $(`[data-checklist="${field}"]`, this);
      return cb ? cb.checked : false;
    });
    const btnFinalize = $('#btn-finalize', this);
    if (btnFinalize) {
      btnFinalize.disabled = !allChecked;
      const hint = $('.finalize-hint', this);
      if (hint) hint.hidden = allChecked;
    }
  }
}

customElements.define('pb-event-closeout', EventCloseout);
