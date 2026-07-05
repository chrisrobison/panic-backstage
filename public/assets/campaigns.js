import { esc, api, publish, badge, PanicElement, $, $$ } from './core.js';

// ── Campaigns page ────────────────────────────────────────────────────────────
// Marketing email campaigns: draft/edit/send. Layout mirrors outbox.js/messages.js
// (list pane + resizable detail pane, same .outbox-* CSS classes) so it reads as
// the same tool family. New campaign-specific pieces (editable HTML/text body,
// recipient picker, event-picker modal) are additive on top of that shell.

const NO_CONTENT_HTML = '<!doctype html><html><body style="font-family:sans-serif;color:#8a8f98;padding:48px;text-align:center;">No content yet.</body></html>';


function fmtDate(raw) {
  if (!raw) return '—';
  const d = new Date(raw.replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
  });
}


function fmtEventDate(raw) {
  if (!raw) return 'Date TBA';
  const d = new Date(`${raw}T12:00:00`);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString(undefined, {
    weekday: 'short', month: 'short', day: 'numeric',
  });
}


class CampaignsPage extends PanicElement {
  // ── Lifecycle ──────────────────────────────────────────────────────────────

  connect() {
    this.campaigns = [];
    this.total = 0;
    this.page = 1;
    this.limit = 50;
    this.query = '';
    this.selected = null;   // full campaign object (with bodies) when open
    this.viewMode = 'html'; // 'html' | 'raw'

    // Recipient-picker selection state (reset whenever a new campaign loads).
    this.selectedListIds = new Set();
    this.selectedContacts = new Map(); // id -> { id, name, email }
    this._recipientCount = 0;

    this._lists = null; // cached mailing-list rows, fetched once
    this._debounce = null;
    this._contactDebounce = null;
    this._previewDebounce = null;
    this._recipientsDialog = null; // open "Recipients" modal, if any
    this._recipientsPaneEl = null; // element inside that modal the picker renders into

    this._app = document.getElementById('app');
    if (this._app) this._app.classList.add('workspace-outbox');
    publish('page.context', { title: 'Campaigns', blurb: 'Create, edit, and send marketing email campaigns.' });

    // Restore user's preferred detail-pane height from last session.
    try {
      const saved = localStorage.getItem('pb-campaign-detail-h');
      if (saved) this.style.setProperty('--detail-h', saved);
    } catch { /* storage unavailable */ }

    this.renderShell();
    this.load();
  }

  disconnectedCallback() {
    this._app?.classList.remove('workspace-outbox');
    this.closeRecipientsModal();
    this.abort?.abort();
  }

  // ── Data: list ───────────────────────────────────────────────────────────────

  async load() {
    const pane = $('.outbox-table-pane', this);
    if (pane) pane.setAttribute('aria-busy', 'true');

    const qs = new URLSearchParams({ q: this.query, page: String(this.page), limit: String(this.limit) });

    try {
      const data = await api(`/campaigns?${qs}`);
      this.campaigns = data.campaigns || [];
      this.total = data.total || 0;
    } catch (err) {
      this.campaigns = [];
      this.total = 0;
      const tbody = $('tbody', this);
      if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">Failed to load campaigns: ${esc(err.message)}</td></tr>`;
      return;
    } finally {
      if (pane) pane.removeAttribute('aria-busy');
    }

    this.renderRows();
    this.renderPager();
  }

  async loadCampaign(id) {
    this.closeRecipientsModal();
    try {
      const data = await api(`/campaigns/${id}`);
      this.selected = data.campaign;
      this.viewMode = this.viewMode || 'html';
      this.selectedListIds = new Set();
      this.selectedContacts = new Map();
      this._recipientCount = 0;
      this.renderDetail();
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    }
  }

  // ── Render: shell ──────────────────────────────────────────────────────────

  renderShell() {
    this.innerHTML = `
      <div class="outbox-head">
        <div class="outbox-search-row">
          <label class="outbox-search-label" aria-label="Search campaigns">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input class="outbox-search" type="search" placeholder="Search name or subject…" autocomplete="off" aria-label="Search campaigns">
          </label>
          <button type="button" class="small secondary campaign-generate-btn"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Generate from Event(s)</button>
          <button type="button" class="small campaign-new-btn"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Blank Email</button>
        </div>
      </div>

      <div class="outbox-body">
        <div class="outbox-table-pane" role="region" aria-label="Campaign list" tabindex="0">
          <table class="data-table outbox-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Subject</th>
                <th style="width:9em">Status</th>
                <th style="width:9em">Recipients</th>
                <th style="width:11em">Created</th>
              </tr>
            </thead>
            <tbody><tr><td colspan="5" class="outbox-empty">Loading…</td></tr></tbody>
          </table>
          <div class="outbox-pager" aria-live="polite"></div>
        </div>

        <div class="outbox-resize-bar" aria-hidden="true">
          <span class="outbox-resize-handle">&#xb7;&#xb7;&#xb7;</span>
        </div>

        <div class="outbox-detail-pane" aria-label="Campaign detail" role="region" hidden>
          <div class="outbox-detail-inner">
            <div class="outbox-detail-head">
              <div class="outbox-detail-meta campaign-detail-meta"></div>
              <div class="outbox-detail-actions">
                <div class="outbox-view-toggle" role="group" aria-label="Body view">
                  <button type="button" class="small outbox-btn-html active" data-view="html">HTML</button>
                  <button type="button" class="small secondary outbox-btn-raw"  data-view="raw">Plain text</button>
                </div>
                <button type="button" class="small secondary outbox-close" aria-label="Close campaign">
                  <i class="fa-solid fa-xmark" aria-hidden="true"></i> Close
                </button>
              </div>
            </div>
            <div class="outbox-detail-body campaign-detail-body">
              <div class="campaign-editor-pane"></div>
              <div class="campaign-actions-row" hidden></div>
            </div>
          </div>
        </div>
      </div>
    `;

    this.bindEvents();
  }

  // ── Render: list ─────────────────────────────────────────────────────────────

  renderRows() {
    const tbody = $('tbody', this);
    if (!tbody) return;

    if (!this.campaigns.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="outbox-empty">${this.query ? 'No campaigns match your search.' : 'No campaigns yet.'}</td></tr>`;
      return;
    }

    tbody.innerHTML = this.campaigns.map((c) => `
      <tr class="outbox-row${this.selected?.id === c.id ? ' selected' : ''}" data-id="${esc(c.id)}" tabindex="0" role="button" aria-pressed="${this.selected?.id === c.id}">
        <td data-label="Name"><span class="outbox-to">${esc(c.name)}</span></td>
        <td data-label="Subject"><span class="outbox-subject">${esc(c.subject || '(no subject)')}</span></td>
        <td data-label="Status">${badge(c.status)}</td>
        <td data-label="Recipients">${esc(c.recipient_count ?? 0)}${Number(c.failed_count) > 0 ? ` <span class="muted small">(${esc(c.failed_count)} failed)</span>` : ''}</td>
        <td data-label="Created"><span class="outbox-date">${esc(fmtDate(c.created_at))}</span></td>
      </tr>
    `).join('');

    $$('.outbox-row', this).forEach((row) => {
      const open = (e) => {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        const id = Number(row.dataset.id);
        if (this.selected?.id === id) {
          this.closeDetail();
        } else {
          this.loadCampaign(id);
        }
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
      <button type="button" class="small secondary pager-prev" ${this.page <= 1 ? 'disabled' : ''} aria-label="Previous page">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </button>
      <span class="pager-pages">${this.page} / ${pages}</span>
      <button type="button" class="small secondary pager-next" ${this.page >= pages ? 'disabled' : ''} aria-label="Next page">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
    `;

    $('.pager-prev', el)?.addEventListener('click', () => { if (this.page > 1) { this.page--; this.load(); } });
    $('.pager-next', el)?.addEventListener('click', () => { if (this.page < pages) { this.page++; this.load(); } });
  }

  // ── Render: detail ───────────────────────────────────────────────────────────

  renderDetail() {
    const pane = $('.outbox-detail-pane', this);
    if (!pane) return;

    if (!this.selected) {
      pane.hidden = true;
      this.classList.remove('detail-open');
      return;
    }

    pane.hidden = false;
    this.classList.add('detail-open');

    this.renderDetailMeta();
    this.renderEditor();
    this.renderActions();
    this.initRecipients();

    $$('.outbox-row', this).forEach((row) => {
      const active = Number(row.dataset.id) === this.selected.id;
      row.classList.toggle('selected', active);
      row.setAttribute('aria-pressed', String(active));
    });

    $('.outbox-detail-inner', this)?.scrollTo(0, 0);
  }

  renderDetailMeta() {
    const meta = $('.campaign-detail-meta', this);
    if (!meta) return;
    const c = this.selected;
    const editable = c.status === 'draft';

    let lockedNote = '';
    if (!editable) {
      lockedNote = "Sent campaigns can't be edited.";
      if (c.sent_at) {
        lockedNote += ` Sent ${fmtDate(c.sent_at)} — ${c.sent_count || 0} delivered${c.failed_count ? `, ${c.failed_count} failed` : ''}.`;
      }
    }

    meta.innerHTML = `
      <div class="campaign-title-row">
        <input type="text" class="campaign-name-input" value="${esc(c.name)}" placeholder="Campaign name" aria-label="Campaign name" ${editable ? '' : 'readonly'}>
        ${badge(c.status)}
      </div>
      <input type="text" class="campaign-subject-input" value="${esc(c.subject || '')}" placeholder="Email subject line" aria-label="Subject" ${editable ? '' : 'readonly'}>
      ${lockedNote ? `<p class="muted small campaign-locked-note">${esc(lockedNote)}</p>` : ''}
    `;

    if (editable) {
      [['.campaign-name-input', 'name'], ['.campaign-subject-input', 'subject']].forEach(([sel, field]) => {
        const input = $(sel, meta);
        if (!input) return;
        input.addEventListener('input', () => { input.dataset.dirty = '1'; });
        input.addEventListener('blur', () => {
          if (!input.dataset.dirty) return;
          delete input.dataset.dirty;
          this.saveField(field, input.value.trim());
        });
      });
    }
  }

  async saveField(field, value) {
    if (!this.selected) return;
    try {
      const data = await api(`/campaigns/${this.selected.id}`, { method: 'PATCH', body: JSON.stringify({ [field]: value }) });
      this.selected = { ...this.selected, ...data.campaign };
      const row = this.campaigns.find((c) => c.id === this.selected.id);
      if (row) { row.name = this.selected.name; row.subject = this.selected.subject; }
      this.renderRows();
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    }
  }

  // ── Render: HTML / plain-text editor ────────────────────────────────────────

  renderEditor() {
    const el = $('.campaign-editor-pane', this);
    if (!el || !this.selected) return;
    const c = this.selected;
    const editable = c.status === 'draft';

    $('.outbox-btn-html', this)?.classList.toggle('active', this.viewMode === 'html');
    $('.outbox-btn-html', this)?.classList.toggle('secondary', this.viewMode !== 'html');
    $('.outbox-btn-raw', this)?.classList.toggle('active', this.viewMode === 'raw');
    $('.outbox-btn-raw', this)?.classList.toggle('secondary', this.viewMode !== 'raw');

    if (this.viewMode === 'html') {
      el.innerHTML = `<iframe class="outbox-email-frame campaign-editor-frame" sandbox="allow-same-origin" title="Campaign HTML body"></iframe>`;
      const frame = $('iframe', el);
      this._htmlFrame = frame;
      this._htmlDirty = false;
      frame.addEventListener('load', () => {
        if (!editable) return;
        const body = frame.contentDocument?.body;
        if (!body) return;
        body.contentEditable = 'true';
        body.addEventListener('input', () => { this._htmlDirty = true; });
        body.addEventListener('blur', () => { this.saveHtmlIfDirty(); });
      });
      frame.srcdoc = c.html_body || NO_CONTENT_HTML;
    } else {
      const text = c.text_body || '';
      el.innerHTML = `<pre class="outbox-raw-body campaign-text-editor"${editable ? ' contenteditable="true"' : ''}>${esc(text)}</pre>`;
      const pre = $('pre', el);
      this._textPre = pre;
      this._textDirty = false;
      if (editable) {
        pre.addEventListener('input', () => { this._textDirty = true; });
        pre.addEventListener('blur', () => { this.saveTextIfDirty(); });
      }
    }
  }

  async saveHtmlIfDirty() {
    if (!this._htmlDirty || !this.selected) return;
    const doc = this._htmlFrame?.contentDocument;
    if (!doc) return;
    this._htmlDirty = false;
    const html = '<!doctype html>\n' + doc.documentElement.outerHTML;
    try {
      const data = await api(`/campaigns/${this.selected.id}`, { method: 'PATCH', body: JSON.stringify({ html_body: html }) });
      this.selected = { ...this.selected, ...data.campaign };
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    }
  }

  async saveTextIfDirty() {
    if (!this._textDirty || !this.selected) return;
    const pre = this._textPre;
    if (!pre) return;
    this._textDirty = false;
    const text = pre.textContent;
    try {
      const data = await api(`/campaigns/${this.selected.id}`, { method: 'PATCH', body: JSON.stringify({ text_body: text }) });
      this.selected = { ...this.selected, ...data.campaign };
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    }
  }

  async saveCurrentViewIfDirty() {
    if (this.viewMode === 'html') await this.saveHtmlIfDirty();
    else await this.saveTextIfDirty();
  }

  // ── Render: recipient picker ─────────────────────────────────────────────────
  // The picker lives in a modal (opened via the "Recipients" button in the
  // actions row) rather than inline in the detail pane, so it never overlaps
  // the email preview above it. `initRecipients` just primes the recipient
  // count (for the button label / Send button state) without opening anything.

  async initRecipients() {
    if (!this.selected) return;
    if (this.selected.status !== 'draft') {
      this._recipientCount = 0;
      this.renderActions();
      return;
    }
    if (!this._lists) {
      try {
        const data = await api('/mailing-lists');
        this._lists = data.lists || [];
      } catch {
        this._lists = [];
      }
    }
    this.refreshRecipientPreview();
  }

  openRecipientsModal() {
    if (!this.selected || this.selected.status !== 'draft' || this._recipientsDialog) return;

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `
      <div class="modal-card wide">
        <div class="section-head padded">
          <h2>Recipients</h2>
          <button type="button" class="small secondary" data-close>Close</button>
        </div>
        <div class="modal-card-body padded campaign-recipients-pane"></div>
      </div>`;
    document.body.appendChild(dialog);
    this._recipientsDialog = dialog;

    const close = () => this.closeRecipientsModal();
    $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

    this.renderRecipientsInto($('.campaign-recipients-pane', dialog));
  }

  closeRecipientsModal() {
    if (!this._recipientsDialog) return;
    this._recipientsDialog.remove();
    this._recipientsDialog = null;
    this._recipientsPaneEl = null;
  }

  async renderRecipientsInto(el) {
    if (!el || !this.selected) return;
    this._recipientsPaneEl = el;

    if (!this._lists) {
      try {
        const data = await api('/mailing-lists');
        this._lists = data.lists || [];
      } catch {
        this._lists = [];
      }
    }

    el.innerHTML = `
      ${this._lists.length ? `<div class="campaign-lists-checklist">
        ${this._lists.map((l) => `<label class="checkbox-row campaign-list-check">
          <input type="checkbox" data-list-id="${esc(l.id)}" ${this.selectedListIds.has(l.id) ? 'checked' : ''}>
          ${esc(l.name)} <span class="muted small">(${esc(l.member_count)})</span>
        </label>`).join('')}
      </div>` : '<p class="muted small">No mailing lists yet.</p>'}

      <div class="campaign-adhoc-search">
        <label class="outbox-search-label campaign-contact-search-label" aria-label="Search contacts to add">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" class="campaign-contact-search" placeholder="Search opted-in contacts to add…" autocomplete="off">
        </label>
        <ul class="campaign-contact-results" hidden></ul>
      </div>

      <div class="campaign-contact-chips">${this.contactChipsHtml()}</div>

      <p class="campaign-recipient-count">Calculating recipients…</p>
    `;

    $$('[data-list-id]', el).forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = Number(cb.dataset.listId);
        if (cb.checked) this.selectedListIds.add(id); else this.selectedListIds.delete(id);
        this.refreshRecipientPreview();
      });
    });

    const search = $('.campaign-contact-search', el);
    const results = $('.campaign-contact-results', el);
    search?.addEventListener('input', () => {
      clearTimeout(this._contactDebounce);
      const q = search.value.trim();
      if (!q) { results.hidden = true; results.innerHTML = ''; return; }
      this._contactDebounce = setTimeout(() => this.searchContacts(q, results, search), 300);
    });

    this.bindContactChipRemove();
    this.refreshRecipientPreview();
  }

  async searchContacts(q, results, search) {
    try {
      const data = await api(`/contacts?q=${encodeURIComponent(q)}&opted=1&limit=10`);
      const contacts = (data.contacts || []).filter((c) => !this.selectedContacts.has(c.id));
      results.innerHTML = contacts.length
        ? contacts.map((c) => {
          const name = `${c.first_name || ''} ${c.last_name || ''}`.trim() || c.email;
          return `<li><button type="button" class="campaign-contact-add" data-id="${esc(c.id)}" data-name="${esc(name)}" data-email="${esc(c.email)}">${esc(name)} <span class="muted small">${esc(c.email)}</span></button></li>`;
        }).join('')
        : '<li class="muted small">No opted-in contacts match.</li>';
      results.hidden = false;

      $$('.campaign-contact-add', results).forEach((btn) => {
        btn.addEventListener('click', () => {
          this.selectedContacts.set(Number(btn.dataset.id), {
            id: Number(btn.dataset.id), name: btn.dataset.name, email: btn.dataset.email,
          });
          search.value = '';
          results.hidden = true;
          results.innerHTML = '';
          const chips = $('.campaign-contact-chips', this._recipientsPaneEl);
          if (chips) chips.innerHTML = this.contactChipsHtml();
          this.bindContactChipRemove();
          this.refreshRecipientPreview();
        });
      });
    } catch {
      // Silently ignore ad-hoc search failures — the user can retry the query.
    }
  }

  contactChipsHtml() {
    return [...this.selectedContacts.values()].map((c) => `
      <span class="chip campaign-contact-chip">${esc(c.name)}
        <button type="button" class="chip-remove" data-remove-contact="${esc(c.id)}" aria-label="Remove ${esc(c.name)}">&times;</button>
      </span>`).join('');
  }

  bindContactChipRemove() {
    const root = this._recipientsPaneEl;
    if (!root) return;
    $$('[data-remove-contact]', root).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.selectedContacts.delete(Number(btn.dataset.removeContact));
        const chips = $('.campaign-contact-chips', root);
        if (chips) chips.innerHTML = this.contactChipsHtml();
        this.bindContactChipRemove();
        this.refreshRecipientPreview();
      });
    });
  }

  refreshRecipientPreview() {
    clearTimeout(this._previewDebounce);
    this._previewDebounce = setTimeout(() => this.loadRecipientPreview(), 250);
  }

  async loadRecipientPreview() {
    if (!this.selected) return;
    const countEl = this._recipientsPaneEl ? $('.campaign-recipient-count', this._recipientsPaneEl) : null;

    const listIds = [...this.selectedListIds].join(',');
    const contactIds = [...this.selectedContacts.keys()].join(',');

    if (!listIds && !contactIds) {
      this._recipientCount = 0;
      if (countEl) countEl.textContent = '0 recipients will receive this.';
      this.renderActions();
      return;
    }

    const qs = new URLSearchParams();
    if (listIds) qs.set('list_ids', listIds);
    if (contactIds) qs.set('contact_ids', contactIds);

    try {
      const data = await api(`/campaigns/${this.selected.id}/recipients/preview?${qs}`);
      this._recipientCount = data.count || 0;
      if (countEl) countEl.textContent = `${this._recipientCount} recipient${this._recipientCount === 1 ? '' : 's'} will receive this.`;
    } catch (err) {
      if (countEl) countEl.textContent = `Couldn't calculate recipients: ${err.message}`;
    }
    this.renderActions();
  }

  // ── Render: actions row (recipients / send test / send campaign) ────────────

  renderActions() {
    const el = $('.campaign-actions-row', this);
    if (!el || !this.selected) return;
    const c = this.selected;
    el.hidden = false;

    const canSend = c.status === 'draft' && (this._recipientCount || 0) > 0;

    el.innerHTML = `
      ${c.status === 'draft' ? `<button type="button" class="small secondary campaign-recipients-btn">
        <i class="fa-solid fa-users" aria-hidden="true"></i> Recipients (${this._recipientCount || 0})
      </button>` : ''}
      <button type="button" class="small secondary campaign-send-test-btn">Send Test…</button>
      <div class="campaign-send-test-form" hidden>
        <input type="email" class="campaign-send-test-email" placeholder="test@example.com" aria-label="Test email address">
        <button type="button" class="small campaign-send-test-submit">Send</button>
        <button type="button" class="small secondary campaign-send-test-cancel">Cancel</button>
      </div>
      ${c.status === 'draft' ? `<button type="button" class="small campaign-send-btn" ${canSend ? '' : 'disabled'}>Send Campaign</button>` : ''}
    `;

    $('.campaign-recipients-btn', el)?.addEventListener('click', () => this.openRecipientsModal());
    $('.campaign-send-test-btn', el)?.addEventListener('click', () => {
      const form = $('.campaign-send-test-form', el);
      form.hidden = !form.hidden;
      if (!form.hidden) $('.campaign-send-test-email', form)?.focus();
    });
    $('.campaign-send-test-cancel', el)?.addEventListener('click', () => {
      $('.campaign-send-test-form', el).hidden = true;
    });
    $('.campaign-send-test-submit', el)?.addEventListener('click', () => this.sendTest());
    $('.campaign-send-btn', el)?.addEventListener('click', () => this.sendCampaign());
  }

  async sendTest() {
    const el = $('.campaign-actions-row', this);
    const input = $('.campaign-send-test-email', el);
    const email = input?.value.trim();
    if (!email) return;
    const btn = $('.campaign-send-test-submit', el);
    btn.disabled = true;
    try {
      await api(`/campaigns/${this.selected.id}/send-test`, { method: 'POST', body: JSON.stringify({ email }) });
      publish('toast.show', { tone: 'success', message: `Test email sent to ${email}.` });
      input.value = '';
      $('.campaign-send-test-form', el).hidden = true;
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
    } finally {
      btn.disabled = false;
    }
  }

  async sendCampaign() {
    if (!this.selected) return;
    const count = this._recipientCount || 0;
    if (count <= 0) return;
    if (!confirm(`Send this campaign to ${count} recipient${count === 1 ? '' : 's'}? This can't be undone.`)) return;

    const btn = $('.campaign-send-btn', this);
    if (btn) btn.disabled = true;
    try {
      await api(`/campaigns/${this.selected.id}/send`, {
        method: 'POST',
        body: JSON.stringify({ list_ids: [...this.selectedListIds], contact_ids: [...this.selectedContacts.keys()] }),
      });
      publish('toast.show', { tone: 'success', message: 'Campaign sent.' });
      await this.loadCampaign(this.selected.id);
      await this.load();
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
      if (btn) btn.disabled = false;
    }
  }

  // ── Close / toggle ────────────────────────────────────────────────────────────

  async closeDetail() {
    if (this.selected) await this.saveCurrentViewIfDirty();
    this.closeRecipientsModal();
    this.selected = null;
    const pane = $('.outbox-detail-pane', this);
    if (pane) pane.hidden = true;
    this.classList.remove('detail-open');
    $$('.outbox-row', this).forEach((row) => {
      row.classList.remove('selected');
      row.setAttribute('aria-pressed', 'false');
    });
  }

  // ── New blank campaign modal ─────────────────────────────────────────────────

  openNewCampaignModal() {
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `
      <div class="modal-card">
        <div class="section-head padded">
          <h2>New blank email</h2>
          <button type="button" class="small secondary" data-close>Close</button>
        </div>
        <form class="grid-form padded" data-form="new-campaign">
          <label class="wide">Name <input name="name" required maxlength="200" placeholder="e.g. July Newsletter"></label>
          <button>Create</button>
        </form>
      </div>`;
    document.body.appendChild(dialog);

    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('input[name="name"]', dialog)?.focus();

    $('[data-form="new-campaign"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = new FormData(e.target).get('name')?.toString().trim();
      if (!name) return;
      const btn = $('button', e.target);
      btn.disabled = true;
      try {
        const data = await api('/campaigns', { method: 'POST', body: JSON.stringify({ name }) });
        close();
        await this.selectNewCampaign(data.campaign.id);
      } catch (err) {
        btn.disabled = false;
        publish('toast.show', { tone: 'error', message: err.message });
      }
    });
  }

  // ── Generate-from-events modal ───────────────────────────────────────────────

  async openEventPickerModal() {
    let events;
    try {
      const data = await api('/campaigns/eligible-events');
      events = data.events || [];
    } catch (err) {
      publish('toast.show', { tone: 'error', message: err.message });
      return;
    }

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `
      <div class="modal-card wide">
        <div class="section-head padded">
          <h2>Generate from Event(s)</h2>
          <button type="button" class="small secondary" data-close>Close</button>
        </div>
        <div class="padded campaign-event-picker">
          <label class="outbox-search-label campaign-event-filter-label" aria-label="Filter events">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" class="campaign-event-filter" placeholder="Filter events…" autocomplete="off">
          </label>
          <ul class="campaign-event-list"></ul>
          <div class="campaign-event-picker-actions">
            <button type="button" class="small secondary" data-close>Cancel</button>
            <button type="button" class="small campaign-generate-submit">Generate Campaign</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(dialog);

    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

    const listEl = $('.campaign-event-list', dialog);
    const renderList = (filter = '') => {
      const f = filter.trim().toLowerCase();
      const filtered = f ? events.filter((ev) => (ev.title || '').toLowerCase().includes(f)) : events;
      listEl.innerHTML = filtered.length
        ? filtered.map((ev) => `
          <li><label class="checkbox-row campaign-event-check">
            <input type="checkbox" value="${esc(ev.id)}">
            <span class="campaign-event-title">${esc(ev.title)}</span>
            <span class="muted small campaign-event-date">${esc(fmtEventDate(ev.date))}${ev.venue_name ? ` · ${esc(ev.venue_name)}` : ''}</span>
          </label></li>`).join('')
        : '<li class="muted small">No matching events.</li>';
    };
    renderList();

    $('.campaign-event-filter', dialog).addEventListener('input', (e) => renderList(e.target.value));

    $('.campaign-generate-submit', dialog).addEventListener('click', async () => {
      const ids = $$('.campaign-event-check input:checked', dialog).map((cb) => Number(cb.value));
      if (!ids.length) {
        publish('toast.show', { tone: 'error', message: 'Pick at least one event.' });
        return;
      }
      const btn = $('.campaign-generate-submit', dialog);
      btn.disabled = true;
      try {
        const data = await api('/campaigns/generate-from-events', { method: 'POST', body: JSON.stringify({ event_ids: ids }) });
        close();
        if (data.dropped_count > 0) {
          publish('toast.show', { tone: 'info', message: `${data.dropped_count} event${data.dropped_count === 1 ? '' : 's'} weren't eligible and were skipped.` });
        }
        await this.selectNewCampaign(data.campaign.id);
      } catch (err) {
        btn.disabled = false;
        publish('toast.show', { tone: 'error', message: err.message });
      }
    });
  }

  // Reset search/paging so a freshly created campaign is visible, then select it.
  async selectNewCampaign(id) {
    this.query = '';
    const searchInput = $('.outbox-search', this);
    if (searchInput) searchInput.value = '';
    this.page = 1;
    await this.load();
    await this.loadCampaign(id);
  }

  // ── Event binding ──────────────────────────────────────────────────────────

  bindEvents() {
    // Search (debounced 300ms)
    const searchInput = $('.outbox-search', this);
    searchInput?.addEventListener('input', () => {
      clearTimeout(this._debounce);
      this._debounce = setTimeout(async () => {
        this.query = searchInput.value.trim();
        this.page = 1;
        await this.closeDetail();
        this.load();
      }, 300);
    });

    $('.campaign-generate-btn', this)?.addEventListener('click', () => this.openEventPickerModal());
    $('.campaign-new-btn', this)?.addEventListener('click', () => this.openNewCampaignModal());

    // View toggle (HTML / Plain text) — save the outgoing view before switching
    // so an unsaved edit is never silently discarded.
    $$('[data-view]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!this.selected || this.viewMode === btn.dataset.view) return;
        await this.saveCurrentViewIfDirty();
        this.viewMode = btn.dataset.view;
        this.renderEditor();
      });
    });

    // Close detail (also flushes any unsaved edit)
    $('.outbox-close', this)?.addEventListener('click', () => this.closeDetail());

    // Drag-to-resize handle between the campaign list and detail panes.
    const bar = $('.outbox-resize-bar', this);
    if (!bar) return;
    bar.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      bar.setPointerCapture(e.pointerId);
      this.classList.add('resizing');

      const body = $('.outbox-body', this);
      const detail = $('.outbox-detail-pane', this);
      const startY = e.clientY;
      const startH = detail.offsetHeight;

      const onMove = (ev) => {
        const delta = startY - ev.clientY;
        const bodyH = body.offsetHeight;
        const newH = Math.min(Math.max(startH + delta, 80), bodyH - 60);
        this.style.setProperty('--detail-h', `${newH}px`);
      };

      const onUp = () => {
        bar.removeEventListener('pointermove', onMove);
        bar.removeEventListener('pointerup', onUp);
        this.classList.remove('resizing');
        const h = this.style.getPropertyValue('--detail-h');
        if (h) window.PBConsent?.savePref('pb-campaign-detail-h', h);
      };

      bar.addEventListener('pointermove', onMove);
      bar.addEventListener('pointerup', onUp);
    });
  }
}

customElements.define('pb-msg-campaigns', CampaignsPage);
