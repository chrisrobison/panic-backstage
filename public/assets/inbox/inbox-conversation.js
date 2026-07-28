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
import { esc, api, publish, openModal, PanicElement, $ } from '../core.js';
import { relativeTime, timeOfDay } from './inbox-shared.js';

const PRESENCE_INTERVAL_MS = 10000;
const POLL_INTERVAL_MS = 4000;
const WEB_URL_PATTERN = /\b(?:https?:\/\/|www\.)[^\s<>"']+/gi;

function stripTrailingUrlPunctuation(candidate) {
  let url = candidate;
  let suffix = '';
  while (/[.,!?;:]$/.test(url)) {
    suffix = url.slice(-1) + suffix;
    url = url.slice(0, -1);
  }
  for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']]) {
    while (url.endsWith(close) && url.split(close).length > url.split(open).length) {
      suffix = close + suffix;
      url = url.slice(0, -1);
    }
  }
  return { url, suffix };
}

export function linkifyMessageText(value) {
  const text = String(value || '');
  let html = '';
  let cursor = 0;

  for (const match of text.matchAll(WEB_URL_PATTERN)) {
    const start = match.index ?? 0;
    const { url, suffix } = stripTrailingUrlPunctuation(match[0]);
    html += esc(text.slice(cursor, start));
    if (url) {
      const href = url.toLowerCase().startsWith('www.') ? `https://${url}` : url;
      html += `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${esc(url)}</a>${esc(suffix)}`;
    } else {
      html += esc(match[0]);
    }
    cursor = start + match[0].length;
  }

  return html + esc(text.slice(cursor));
}

export function safeEmailDocument(value) {
  const document = new DOMParser().parseFromString(String(value || ''), 'text/html');

  document.querySelectorAll(
    'script, iframe, frame, frameset, object, embed, form, input, button, textarea, select, option, '
    + 'meta, base, link, svg, math'
  ).forEach((element) => element.remove());

  document.querySelectorAll('*').forEach((element) => {
    for (const attribute of [...element.attributes]) {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('on') || name === 'srcdoc' || name === 'formaction') {
        element.removeAttribute(attribute.name);
      }
    }

    if (element instanceof HTMLAnchorElement) {
      const href = element.getAttribute('href')?.trim() || '';
      if (!/^(https?:|mailto:|tel:)/i.test(href)) {
        element.removeAttribute('href');
      } else {
        element.target = '_blank';
        element.rel = 'noopener noreferrer';
      }
    }

    if (element instanceof HTMLImageElement) {
      const src = element.getAttribute('src')?.trim() || '';
      const isSafeInlineImage = /^data:image\/(?:png|gif|jpe?g|webp);/i.test(src);
      element.removeAttribute('srcset');
      if (!isSafeInlineImage) {
        const alt = element.getAttribute('alt')?.trim() || '';
        if (alt) {
          const replacement = document.createElement('span');
          replacement.className = 'email-image-placeholder';
          replacement.textContent = `[Image: ${alt}]`;
          element.replaceWith(replacement);
        } else {
          element.remove();
        }
      }
    }
  });

  const emailStyles = [...document.head.querySelectorAll('style')].map((style) => style.outerHTML).join('');
  return `<!doctype html>
    <html><head>
      <meta charset="utf-8">
      <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'">
      ${emailStyles}
      <style>
        :root { color-scheme: light; }
        html, body { margin: 0; padding: 0; max-width: 100%; overflow-wrap: anywhere; }
        body { padding: 14px; font: 14px/1.5 Arial, Helvetica, sans-serif; color: #222; background: #fff; }
        table { max-width: 100% !important; }
        img { max-width: 100% !important; height: auto !important; }
        a { color: #1769aa; }
        .email-image-placeholder { display: inline-block; color: #777; font-size: 12px; }
      </style>
    </head><body>${document.body.innerHTML}</body></html>`;
}

class InboxConversation extends PanicElement {
  set data(value) {
    const changed = !this._data || this._data.leadId !== value?.leadId;
    this._data = value || {};
    if (changed) {
      this.pendingAttachments = [];
      this.bootstrap();
    }
  }
  get data() { return this._data || {}; }

  connect() {
    this.messages = [];
    this.presence = [];
    this.mode = 'reply';
    this.workflowAction = '';
    this.subject = '';
    this.latestMessageId = null;
    this.staleWarning = null;
    this.pendingAttachments = [];
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
    const subject = $('[data-subject]', this)?.value;
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
          ${this.mode === 'reply' ? `<input type="text" class="ib-composer-subject" data-subject aria-label="Email subject" placeholder="Subject" value="${esc(subject || this.subject || this.draft?.subject || '')}">` : ''}
          <textarea placeholder="${this.mode === 'internal_note' ? 'Write an internal note (never sent to the customer)...' : 'Write a message...'}" data-body>${esc(textarea || this.draft?.body_text || '')}</textarea>
          ${this.mode === 'reply' && this.pendingAttachments.length ? `<div class="ib-composer-attachments">${this.pendingAttachments.map((file) => `
            <span><i class="fa-solid fa-paperclip" aria-hidden="true"></i>${esc(file.filename)}
              <button type="button" data-remove-attachment="${file.id}" title="Remove attachment" aria-label="Remove ${esc(file.filename)}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </span>`).join('')}</div>` : ''}
          <div class="ib-composer-actions">
            <div class="ib-composer-tools">
              ${this.mode === 'reply' ? `
                <button type="button" title="Attach file" data-attach><i class="fa-solid fa-paperclip" aria-hidden="true"></i></button>
                <input type="file" data-file-input hidden>
                <button type="button" title="Insert template" data-template><i class="fa-solid fa-file-lines" aria-hidden="true"></i></button>` : ''}
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
    $('[data-attach]', this)?.addEventListener('click', () => $('[data-file-input]', this)?.click());
    $('[data-file-input]', this)?.addEventListener('change', (e) => this.uploadFile(e.target.files?.[0]));
    $('[data-template]', this)?.addEventListener('click', () => this.openTemplatePicker());
    $$('[data-remove-attachment]', this).forEach((button) => button.addEventListener('click', () => {
      this.pendingAttachments = this.pendingAttachments.filter((file) => Number(file.id) !== Number(button.dataset.removeAttachment));
      this._sendKey = null;
      this.render();
    }));
    $('[data-dismiss-stale]', this)?.addEventListener('click', () => { this.staleWarning = null; this.loadMessages(); });
    const textarea = $('[data-body]', this);
    textarea?.addEventListener('input', () => {
      this._sendKey = null;
      this.heartbeatDraftingDebounced();
      this.saveDraftDebounced(textarea.value);
    });
    $('[data-subject]', this)?.addEventListener('input', () => {
      this._sendKey = null;
      this.saveDraftDebounced(textarea?.value || '');
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
        const subject = $('[data-subject]', this)?.value || '';
        await api(`/leads/${leadId}/drafts`, {
          method: 'POST',
          body: JSON.stringify({
            kind: this.mode === 'internal_note' ? 'note' : 'reply',
            subject,
            body_text: bodyText,
            based_on_message_id: this.latestMessageId,
          }),
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
    const subject = $('[data-subject]', this)?.value?.trim() || '';
    const idempotencyKey = direction === 'outbound'
      ? (this._sendKey ||= (crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`))
      : null;
    try {
      await api(`/leads/${leadId}/messages`, {
        method: 'POST',
        body: JSON.stringify({
          direction,
          subject,
          body_text: bodyText,
          based_on_message_id: this.latestMessageId,
          idempotency_key: idempotencyKey,
          workflow_action: this.workflowAction || null,
          attachment_ids: this.pendingAttachments.map((file) => file.id),
        }),
      });
      this._sendKey = null;
      this.workflowAction = '';
      this.subject = '';
      this.pendingAttachments = [];
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
    const via = m.direction === 'internal_note'
      ? 'Internal Note'
      : m.channel === 'email' ? 'via Email' : m.channel === 'manual' ? 'via Website Form' : '';
    const failed = m.status === 'failed';
    return `
      <div class="ib-msg ${esc(m.direction)}${failed ? ' failed' : ''}">
        <div class="ib-msg-head">
          <span class="name">${esc(m.direction === 'outbound' ? (m.sent_by_name || 'You') : (m.from_name || 'Contact'))}</span>
          <span>${esc(timeOfDay(m.created_at))}</span>
          ${via ? `<span class="via">${esc(via)}</span>` : ''}
          ${failed ? '<span class="via">Delivery failed</span>' : ''}
        </div>
        ${m.subject ? `<div class="ib-msg-subject">${esc(m.subject)}</div>` : ''}
        ${m.body_html
          ? `<div class="ib-msg-body ib-msg-body-html"><iframe class="ib-email-frame" title="HTML email from ${esc(m.from_name || 'contact')}" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="${esc(safeEmailDocument(m.body_html))}"></iframe></div>`
          : `<div class="ib-msg-body">${linkifyMessageText(m.body_text)}</div>`}
        ${failed && m.delivery_error ? `<div class="ib-msg-error">${esc(m.delivery_error)}</div>` : ''}
      </div>`;
  }

  prefill({ subject = '', body = '', workflowAction = '' } = {}) {
    this.mode = 'reply';
    this.subject = subject;
    this.workflowAction = workflowAction;
    this.draft = { subject, body_text: body };
    this._sendKey = null;
    this.render();
    $('[data-body]', this)?.focus();
  }

  openTemplatePicker() {
    const templates = this.data.replyTemplates || [];
    if (!templates.length) {
      publish('toast.show', { message: 'No reply templates are configured.', tone: 'error' });
      return;
    }
    const { dialog, close } = openModal({
      title: 'Insert reply template',
      bodyHtml: `<form class="padded grid-form" data-template-form>
        <label class="wide">Template<select name="template_id">${templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></label>
        <div class="wide"><button type="submit">Insert Template</button></div>
      </form>`,
    });
    $('[data-template-form]', dialog)?.addEventListener('submit', (event) => {
      event.preventDefault();
      const id = Number(new FormData(event.currentTarget).get('template_id'));
      const template = templates.find((t) => Number(t.id) === id);
      if (template) this.prefillTemplate(template);
      close();
    });
  }

  prefillTemplate(template, replacements = {}) {
    const lead = this.data.lead || {};
    const values = {
      desired_date: lead.desired_date || 'your preferred date',
      event_name: lead.event_name || 'your event',
      contact_name: lead.contact_name || 'there',
      venue_name: this.data.venueName || 'our venue',
      ...replacements,
    };
    const replace = (value) => String(value || '').replace(/\{\{(\w+)\}\}/g, (_, key) => values[key] ?? '');
    this.prefill({
      subject: replace(template.subject),
      body: replace(template.body_text),
      workflowAction: template.kind === 'general' ? '' : template.kind,
    });
  }

  async uploadFile(file) {
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    try {
      const res = await api(`/leads/${this.data.leadId}/attachments`, { method: 'POST', body: data });
      this.pendingAttachments.push({ id: Number(res.id), filename: file.name });
      this._sendKey = null;
      this.render();
      publish('toast.show', { message: `${file.name} will be included with this reply.` });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
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
