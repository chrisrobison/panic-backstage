// ── Event Closeout & Billing panel ───────────────────────────────────────────
// Ledger line items (revenue / costs / payments) and P&L summary with a
// closeout checklist and finalize / reopen workflow.

import { esc, titleCase, api, publish, money, openModal, PanicElement, $, $$ } from './core.js';

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

// Small visual icon per payee_type on the Balances list — purely decorative,
// falls back to a generic tag icon for anything unrecognized (custom types
// shouldn't happen since the select is server-driven, but this file doesn't
// hard-fail on an unexpected value elsewhere either).
const PAYEE_TYPE_ICONS = {
  artist: 'fa-microphone-lines',
  promoter: 'fa-bullhorn',
  vendor: 'fa-truck',
  staff: 'fa-user-tie',
  client: 'fa-building',
  other: 'fa-tag',
};

const LINE_TYPE_ICONS = {
  revenue: 'fa-sack-dollar',
  cost: 'fa-receipt',
  payment: 'fa-money-bill-transfer',
};

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
  const meta = {
    paid:    { label: 'Paid',    icon: 'fa-circle-check' },
    partial: { label: 'Partial', icon: 'fa-circle-half-stroke' },
    unpaid:  { label: 'Unpaid',  icon: 'fa-circle-exclamation' },
  }[status] || { label: 'Unpaid', icon: 'fa-circle-exclamation' };
  return `<span class="pill ${esc(status)}"><i class="fa-solid ${meta.icon}" aria-hidden="true"></i>${meta.label}</span>`;
}

// One KPI tile for the top-of-panel hero row. `value` is inserted as-is
// (callers pre-escape/format via money()/esc()), `tone` picks a scoped color
// modifier (see .kpi-icon.tone-* / .kpi-value.tone-* below).
function kpiTile(icon, label, value, tone = '') {
  return `<article class="kpi-card">
    <span class="kpi-icon ${tone}"><i class="fa-solid ${icon}" aria-hidden="true"></i></span>
    <div><span class="kpi-label">${esc(label)}</span><strong class="kpi-value ${tone}">${value}</strong></div>
  </article>`;
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
  // Properties set by the workspace BEFORE eventId (see the comment on the
  // eventId setter for why order matters):
  //   canEdit, canFinalize, canEditSettlement, showDoorSalesFallback
  // showDoorSalesFallback mirrors the old standalone Settlement tab's own
  // gate (view_settlement && !isPrivate) — see event-workspace.js. That tab
  // is gone; its two functionally-load-bearing fields (tickets_sold /
  // gross_ticket_sales — the only ones Report.php's ticket-count fallback
  // actually reads) live on here as a collapsed fallback section, alongside
  // the settlement-doc-URL link the old tab also owned.
  //
  // Properties set by the workspace after DOM insertion:
  //   eventId (triggers load()), settlementDocUrl

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

  // Settlement fetch is conditional on showDoorSalesFallback, which the
  // workspace sets BEFORE eventId specifically so it's already correct by
  // the time this (synchronous, up to the first await) function body runs.
  _fetchAll() {
    const calls = [
      api(`/events/${this.eventId}/ledger`),
      api(`/events/${this.eventId}/ledger/summary`),
    ];
    if (this.showDoorSalesFallback) calls.push(api(`/events/${this.eventId}/settlement`));
    return Promise.all(calls);
  }

  async load() {
    this.setLoading('Loading closeout data');
    try {
      const [ledger, summary, doorSales] = await this._fetchAll();
      this._ledger    = ledger;
      this._summary   = summary;
      this._doorSales = doorSales?.settlement || {};
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  async reloadAll() {
    try {
      const [ledger, summary, doorSales] = await this._fetchAll();
      this._ledger    = ledger;
      this._summary   = summary;
      this._doorSales = doorSales?.settlement || {};
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
    // Stashed on the instance so _openAddEntryModal() / the line_type-change
    // listener (which run later, on demand) don't need their own copy — see
    // categoriesByType() above for why these come from the server.
    this._categoriesByType = categoriesByType(this._ledger);
    this._payeeTypes = this._ledger?.payee_types || ['artist', 'promoter', 'vendor', 'staff', 'client', 'other'];

    // ── Payee balances (who's still owed money) ───────────────────────────────
    // Server-computed (Ledger::calculateBalances()) so this can't drift from
    // what finalize() itself checks before allowing a closeout to lock.
    const balances = this._ledger?.balances || [];
    const totalStillOwed = Number(this._ledger?.total_still_owed || 0);
    const payoutsDisbursed = totalStillOwed <= 0.005;

    // ── Partition entries by type ─────────────────────────────────────────────
    const revenue  = entries.filter(e => e.line_type === 'revenue');
    const costs    = entries.filter(e => e.line_type === 'cost');
    const payments = entries.filter(e => e.line_type === 'payment');

    // ── Hero KPI row — the four numbers everyone actually opens this tab for ──
    const s = this._summary?.summary || {};
    const venueNet = Number(s.venue_net || 0);
    const heroRow = `
      <div class="closeout-kpis">
        ${kpiTile('fa-sack-dollar', 'Gross Revenue', esc(money(s.gross_revenue || 0)), 'tone-blue')}
        ${kpiTile('fa-receipt', 'Total Costs', esc(money(s.total_costs || 0)), 'tone-red')}
        ${kpiTile('fa-scale-balanced', 'Venue Net', esc(money(venueNet)), venueNet >= 0 ? 'tone-green' : 'tone-red')}
        ${payoutsDisbursed
          ? kpiTile('fa-circle-check', 'Payee Balances', 'All settled', 'tone-green')
          : kpiTile('fa-hand-holding-dollar', 'Still Owed', esc(money(totalStillOwed)), 'tone-amber')}
      </div>`;

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

    const groupTable = (label, arr, accent, icon) => {
      if (!arr.length && !editable) return '';
      const rows = arr.length
        ? arr.map(entryRow).join('') + subtotalRow(`${label} subtotal`, groupSum(arr))
        : `<tr><td colspan="4" class="entry-empty">No ${label.toLowerCase()} entries yet.</td></tr>`;
      return `<div class="entry-group" style="--group-accent:${accent}">
        <h3 class="group-head" style="border-left:3px solid ${accent};padding-left:0.5rem">
          <i class="fa-solid ${icon}" aria-hidden="true" style="color:${accent};margin-right:6px"></i>${esc(label)}
        </h3>
        <div class="table-scroll">
          <table class="entry-table">
            <thead><tr><th>Category</th><th>Description</th><th>Amount</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;
    };

    // ── Balances panel (primary view: who's still owed money) ────────────────
    const balanceRow = (b) => {
      const settled = b.status === 'paid';
      const icon = PAYEE_TYPE_ICONS[b.payee_type] || 'fa-tag';
      const action = (editable && !settled)
        ? `<button type="button" class="small primary log-pay-btn" data-payee="${esc(b.payee_name)}" data-payee-type="${esc(b.payee_type || '')}" data-owed="${esc(String(b.still_owed))}"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Log Payment</button>`
        : '<span class="muted">&mdash;</span>';
      return `<tr class="bal-row" data-status="${esc(b.status)}">
        <td>
          <span class="payee-icon-wrap"><i class="fa-solid ${icon}" aria-hidden="true"></i></span>
          <span class="payee-name">${esc(b.payee_name)}</span>${b.payee_type ? `<br><span class="payee-type">${esc(titleCase(b.payee_type))}</span>` : ''}
        </td>
        <td class="amount">${esc(money(b.committed))}</td>
        <td class="amount">${esc(money(b.paid))}</td>
        <td class="amount">${esc(money(b.still_owed))}</td>
        <td>${statusPill(b.status)}</td>
        <td>${action}</td>
      </tr>`;
    };

    const payeeCount = balances.length;
    const paidCount  = balances.filter(b => b.status === 'paid').length;
    const paidPct    = payeeCount ? Math.round((paidCount / payeeCount) * 100) : 100;
    const balancesStatus = !payeeCount ? '' : payoutsDisbursed
      ? `<div class="balances-status balances-status-done"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> All ${payeeCount} ${payeeCount === 1 ? 'payee' : 'payees'} paid.</div>`
      : `<div class="balances-status">
           <div class="balances-status-text"><strong>${paidCount} of ${payeeCount}</strong> ${payeeCount === 1 ? 'payee' : 'payees'} paid &middot; <span class="owed-amt">${esc(money(totalStillOwed))} remaining</span></div>
           <div class="progress-track"><div class="progress-fill tone-green" style="width:${paidPct}%"></div></div>
         </div>`;

    const balancesSection = `
      <div class="panel balances-panel entry-group balances-group">
        <h3 class="group-head"><i class="fa-solid fa-people-arrows" aria-hidden="true" style="margin-right:6px"></i>Balances &mdash; Who's Owed</h3>
        ${balancesStatus}
        ${balances.length ? `
          <div class="filter-row"><label><input type="checkbox" id="filter-unpaid"> Show unpaid &amp; partial only</label></div>
          <div class="table-scroll">
            <table class="entry-table balances-table">
              <thead><tr><th>Payee</th><th>Committed</th><th>Paid</th><th>Still Owed</th><th>Status</th><th></th></tr></thead>
              <tbody>${balances.map(balanceRow).join('')}</tbody>
            </table>
          </div>` : `<p class="entry-empty">No payee-tracked costs yet. Add a Cost entry with a Payee to start tracking who's owed.</p>`}
      </div>`;

    // ── All checklist items checked, AND everyone's been paid? ────────────────
    // Money owed to a payee blocks finalize the same way an unchecked
    // checklist item does — see Ledger::finalize() for the server-side gate
    // this mirrors (never trust the client copy alone).
    const manualChecklistDone = CHECKLIST_FIELDS.every(([field]) => Boolean(closeout[field]));
    const allChecked = manualChecklistDone && payoutsDisbursed;

    // ── Checklist HTML ────────────────────────────────────────────────────────
    const checklistDisabled = !editable ? ' disabled' : '';
    const checklistDoneCount = CHECKLIST_FIELDS.filter(([field]) => Boolean(closeout[field])).length + (payoutsDisbursed ? 1 : 0);
    const checklistTotal = CHECKLIST_FIELDS.length + 1; // +1 for the derived payouts-disbursed row
    const checklistPct = Math.round((checklistDoneCount / checklistTotal) * 100);
    const checklistItems = CHECKLIST_FIELDS.map(([field, label]) => {
      const checked = Boolean(closeout[field]) ? ' checked' : '';
      return `<label class="check-label${checked ? ' is-checked' : ''}">
        <input type="checkbox" data-checklist="${esc(field)}"${checked}${checklistDisabled}>
        ${esc(label)}
      </label>`;
    }).join('') + `<label class="check-label derived-check${payoutsDisbursed ? ' is-checked' : ''}" title="Checked automatically once every payee in Balances shows $0.00 still owed — not a box you check yourself.">
        <input type="checkbox" disabled${payoutsDisbursed ? ' checked' : ''}>
        All Payouts Disbursed <span class="derived-tag">auto</span>
      </label>`;

    // ── Finalize / reopen controls ────────────────────────────────────────────
    let finalizeBlock = '';
    if (finalized) {
      finalizeBlock = `<div class="panel-success"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Finalized on ${formatDate(closeout.finalized_at)}</div>`;
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
      finalizeBlock = `
        <div class="finalize-card ${allChecked ? 'finalize-ready' : 'finalize-blocked'}">
          <button type="button" class="primary" id="btn-finalize"${disabled}><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i> Finalize Closeout</button>
          ${hint ? `<p class="finalize-hint"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ${hint}</p>` : '<p class="finalize-hint finalize-ok">Everything checks out — ready to finalize.</p>'}
        </div>`;
    }

    // ── Door sales fallback + settlement doc link (folded in from the old
    // standalone Settlement tab) ──────────────────────────────────────────────
    const doorSales = this._doorSales || {};
    const editableSettlement = editable && Boolean(this.canEditSettlement);
    const docUrl = this.settlementDocUrl || '';
    const docLink = docUrl && /^https?:/i.test(docUrl)
      ? `<a class="button small secondary" href="${esc(docUrl)}" target="_blank" rel="noopener noreferrer">Open settlement doc &nearr;</a>`
      : '';
    const doorSalesSection = this.showDoorSalesFallback ? `
      <details class="ledger-detail-toggle door-sales-toggle">
        <summary><i class="fa-solid fa-door-open" aria-hidden="true"></i> Door sales &amp; settlement doc <span class="field-hint">(fallback for outside ticketing)</span></summary>
        <div class="ledger-detail-body">
          <p class="fallback-note">Only needed when tickets sold outside this app — at the door or through an outside ticketing service. Leave blank when in-house ticketing already covers the count; it won't be double-counted against the Revenue above.</p>
          <form class="row-form add-entry-form" id="door-sales-form">
            <div class="form-row">
              <label>Tickets sold
                <input type="number" name="tickets_sold" min="0" step="1" value="${esc(String(doorSales.tickets_sold ?? 0))}"${editableSettlement ? '' : ' disabled'}>
              </label>
              <label>Gross ticket sales
                <input type="number" name="gross_ticket_sales" min="0" step="0.01" value="${esc(String(doorSales.gross_ticket_sales ?? 0))}"${editableSettlement ? '' : ' disabled'}>
              </label>
              ${editableSettlement ? '<button type="submit" class="small">Save door sales</button>' : ''}
            </div>
          </form>
          <form class="row-form add-entry-form" id="settlement-doc-form">
            <div class="form-row">
              <label class="wide">Settlement document
                <input type="text" name="settlement_doc_url" value="${esc(docUrl)}" placeholder="URL or note pointing to the night-of settlement sheet"${editableSettlement ? '' : ' disabled'}>
              </label>
              ${docLink}
              ${editableSettlement ? '<button type="submit" class="small">Save link</button>' : ''}
            </div>
          </form>
        </div>
      </details>` : '';

    this.innerHTML = `
      <section class="panel">
        <div class="section-head padded">
          <h2>Closeout &amp; Billing</h2>
          ${editable ? '<button type="button" class="secondary small" id="btn-add-entry"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Entry</button>' : ''}
        </div>

        <div class="closeout-kpis-wrap padded">${heroRow}</div>

        <div class="closeout-body padded">
          ${balancesSection}

          <div class="closeout-columns">

            <!-- Left: full ledger detail + door sales fallback -->
            <article class="panel closeout-panel-left">
              <details class="ledger-detail-toggle">
                <summary><i class="fa-solid fa-list-ul" aria-hidden="true"></i> Full ledger detail (revenue / costs / payments, for the audit trail)</summary>
                <div class="ledger-detail-body">
                  ${groupTable('Revenue', revenue, 'var(--green, #0f8f46)', 'fa-sack-dollar')}
                  ${groupTable('Costs',   costs,   'var(--red,   #ef4338)', 'fa-receipt')}
                  ${groupTable('Payments', payments, 'var(--blue,  #1268c7)', 'fa-money-bill-transfer')}
                </div>
              </details>
              ${doorSalesSection}
            </article>

            <!-- Right: P&L Summary + Closeout Checklist + Finalize -->
            <article class="panel closeout-panel-right">
              <div id="summary-card">
                ${this._summaryHTML()}
              </div>

              <div class="closeout-checklist">
                <div class="checklist-head">
                  <h3 class="panel-subtitle">Closeout Checklist</h3>
                  <span class="checklist-count">${checklistDoneCount}/${checklistTotal}</span>
                </div>
                <div class="progress-track checklist-progress"><div class="progress-fill tone-blue" style="width:${checklistPct}%"></div></div>
                <div class="checklist-items">${checklistItems}</div>
              </div>

              <div class="finalize-block">
                ${finalizeBlock}
              </div>
            </article>

          </div>
        </div>
      </section>

      <style>
        .closeout-kpis-wrap { padding-top: 0; }
        .closeout-kpis {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
          gap: 14px;
        }
        .closeout-kpis .kpi-card { align-items: flex-start; }
        .kpi-icon.tone-green { background: color-mix(in srgb, var(--green, #0f8f46) 14%, transparent); color: var(--green, #0f8f46); }
        .kpi-icon.tone-red   { background: color-mix(in srgb, var(--red,   #ef4338) 14%, transparent); color: var(--red,   #ef4338); }
        .kpi-icon.tone-blue  { background: color-mix(in srgb, var(--blue,  #1268c7) 14%, transparent); color: var(--blue,  #1268c7); }
        .kpi-icon.tone-amber { background: rgba(217,119,6,.16); color: #b45309; }
        .kpi-value.tone-green { color: var(--green, #0f8f46); }
        .kpi-value.tone-red   { color: var(--red,   #ef4338); }
        .kpi-value.tone-blue  { color: var(--blue,  #1268c7); }
        .kpi-value.tone-amber { color: #b45309; }

        .closeout-body { padding-top: 0; display: flex; flex-direction: column; gap: 1.25rem; }
        .closeout-columns {
          display: flex;
          gap: 1.5rem;
          align-items: flex-start;
        }
        .closeout-panel-left  { flex: 3 2 0; min-width: 0; }
        .closeout-panel-right { flex: 2 1 0; min-width: 260px; }
        .closeout-panel-left,
        .closeout-panel-right,
        .balances-panel {
          padding: 1rem;
          border: 1px solid var(--line, #dfe3e8);
          border-radius: 10px;
          background: var(--panel, #fff);
        }
        .panel-subtitle {
          margin: 0;
          font-size: 0.95rem;
          font-weight: 700;
          color: var(--muted, #6f7582);
          text-transform: uppercase;
          letter-spacing: 0.04em;
        }
        .entry-group { margin-bottom: 1.5rem; }
        .entry-group:last-child { margin-bottom: 0; }
        .group-head { margin: 0 0 0.5rem; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .entry-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .balances-table { min-width: 560px; }
        .entry-table th {
          text-align: left;
          padding: 4px 6px;
          font-size: 0.78rem;
          color: var(--muted, #6f7582);
          border-bottom: 1px solid var(--line, #dfe3e8);
        }
        .entry-table td { padding: 7px 6px; border-bottom: 1px solid var(--line, #dfe3e8); vertical-align: middle; }
        .entry-table tbody tr:nth-child(even):not(.subtotal-row) { background: color-mix(in srgb, var(--soft, #eef0f3) 45%, transparent); }
        .entry-table td.amount { text-align: right; font-variant-numeric: tabular-nums; }
        .entry-table tr.subtotal-row td { background: var(--soft, #eef0f3); }
        .entry-empty { color: var(--muted, #6f7582); font-style: italic; text-align: center; padding: 1rem 0 !important; }

        /* ── Add-entry modal (openModal() from core.js) ────────────────────── */
        .linetype-toggle { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .linetype-option {
          display: flex; align-items: center; justify-content: center; gap: 6px;
          padding: 10px 8px; border: 1.5px solid var(--line, #dfe3e8); border-radius: 10px;
          cursor: pointer; font-size: 0.85rem; font-weight: 600; text-align: center;
          transition: border-color .15s, background .15s, color .15s;
        }
        .linetype-option:hover { border-color: var(--blue, #1268c7); }
        .linetype-option input { position: absolute; opacity: 0; width: 0; height: 0; }
        .linetype-option:has(input:checked) {
          border-color: var(--blue, #1268c7);
          background: color-mix(in srgb, var(--blue, #1268c7) 8%, transparent);
          color: var(--blue, #1268c7);
        }
        .modal-actions { display: flex; gap: 0.5rem; margin-top: 4px; }

        .summary-card { font-size: 0.9rem; margin-bottom: 1.25rem; }
        .summary-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--line, #dfe3e8); }
        .summary-row:last-child { border-bottom: none; }
        .summary-row .label { color: var(--muted, #6f7582); }
        .summary-row .value { font-weight: 700; font-variant-numeric: tabular-nums; }
        .summary-actions { margin-top: 0.5rem; }

        .checklist-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem; }
        .checklist-count { font-size: 0.78rem; font-weight: 800; color: var(--muted, #6f7582); background: var(--soft, #eef0f3); border-radius: 999px; padding: 2px 9px; }
        .progress-track { height: 7px; background: var(--soft, #eef0f3); border-radius: 999px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 999px; transition: width .3s ease; }
        .progress-fill.tone-green { background: var(--green, #0f8f46); }
        .progress-fill.tone-blue  { background: var(--blue, #1268c7); }
        .checklist-progress { margin-bottom: 0.75rem; }

        .checklist-items { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1rem; }
        .check-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; padding: 6px 8px; border-radius: 7px; transition: background .15s; }
        .check-label.is-checked { background: color-mix(in srgb, var(--green, #0f8f46) 8%, transparent); }
        .check-label input[type="checkbox"] { width: auto; accent-color: var(--blue, #1268c7); }

        .finalize-card { padding: 0.85rem; border-radius: 10px; border: 1px solid var(--line, #dfe3e8); }
        .finalize-card.finalize-ready  { background: color-mix(in srgb, var(--green, #0f8f46) 7%, transparent); border-color: color-mix(in srgb, var(--green, #0f8f46) 35%, var(--line, #dfe3e8)); }
        .finalize-card.finalize-blocked { background: color-mix(in srgb, #d97706 7%, transparent); border-color: color-mix(in srgb, #d97706 35%, var(--line, #dfe3e8)); }
        button.primary { background: var(--blue, #1268c7); color: #fff; border-color: var(--blue, #1268c7); }
        button.primary:disabled { opacity: 0.45; cursor: not-allowed; }
        .finalize-hint { font-size: 0.8rem; color: var(--muted, #6f7582); margin: 0.5rem 0 0; display: flex; align-items: flex-start; gap: 6px; }
        .finalize-hint.finalize-ok { color: var(--green, #0f8f46); }
        .panel-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 8px; padding: 0.6rem 0.9rem; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 8px; }
        .reopen-block { margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .reopen-block label { font-size: 0.85rem; }

        /* ── Payee balances ────────────────────────────────────────────────── */
        .balances-status { margin-bottom: 10px; padding: 10px 12px; background: var(--soft, #eef0f3); border-radius: 8px; font-size: 0.85rem; }
        .balances-status-done { background: #d1fae5; color: #065f46; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .balances-status-text { margin-bottom: 5px; }
        .owed-amt { font-weight: 700; color: var(--red, #ef4338); }
        .filter-row { font-size: 0.82rem; color: var(--muted, #6f7582); margin-bottom: 0.4rem; }
        .filter-row label { display: flex; align-items: center; gap: 0.4rem; font-weight: normal; }
        .filter-row input { width: auto; }
        .balances-table tr.bal-row[hidden] { display: none; }
        .payee-icon-wrap {
          display: inline-flex; align-items: center; justify-content: center;
          width: 24px; height: 24px; border-radius: 7px; margin-right: 7px;
          background: var(--soft, #eef0f3); color: var(--muted, #6f7582); font-size: 0.72rem;
          vertical-align: middle;
        }
        .payee-name { font-weight: 600; }
        .payee-type { font-size: 0.76rem; color: var(--muted, #6f7582); margin-left: 31px; }
        .pill { display: inline-flex; align-items: center; gap: 5px; font-size: 0.74rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .pill.paid { background: #d1fae5; color: #065f46; }
        .pill.partial { background: #fdf3df; color: #92660a; }
        .pill.unpaid { background: #fdeceb; color: #a3221c; }
        .muted { color: var(--muted, #6f7582); }
        .field-hint { font-weight: normal; color: var(--muted, #6f7582); text-transform: none; letter-spacing: normal; }
        .pay-inline td { background: var(--soft, #eef0f3); padding: 0.7rem !important; }
        .pay-inline-head { font-size: 0.82rem; font-weight: 600; margin-bottom: 6px; }
        .pay-inline-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.5rem; }
        .pay-inline-form label { display: flex; flex-direction: column; gap: 3px; font-size: 0.78rem; color: var(--muted, #6f7582); }
        .pay-inline-form label.wide { flex: 1 1 200px; }
        .pay-inline-form input { font: inherit; font-size: 0.85rem; padding: 5px 7px; border-radius: 6px; border: 1px solid var(--line, #dfe3e8); }
        .ledger-detail-toggle { margin-top: 0.5rem; }
        .ledger-detail-toggle summary { cursor: pointer; font-size: 0.82rem; font-weight: 700; color: var(--muted, #6f7582); padding: 0.4rem 0; }
        .ledger-detail-toggle summary i { margin-right: 6px; }
        .ledger-detail-body { padding-top: 0.5rem; }
        .door-sales-toggle { margin-top: 0.75rem; }
        .fallback-note { font-size: 0.8rem; color: var(--muted, #6f7582); line-height: 1.45; margin: 0 0 0.6rem; }
        .door-sales-toggle .add-entry-form { margin-bottom: 0.6rem; }
        .door-sales-toggle .add-entry-form:last-child { margin-bottom: 0; }
        .door-sales-toggle input[disabled] { opacity: 0.6; }
        .derived-tag { font-size: 0.66rem; font-weight: 700; letter-spacing: 0.03em; background: var(--soft, #eef0f3); border-radius: 5px; padding: 1px 6px; margin-left: 2px; }
        .owed-row { margin-top: 4px; padding-top: 8px; border-top: 2px solid var(--line, #dfe3e8); }
        .owed-sub-row { padding-top: 0; border-bottom: none; }
        .owed-sub-row .sub-value { font-size: 0.76rem; color: var(--muted, #6f7582); }

        @media (max-width: 860px) {
          .closeout-columns { flex-direction: column; }
          .closeout-panel-left,
          .closeout-panel-right { flex: none; width: 100%; }
        }
        @media (max-width: 560px) {
          .linetype-toggle { grid-template-columns: 1fr; }
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
      <h3 class="panel-subtitle" style="margin-bottom:0.75rem">P&amp;L Summary</h3>
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

  // ── Add Entry modal ───────────────────────────────────────────────────────
  // Moved from an inline reveal-below-the-table form to a modal — the default
  // pattern for table add/edit forms in this app (see project memory:
  // ui-conventions.md). Field names/ids match what the form always submitted
  // so the POST /events/{id}/ledger call itself is unchanged.
  _openAddEntryModal() {
    const catsByType = this._categoriesByType;
    const payeeTypes = this._payeeTypes;
    // Cost entries with a payee on them — offered as an optional "link this
    // payment to a specific cost" choice.
    const linkableCosts = (this._ledger?.entries || []).filter(e => e.line_type === 'cost' && !Number(e.is_void) && e.payee_name);

    const typeOption = (value, label) => `<label class="linetype-option">
        <input type="radio" name="line_type" value="${esc(value)}"${value === 'revenue' ? ' checked' : ''}>
        <i class="fa-solid ${LINE_TYPE_ICONS[value]}" aria-hidden="true"></i> ${esc(label)}
      </label>`;

    const { dialog, close } = openModal({
      title: 'Add Ledger Entry',
      bodyHtml: `
        <form class="grid-form padded add-entry-form" data-form="add-entry">
          <div class="linetype-toggle wide" role="radiogroup" aria-label="Entry type">
            ${typeOption('revenue', 'Revenue')}
            ${typeOption('cost', 'Cost')}
            ${typeOption('payment', 'Payment')}
          </div>
          <label>Category
            <select name="category" id="entry-category">${categoryOptions('revenue', catsByType)}</select>
          </label>
          <label>Amount
            <input type="number" name="amount" step="0.01" min="0" placeholder="0.00" required>
          </label>
          <label class="wide">Description
            <input type="text" name="description" placeholder="e.g. Door sales Saturday night">
          </label>
          <label data-payee-field hidden>Payee
            <input type="text" name="payee_name" placeholder="Who's this owed to / paid to?">
          </label>
          <label data-payee-field hidden>Payee type
            <select name="payee_type">
              <option value="">&mdash;</option>
              ${payeeTypes.map(t => `<option value="${esc(t)}">${esc(titleCase(t))}</option>`).join('')}
            </select>
          </label>
          <label class="wide" id="entry-link-cost-wrap" hidden>Link to a specific cost <span class="field-hint">(optional)</span>
            <select name="paid_entry_id" id="entry-link-cost">
              <option value="">&mdash; payee-level payment, not linked to one cost line &mdash;</option>
              ${linkableCosts.map(c => `<option value="${esc(String(c.id))}" data-payee="${esc(c.payee_name)}" data-payee-type="${esc(c.payee_type || '')}">${esc(c.payee_name)} &mdash; ${esc(titleCase(c.category))} &mdash; ${esc(money(c.amount))}</option>`).join('')}
            </select>
          </label>
          <div class="modal-actions wide">
            <button type="submit" class="primary">Add Entry</button>
            <button type="button" class="secondary" data-close>Cancel</button>
          </div>
        </form>`,
      focus: '[name="amount"]',
    });

    const form = $('[data-form="add-entry"]', dialog);

    $$('input[name="line_type"]', form).forEach(radio => {
      radio.addEventListener('change', () => {
        const catSel = $('#entry-category', form);
        if (catSel) catSel.innerHTML = categoryOptions(radio.value, catsByType);
        $$('[data-payee-field]', form).forEach(el => { el.hidden = radio.value === 'revenue'; });
        const linkWrap = $('#entry-link-cost-wrap', form);
        if (linkWrap) linkWrap.hidden = radio.value !== 'payment';
      });
    });

    // Picking a specific cost to pay down autofills the payee fields so the
    // payment entry records the same payee as the cost it settles.
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
      const submitBtn = $('button[type="submit"]', form);
      submitBtn.disabled = true;
      try {
        await api(`/events/${this.eventId}/ledger`, { method: 'POST', body: JSON.stringify(data) });
        publish('toast.show', { message: 'Entry added.' });
        close();
        await this.reloadAll();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
        submitBtn.disabled = false;
      }
    });
  }

  _bind() {
    // Refresh summary button
    this._bindSummary();

    // Add-entry button opens the modal (see _openAddEntryModal()).
    const btnAdd = $('#btn-add-entry', this);
    if (btnAdd) {
      btnAdd.addEventListener('click', () => this._openAddEntryModal());
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
    // specific cost line; use the Add Entry modal's "link to a specific cost"
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
          <div class="pay-inline-head"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Recording payment to <strong>${esc(payee)}</strong></div>
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

    // Door sales fallback (tickets_sold/gross_ticket_sales — see the
    // property docblock at the top of this class for why these two fields
    // specifically survive from the old Settlement tab).
    const doorSalesForm = $('#door-sales-form', this);
    if (doorSalesForm) {
      doorSalesForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const vals = Object.fromEntries(new FormData(doorSalesForm).entries());
        try {
          await api(`/events/${this.eventId}/settlement`, {
            method: 'POST',
            body: JSON.stringify({
              tickets_sold: parseInt(vals.tickets_sold, 10) || 0,
              gross_ticket_sales: parseFloat(vals.gross_ticket_sales) || 0,
            }),
          });
          publish('toast.show', { message: 'Door sales saved.' });
          await this.reloadAll();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }

    // Settlement document link — a plain PATCH on the event itself
    // (settlement_doc_url), same as the old Settlement tab's "doc" form.
    const settlementDocForm = $('#settlement-doc-form', this);
    if (settlementDocForm) {
      settlementDocForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = new FormData(settlementDocForm).get('settlement_doc_url') || '';
        try {
          await api(`/events/${this.eventId}`, { method: 'PATCH', body: JSON.stringify({ settlement_doc_url: url }) });
          this.settlementDocUrl = url;
          publish('toast.show', { message: 'Settlement doc link saved.' });
          this.render();
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

    $$('.check-label', this).forEach(label => {
      const cb = $('input[type="checkbox"]', label);
      label.classList.toggle('is-checked', Boolean(cb?.checked));
    });

    const btnFinalize = $('#btn-finalize', this);
    if (btnFinalize) {
      btnFinalize.disabled = !allChecked;
      const card = $('.finalize-card', this);
      if (card) {
        card.classList.toggle('finalize-ready', allChecked);
        card.classList.toggle('finalize-blocked', !allChecked);
      }
      const hint = $('.finalize-hint', this);
      if (hint) {
        if (allChecked) {
          hint.classList.add('finalize-ok');
          hint.innerHTML = 'Everything checks out — ready to finalize.';
        } else {
          hint.classList.remove('finalize-ok');
          const parts = [];
          if (!payoutsDisbursed) {
            const unpaidNames = (this._ledger?.balances || []).filter(b => b.status !== 'paid').map(b => esc(b.payee_name));
            parts.push(`${esc(money(totalStillOwed))} still owed to ${unpaidNames.length} ${unpaidNames.length === 1 ? 'payee' : 'payees'} (${unpaidNames.join(', ')})`);
          }
          if (!manualChecklistDone) parts.push('checklist not complete');
          hint.innerHTML = `<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Can&rsquo;t finalize &mdash; ${parts.join('; ')}.`;
        }
      }
    }
  }
}

customElements.define('pb-event-closeout', EventCloseout);
