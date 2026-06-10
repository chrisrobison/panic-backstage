// ── Admin ticketing surface ──────────────────────────────────────────────────
// Web component for the event workspace. It guides the operator through one
// clear choice — sell tickets in-house or link out to another platform — and
// then surfaces only the tools relevant to that mode:
//
//   • External: just the public ticket URL + platform name (with a preview).
//   • In-house: live sales summary, ticket types, comps, door-scanner links,
//     and a cancel/refund action.
//
// A separate global control (pb-payment-settings) lets venue admins switch the
// active payment provider. events.js mounts <pb-ticketing-admin> by assigning
// `.data` (the event workspace payload).

import { esc, titleCase, publish, api, formData, can, money, helpLink, PanicElement, $, $$ } from './core.js';


const moneyCents = (cents) => money(Number(cents || 0) / 100);

const TYPE_STATUSES = ['draft', 'on_sale', 'paused', 'sold_out', 'closed'];

// QR rendered by our own /assets/qr.svg generator (src/QrCode.php) — never send
// scanner-link or ticket tokens to a third-party CDN, which would leak secrets
// that grant entry. Same-origin, zero dependencies.
const qrSrc = (text, size = 160) =>
  `assets/qr.svg?size=${size}&text=${encodeURIComponent(text)}`;

// A "+" reveal button that toggles a specific form (by selector) within the
// component. Mirrors the shared addToggle styling but targets one of several
// independent forms in this panel (ticket types, scanner links).
const revealBtn = (label, target) =>
  `<button type="button" class="add-toggle" data-add-target="${esc(target)}" aria-label="${esc(label)}" title="${esc(label)}"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>`;


class TicketingAdmin extends PanicElement {
  set data(data) {
    this.eventData = data;
    this.eventId = data?.event?.id;
    this.editable = can(data, 'manage_ticketing');
    if (this.isConnected) this.load();
  }

  connect() {
    if (this.eventData) this.load();
  }

  async load() {
    if (!this.eventId) return;
    this.setLoading('Loading ticketing');
    try {
      const [dash, links] = await Promise.all([
        api(`/events/${this.eventId}/ticketing`),
        this.loadScannerLinks(),
      ]);
      this.dash = dash;
      this.links = links;
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  // Scanner links are a sibling surface (event_scanner_links). Tolerate its
  // absence so ticketing still renders if that endpoint isn't deployed yet.
  async loadScannerLinks() {
    try {
      const res = await api(`/events/${this.eventId}/scanner-links`);
      return res?.links || res?.scanner_links || [];
    } catch {
      return null;
    }
  }

  render() {
    const dash = this.dash;
    const ev = dash.event;
    const editable = this.editable;
    const internal = ev.ticketing_mode === 'internal';

    this.innerHTML = `<section class="panel ticketing" id="ticketing">
      <div class="section-head padded">
        <h2>Ticketing ${helpLink('ticketing', 'Ticketing')}</h2>
        <span class="badge ${internal ? 'status-published' : ''}">${internal ? 'Selling in-house' : 'External tickets'}</span>
      </div>

      ${editable ? this.modePickerHtml(ev, internal) : ''}

      ${internal ? this.internalBodyHtml(dash.summary) : this.externalBodyHtml(ev)}
    </section>`;

    this.bind();
  }

  // The single most important control: how tickets are sold. Two clear cards;
  // picking "external" reveals the public-URL fields, picking "in-house" hides
  // them. The heavy sections below switch only after the choice is saved.
  modePickerHtml(ev, internal) {
    const option = (value, icon, title, desc) => {
      const selected = (value === 'internal') === internal;
      return `<label class="mode-option${selected ? ' selected' : ''}">
        <input type="radio" name="ticketing_mode" value="${value}" ${selected ? 'checked' : ''}>
        <span class="mode-option-icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span>
        <span class="mode-option-body"><strong>${title}</strong><span class="muted">${desc}</span></span>
      </label>`;
    };
    return `<form class="padded ticketing-mode" data-form="mode">
      <div class="field-label">How are tickets sold?</div>
      <div class="mode-options">
        ${option('internal', 'fa-store', 'Sell tickets here', 'In-house checkout, QR tickets &amp; door scanning.')}
        ${option('external', 'fa-arrow-up-right-from-square', 'Use another platform', 'Send buyers to TIXR, Eventbrite, etc.')}
      </div>
      <div class="mode-config" data-mode-config="external"${internal ? ' hidden' : ''}>
        <label class="wide">Ticket page URL
          <input name="ticket_url" type="url" value="${esc(ev.ticket_url || '')}" placeholder="https://tickets.example.com/your-show">
        </label>
        <label>Platform name
          <input name="ticket_system" value="${esc(ev.ticket_system || '')}" placeholder="TIXR, Eventbrite…">
        </label>
      </div>
      <div class="form-actions">
        <button class="small">Save ticketing mode</button>
        <span class="unsaved-hint" data-unsaved hidden>Unsaved changes — click save to apply.</span>
      </div>
    </form>`;
  }

  // In-house: sales summary + ticket types + comps + scanner links + refund.
  internalBodyHtml(summary) {
    const editable = this.editable;
    return `
      <div class="padded ticketing-summary">
        <div class="stat-grid">
          ${this.stat('Sold', summary.tickets_sold)}
          ${this.stat('Available', summary.tickets_available)}
          ${this.stat('Redeemed', `${summary.tickets_redeemed} / ${summary.tickets_issued}`)}
          ${this.stat('Gross sales', money(summary.gross_ticket_sales))}
        </div>
      </div>

      <div class="section-head padded sub-head">
        <h3>Ticket types</h3>
        ${editable ? revealBtn('Add ticket type', 'form[data-form="tier"]') : ''}
      </div>
      ${editable ? this.tierFormHtml() : ''}
      <div class="ticketing-tiers padded">${this.tiersHtml()}</div>

      ${editable ? this.compSectionHtml() : ''}
      ${this.scannerSectionHtml()}

      ${editable ? `<div class="padded danger-zone">
        <h3>Cancel &amp; refund</h3>
        <p class="ticket-note">Refunds every paid order through its original processor, voids all issued tickets, and marks orders refunded. This cannot be undone.</p>
        <button type="button" class="danger" data-refund-all>Refund all orders</button>
      </div>` : ''}`;
  }

  // External: just a preview of where buyers are sent (the editable URL fields
  // live in the mode picker above).
  externalBodyHtml(ev) {
    const url = ev.ticket_url || '';
    const card = url
      ? `<div class="external-link-card">
          <div class="external-link-info">
            <span class="muted">Buyers are sent to</span>
            <a href="${esc(url)}" target="_blank" rel="noopener" class="external-link-url">${esc(url)}</a>
            ${ev.ticket_system ? `<span class="muted">via ${esc(ev.ticket_system)}</span>` : ''}
          </div>
          <a class="button secondary" href="${esc(url)}" target="_blank" rel="noopener">Open <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
        </div>`
      : `<p class="empty-state">No ticket link yet.${this.editable ? ' Add your ticket page URL above so it appears on the public event page.' : ''}</p>`;
    return `<div class="padded ticketing-external">
      ${card}
      <p class="ticket-note">In-house tools — ticket types, comps, and door scanning — are available in “Sell tickets here” mode.</p>
    </div>`;
  }

  stat(label, value) {
    return `<div class="stat"><span class="stat-value">${esc(value)}</span><span class="stat-label">${esc(label)}</span></div>`;
  }

  tiersHtml() {
    const tiers = this.dash.tiers || [];
    if (!tiers.length) {
      return `<p class="empty-state">No ticket types yet.${this.editable ? ' Add one (e.g. “General Admission”) to start selling.' : ''}</p>`;
    }
    const editable = this.editable;
    return `<table class="data-table">
      <thead><tr><th>Type</th><th>Price</th><th>Sold</th><th>Comp</th><th>Avail</th><th>Revenue</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
      <tbody>${tiers.map((t) => `<tr data-tier="${esc(t.id)}">
        <td data-label="Type"><strong>${esc(t.name)}</strong>${t.description ? `<br><span class="muted">${esc(t.description)}</span>` : ''}</td>
        <td data-label="Price">${moneyCents(t.price_cents)}</td>
        <td data-label="Sold">${esc(t.quantity_sold)} / ${esc(t.quantity_total)}</td>
        <td data-label="Comp">${esc(t.quantity_comped)}</td>
        <td data-label="Avail">${esc(t.available)}</td>
        <td data-label="Revenue">${moneyCents(t.revenue_cents)}</td>
        <td data-label="Status"><span class="badge">${esc(titleCase(t.status))}</span></td>
        ${editable ? `<td class="row-actions"><button type="button" class="link" data-edit-tier="${esc(t.id)}">Edit</button><button type="button" class="link danger" data-del-tier="${esc(t.id)}">Delete</button></td>` : ''}
      </tr>`).join('')}</tbody>
    </table>`;
  }

  tierFormHtml(tier = null) {
    const t = tier || {};
    const dollars = t.price_cents != null ? (t.price_cents / 100).toFixed(2) : '';
    return `<form class="grid-form padded" data-form="tier" data-tier-id="${esc(t.id || '')}" hidden>
      <label>Name <input name="name" required value="${esc(t.name || '')}" placeholder="General Admission"></label>
      <label>Price (USD) <input name="price_dollars" type="number" step="0.01" min="0" value="${esc(dollars)}" placeholder="0.00"></label>
      <label>Quantity <input name="quantity_total" type="number" min="0" required value="${esc(t.quantity_total ?? '')}" placeholder="100"></label>
      <label>Status <select name="status">${TYPE_STATUSES.map((s) => `<option value="${s}" ${s === (t.status || 'draft') ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}</select></label>
      <label>Sales start <input name="sales_start" type="datetime-local" value="${esc((t.sales_start || '').replace(' ', 'T').slice(0, 16))}"></label>
      <label>Sales end <input name="sales_end" type="datetime-local" value="${esc((t.sales_end || '').replace(' ', 'T').slice(0, 16))}"></label>
      <label class="wide">Description <input name="description" value="${esc(t.description || '')}" placeholder="What’s included (optional)"></label>
      <div class="form-actions"><button>${t.id ? 'Save ticket type' : 'Add ticket type'}</button><button type="button" class="secondary" data-cancel-tier>Cancel</button></div>
    </form>`;
  }

  compSectionHtml() {
    const tiers = this.dash.tiers || [];
    if (!this.editable) return '';
    return `<div class="section-head padded sub-head"><h3>Comp tickets</h3>${tiers.length ? revealBtn('Issue comp tickets', 'form[data-form="comp"]') : ''}</div>
      ${tiers.length ? `<form class="grid-form padded" data-form="comp" hidden>
        <label>Ticket type <select name="ticket_type_id" required>${tiers.map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label>
        <label>Quantity <input name="quantity" type="number" min="1" value="1" required></label>
        <label>Holder name <input name="holder_name" placeholder="Optional"></label>
        <label>Holder email <input name="holder_email" type="email" placeholder="Emails QR if set"></label>
        <div class="form-actions"><button>Issue comps</button><button type="button" class="secondary" data-cancel-comp>Cancel</button></div>
      </form>
      <div class="comp-result padded" hidden></div>`
        : '<p class="ticket-note padded">Add a ticket type first to issue comps.</p>'}`;
  }

  scannerSectionHtml() {
    const links = this.links;
    const editable = this.editable;
    const body = this.scannerListInner();
    const canManage = editable && links !== null;
    return `<div class="section-head padded sub-head"><h3>Door scanner links</h3>${canManage ? revealBtn('New scanner link', 'form[data-form="scanner"]') : ''}</div>
      ${canManage ? `<form class="row-form padded" data-form="scanner" hidden>
        <label class="wide">Label <input name="label" placeholder="Front door"></label>
        <label>PIN (optional) <input name="pin" inputmode="numeric" placeholder="e.g. 4821"></label>
        <button class="small">Create link</button>
      </form>` : ''}
      <div class="scanner-links padded">${body}</div>
      <div class="scanner-new padded" hidden></div>`;
  }

  bind() {
    // "+" reveal buttons, each targeting a specific form by selector.
    $$('[data-add-target]', this).forEach((btn) => btn.addEventListener('click', () => {
      const form = $(btn.dataset.addTarget, this);
      if (!form) return;
      const show = form.hasAttribute('hidden');
      form.toggleAttribute('hidden', !show);
      btn.classList.toggle('active', show);
      if (show) $$('input, select, textarea', form).find((el) => !el.disabled && el.type !== 'hidden')?.focus();
    }));

    // Mode picker: reactively reveal the external-URL fields and flag unsaved
    // changes; persist on submit.
    const modeForm = $('form[data-form="mode"]', this);
    if (modeForm) {
      const saved = this.dash.event.ticketing_mode === 'internal' ? 'internal' : 'external';
      $$('input[name="ticketing_mode"]', modeForm).forEach((radio) => radio.addEventListener('change', () => {
        $$('.mode-option', modeForm).forEach((lab) => lab.classList.toggle('selected', $('input', lab).checked));
        $('[data-mode-config="external"]', modeForm)?.toggleAttribute('hidden', radio.value !== 'external');
        const hint = $('[data-unsaved]', modeForm);
        if (hint) hint.hidden = radio.value === saved;
      }));
      modeForm.addEventListener('submit', (e) => this.saveMode(e));
    }

    // Ticket type create / cancel
    $('form[data-form="tier"]', this)?.addEventListener('submit', (e) => this.saveTier(e));
    $('[data-cancel-tier]', this)?.addEventListener('click', () => this.collapseForm('form[data-form="tier"]'));

    // Ticket type edit / delete (row actions, only if editable)
    $$('[data-edit-tier]', this).forEach((btn) => btn.addEventListener('click', () => this.editTier(Number(btn.dataset.editTier))));
    $$('[data-del-tier]', this).forEach((btn) => btn.addEventListener('click', () => this.deleteTier(Number(btn.dataset.delTier))));

    // Comp
    $('form[data-form="comp"]', this)?.addEventListener('submit', (e) => this.issueComps(e));
    $('[data-cancel-comp]', this)?.addEventListener('click', () => this.collapseForm('form[data-form="comp"]'));

    // Refund all
    $('[data-refund-all]', this)?.addEventListener('click', () => this.refundAll());

    // Scanner links
    $('form[data-form="scanner"]', this)?.addEventListener('submit', (e) => this.createScannerLink(e));
    this.bindScanner();
  }

  // Wire the per-row scanner actions. Kept separate so it can be re-run after
  // the links list is re-rendered in place (without double-binding the rest).
  bindScanner() {
    $$('[data-revoke-link]', this).forEach((btn) => btn.addEventListener('click', () => this.revokeLink(Number(btn.dataset.revokeLink))));
    $$('[data-show-link]', this).forEach((btn) => btn.addEventListener('click', () => this.toggleScannerReveal(Number(btn.dataset.showLink))));
    $$('[data-gen-link]', this).forEach((btn) => btn.addEventListener('click', () => this.regenerateScannerLink(Number(btn.dataset.genLink))));
  }

  // Hide a reveal form again and reset its matching "+" toggle. Matches the
  // toggle in JS (not a CSS attribute selector) because the selector value
  // itself contains quotes.
  collapseForm(selector) {
    $(selector, this)?.setAttribute('hidden', '');
    $$('[data-add-target]', this).forEach((btn) => {
      if (btn.dataset.addTarget === selector) btn.classList.remove('active');
    });
  }

  async saveMode(e) {
    e.preventDefault();
    const values = formData(e.target);
    const mode = values.ticketing_mode === 'internal' ? 'internal' : 'external';
    const body = { ticketing_mode: mode };
    // Only persist the external fields when external — and keep any saved URL
    // untouched when switching to in-house so toggling back doesn't lose it.
    if (mode === 'external') {
      body.ticket_url = values.ticket_url || '';
      body.ticket_system = values.ticket_system || '';
    }
    const res = await api(`/events/${this.eventId}/ticketing`, { method: 'PATCH', body: JSON.stringify(body) });
    publish('toast.show', {
      message: res?.seeded_default_type
        ? 'In-house ticketing on — added a General Admission type at the event price.'
        : 'Ticketing settings saved.',
    });
    await this.load();
  }

  editTier(id) {
    const tier = (this.dash.tiers || []).find((t) => Number(t.id) === id);
    if (!tier) return;
    const host = $('form[data-form="tier"]', this);
    if (!host) return;
    host.outerHTML = this.tierFormHtml(tier);
    const form = $('form[data-form="tier"]', this);
    form.removeAttribute('hidden');
    form.addEventListener('submit', (e) => this.saveTier(e));
    $('[data-cancel-tier]', form)?.addEventListener('click', () => this.load());
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async saveTier(e) {
    e.preventDefault();
    const values = formData(e.target);
    const body = {
      name: values.name,
      description: values.description || null,
      price_cents: Math.round(Number(values.price_dollars || 0) * 100),
      quantity_total: Number(values.quantity_total || 0),
      status: values.status,
      sales_start: values.sales_start ? values.sales_start.replace('T', ' ') + ':00' : null,
      sales_end: values.sales_end ? values.sales_end.replace('T', ' ') + ':00' : null,
    };
    const id = e.target.dataset.tierId;
    const path = id ? `/events/${this.eventId}/ticketing/types/${id}` : `/events/${this.eventId}/ticketing`;
    await api(path, { method: id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
    publish('toast.show', { message: id ? 'Ticket type updated.' : 'Ticket type created.' });
    await this.load();
  }

  async deleteTier(id) {
    if (!confirm('Delete this ticket type? Types with issued tickets cannot be deleted.')) return;
    try {
      await api(`/events/${this.eventId}/ticketing/types/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Ticket type deleted.' });
      await this.load();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async issueComps(e) {
    e.preventDefault();
    const values = formData(e.target);
    const res = await api(`/events/${this.eventId}/ticketing/comp`, { method: 'POST', body: JSON.stringify(values) });
    const out = $('.comp-result', this);
    if (out) {
      out.hidden = false;
      out.innerHTML = `<p>Issued <strong>${esc(res.issued)}</strong> comp ticket(s)${res.emailed ? `, emailed ${esc(res.emailed)}.` : '.'}</p>
        <div class="comp-codes">${(res.tickets || []).map((t) => `<span class="ticket-code">${esc(t.code)}</span>`).join('')}</div>`;
    }
    publish('toast.show', { message: `Issued ${res.issued} comp ticket(s).` });
    await this.refreshSummary();
  }

  async refundAll() {
    if (!confirm('Refund ALL paid orders for this event and void every ticket? This cannot be undone.')) return;
    try {
      const res = await api(`/events/${this.eventId}/ticketing/refund`, { method: 'POST', body: JSON.stringify({}) });
      const msg = `Refunded ${res.orders_refunded} order(s) (${money(res.cents_refunded / 100)})${res.failed ? `, ${res.failed} failed` : ''}.`;
      publish('toast.show', { tone: res.failed ? 'error' : 'info', message: msg });
      await this.load();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async createScannerLink(e) {
    e.preventDefault();
    try {
      const res = await api(`/events/${this.eventId}/scanner-links`, { method: 'POST', body: JSON.stringify(formData(e.target)) });
      // The shareable URL + secret are returned once on creation.
      const url = res?.url || res?.scanner_url || res?.link;
      const out = $('.scanner-new', this);
      if (url && out) {
        out.hidden = false;
        out.innerHTML = `<p class="subtle">Share this link with door staff (shown once):</p>
          <code class="scanner-url">${esc(url)}</code>
          <div class="qr"><img src="${esc(qrSrc(url))}" alt="Scanner link QR" width="160" height="160"></div>`;
      }
      publish('toast.show', { message: 'Scanner link created.' });
      this.links = await this.loadScannerLinks();
      // Refresh the links list region in place.
      const region = $('.scanner-links', this);
      if (region) region.innerHTML = this.scannerListInner();
      this.bindScanner();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  // Inner HTML of the scanner links list (table or empty state). Single source
  // of truth for both first render and in-place refresh after create/regenerate.
  scannerListInner() {
    const links = this.links;
    const editable = this.editable;
    if (links === null) return '<p class="empty-state">Scanner links are unavailable.</p>';
    if (!links.length) return '<p class="empty-state">No scanner links yet.</p>';
    const cols = editable ? 5 : 4;
    return `<table class="data-table">
      <thead><tr><th>Label</th><th>Created</th><th>Last used</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
      <tbody>${links.map((l) => `<tr data-link="${esc(l.id)}">
        <td data-label="Label">${esc(l.label || 'Door scanner')}</td>
        <td data-label="Created">${esc((l.created_at || '').slice(0, 16).replace('T', ' '))}</td>
        <td data-label="Last used">${esc(l.last_used_at ? l.last_used_at.slice(0, 16).replace('T', ' ') : '—')}</td>
        <td data-label="Status">${l.revoked_at ? '<span class="badge status-canceled">Revoked</span>' : '<span class="badge status-published">Active</span>'}</td>
        ${editable ? `<td class="row-actions">${l.revoked_at ? '' : `${l.has_token
            ? `<button type="button" class="link" data-show-link="${esc(l.id)}">Show link</button>`
            : `<button type="button" class="link" data-gen-link="${esc(l.id)}">Generate link</button>`}
          <button type="button" class="link danger" data-revoke-link="${esc(l.id)}">Revoke</button>`}</td>` : ''}
      </tr>${editable && !l.revoked_at ? `<tr class="scanner-reveal-row" data-reveal-row="${esc(l.id)}" hidden><td colspan="${cols}"><div class="scanner-reveal" data-reveal="${esc(l.id)}"></div></td></tr>` : ''}`).join('')}</tbody>
    </table>`;
  }

  // Render the URL + QR for a link into its reveal row and show it.
  revealScannerUrl(id, url) {
    const host = $(`[data-reveal="${id}"]`, this);
    const row = $(`[data-reveal-row="${id}"]`, this);
    if (!host || !row || !url) return;
    host.innerHTML = `<p class="subtle">Open on the door device, or have staff scan this QR:</p>
      <code class="scanner-url">${esc(url)}</code>
      <div class="qr"><img src="${esc(qrSrc(url))}" alt="Scanner link QR" width="160" height="160"></div>`;
    row.hidden = false;
    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // Toggle an existing (token-bearing) link's reveal row.
  toggleScannerReveal(id) {
    const row = $(`[data-reveal-row="${id}"]`, this);
    if (!row) return;
    if (!row.hidden) { row.hidden = true; return; }
    const link = (this.links || []).find((l) => Number(l.id) === id);
    this.revealScannerUrl(id, link?.scanner_url);
  }

  // Rotate a legacy/leaked link's secret, then reveal the fresh URL + QR.
  async regenerateScannerLink(id) {
    if (!confirm('Generate a fresh link for this scanner? Any previously shared copy of this link will stop working.')) return;
    try {
      const res = await api(`/events/${this.eventId}/scanner-links/${id}`, { method: 'POST', body: JSON.stringify({}) });
      const url = res?.scanner_url || res?.url;
      const link = (this.links || []).find((l) => Number(l.id) === id);
      if (link && url) { link.has_token = true; link.scanner_url = url; }
      publish('toast.show', { message: 'Scanner link generated.' });
      const region = $('.scanner-links', this);
      if (region) region.innerHTML = this.scannerListInner();
      this.bindScanner();
      this.revealScannerUrl(id, url);
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async revokeLink(id) {
    if (!confirm('Revoke this scanner link? Door staff using it will lose access.')) return;
    try {
      await api(`/events/${this.eventId}/scanner-links/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Scanner link revoked.' });
      this.links = await this.loadScannerLinks();
      this.render();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  // Refresh just the summary stats + tiers table without a full reload.
  async refreshSummary() {
    try {
      this.dash = await api(`/events/${this.eventId}/ticketing`);
      this.render();
    } catch { /* keep current view */ }
  }
}


// ── Global payment-provider switch (venue admin) ──────────────────────────────
// Standalone control for the active payment processor + default currency.
// Mounted on the Admin page (its own tab) — gated to manage_users on the server.

class PaymentSettingsPanel extends PanicElement {
  connect() {
    this.load();
  }

  async load() {
    this.setLoading('Loading payment settings');
    try {
      this.settings = await api('/payment-settings');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const s = this.settings;
    const providers = s.providers || [];
    this.innerHTML = `<section class="panel" id="payment-settings">
      <div class="section-head padded"><h2>Payment provider ${helpLink('admin-payments', 'Payment providers')}</h2></div>
      <div class="padded">
        <p class="subtle">Select the active processor for in-house ticket checkout. Secret keys live in the server environment and are never shown here.</p>
        <form class="grid-form" data-form="payment">
          <label>Active provider
            <select name="active_provider">
              ${providers.map((p) => `<option value="${esc(p.key)}" ${p.key === s.active_provider ? 'selected' : ''} ${p.configured ? '' : 'disabled'}>${esc(p.label)}${p.configured ? '' : ' (keys missing)'}</option>`).join('')}
            </select>
          </label>
          <label>Default currency <input name="currency" value="${esc(s.currency || 'USD')}" maxlength="3" style="text-transform:uppercase"></label>
          <div class="form-actions"><button>Save</button></div>
        </form>
        <ul class="provider-status">
          ${providers.map((p) => `<li><span class="status-dot ${p.configured ? 'green' : 'red'}"></span> ${esc(p.label)} — ${p.configured ? 'configured' : 'keys not set in environment'}</li>`).join('')}
        </ul>
      </div>
    </section>`;

    $('form[data-form="payment"]', this).addEventListener('submit', async (e) => {
      e.preventDefault();
      const values = formData(e.target);
      this.settings = await api('/payment-settings', { method: 'PATCH', body: JSON.stringify(values) });
      publish('toast.show', { message: 'Payment settings saved.' });
      this.render();
    });
  }
}


customElements.define('pb-ticketing-admin', TicketingAdmin);
customElements.define('pb-payment-settings', PaymentSettingsPanel);

export { TicketingAdmin, PaymentSettingsPanel };
