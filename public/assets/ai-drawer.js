import { api, esc, mdToHtml, PanicElement, $ } from './core.js';

/**
 * <pb-ai-drawer> — the AI Assistant drawer (Phase 1: read-only Q&A).
 *
 * A persistent right-side slide-over PANEL, not a modal: openModal()
 * deliberately isn't used here, and unlike the mobile nav drawer
 * (app.js's setupMobileDrawer()) there is no backdrop/scrim element — the
 * rest of the app stays fully visible, scrollable, and clickable while
 * this is open. Only an explicit close button and Escape dismiss it; there
 * is no click-outside-to-dismiss since there's nothing to click outside of.
 * See the `.ai-drawer-*` rules in app.css for the slide mechanics (borrowed
 * from the mobile drawer's translateX/transition approach, not its markup).
 *
 * Mounted once in the app shell (app.js's renderShell()), gated on
 * `capabilities.use_ai_assistant`. Two entry points open the same shared
 * instance:
 *   - the topbar trigger (general — no event scope)
 *   - an event workspace's "Ask AI" button (see event-workspace.js), which
 *     calls open({ eventId }) so "what's the status of this event" works
 *     without the user having to name it.
 */
class AiDrawer extends PanicElement {
  connect() {
    this.isOpen = false;
    this.eventId = null;
    this.conversationId = null;
    this.messages = []; // [{ role: 'user'|'assistant'|'error', content }]
    this.sending = false;
    this.render();

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.isOpen) this.close();
    }, { signal: this.abort.signal });
  }

  /**
   * Open the drawer, optionally (re-)scoping it to an event. Passing a
   * different eventId than the drawer is currently scoped to starts a
   * fresh conversation — the transcript so far belonged to the old scope.
   * Called from app.js (topbar, eventId: null) and event-workspace.js
   * (per-event "Ask AI" button).
   */
  open({ eventId } = {}) {
    if (eventId !== undefined && eventId !== this.eventId) {
      this.eventId = eventId ?? null;
      this.conversationId = null;
      this.messages = [];
      this.renderTranscript();
    }
    this.isOpen = true;
    this.classList.add('ai-drawer-open');
    this.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('[data-ai-input]', this)?.focus(), 60);
  }

  close() {
    this.isOpen = false;
    this.classList.remove('ai-drawer-open');
    this.setAttribute('aria-hidden', 'true');
  }

  render() {
    this.setAttribute('aria-hidden', 'true');
    this.innerHTML = `<div class="ai-drawer-panel" role="dialog" aria-label="AI Assistant" aria-modal="false">
      <div class="ai-drawer-head">
        <strong><i class="fa-solid fa-sparkles" aria-hidden="true"></i> AI Assistant</strong>
        <button type="button" class="ai-drawer-close" data-ai-close aria-label="Close AI Assistant"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="ai-drawer-transcript" data-ai-transcript aria-live="polite"></div>
      <form class="ai-drawer-form" data-ai-form>
        <textarea class="ai-drawer-input" data-ai-input rows="1" placeholder="Ask about this event, or events in general…" aria-label="Message the AI Assistant"></textarea>
        <button type="submit" class="ai-drawer-send" data-ai-send>Send</button>
      </form>
    </div>`;

    $('[data-ai-close]', this).addEventListener('click', () => this.close());

    const textarea = $('[data-ai-input]', this);
    $('[data-ai-form]', this).addEventListener('submit', (event) => {
      event.preventDefault();
      this.send(textarea.value);
    });
    textarea.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.send(textarea.value);
      }
    });
    // Grow the input up to the CSS max-height as the user types a longer question.
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = `${textarea.scrollHeight}px`;
    });

    this.renderTranscript();
  }

  renderTranscript() {
    const el = $('[data-ai-transcript]', this);
    if (!el) return;
    if (!this.messages.length) {
      el.innerHTML = `<p class="ai-drawer-empty">Ask a question about ${this.eventId ? 'this event' : 'an event, or events in general'} — I can look things up, but I can't make changes yet.</p>`;
      return;
    }
    el.innerHTML = this.messages.map((message) => {
      if (message.role === 'pending') {
        return `<div class="ai-msg ai-msg-pending">Thinking…</div>`;
      }
      if (message.role === 'error') {
        return `<div class="ai-msg ai-msg-error">${esc(message.content)}</div>`;
      }
      // Assistant replies render as the app's shared Markdown subset (safe
      // by construction — see core.js's mdToHtml); the user's own message
      // is rendered as plain escaped text.
      const body = message.role === 'assistant' ? mdToHtml(message.content) : esc(message.content);
      return `<div class="ai-msg ai-msg-${esc(message.role)}">${body}</div>`;
    }).join('');
    el.scrollTop = el.scrollHeight;
  }

  async send(rawMessage) {
    const message = (rawMessage || '').trim();
    if (!message || this.sending) return;

    const textarea = $('[data-ai-input]', this);
    textarea.value = '';
    textarea.style.height = 'auto';

    this.messages = [...this.messages, { role: 'user', content: message }, { role: 'pending', content: '' }];
    this.renderTranscript();

    this.sending = true;
    this.setSendingUi(true);

    try {
      const body = { message };
      if (this.conversationId) body.conversation_id = this.conversationId;
      if (this.eventId) body.event_id = this.eventId;
      const data = await api('/ai/ask', { method: 'POST', body: JSON.stringify(body) });
      this.conversationId = data.conversation_id;
      this.messages = [...this.messages.filter((m) => m.role !== 'pending'), { role: 'assistant', content: data.reply }];
    } catch (error) {
      this.messages = [...this.messages.filter((m) => m.role !== 'pending'), { role: 'error', content: error.message || 'Something went wrong asking the AI Assistant.' }];
    } finally {
      this.sending = false;
      this.setSendingUi(false);
      this.renderTranscript();
    }
  }

  setSendingUi(sending) {
    const btn = $('[data-ai-send]', this);
    if (btn) {
      btn.disabled = sending;
      btn.textContent = sending ? 'Thinking…' : 'Send';
    }
    const textarea = $('[data-ai-input]', this);
    if (textarea) textarea.disabled = sending;
  }
}
customElements.define('pb-ai-drawer', AiDrawer);
