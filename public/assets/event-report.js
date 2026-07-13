// ── Per-event P&L / Settlement Report ────────────────────────────────────────
// A read-only, printable statement for one event: the server-computed P&L
// (same numbers as the Closeout tab's ledger summary — never recomputed
// differently here), plus the cost detail that explains it: vendor bills,
// staffing labor cost, lineup payout terms, and a ticket-type breakdown.
// Source: GET /api/events/{id}/report (src/Events/Report.php).

import { esc, titleCase, api, money, shortDate, eventDate, emptyState, PanicElement, $ } from './core.js';

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

    const byCategoryRows = Object.entries(s.by_category || {})
      .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]))
      .map(([cat, amt]) => `<tr><td>${esc(titleCase(cat))}</td><td class="amount">${esc(money(amt))}</td></tr>`)
      .join('');

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

    const closeout = d.closeout || {};
    const closeoutLabel = closeout.finalized_at
      ? `Finalized ${shortDate(eventDate({ date: closeout.finalized_at.slice(0, 10) }))}`
      : titleCase(closeout.status || 'open');

    this.innerHTML = `
      <section class="panel rpt-print-area">
        <div class="section-head padded">
          <h2>P&amp;L / Settlement Report</h2>
          <button type="button" class="secondary small" data-print-report><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
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
            <div class="summary-row"><span class="label">Tickets Sold</span><span class="value">${esc(String(s.tickets_sold || 0))}</span></div>
            <div class="summary-row"><span class="label">Payments Received</span><span class="value">${esc(money(s.total_payments || 0))}</span></div>
          </section>

          <h3 class="panel-subtitle">Ledger by Category</h3>
          <table class="data-table er-table">
            <thead><tr><th>Category</th><th>Amount</th></tr></thead>
            <tbody>${byCategoryRows || `<tr><td colspan="2">${emptyState('No ledger entries yet.')}</td></tr>`}</tbody>
          </table>

          ${(d.ticket_types || []).length ? `
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

        </div>
      </section>

      <style>
        .er-header { margin-bottom: 1rem; }
        .er-header h1 { margin: 0 0 0.15rem; font-size: 1.3rem; }
        .er-header p { margin: 0; color: var(--muted, #6f7582); font-size: 0.9rem; }
        .er-closeout-status { margin-top: 0.35rem !important; }
        .er-summary { max-width: 420px; margin-bottom: 1.5rem; }
        .panel-subtitle { margin: 1.25rem 0 0.5rem; font-size: 0.95rem; font-weight: 700; color: var(--muted, #6f7582); text-transform: uppercase; letter-spacing: 0.04em; }
        .er-subtotal { float: right; text-transform: none; letter-spacing: normal; font-weight: 700; color: var(--ink, #101318); }
        .er-table th { text-align: left; font-size: 0.78rem; color: var(--muted, #6f7582); border-bottom: 1px solid var(--line, #dfe3e8); padding: 4px 6px; }
        .er-table td { padding: 5px 6px; border-bottom: 1px solid var(--line, #dfe3e8); }
        .er-table .amount { text-align: right; font-variant-numeric: tabular-nums; }
        @media print {
          [data-print-report] { display: none !important; }
        }
      </style>`;

    $('[data-print-report]', this)?.addEventListener('click', () => window.print());
  }
}
customElements.define('pb-event-report', EventReport);
