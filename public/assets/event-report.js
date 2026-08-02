// ── Per-event P&L / Settlement Report ────────────────────────────────────────
// A read-only, printable statement for one event: the server-computed P&L
// (same numbers as the Closeout tab's ledger summary — never recomputed
// differently here), plus the cost detail that explains it: vendor bills,
// staffing labor cost, lineup payout terms, and a ticket-type breakdown.
// Source: GET /api/events/{id}/report (src/Events/Report.php).

import { esc, titleCase, api, money, shortDate, eventDate, emptyState, PanicElement, $, appUrl, publish } from './core.js';
import { openPrintWindow, buildSettlementCsv, downloadTextFile } from './print.js';

class EventReport extends PanicElement {
  get eventId()  { return this._eventId; }
  set eventId(v) {
    this._eventId = v;
    if (v) this.load();
  }

  async connect() {
    if (this._eventId) await this.load();
  }

  async load() {
    this.setLoading('Loading report');
    try {
      this._data = await api(`/events/${this.eventId}/report`);
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const d = this._data || {};
    const event = d.event || {};
    const s = d.summary || {};
    const netColor = Number(s.venue_net || 0) >= 0 ? 'var(--green, #0f8f46)' : 'var(--red, #ef4338)';

    // Line-item breakdown, grouped exactly like the Closeout tab (one row per
    // ledger entry, not a collapsed per-category sum) — see src/Events/Report.php.
    const entries = d.ledger_entries || [];
    const entryRows = (arr) => arr.map((e) => `<tr>
        <td>${esc(titleCase(e.category))}</td>
        <td>${esc(e.description || '')}</td>
        <td class="amount">${esc(money(e.amount))}</td>
      </tr>`).join('');
    const groupSum = (arr) => arr.reduce((sum, e) => sum + Number(e.amount || 0), 0);
    const groupTable = (label, arr) => {
      if (!arr.length) return `<tr><td colspan="3">${emptyState(`No ${label.toLowerCase()} entries yet.`)}</td></tr>`;
      return entryRows(arr) + `<tr class="er-subtotal-row"><td colspan="2"><strong>${esc(label)} subtotal</strong></td><td class="amount"><strong>${esc(money(groupSum(arr)))}</strong></td></tr>`;
    };
    const revenueEntries = entries.filter((e) => e.line_type === 'revenue');
    const costEntries    = entries.filter((e) => e.line_type === 'cost');
    const paymentEntries = entries.filter((e) => e.line_type === 'payment');

    // Effective ticket count: falls back to the hand-entered Settlement tab
    // figure when in-house ticketing shows 0 sold (door / outside-service sales
    // never create ticket_order_items rows) — see Report.php for the logic.
    const ticketsSold = d.tickets_sold_effective ?? s.tickets_sold ?? 0;
    const grossTicketSales = d.gross_ticket_sales_effective ?? s.gross_ticket_sales ?? 0;
    const ticketSourceNote = d.ticket_sales_source === 'door_or_manual'
      ? 'Reported at settlement (door / outside ticketing)'
      : d.ticket_sales_source === 'box_office' ? 'In-house ticketing' : '';

    // Ticket types that exist (created for advance sales, comps, etc.) but
    // sold zero seats in-house usually mean the show sold through an outside
    // ticketing service or at the door instead — showing a per-type table of
    // all-zero rows would misleadingly read as "nothing sold" even though
    // ticketsSold/grossTicketSales above (the effective, fallen-back-to-manual
    // figures) say otherwise. Only render the breakdown when at least one
    // in-house sale actually happened — mirrors print.js's renderSettlementSection.
    const inHouseTicketsSold = (d.ticket_types || []).reduce((sum, t) => sum + Number(t.sold || 0), 0);
    const ticketRows = (d.ticket_types || []).map((t) => `<tr>
        <td>${esc(t.name)}</td>
        <td class="amount">${esc(money(t.price))}</td>
        <td class="amount">${esc(String(t.sold))} / ${esc(String(t.quantity_total))}</td>
        <td class="amount">${esc(money(t.gross_sales))}</td>
      </tr>`).join('');

    const vendorRows = (d.vendors || []).map((v) => `<tr>
        <td>${esc(v.company_name || titleCase(v.service_category))}</td>
        <td>${esc(titleCase(v.service_category))}</td>
        <td>${esc(titleCase(v.payment_status))}</td>
        <td class="amount">${esc(money(v.amount))}</td>
      </tr>`).join('');

    const staffingRows = (d.staffing || []).map((row) => `<tr>
        <td>${esc(row.staff_name || '—')}</td>
        <td>${esc(titleCase(row.role))}</td>
        <td class="amount">${esc(Number(row.hours || 0).toFixed(1))}</td>
        <td class="amount">${esc(money(row.hourly_rate || 0))}</td>
        <td class="amount">${esc(money(row.cost))}</td>
      </tr>`).join('');

    const lineupRows = (d.lineup || []).map((l) => `<tr>
        <td>${esc(l.display_name)}</td>
        <td>${esc(titleCase(l.status))}</td>
        <td>${esc(l.payout_terms || '—')}</td>
      </tr>`).join('');

    // Revenue-split / guarantee sign-off: promoter_settlement / artist_guarantee
    // cost entries (what's been committed) net of promoter_payout / artist_payout
    // payment entries (what's already been disbursed) — see src/Events/Report.php.
    const payoutObligations = d.payout_obligations || [];
    const payoutRows = payoutObligations.map((p) => `<tr>
        <td>${esc(p.label)}</td>
        <td class="amount">${esc(money(p.committed))}</td>
        <td class="amount">${esc(money(p.disbursed))}</td>
        <td class="amount"><strong>${esc(money(p.still_owed))}</strong></td>
      </tr>`).join('');

    // Bottom line: nets a client-billed receivable (rental fee/hosted bar/
    // etc. minus payments received), a door shortfall (non-split costs
    // exceeding door-collected ticket/bar revenue — what the client owes for
    // staffing when the draw was weak), and the payout-obligations total
    // (what the venue owes out to the promoter/artist) into one sign-off
    // figure — see src/Events/Report.php's class doc block for the reasoning
    // (a plain "Gross Revenue − Payments Received" reads a fully-covered
    // door split as an unpaid client receivable, which it isn't).
    const bottomLineType = d.bottom_line_type || 'settled';
    const bottomLineColor = bottomLineType === 'due_from_client' ? 'var(--red, #ef4338)'
      : bottomLineType === 'due_to_promoter' ? 'var(--green, #0f8f46)'
      : 'var(--green, #0f8f46)';
    const bottomLineLabel = bottomLineType === 'due_from_client' ? 'Balance Due From Client'
      : bottomLineType === 'due_to_promoter' ? 'Amount To Pay Promoter/Artist'
      : 'Settled — No Balance Due';
    const bottomLineNote = bottomLineType === 'due_from_client'
      ? 'Includes any unpaid room/rental billing plus a staffing shortfall not covered by ticket sales, net of what the venue owes out to the promoter/artist.'
      : bottomLineType === 'due_to_promoter'
        ? 'Ticket sales covered staffing; this is the revenue-split payout still owed to the promoter/artist (see Payout Obligations below), not a client bill.'
        : 'Ticket sales covered costs and no payout is outstanding.';

    const closeout = d.closeout || {};
    const closeoutLabel = closeout.finalized_at
      ? `Finalized ${shortDate(eventDate({ date: closeout.finalized_at.slice(0, 10) }))}`
      : titleCase(closeout.status || 'open');

    this.innerHTML = `
      <section class="panel rpt-print-area">
        <div class="section-head padded">
          <h2>P&amp;L / Settlement Report</h2>
          <div class="section-head-actions">
            <button type="button" class="secondary small" data-copy-report-link title="Copy a link straight to this event's Report tab (staff/owner login required — for an external, no-login link use the header's Share button instead)"><i class="fa-solid fa-link" aria-hidden="true"></i> Copy Link</button>
            <button type="button" class="secondary small" data-download-csv title="Download this report as a spreadsheet (CSV)"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Download CSV</button>
            <button type="button" class="secondary small" data-print-report><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
          </div>
        </div>
        <div class="panel-body">

          <header class="er-header">
            <h1>${esc(event.title || '')}</h1>
            <p>${esc(shortDate(eventDate({ date: event.date })))}${event.end_date && event.end_date !== event.date ? ` – ${esc(shortDate(eventDate({ date: event.end_date })))}` : ''} · ${esc(event.venue_name || '')}</p>
            <p class="er-closeout-status">Closeout: <strong>${esc(closeoutLabel)}</strong></p>
          </header>

          <section class="summary-card er-summary">
            <div class="summary-row"><span class="label">Gross Revenue</span><span class="value">${esc(money(s.gross_revenue || 0))}</span></div>
            <div class="summary-row"><span class="label">Total Costs</span><span class="value">${esc(money(s.total_costs || 0))}</span></div>
            <div class="summary-row"><span class="label">Venue Net</span><span class="value" style="color:${netColor}">${esc(money(s.venue_net || 0))}</span></div>
            <div class="summary-row"><span class="label">Margin</span><span class="value">${esc(s.margin_pct != null ? Number(s.margin_pct).toFixed(1) : '0.0')}%</span></div>
            <div class="summary-row"><span class="label">Tickets Sold</span><span class="value">${esc(String(ticketsSold))}${ticketSourceNote ? ` <span class="er-note">(${esc(ticketSourceNote)})</span>` : ''}</span></div>
            <div class="summary-row"><span class="label">Gross Ticket Sales</span><span class="value">${esc(money(grossTicketSales))}</span></div>
            <div class="summary-row"><span class="label">Payments Received</span><span class="value">${esc(money(d.payments_received ?? s.total_payments ?? 0))}</span></div>
          </section>

          <section class="summary-card er-bottom-line">
            <div class="summary-row"><span class="label">${esc(bottomLineLabel)}</span><span class="value" style="color:${bottomLineColor}">${esc(money(d.bottom_line_amount || 0))}</span></div>
          </section>
          <p class="er-note">${esc(bottomLineNote)}</p>

          <h3 class="panel-subtitle">Revenue</h3>
          <table class="data-table er-table">
            <thead><tr><th>Category</th><th>Description</th><th>Amount</th></tr></thead>
            <tbody>${groupTable('Revenue', revenueEntries)}</tbody>
          </table>

          <h3 class="panel-subtitle">Costs</h3>
          <table class="data-table er-table">
            <thead><tr><th>Category</th><th>Description</th><th>Amount</th></tr></thead>
            <tbody>${groupTable('Costs', costEntries)}</tbody>
          </table>

          <h3 class="panel-subtitle">Payments &amp; Balance</h3>
          <table class="data-table er-table">
            <thead><tr><th>Category</th><th>Description</th><th>Amount</th></tr></thead>
            <tbody>${groupTable('Payments', paymentEntries)}</tbody>
          </table>
          <div class="summary-card er-balance">
            <div class="summary-row"><span class="label">Payments Received</span><span class="value">${esc(money(d.payments_received || 0))}</span></div>
            <div class="summary-row"><span class="label">Disbursed</span><span class="value">${esc(money(d.disbursed || 0))}</span></div>
            <div class="summary-row"><span class="label">Client-Billed Balance Due</span><span class="value" style="color:${Number(d.client_receivable || 0) > 0 ? 'var(--red, #ef4338)' : 'var(--green, #0f8f46)'}">${esc(money(d.client_receivable || 0))}</span></div>
            <div class="summary-row"><span class="label">Staffing Shortfall (net of door revenue)</span><span class="value" style="color:${Number(d.door_shortfall || 0) > 0 ? 'var(--red, #ef4338)' : 'var(--green, #0f8f46)'}">${esc(money(d.door_shortfall || 0))}</span></div>
          </div>
          <p class="er-note">Client-Billed Balance Due = unpaid room rental / hosted-bar / equipment billing (invoice or deposit still owed). Staffing Shortfall = staffing, security and production costs not covered by ticket/bar revenue — what the client owes when the draw was weak, already net of ticket sales. Neither reflects payouts the venue owes the promoter/artist — see Payout Obligations and the Bottom Line above.</p>

          ${inHouseTicketsSold > 0 ? `
          <h3 class="panel-subtitle">Ticket Sales</h3>
          <table class="data-table er-table">
            <thead><tr><th>Type</th><th>Price</th><th>Sold / Total</th><th>Gross</th></tr></thead>
            <tbody>${ticketRows}</tbody>
          </table>` : ''}

          ${(d.vendors || []).length ? `
          <h3 class="panel-subtitle">Vendor Costs <span class="er-subtotal">${esc(money(d.vendor_total || 0))}</span></h3>
          <table class="data-table er-table">
            <thead><tr><th>Vendor</th><th>Category</th><th>Payment Status</th><th>Amount</th></tr></thead>
            <tbody>${vendorRows}</tbody>
          </table>` : ''}

          ${(d.staffing || []).length ? `
          <h3 class="panel-subtitle">Staffing / Labor Costs <span class="er-subtotal">${esc(money(d.staffing_total || 0))}</span></h3>
          <table class="data-table er-table">
            <thead><tr><th>Staff</th><th>Role</th><th>Hours</th><th>Rate</th><th>Cost</th></tr></thead>
            <tbody>${staffingRows}</tbody>
          </table>` : ''}

          ${(d.lineup || []).length ? `
          <h3 class="panel-subtitle">Artist / Lineup Payouts</h3>
          <table class="data-table er-table">
            <thead><tr><th>Artist</th><th>Status</th><th>Payout Terms</th></tr></thead>
            <tbody>${lineupRows}</tbody>
          </table>` : ''}

          ${d.manual_settlement ? `
          <h3 class="panel-subtitle">Door / Manual Settlement Record</h3>
          <p class="er-note">Hand-entered on the Settlement tab the night of the show — kept as a supplementary record; the Revenue/Costs/Payments figures above (from the Closeout ledger) are authoritative.</p>
          <div class="summary-card">
            <div class="summary-row"><span class="label">Tickets Sold</span><span class="value">${esc(String(d.manual_settlement.tickets_sold || 0))}</span></div>
            <div class="summary-row"><span class="label">Gross Ticket Sales</span><span class="value">${esc(money(d.manual_settlement.gross_ticket_sales || 0))}</span></div>
            <div class="summary-row"><span class="label">Bar Sales</span><span class="value">${esc(money(d.manual_settlement.bar_sales || 0))}</span></div>
            <div class="summary-row"><span class="label">Expenses</span><span class="value">${esc(money(d.manual_settlement.expenses || 0))}</span></div>
            <div class="summary-row"><span class="label">Band Payouts</span><span class="value">${esc(money(d.manual_settlement.band_payouts || 0))}</span></div>
            <div class="summary-row"><span class="label">Promoter Payout</span><span class="value">${esc(money(d.manual_settlement.promoter_payout || 0))}</span></div>
          </div>` : ''}

          ${payoutObligations.length ? `
          <h3 class="panel-subtitle">Payout Obligations — Sign-Off Required</h3>
          <p class="er-note">Revenue-split / guarantee amounts committed on the Closeout ledger (promoter_settlement / artist_guarantee cost entries), net of whatever's already been disbursed (promoter_payout / artist_payout payment entries). "Still Owed" is the amount awaiting approval before disbursement.</p>
          <table class="data-table er-table">
            <thead><tr><th>Party</th><th class="amount">Committed</th><th class="amount">Disbursed</th><th class="amount">Still Owed</th></tr></thead>
            <tbody>${payoutRows}</tbody>
          </table>` : ''}

        </div>
      </section>

      <style>
        .er-header { margin-bottom: 1rem; }
        .er-header h1 { margin: 0 0 0.15rem; font-size: 1.3rem; }
        .er-header p { margin: 0; color: var(--muted, #6f7582); font-size: 0.9rem; }
        .er-closeout-status { margin-top: 0.35rem !important; }
        .er-summary { max-width: 420px; margin-bottom: 1.5rem; }
        .er-bottom-line { max-width: 420px; margin-bottom: 0.3rem; border-width: 2px; }
        .er-bottom-line .label { font-weight: 700; }
        .er-bottom-line .value { font-size: 1.15rem; font-weight: 700; }
        .panel-subtitle { margin: 1.25rem 0 0.5rem; font-size: 0.95rem; font-weight: 700; color: var(--muted, #6f7582); text-transform: uppercase; letter-spacing: 0.04em; }
        .er-subtotal { float: right; text-transform: none; letter-spacing: normal; font-weight: 700; color: var(--ink, #101318); }
        .er-table th { text-align: left; font-size: 0.78rem; color: var(--muted, #6f7582); border-bottom: 1px solid var(--line, #dfe3e8); padding: 4px 6px; }
        .er-table td { padding: 5px 6px; border-bottom: 1px solid var(--line, #dfe3e8); }
        .er-table .amount { text-align: right; font-variant-numeric: tabular-nums; }
        .er-table tr.er-subtotal-row td { background: var(--soft, #eef0f3); }
        .er-note { font-size: 0.8rem; color: var(--muted, #6f7582); margin: 0.3rem 0 1rem; }
        .er-balance { max-width: 420px; margin-bottom: 0.5rem; }
        @media print {
          [data-print-report] { display: none !important; }
        }
      </style>`;

    // Opens the same formal, signable Settlement Report print window used by
    // the workspace header's Print ▸ Settlement Report button (print.js) —
    // this._data is already the exact GET /api/events/{id}/report payload
    // that builder expects. Previously this called window.print() directly
    // on the in-page tab, which (with no app-wide print stylesheet to hide
    // the sidebar/tab bar) printed the whole workspace chrome along with the
    // report instead of a clean statement.
    $('[data-print-report]', this)?.addEventListener('click', () => openPrintWindow('settlement', this._data));
    $('[data-copy-report-link]', this)?.addEventListener('click', () => {
      navigator.clipboard.writeText(appUrl(`#event-${event.id}-report`)).then(() => publish('toast.show', { message: 'Report link copied!' }));
    });
    $('[data-download-csv]', this)?.addEventListener('click', () => {
      const stamp = new Date().toISOString().slice(0, 10);
      downloadTextFile(buildSettlementCsv(d), `settlement-report_${event.id}_${stamp}.csv`, 'text/csv;charset=utf-8;');
    });
  }
}
customElements.define('pb-event-report', EventReport);
