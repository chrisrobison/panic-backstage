import { esc, titleCase, publish, api, apiUrl, getToken, formData, badge, option, select, helpLink, can, table, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';


// ── Contracts (admin) ─────────────────────────────────────────────────────────
// Scaffold for managing reusable contract sections/clauses. Full CRUD lands in
// a follow-up; this gives the Admin ▸ Contracts nav item a destination.
// ── Contract / Deal Builder ──────────────────────────────────────────────────

const CONTRACT_DEAL_COLUMNS = ['rental_fee', 'deposit_amount', 'balance_due_date', 'bar_minimum', 'guarantee_amount', 'door_split_artist', 'door_split_venue', 'door_split_promoter', 'advance_ticket_price', 'door_ticket_price', 'security_count', 'security_rate', 'security_paid_by', 'sound_tech_included', 'lighting_tech_included', 'merch_venue_percent', 'recurrence_rule', 'term_start', 'term_end', 'trial_period_weeks', 'termination_notice_days', 'review_cadence', 'revenue_split_house', 'revenue_split_producer'];


function contractStatusTone(status) {
  return {
    draft: 'gray', needs_review: 'amber', approved: 'green', sent: 'blue', signed: 'green', canceled: 'red', superseded: 'gray',
    // Digital-signature workflow (contracts + individual signers)
    ready_to_send: 'blue', pending: 'gray', viewed: 'blue', partially_signed: 'amber',
    signed_by_client: 'green', countersigned: 'green', fully_executed: 'green',
    voided: 'red', declined: 'red', expired: 'gray', error: 'red',
  }[status] || 'gray';
}

// Short human label for a signer/contract status token.
function contractStatusLabel(status) {
  return { signed_by_client: 'Signed by client', partially_signed: 'Partially signed', fully_executed: 'Fully executed', ready_to_send: 'Ready to send' }[status] || titleCase(status || '');
}

function contractStatusBadge(status) {
  return `<span class="badge status-${esc(contractStatusTone(status))}">${esc(contractStatusLabel(status))}</span>`;
}

function riskBadge(level) {
  if (!level || level === 'none') return '';
  return `<span class="risk-badge risk-${esc(level)}">${esc(level)}</span>`;
}

function parseJson(value, fallback) {
  if (value === null || value === undefined) return fallback;
  if (typeof value === 'object') return value;
  try { const decoded = JSON.parse(value); return decoded ?? fallback; } catch { return fallback; }
}


// Self-contained typography for contract print/PDF windows.
// @page sets letter size + margins; the browser handles everything from there.
const CONTRACT_DOC_CSS = `
  *, *::before, *::after { box-sizing: border-box; }

  body {
    font-family: Georgia, 'Times New Roman', serif;
    color: #111;
    background: #fff;
    margin: 0;
    padding: 40px 48px;
    line-height: 1.6;
  }

  .contract-doc { max-width: 7in; margin: 0 auto; }

  .contract-doc-head h1 { font-size: 22px; margin: 0 0 4px; }
  .contract-doc-sub    { color: #555; margin: 0 0 20px; font-style: italic; }

  .contract-summary { width: 100%; border-collapse: collapse; margin: 0 0 24px; font-size: 13px; }
  .contract-summary caption { text-align: left; font-weight: bold; padding-bottom: 6px; }
  .contract-summary th { text-align: left; width: 42%; padding: 4px 8px; color: #444; font-weight: normal; border-bottom: 1px solid #ddd; }
  .contract-summary td { padding: 4px 8px; border-bottom: 1px solid #ddd; }

  .contract-section    { margin: 0 0 20px; }
  .contract-section h2 { font-size: 15px; margin: 0 0 6px; }
  .contract-section-body p { margin: 0 0 8px; text-align: justify; }

  .contract-token-missing { background: #ffe2a8; color: #7a4b00; padding: 0 4px; border-radius: 3px; font-style: italic; }

  /* ── Print / Save as PDF ────────────────────────────────────────────────── */
  @media print {
    @page { size: 8.5in 11in; margin: 0.5in; }

    body { padding: 0; background: #fff; }
    .contract-doc { max-width: none; margin: 0; }
    .print-bar { display: none !important; }
  }
`;


/**
 * Open the contract preview in a new window.
 * Pass autoPrint:true to immediately trigger the print dialog (Download PDF flow).
 */
function printContractWindow(html, title, { autoPrint = false } = {}) {
  const win = window.open('', '_blank', 'width=900,height=1100');
  if (!win) {
    publish('toast.show', { message: 'Pop-up blocked — allow pop-ups to print.', tone: 'error' });
    return;
  }
  const autoScript = autoPrint
    ? `<script>window.addEventListener('load', function(){ window.print(); });<\/script>`
    : '';
  win.document.open();
  win.document.write(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>${esc(title)}</title>
  <style>
${CONTRACT_DOC_CSS}
    /* Screen-only print bar */
    .print-bar {
      position: sticky; top: 0; z-index: 99;
      background: #f5f5f5; border-bottom: 1px solid #ddd;
      padding: 10px 18px; display: flex; align-items: center; gap: 10px;
      font-family: system-ui, sans-serif; font-size: 13px;
    }
    .print-bar button { font: inherit; padding: 6px 14px; border: 1px solid #888; background: #fff; border-radius: 4px; cursor: pointer; }
    .print-bar .primary { background: #111; color: #fff; border-color: #111; }
    .print-bar .hint { color: #888; margin-left: 4px; }
  </style>
  ${autoScript}
</head>
<body>
  <div class="print-bar">
    <button class="primary" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button onclick="window.close()">Close</button>
    <span class="hint">In the print dialog choose "Save as PDF" to download</span>
  </div>
  ${html}
</body>
</html>`);
  win.document.close();
  win.focus();
}


/**
 * Request a server-rendered PDF from GET /api/contracts/{id}/pdf
 * (wkhtmltopdf on the server) and trigger a browser download.
 * Falls back to the print window if the endpoint fails.
 */
/** Safe PDF filename stem from a contract title. */
function pdfFilename(title, suffix = '') {
  const stem = (title || 'contract').replace(/[^\w\s-]/g, '').trim() || 'contract';
  return `${stem}${suffix}.pdf`;
}

/** Fetch an authed endpoint that returns a PDF and hand the browser a download. */
async function downloadAuthedPdf(path, filename) {
  const resp = await fetch(apiUrl(path), { headers: { Authorization: `Bearer ${getToken()}` } });
  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error(err.error || `Server error ${resp.status}`);
  }
  const blob = await resp.blob();
  const url  = URL.createObjectURL(blob);
  const a    = Object.assign(document.createElement('a'), { href: url, download: filename });
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

async function downloadContractPdf(contractId, title) {
  publish('toast.show', { message: 'Generating PDF…' });
  try {
    await downloadAuthedPdf(`contracts/${contractId}/pdf`, pdfFilename(title));
    publish('toast.show', { message: 'PDF downloaded.' });
  } catch (err) {
    publish('toast.show', { message: `PDF failed: ${err.message} — use Print instead.`, tone: 'error' });
  }
}


// Event workspace tab: list + create contracts for one event.
class EventContracts extends HTMLElement {
  set data(value) { this.eventData = value; this.load(); }

  async load() {
    try {
      this.list = await api(`/events/${this.eventData.event.id}/contracts`);
      this.render();
    } catch (error) {
      this.innerHTML = `<section class="panel padded"><p class="error-text">${esc(error.message)}</p></section>`;
    }
  }

  render() {
    const manage = can(this.eventData, 'manage_contracts');
    const contracts = this.list.contracts || [];
    const templates = this.list.templates || [];
    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Contracts ${helpLink('contracts', 'Contracts')}</h2><div class="section-head-actions"><span class="muted">${contracts.length} total</span>${addToggle('Create contract', manage)}</div></div>
      <table class="data-table contracts-table">
        <thead><tr><th>Title</th><th>Type</th><th>Counterparty</th><th>Status</th><th>Updated</th><th></th></tr></thead>
        <tbody>${contracts.map((c) => `<tr class="clickable-row" data-contract-href="#contract-${esc(c.id)}">
          <td><strong>${esc(c.title)}</strong></td>
          <td>${esc(titleCase(c.contract_type))}</td>
          <td>${esc(c.counterparty_name || '—')}</td>
          <td>${contractStatusBadge(c.status)}</td>
          <td class="muted">${esc((c.updated_at || '').slice(0, 10))}</td>
          <td class="row-action-cell"><a class="button small secondary" href="#contract-${esc(c.id)}">Open →</a></td>
        </tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">No contracts yet for this event.</div></td></tr>'}</tbody>
      </table>
      ${manage ? `<form class="row-form" data-form="new" data-add-form hidden>
        <label>Deal type <select name="template_id" required><option value="">Choose a template…</option>${templates.map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label>
        <label>Counterparty <input name="counterparty_name" placeholder="Artist / promoter / client"></label>
        <button>Create contract</button>
        <button type="button" class="secondary small" data-cancel-add>Cancel</button>
      </form>` : ''}
    </section>`;
    if (manage) {
      bindAddToggle(this);
      $('[data-form="new"]', this).addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
          const result = await api(`/events/${this.eventData.event.id}/contracts`, { method: 'POST', body: JSON.stringify(formData(event.target)) });
          publish('toast.show', { message: 'Contract created.' });
          location.hash = `#contract-${result.id}`;
        } catch (error) {
          publish('toast.show', { message: error.message, tone: 'error' });
        }
      });
    }
    // Make the entire row clickable — clicking anywhere except a button/link
    // navigates to the contract editor.
    $$('tr[data-contract-href]', this).forEach((row) => {
      row.addEventListener('click', (e) => {
        if (e.target.closest('a, button')) return;
        location.hash = row.dataset.contractHref;
      });
    });
  }
}


// Full-page contract builder reached via #contract-<id>.
class ContractEditor extends PanicElement {
  async connect() { await this.load(); }

  async load() {
    this.setLoading('Loading contract');
    try {
      this.data = await api(`/contracts/${this.contractId}`);
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  get manage() { return Boolean(this.data?.capabilities?.manage); }
  variables() { return parseJson(this.data.contract.variables_json, {}) || {}; }

  render() {
    const data = this.data;
    const contract = data.contract;
    const backHref = contract.event_id ? `#event-${contract.event_id}` : '#admin-contracts';
    const forLabel = data.event ? ` · ${esc(data.event.title)}` : (data.venue ? ` · ${esc(data.venue.name)}` : '');
    this.innerHTML = `<section class="event-top">
      <div><a class="back-link" href="${backHref}">&lt;- Back</a><h1>${esc(contract.title)}</h1>
        <p class="subtle">${esc(titleCase(contract.contract_type))} · ${contractStatusBadge(contract.status)}${forLabel}</p></div>
      <div class="event-actions">
        <button class="secondary" data-act="pdf">⬇ Download PDF</button>
        <button class="secondary" data-act="print">🖨 Print / Save PDF</button>
        ${this.manage ? '<button class="secondary" data-act="email-pdf">✉ Email PDF</button>' : ''}
        ${this.manage ? '<button data-act="render">Generate version</button>' : ''}
      </div>
    </section>
    <div class="contract-layout">
      <div class="contract-col-left">${this.manage ? this.dealFormHtml() : '<section class="panel padded"><p class="muted">You have read-only access to this contract.</p></section>'}</div>
      <div class="contract-col-center">
        <section class="panel">
          <div class="section-head padded"><h2>Preview</h2><span class="muted">${data.versions.length ? `v${data.versions[0].version_number} generated` : 'draft — not yet generated'}</span></div>
          <div class="contract-preview" data-preview>${data.preview_html}</div>
        </section>
      </div>
      <div class="contract-col-right">
        ${this.statusHtml()}
        ${this.manage ? this.signingHtml() : ''}
        ${this.warningsHtml()}
        ${this.modulesHtml()}
        ${this.versionsHtml()}
      </div>
    </div>`;
    this.bind();
  }

  dealFormHtml() {
    const contract = this.data.contract;
    const vars = this.variables();
    const paidByOpts = ['', ...(this.data.security_paid_by || [])];
    const groups = [
      ['Counterparty', [['counterparty_name', 'Name', 'text'], ['counterparty_org', 'Organization', 'text'], ['counterparty_email', 'Email', 'email']], true],
      ['Money & Splits', [['rental_fee', 'Rental fee ($)', 'number'], ['deposit_amount', 'Deposit ($)', 'number'], ['balance_due_date', 'Balance due', 'date'], ['bar_minimum', 'Bar minimum ($)', 'number'], ['guarantee_amount', 'Guarantee ($)', 'number'], ['revenue_split_house', 'House split (%)', 'number'], ['revenue_split_producer', 'Producer split (%)', 'number'], ['door_split_artist', 'Artist door (%)', 'number'], ['door_split_venue', 'Venue door (%)', 'number'], ['door_split_promoter', 'Promoter door (%)', 'number'], ['advance_ticket_price', 'Advance ticket ($)', 'number'], ['door_ticket_price', 'Door ticket ($)', 'number'], ['merch_venue_percent', 'Venue merch (%)', 'number']], false],
      ['Security & Production', [['security_count', '# Guards', 'number'], ['security_rate', 'Security rate ($/hr)', 'number'], ['security_paid_by', 'Security paid by', 'paidby'], ['sound_tech_included', 'Sound tech included', 'bool'], ['lighting_tech_included', 'Lighting tech included', 'bool']], false],
      ['Recurring / Residency', [['recurrence_rule', 'Recurrence', 'text'], ['term_start', 'Term start', 'date'], ['term_end', 'Term end', 'date'], ['trial_period_weeks', 'Trial (weeks)', 'number'], ['termination_notice_days', 'Termination notice (days)', 'number'], ['review_cadence', 'Review cadence', 'text']], false],
    ];
    const field = ([name, label, type]) => {
      const val = contract[name];
      if (type === 'bool') {
        const v = (val === null || val === undefined || val === '') ? '' : String(Number(val));
        return `<label>${esc(label)}<select name="${name}"><option value=""${v === '' ? ' selected' : ''}>—</option><option value="1"${v === '1' ? ' selected' : ''}>Yes</option><option value="0"${v === '0' ? ' selected' : ''}>No</option></select></label>`;
      }
      if (type === 'paidby') {
        return `<label>${esc(label)}<select name="${name}">${paidByOpts.map((o) => `<option value="${esc(o)}"${String(val || '') === o ? ' selected' : ''}>${o ? titleCase(o) : '—'}</option>`).join('')}</select></label>`;
      }
      return `<label>${esc(label)}<input type="${type}" name="${name}" value="${esc(val ?? '')}"${type === 'number' ? ' step="0.01"' : ''}></label>`;
    };
    const reqKeys = new Set();
    (this.data.sections || []).forEach((s) => (parseJson(s.required_fields_json, []) || []).forEach((k) => { if (!CONTRACT_DEAL_COLUMNS.includes(k)) reqKeys.add(k); }));
    Object.keys(vars).forEach((k) => reqKeys.add(k));
    const varKeys = [...reqKeys];
    const varHtml = varKeys.map((k) => `<label>${esc(titleCase(k))}<input name="var:${esc(k)}" value="${esc(vars[k] ?? '')}"></label>`).join('');
    return `<section class="panel">
      <div class="section-head padded"><h2>Deal terms</h2></div>
      <form data-form="deal" class="contract-deal-form">
        ${groups.map(([title, fields, open]) => `<details class="contract-fieldset"${open ? ' open' : ''}><summary>${esc(title)}</summary><div class="grid-form">${fields.map(field).join('')}</div></details>`).join('')}
        <details class="contract-fieldset"${varKeys.length ? ' open' : ''}><summary>Other variables</summary><div class="grid-form">${varHtml || '<p class="muted">No extra variables required by the current clauses.</p>'}</div><div class="contract-newvar grid-form"><label>New variable key <input data-newvar-key placeholder="e.g. ticket_platform"></label><label>Value <input data-newvar-val></label></div></details>
        <div class="padded contract-save-row"><button>Save deal terms</button> <span class="autosave-status muted small" data-autosave-status></span></div>
      </form>
    </section>`;
  }

  statusHtml() {
    const data = this.data;
    const contract = data.contract;
    const steps = ['draft', 'needs_review', 'approved', 'sent', 'signed'];
    const idx = steps.indexOf(contract.status);
    const actions = [];
    if (this.manage) {
      if (contract.status === 'draft') actions.push(['needs_review', 'Submit for review', 'secondary']);
      if (['draft', 'needs_review'].includes(contract.status) && data.capabilities.approve) actions.push(['approved', 'Approve', 'primary']);
      if (['approved', 'needs_review'].includes(contract.status)) actions.push(['sent', 'Mark sent', 'secondary']);
      if (contract.status === 'sent') actions.push(['signed', 'Mark signed', 'primary']);
      if (!['canceled', 'signed'].includes(contract.status)) actions.push(['canceled', 'Cancel', 'danger']);
    }
    return `<section class="panel padded">
      <h3 class="contract-h3">Status</h3>
      <div class="contract-status-track">${steps.map((s, i) => `<span class="contract-step ${idx >= i && idx >= 0 ? 'done' : ''} ${contract.status === s ? 'current' : ''}">${esc(titleCase(s))}</span>`).join('')}</div>
      <p class="muted small">Current: ${contractStatusBadge(contract.status)}${contract.sent_at ? ` · sent ${esc(String(contract.sent_at).slice(0, 10))}` : ''}${contract.signed_at ? ` · signed ${esc(String(contract.signed_at).slice(0, 10))}` : ''}</p>
      <div class="inline-actions">${actions.map(([s, label, cls]) => `<button class="small ${cls}" data-status="${s}">${esc(label)}</button>`).join('') || '<span class="muted small">No actions available.</span>'}</div>
    </section>`;
  }

  signingHtml() {
    const contract = this.data.contract;
    const status = contract.status;
    const signers = this.data.signers || [];
    const terminal = ['fully_executed', 'voided', 'canceled'].includes(status);
    const pending = signers.filter((s) => ['pending', 'sent', 'viewed'].includes(s.status));
    const countersignable = ['sent', 'viewed', 'partially_signed', 'signed_by_client', 'countersigned'].includes(status);
    const hasFinalPdf = Boolean(contract.final_pdf_path);
    const sentish = ['sent', 'viewed', 'partially_signed', 'signed_by_client', 'countersigned'].includes(status);

    const signerRow = (s) => {
      const when = s.signed_at ? ` · signed ${esc(String(s.signed_at).slice(0, 10))}`
        : s.viewed_at ? ` · viewed ${esc(String(s.viewed_at).slice(0, 10))}`
        : s.declined_at ? ` · declined ${esc(String(s.declined_at).slice(0, 10))}` : '';
      return `<li class="contract-signer">
        <div class="contract-signer-main"><strong>${esc(s.name || s.email || '—')}</strong> <span class="muted small">${esc(titleCase(s.role || ''))}</span></div>
        <div class="muted small">${esc(s.email || '')}${when}</div>
        <div>${contractStatusBadge(s.status)}</div>
      </li>`;
    };

    const actions = [];
    if (!terminal) actions.push(`<button class="small primary" data-act="sign-send">${signers.length ? 'Re-send for signature' : '✍ Send for signature'}</button>`);
    if (pending.length) actions.push('<button class="small secondary" data-act="sign-resend">Resend link</button>');
    if (countersignable) actions.push('<button class="small secondary" data-act="sign-countersign">Countersign</button>');
    if (hasFinalPdf) actions.push('<button class="small secondary" data-act="sign-download">⬇ Signed PDF</button>');
    if (!terminal && (signers.length || sentish)) actions.push('<button class="small danger" data-act="sign-void">Void</button>');

    return `<section class="panel padded" data-panel="signing">
      <div class="section-head"><h3 class="contract-h3">Signature</h3>${signers.length ? `<span class="muted small">${signers.length} signer${signers.length === 1 ? '' : 's'}</span>` : ''}</div>
      ${signers.length
        ? `<ul class="contract-signers">${signers.map(signerRow).join('')}</ul>`
        : '<p class="muted small">Not yet sent for signature. Send it to collect a legally-tracked electronic signature.</p>'}
      <div class="inline-actions">${actions.join('') || '<span class="muted small">No signature actions available in this state.</span>'}</div>
      <button class="linklike small" data-act="sign-audit">View audit log</button>
      <div data-audit-log hidden></div>
    </section>`;
  }

  warningsHtml() {
    const missing = this.data.missing || [];
    const risks = this.data.risk_flags || [];
    if (!missing.length && !risks.length) {
      return `<section class="panel padded contract-ok" data-panel="warnings"><h3 class="contract-h3">Checks</h3><p class="muted small">No missing required terms.${this.data.versions.length ? '' : ' Generate a version to enable sending.'}</p></section>`;
    }
    return `<section class="panel padded" data-panel="warnings">
      <h3 class="contract-h3">Review</h3>
      ${missing.length ? `<div class="contract-missing-list"><strong>Missing required terms</strong><ul>${missing.map((m) => `<li class="missing-field-link" data-field="${esc(m.key)}" title="Click to go to this field">${esc(m.label)} <span class="muted">(${esc(m.section)})</span></li>`).join('')}</ul></div>` : ''}
      ${risks.length ? `<div class="contract-risk-list"><strong>Risk warnings</strong><ul>${risks.map((r) => `<li>${riskBadge(r.level)} ${esc(r.message)}</li>`).join('')}</ul></div>` : ''}
    </section>`;
  }

  modulesHtml() {
    const data = this.data;
    const manage = this.manage;
    const sections = data.sections || [];
    const present = new Set(sections.map((s) => String(s.module_id || '')));
    const addable = (data.available_modules || []).filter((m) => !present.has(String(m.id)));
    return `<section class="panel">
      <div class="section-head padded"><h3 class="contract-h3">Clauses</h3>${manage ? '<button class="small secondary" data-act="reevaluate" title="Re-run smart selection against the current deal terms">Smart re-check</button>' : ''}</div>
      <ul class="contract-module-list${manage ? ' is-manage' : ''}">
      ${sections.map((s) => `<li class="${Number(s.included) ? '' : 'excluded'}"${manage ? ` data-sid="${s.id}" draggable="true"` : ''}>
        ${manage ? '<span class="contract-mod-drag" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span>' : ''}
        <label class="contract-mod-toggle">
          <input type="checkbox" data-toggle="${s.id}" ${Number(s.included) ? 'checked' : ''} ${(!manage || (Number(s.is_locked) && !data.capabilities.manage)) ? 'disabled' : ''}>
          <span>${esc(s.title)}</span>
        </label>
        <span class="contract-mod-tags">${riskBadge(s.risk_level)}${Number(s.is_locked) ? '<i class="fa-solid fa-lock" title="Locked clause"></i>' : ''}${Number(s.auto_selected) ? '<span class="auto-tag" title="Auto-selected by smart rules">auto</span>' : ''}</span>
        ${manage ? `<span class="contract-mod-actions">
          <button class="icon-btn" data-edit="${s.id}" title="Edit clause">✎</button>
        </span>` : ''}
      </li>`).join('')}
      </ul>
      ${manage ? `<div class="padded contract-add-row">
        <select data-add-module><option value="">Add a clause…</option>${addable.map((m) => `<option value="${esc(m.id)}">${esc(m.name)} (${esc(m.category)})</option>`).join('')}</select>
        <button class="small secondary" data-act="add-module">Add</button>
      </div>
      <div class="padded contract-add-row">
        <select data-apply-template><option value="">Rebuild from template…</option>${(data.templates || []).map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select>
        <button class="small secondary" data-act="apply-template">Apply</button>
      </div>` : ''}
    </section>`;
  }

  versionsHtml() {
    const versions = this.data.versions || [];
    return `<section class="panel padded"><h3 class="contract-h3">Version history</h3>
      ${versions.length ? `<ul class="contract-versions">${versions.map((v) => `<li><button class="linklike" data-version="${v.id}">v${esc(v.version_number)}</button> <span class="muted small">${esc(String(v.created_at || '').slice(0, 16).replace('T', ' '))}${v.created_by_name ? ` · ${esc(v.created_by_name)}` : ''}</span></li>`).join('')}</ul>` : '<p class="muted small">No versions generated yet.</p>'}
    </section>`;
  }

  bind() {
    const id = this.contractId;
    $('[data-act="pdf"]', this)?.addEventListener('click', () => {
      downloadContractPdf(this.contractId, this.data.contract.title || 'Contract');
    });
    $('[data-act="print"]', this)?.addEventListener('click', () => {
      printContractWindow(this.data.preview_html, this.data.contract.title || 'Contract');
    });
    $('[data-act="email-pdf"]', this)?.addEventListener('click', () => this.emailPdfModal());
    $('[data-act="sign-send"]', this)?.addEventListener('click', () => this.sendModal());
    $('[data-act="sign-resend"]', this)?.addEventListener('click', () => this.resendLinks());
    $('[data-act="sign-countersign"]', this)?.addEventListener('click', () => this.countersignModal());
    $('[data-act="sign-download"]', this)?.addEventListener('click', () => this.downloadSignedPdf());
    $('[data-act="sign-void"]', this)?.addEventListener('click', () => this.voidContract());
    $('[data-act="sign-audit"]', this)?.addEventListener('click', () => this.toggleAudit());
    $('[data-act="render"]', this)?.addEventListener('click', () => this.action(() => api(`/contracts/${id}/render`, { method: 'POST' }), 'Version generated.'));
    $('[data-act="reevaluate"]', this)?.addEventListener('click', () => this.action(() => api(`/contracts/${id}/reevaluate`, { method: 'POST' }), 'Smart selection refreshed.'));
    const dealForm = $('[data-form="deal"]', this);
    dealForm?.addEventListener('submit', (event) => { event.preventDefault(); this.saveDeal(event.target); });
    // Auto-save: on any field change, immediately update missing token spans in the
    // preview, then debounce a silent PATCH + scroll-preserving preview refresh.
    if (this.manage && dealForm) {
      dealForm.addEventListener('change', (e) => {
        if (e.target.matches('[data-newvar-key], [data-newvar-val]')) return;
        const { name } = e.target;
        if (name) {
          const key = name.startsWith('var:') ? name.slice(4) : name;
          this.applyTokenToPreview(key, e.target.value);
        }
        clearTimeout(this._dealSaveTimer);
        this._dealSaveTimer = setTimeout(() => this.saveDealSilent(dealForm), 800);
      });
    }
    $$('[data-status]', this).forEach((b) => b.addEventListener('click', () => this.changeStatus(b.dataset.status)));
    $$('[data-toggle]', this).forEach((cb) => cb.addEventListener('change', () => this.patchSections([{ id: Number(cb.dataset.toggle), included: cb.checked ? 1 : 0 }])));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.editSection(Number(b.dataset.edit))));

    // ── drag-and-drop clause reordering ──────────────────────────────────────
    if (this.manage) {
      const ul = $('.contract-module-list', this);
      if (ul) {
        let dragSrc = null;
        $$('li[data-sid]', ul).forEach((li) => {
          li.addEventListener('dragstart', (e) => {
            dragSrc = li;
            e.dataTransfer.effectAllowed = 'move';
            setTimeout(() => li.classList.add('dragging'), 0);
          });
          li.addEventListener('dragend', () => {
            li.classList.remove('dragging');
            dragSrc = null;
            $$('li', ul).forEach((x) => x.classList.remove('drag-over'));
          });
          li.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
          li.addEventListener('dragenter', (e) => { e.preventDefault(); if (li !== dragSrc) li.classList.add('drag-over'); });
          li.addEventListener('dragleave', (e) => { if (!li.contains(e.relatedTarget)) li.classList.remove('drag-over'); });
          li.addEventListener('drop', (e) => {
            e.preventDefault();
            li.classList.remove('drag-over');
            if (!dragSrc || dragSrc === li) return;
            const items = [...$$('li[data-sid]', ul)];
            const srcIdx = items.indexOf(dragSrc);
            const tgtIdx = items.indexOf(li);
            if (srcIdx < 0 || tgtIdx < 0) return;
            items.splice(srcIdx, 1);
            items.splice(tgtIdx, 0, dragSrc);
            const patches = items.map((el, newIdx) => ({ id: Number(el.dataset.sid), sort_order: newIdx + 1 }));
            this.patchSections(patches);
          });
        });
      }
    }
    $$('[data-version]', this).forEach((b) => b.addEventListener('click', () => this.viewVersion(Number(b.dataset.version))));
    $('[data-act="add-module"]', this)?.addEventListener('click', () => {
      const sel = $('[data-add-module]', this);
      if (sel.value) this.action(() => api(`/contracts/${id}/sections`, { method: 'POST', body: JSON.stringify({ module_id: Number(sel.value) }) }), 'Clause added.');
    });
    $('[data-act="apply-template"]', this)?.addEventListener('click', () => {
      const sel = $('[data-apply-template]', this);
      if (sel.value && confirm('Rebuild clauses from this template? Custom edits to clauses on this contract will be lost.')) {
        this.action(() => api(`/contracts/${id}/apply-template`, { method: 'POST', body: JSON.stringify({ template_id: Number(sel.value) }) }), 'Template applied.');
      }
    });

    // ── token click: missing token span → focus its deal form field ───────────
    $$('[data-token]', this).forEach((span) => {
      span.addEventListener('click', () => this.focusDealField(span.dataset.token));
    });

    // ── review click: missing required term → focus its deal form field ───────
    $$('[data-field]', this).forEach((li) => {
      li.addEventListener('click', () => this.focusDealField(li.dataset.field));
    });

    // ── contenteditable: section bodies (managers only) ───────────────────────
    if (this.manage) {
      $$('.contract-section-body', this).forEach((div) => {
        div.contentEditable = 'true';
        div.setAttribute('spellcheck', 'true');
        div.addEventListener('input', () => { div.dataset.dirty = '1'; });
        div.addEventListener('blur', () => {
          if (!div.dataset.dirty) return;
          const sid = Number(div.closest('[data-section-id]')?.dataset?.sectionId ?? 0);
          if (sid) this.saveSectionBody(sid, div);
        });
        // Prevent Enter from inserting <div> wrappers; use <p> behavior instead
        div.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.execCommand('insertParagraph');
          }
        });
      });
    }
  }

  async action(fn, message) {
    try { await fn(); publish('toast.show', { message }); await this.load(); }
    catch (error) { publish('toast.show', { message: error.message, tone: 'error' }); }
  }

  async patchSections(rows) {
    try { await api(`/contracts/${this.contractId}/sections`, { method: 'PATCH', body: JSON.stringify({ sections: rows }) }); await this.load(); }
    catch (error) { publish('toast.show', { message: error.message, tone: 'error' }); }
  }

  reorder(sectionId, dir) {
    const list = this.data.sections;
    const idx = list.findIndex((x) => Number(x.id) === sectionId);
    const j = idx + dir;
    if (idx < 0 || j < 0 || j >= list.length) return;
    const a = list[idx];
    const b = list[j];
    this.patchSections([{ id: Number(a.id), sort_order: Number(b.sort_order) }, { id: Number(b.id), sort_order: Number(a.sort_order) }]);
  }

  async removeSection(sectionId) {
    if (!confirm('Remove this clause from the contract?')) return;
    try { await api(`/contracts/${this.contractId}/sections/${sectionId}`, { method: 'DELETE' }); publish('toast.show', { message: 'Clause removed.' }); await this.load(); }
    catch (error) { publish('toast.show', { message: error.message, tone: 'error' }); }
  }

  async changeStatus(status) {
    try {
      await api(`/contracts/${this.contractId}/status`, { method: 'POST', body: JSON.stringify({ status }) });
      publish('toast.show', { message: `Marked ${titleCase(status)}.` });
      await this.load();
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
    }
  }

  // ── digital signature / email ─────────────────────────────────────────────

  /** Small modal helper: append a modal-backdrop, wire close, return the dialog element. */
  openModal(innerHtml) {
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">${innerHtml}</div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog)?.addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    return { dialog, close };
  }

  emailPdfModal() {
    const c = this.data.contract;
    const { dialog, close } = this.openModal(`<div class="section-head padded"><h2>Email contract PDF</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="email-pdf">
        <label class="wide">Recipient email <input type="email" name="email" required value="${esc(c.counterparty_email || '')}" placeholder="name@example.com"></label>
        <label class="wide">Message <span class="muted small">(optional)</span><textarea name="message" rows="4" placeholder="Add a short note to include in the email…"></textarea></label>
        <p class="muted small">The current contract PDF is generated and attached automatically.</p>
        <button>Send PDF</button>
      </form>`);
    $('[data-form="email-pdf"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = formData(e.target);
      const btn = $('button', e.target);
      btn.disabled = true;
      try {
        await api(`/contracts/${this.contractId}/email-pdf`, { method: 'POST', body: JSON.stringify({ email: fd.email, message: fd.message }) });
        close();
        publish('toast.show', { message: `PDF emailed to ${fd.email}.` });
      } catch (error) {
        btn.disabled = false;
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  sendModal() {
    const c = this.data.contract;
    const already = (this.data.signers || []).length > 0;
    const { dialog, close } = this.openModal(`<div class="section-head padded"><h2>Send for signature</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="sign-send">
        ${already ? '<p class="muted small wide">This voids any outstanding signing links and sends a fresh request.</p>' : ''}
        <label class="wide">Signer name <input name="name" value="${esc(c.counterparty_name || '')}" placeholder="Full name"></label>
        <label class="wide">Signer email <input type="email" name="email" required value="${esc(c.counterparty_email || '')}" placeholder="name@example.com"></label>
        <label class="wide">Company <span class="muted small">(optional)</span><input name="company" value="${esc(c.counterparty_org || '')}"></label>
        <p class="muted small">The signer receives an email with a secure link to review and sign electronically.</p>
        <button>Send signing request</button>
      </form>`);
    $('[data-form="sign-send"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = formData(e.target);
      const btn = $('button', e.target);
      btn.disabled = true;
      try {
        await api(`/contracts/${this.contractId}/send`, {
          method: 'POST',
          body: JSON.stringify({ signers: [{ role: 'renter', name: fd.name, email: fd.email, company: fd.company }] }),
        });
        close();
        publish('toast.show', { message: 'Signing request sent.' });
        await this.load();
      } catch (error) {
        btn.disabled = false;
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  resendLinks() {
    this.action(() => api(`/contracts/${this.contractId}/resend`, { method: 'POST' }), 'Signing link resent.');
  }

  countersignModal() {
    const { dialog, close } = this.openModal(`<div class="section-head padded"><h2>Countersign contract</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="countersign">
        <label class="wide">Your name <input name="name" required placeholder="Signer name"></label>
        <label class="wide">Title <span class="muted small">(optional)</span><input name="title" placeholder="e.g. Owner"></label>
        <label class="wide">Signature <input name="signature_text" placeholder="Type your name as signature"></label>
        <p class="muted small">Recording the venue's countersignature. When all parties have signed, the final signed PDF is generated automatically.</p>
        <button>Countersign</button>
      </form>`);
    $('[data-form="countersign"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = formData(e.target);
      const btn = $('button', e.target);
      btn.disabled = true;
      try {
        await api(`/contracts/${this.contractId}/countersign`, { method: 'POST', body: JSON.stringify(fd) });
        close();
        publish('toast.show', { message: 'Contract countersigned.' });
        await this.load();
      } catch (error) {
        btn.disabled = false;
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  async downloadSignedPdf() {
    publish('toast.show', { message: 'Fetching signed PDF…' });
    try {
      await downloadAuthedPdf(`contracts/${this.contractId}/download`, pdfFilename(this.data.contract.title, '-signed'));
      publish('toast.show', { message: 'Signed PDF downloaded.' });
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
    }
  }

  voidContract() {
    const reason = prompt('Void this contract? This invalidates all pending signing links. Optionally add a reason:');
    if (reason === null) return; // cancelled
    this.action(() => api(`/contracts/${this.contractId}/void`, { method: 'POST', body: JSON.stringify({ reason }) }), 'Contract voided.');
  }

  async toggleAudit() {
    const box = $('[data-audit-log]', this);
    if (!box) return;
    if (!box.hidden) { box.hidden = true; box.innerHTML = ''; return; }
    box.hidden = false;
    box.innerHTML = '<p class="muted small">Loading…</p>';
    try {
      const res = await api(`/contracts/${this.contractId}/audit`);
      const rows = res.audit_log || [];
      box.innerHTML = rows.length
        ? `<ul class="contract-audit">${rows.map((r) => `<li><span class="muted small">${esc(String(r.created_at || '').slice(0, 16).replace('T', ' '))}</span> ${esc(titleCase(r.action || ''))}${r.signer_name ? ` · ${esc(r.signer_name)}` : ''}${r.ip_address ? ` <span class="muted small">(${esc(r.ip_address)})</span>` : ''}</li>`).join('')}</ul>`
        : '<p class="muted small">No audit entries yet.</p>';
    } catch (error) {
      box.innerHTML = `<p class="error-text small">${esc(error.message)}</p>`;
    }
  }

  /** Extract the PATCH body from the deal form. Shared by explicit save and auto-save. */
  buildDealBody(form) {
    const body = { variables: {} };
    form.querySelectorAll('[name]').forEach((el) => {
      const name = el.name;
      if (name.startsWith('var:')) { const key = name.slice(4); if (el.value !== '') body.variables[key] = el.value; }
      else { body[name] = el.value; }
    });
    const nk = form.querySelector('[data-newvar-key]')?.value?.trim();
    const nv = form.querySelector('[data-newvar-val]')?.value;
    if (nk) body.variables[nk] = nv ?? '';
    return body;
  }

  /** Immediately swap any matching missing-token spans in the preview with the new value. */
  applyTokenToPreview(key, value) {
    const preview = $('[data-preview]', this);
    if (!preview) return;
    preview.querySelectorAll(`.contract-token-missing[data-token]`).forEach((span) => {
      if (span.dataset.token === key && value && value.trim()) {
        span.replaceWith(document.createTextNode(value));
      }
    });
  }

  /** Auto-save triggered by field changes: save silently then do a scroll-preserving preview refresh. */
  async saveDealSilent(form) {
    const body = this.buildDealBody(form);
    const statusEl = $('[data-autosave-status]', this);
    if (statusEl) statusEl.textContent = 'Saving…';
    try {
      await api(`/contracts/${this.contractId}`, { method: 'PATCH', body: JSON.stringify(body) });
      if (statusEl) {
        statusEl.textContent = 'Saved ✓';
        setTimeout(() => { statusEl.textContent = ''; }, 2500);
      }
      await this.refreshPreviewOnly();
    } catch (error) {
      if (statusEl) statusEl.textContent = '';
      publish('toast.show', { message: error.message, tone: 'error' });
    }
  }

  /** Fetch updated contract data and replace only the preview div + warnings panel (scroll preserved). */
  async refreshPreviewOnly() {
    const previewEl = $('[data-preview]', this);
    const scrollTop = previewEl?.scrollTop ?? 0;
    try {
      const updated = await api(`/contracts/${this.contractId}`);
      this.data = updated;
      // Refresh preview HTML, restoring scroll position
      if (previewEl) {
        previewEl.innerHTML = updated.preview_html;
        requestAnimationFrame(() => { previewEl.scrollTop = scrollTop; });
      }
      // Re-bind token click → deal field for newly rendered preview tokens
      $$('[data-token]', previewEl).forEach((span) => {
        span.addEventListener('click', () => this.focusDealField(span.dataset.token));
      });
      // Refresh warnings panel (missing fields / risk flags may have changed)
      const oldWarnings = $('[data-panel="warnings"]', this);
      if (oldWarnings) {
        const tmp = document.createElement('div');
        tmp.innerHTML = this.warningsHtml();
        const newWarnings = tmp.firstElementChild;
        oldWarnings.replaceWith(newWarnings);
        $$('[data-field]', newWarnings).forEach((li) => {
          li.addEventListener('click', () => this.focusDealField(li.dataset.field));
        });
      }
    } catch {
      // Silently swallow refresh errors — data was already saved
    }
  }

  saveDeal(form) {
    this.action(() => api(`/contracts/${this.contractId}`, { method: 'PATCH', body: JSON.stringify(this.buildDealBody(form)) }), 'Deal terms saved.');
  }

  /** Find the deal-form field for `key`, open its <details> group, scroll to it, and flash it. */
  focusDealField(key) {
    const form = $('[data-form="deal"]', this);
    if (!form) return;
    // Deal columns use name="key"; custom variables use name="var:key"
    const field = form.querySelector(`[name="${CSS.escape(key)}"]`)
               ?? form.querySelector(`[name="var:${CSS.escape(key)}"]`);
    if (!field) {
      // Variable not in the form yet — open the Other variables section and hint
      const otherDetails = $$('details.contract-fieldset', form)
        .find((d) => $('summary', d)?.textContent?.trim().startsWith('Other'));
      if (otherDetails) {
        otherDetails.open = true;
        otherDetails.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      publish('toast.show', { message: `Add "${key}" in the Other variables section.` });
      return;
    }
    field.closest('details.contract-fieldset')?.setAttribute('open', '');
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
    requestAnimationFrame(() => {
      field.focus();
      field.select?.();
      field.classList.add('field-highlight');
      setTimeout(() => field.classList.remove('field-highlight'), 1400);
    });
  }

  /** Save an inline-edited section body back to the server as its new body_template. */
  async saveSectionBody(sectionId, divEl) {
    const clone = divEl.cloneNode(true);
    // Restore {{token}} placeholders for any missing-token spans still in the text
    clone.querySelectorAll('.contract-token-missing[data-token]').forEach((span) => {
      span.replaceWith(`{{${span.dataset.token}}}`);
    });
    // Extract paragraphs; replace <br> within each with \n
    const ps = [...clone.querySelectorAll('p')];
    if (ps.length) {
      ps.forEach((p) => [...p.querySelectorAll('br')].forEach((br) => br.replaceWith('\n')));
    }
    const bodyTemplate = (ps.length
      ? ps.map((p) => p.textContent).join('\n\n')
      : clone.textContent
    ).trim();
    try {
      await api(`/contracts/${this.contractId}/sections`, {
        method: 'PATCH',
        body: JSON.stringify({ sections: [{ id: sectionId, body_template: bodyTemplate }] }),
      });
      delete divEl.dataset.dirty;
      publish('toast.show', { message: 'Section saved.' });
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
    }
  }

  editSection(sectionId) {
    const section = (this.data.sections || []).find((x) => Number(x.id) === sectionId);
    if (!section) return;
    const canDelete = !(Number(section.is_locked) && !this.data.capabilities.manage);
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide-modal"><div class="section-head padded"><h2>Edit clause</h2><div class="section-head-actions">${canDelete ? '<button class="small danger" data-delete>Remove clause</button>' : ''}<button class="small secondary" data-close>Close</button></div></div>
      <form class="grid-form padded" data-form="edit">
        <label class="wide">Title <input name="title" value="${esc(section.title)}"></label>
        <label class="wide">Body <textarea name="body_template" rows="12">${esc(section.body_template)}</textarea></label>
        <p class="muted small">Use <code>{{variable}}</code> tokens — e.g. {{rental_fee}}, {{recurrence_rule}}, {{counterparty_display}}.</p>
        <button>Save clause</button>
      </form></div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-delete]', dialog)?.addEventListener('click', () => { close(); this.removeSection(sectionId); });
    $('[data-form="edit"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = formData(e.target);
      try {
        await api(`/contracts/${this.contractId}/sections`, { method: 'PATCH', body: JSON.stringify({ sections: [{ id: sectionId, title: fd.title, body_template: fd.body_template }] }) });
        close();
        publish('toast.show', { message: 'Clause updated.' });
        await this.load();
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  async viewVersion(versionId) {
    try {
      const result = await api(`/contracts/${this.contractId}/versions/${versionId}`);
      const win = window.open('', '_blank', 'width=900,height=1100');
      if (!win) { publish('toast.show', { message: 'Pop-up blocked — allow pop-ups to view versions.' }); return; }
      win.document.open();
      win.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${esc(this.data.contract.title)} v${esc(result.version.version_number)}</title><style>${CONTRACT_DOC_CSS}</style></head><body>${result.version.rendered_html}</body></html>`);
      win.document.close();
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
    }
  }
}


// Admin → Contracts: all contracts + clause library + template management.
class AdminContracts extends PanicElement {
  connect() { this.tab = 'list'; this.load(); }

  async load() {
    this.setLoading('Loading contracts');
    try {
      if (this.tab === 'list') this.payload = await api('/contracts');
      else if (this.tab === 'modules') this.payload = await api('/contract-modules');
      else this.payload = await api('/contract-templates');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const tabs = [['list', 'All Contracts'], ['modules', 'Clause Library'], ['templates', 'Templates']];
    this.innerHTML = `<nav class="workspace-tabs tabs">${tabs.map(([k, l]) => `<a href="#admin-contracts" data-subtab="${k}" class="${this.tab === k ? 'active' : ''}">${esc(l)}</a>`).join('')}</nav><div data-sub></div>`;
    $$('[data-subtab]', this).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); this.tab = a.dataset.subtab; this.load(); }));
    const sub = $('[data-sub]', this);
    if (this.tab === 'list') this.renderList(sub);
    else if (this.tab === 'modules') this.renderModules(sub);
    else this.renderTemplates(sub);
  }

  renderList(root) {
    const data = this.payload;
    const contracts = data.contracts || [];
    root.innerHTML = `<article class="panel">
      <div class="section-head padded"><h2>All Contracts</h2><span class="muted">${contracts.length} total</span></div>
      <table class="data-table admin-table"><thead><tr><th>Title</th><th>Type</th><th>For</th><th>Counterparty</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>${contracts.map((c) => `<tr>
        <td><a href="#contract-${esc(c.id)}">${esc(c.title)}</a></td>
        <td>${esc(titleCase(c.contract_type))}</td>
        <td>${c.event_title ? `<a href="#event-${esc(c.event_id)}">${esc(c.event_title)}</a>` : esc(c.venue_name || '—')}</td>
        <td>${esc(c.counterparty_name || '—')}</td>
        <td>${contractStatusBadge(c.status)}</td>
        <td class="muted">${esc((c.updated_at || '').slice(0, 10))}</td>
      </tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">No contracts yet.</div></td></tr>'}</tbody></table>
    </article>
    ${data.can_create_standalone ? `<article class="panel"><div class="section-head padded"><h2>New venue-level contract</h2></div>
      <form class="grid-form padded" data-form="new">
        <label>Title <input name="title" placeholder="e.g. Thursday Swing Residency"></label>
        <label>Venue <select name="venue_id"><option value="">—</option>${(data.venues || []).map((v) => `<option value="${esc(v.id)}">${esc(v.name)}</option>`).join('')}</select></label>
        <label>Template <select name="template_id" required><option value="">Choose…</option>${(data.templates || []).map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label>
        <label>Counterparty <input name="counterparty_name"></label>
        <button>Create contract</button>
      </form>
      <p class="muted padded small">Venue-level contracts aren't tied to a single event — use them for recurring residencies (e.g. a weekly swing night).</p></article>` : ''}`;
    if (data.can_create_standalone) {
      $('[data-form="new"]', root).addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
          const result = await api('/contracts', { method: 'POST', body: JSON.stringify(formData(event.target)) });
          location.hash = `#contract-${result.id}`;
        } catch (error) {
          publish('toast.show', { message: error.message, tone: 'error' });
        }
      });
    }
  }

  renderModules(root) {
    const modules = this.payload.modules || [];
    root.innerHTML = `<article class="panel">
      <div class="section-head padded"><h2>Clause Library</h2><div class="section-head-actions"><span class="muted">${modules.length} clauses</span><button class="small" data-new>+ New clause</button></div></div>
      <table class="data-table admin-table"><thead><tr><th>Name</th><th>Key</th><th>Category</th><th>Risk</th><th>Flags</th><th></th></tr></thead>
      <tbody>${modules.map((m) => `<tr class="${Number(m.is_active) ? '' : 'muted-row'}">
        <td><strong>${esc(m.name)}</strong></td><td><code>${esc(m.module_key)}</code></td>
        <td>${esc(titleCase(m.category))}</td><td>${riskBadge(m.risk_level) || '—'}</td>
        <td>${Number(m.is_locked) ? '<i class="fa-solid fa-lock" title="Locked"></i> ' : ''}${Number(m.is_active) ? '' : '<span class="muted">inactive</span>'}</td>
        <td class="row-actions"><button class="small secondary" data-edit="${esc(m.id)}">Edit</button><button class="small danger" data-del="${esc(m.id)}" data-name="${esc(m.name)}">Delete</button></td>
      </tr>`).join('')}</tbody></table></article>`;
    $('[data-new]', root).addEventListener('click', () => this.moduleModal(null));
    $$('[data-edit]', root).forEach((b) => b.addEventListener('click', () => this.moduleModal(modules.find((m) => String(m.id) === b.dataset.edit))));
    $$('[data-del]', root).forEach((b) => b.addEventListener('click', () => this.deleteRow('/contract-modules', b.dataset.del, b.dataset.name)));
  }

  moduleModal(module) {
    const data = this.payload;
    const m = module || {};
    const isEdit = Boolean(module && module.id);
    const cats = data.categories || ['base', 'financial', 'operational', 'legal', 'risk'];
    const risks = data.risk_levels || ['none', 'low', 'medium', 'high'];
    const req = parseJson(m.required_fields_json, []) || [];
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide-modal"><div class="section-head padded"><h2>${isEdit ? 'Edit clause' : 'New clause'}</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="mod">
        <label>Name <input name="name" required value="${esc(m.name || '')}"></label>
        <label>Key <input name="module_key" value="${esc(m.module_key || '')}" placeholder="auto from name"></label>
        <label>Category ${select('category', cats, m.category || 'operational')}</label>
        <label>Risk ${select('risk_level', risks, m.risk_level || 'none')}</label>
        <label class="wide">Required variables <input name="required_fields" value="${esc(req.join(', '))}" placeholder="comma-separated keys, e.g. rental_fee, deposit_amount"></label>
        <label class="checkbox-row"><input type="checkbox" name="is_locked" ${Number(m.is_locked) ? 'checked' : ''}> Locked (only admins can edit/remove on a contract)</label>
        <label class="checkbox-row"><input type="checkbox" name="is_active" ${(!isEdit || Number(m.is_active)) ? 'checked' : ''}> Active</label>
        <label class="wide">Body <textarea name="body_template" rows="10">${esc(m.body_template || '')}</textarea></label>
        <button>Save clause</button>
      </form></div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="mod"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = formData(e.target);
      fd.is_locked = $('[name="is_locked"]', dialog).checked ? 1 : 0;
      fd.is_active = $('[name="is_active"]', dialog).checked ? 1 : 0;
      try {
        await api(isEdit ? `/contract-modules/${module.id}` : '/contract-modules', { method: isEdit ? 'PATCH' : 'POST', body: JSON.stringify(fd) });
        close();
        publish('toast.show', { message: 'Clause saved.' });
        this.load();
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  renderTemplates(root) {
    const templates = this.payload.templates || [];
    root.innerHTML = `<article class="panel"><div class="section-head padded"><h2>Templates</h2><div class="section-head-actions"><span class="muted">${templates.length} templates</span><button class="small" data-new>+ New template</button></div></div>
      <table class="data-table admin-table"><thead><tr><th>Name</th><th>Type</th><th>Clauses</th><th>Active</th><th></th></tr></thead>
      <tbody>${templates.map((t) => `<tr class="${Number(t.is_active) ? '' : 'muted-row'}"><td><strong>${esc(t.name)}</strong>${t.description ? `<br><small class="muted">${esc(t.description)}</small>` : ''}</td><td>${esc(titleCase(t.contract_type))}</td><td>${esc(t.module_count)}</td><td>${Number(t.is_active) ? 'Yes' : 'No'}</td><td class="row-actions"><button class="small secondary" data-edit="${esc(t.id)}">Edit</button><button class="small danger" data-del="${esc(t.id)}" data-name="${esc(t.name)}">Delete</button></td></tr>`).join('')}</tbody></table></article>`;
    $('[data-new]', root).addEventListener('click', () => this.templateModal(null));
    $$('[data-edit]', root).forEach((b) => b.addEventListener('click', () => this.templateModal(Number(b.dataset.edit))));
    $$('[data-del]', root).forEach((b) => b.addEventListener('click', () => this.deleteRow('/contract-templates', b.dataset.del, b.dataset.name)));
  }

  async templateModal(id) {
    let template = { modules: [] };
    let allModules = this.payload.modules || [];
    let types = this.payload.types || [];
    if (id) {
      const result = await api(`/contract-templates/${id}`);
      template = result.template;
      allModules = result.modules;
      types = result.types;
    }
    const wiring = {};
    (template.modules || []).forEach((w, i) => { wiring[w.module_id] = { is_required: Number(w.is_required), condition: w.condition_json ? JSON.stringify(w.condition_json) : '', order: w.sort_order ?? i }; });
    const ordered = [...allModules].sort((a, b) => {
      const wa = wiring[a.id];
      const wb = wiring[b.id];
      if (wa && wb) return wa.order - wb.order;
      if (wa) return -1;
      if (wb) return 1;
      return 0;
    });
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide-modal"><div class="section-head padded"><h2>${id ? 'Edit template' : 'New template'}</h2><button class="small secondary" data-close>Close</button></div>
      <form data-form="tpl" class="padded">
        <div class="grid-form">
          <label>Name <input name="name" required value="${esc(template.name || '')}"></label>
          <label>Type ${select('contract_type', types, template.contract_type || 'other')}</label>
          <label class="wide">Description <input name="description" value="${esc(template.description || '')}"></label>
          <label class="checkbox-row"><input type="checkbox" name="is_active" ${(!id || Number(template.is_active)) ? 'checked' : ''}> Active</label>
        </div>
        <h3 class="contract-h3">Clauses &amp; smart conditions</h3>
        <p class="muted small">Check a clause to include it. <strong>Required</strong> = always included. A <strong>condition</strong> auto-includes the clause only when it matches the deal — e.g. <code>{"all":[{"field":"age_policy","op":"eq","value":"all_ages"}]}</code> or <code>{"any":[{"field":"expected_attendance","op":"gte","value":200}]}</code>.</p>
        <table class="data-table contract-wiring"><thead><tr><th>Use</th><th>Clause</th><th>Required</th><th>Condition (JSON, optional)</th></tr></thead><tbody>
        ${ordered.map((m) => { const w = wiring[m.id]; return `<tr data-module="${esc(m.id)}"><td><input type="checkbox" data-inc ${w ? 'checked' : ''}></td><td>${esc(m.name)} <span class="muted small">${esc(m.category)}</span></td><td><input type="checkbox" data-req ${w && w.is_required ? 'checked' : ''}></td><td><input data-cond value="${esc(w ? w.condition : '')}" placeholder="(always, when checked)"></td></tr>`; }).join('')}
        </tbody></table>
        <button>Save template</button>
      </form></div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="tpl"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = {
        name: $('[name="name"]', dialog).value,
        contract_type: $('[name="contract_type"]', dialog).value,
        description: $('[name="description"]', dialog).value,
        is_active: $('[name="is_active"]', dialog).checked ? 1 : 0,
        modules: [],
      };
      let order = 0;
      $$('tr[data-module]', dialog).forEach((tr) => {
        if (!$('[data-inc]', tr).checked) return;
        const condRaw = $('[data-cond]', tr).value.trim();
        let condition = null;
        if (condRaw) { try { condition = JSON.parse(condRaw); } catch { condition = null; } }
        body.modules.push({ module_id: Number(tr.dataset.module), is_required: $('[data-req]', tr).checked ? 1 : 0, condition_json: condition, sort_order: order++ });
      });
      try {
        await api(id ? `/contract-templates/${id}` : '/contract-templates', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
        close();
        publish('toast.show', { message: 'Template saved.' });
        this.load();
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
      }
    });
  }

  async deleteRow(base, id, name) {
    if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
    try { await api(`${base}/${id}`, { method: 'DELETE' }); publish('toast.show', { message: 'Deleted.' }); this.load(); }
    catch (error) { publish('toast.show', { message: error.message, tone: 'error' }); }
  }
}
customElements.define('pb-event-contracts', EventContracts);
customElements.define('pb-contract-editor', ContractEditor);
customElements.define('pb-admin-contracts', AdminContracts);
