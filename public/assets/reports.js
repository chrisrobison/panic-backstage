// ── Venue-wide Reports ───────────────────────────────────────────────────────
// Two tabs sharing one filter bar (date range + event status):
//   Overview    — KPI cards, monthly revenue/cost trend, category breakdown,
//                 best/worst performing events
//   Settlements — one row per event with computed P&L + closeout status,
//                 sortable, CSV-exportable
//
// Every number comes from GET /api/reports/(overview|settlements), which in
// turn is computed from event_ledger_entries — the same source of truth
// Events\Ledger::calculateSummary() uses for a single event's Closeout tab.
// There is no separate "reports math" to keep in sync with the ledger.

import {
  esc, titleCase, api, apiUrl, getToken, money, isoDate, addDays,
  shortDate, eventDate, statusLabel, badge, publish, emptyState,
  PanicElement, $, $$,
} from './core.js';

const PRESETS = [
  ['last12',    'Last 12 Months'],
  ['ytd',       'This Year'],
  ['last30',    'Last 30 Days'],
  ['thismonth', 'This Month'],
  ['all',       'All Time'],
  ['custom',    'Custom Range'],
];

const REPORT_STATUSES = [
  'empty', 'proposed', 'confirmed', 'booked', 'needs_assets', 'assets_approved',
  'ready_to_announce', 'published', 'advanced', 'completed', 'settled', 'canceled',
];

function presetRange(preset) {
  const today = new Date();
  switch (preset) {
    case 'ytd':       return { from: `${today.getFullYear()}-01-01`, to: isoDate(today) };
    case 'last30':    return { from: isoDate(addDays(today, -30)), to: isoDate(today) };
    case 'thismonth': return { from: isoDate(new Date(today.getFullYear(), today.getMonth(), 1)), to: isoDate(today) };
    case 'all':       return { from: '2000-01-01', to: isoDate(addDays(today, 3650)) };
    case 'last12':
    default: {
      const from = new Date(today);
      from.setMonth(from.getMonth() - 12);
      return { from: isoDate(from), to: isoDate(today) };
    }
  }
}

function monthLabel(ym) {
  const [y, m] = String(ym).split('-').map(Number);
  if (!y || !m) return esc(ym);
  return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
}

// Fetch a report endpoint as a file download (CSV) — needs the bearer token
// on the request, so a plain <a href> won't do; core.js's api() helper only
// returns parsed JSON, so this borrows its auth header directly.
async function downloadReport(path, filename) {
  try {
    const token = getToken();
    const res = await fetch(apiUrl(path), { headers: token ? { Authorization: `Bearer ${token}` } : {} });
    if (!res.ok) throw new Error(`Export failed (${res.status})`);
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    publish('toast.show', { message: err.message || 'Export failed.', tone: 'error' });
  }
}

// ── Chart building blocks ─────────────────────────────────────────────────────

// Grouped bar chart: two thin bars (revenue/cost) per month, sharing one
// dollar axis. Native <title> tooltips give a per-month hover readout without
// a bespoke JS tooltip layer.
function trendChartSvg(trend) {
  if (!trend.length) return emptyState('No ledger activity in this range yet.');
  const W = 720, H = 220, padL = 44, padR = 12, padT = 14, padB = 26;
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;
  const n = trend.length;
  const maxVal = Math.max(1, ...trend.flatMap((t) => [t.revenue, t.costs]));
  const groupW = innerW / n;
  const barW = Math.max(3, Math.min(20, groupW * 0.32));
  const gap = 2;
  const yFor = (v) => padT + innerH - (v / maxVal) * innerH;

  const gridLines = [0, 0.25, 0.5, 0.75, 1].map((f) => {
    const y = (padT + innerH * (1 - f)).toFixed(1);
    const val = money(maxVal * f);
    return `<line x1="${padL}" x2="${W - padR}" y1="${y}" y2="${y}" class="rpt-grid" />
      <text x="${padL - 6}" y="${Number(y) + 3}" text-anchor="end" class="rpt-axis-label">${esc(val)}</text>`;
  }).join('');

  const bars = trend.map((t, i) => {
    const cx = padL + groupW * i + groupW / 2;
    const revH = (t.revenue / maxVal) * innerH;
    const costH = (t.costs / maxVal) * innerH;
    const revX = (cx - barW - gap / 2).toFixed(1);
    const costX = (cx + gap / 2).toFixed(1);
    const title = `${monthLabel(t.ym)}: Revenue ${money(t.revenue)} · Costs ${money(t.costs)} · Net ${money(t.net)}`;
    return `<g>
        <title>${esc(title)}</title>
        <rect x="${revX}" y="${yFor(t.revenue).toFixed(1)}" width="${barW.toFixed(1)}" height="${Math.max(0, revH).toFixed(1)}" rx="3" fill="var(--green,#0f8f46)" />
        <rect x="${costX}" y="${yFor(t.costs).toFixed(1)}" width="${barW.toFixed(1)}" height="${Math.max(0, costH).toFixed(1)}" rx="3" fill="var(--red,#ef4338)" />
      </g>`;
  }).join('');

  const step = n > 8 ? Math.ceil(n / 8) : 1;
  const xLabels = trend.map((t, i) => {
    if (i % step !== 0) return '';
    const cx = padL + groupW * i + groupW / 2;
    return `<text x="${cx.toFixed(1)}" y="${H - 8}" text-anchor="middle" class="rpt-axis-label">${esc(monthLabel(t.ym))}</text>`;
  }).join('');

  return `<svg viewBox="0 0 ${W} ${H}" class="rpt-chart" role="img" aria-label="Monthly revenue and costs">
    ${gridLines}${bars}${xLabels}
  </svg>`;
}

// Horizontal single-hue bar list for a category breakdown — magnitude only,
// so one color per side (green for revenue categories, red for costs) rather
// than a many-way categorical palette.
function categoryBarList(items, color) {
  if (!items.length) return emptyState('No entries yet.');
  const max = Math.max(1, ...items.map((i) => i.total));
  return `<ul class="rpt-cat-list">${items.slice(0, 10).map((i) => `
    <li>
      <span class="rpt-cat-label">${esc(titleCase(i.category))}</span>
      <span class="rpt-cat-track"><span class="rpt-cat-fill" style="width:${Math.max(2, (i.total / max) * 100).toFixed(1)}%;background:${color}"></span></span>
      <span class="rpt-cat-value">${esc(money(i.total))}</span>
    </li>`).join('')}</ul>`;
}

function eventLink(id, title) {
  return `<a href="#event-${esc(String(id))}">${esc(title || `Event #${id}`)}</a>`;
}

function rankedEventRow(e) {
  const tone = e.venue_net >= 0 ? 'green' : 'red';
  return `<tr>
    <td>${eventLink(e.id, e.title)}</td>
    <td>${esc(shortDate(eventDate({ date: e.date })))}</td>
    <td class="amount ${tone}">${esc(money(e.venue_net))}</td>
    <td class="amount">${esc(String(e.margin_pct))}%</td>
  </tr>`;
}

// ── Main component ────────────────────────────────────────────────────────────

class ReportsPage extends PanicElement {
  connect() {
    publish('page.context', { title: 'Reports', blurb: 'P&L and settlement reporting across every event.' });
    this.tab = 'overview';
    this.preset = 'last12';
    const range = presetRange(this.preset);
    this.from = range.from;
    this.to = range.to;
    this.status = '';
    this.load();
  }

  async load() {
    this.setLoading('Loading reports');
    try {
      if (this.tab === 'settlements') {
        this.settlementsData = await api(`/reports/settlements${this.query()}`);
      } else {
        this.overviewData = await api(`/reports/overview${this.query()}`);
      }
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  query() {
    const params = new URLSearchParams({ from: this.from, to: this.to });
    if (this.status) params.set('status', this.status);
    return `?${params.toString()}`;
  }

  filterBarHtml() {
    return `<div class="rpt-filter-bar">
      <label>Range
        <select data-preset>${PRESETS.map(([k, l]) => `<option value="${k}" ${k === this.preset ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
      </label>
      <label>From <input type="date" data-from value="${esc(this.from)}" ${this.preset !== 'custom' ? 'disabled' : ''}></label>
      <label>To <input type="date" data-to value="${esc(this.to)}" ${this.preset !== 'custom' ? 'disabled' : ''}></label>
      <label>Status
        <select data-status>
          <option value="">All statuses</option>
          ${REPORT_STATUSES.map((s) => `<option value="${esc(s)}" ${s === this.status ? 'selected' : ''}>${esc(statusLabel(s))}</option>`).join('')}
        </select>
      </label>
    </div>`;
  }

  render() {
    this.innerHTML = `
      <div class="page-head">
        <h1>Reports</h1>
      </div>
      <nav class="workspace-tabs tabs reports-tabs">
        <a data-rpt-tab="overview" href="#reports" class="${this.tab === 'overview' ? 'active' : ''}">Overview</a>
        <a data-rpt-tab="settlements" href="#reports" class="${this.tab === 'settlements' ? 'active' : ''}">Settlements</a>
      </nav>
      ${this.filterBarHtml()}
      <div class="rpt-outlet">${this.tab === 'settlements' ? this.settlementsHtml() : this.overviewHtml()}</div>
      ${this.styleBlock()}`;
    this.bind();
  }

  overviewHtml() {
    const d = this.overviewData || {};
    const t = d.totals || {};
    const netTone = (t.venue_net ?? 0) >= 0 ? 'green' : 'red';
    const card = (icon, label, value, note, tone = '') => `<article class="metric-card ${tone}"><span class="icon-bubble ${tone}"><i class="fa-solid ${icon}" aria-hidden="true"></i></span><h3>${esc(label)}</h3><strong>${esc(value)}</strong><p>${esc(note)}</p></article>`;

    return `
      <section class="metric-grid rpt-metric-grid">
        ${card('fa-sack-dollar', 'Gross Revenue', money(t.gross_revenue), `${t.events_count ?? 0} events in range`, 'green')}
        ${card('fa-file-invoice-dollar', 'Total Costs', money(t.total_costs), 'Labor, vendors, artists & more', 'red')}
        ${card('fa-scale-balanced', 'Venue Net', money(t.venue_net), `${t.margin_pct ?? 0}% margin`, netTone)}
        ${card('fa-ticket', 'Tickets Sold', t.tickets_sold ?? 0, money(t.gross_ticket_sales ?? 0) + ' gross')}
        ${card('fa-chart-line', 'Avg Net / Event', money(t.avg_net_per_event), 'Per event in range')}
        ${card('fa-clipboard-check', 'Awaiting Closeout', t.unsettled_count ?? 0, 'Completed, not finalized', (t.unsettled_count ?? 0) > 0 ? 'amber' : '')}
      </section>

      <article class="panel">
        <div class="section-head padded"><h2>Revenue vs. Costs by Month</h2>
          <span class="rpt-legend"><span class="rpt-swatch" style="background:var(--green,#0f8f46)"></span>Revenue<span class="rpt-swatch" style="background:var(--red,#ef4338)"></span>Costs</span>
        </div>
        <div class="panel-body">${trendChartSvg(d.trend || [])}</div>
        <details class="rpt-table-toggle"><summary>View as table</summary>${this.trendTableHtml(d.trend || [])}</details>
      </article>

      <section class="rpt-cat-grid">
        <article class="panel">
          <div class="section-head padded"><h2>Top Revenue Categories</h2></div>
          <div class="panel-body">${categoryBarList(d.revenue_by_category || [], 'var(--green,#0f8f46)')}</div>
        </article>
        <article class="panel">
          <div class="section-head padded"><h2>Top Cost Categories</h2></div>
          <div class="panel-body">${categoryBarList(d.cost_by_category || [], 'var(--red,#ef4338)')}</div>
        </article>
      </section>

      <section class="rpt-cat-grid">
        <article class="panel">
          <div class="section-head padded"><h2>Best Performing Events</h2></div>
          <table class="data-table"><thead><tr><th>Event</th><th>Date</th><th>Net</th><th>Margin</th></tr></thead>
            <tbody>${(d.top_events || []).length ? d.top_events.map(rankedEventRow).join('') : `<tr><td colspan="4">${emptyState('No events in range.')}</td></tr>`}</tbody></table>
        </article>
        <article class="panel">
          <div class="section-head padded"><h2>Worst Performing Events</h2></div>
          <table class="data-table"><thead><tr><th>Event</th><th>Date</th><th>Net</th><th>Margin</th></tr></thead>
            <tbody>${(d.bottom_events || []).length ? d.bottom_events.map(rankedEventRow).join('') : `<tr><td colspan="4">${emptyState('No events in range.')}</td></tr>`}</tbody></table>
        </article>
      </section>`;
  }

  trendTableHtml(trend) {
    if (!trend.length) return emptyState('No data.');
    return `<table class="data-table">
      <thead><tr><th>Month</th><th>Revenue</th><th>Costs</th><th>Net</th></tr></thead>
      <tbody>${trend.map((t) => `<tr><td>${esc(monthLabel(t.ym))}</td><td class="amount">${esc(money(t.revenue))}</td><td class="amount">${esc(money(t.costs))}</td><td class="amount">${esc(money(t.net))}</td></tr>`).join('')}</tbody>
    </table>`;
  }

  settlementsHtml() {
    const rows = this.settlementsData?.settlements || [];
    // Reuses the generic .badge.success/.info/.warning tone classes (see
    // app.css — "used by promote and potentially others") rather than the
    // event-status .status-* classes, which are keyed by literal event
    // status strings and have no entry for closeout states.
    const closeoutTone = { finalized: 'success', reopened: 'warning', in_progress: 'info', pending_review: 'info' };
    const row = (r) => {
      const tone = r.venue_net >= 0 ? 'green' : 'red';
      return `<tr>
        <td>${eventLink(r.id, r.title)}</td>
        <td>${esc(shortDate(eventDate({ date: r.date })))}</td>
        <td>${badge(r.status)}</td>
        <td class="amount">${esc(money(r.gross_revenue))}</td>
        <td class="amount">${esc(money(r.total_costs))}</td>
        <td class="amount ${tone}">${esc(money(r.venue_net))}</td>
        <td class="amount">${esc(String(r.margin_pct))}%</td>
        <td><span class="badge ${closeoutTone[r.closeout_status] || ''}">${esc(titleCase(r.closeout_status || 'open'))}</span></td>
      </tr>`;
    };
    return `<article class="panel">
      <div class="section-head padded">
        <h2>Settlement Report</h2>
        <button type="button" class="secondary small" data-export-csv><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
      </div>
      <table class="data-table">
        <thead><tr><th>Event</th><th>Date</th><th>Status</th><th>Gross Revenue</th><th>Total Costs</th><th>Venue Net</th><th>Margin</th><th>Closeout</th></tr></thead>
        <tbody>${rows.length ? rows.map(row).join('') : `<tr><td colspan="8">${emptyState('No events in this range.')}</td></tr>`}</tbody>
      </table>
    </article>`;
  }

  bind() {
    $$('[data-rpt-tab]', this).forEach((a) => a.addEventListener('click', (event) => {
      event.preventDefault();
      this.tab = a.dataset.rptTab;
      this.load();
    }));
    $('[data-preset]', this)?.addEventListener('change', (event) => {
      this.preset = event.target.value;
      if (this.preset !== 'custom') {
        const range = presetRange(this.preset);
        this.from = range.from;
        this.to = range.to;
      }
      this.load();
    });
    $('[data-from]', this)?.addEventListener('change', (event) => {
      this.from = event.target.value;
      this.preset = 'custom';
      this.load();
    });
    $('[data-to]', this)?.addEventListener('change', (event) => {
      this.to = event.target.value;
      this.preset = 'custom';
      this.load();
    });
    $('[data-status]', this)?.addEventListener('change', (event) => {
      this.status = event.target.value;
      this.load();
    });
    $('[data-export-csv]', this)?.addEventListener('click', () => {
      downloadReport(`/reports/settlements${this.query()}&format=csv`, `settlement-report_${this.from}_to_${this.to}.csv`);
    });
  }

  styleBlock() {
    return `<style>
      .rpt-filter-bar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin: 0 0 1rem; }
      .rpt-filter-bar label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.8rem; color: var(--muted, #6f7582); font-weight: 600; }
      .rpt-filter-bar select, .rpt-filter-bar input { font: inherit; padding: 6px 8px; border: 1px solid var(--line, #dfe3e8); border-radius: 6px; }
      /* Large dollar totals (e.g. "$1,040,392.50") have no natural break point,
         and CSS Grid items default to a min-width based on their content's
         intrinsic size — so without the overrides below, one big number
         forces its whole column wider than the grid, overflowing every card
         in the row. minmax(0, 1fr) lets the track shrink below content size;
         overflow-wrap/word-break give the browser somewhere to break a long
         unbroken number as a last resort. .metric-card's own inner grid
         (icon column + content column) needs the same minmax(0, 1fr) fix. */
      .rpt-metric-grid { margin-bottom: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
      .rpt-metric-grid .metric-card { grid-template-columns: 60px minmax(0, 1fr); min-width: 0; }
      .rpt-metric-grid .metric-card strong,
      .rpt-metric-grid .metric-card h3,
      .rpt-metric-grid .metric-card p { overflow-wrap: anywhere; word-break: break-word; }
      .rpt-metric-grid .metric-card strong { font-size: clamp(20px, 2.4vw, 38px); display: block; }
      .rpt-legend { font-size: 0.8rem; color: var(--muted, #6f7582); display: inline-flex; align-items: center; gap: 0.3rem; }
      .rpt-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-left: 0.6rem; }
      .rpt-swatch:first-child { margin-left: 0; }
      .rpt-chart { width: 100%; height: auto; display: block; }
      .rpt-grid { stroke: var(--line, #dfe3e8); stroke-width: 1; }
      .rpt-axis-label { font-size: 9px; fill: var(--muted, #6f7582); }
      .rpt-table-toggle { padding: 0 1rem 1rem; }
      .rpt-table-toggle summary { cursor: pointer; font-size: 0.85rem; color: var(--blue, #1268c7); font-weight: 600; }
      .rpt-cat-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; margin-bottom: 1rem; }
      .rpt-cat-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.55rem; }
      .rpt-cat-list li { display: grid; grid-template-columns: 120px minmax(0, 1fr) auto; align-items: center; gap: 0.6rem; font-size: 0.85rem; }
      .rpt-cat-label { color: var(--muted, #6f7582); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .rpt-cat-track { background: var(--soft, #eef0f3); border-radius: 4px; height: 10px; overflow: hidden; }
      .rpt-cat-fill { display: block; height: 100%; border-radius: 4px; }
      .rpt-cat-value { font-variant-numeric: tabular-nums; text-align: right; font-weight: 600; overflow-wrap: anywhere; }
      /* .data-table and .amount are shared, global classes (used by ~40 other
         panels app-wide) — scope every override to pb-reports-page so this
         can't change table layout anywhere else. */
      pb-reports-page .data-table .amount { text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
      pb-reports-page .data-table .amount.green { color: var(--green, #0f8f46); }
      pb-reports-page .data-table .amount.red { color: var(--red, #ef4338); }
      pb-reports-page .data-table td, pb-reports-page .data-table th { overflow-wrap: anywhere; }
      @media (max-width: 860px) {
        .rpt-cat-grid { grid-template-columns: 1fr; }
      }
      @media print {
        .rpt-filter-bar, .reports-tabs, [data-export-csv] { display: none !important; }
      }
    </style>`;
  }
}
customElements.define('pb-reports-page', ReportsPage);
