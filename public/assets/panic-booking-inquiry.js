/**
 * <panic-booking-inquiry>
 *
 * A self-contained booking-inquiry form, meant to be pasted into a page on
 * a completely different domain — a venue's marketing site, a promoter's
 * landing page, wherever someone needs a "book this space" form without
 * standing up a backend. It renders into a shadow root (unlike
 * public/assets/mab-events-carousel.js, which deliberately renders into
 * light DOM to inherit the host page's theme) so it looks the same
 * regardless of what CSS the host page happens to load — the whole premise
 * here is "drop it in anywhere and it already looks finished."
 *
 * On submit it POSTs JSON straight to Panic Backstage's public, CORS-open
 * intake endpoint (see src/PublicInquiry.php), which files it as a lead in
 * the venue's normal Leads pipeline. No API key, no auth — the endpoint is
 * intentionally public (same trade-off as a "contact us" form) and leans on
 * a honeypot field plus server-side rate limiting instead.
 *
 * Usage (zero config — reads its own <script src> to find the API, exactly
 * like mab-events-carousel.js does):
 *
 *   <panic-booking-inquiry venue="Mabuhay Gardens"></panic-booking-inquiry>
 *   <script src="https://panicbooking.com/backstage/assets/panic-booking-inquiry.js"></script>
 *
 * Attributes (all optional):
 *   endpoint        Full URL to POST to. Defaults to this script's own
 *                    origin's /api/public/inquiries.
 *   venue            Display name used in the default heading/blurb.
 *   heading          Overrides the whole heading text.
 *   blurb            Overrides the helper line under the heading.
 *   button-text      Overrides the submit button label (default "Send inquiry").
 *   theme            "light" (default) or "dark".
 *   accent           Any CSS color; shorthand for --pbi-accent (see Styling below).
 *   event-types      Comma list restricting/reordering the "Event type" options.
 *                    Values: private_event,wedding,corporate,concert,comedy,
 *                    community,fundraiser,other. Unknown values are ignored.
 *   fallback-email   Shown as a mailto link if the request fails outright
 *                    (network error / server unreachable), so a submission
 *                    is never a dead end.
 *   redirect         URL to send the browser to on a successful submit,
 *                    instead of showing the built-in inline thank-you panel.
 *
 * Styling hooks: CSS custom properties set on the element itself (they
 * pierce the shadow boundary by design), see DEFAULT_TOKENS below for the
 * full list — --pbi-accent, --pbi-paper, --pbi-ink, --pbi-radius, etc.
 * Advanced reskins can also target ::part(card|heading|button|error|success).
 *
 * Events (both bubble and cross the shadow boundary — composed: true):
 *   "panic-inquiry-submitted"  detail: {}                     — on success.
 *   "panic-inquiry-error"      detail: { status, message }    — on failure
 *       (status is 0 for a network error, otherwise the HTTP status code).
 */
(function () {
  'use strict';

  const scriptEl = document.currentScript
    || Array.from(document.querySelectorAll('script[src*="panic-booking-inquiry.js"]')).pop();
  const scriptUrl = new URL((scriptEl && scriptEl.src) || location.href, location.href);
  const appBaseUrl = new URL('..', scriptUrl); // assets/panic-booking-inquiry.js -> app root
  const DEFAULT_ENDPOINT = new URL('api/public/inquiries', appBaseUrl).toString();

  const EVENT_TYPES = [
    ['private_event', 'Private party'],
    ['wedding', 'Wedding'],
    ['corporate', 'Corporate event'],
    ['concert', 'Concert / live music'],
    ['comedy', 'Comedy show'],
    ['community', 'Community event'],
    ['fundraiser', 'Fundraiser'],
    ['other', 'Something else'],
  ];

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  const STYLE = `
    :host {
      /* ── Design tokens — override any of these on the element to reskin it. ── */
      --pbi-accent: #7a2036;
      --pbi-accent-ink: #ffffff;
      --pbi-ink: #1c1a1e;
      --pbi-muted: #726e73;
      --pbi-paper: #ffffff;
      --pbi-field: #f5f3f4;
      --pbi-line: #e6e2e4;
      --pbi-success: #1f7a52;
      --pbi-error: #b3261e;
      --pbi-radius: 14px;
      --pbi-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      --pbi-font-display: Georgia, "Iowan Old Style", "Palatino Linotype", "Times New Roman", serif;
      --pbi-font-mono: ui-monospace, "SF Mono", "Fira Code", Consolas, monospace;

      display: block;
      font-family: var(--pbi-font);
      color: var(--pbi-ink);
      -webkit-font-smoothing: antialiased;
    }
    :host([theme="dark"]) {
      --pbi-ink: #f1eef0;
      --pbi-muted: #a79fa4;
      --pbi-paper: #201b1e;
      --pbi-field: #2b2429;
      --pbi-line: #3a3237;
      --pbi-accent: #d9647e;
      --pbi-accent-ink: #241016;
      --pbi-success: #4ade95;
      --pbi-error: #ff8a80;
    }
    *, *::before, *::after { box-sizing: border-box; }

    .card {
      background: var(--pbi-paper);
      border: 1px solid var(--pbi-line);
      border-radius: var(--pbi-radius);
      overflow: hidden;
      box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 12px 32px -16px rgba(0,0,0,.18);
    }
    .bar { height: 4px; background: var(--pbi-accent); }
    .head { padding: 26px 28px 20px; }
    .eyebrow {
      font-family: var(--pbi-font-mono); font-size: .7rem; letter-spacing: .12em;
      text-transform: uppercase; color: var(--pbi-accent); margin: 0 0 10px; font-weight: 600;
    }
    h2 {
      font-family: var(--pbi-font-display); font-weight: 500; margin: 0 0 8px;
      font-size: 1.5rem; line-height: 1.2; color: var(--pbi-ink);
    }
    .blurb { margin: 0; color: var(--pbi-muted); font-size: .92rem; line-height: 1.5; max-width: 46ch; }

    /* Ticket-stub perforation between the header and the form — the one
       signature flourish, kept to a single hairline so it reads as detail
       rather than decoration. */
    .perf {
      height: 1px; margin: 0 28px;
      background-image: radial-gradient(circle at 5px 0.5px, transparent 3px, var(--pbi-line) 3.5px);
      background-size: 10px 1px; background-repeat: repeat-x;
    }

    form { padding: 22px 28px 28px; display: grid; gap: 16px; }
    .row { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
    .field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .field.full { grid-column: 1 / -1; }
    label { font-size: .8rem; font-weight: 600; color: var(--pbi-ink); }
    label .opt { font-weight: 400; color: var(--pbi-muted); }
    label .req { color: var(--pbi-accent); margin-left: 2px; }

    input, select, textarea {
      font: inherit; color: var(--pbi-ink); background: var(--pbi-field);
      border: 1px solid var(--pbi-line); border-radius: calc(var(--pbi-radius) * .4);
      padding: 10px 12px; width: 100%; transition: border-color .15s, box-shadow .15s;
    }
    textarea { resize: vertical; min-height: 96px; line-height: 1.45; }
    input::placeholder, textarea::placeholder { color: var(--pbi-muted); opacity: .8; }
    input:focus, select:focus, textarea:focus {
      outline: none; border-color: var(--pbi-accent);
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--pbi-accent) 22%, transparent);
    }
    input:invalid:not(:placeholder-shown) { border-color: var(--pbi-error); }
    select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23726e73'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }

    /* Honeypot: present in the DOM (real bots that fill every field will
       populate it) but invisible and unreachable to a human — off-canvas
       rather than display:none, since some bots skip display:none fields. */
    .hp {
      position: absolute !important; width: 1px !important; height: 1px !important;
      overflow: hidden !important; clip: rect(0 0 0 0) !important; white-space: nowrap !important;
      left: -9999px !important;
    }

    .actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
    .fine-print { font-family: var(--pbi-font-mono); font-size: .68rem; color: var(--pbi-muted); letter-spacing: .02em; }

    button {
      font: inherit; font-weight: 600; font-size: .88rem; color: var(--pbi-accent-ink);
      background: var(--pbi-accent); border: none; border-radius: calc(var(--pbi-radius) * .4);
      padding: 11px 22px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
      transition: transform .12s, opacity .12s; white-space: nowrap;
    }
    button:hover { transform: translateY(-1px); }
    button:active { transform: translateY(0); }
    button:disabled { opacity: .6; cursor: default; transform: none; }
    .spinner {
      width: 13px; height: 13px; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--pbi-accent-ink) 35%, transparent);
      border-top-color: var(--pbi-accent-ink); animation: spin .7s linear infinite; display: none;
    }
    button[aria-busy="true"] .spinner { display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 1.4s; } button:hover { transform: none; } }

    .banner {
      display: none; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: calc(var(--pbi-radius) * .4);
      font-size: .84rem; line-height: 1.4;
    }
    .banner.show { display: flex; }
    .banner.error { background: color-mix(in srgb, var(--pbi-error) 12%, transparent); color: var(--pbi-error); }
    .banner a { color: inherit; font-weight: 600; }

    .success { padding: 40px 28px 44px; text-align: center; }
    .success .mark {
      width: 46px; height: 46px; margin: 0 auto 16px; border-radius: 50%;
      background: color-mix(in srgb, var(--pbi-success) 16%, transparent);
      display: flex; align-items: center; justify-content: center; color: var(--pbi-success);
    }
    .success h2 { font-size: 1.3rem; }
    .success p { color: var(--pbi-muted); font-size: .92rem; max-width: 40ch; margin: 0 auto 20px; }
    .success button { background: transparent; color: var(--pbi-accent); border: 1px solid var(--pbi-line); }

    .field .count { font-family: var(--pbi-font-mono); font-size: .68rem; color: var(--pbi-muted); text-align: right; }
  `;

  class PanicBookingInquiry extends HTMLElement {
    static get observedAttributes() {
      return ['venue', 'heading', 'blurb', 'button-text', 'accent', 'theme'];
    }

    constructor() {
      super();
      this._root = this.attachShadow({ mode: 'open' });
      this._submitting = false;
      this._submitted = false;
    }

    connectedCallback() {
      if (!this._root.firstChild) {
        this._render();
      }
    }

    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue === newValue) return;
      if (name === 'accent' && newValue) {
        this.style.setProperty('--pbi-accent', newValue);
        return;
      }
      if (this._root.firstChild && !this._submitted) this._render();
    }

    endpoint() {
      return this.getAttribute('endpoint') || DEFAULT_ENDPOINT;
    }

    allowedEventTypes() {
      const raw = this.getAttribute('event-types');
      if (!raw) return EVENT_TYPES;
      const wanted = raw.split(',').map((s) => s.trim()).filter(Boolean);
      const byKey = new Map(EVENT_TYPES);
      return wanted.filter((k) => byKey.has(k)).map((k) => [k, byKey.get(k)]);
    }

    _render() {
      const venue = this.getAttribute('venue') || '';
      const heading = this.getAttribute('heading') || 'Tell us about your event';
      const blurb = this.getAttribute('blurb')
        || (venue
          ? `Send ${esc(venue)} a few details and a real person will follow up — usually within one business day.`
          : 'Send us a few details and a real person will follow up — usually within one business day.');
      const buttonText = this.getAttribute('button-text') || 'Send inquiry';
      const types = this.allowedEventTypes();

      this._root.innerHTML = `
        <style>${STYLE}</style>
        <div class="card" part="card">
          <div class="bar" aria-hidden="true"></div>
          <div class="head">
            <p class="eyebrow">${venue ? esc(venue) + ' · ' : ''}Booking inquiry</p>
            <h2 part="heading">${esc(heading)}</h2>
            <p class="blurb">${blurb}</p>
          </div>
          <div class="perf" aria-hidden="true"></div>
          <form novalidate>
            <div class="row">
              <div class="field">
                <label for="pbi-name">Your name<span class="req" aria-hidden="true">*</span></label>
                <input id="pbi-name" name="contact_name" type="text" autocomplete="name" required maxlength="255" placeholder="Jordan Rivera">
              </div>
              <div class="field">
                <label for="pbi-email">Email<span class="req" aria-hidden="true">*</span></label>
                <input id="pbi-email" name="contact_email" type="email" autocomplete="email" required maxlength="255" placeholder="jordan@example.com">
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="pbi-phone">Phone <span class="opt">(optional)</span></label>
                <input id="pbi-phone" name="contact_phone" type="tel" autocomplete="tel" maxlength="60" placeholder="(555) 555-0100">
              </div>
              <div class="field">
                <label for="pbi-org">Organization <span class="opt">(optional)</span></label>
                <input id="pbi-org" name="contact_org" type="text" autocomplete="organization" maxlength="255" placeholder="Company or band name">
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="pbi-type">Event type</label>
                <select id="pbi-type" name="event_type">
                  ${types.map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('')}
                </select>
              </div>
              <div class="field">
                <label for="pbi-event-name">Event name <span class="opt">(optional)</span></label>
                <input id="pbi-event-name" name="event_name" type="text" maxlength="255" placeholder="What should we call it?">
              </div>
            </div>
            <div class="row">
              <div class="field">
                <label for="pbi-date">Preferred date <span class="opt">(optional)</span></label>
                <input id="pbi-date" name="desired_date" type="date">
              </div>
              <div class="field">
                <label for="pbi-date-alt">Alternate date <span class="opt">(optional)</span></label>
                <input id="pbi-date-alt" name="desired_date_alt" type="date">
              </div>
              <div class="field">
                <label for="pbi-attendance">Estimated guests <span class="opt">(optional)</span></label>
                <input id="pbi-attendance" name="projected_attendance" type="number" min="0" max="100000" inputmode="numeric" placeholder="150">
              </div>
              <div class="field">
                <label for="pbi-budget">Budget <span class="opt">(optional)</span></label>
                <input id="pbi-budget" name="budget" type="number" min="0" max="99999999" step="1" inputmode="numeric" placeholder="5000">
              </div>
            </div>
            <div class="field full">
              <label for="pbi-message">Tell us about your event<span class="req" aria-hidden="true">*</span></label>
              <textarea id="pbi-message" name="message" required maxlength="4000" placeholder="Date flexibility, guest count, catering/bar needs, anything else that helps us plan…"></textarea>
            </div>
            <div class="field hp" aria-hidden="true">
              <label for="pbi-company">Company</label>
              <input id="pbi-company" name="company" type="text" tabindex="-1" autocomplete="off">
            </div>
            <div class="banner error" role="alert" part="error"></div>
            <div class="actions">
              <span class="fine-print">We never share your info.</span>
              <button type="submit" part="button">
                <span class="spinner" aria-hidden="true"></span>
                <span class="label">${esc(buttonText)}</span>
              </button>
            </div>
          </form>
        </div>
      `;

      this._form = this._root.querySelector('form');
      this._banner = this._root.querySelector('.banner.error');
      this._button = this._root.querySelector('button[type="submit"]');
      this._form.addEventListener('submit', (e) => this._onSubmit(e));
    }

    _setBusy(busy) {
      this._submitting = busy;
      this._button.disabled = busy;
      this._button.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    _showError(message) {
      const fallback = this.getAttribute('fallback-email');
      this._banner.innerHTML = esc(message)
        + (fallback ? ` You can also email us directly at <a href="mailto:${esc(fallback)}">${esc(fallback)}</a>.` : '');
      this._banner.classList.add('show');
    }

    _clearError() {
      this._banner.classList.remove('show');
      this._banner.textContent = '';
    }

    async _onSubmit(event) {
      event.preventDefault();
      if (this._submitting) return;
      this._clearError();

      if (!this._form.reportValidity()) return;

      const data = Object.fromEntries(new FormData(this._form).entries());
      this._setBusy(true);

      let res;
      try {
        res = await fetch(this.endpoint(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(data),
        });
      } catch (err) {
        this._setBusy(false);
        this._showError('Couldn’t reach the venue right now — please try again in a moment.');
        this.dispatchEvent(new CustomEvent('panic-inquiry-error', { bubbles: true, composed: true, detail: { status: 0, message: String(err) } }));
        return;
      }

      let payload = {};
      try { payload = await res.json(); } catch (_) { /* non-JSON error page — fall through */ }

      this._setBusy(false);

      if (!res.ok) {
        const message = res.status === 429
          ? 'You’ve sent a few requests recently — please try again shortly.'
          : (payload && payload.error) || 'Something went wrong sending that — please try again.';
        this._showError(message);
        this.dispatchEvent(new CustomEvent('panic-inquiry-error', { bubbles: true, composed: true, detail: { status: res.status, message } }));
        return;
      }

      this._submitted = true;
      const redirect = this.getAttribute('redirect');
      if (redirect) {
        window.location.assign(redirect);
        return;
      }
      this._renderSuccess(data);
      this.dispatchEvent(new CustomEvent('panic-inquiry-submitted', { bubbles: true, composed: true, detail: {} }));
    }

    _renderSuccess(data) {
      const name = (data.contact_name || '').split(' ')[0] || 'there';
      this._root.innerHTML = `
        <style>${STYLE}</style>
        <div class="card" part="card">
          <div class="bar" aria-hidden="true"></div>
          <div class="success" part="success" role="status">
            <div class="mark" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 12.5l5 5L20 6.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h2>Thanks, ${esc(name)}.</h2>
            <p>Your inquiry is in — we read every one personally and reply within a business day, usually to ${esc(data.contact_email || 'the email you gave us')}.</p>
            <button type="button">Send another inquiry</button>
          </div>
        </div>
      `;
      this._root.querySelector('button').addEventListener('click', () => {
        this._submitted = false;
        this._render();
      });
    }
  }

  if (!customElements.get('panic-booking-inquiry')) {
    customElements.define('panic-booking-inquiry', PanicBookingInquiry);
  }
})();
