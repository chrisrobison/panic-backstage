import { esc, api, publish, PanicElement, $, $$ } from './core.js';

// ── Messages app ───────────────────────────────────────────────────────────────
// In-app messaging for staff. One component, three boxes:
//   Inbox   — messages addressed to me (system notifications + staff messages)
//   Archive — inbox messages I've filed away
//   Outbox  — messages I have sent
// Reuses the Outbox split-pane look (.outbox-* classes + .workspace-outbox).

function fmtDate(raw) {
  if (!raw) return '—';
  const d = new Date(raw.replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
  });
}

class MessagesPage extends PanicElement {
  // Subclasses override these three.
  get box() { return 'inbox'; }
  get boxTitle() { return 'Inbox'; }
  get boxBlurb() { return ''; }

  get isSent() { return this.box === 'sent'; }
  get isArchive() { return this.box === 'archive'; }

  connect() {
    this.messages = [];
    this.total = 0;
    this.page = 1;
    this.limit = 50;
    this.query = '';
    this.selected = null;
    this._debounce = null;

    this._app = document.getElementById('app');
    if (this._app) this._app.classList.add('workspace-outbox');

    this.renderShell();
    this.load();
  }

  disconnectedCallback() {
    this._app?.classList.remove('workspace-outbox');
    this.abort?.abort();
  }

  // ── Data ──────────────────────────────────────────────────────────────────

  async load() {
    const pane = $('.outbox-table-pane', this);
    if (pane) pane.setAttribute('aria-busy', 'true');

    const qs = new URLSearchParams({
      box: this.box, q: this.query, page: String(this.page), limit: String(this.limit),
    });

    try {
      const data = await api(`/messages?${qs}`);
      this.messages = data.messages || [];
      this.total = data.total || 0;
    } catch (err) {
      this.messages = [];
      this.total = 0;
      const tbody = $('tbody', this);
      if (tbody) tbody.innerHTML = `<tr><td colspan="3" class="outbox-empty">Failed to load messages: ${esc(err.message)}</td></tr>`;
      return;
    } finally {
      if (pane) pane.removeAttribute('aria-busy');
    }

    this.renderRows();
    this.renderPager();
  }

  async loadMessage(id) {
    try {
      const data = await api(`/messages/${id}`);
      this.selected = data.message;
      // Opening an unread inbox message marks it read server-side; reflect locally.
      const row = this.messages.find((m) => m.id === id);
      if (row && !row.read_at) {
        row.read_at = data.message.read_at;
        publish('messages.changed', {});
      }
      this.renderDetail();
      this.renderRows();
    } catch { /* keep current selection */ }
  }

  // ── Render ──────────────────────────────────────────────────────────────────

  renderShell() {
    const personCol = this.isSent ? 'To' : 'From';
    this.innerHTML = `
      <div class="outbox-head">
        <div class="outbox-title-row">
          <h1>${esc(this.boxTitle)}</h1>
          <p class="subtle">${esc(this.boxBlurb)}</p>
        </div>
        <div class="outbox-search-row">
          <label class="outbox-search-label" aria-label="Search messages">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input class="outbox-search" type="search" placeholder="Search ${this.isSent ? 'recipient' : 'sender'}, subject…" autocomplete="off" aria-label="Search messages">
          </label>
          <button type="button" class="small msg-compose-btn"><i class="fa-solid fa-pen" aria-hidden="true"></i> Compose</button>
        </div>
      </div>

      <div class="outbox-body">
        <div class="outbox-table-pane" role="region" aria-label="Message list" tabindex="0">
          <table class="data-table outbox-table">
            <thead>
              <tr>
                <th style="width:11em">Date</th>
                <th style="width:14em">${personCol}</th>
                <th>Subject</th>
              </tr>
            </thead>
            <tbody><tr><td colspan="3" class="outbox-empty">Loading…</td></tr></tbody>
          </table>
          <div class="outbox-pager" aria-live="polite"></div>
        </div>

        <div class="outbox-detail-pane" aria-label="Message detail" role="region" hidden>
          <div class="outbox-detail-inner">
            <div class="outbox-detail-head">
              <div class="outbox-detail-meta"></div>
              <div class="outbox-detail-actions"></div>
            </div>
            <div class="outbox-detail-body"></div>
          </div>
        </div>
      </div>
    `;
    this.bindEvents();
  }

  personFor(m) {
    if (this.isSent) return m.recipient_name || m.recipient_email || '—';
    return m.sender_name || 'System';
  }

  renderRows() {
    const tbody = $('tbody', this);
    if (!tbody) return;

    if (!this.messages.length) {
      const empty = this.query ? 'No messages match your search.'
        : this.isSent ? 'You haven’t sent any messages yet.'
        : this.isArchive ? 'No archived messages.'
        : 'Your inbox is empty.';
      tbody.innerHTML = `<tr><td colspan="3" class="outbox-empty">${empty}</td></tr>`;
      return;
    }

    tbody.innerHTML = this.messages.map((m) => {
      const unread = !this.isSent && !m.read_at;
      return `
      <tr class="outbox-row${this.selected?.id === m.id ? ' selected' : ''}${unread ? ' msg-unread' : ''}" data-id="${esc(m.id)}" tabindex="0" role="button" aria-pressed="${this.selected?.id === m.id}">
        <td data-label="Date"><span class="outbox-date">${unread ? '<span class="msg-dot" aria-label="Unread"></span>' : ''}${esc(fmtDate(m.created_at))}</span></td>
        <td data-label="${this.isSent ? 'To' : 'From'}"><span class="outbox-to">${esc(this.personFor(m))}</span></td>
        <td data-label="Subject"><span class="outbox-subject">${esc(m.subject || '(no subject)')}</span></td>
      </tr>`;
    }).join('');

    $$('.outbox-row', this).forEach((row) => {
      const open = (e) => {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        const id = Number(row.dataset.id);
        if (this.selected?.id === id) this.closeDetail();
        else this.loadMessage(id);
      };
      row.addEventListener('click', open);
      row.addEventListener('keydown', open);
    });
  }

  renderPager() {
    const el = $('.outbox-pager', this);
    if (!el) return;
    if (this.total === 0) { el.innerHTML = ''; return; }

    const start = (this.page - 1) * this.limit + 1;
    const end = Math.min(this.page * this.limit, this.total);
    const pages = Math.ceil(this.total / this.limit) || 1;

    el.innerHTML = `
      <span class="pager-info">${start}–${end} of ${this.total.toLocaleString()}</span>
      <button type="button" class="small secondary pager-prev" ${this.page <= 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
      <span class="pager-pages">${this.page} / ${pages}</span>
      <button type="button" class="small secondary pager-next" ${this.page >= pages ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
    `;
    $('.pager-prev', el)?.addEventListener('click', () => { if (this.page > 1) { this.page--; this.load(); } });
    $('.pager-next', el)?.addEventListener('click', () => { if (this.page < pages) { this.page++; this.load(); } });
  }

  renderDetail() {
    const pane = $('.outbox-detail-pane', this);
    if (!pane) return;
    if (!this.selected) { pane.hidden = true; this.classList.remove('detail-open'); return; }

    const m = this.selected;
    pane.hidden = false;
    this.classList.add('detail-open');

    const who = this.isSent
      ? `<div><dt>To</dt><dd>${esc(m.recipient_name || m.recipient_email || '—')}</dd></div>`
      : `<div><dt>From</dt><dd>${esc(m.sender_name || 'System')}${m.sender_email ? ` &lt;${esc(m.sender_email)}&gt;` : ''}</dd></div>`;

    const meta = $('.outbox-detail-meta', this);
    if (meta) {
      meta.innerHTML = `
        <dl class="outbox-meta-list">
          ${who}
          <div><dt>Subject</dt><dd>${esc(m.subject || '(no subject)')}</dd></div>
          <div><dt>Date</dt><dd>${esc(fmtDate(m.created_at))}</dd></div>
        </dl>`;
    }

    // Actions vary by box. Reply is available whenever there's a human sender.
    const canReply = !this.isSent && m.sender_user_id;
    const actions = $('.outbox-detail-actions', this);
    if (actions) {
      actions.innerHTML = `
        ${canReply ? '<button type="button" class="small msg-reply"><i class="fa-solid fa-reply" aria-hidden="true"></i> Reply</button>' : ''}
        ${this.box === 'inbox' ? '<button type="button" class="small secondary msg-archive"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>' : ''}
        ${this.box === 'archive' ? '<button type="button" class="small secondary msg-unarchive"><i class="fa-solid fa-inbox" aria-hidden="true"></i> Move to inbox</button>' : ''}
        <button type="button" class="small secondary outbox-close" aria-label="Close message"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Close</button>`;
      $('.msg-reply', actions)?.addEventListener('click', () => this.openCompose({
        recipientId: m.sender_user_id,
        subject: /^re:/i.test(m.subject || '') ? m.subject : `Re: ${m.subject || ''}`,
        replyTo: m.id,
      }));
      $('.msg-archive', actions)?.addEventListener('click', () => this.archive(m.id, true));
      $('.msg-unarchive', actions)?.addEventListener('click', () => this.archive(m.id, false));
      $('.outbox-close', actions)?.addEventListener('click', () => this.closeDetail());
    }

    const body = $('.outbox-detail-body', this);
    if (body) {
      if (m.body_html) {
        body.innerHTML = `<iframe class="outbox-email-frame" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" title="Message body"></iframe>`;
        $('iframe', body).srcdoc = m.body_html;
      } else {
        body.innerHTML = `<pre class="outbox-raw-body">${esc(m.body_text || '(no body)')}</pre>`;
      }
    }

    $$('.outbox-row', this).forEach((row) => {
      const active = Number(row.dataset.id) === m.id;
      row.classList.toggle('selected', active);
      row.setAttribute('aria-pressed', String(active));
    });
    $('.outbox-detail-inner', this)?.scrollTo(0, 0);
  }

  closeDetail() {
    this.selected = null;
    const pane = $('.outbox-detail-pane', this);
    if (pane) pane.hidden = true;
    this.classList.remove('detail-open');
    $$('.outbox-row', this).forEach((row) => {
      row.classList.remove('selected');
      row.setAttribute('aria-pressed', 'false');
    });
  }

  async archive(id, archived) {
    try {
      await api(`/messages/${id}/${archived ? 'archive' : 'unarchive'}`, { method: 'POST' });
      publish('toast.show', { tone: 'info', message: archived ? 'Message archived' : 'Moved to inbox' });
      publish('messages.changed', {});
      this.closeDetail();
      this.load();
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    }
  }

  // ── Compose ──────────────────────────────────────────────────────────────────

  async openCompose({ recipientId = '', subject = '', replyTo = null } = {}) {
    let recipients = this._recipients;
    if (!recipients) {
      try {
        const data = await api('/messages/recipients');
        recipients = this._recipients = data.recipients || [];
      } catch (err) {
        publish('toast.show', { tone: 'error', message: `Could not load recipients: ${err.message}` });
        return;
      }
    }

    const overlay = document.createElement('div');
    overlay.className = 'msg-modal-overlay';
    overlay.innerHTML = `
      <div class="msg-modal" role="dialog" aria-modal="true" aria-label="Compose message">
        <div class="msg-modal-head"><h2>${replyTo ? 'Reply' : 'New message'}</h2>
          <button type="button" class="msg-modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <form class="msg-modal-form">
          <label>To
            <select name="recipient" required>
              <option value="">Select a person…</option>
              ${recipients.map((r) => `<option value="${esc(r.id)}" ${String(r.id) === String(recipientId) ? 'selected' : ''}>${esc(r.name || r.email)}${r.role ? ` · ${esc(r.role)}` : ''}</option>`).join('')}
            </select>
          </label>
          <label>Subject
            <input name="subject" type="text" maxlength="200" value="${esc(subject)}" placeholder="Subject">
          </label>
          <label>Message
            <textarea name="body" rows="8" required placeholder="Write your message…"></textarea>
          </label>
          <div class="msg-modal-actions">
            <button type="button" class="secondary msg-modal-cancel">Cancel</button>
            <button type="submit" class="msg-modal-send"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    $('.msg-modal-close', overlay).addEventListener('click', close);
    $('.msg-modal-cancel', overlay).addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    const field = recipientId ? $('textarea', overlay) : $('select', overlay);
    field?.focus();

    $('.msg-modal-form', overlay).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const recipient_user_id = Number(fd.get('recipient'));
      const body = String(fd.get('body') || '').trim();
      if (!recipient_user_id || !body) return;
      const sendBtn = $('.msg-modal-send', overlay);
      sendBtn.disabled = true;
      try {
        await api('/messages', {
          method: 'POST',
          body: JSON.stringify({
            recipient_user_id,
            subject: String(fd.get('subject') || '').trim(),
            body,
            in_reply_to_id: replyTo,
          }),
        });
        publish('toast.show', { tone: 'success', message: 'Message sent' });
        publish('messages.changed', {});
        close();
        if (this.isSent) this.load();
      } catch (err) {
        sendBtn.disabled = false;
        publish('toast.show', { tone: 'error', message: err.message });
      }
    });
  }

  bindEvents() {
    const searchInput = $('.outbox-search', this);
    searchInput?.addEventListener('input', () => {
      clearTimeout(this._debounce);
      this._debounce = setTimeout(() => {
        this.query = searchInput.value.trim();
        this.page = 1;
        this.closeDetail();
        this.load();
      }, 300);
    });
    $('.msg-compose-btn', this)?.addEventListener('click', () => this.openCompose());
  }
}

class InboxPage extends MessagesPage {
  get box() { return 'inbox'; }
  get boxTitle() { return 'Inbox'; }
  get boxBlurb() { return 'Messages and system notifications sent to you.'; }
}

class ArchivePage extends MessagesPage {
  get box() { return 'archive'; }
  get boxTitle() { return 'Archive'; }
  get boxBlurb() { return 'Messages you’ve filed away.'; }
}

class SentPage extends MessagesPage {
  get box() { return 'sent'; }
  get boxTitle() { return 'Outbox'; }
  get boxBlurb() { return 'Messages you’ve sent.'; }
}

customElements.define('pb-messages-inbox', InboxPage);
customElements.define('pb-messages-archive', ArchivePage);
customElements.define('pb-messages-sent', SentPage);
