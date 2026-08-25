// ── Event Closeout & Billing panel ───────────────────────────────────────────
// Ledger line items (revenue / costs / payments) and P&L summary with a
// closeout checklist and finalize / reopen workflow.

import { esc, titleCase, api, publish, money, PanicElement, $, $$ } from './core.js';

// ── Category lists by line_type ───────────────────────────────────────────────
// These are NOT hardcoded here: src/Events/Ledger.php is the single source of
// truth (REVENUE_CATEGORIES / COST_CATEGORIES / PAYMENT_CATEGORIES consts) and
// GET /api/events/{id}/ledger echoes them back on every load. A hardcoded copy
// here previously drifted from the backend's PAYMENT_CATEGORIES list (backend
// added invoice_payment/outstanding_balance/artist_payout/etc. and dropped
// balance_payment/refund_issued/credit_applied; this file still offered the
// old names), so picking most "Payment" categories in the UI submitted a
// value the server didn't recognize and got a 422 "Invalid category" back.
// Deriving the select's options from the server response instead of a second
// hand-maintained list makes that class of drift impossible.
function categoriesByType(ledger) {
  return {
    revenue: ledger?.revenue_categories || [],
    cost:    ledger?.cost_categories    || [],
    payment: ledger?.payment_categories || [],
  };
}

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
function categoryOptions(lineType, catsByType, selected = '') {
  const cats = catsByType[lineType] || catsByType.revenue || [];
  return cats.map(c =>
    `<option value="${esc(c)}"${c === selected ? ' selected' : ''}>${esc(titleCase(c))}</option>`
  ).join('');
}

function formatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? esc(value) : esc(d.toLocaleDateString(undefined, { dateStyle: 'medium' }));
}

function statusPill(status) {
  const label = status === 'paid' ? 'Paid' : status === 'partial' ? 'Partial' : 'Unpaid';
  return `<span class="pill ${esc(status)}">${label}</span>`;
}

// Best-effort payment category for a quick "Log Payment" action fired from
// the Balances panel (payee-level, not tied to one specific cost entry).
// These four names are drawn straight from Ledger.php's PAYMENT_CATEGORIES
// — see categoriesByType() above for why this file doesn't hand-maintain
// the *whole* list, but these specific names are stable payout categories
// unlikely to be renamed independent of this mapping.
function defaultPaymentCategoryForPayeeType(payeeType) {
  return {
    artist: 'artist_payout',
    promoter: 'promoter_payout',
    vendor: 'vendor_payout',
    staff: 'staff_payout',
  }[payeeType] || 'adjustment';
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
    // Stashed on the instance so _bind()'s line_type-change listener (which
    // runs after this render but needs the same lists) doesn't need its own
    // copy — see categoriesByType() above for why these come from the server.
    this._categoriesByType = categoriesByType(this._ledger);
    this._payeeTypes = this._ledger?.payee_types || ['artist', 'promoter', 'vendor', 'staff', 'client', 'other'];

    // ── Payee balances (who's still owed money) ───────────────────────────────
    // Server-computed (Ledger::calculateBalances()) so this can't drift from
    // what finalize() itself checks before allowing a closeout to lock.
    const balances = this._ledger?.balances || [];
    const totalStillOwed = Number(this._ledger?.total_still_owed || 0);
    const payoutsDisbursed = totalStillOwed <= 0.005;
    // Cost entries with a payee on them — offered as an optional "link this
    // payment to a specific cost" choice on the generic Add Entry form.
    const linkableCosts = entries.filter(e => e.line_type === 'cost' && !Number(e.is_void) && e.payee_name);

    // ── Partition entries by type ─────────────────────────────────────────────
    const revenue  = entries.filter(e => e.line_type === 'revenue');
    const costs    = entries.filter(e => e.line_type === 'cost');
    const payments = entries.filter(e => e.line_type === 'payment');

    const entryRow = (entry) => {
      const voided = Number(entry.is_void);
      const style  = voided ? ' style="text-decoration:line-through;opacity:0.4"' : '';
      const automatic = entry.source === 'ticketing_sync';
      const voidBtn = (editable && !voided && !automatic)
        ? `<button type="button" class="small danger" data-void="${esc(String(entry.id))}">Void</button>`
        : (automatic ? '<span class="tag">Provider reported</span>' : '');
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
                ${categoryOptions('revenue', this._categoriesByType)}
              </select>
            </label>
            <label>Amount
              <input type="number" name="amount" step="0.01" min="0" placeholder="0.00" required>
            </label>
            <label class="wide">Description
              <input type="text" name="description" placeholder="e.g. Door sales Saturday night">
            </label>
          </div>
          <div class="form-row" id="entry-payee-row" hidden>
            <label>Payee
              <input type="text" name="payee_name" placeholder="Who's this owed to / paid to?">
            </label>
            <label>Payee type
              <select name="payee_type">
                <option value="">&mdash;</option>
                ${this._payeeTypes.map(t => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('')}
              </select>
            </label>
            <label class="wide" id="entry-link-cost-wrap" hidden>Link to a specific cost <span class="field-hint">(optional)</span>
              <select name="paid_entry_id" id="entry-link-cost">
                <option value="">&mdash; payee-level payment, not linked to one cost line &mdash;</option>
                ${linkableCosts.map(c => `<option value="${esc(String(c.id))}" data-payee="${esc(c.payee_name)}" data-payee-type="${esc(c.payee_type || '')}">${esc(c.payee_name)} &mdash; ${esc(titleCase(c.category))} &mdash; ${esc(money(c.amount))}</option>`).join('')}
              </select>
            </label>
          </div>
          <div class="form-actions">
            <button type="submit">Add Entry</button>
            <button type="button" class="secondary small" id="cancel-add-entry">Cancel</button>
          </div>
        </form>
      </div>` : '';

    // ── Balances panel (primary view: who's still owed money) ────────────────
    const balanceRow = (b) => {
      const settled = b.status === 'paid';
      const action = (editable && !settled)
        ? `<button type="button" class="small log-pay-btn" data-payee="${esc(b.payee_name)}" data-payee-type="${esc(b.payee_type || '')}" data-owed="${esc(String(b.still_owed))}">Log Payment</button>`
        : '<span class="muted">&mdash;</span>';
      return `<tr class="bal-row" data-status="${esc(b.status)}">
        <td><span class="payee-name">${esc(b.payee_name)}</span>${b.payee_type ? `<br><span class="payee-type">${esc(titleCase(b.payee_type))}</span>` : ''}</td>
        <td class="amount">${esc(money(b.committed))}</td>
        <td class="amount">${esc(money(b.paid))}</td>
        <td class="amount">${esc(money(b.still_owed))}</td>
        <td>${statusPill(b.status)}</td>
        <td>${action}</td>
      </tr>`;
    };

    const balancesSection = `
      <div class="entry-group balances-group">
        <h3 class="group-head">Balances &mdash; Who's Owed</h3>
        ${balances.length ? `
          <div class="filter-row"><label><input type="checkbox" id="filter-unpaid"> Show unpaid &amp; partial only</label></div>
          <table class="entry-table balances-table">
            <thead><tr><th>Payee</th><th>Committed</th><th>Paid</th><th>Still Owed</th><th>Status</th><th></th></tr></thead>
            <tbody>${balances.map(balanceRow).join('')}</tbody>
          </table>` : `<p class="entry-empty">No payee-tracked costs yet. Add a Cost entry with a Payee below to start tracking who's owed.</p>`}
      </div>`;

    // ── All checklist items checked, AND everyone's been paid? ────────────────
    // Money owed to a payee blocks finalize the same way an unchecked
    // checklist item does — see Ledger::finalize() for the server-side gate
    // this mirrors (never trust the client copy alone).
    const manualChecklistDone = CHECKLIST_FIELDS.every(([field]) => Boolean(closeout[field]));
    const allChecked = manualChecklistDone && payoutsDisbursed;

    // ── Checklist HTML ────────────────────────────────────────────────────────
    const checklistDisabled = !editable ? ' disabled' : '';
    const checklistItems = CHECKLIST_FIELDS.map(([field, label]) => {
      const checked = Boolean(closeout[field]) ? ' checked' : '';
      return `<label class="check-label">
        <input type="checkbox" data-checklist="${esc(field)}"${checked}${checklistDisabled}>
        ${esc(label)}
      </label>`;
    }).join('') + `<label class="check-label derived-check" title="Checked automatically once every payee in Balances shows $0.00 still owed — not a box you check yourself.">
        <input type="checkbox" disabled${payoutsDisbursed ? ' checked' : ''}>
        All Payouts Disbursed <span class="derived-tag">auto</span>
      </label>`;

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
      let hint = '';
      if (!allChecked) {
        const parts = [];
        if (!payoutsDisbursed) {
          const unpaidNames = balances.filter(b => b.status !== 'paid').map(b => esc(b.payee_name));
          parts.push(`${esc(money(totalStillOwed))} still owed to ${unpaidNames.length} ${unpaidNames.length === 1 ? 'payee' : 'payees'} (${unpaidNames.join(', ')})`);
        }
        if (!manualChecklistDone) {
          parts.push('checklist not complete');
        }
        hint = `Can&rsquo;t finalize &mdash; ${parts.join('; ')}.`;
      }
      finalizeBlock = `<button type="button" class="primary" id="btn-finalize"${disabled}>Finalize Closeout</button>
        ${hint ? `<p class="finalize-hint">${hint}</p>` : ''}`;
    }

    this.innerHTML = `
      <section class="panel">
        <div class="section-head padded">
          <h2>Closeout &amp; Billing</h2>
          ${editable ? '<button type="button" class="secondary small" id="btn-add-entry"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Entry</button>' : ''}
        </div>
        <div class="closeout-layout">

          <!-- Left: Balances (primary) + full ledger detail -->
          <article class="panel closeout-panel-left">
            ${addForm}
            ${balancesSection}
            <details class="ledger-detail-toggle">
              <summary>Full ledger detail (revenue / costs / payments, for the audit trail)</summary>
              <div class="ledger-detail-body">
                ${groupTable('Revenue', revenue, 'var(--green, #0f8f46)')}
                ${groupTable('Costs',   costs,   'var(--red,   #ef4338)')}
                ${groupTable('Payments', payments, 'var(--blue,  #1268c7)')}
              </div>
            </details>
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

        /* ── Payee balances ────────────────────────────────────────────────── */
        .balances-group { margin-bottom: 1rem; }
        .filter-row { font-size: 0.82rem; color: var(--muted, #6f7582); margin-bottom: 0.4rem; }
        .filter-row label { display: flex; align-items: center; gap: 0.4rem; font-weight: normal; }
        .filter-row input { width: auto; }
        .balances-table tr.bal-row[hidden] { display: none; }
        .payee-name { font-weight: 600; }
        .payee-type { font-size: 0.76rem; color: var(--muted, #6f7582); }
        .pill { display: inline-flex; align-items: center; gap: 4px; font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
        .pill.paid { background: #d1fae5; color: #065f46; }
        .pill.partial { background: #fdf3df; color: #92660a; }
        .pill.unpaid { background: #fdeceb; color: #a3221c; }
        .muted { color: var(--muted, #6f7582); }
        .field-hint { font-weight: normal; color: var(--muted, #6f7582); text-transform: none; letter-spacing: normal; }
        .pay-inline td { background: var(--soft, #eef0f3); padding: 0.6rem !important; }
        .pay-inline-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.5rem; }
        .pay-inline-form label { display: flex; flex-direction: column; gap: 3px; font-size: 0.78rem; color: var(--muted, #6f7582); }
        .pay-inline-form label.wide { flex: 1 1 200px; }
        .pay-inline-form input { font: inherit; font-size: 0.85rem; padding: 5px 7px; border-radius: 6px; border: 1px solid var(--line, #dfe3e8); }
        .ledger-detail-toggle { margin-top: 0.5rem; }
        .ledger-detail-toggle summary { cursor: pointer; font-size: 0.82rem; font-weight: 700; color: var(--muted, #6f7582); padding: 0.4rem 0; }
        .ledger-detail-body { padding-top: 0.5rem; }
        .derived-check { color: var(--muted, #6f7582); }
        .derived-tag { font-size: 0.66rem; font-weight: 700; letter-spacing: 0.03em; background: var(--soft, #eef0f3); border-radius: 5px; padding: 1px 6px; margin-left: 2px; }
        .owed-row { margin-top: 4px; padding-top: 8px; border-top: 2px solid var(--line, #dfe3e8); }
        .owed-sub-row { padding-top: 0; border-bottom: none; }
        .owed-sub-row .sub-value { font-size: 0.76rem; color: var(--muted, #6f7582); }

        @media (max-width: 860px) {
          .closeout-layout { flex-direction: column; }
          .closeout-panel-left,
          .closeout-panel-right { flex: none; width: 100%; }
        }
      </style>`;

    this._bind();
  }

  _summaryHTML() {
    // Ledger::calculateSummary() (see src/Events/Ledger.php) returns snake_case
    // keys nested under a top-level "summary" key — GET .../ledger/summary
    // responds { summary: { gross_revenue, total_costs, venue_net, ... } }.
    // This previously read camelCase keys straight off the unwrapped response
    // (s.grossRevenue, s.venueNet, ...), which never matched, so every figure
    // in this card silently rendered as $0.00 / 0.0% regardless of real data.
    const s = this._summary?.summary || {};
    const venueNet = Number(s.venue_net || 0);
    const netColor = venueNet >= 0 ? 'var(--green, #0f8f46)' : 'var(--red, #ef4338)';
    const stillOwed = Number(s.total_still_owed || 0);
    const owedColor = stillOwed > 0.005 ? 'var(--red, #ef4338)' : 'var(--green, #0f8f46)';
    const unpaidCt  = Number(s.payees_unpaid || 0);
    const partialCt = Number(s.payees_partial || 0);
    const owedSub = stillOwed > 0.005
      ? `${[unpaidCt ? `${unpaidCt} unpaid` : '', partialCt ? `${partialCt} partial` : ''].filter(Boolean).join(' · ')}`
      : 'All payees settled';
    return `<div class="summary-card">
      <h3 class="panel-subtitle">P&amp;L Summary</h3>
      <div class="summary-row"><span class="label">Gross Revenue</span><span class="value">${esc(money(s.gross_revenue || 0))}</span></div>
      <div class="summary-row"><span class="label">Total Costs</span><span class="value">${esc(money(s.total_costs || 0))}</span></div>
      <div class="summary-row"><span class="label">Venue Net</span><span class="value" style="color:${netColor}">${esc(money(venueNet))}</span></div>
      <div class="summary-row"><span class="label">Margin</span><span class="value">${esc(String(s.margin_pct != null ? Number(s.margin_pct).toFixed(1) : '0.0'))}%</span></div>
      <div class="summary-row"><span class="label">Payments Received</span><span class="value">${esc(money(s.total_payments || 0))}</span></div>
      <div class="summary-row owed-row"><span class="label">Still Owed to Payees</span><span class="value" style="color:${owedColor}">${esc(money(stillOwed))}</span></div>
      <div class="summary-row owed-sub-row"><span class="label">&nbsp;</span><span class="sub-value">${esc(owedSub)}</span></div>
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

    // Update category select + payee fields when line_type changes
    const form = $('#add-entry-form', this);
    if (form) {
      $$('input[name="line_type"]', form).forEach(radio => {
        radio.addEventListener('change', () => {
          const catSel = $('#entry-category', form);
          if (catSel) catSel.innerHTML = categoryOptions(radio.value, this._categoriesByType);
          const payeeRow = $('#entry-payee-row', form);
          const linkWrap = $('#entry-link-cost-wrap', form);
          if (payeeRow) payeeRow.hidden = radio.value === 'revenue';
          if (linkWrap) linkWrap.hidden = radio.value !== 'payment';
        });
      });

      // Picking a specific cost to pay down autofills the payee fields so
      // the payment entry records the same payee as the cost it settles.
      const linkSel = $('#entry-link-cost', form);
      if (linkSel) {
        linkSel.addEventListener('change', () => {
          const opt = linkSel.selectedOptions[0];
          if (opt && opt.value) {
            form.elements.payee_name.value = opt.dataset.payee || '';
            form.elements.payee_type.value = opt.dataset.payeeType || '';
          }
        });
      }

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

    // Balances: filter to unpaid/partial only
    const filterBox = $('#filter-unpaid', this);
    if (filterBox) {
      filterBox.addEventListener('change', () => {
        $$('.bal-row', this).forEach(row => {
          const settled = row.dataset.status === 'paid';
          row.hidden = filterBox.checked && settled;
          const next = row.nextElementSibling;
          if (next && next.classList.contains('pay-inline')) next.remove();
        });
      });
    }

    // Balances: "Log Payment" — a quick payee-level payment (not tied to one
    // specific cost line; use the Add Entry form's "link to a specific cost"
    // option when that precision matters).
    $$('.log-pay-btn', this).forEach(btn => {
      btn.addEventListener('click', () => {
        const row = btn.closest('tr');
        const existing = row.nextElementSibling;
        if (existing && existing.classList.contains('pay-inline')) {
          existing.remove();
          return;
        }
        const payee = btn.dataset.payee;
        const payeeType = btn.dataset.payeeType;
        const owed = Number(btn.dataset.owed || 0);
        const inline = document.createElement('tr');
        inline.className = 'pay-inline';
        inline.innerHTML = `<td colspan="6">
          <form class="pay-inline-form">
            <label>Amount <input type="number" step="0.01" min="0.01" name="amount" value="${owed > 0 ? owed.toFixed(2) : ''}" required></label>
            <label class="wide">Note <input type="text" name="description" placeholder="cash / check / Zelle, etc."></label>
            <button type="submit" class="small primary">Record payment</button>
            <button type="button" class="small secondary" data-cancel-pay>Cancel</button>
          </form>
        </td>`;
        row.after(inline);
        $('[data-cancel-pay]', inline).addEventListener('click', () => inline.remove());
        $('form', inline).addEventListener('submit', async (e) => {
          e.preventDefault();
          const vals = Object.fromEntries(new FormData(e.target).entries());
          try {
            await api(`/events/${this.eventId}/ledger`, {
              method: 'POST',
              body: JSON.stringify({
                line_type: 'payment',
                category: defaultPaymentCategoryForPayeeType(payeeType),
                amount: parseFloat(vals.amount) || 0,
                description: vals.description || `Payment to ${payee}`,
                payee_name: payee,
                payee_type: payeeType,
              }),
            });
            publish('toast.show', { message: `Payment to ${payee} recorded.` });
            await this.reloadAll();
          } catch (err) {
            publish('toast.show', { message: err.message, tone: 'error' });
          }
        });
      });
    });

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

  // Re-check all boxes and enable/disable the finalize button without a full
  // reload. Toggling a checklist item never changes who's owed money, so
  // this reuses the balances totals from the last full load rather than
  // re-fetching — but still folds them in, otherwise ticking the last
  // checklist box would wrongly re-enable Finalize while a payee is unpaid.
  _updateFinalizeState() {
    const manualChecklistDone = CHECKLIST_FIELDS.every(([field]) => {
      const cb = $(`[data-checklist="${field}"]`, this);
      return cb ? cb.checked : false;
    });
    const totalStillOwed = Number(this._ledger?.total_still_owed || 0);
    const payoutsDisbursed = totalStillOwed <= 0.005;
    const allChecked = manualChecklistDone && payoutsDisbursed;

    const btnFinalize = $('#btn-finalize', this);
    if (btnFinalize) {
      btnFinalize.disabled = !allChecked;
      const hint = $('.finalize-hint', this);
      if (hint) {
        if (allChecked) {
          hint.hidden = true;
        } else {
          hint.hidden = false;
          const parts = [];
          if (!payoutsDisbursed) {
            const unpaidNames = (this._ledger?.balances || []).filter(b => b.status !== 'paid').map(b => esc(b.payee_name));
            parts.push(`${esc(money(totalStillOwed))} still owed to ${unpaidNames.length} ${unpaidNames.length === 1 ? 'payee' : 'payees'} (${unpaidNames.join(', ')})`);
          }
          if (!manualChecklistDone) parts.push('checklist not complete');
          hint.innerHTML = `Can&rsquo;t finalize &mdash; ${parts.join('; ')}.`;
        }
      }
    }
  }
}

customElements.define('pb-event-closeout', EventCloseout);
