// Public ticket purchase web component for the public event page.
//
// <pb-ticket-purchase event-id="123"></pb-ticket-purchase>
//
// Lists currently-buyable ticket types (GET /api/public/tickets/{eventId}),
// lets a visitor pick quantities + enter contact details, then starts a hosted
// checkout (POST /api/public/tickets/{eventId}/checkout) and redirects the
// browser to the returned provider checkout_url.
//
// Fully public: api() attaches a JWT only if one happens to exist; these
// endpoints are unauthenticated and return 200, so no login redirect occurs.
import { api, esc, PanicElement } from './core.js';

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
    await this.load();
  }

  async load() {
    this.setLoading('Loading tickets');
    try {
      const data = await api(`/public/tickets/${encodeURIComponent(this.eventId)}`);
      this.types = Array.isArray(data.ticket_types) ? data.ticket_types : [];
      this.render();
    } catch (error) {
      // A 404 simply means this event does not sell tickets here — stay quiet.
      this.replaceChildren();
    }
  }

  render() {
    if (!this.types || this.types.length === 0) {
      this.replaceChildren();
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
