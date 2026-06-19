import { esc, api, PanicElement, $, $$ } from './core.js';

// ── Outbox page ───────────────────────────────────────────────────────────────
// Browsable list of every transactional email the system has sent.
// Layout: fixed-height split pane — table scrolls independently, detail
// pane scrolls independently, nothing overflows the viewport.


// Column definition drives both the table header and the sort cycle.
const COLS = [
  { key: 'sent_at',    label: 'Date',      width: '10em' },
  { key: 'to_address', label: 'Recipient', width: 'auto' },
  { key: 'subject',    label: 'Subject',   width: 'auto' },
  { key: 'template',   label: 'Template',  width: '9em'  },
];


function fmtDate(raw) {
  if (!raw) return '—';
  const d = new Date(raw.replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
  });
}


class OutboxPage extends PanicElement {
  // ── Lifecycle ──────────────────────────────────────────────────────────────

  connect() {
    this.messages  = [];
    this.total     = 0;
    this.page      = 1;
    this.limit     = 50;
    this.sort      = { key: 'sent_at', dir: 'desc' };
    this.query     = '';
    this.selected  = null;   // full message object (with bodies) when open
    this.viewMode  = 'html'; // 'html' | 'raw'
    this._debounce = null;

    // Remove workspace padding so our split-pane can fill edge-to-edge.
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
      q:     this.query,
      sort:  this.sort.key,
      dir:   this.sort.dir,
      page:  String(this.page),
      limit: String(this.limit),
    });

    try {
      const data = await api(`/outbox?${qs}`);
      this.messages = data.messages || [];
      this.total    = data.total    || 0;
    } catch (err) {
      this.messages = [];
      this.total    = 0;
      const tbody = $('tbody', this);
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="4" class="outbox-empty">Failed to load messages: ${esc(err.message)}</td></tr>`;
      }
      return;
    } finally {
      if (pane) pane.removeAttribute('aria-busy');
    }

    this.renderRows();
    this.renderPager();
  }

  async loadMessage(id) {
    try {
      const data = await api(`/outbox/${id}`);
      this.selected = data.message;
      this.viewMode = 'html';
      this.renderDetail();
    } catch (err) {
      // No-op: if it fails, leave the current selection.
    }
  }

  // ── Render ─────────────────────────────────────────────────────────────────

  renderShell() {
    this.innerHTML = `
      <div class="outbox-head">
        <div class="outbox-title-row">
          <h1>Outbox</h1>
          <p class="subtle">All outgoing transactional email sent by this system.</p>
        </div>
        <div class="outbox-search-row">
          <label class="outbox-search-label" aria-label="Search messages">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input class="outbox-search" type="search" placeholder="Search recipient, subject, template…" autocomplete="off" aria-label="Search outbox">
          </label>
        </div>
      </div>

      <div class="outbox-body">
        <div class="outbox-table-pane" role="region" aria-label="Message list" tabindex="0">
          <table class="data-table outbox-table">
            <thead>
              <tr>${COLS.map((c) => this.thHtml(c)).join('')}</tr>
            </thead>
            <tbody>
              <tr><td colspan="4" class="outbox-empty">Loading…</td></tr>
            </tbody>
          </table>
          <div class="outbox-pager" aria-live="polite"></div>
        </div>

        <div class="outbox-detail-pane" aria-label="Message detail" role="region" hidden>
          <div class="outbox-detail-inner">
            <div class="outbox-detail-head">
              <div class="outbox-detail-meta"></div>
              <div class="outbox-detail-actions">
                <div class="outbox-view-toggle" role="group" aria-label="Body view">
                  <button type="button" class="small outbox-btn-html active" data-view="html">HTML</button>
                  <button type="button" class="small secondary outbox-btn-raw"  data-view="raw">Plain text</button>
                </div>
                <button type="button" class="small secondary outbox-close" aria-label="Close message">
                  <i class="fa-solid fa-xmark" aria-hidden="true"></i> Close
                </button>
              </div>
            </div>
            <div class="outbox-detail-body"></div>
          </div>
        </div>
      </div>
    `;

    this.bindEvents();
  }

  thHtml(col) {
    const active = this.sort.key === col.key;
    const arrow  = active ? (this.sort.dir === 'asc' ? ' ↑' : ' ↓') : '';
    const style  = col.width !== 'auto' ? ` style="width:${col.width}"` : '';
    return `<th class="${active ? 'sorted' : ''}"${style}>
      <button type="button" class="th-sort" data-sort-key="${esc(col.key)}"
        aria-sort="${active ? (this.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}">
        ${esc(col.label)}<span class="sort-arrow">${arrow}</span>
      </button>
    </th>`;
  }

  renderRows() {
    const tbody = $('tbody', this);
    if (!tbody) return;

    // Rebuild sortable headers (arrow direction may have changed).
    const thead = $('thead tr', this);
    if (thead) thead.innerHTML = COLS.map((c) => this.thHtml(c)).join('');
    this.bindSortHeaders();

    if (!this.messages.length) {
      tbody.innerHTML = `<tr><td colspan="4" class="outbox-empty">${this.query ? 'No messages match your search.' : 'No messages in the outbox yet.'}</td></tr>`;
      return;
    }

    tbody.innerHTML = this.messages.map((m) => `
      <tr class="outbox-row${this.selected?.id === m.id ? ' selected' : ''}" data-id="${esc(m.id)}" tabindex="0" role="button" aria-pressed="${this.selected?.id === m.id}">
        <td data-label="Date"><span class="outbox-date">${esc(fmtDate(m.sent_at))}</span></td>
        <td data-label="Recipient"><span class="outbox-to">${esc(m.to_address)}</span></td>
        <td data-label="Subject"><span class="outbox-subject">${esc(m.subject || '(no subject)')}</span></td>
        <td data-label="Template"><span class="outbox-template ${m.template ? '' : 'muted'}">${esc(m.template || '—')}</span></td>
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
          this.loadMessage(id);
        }
      };
      row.addEventListener('click', open);
      row.addEventListener('keydown', open);
    });
  }

  renderPager() {
    const el = $('.outbox-pager', this);
    if (!el) return;

    const start = (this.page - 1) * this.limit + 1;
    const end   = Math.min(this.page * this.limit, this.total);
    const pages = Math.ceil(this.total / this.limit) || 1;

    if (this.total === 0) {
      el.innerHTML = '';
      return;
    }

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

    $('.pager-prev', el)?.addEventListener('click', () => {
      if (this.page > 1) { this.page--; this.load(); }
    });
    $('.pager-next', el)?.addEventListener('click', () => {
      if (this.page < pages) { this.page++; this.load(); }
    });
  }

  renderDetail() {
    const pane = $('.outbox-detail-pane', this);
    if (!pane) return;

    if (!this.selected) {
      pane.hidden = true;
      this.classList.remove('detail-open');
      return;
    }

    const m = this.selected;
    pane.hidden = false;
    this.classList.add('detail-open');

    // Meta header
    const meta = $('.outbox-detail-meta', this);
    if (meta) {
      meta.innerHTML = `
        <dl class="outbox-meta-list">
          <div><dt>To</dt><dd>${esc(m.to_address)}</dd></div>
          <div><dt>Subject</dt><dd>${esc(m.subject || '(no subject)')}</dd></div>
          <div><dt>Sent</dt><dd>${esc(fmtDate(m.sent_at))}</dd></div>
          ${m.template ? `<div><dt>Template</dt><dd><code>${esc(m.template)}</code></dd></div>` : ''}
        </dl>
      `;
    }

    // Body
    this.renderBody();

    // Highlight selected row.
    $$('.outbox-row', this).forEach((row) => {
      const active = Number(row.dataset.id) === m.id;
      row.classList.toggle('selected', active);
      row.setAttribute('aria-pressed', String(active));
    });

    // Scroll detail pane to top on each new selection.
    $('.outbox-detail-inner', this)?.scrollTo(0, 0);
  }

  renderBody() {
    const el = $('.outbox-detail-body', this);
    if (!el || !this.selected) return;

    const m = this.selected;

    // Update toggle button states.
    $('.outbox-btn-html', this)?.classList.toggle('active', this.viewMode === 'html');
    $('.outbox-btn-html', this)?.classList.toggle('secondary', this.viewMode !== 'html');
    $('.outbox-btn-raw', this)?.classList.toggle('active', this.viewMode === 'raw');
    $('.outbox-btn-raw', this)?.classList.toggle('secondary', this.viewMode !== 'raw');

    if (this.viewMode === 'html' && m.html_body) {
      el.innerHTML = `<iframe class="outbox-email-frame" sandbox="allow-same-origin" title="Email HTML preview"></iframe>`;
      const frame = $('iframe', el);
      // Use srcdoc to load HTML safely inside the sandboxed iframe.
      frame.srcdoc = m.html_body;
    } else if (this.viewMode === 'raw' || !m.html_body) {
      const body = m.text_body || m.html_body || '(no body)';
      el.innerHTML = `<pre class="outbox-raw-body">${esc(body)}</pre>`;
    }
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

  // ── Event binding ──────────────────────────────────────────────────────────

  bindEvents() {
    // Search (debounced 300ms)
    const searchInput = $('.outbox-search', this);
    searchInput?.addEventListener('input', () => {
      clearTimeout(this._debounce);
      this._debounce = setTimeout(() => {
        this.query = searchInput.value.trim();
        this.page  = 1;
        this.closeDetail();
        this.load();
      }, 300);
    });

    // View toggle (HTML / Plain text)
    $$('[data-view]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        this.viewMode = btn.dataset.view;
        this.renderBody();
      });
    });

    // Close detail
    $('.outbox-close', this)?.addEventListener('click', () => this.closeDetail());

    this.bindSortHeaders();
  }

  bindSortHeaders() {
    $$('.th-sort', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.sortKey;
        if (this.sort.key === key) {
          this.sort.dir = this.sort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          this.sort = { key, dir: key === 'sent_at' ? 'desc' : 'asc' };
        }
        this.page = 1;
        this.load();
      });
    });
  }
}

customElements.define('pb-outbox-page', OutboxPage);
