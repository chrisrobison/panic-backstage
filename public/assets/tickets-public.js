// Public ticket purchase web component for the public event page.
//
// <pb-ticket-purchase event-id="123"></pb-ticket-purchase>
//
// Lists currently-buyable ticket types (GET /api/public/tickets/{eventId}),
// lets a visitor pick quantities + enter contact details, then starts a hosted
// checkout (POST /api/public/tickets/{eventId}/checkout) and redirects the
// browser to the returned provider checkout_url. If every selected ticket is
// free (order total $0), the server skips payment entirely and fulfills the
// order synchronously, returning { free: true, order_id, receipt_token }
// instead of a checkout_url — in that case we jump straight into the same
// receipt polling used for the post-payment return (see below), no redirect.
//
// When the provider bounces the buyer back here with ?checkout=success&
// order=<id>&receipt=<token> in the URL, this component also polls
// GET /api/public/tickets/{eventId}/orders/{orderId}?receipt=<token> until
// the webhook has fulfilled the order, then renders the issued ticket(s) —
// name, type, and a scannable QR — right on the page. Previously the buyer's
// only copy was the confirmation email; this shows it immediately so a
// walk-up/door sale doesn't leave them waiting on their inbox.
//
// Private discount codes are supported but never advertised: the tier list
// above says nothing about them, and the code box is only ever a place to
// confirm a code somebody was already given. A code can also be pre-filled
// from the page URL (?code=EASTBAY30) so an outreach email can link straight
// into a discounted cart. Either way the code is priced by the server
// (POST /discount) against the exact cart, and re-priced server-side at
// checkout — nothing here is trusted to compute money.
//
// Fully public: api() attaches a JWT only if one happens to exist; these
// endpoints are unauthenticated and return 200, so no login redirect occurs.
import { api, esc, PanicElement } from './core.js';

const RECEIPT_POLL_INTERVAL_MS = 2000;
const RECEIPT_POLL_MAX_ATTEMPTS = 15; // ~30s — generous for a webhook round trip

function priceLabel(cents, currency) {
  const amount = (Number(cents || 0) / 100).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  if (Number(cents || 0) === 0) return 'Free';
  return `${currency || 'USD'} ${amount}`;
}

class TicketPurchase extends PanicElement {
  async connect() {
    this.eventId = this.getAttribute('event-id');
    if (!this.eventId) {
      this.replaceChildren();
      return;
    }
    this.qty = {};
    this.receiptHtml = '';

    // Discount state. `discountCode` is what's in the box, `discount` is the
    // server's pricing of it against a specific cart (null until confirmed).
    this.discount = null;
    this.discountBusy = false;
    this.discountRejected = false;

    const params = new URLSearchParams(window.location.search);

    // Pre-filled code from an outreach link. Deliberately NOT scrubbed from
    // the address bar the way the checkout params below are: this one is meant
    // to be shareable and survive a reload.
    this.discountCode = (params.get('code') || '').trim().toUpperCase();

    const checkout = params.get('checkout');
    const orderId = params.get('order');
    const receiptToken = params.get('receipt');
    const isReceiptReturn = checkout === 'success' && orderId && receiptToken;

    if (checkout) {
      // Scrub these from the address bar immediately: they're single-use
      // purchase state, not something worth bookmarking or re-sharing (the
      // receipt token grants read access to the buyer's ticket).
      params.delete('checkout');
      params.delete('order');
      params.delete('receipt');
      const query = params.toString();
      history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    }

    if (isReceiptReturn) {
      this.receiptHtml = this.receiptPendingHtml();
    }

    await this.load();

    if (isReceiptReturn) {
      await this.pollReceipt(orderId, receiptToken);
    }
  }

  async load() {
    this.setLoading('Loading tickets');
    try {
      const data = await api(`/public/tickets/${encodeURIComponent(this.eventId)}`);
      this.types = Array.isArray(data.ticket_types) ? data.ticket_types : [];
      this.render();
    } catch (error) {
      // A 404 simply means this event does not sell tickets here — stay quiet,
      // unless we still have a purchase receipt to show from before this call.
      if (this.receiptHtml) {
        this.innerHTML = this.receiptHtml;
      } else {
        this.replaceChildren();
      }
    }
  }

  /** Poll the just-completed order until fulfilled, then render its ticket(s). */
  async pollReceipt(orderId, receiptToken) {
    for (let attempt = 0; attempt < RECEIPT_POLL_MAX_ATTEMPTS; attempt++) {
      let data;
      try {
        data = await api(
          `/public/tickets/${encodeURIComponent(this.eventId)}/orders/${encodeURIComponent(orderId)}`
          + `?receipt=${encodeURIComponent(receiptToken)}`
        );
      } catch (error) {
        // A 404 on the very first attempt means the order/receipt pair itself
        // is invalid (bad or tampered link) — that will never resolve, so say
        // so plainly rather than telling the visitor to wait on an email that
        // isn't coming. A failure on a later attempt (after we'd already
        // gotten at least one valid response) is more likely a transient
        // network blip mid-poll, so fall through to the generic timeout
        // message instead of a false "not found".
        if (attempt === 0) {
          this.receiptHtml = this.receiptNotFoundHtml();
          this.render();
          return;
        }
        break;
      }

      if (data && data.status === 'fulfilled') {
        this.receiptHtml = this.receiptReadyHtml(data.tickets || []);
        this.render();
        return;
      }
      if (data && ['canceled', 'refunded', 'expired'].includes(data.status)) {
        this.receiptHtml = this.receiptFailedHtml();
        this.render();
        return;
      }

      await new Promise((resolve) => setTimeout(resolve, RECEIPT_POLL_INTERVAL_MS));
    }

    // Timed out — payment succeeded (we only got here via the success
    // redirect) but fulfillment is still pending, most likely a slow webhook.
    // The confirmation email will still arrive once it lands.
    this.receiptHtml = this.receiptTimeoutHtml();
    this.render();
  }

  receiptPendingHtml() {
    return `
      <section class="tkp-receipt" aria-live="polite">
        <h2 class="tkp-title">Payment received</h2>
        <p>Issuing your ticket…</p>
      </section>`;
  }

  receiptNotFoundHtml() {
    return `
      <section class="tkp-receipt" aria-live="polite">
        <h2 class="tkp-title">We couldn't find that order</h2>
        <p>This purchase link looks incomplete or expired. If you were charged, check your email for your ticket, or ask the venue to look up your order.</p>
      </section>`;
  }

  receiptTimeoutHtml() {
    return `
      <section class="tkp-receipt" aria-live="polite">
        <h2 class="tkp-title">Payment received</h2>
        <p>Your ticket is still being issued — check your email in a moment for your QR code.</p>
      </section>`;
  }

  receiptFailedHtml() {
    return `
      <section class="tkp-receipt" aria-live="polite">
        <h2 class="tkp-title">Order not completed</h2>
        <p>This order was canceled or refunded. If you were charged and don't see why, contact the venue.</p>
      </section>`;
  }

  receiptReadyHtml(tickets) {
    if (!tickets.length) {
      return `
        <section class="tkp-receipt" aria-live="polite">
          <h2 class="tkp-title">Payment received</h2>
          <p>Check your email for your ticket QR code.</p>
        </section>`;
    }
    const cards = tickets.map((t) => `
      <li class="tkp-ticket-card">
        <img class="tkp-ticket-qr" src="${esc(t.qr_url)}" alt="Scannable QR code for ${esc(t.ticket_type_name)}" width="160" height="160">
        <div class="tkp-ticket-meta">
          <strong>${esc(t.ticket_type_name)}</strong>
          ${t.holder_name ? `<span>${esc(t.holder_name)}</span>` : ''}
          <span class="tkp-ticket-code">${esc(t.code)}</span>
          <a href="${esc(t.ticket_url)}">Open full ticket</a>
        </div>
      </li>`).join('');
    return `
      <section class="tkp-receipt" aria-live="polite">
        <h2 class="tkp-title">You're in! 🎟️</h2>
        <p>Show this QR code at the door. A copy has also been emailed to you.</p>
        <ul class="tkp-ticket-list">${cards}</ul>
      </section>`;
  }

  render() {
    if (!this.types || this.types.length === 0) {
      this.innerHTML = this.receiptHtml || '';
      return;
    }

    const rows = this.types.map((type) => {
      const soldOut = type.sold_out || type.available <= 0;
      const max = Math.min(type.available, 20);
      const select = soldOut
        ? '<span class="tkp-soldout">Sold out</span>'
        : `<div class="tkp-stepper">
             <button type="button" class="tkp-step" data-step="-1" data-type="${esc(type.id)}" aria-label="Decrease quantity for ${esc(type.name)}">&minus;</button>
             <input type="number" class="tkp-qty-input" data-type="${esc(type.id)}" min="0" max="${max}" step="1" value="0" inputmode="numeric" aria-label="Quantity for ${esc(type.name)}">
             <button type="button" class="tkp-step" data-step="1" data-type="${esc(type.id)}" aria-label="Increase quantity for ${esc(type.name)}">+</button>
           </div>`;
      return `
        <li class="tkp-row${soldOut ? ' tkp-row-out' : ''}">
          <div class="tkp-info">
            <span class="tkp-name">${esc(type.name)}</span>
            ${type.description ? `<span class="tkp-desc">${esc(type.description)}</span>` : ''}
          </div>
          <span class="tkp-price">${esc(priceLabel(type.price_cents, type.currency))}</span>
          <span class="tkp-qty">${select}</span>
        </li>`;
    }).join('');

    this.innerHTML = `
      ${this.receiptHtml || ''}
      <section class="tkp">
        <h2 class="tkp-title">Get Tickets</h2>
        <p class="tkp-list-label">Ticket Type</p>
        <form class="tkp-form" novalidate>
          <ul class="tkp-list">${rows}</ul>
          <div class="tkp-discount">
            <div class="tkp-discount-row">
              <input type="text" data-code placeholder="Discount code" aria-label="Discount code"
                     value="${esc(this.discountCode || '')}" autocomplete="off" spellcheck="false"
                     autocapitalize="characters">
              <button type="button" class="tkp-code-apply" data-apply-code>Apply</button>
            </div>
            <p class="tkp-discount-note" data-code-note hidden></p>
          </div>
          <div class="tkp-buyer">
            <label>Name <input name="buyer_name" required autocomplete="name" placeholder="Full name"></label>
            <label>Email <input name="buyer_email" type="email" required autocomplete="email" placeholder="you@example.com"></label>
            <label>Phone <input name="buyer_phone" type="tel" autocomplete="tel" placeholder="Optional"></label>
          </div>
          <p class="tkp-error" role="alert" hidden></p>
          <div class="tkp-footer">
            <span class="tkp-total">Total: <strong data-total>—</strong></span>
            <button type="submit" class="button" data-buy disabled>Select tickets</button>
          </div>
          <p class="tkp-secure"><i class="fa-solid fa-lock" aria-hidden="true"></i> Secure checkout by Mabuhay Booking</p>
        </form>
      </section>`;

    this.form = this.querySelector('form');
    this.errorEl = this.querySelector('.tkp-error');
    this.totalEl = this.querySelector('[data-total]');
    this.buyBtn = this.querySelector('[data-buy]');
    this.codeEl = this.querySelector('[data-code]');
    this.codeNoteEl = this.querySelector('[data-code-note]');

    this.form.addEventListener('change', () => this.recalc());
    this.form.addEventListener('input', () => this.recalc());
    this.form.addEventListener('submit', (event) => this.checkout(event));

    this.codeEl.addEventListener('input', () => {
      const next = this.codeEl.value.trim().toUpperCase();
      if (next === this.discountCode) return;
      // Editing the code invalidates whatever was applied, and clears the
      // "already rejected" latch so the new value gets a fresh attempt.
      this.discountCode = next;
      this.discount = null;
      this.discountRejected = false;
      this.showCodeNote('');
    });
    // Enter in the code box means "apply this code", not "submit the order".
    this.codeEl.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        this.applyCode();
      }
    });
    this.querySelector('[data-apply-code]').addEventListener('click', () => this.applyCode());
    this.addEventListener('click', (event) => {
      if (event.target.closest('[data-clear-code]')) {
        event.preventDefault();
        this.clearCode();
      }
    });

    this.renderCodeState();
    this.querySelectorAll('.tkp-step').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = this.querySelector(`input.tkp-qty-input[data-type="${btn.dataset.type}"]`);
        if (!input) return;
        const max = Number(input.max) || 20;
        const next = Math.max(0, Math.min(max, (Number(input.value) || 0) + Number(btn.dataset.step)));
        input.value = next;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
    this.recalc();
  }

  selectedItems() {
    const items = [];
    let totalCents = 0;
    this.querySelectorAll('input[data-type]').forEach((sel) => {
      const max = Number(sel.max) || 20;
      const qty = Math.max(0, Math.min(Math.floor(Number(sel.value) || 0), max));
      if (qty <= 0) return;
      const id = Number(sel.getAttribute('data-type'));
      const type = (this.types || []).find((t) => Number(t.id) === id);
      if (!type) return;
      items.push({ ticket_type_id: id, quantity: qty });
      totalCents += qty * Number(type.price_cents || 0);
    });
    return { items, totalCents, currency: (this.types[0] && this.types[0].currency) || 'USD' };
  }

  /**
   * Identity of the current cart. A discount is priced by the server against
   * one specific cart, so this is what tells us the quote has gone stale.
   */
  cartSignature(items) {
    return items
      .map((i) => `${i.ticket_type_id}x${i.quantity}`)
      .sort()
      .join(',');
  }

  recalc() {
    const { items, totalCents, currency } = this.selectedItems();
    const signature = this.cartSignature(items);

    const applied = this.discount && this.discount.signature === signature ? this.discount : null;
    const dueCents = Math.max(0, totalCents - (applied ? applied.discount_cents : 0));
    const total = items.length ? priceLabel(dueCents, currency) : '—';

    this.totalEl.textContent = total;
    this.buyBtn.textContent = items.length ? `Get Tickets — ${total}` : 'Select tickets';
    this.buyBtn.disabled = items.length === 0;

    // Re-price when the cart moves under an applied code, and auto-apply a
    // code that arrived pre-filled as soon as there's a cart to price it
    // against. `discountRejected` stops a bad code retrying on every keystroke.
    const stale = this.discount && this.discount.signature !== signature;
    const pending = !this.discount && this.discountCode && !this.discountRejected;
    if (items.length > 0 && !this.discountBusy && (stale || pending)) {
      this.refreshDiscount();
    }
  }

  /** Explicit "Apply" — always re-asks the server, even for a rejected code. */
  applyCode() {
    this.discountCode = this.codeEl.value.trim().toUpperCase();
    this.discountRejected = false;
    this.discount = null;
    if (!this.discountCode) {
      this.showCodeNote('');
      return;
    }
    const { items } = this.selectedItems();
    if (items.length === 0) {
      this.showCodeNote('Select your tickets first, then apply the code.', 'pending');
      return;
    }
    this.refreshDiscount();
  }

  clearCode() {
    this.discountCode = '';
    this.discount = null;
    this.discountRejected = false;
    if (this.codeEl) this.codeEl.value = '';
    this.showCodeNote('');
    this.recalc();
  }

  /** Ask the server to price the current code against the current cart. */
  async refreshDiscount() {
    const code = this.discountCode;
    const { items } = this.selectedItems();
    if (!code || items.length === 0) return;

    this.discountBusy = true;
    this.showCodeNote('Checking code…', 'pending');
    try {
      const result = await api(`/public/tickets/${encodeURIComponent(this.eventId)}/discount`, {
        method: 'POST',
        body: JSON.stringify({
          code,
          items,
          buyer_email: (this.form && this.form.buyer_email.value.trim()) || '',
        }),
      });
      this.discount = { ...result, signature: this.cartSignature(items) };
      this.renderCodeState();
    } catch (error) {
      this.discount = null;
      // Latch the failure so recalc() doesn't re-ask on every keystroke — the
      // Apply button and any edit to the code both clear it.
      this.discountRejected = true;
      this.showCodeNote(error.message || 'That code could not be applied.', 'error');
    } finally {
      this.discountBusy = false;
      this.recalc();
    }
  }

  /** Reflect the applied discount (if any) in the note under the code box. */
  renderCodeState() {
    if (!this.discount) {
      this.showCodeNote('');
      return;
    }
    const saved = priceLabel(this.discount.discount_cents, this.discount.currency);
    this.showCodeNote(
      `${this.discount.code} applied — ${this.discount.description}. You save ${saved}.`,
      'ok'
    );
  }

  showCodeNote(message, kind) {
    if (!this.codeNoteEl) return;
    if (!message) {
      this.codeNoteEl.hidden = true;
      this.codeNoteEl.innerHTML = '';
      return;
    }
    const remove = kind === 'ok'
      ? ' <button type="button" class="tkp-code-clear" data-clear-code>Remove</button>'
      : '';
    this.codeNoteEl.className = `tkp-discount-note tkp-discount-${kind || 'pending'}`;
    this.codeNoteEl.innerHTML = esc(message) + remove;
    this.codeNoteEl.hidden = false;
  }

  showError(message) {
    if (!this.errorEl) return;
    this.errorEl.textContent = message;
    this.errorEl.hidden = !message;
  }

  async checkout(event) {
    event.preventDefault();
    this.showError('');

    const { items } = this.selectedItems();
    if (items.length === 0) {
      this.showError('Select at least one ticket.');
      return;
    }
    const buyer_name = this.form.buyer_name.value.trim();
    const buyer_email = this.form.buyer_email.value.trim();
    const buyer_phone = this.form.buyer_phone.value.trim();
    if (!buyer_name) {
      this.showError('Please enter your name.');
      return;
    }
    if (!buyer_email) {
      this.showError('Please enter a valid email address.');
      return;
    }

    this.buyBtn.disabled = true;
    const original = this.buyBtn.textContent;
    this.buyBtn.textContent = 'Starting checkout…';

    try {
      const result = await api(`/public/tickets/${encodeURIComponent(this.eventId)}/checkout`, {
        method: 'POST',
        body: JSON.stringify({
          buyer_name,
          buyer_email,
          buyer_phone,
          items,
          // Send the raw code, not the previewed figure: the server re-prices
          // it against this cart and this buyer, so a stale or tampered quote
          // can never decide what someone is charged.
          discount_code: this.discountCode || '',
        }),
      });
      if (result && result.checkout_url) {
        window.location.href = result.checkout_url;
        return;
      }
      if (result && result.free && result.order_id && result.receipt_token) {
        // Free order: already fulfilled server-side, no payment step and no
        // redirect to bounce through. Reuse the same receipt UI/poll as a
        // post-checkout return — it will resolve on the very first poll since
        // fulfillment already happened synchronously.
        this.receiptHtml = this.receiptPendingHtml();
        this.render();
        await this.pollReceipt(result.order_id, result.receipt_token);
        return;
      }
      this.showError('Could not start checkout. Please try again.');
    } catch (error) {
      this.showError(error.message || 'Could not start checkout. Please try again.');
      // Inventory may have shifted; refresh availability so quantities re-clamp.
      await this.load();
      return;
    } finally {
      this.buyBtn.disabled = false;
      this.buyBtn.textContent = original;
    }
  }
}

customElements.define('pb-ticket-purchase', TicketPurchase);

export { TicketPurchase };
