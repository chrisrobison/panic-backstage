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

    const params = new URLSearchParams(window.location.search);
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
        : `<input type="number" class="tkp-qty-input" data-type="${esc(type.id)}" min="0" max="${max}" step="1" value="0" inputmode="numeric" aria-label="Quantity for ${esc(type.name)}">`;
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
        <h2 class="tkp-title">Tickets</h2>
        <form class="tkp-form" novalidate>
          <ul class="tkp-list">${rows}</ul>
          <div class="tkp-buyer">
            <label>Name <input name="buyer_name" required autocomplete="name" placeholder="Full name"></label>
            <label>Email <input name="buyer_email" type="email" required autocomplete="email" placeholder="you@example.com"></label>
            <label>Phone <input name="buyer_phone" type="tel" autocomplete="tel" placeholder="Optional"></label>
          </div>
          <p class="tkp-error" role="alert" hidden></p>
          <div class="tkp-footer">
            <span class="tkp-total">Total: <strong data-total>—</strong></span>
            <button type="submit" class="button" data-buy disabled>Checkout</button>
          </div>
        </form>
      </section>`;

    this.form = this.querySelector('form');
    this.errorEl = this.querySelector('.tkp-error');
    this.totalEl = this.querySelector('[data-total]');
    this.buyBtn = this.querySelector('[data-buy]');

    this.form.addEventListener('change', () => this.recalc());
    this.form.addEventListener('input', () => this.recalc());
    this.form.addEventListener('submit', (event) => this.checkout(event));
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

  recalc() {
    const { items, totalCents, currency } = this.selectedItems();
    this.totalEl.textContent = items.length ? priceLabel(totalCents, currency) : '—';
    this.buyBtn.disabled = items.length === 0;
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
        body: JSON.stringify({ buyer_name, buyer_email, buyer_phone, items }),
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
