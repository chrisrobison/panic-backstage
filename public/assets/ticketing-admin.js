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

// Format a stored datetime string ("2026-06-30 21:00:00") as a short, local
// "Jun 30, 9:00 PM" label. Returns '' for empty values.
const shortDateTime = (value) => {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
};

// Render a tier's sales window as "start → end". An open-ended bound shows as
// "—"; a tier with neither bound (e.g. Comps) shows a single em dash.
const salesWindow = (start, end) => {
  const s = shortDateTime(start);
  const e = shortDateTime(end);
  if (!s && !e) return '<span class="muted">—</span>';
  return `${esc(s || '—')} <span class="muted">→</span> ${esc(e || '—')}`;
};

const TYPE_STATUSES = ['draft', 'on_sale', 'paused', 'sold_out', 'closed'];

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
      const [dash, links, tickets] = await Promise.all([
        api(`/events/${this.eventId}/ticketing`),
        this.loadScannerLinks(),
        this.loadTickets(),
      ]);
      this.dash = dash;
      this.links = links;
      this.tickets = tickets;
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

  // Every issued ticket (paid + comp) for the event — drives the issued-tickets
  // table. Tolerate absence so ticketing still renders if unavailable.
  async loadTickets() {
    try {
      const res = await api(`/events/${this.eventId}/ticketing/tickets`);
      return res?.tickets || [];
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
        ${editable ? `<button type="button" class="add-toggle" data-add-tier aria-label="Add ticket type" title="Add ticket type"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>` : ''}
      </div>
      <div class="ticketing-tiers padded">${this.tiersHtml()}</div>

      ${editable ? this.compSectionHtml() : ''}
      ${this.issuedTicketsHtml()}
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
      <thead><tr><th>Type</th><th>Price</th><th>Sold</th><th>Comp</th><th>Avail</th><th>Revenue</th><th>Sales window</th><th>Status</th>${editable ? '<th></th>' : ''}</tr></thead>
      <tbody>${tiers.map((t) => `<tr data-tier="${esc(t.id)}">
        <td data-label="Type"><strong>${esc(t.name)}</strong>${t.description ? `<br><span class="muted">${esc(t.description)}</span>` : ''}</td>
        <td data-label="Price">${moneyCents(t.price_cents)}</td>
        <td data-label="Sold">${esc(t.quantity_sold)} / ${esc(t.quantity_total)}</td>
        <td data-label="Comp">${esc(t.quantity_comped)}</td>
        <td data-label="Avail">${esc(t.available)}</td>
        <td data-label="Revenue">${moneyCents(t.revenue_cents)}</td>
        <td data-label="Sales window">${salesWindow(t.sales_start, t.sales_end)}</td>
        <td data-label="Status"><span class="badge">${esc(titleCase(t.status))}</span></td>
        ${editable ? `<td class="row-actions"><button type="button" class="link" data-edit-tier="${esc(t.id)}" aria-label="Edit" title="Edit"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button></td>` : ''}
      </tr>`).join('')}</tbody>
    </table>`;
  }

  // Shared modal shell: appends a titled backdrop+card, wires close-on-button
  // ([data-close]), backdrop-click, and Escape, and returns { dialog, close }.
  // The close handler is delegated so [data-close] buttons injected into the
  // body later (e.g. after a result renders) still work.
  openModal(title, bodyHtml) {
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>${esc(title)}</h2><button class="small secondary" data-close type="button">Close</button></div>
      ${bodyHtml}
    </div>`;
    document.body.appendChild(dialog);
    const onEsc = (event) => { if (event.key === 'Escape') close(); };
    const close = () => { document.removeEventListener('keydown', onEsc); dialog.remove(); };
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog || event.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', onEsc);
    return { dialog, close };
  }

  // Open the ticket-type editor in a modal dialog (shared by Add + Edit). Using
  // a modal — rather than an inline reveal — keeps the form in a single, obvious
  // place instead of shifting the tiers table around underneath it.
  openTierModal(tier = null) {
    const t = tier || {};
    const isEdit = Boolean(t.id);
    const dollars = t.price_cents != null ? (t.price_cents / 100).toFixed(2) : '';
    const { dialog, close } = this.openModal(isEdit ? 'Edit ticket type' : 'Add ticket type', `
      <form class="grid-form padded" data-form="tier" data-tier-id="${esc(t.id || '')}">
        <label>Name <input name="name" required value="${esc(t.name || '')}" placeholder="General Admission"></label>
        <label>Price (USD) <input name="price_dollars" type="number" step="0.01" min="0" value="${esc(dollars)}" placeholder="0.00"></label>
        <label>Quantity <input name="quantity_total" type="number" min="0" required value="${esc(t.quantity_total ?? '')}" placeholder="100"></label>
        <label>Status <select name="status">${TYPE_STATUSES.map((s) => `<option value="${s}" ${s === (t.status || 'draft') ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}</select></label>
        <label>Sales start <input name="sales_start" type="datetime-local" value="${esc((t.sales_start || '').replace(' ', 'T').slice(0, 16))}"></label>
        <label>Sales end <input name="sales_end" type="datetime-local" value="${esc((t.sales_end || '').replace(' ', 'T').slice(0, 16))}"></label>
        <label class="wide">Description <input name="description" value="${esc(t.description || '')}" placeholder="What’s included (optional)"></label>
        <div class="wide form-actions"><button type="submit">${isEdit ? 'Save ticket type' : 'Add ticket type'}</button><button type="button" class="secondary" data-close>Cancel</button>${isEdit ? `<button type="button" class="link danger delete-tier" data-del-tier="${esc(t.id)}">Delete ticket type</button>` : ''}</div>
        <p class="error-text wide" data-error></p>
      </form>`);
    $('input[name="name"]', dialog).focus();
    $('[data-form="tier"]', dialog).addEventListener('submit', (e) => this.saveTier(e, close));
    $('[data-del-tier]', dialog)?.addEventListener('click', () => this.deleteTier(Number(t.id), close));
  }

  // Issue comp tickets in a modal. Kept open after success so the issued codes
  // + QR links stay visible; the panel behind refreshes via load().
  openCompModal() {
    const tiers = this.dash.tiers || [];
    if (!tiers.length) return;
    const { dialog } = this.openModal('Issue comp tickets', `
      <form class="grid-form padded" data-form="comp">
        <label>Ticket type <select name="ticket_type_id" required>${tiers.map((t) => `<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label>
        <label>Quantity <input name="quantity" type="number" min="1" value="1" required></label>
        <label>Holder name <input name="holder_name" placeholder="Optional"></label>
        <label>Holder email <input name="holder_email" type="email" placeholder="Emails QR if set"></label>
        <div class="wide form-actions"><button type="submit">Issue comps</button><button type="button" class="secondary" data-close>Cancel</button></div>
        <div class="comp-result padded" hidden></div>
        <p class="error-text wide" data-error></p>
      </form>`);
    $('select[name="ticket_type_id"]', dialog).focus();
    $('[data-form="comp"]', dialog).addEventListener('submit', (e) => this.issueComps(e));
  }

  // Create a door-scanner link in a modal. The shareable URL + secret are shown
  // once, inside the modal, so the operator can copy it before closing.
  openScannerModal() {
    const { dialog } = this.openModal('New scanner link', `
      <form class="grid-form padded" data-form="scanner">
        <label class="wide">Label <input name="label" placeholder="Front door"></label>
        <label>PIN (optional) <input name="pin" inputmode="numeric" placeholder="e.g. 4821"></label>
        <div class="wide form-actions"><button type="submit">Create link</button><button type="button" class="secondary" data-close>Cancel</button></div>
        <div class="scanner-new padded" hidden></div>
        <p class="error-text wide" data-error></p>
      </form>`);
    $('input[name="label"]', dialog).focus();
    $('[data-form="scanner"]', dialog).addEventListener('submit', (e) => this.createScannerLink(e));
  }

  compSectionHtml() {
    const tiers = this.dash.tiers || [];
    if (!this.editable) return '';
    return `<div class="section-head padded sub-head"><h3>Comp tickets</h3>${tiers.length ? `<button type="button" class="add-toggle" data-add-comp aria-label="Issue comp tickets" title="Issue comp tickets"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>` : ''}</div>
      ${tiers.length ? '' : '<p class="ticket-note padded">Add a ticket type first to issue comps.</p>'}`;
  }

  scannerSectionHtml() {
    const links = this.links;
    const editable = this.editable;
    const body = this.scannerListInner();
    const canManage = editable && links !== null;
    return `<div class="section-head padded sub-head"><h3>Door scanner links</h3>${canManage ? `<button type="button" class="add-toggle" data-add-scanner aria-label="New scanner link" title="New scanner link"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>` : ''}</div>
      <div class="scanner-links padded">${body}</div>`;
  }

  bind() {
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

    // Ticket type: Add opens the editor modal (Edit opens it prefilled below).
    $('[data-add-tier]', this)?.addEventListener('click', () => this.openTierModal(null));

    // Ticket type edit (row action, only if editable). Delete now lives inside
    // the edit modal, so it is wired up in openTierModal().
    $$('[data-edit-tier]', this).forEach((btn) => btn.addEventListener('click', () => this.editTier(Number(btn.dataset.editTier))));

    // Comp: opens the issue-comps modal.
    $('[data-add-comp]', this)?.addEventListener('click', () => this.openCompModal());

    // Issued-ticket row actions
    $$('[data-resend-ticket]', this).forEach((btn) => btn.addEventListener('click', () => this.resendTicket(btn)));
    $$('[data-void-ticket]', this).forEach((btn) => btn.addEventListener('click', () => this.voidTicket(Number(btn.dataset.voidTicket))));

    // Refund all
    $('[data-refund-all]', this)?.addEventListener('click', () => this.refundAll());

    // Scanner links: opens the new-scanner-link modal.
    $('[data-add-scanner]', this)?.addEventListener('click', () => this.openScannerModal());
    this.bindScanner();
  }

  // Wire the per-row scanner actions. Kept separate so it can be re-run after
  // the links list is re-rendered in place (without double-binding the rest).
  bindScanner() {
    $$('[data-revoke-link]', this).forEach((btn) => btn.addEventListener('click', () => this.revokeLink(Number(btn.dataset.revokeLink))));
    $$('[data-show-link]', this).forEach((btn) => btn.addEventListener('click', () => this.toggleScannerReveal(Number(btn.dataset.showLink))));
    $$('[data-gen-link]', this).forEach((btn) => btn.addEventListener('click', () => this.regenerateScannerLink(Number(btn.dataset.genLink))));
  }

  // ── Issued tickets (paid + comp) ───────────────────────────────────────────
  // A flat list of every ticket with its live status and per-row actions:
  // View the QR, resend the link by email, or void (invalidate) it. "Scanned in"
  // means the ticket was already redeemed at the door and won't admit again.
  issuedTicketsHtml() {
    const tickets = this.tickets || [];
    const editable = this.editable;
    const rows = tickets.map((t) => `<tr class="ticket-row ticket-${esc(t.status)}">
      <td data-label="Code">${esc(t.code)}</td>
      <td data-label="Holder">${esc(t.holder_name || '—')}${t.holder_email ? `<br><span class="muted small">${esc(t.holder_email)}</span>` : ''}</td>
      <td data-label="Tier">${esc(t.tier)}${t.is_comp ? ' <span class="comp-badge">comp</span>' : ''}</td>
      <td data-label="Status">${this.ticketStatusBadge(t)}</td>
      <td class="row-actions">
        ${t.url ? `<a class="small secondary" href="${esc(t.url)}" target="_blank" rel="noopener">View</a>` : ''}
        ${editable && t.url && t.holder_email && t.status !== 'void' ? `<button type="button" class="small secondary" data-resend-ticket="${esc(t.id)}">Resend</button>` : ''}
        ${editable && t.status === 'issued' ? `<button type="button" class="small danger" data-void-ticket="${esc(t.id)}">Void</button>` : ''}
      </td>
    </tr>`).join('');
    return `<div class="section-head padded sub-head"><h3>Issued tickets <span class="muted">${tickets.length}</span></h3></div>
      <div class="ticketing-tickets padded">${tickets.length
        ? `<table class="data-table tickets-table"><thead><tr><th>Code</th><th>Holder</th><th>Tier</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
        : '<p class="ticket-note">No tickets issued yet — sold and comped tickets show up here with their QR, status, and resend/void controls.</p>'}</div>`;
  }

  ticketStatusBadge(t) {
    const map = {
      issued:   ['Valid', 'status-published'],
      redeemed: ['Scanned in', 'status-confirmed'],
      void:     ['Void', 'status-canceled'],
    };
    const [label, cls] = map[t.status] || [t.status, ''];
    return `<span class="badge ${cls}">${esc(label)}</span>`;
  }

  async resendTicket(btn) {
    btn.disabled = true;
    try {
      const res = await api(`/events/${this.eventId}/ticketing/tickets/${btn.dataset.resendTicket}`, { method: 'POST' });
      publish('toast.show', { message: `Resent ${res.emailed || 0} ticket(s).` });
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    } finally {
      btn.disabled = false;
    }
  }

  async voidTicket(id) {
    if (!confirm('Void this ticket? It will no longer scan at the door.')) return;
    try {
      await api(`/events/${this.eventId}/ticketing/tickets/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Ticket voided.' });
      await this.load();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
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
        ? 'In-house ticketing on — seeded Advance & Door tiers, a comp allocation, and a “Door” scanner link.'
        : 'Ticketing settings saved.',
    });
    await this.load();
  }

  editTier(id) {
    const tier = (this.dash.tiers || []).find((t) => Number(t.id) === id);
    if (tier) this.openTierModal(tier);
  }

  async saveTier(e, close) {
    e.preventDefault();
    const submit = $('button[type="submit"]', e.target);
    if (submit) submit.disabled = true;
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
    try {
      await api(path, { method: id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: id ? 'Ticket type updated.' : 'Ticket type created.' });
      close?.();
      await this.load();
    } catch (error) {
      const err = $('[data-error]', e.target);
      if (err) err.textContent = error.message || 'Save failed.';
      if (submit) submit.disabled = false;
    }
  }

  async deleteTier(id, close) {
    if (!confirm('Delete this ticket type? Types with issued tickets cannot be deleted.')) return;
    try {
      await api(`/events/${this.eventId}/ticketing/types/${id}`, { method: 'DELETE' });
      if (typeof close === 'function') close();
      publish('toast.show', { message: 'Ticket type deleted.' });
      await this.load();
    } catch (error) {
      publish('toast.show', { tone: 'error', message: error.message });
    }
  }

  async issueComps(e) {
    e.preventDefault();
    const submit = $('button[type="submit"]', e.target);
    if (submit) submit.disabled = true;
    const values = formData(e.target);
    try {
      const res = await api(`/events/${this.eventId}/ticketing/comp`, { method: 'POST', body: JSON.stringify(values) });
      const out = $('.comp-result', e.target);
      if (out) {
        out.hidden = false;
        out.innerHTML = `<p>Issued <strong>${esc(res.issued)}</strong> comp ticket(s)${res.emailed ? `, emailed ${esc(res.emailed)}.` : '.'}</p>
          <div class="comp-codes">${(res.tickets || []).map((t) => `<span class="ticket-code">${esc(t.code)}</span>${t.url ? ` <a class="comp-view" href="${esc(t.url)}" target="_blank" rel="noopener">QR</a>` : ''}`).join('')}</div>`;
      }
      publish('toast.show', { message: `Issued ${res.issued} comp ticket(s).` });
      // New tickets exist now — refresh the panel behind the modal so the
      // issued-tickets list updates. Re-enable so more comps can be issued.
      await this.load();
    } catch (error) {
      const err = $('[data-error]', e.target);
      if (err) err.textContent = error.message || 'Failed to issue comps.';
    } finally {
      if (submit) submit.disabled = false;
    }
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
    const submit = $('button[type="submit"]', e.target);
    if (submit) submit.disabled = true;
    try {
      const res = await api(`/events/${this.eventId}/scanner-links`, { method: 'POST', body: JSON.stringify(formData(e.target)) });
      // The shareable URL + secret are returned once on creation — show them in
      // the modal so the operator can copy before closing.
      const url = res?.url || res?.scanner_url || res?.link;
      const out = $('.scanner-new', e.target);
      if (url && out) {
        out.hidden = false;
        out.innerHTML = `<p class="subtle">Share this link with door staff (shown once):</p>
          <code class="scanner-url">${esc(url)}</code>
          <div class="qr"><img src="${esc(qrSrc(url))}" alt="Scanner link QR" width="160" height="160"></div>`;
      }
      publish('toast.show', { message: 'Scanner link created.' });
      this.links = await this.loadScannerLinks();
      // Refresh the links list region behind the modal in place.
      const region = $('.scanner-links', this);
      if (region) region.innerHTML = this.scannerListInner();
      this.bindScanner();
    } catch (error) {
      const err = $('[data-error]', e.target);
      if (err) err.textContent = error.message || 'Failed to create scanner link.';
    } finally {
      if (submit) submit.disabled = false;
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
//
// Also renders the POS Location Mapping section: maps Square POS terminal
// location IDs to venues so incoming POS webhooks are routed to the right
// event ledger automatically.

class PaymentSettingsPanel extends PanicElement {
  connect() {
    this.load();
  }

  async load() {
    this.setLoading('Loading payment settings');
    try {
      [this.settings, this.posData] = await Promise.all([
        api('/payment-settings'),
        api('/pos-location-map').catch(() => ({ mappings: [], venues: [] })),
      ]);
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const s = this.settings;
    const providers = s.providers || [];
    const env       = s.env       || {};

    this.innerHTML = `<section class="panel" id="payment-settings">
      <div class="section-head padded"><h2>Payment provider ${helpLink('admin-payments', 'Payment providers')}</h2></div>
      <div class="padded">
        <p class="subtle">Select the active processor for in-house ticket checkout.</p>
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
      ${this.envSectionHtml('square', 'Square', env.square || [], s.active_provider)}
      ${this.envSectionHtml('stripe', 'Stripe', env.stripe || [], s.active_provider)}
    </section>
    ${this.posLocationMapHtml()}`;

    // Switch visible env section when the provider select changes.
    const providerSelect = $('select[name="active_provider"]', this);
    providerSelect?.addEventListener('change', () => this.syncEnvSections(providerSelect.value));

    $('form[data-form="payment"]', this).addEventListener('submit', async (e) => {
      e.preventDefault();
      const values = formData(e.target);
      this.settings = await api('/payment-settings', { method: 'PATCH', body: JSON.stringify(values) });
      publish('toast.show', { message: 'Payment settings saved.' });
      this.render();
    });

    this.bindPosLocationMap();
  }

  // ── POS Location Mapping ────────────────────────────────────────────────────

  posLocationMapHtml() {
    const mappings = (this.posData && this.posData.mappings) || [];
    const venues   = (this.posData && this.posData.venues)   || [];

    const venueOptions = venues.map((v) =>
      `<option value="${esc(v.id)}">${esc(v.name)}</option>`
    ).join('');

    const categoryOptions = [
      ['bar_sales',     'Bar Sales'],
      ['merch_share',   'Merch Share'],
      ['other_revenue', 'Other Revenue'],
    ].map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('');

    const tableRows = mappings.length
      ? mappings.map((m) => `
        <tr data-mapping-id="${esc(m.id)}">
          <td data-label="Provider">${esc(titleCase(m.pos_provider))}</td>
          <td data-label="Location ID"><code>${esc(m.location_id)}</code></td>
          <td data-label="Venue">${esc(m.venue_name || m.venue_id)}</td>
          <td data-label="Default category">${esc(titleCase(m.default_category.replace(/_/g, ' ')))}</td>
          <td data-label="Status">${m.is_active ? '<span class="badge status-published">Active</span>' : '<span class="badge status-canceled">Inactive</span>'}</td>
          <td data-label="Notes">${esc(m.notes || '—')}</td>
          <td class="row-actions">
            <button type="button" class="link danger" data-delete-mapping="${esc(m.id)}">Remove</button>
          </td>
        </tr>`).join('')
      : `<tr><td colspan="7" class="empty-state">No POS location mappings yet.</td></tr>`;

    return `<section class="panel" id="pos-location-map" style="margin-top:1.5rem">
      <div class="section-head padded">
        <h2>POS Location Mapping</h2>
        <p class="subtle" style="font-weight:normal;font-size:.9em;margin:.25rem 0 0">
          Maps Square POS terminal locations to venues. When a sale arrives on the
          POS webhook, the location ID is looked up here to determine which venue
          (and which ledger category) to write to.
        </p>
      </div>
      <div class="padded">
        <div style="overflow-x:auto">
          <table class="data-table" id="pos-map-table">
            <thead>
              <tr>
                <th>Provider</th><th>Location ID</th><th>Venue</th>
                <th>Default category</th><th>Status</th><th>Notes</th><th></th>
              </tr>
            </thead>
            <tbody>${tableRows}</tbody>
          </table>
        </div>
      </div>
      <div class="section-head padded sub-head"><h3>Add mapping</h3></div>
      <div class="padded">
        <form class="grid-form" data-form="pos-map-add">
          <label>Square Location ID
            <input name="location_id" required placeholder="e.g. LXXXXXXXXXXXXXXXXX"
              style="font-family:monospace">
          </label>
          <label>Venue
            <select name="venue_id" required>
              <option value="">— select venue —</option>
              ${venueOptions}
            </select>
          </label>
          <label>Default category
            <select name="default_category">
              ${categoryOptions}
            </select>
          </label>
          <label>Notes (optional)
            <input name="notes" placeholder="e.g. main bar terminal">
          </label>
          <div class="form-actions"><button>Add mapping</button></div>
        </form>
      </div>
    </section>`;
  }

  bindPosLocationMap() {
    const addForm = $('form[data-form="pos-map-add"]', this);
    if (addForm) {
      addForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('/pos-location-map', { method: 'POST', body: JSON.stringify(formData(e.target)) });
          publish('toast.show', { message: 'POS location mapping added.' });
          this.posData = await api('/pos-location-map').catch(() => this.posData);
          this.render();
        } catch (err) {
          publish('toast.show', { tone: 'error', message: err.message });
        }
      });
    }

    $$('[data-delete-mapping]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.deleteMapping;
        if (!confirm('Remove this POS location mapping?')) return;
        try {
          await api(`/pos-location-map/${id}`, { method: 'DELETE' });
          publish('toast.show', { message: 'Mapping removed.' });
          this.posData = await api('/pos-location-map').catch(() => this.posData);
          this.render();
        } catch (err) {
          publish('toast.show', { tone: 'error', message: err.message });
        }
      });
    });
  }

  /** Build the collapsible env-var block for one provider. */
  envSectionHtml(providerKey, providerLabel, vars, activeProvider) {
    if (!vars.length) return '';
    const hidden = providerKey !== activeProvider;
    return `
      <div class="env-config-section" data-env-provider="${esc(providerKey)}"${hidden ? ' hidden' : ''}>
        <div class="section-head padded sub-head">
          <h3>${esc(providerLabel)} environment</h3>
          <p class="subtle" style="font-weight:normal;font-size:.85em;margin:.15rem 0 0">
            Read from server <code>.env</code> — edit the file directly to change values.
          </p>
        </div>
        <div class="padded">
          <div class="grid-form">
            ${vars.map((v) => `
              <label>
                ${esc(v.label)} <small class="muted" style="font-weight:normal">${esc(v.key)}</small>
                <input type="text" value="${esc(v.value)}" readonly
                  placeholder="(not set)"
                  style="font-family:monospace;${v.value ? '' : 'opacity:.45'}">
              </label>`).join('')}
          </div>
        </div>
      </div>`;
  }

  /** Show only the env section matching the currently-selected provider. */
  syncEnvSections(activeKey) {
    $$('[data-env-provider]', this).forEach((el) => {
      el.hidden = el.dataset.envProvider !== activeKey;
    });
  }
}


customElements.define('pb-ticketing-admin', TicketingAdmin);
customElements.define('pb-payment-settings', PaymentSettingsPanel);

export { TicketingAdmin, PaymentSettingsPanel };
