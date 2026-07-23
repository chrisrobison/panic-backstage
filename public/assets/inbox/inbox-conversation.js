// <pb-inbox-conversation> — the Conversation tab: thread + composer.
// Owns its own API calls (messages, drafts, presence) for the lead it's
// given via `.data = { leadId, lead }`, and bubbles `inbox-message-sent` so
// the shell can refresh the list/detail panel without this component
// needing to know about either.
//
// Duplicate-reply prevention: every send includes `based_on_message_id`
// (the last message id this composer had loaded). If the server reports a
// newer message arrived since, the send is rejected (409) and the composer
// shows a "review the new message" banner instead of silently sending.
import { esc, api, publish, PanicElement, $ } from '../core.js';
import { relativeTime, timeOfDay } from './inbox-shared.js';

const PRESENCE_INTERVAL_MS = 10000;
const POLL_INTERVAL_MS = 4000;

class InboxConversation extends PanicElement {
  set data(value) {
    const changed = !this._data || this._data.leadId !== value?.leadId;
    this._data = value || {};
    if (changed) this.bootstrap();
  }
  get data() { return this._data || {}; }

  connect() {
    this.messages = [];
    this.presence = [];
    this.mode = 'reply';
    this.latestMessageId = null;
    this.staleWarning = null;
    this.render();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    clearInterval(this._presenceTimer);
    clearInterval(this._pollTimer);
  }

  async bootstrap() {
    clearInterval(this._presenceTimer);
    clearInterval(this._pollTimer);
    this.staleWarning = null;
    await this.loadMessages();
    await this.loadDraft();
    this.heartbeat('viewing');
    this._presenceTimer = setInterval(() => this.pollPresence(), PRESENCE_INTERVAL_MS);
    this._pollTimer = setInterval(() => this.loadMessages(true), POLL_INTERVAL_MS);
  }

  async loadMessages(silent = false) {
    const { leadId } = this.data;
    if (!leadId) return;
    try {
      const res = await api(`/leads/${leadId}/messages`);
      const incoming = res.messages || [];
      const newLatest = incoming.length ? incoming[incoming.length - 1].id : null;
      if (!silent || newLatest !== this.latestMessageId) {
        this.messages = incoming;
        this.latestMessageId = newLatest;
        this.render();
        this.scrollToBottom();
      }
    } catch (err) {
      if (!silent) publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async loadDraft() {
    const { leadId } = this.data;
    try {
      const res = await api(`/leads/${leadId}/drafts`);
      this.draft = res.draft || null;
    } catch { this.draft = null; }
  }

  async heartbeat(state) {
    const { leadId } = this.data;
    if (!leadId) return;
    try { await api(`/leads/${leadId}/presence`, { method: 'POST', body: JSON.stringify({ state }) }); } catch { /* best-effort */ }
  }

  async pollPresence() {
    const { leadId } = this.data;
    if (!leadId) return;
    try {
      const res = await api(`/leads/${leadId}/presence`);
      this.presence = res.presence || [];
      this.renderPresenceBanner();
    } catch { /* best-effort */ }
  }

  render() {
    const textarea = $('textarea', this)?.value;
    this.innerHTML = `
      <div class="ib-conversation">
        <div class="ib-presence-banner" data-presence-banner hidden></div>
        ${this.staleWarning ? `
          <div class="ib-stale-warning">
            <span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> A new message arrived on this thread. Review it before sending.</span>
            <button type="button" class="small" data-dismiss-stale>Refresh</button>
          </div>` : ''}
        <div class="ib-thread" data-thread>
          ${this.messages.length ? this.messages.map((m) => this.messageHtml(m)).join('') : '<div class="empty-state padded">No messages yet.</div>'}
        </div>
        <div class="ib-composer">
          <div class="ib-composer-modes">
            <button type="button" class="${this.mode === 'reply' ? 'active' : ''}" data-mode="reply"><i class="fa-regular fa-envelope" aria-hidden="true"></i> Reply</button>
            <button type="button" class="${this.mode === 'internal_note' ? 'active' : ''}" data-mode="internal_note"><i class="fa-regular fa-comment" aria-hidden="true"></i> Internal Note</button>
          </div>
          <textarea placeholder="${this.mode === 'internal_note' ? 'Write an internal note (never sent to the customer)...' : 'Write a message...'}" data-body>${esc(textarea || this.draft?.body_text || '')}</textarea>
          <div class="ib-composer-actions">
            <div class="ib-composer-tools">
              <button type="button" title="Attach file"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></button>
              <button type="button" title="Insert template"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></button>
            </div>
            <button type="button" class="button" data-send>${this.mode === 'internal_note' ? 'Add Note' : 'Send'}</button>
          </div>
        </div>
      </div>`;

    this.bind();
    this.renderPresenceBanner();
  }

  bind() {
    $$('[data-mode]', this).forEach((btn) => btn.addEventListener('click', () => { this.mode = btn.dataset.mode; this.render(); }));
    $('[data-send]', this)?.addEventListener('click', () => this.send());
    $('[data-dismiss-stale]', this)?.addEventListener('click', () => { this.staleWarning = null; this.loadMessages(); });
    const textarea = $('[data-body]', this);
    textarea?.addEventListener('input', () => {
      this.heartbeatDraftingDebounced();
      this.saveDraftDebounced(textarea.value);
    });
  }

  heartbeatDraftingDebounced() {
    clearTimeout(this._draftingTimer);
    this.heartbeat('drafting');
    this._draftingTimer = setTimeout(() => this.heartbeat('viewing'), 15000);
  }

  saveDraftDebounced(bodyText) {
    clearTimeout(this._saveDebounce);
    this._saveDebounce = setTimeout(async () => {
      const { leadId } = this.data;
      try {
        await api(`/leads/${leadId}/drafts`, {
          method: 'POST',
          body: JSON.stringify({ kind: this.mode === 'internal_note' ? 'note' : 'reply', body_text: bodyText, based_on_message_id: this.latestMessageId }),
        });
      } catch { /* best-effort */ }
    }, 800);
  }

  async send() {
    const { leadId } = this.data;
    const textarea = $('[data-body]', this);
    const bodyText = (textarea?.value || '').trim();
    if (!bodyText) return;

    const direction = this.mode === 'internal_note' ? 'internal_note' : 'outbound';
    try {
      await api(`/leads/${leadId}/messages`, {
        method: 'POST',
        body: JSON.stringify({ direction, body_text: bodyText, based_on_message_id: this.latestMessageId }),
      });
      this.staleWarning = null;
      await this.loadMessages();
      publish('toast.show', { message: direction === 'outbound' ? 'Reply sent.' : 'Note added.' });
      this.dispatchEvent(new CustomEvent('inbox-message-sent', { bubbles: true, detail: { leadId } }));
    } catch (err) {
      if (String(err.message || '').toLowerCase().includes('new message')) {
        this.staleWarning = true;
        this.render();
      } else {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }
  }

  messageHtml(m) {
    const via = m.channel === 'email' ? 'via Email' : m.channel === 'manual' ? 'via Website Form' : '';
    return `
      <div class="ib-msg ${esc(m.direction)}">
        <div class="ib-msg-head">
          <span class="name">${esc(m.direction === 'outbound' ? (m.sent_by_name || 'You') : (m.from_name || 'Contact'))}</span>
          <span>${esc(timeOfDay(m.created_at))}</span>
          ${via ? `<span class="via">${esc(via)}</span>` : ''}
        </div>
        <div class="ib-msg-body">${esc(m.body_text || '')}</div>
      </div>`;
  }

  renderPresenceBanner() {
    const el = $('[data-presence-banner]', this);
    if (!el) return;
    const drafting = (this.presence || []).find((p) => p.state === 'drafting');
    if (drafting) {
      el.hidden = false;
      el.textContent = `${drafting.name} is currently drafting a reply.`;
    } else {
      el.hidden = true;
    }
  }

  scrollToBottom() {
    const thread = $('[data-thread]', this);
    if (thread) thread.scrollTop = thread.scrollHeight;
  }
}

function $$(sel, root) { return Array.from(root.querySelectorAll(sel)); }

customElements.define('pb-inbox-conversation', InboxConversation);
