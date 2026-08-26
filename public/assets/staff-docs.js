import { esc, api, publish, badge, emptyState, PanicElement, $, $$ } from './core.js';
import { PRINT_CSS } from './print.js';

// ── Staff Handbook & Compliance ──────────────────────────────────────────────
// Three pages sharing one file:
//   pb-staff-docs-page        list of handbook/policy/SOP documents (route: staff-docs)
//   pb-staff-doc-reader       one document, rendered + TOC + acknowledge (route: staff-docs-<slug>)
//   pb-staff-compliance-page  admin matrix: staff x documents x certifications (route: staff-compliance)
//
// Content is authored as Markdown under docs/staff/** and rendered
// server-side (Panic\Markdown, see src/StaffDocs.php) — the HTML this page
// injects is already-sanitized, staff-authored content, not user input.

const TYPE_LABELS = { handbook: 'Handbook', policy: 'Policy', sop: 'SOP' };

function fmtDate(raw) {
  if (!raw) return '—';
  const d = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return raw;
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function fmtDateTime(raw) {
  if (!raw) return '—';
  const d = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return raw;
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function docStatusPill(doc) {
  if (doc.needs_acknowledgment) return '<span class="badge status-needs-ack">Action required</span>';
  if (doc.requires_acknowledgment && doc.acknowledged_at) return '<span class="badge status-confirmed">Acknowledged</span>';
  return '';
}

function openStaffDocPrintWindow(document_, version, html) {
  const win = window.open('', '_blank', 'width=900,height=1100');
  if (!win) {
    publish('toast.show', { message: 'Pop-up blocked — allow pop-ups to print.' });
    return;
  }
  const extraCss = `
    .doc-cover { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; }
    .doc-cover h1 { font-size: 20pt; margin: 0 0 6px; }
    .doc-cover .doc-meta { font-size: 9.5pt; color: #444; display: flex; gap: 18px; flex-wrap: wrap; }
    .doc-body h1 { font-size: 16pt; margin: 22px 0 8px; }
    .doc-body h2 { font-size: 13.5pt; margin: 18px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
    .doc-body h3 { font-size: 11.5pt; margin: 14px 0 6px; }
    .doc-body p, .doc-body li { font-size: 10pt; line-height: 1.5; }
    .doc-body table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin: 10px 0; }
    .doc-body th, .doc-body td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
    .doc-body blockquote { border-left: 3px solid #c0392b; margin: 10px 0; padding: 6px 12px; background: #fbeeee; font-size: 9.5pt; }
    .doc-body a { color: #111; }
    .heading-anchor { display: none; }
  `;
  win.document.open();
  win.document.write(`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${esc(document_.title)} — v${esc(version.version || '')}</title>
<style>${PRINT_CSS}${extraCss}</style>
</head>
<body>
  <div class="print-toolbar">
    <button type="button" onclick="window.print()" class="primary">Print</button>
    <button type="button" onclick="window.close()">Close</button>
  </div>
  <article class="sheet">
    <div class="doc-cover">
      <h1>${esc(document_.title)}</h1>
      <div class="doc-meta">
        <span>Version ${esc(version.version || '—')}</span>
        <span>Status: ${esc(document_.status)}</span>
        <span>Effective: ${esc(fmtDate(version.effective_date))}</span>
        <span>Published: ${esc(fmtDate(version.published_at))}</span>
      </div>
    </div>
    <div class="doc-body">${html}</div>
  </article>
</body>
</html>`);
  win.document.close();
}

// ── List page ────────────────────────────────────────────────────────────────

class StaffDocsPage extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Staff Handbook & Compliance', blurb: 'Policies, SOPs, and what you need to read and acknowledge for your role.' });
    this.setLoading('Loading documents');
    try {
      const data = await api('/staff-docs');
      this.data = data;
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const docs = this.data.documents || [];
    const groups = ['handbook', 'policy', 'sop'].map((type) => ({
      type,
      items: docs.filter((d) => d.document_type === type),
    })).filter((g) => g.items.length);

    const actionNeeded = docs.filter((d) => d.needs_acknowledgment);

    const banner = actionNeeded.length ? `
      <div class="panel padded" style="border-left:4px solid #c0392b;">
        <strong>${actionNeeded.length} document${actionNeeded.length === 1 ? '' : 's'} need${actionNeeded.length === 1 ? 's' : ''} your acknowledgment:</strong>
        <ul style="margin:8px 0 0 20px;">
          ${actionNeeded.map((d) => `<li><a href="#staff-docs-${esc(d.slug)}">${esc(d.title)}</a></li>`).join('')}
        </ul>
      </div>` : '';

    const groupHtml = groups.map((g) => `
      <section class="panel">
        <div class="section-head"><h3>${esc(TYPE_LABELS[g.type] || g.type)}</h3></div>
        <div class="panel-body">
          <table class="data-table">
            <thead><tr><th>Document</th><th>Version</th><th>Status</th></tr></thead>
            <tbody>
              ${g.items.map((d) => `
                <tr>
                  <td><a href="#staff-docs-${esc(d.slug)}">${esc(d.title)}</a></td>
                  <td>${esc(d.current_version || '—')}</td>
                  <td>${docStatusPill(d) || (d.requires_acknowledgment ? '' : '<span class="badge status-empty">Reference</span>')}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </section>`).join('');

    const adminLink = this.data.is_admin
      ? `<a class="button secondary" href="#staff-compliance"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Compliance overview</a>`
      : '';

    this.innerHTML = `
      <section class="page-head">
        <h2>Staff Handbook &amp; Compliance</h2>
        ${adminLink}
      </section>
      ${banner}
      ${groupHtml || emptyState('No published documents yet.')}
    `;
  }
}
customElements.define('pb-staff-docs-page', StaffDocsPage);

// ── Reader ───────────────────────────────────────────────────────────────────

class StaffDocReader extends PanicElement {
  set slug(value) {
    this._slug = value;
    if (this.isConnected) this.load();
  }

  connect() {
    this.load();
  }

  async load() {
    if (!this._slug) return;
    this.setLoading('Loading document');
    try {
      const [doc, list] = await Promise.all([
        api(`/staff-docs/${encodeURIComponent(this._slug)}`),
        api('/staff-docs'),
      ]);
      this.doc = doc;
      this.siblingList = (list.documents || []).filter((d) => d.document_type === doc.document.document_type);
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const { document: meta, version, html, toc, acknowledgment } = this.doc;
    publish('page.context', { title: meta.title, blurb: `${TYPE_LABELS[meta.document_type] || meta.document_type} · v${meta.current_version || '—'}` });

    const idx = this.siblingList.findIndex((d) => d.slug === meta.slug);
    const prev = idx > 0 ? this.siblingList[idx - 1] : null;
    const next = idx >= 0 && idx < this.siblingList.length - 1 ? this.siblingList[idx + 1] : null;

    const tocHtml = (toc || []).map((h) => `<li class="toc-l${h.level}"><a data-toc href="#sec-${esc(h.id)}">${esc(h.text)}</a></li>`).join('');

    let ackBanner = '';
    if (meta.requires_acknowledgment) {
      ackBanner = acknowledgment
        ? `<div class="panel padded" style="border-left:4px solid #2e7d32;">
             <i class="fa-solid fa-circle-check" aria-hidden="true" style="color:#2e7d32;"></i>
             You acknowledged version ${esc(acknowledgment.version)} on ${esc(fmtDateTime(acknowledgment.acknowledged_at))}.
           </div>`
        : `<div class="panel padded" style="border-left:4px solid #c0392b;">
             <strong>ACTION REQUIRED</strong> — this document requires your acknowledgment. Read it, then use the
             acknowledgment control at the bottom of the page.
           </div>`;
    }

    const ackControl = meta.requires_acknowledgment ? `
      <section class="panel padded" id="acknowledge-block">
        ${acknowledgment ? `
          <p><i class="fa-solid fa-circle-check" aria-hidden="true" style="color:#2e7d32;"></i>
             Acknowledged — version ${esc(acknowledgment.version)} on ${esc(fmtDateTime(acknowledgment.acknowledged_at))}.</p>
        ` : `
          <p>I acknowledge that I have received and reviewed this document and understand that I am responsible for
             following the policies and procedures applicable to my role.</p>
          <button type="button" class="button" data-acknowledge>Read and Acknowledge — v${esc(meta.current_version || '')}</button>
        `}
      </section>` : '';

    this.innerHTML = `
      <section class="page-head">
        <div>
          <a class="button secondary" href="#staff-docs"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Staff Docs</a>
        </div>
        <div class="inline-actions">
          <button type="button" class="button secondary" data-print><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
        </div>
      </section>
      <div class="panel padded">
        <h2 style="margin:0 0 6px;">${esc(meta.title)}</h2>
        <div class="status-line">
          ${badge(meta.document_type)}
          <span>Version ${esc(meta.current_version || '—')}</span>
          <span>Status: ${esc(meta.status)}</span>
          <span>Effective: ${esc(fmtDate(version?.effective_date))}</span>
          <span>Last updated: ${esc(fmtDate(version?.published_at))}</span>
        </div>
      </div>
      ${ackBanner}
      <div class="help-layout">
        <aside class="help-toc" aria-label="Table of contents">
          <div class="help-toc-group"><h4>On this page</h4><ul>${tocHtml}</ul></div>
        </aside>
        <article class="help-content panel padded">
          <div class="help-section doc-body">${this.anchorHtml(html)}</div>
          ${ackControl}
          <p class="help-back" style="display:flex; justify-content:space-between; margin-top:24px;">
            ${prev ? `<a href="#staff-docs-${esc(prev.slug)}">&larr; ${esc(prev.title)}</a>` : '<span></span>'}
            ${next ? `<a href="#staff-docs-${esc(next.slug)}">${esc(next.title)} &rarr;</a>` : '<span></span>'}
          </p>
        </article>
      </div>
    `;

    $$('blockquote', this).forEach((bq) => {
      const text = bq.textContent || '';
      if (/\bTODO\b/.test(text) || /\bVERIFY\b/.test(text)) bq.classList.add('staff-doc-callout');
    });

    $('[data-print]', this)?.addEventListener('click', () => openStaffDocPrintWindow(meta, version || {}, html));
    $('[data-acknowledge]', this)?.addEventListener('click', (e) => this.acknowledge(e.target));
    $$('[data-toc]', this).forEach((a) => a.addEventListener('click', () => {
      $$('[data-toc]', this).forEach((l) => l.classList.remove('active'));
      a.classList.add('active');
    }));
  }

  // The renderer emits `id="slug"` directly on headings; prefix with
  // `sec-` in the TOC links only to keep this reader's anchors namespaced
  // from any other `id` on the page (nav, other widgets) without touching
  // the frozen HTML itself.
  anchorHtml(html) {
    return (html || '').replace(/ id="([a-z0-9-]+)"/g, ' id="sec-$1"');
  }

  async acknowledge(button) {
    button.disabled = true;
    try {
      await api(`/staff-docs/${encodeURIComponent(this._slug)}/acknowledge`, { method: 'POST', body: '{}' });
      publish('toast.show', { message: 'Acknowledged.' });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not record acknowledgment.' });
      button.disabled = false;
    }
  }
}
customElements.define('pb-staff-doc-reader', StaffDocReader);

// ── Compliance overview (admin) ─────────────────────────────────────────────

class StaffCompliancePage extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Compliance Overview', blurb: "Who's acknowledged what, and certification status by staff member." });
    this.filters = { role: '', onlyIssues: false, q: '' };
    this.setLoading('Loading compliance data');
    try {
      this.data = await api('/staff-compliance');
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  matches(row) {
    if (this.filters.role && row.role !== this.filters.role) return false;
    if (this.filters.q && !row.name.toLowerCase().includes(this.filters.q.toLowerCase())) return false;
    if (this.filters.onlyIssues) {
      const missingDoc = row.documents.some((d) => d.required && !d.acknowledged && !d.no_login);
      const certIssue = row.certifications.some((c) => c.status === 'expired' || c.status === 'expiring_soon' || c.status === 'missing_expiration');
      if (!missingDoc && !certIssue) return false;
    }
    return true;
  }

  render() {
    const staff = (this.data.staff || []).filter((r) => this.matches(r));
    const docs = this.data.documents || [];
    const roles = [...new Set((this.data.staff || []).map((r) => r.role))].sort();

    const rows = staff.map((row) => {
      const docCells = docs.map((d) => {
        const found = row.documents.find((x) => x.slug === d.slug);
        if (!found) return '<td class="num">—</td>';
        if (found.no_login) return '<td class="num" title="No login account">n/a</td>';
        return `<td class="num">${found.acknowledged ? '<span class="badge status-confirmed">✓</span>' : (found.required ? '<span class="badge status-needs-ack">✗</span>' : '<span class="badge status-empty">—</span>')}</td>`;
      }).join('');
      const certSummary = row.certifications.length
        ? row.certifications.map((c) => {
            const tone = c.status === 'expired' ? 'status-expired' : (c.status === 'expiring_soon' || c.status === 'missing_expiration' ? 'status-expiring' : 'status-confirmed');
            return `<span class="badge ${tone}" title="${esc(c.type)}${c.expires_at ? ' — expires ' + esc(fmtDate(c.expires_at)) : ''}">${esc(c.slug)}</span>`;
          }).join(' ')
        : '<span class="badge status-empty">none on file</span>';
      return `<tr>
        <td>${esc(row.name)}${row.active ? '' : ' <span class="badge status-empty">inactive</span>'}</td>
        <td>${esc(row.role)}</td>
        ${docCells}
        <td>${certSummary}</td>
      </tr>`;
    }).join('');

    this.innerHTML = `
      <section class="page-head">
        <h2>Compliance Overview</h2>
        <a class="button secondary" href="#staff-docs"><i class="fa-solid fa-book" aria-hidden="true"></i> Staff Docs</a>
      </section>
      <div class="toolbar">
        <input type="search" placeholder="Search staff…" data-q value="${esc(this.filters.q)}">
        <select data-role>
          <option value="">All roles</option>
          ${roles.map((r) => `<option value="${esc(r)}" ${r === this.filters.role ? 'selected' : ''}>${esc(r)}</option>`).join('')}
        </select>
        <label><input type="checkbox" data-issues ${this.filters.onlyIssues ? 'checked' : ''}> Only show issues</label>
      </div>
      <div class="panel">
        <div class="panel-body table-scroll">
          <table class="data-table">
            <thead><tr>
              <th>Staff</th><th>Role</th>
              ${docs.map((d) => `<th class="num" title="${esc(d.title)}">${esc(d.title.length > 18 ? d.slug : d.title)}</th>`).join('')}
              <th>Certifications</th>
            </tr></thead>
            <tbody>${rows || `<tr><td colspan="${3 + docs.length}">${emptyState('No staff match these filters.')}</td></tr>`}</tbody>
          </table>
        </div>
      </div>
    `;

    $('[data-q]', this)?.addEventListener('input', (e) => { this.filters.q = e.target.value; this.render(); });
    $('[data-role]', this)?.addEventListener('change', (e) => { this.filters.role = e.target.value; this.render(); });
    $('[data-issues]', this)?.addEventListener('change', (e) => { this.filters.onlyIssues = e.target.checked; this.render(); });
  }
}
customElements.define('pb-staff-compliance-page', StaffCompliancePage);
