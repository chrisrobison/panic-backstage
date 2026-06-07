// ── Admin ticketing surface ──────────────────────────────────────────────────
// Web component for the event workspace: manage ticket tiers, view the live
// sales dashboard, comp tickets, manage door-scanner links, and switch the
// internal/external ticketing mode. A separate global control (pb-payment-
// settings) lets venue admins switch the active payment provider.
//
// New file only — registers its own custom elements; events.js mounts
// <pb-ticketing-admin> by assigning `.data` (the event workspace payload).

import { esc, titleCase, publish, api, formData, can, money, PanicElement, addToggle, $, $$ } from './core.js';


const moneyCents = (cents) => money(Number(cents || 0) / 100);

const TIER_STATUSES = ['draft', 'on_sale', 'paused', 'sold_out', 'closed'];

// QR rendered by our own /assets/qr.svg generator (src/QrCode.php) — never send
// scanner-link or ticket tokens to a third-party CDN, which would leak secrets
// that grant entry. Same-origin, zero dependencies.
const qrSrc = (text, size = 160) =>
  `assets/qr.svg?size=${size}&text=${encodeURIComponent(text)}`;


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
    const summary = dash.summary;
    const editable = this.editable;
    const internal = ev.ticketing_mode === 'internal';

    this.innerHTML = `<section class="panel" id="ticketing">
      <div class="section-head padded">
        <h2>Ticketing</h2>
        <div class="inline-actions">
          <span class="badge ${internal ? 'status-published' : ''}">${internal ? 'In-house ticketing' : 'External ticketing'}</span>
        </div>
      </div>

      <div class="padded ticketing-summary">
        <div class="stat-grid">
          ${this.stat('Sold', summary.tickets_sold)}
          ${this.stat('Available', summary.tickets_available)}
          ${this.stat('Redeemed', `${summary.tickets_redeemed} / ${summary.tickets_issued}`)}
          ${this.stat('Gross sales', money(summary.gross_ticket_sales))}
        </div>
      </div>

      <form class="row-form padded" data-form="mode">
        <label>Mode
          <select name="ticketing_mode" ${editable ? '' : 'disabled'}>
            <option value="external" ${!internal ? 'selected' : ''}>External (link out)</option>
            <option value="internal" ${internal ? 'selected' : ''}>Internal (in-house)</option>
          </select>
        </label>
        <label class="wide">External ticket URL
          <input name="ticket_url" value="${esc(ev.ticket_url || '')}" placeholder="https://…" ${editable ? '' : 'disabled'}>
        </label>
        <label>Ticket system
          <input name="ticket_system" value="${esc(ev.ticket_system || '')}" placeholder="TIXR / Door" ${editable ? '' : 'disabled'}>
        </label>
        ${editable ? '<button class="small">Save mode</button>' : ''}
      </form>

      <div class="section-head padded sub-head">
        <h3>Tiers</h3>
        ${addToggle('Add tier', editable)}
      </div>
      ${editable ? this.tierFormHtml() : ''}
      <div class="ticketing-tiers padded">${this.tiersHtml()}</div>

      ${internal ? this.compSectionHtml() : ''}
      ${internal ? this.scannerSectionHtml() : ''}

      ${editable && internal ? `<div class="padded danger-zone">
        <h3>Cancel &amp; refund</h3>
        <p class="subtle">Refunds every paid order through its original processor, voids all issued tickets, and marks orders refunded. This cannot be undone.</p>
        <button type="button" class="danger" data-refund-all>Refund all orders</button>
      </div>` : ''}
    </section>`;

    this.bind();
  }

  stat(label, value) {
    return `<div class="stat"><span class="stat-value">${esc(value)}</span><span class="stat-label">${esc(label)}</span></div>`;
  }

  tiersHtml() {
    const tiers = this.dash.tiers || [];
    if (!tiers.length) return '<p class="empty-state">No ticket tiers yet.</p>';
    const editable = this.editable;
    return `<table class="data-table">
      <thead><tr><th>Tier</th><th>Price</th><th>Sold</th><th>Comp</th><th>Avail</th><th>Revenue</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
      <tbody>${tiers.map((t) => `<tr data-tier="${esc(t.id)}">
        <td data-label="Tier"><strong>${esc(t.name)}</strong>${t.description ? `<br><span class="muted">${esc(t.description)}</span>` : ''}</td>
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
    return `<form class="grid-form padded hidden" data-form="tier" data-tier-id="${esc(t.id || '')}">
      <label>Name <input name="name" required value="${esc(t.name || '')}"></label>
      <label>Price (USD) <input name="price_dollars" type="number" step="0.01" min="0" value="${esc(dollars)}" placeholder="0.00"></label>
      <label>Quantity <input name="quantity_total" type="number" min="0" required value="${esc(t.quantity_total ?? '')}"></label>
      <label>Status <select name="status">${TIER_STATUSES.map((s) => `<option value="${s}" ${s === (t.status || 'draft') ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}</select></label>
      <label>Sales start <input name="sales_start" type="datetime-local" value="${esc((t.sales_start || '').replace(' ', 'T').slice(0, 16))}"></label>
      <label>Sales end <input name="sales_end" type="datetime-local" value="${esc((t.sales_end || '').replace(' ', 'T').slice(0, 16))}"></label>
      <label class="wide">Description <input name="description" value="${esc(t.description || '')}"></label>
      <div class="form-actions"><button>${t.id ? 'Save tier' : 'Add tier'}</button><button type="button" class="secondary" data-cancel-tier>Cancel</button></div>
    </form>`;
  }

  compSectionHtml() {
    const tiers = this.dash.tiers || [];
    if (!this.editable) return '';
    return `<div class="section-head padded sub-head"><h3>Comp tickets</h3></div>
      <form class="grid-form padded" data-form="comp">
        <label>Tier <select name="ticket_type_id" required>${tiers.map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label>
        <label>Quantity <input name="quantity" type="number" min="1" value="1" required></label>
        <label>Holder name <input name="holder_name" placeholder="Optional"></label>
        <label>Holder email <input name="holder_email" type="email" placeholder="Emails QR if set"></label>
        <div class="form-actions"><button>Issue comps</button></div>
      </form>
      <div class="comp-result padded" hidden></div>`;
  }

  scannerSectionHtml() {
    const links = this.links;
    const editable = this.editable;
    let body;
    if (links === null) {
      body = '<p class="empty-state">Scanner links are unavailable.</p>';
    } else if (!links.length) {
      body = '<p class="empty-state">No scanner links yet.</p>';
    } else {
      body = `<table class="data-table">
        <thead><tr><th>Label</th><th>Created</th><th>Last used</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
        <tbody>${links.map((l) => `<tr data-link="${esc(l.id)}">
          <td data-label="Label">${esc(l.label || 'Door scanner')}</td>
          <td data-label="Created">${esc((l.created_at || '').slice(0, 16).replace('T', ' '))}</td>
          <td data-label="Last used">${esc(l.last_used_at ? l.last_used_at.slice(0, 16).replace('T', ' ') : '—')}</td>
          <td data-label="Status">${l.revoked_at ? '<span class="badge status-canceled">Revoked</span>' : '<span class="badge status-published">Active</span>'}</td>
          ${editable ? `<td class="row-actions">${l.revoked_at ? '' : `<button type="button" class="link danger" data-revoke-link="${esc(l.id)}">Revoke</button>`}</td>` : ''}
        </tr>`).join('')}</tbody>
      </table>`;
    }
    return `<div class="section-head padded sub-head"><h3>Door scanner links</h3>${editable && links !== null ? addToggle('New scanner link', true) : ''}</div>
      ${editable && links !== null ? `<form class="row-form padded hidden" data-form="scanner">
        <label class="wide">Label <input name="label" placeholder="Front door"></label>
        <label>PIN (optional) <input name="pin" inputmode="numeric" placeholder="e.g. 4821"></label>
        <button class="small">Create link</button>
      </form>` : ''}
      <div class="scanner-links padded">${body}</div>
      <div class="scanner-new padded" hidden></div>`;
  }

  bind() {
    // Toggle reveal forms (+ buttons).
    $$('[data-add]', this).forEach((btn) => btn.addEventListener('click', () => {
      const form = btn.closest('.section-head')?.nextElementSibling;
      if (form?.matches('form')) form.classList.toggle('hidden');
    }));

    // Mode / settings
    $('form[data-form="mode"]', this)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventId}/ticketing`, { method: 'PATCH', body: JSON.stringify(formData(e.target)) });
      publish('toast.show', { message: 'Ticketing settings saved.' });
      await this.load();
    });

    // Tier create
    $('form[data-form="tier"]', this)?.addEventListener('submit', (e) => this.saveTier(e));
    $('[data-cancel-tier]', this)?.addEventListener('click', () => $('form[data-form="tier"]', this)?.classList.add('hidden'));

    // Tier edit / delete (row actions, only if editable)
    $$('[data-edit-tier]', this).forEach((btn) => btn.addEventListener('click', () => this.editTier(Number(btn.dataset.editTier))));
    $$('[data-del-tier]', this).forEach((btn) => btn.addEventListener('click', () => this.deleteTier(Number(btn.dataset.delTier))));

    // Comp
    $('form[data-form="comp"]', this)?.addEventListener('submit', (e) => this.issueComps(e));

    // Refund all
    $('[data-refund-all]', this)?.addEventListener('click', () => this.refundAll());

    // Scanner links
    $('form[data-form="scanner"]', this)?.addEventListener('submit', (e) => this.createScannerLink(e));
    $$('[data-revoke-link]', this).forEach((btn) => btn.addEventListener('click', () => this.revokeLink(Number(btn.dataset.revokeLink))));
  }

  editTier(id) {
    const tier = (this.dash.tiers || []).find((t) => Number(t.id) === id);
    if (!tier) return;
    const host = $('form[data-form="tier"]', this);
    if (!host) return;
    host.outerHTML = this.tierFormHtml(tier).replace('class="grid-form padded hidden"', 'class="grid-form padded"');
    const form = $('form[data-form="tier"]', this);
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
    publish('toast.show', { message: id ? 'Tier updated.' : 'Tier created.' });
    await this.load();
  }

  async deleteTier(id) {
    if (!confirm('Delete this tier? Tiers with issued tickets cannot be deleted.')) return;
    try {
      await api(`/events/${this.eventId}/ticketing/types/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Tier deleted.' });
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
      this.bind();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  // Inner HTML of the scanner links list (table or empty state) for in-place refresh.
  scannerListInner() {
    const links = this.links;
    const editable = this.editable;
    if (links === null) return '<p class="empty-state">Scanner links are unavailable.</p>';
    if (!links.length) return '<p class="empty-state">No scanner links yet.</p>';
    return `<table class="data-table">
      <thead><tr><th>Label</th><th>Created</th><th>Last used</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
      <tbody>${links.map((l) => `<tr data-link="${esc(l.id)}">
        <td data-label="Label">${esc(l.label || 'Door scanner')}</td>
        <td data-label="Created">${esc((l.created_at || '').slice(0, 16).replace('T', ' '))}</td>
        <td data-label="Last used">${esc(l.last_used_at ? l.last_used_at.slice(0, 16).replace('T', ' ') : '—')}</td>
        <td data-label="Status">${l.revoked_at ? '<span class="badge status-canceled">Revoked</span>' : '<span class="badge status-published">Active</span>'}</td>
        ${editable ? `<td class="row-actions">${l.revoked_at ? '' : `<button type="button" class="link danger" data-revoke-link="${esc(l.id)}">Revoke</button>`}</td>` : ''}
      </tr>`).join('')}</tbody>
    </table>`;
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
      <div class="section-head padded"><h2>Payment provider</h2></div>
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
