import { esc, api, publish, PanicElement, $, $$ } from './core.js';

// ── DB Browser ────────────────────────────────────────────────────────────────
// Read-only inspector for the current tenant database. Restricted to tenant
// instance admins / super admins (server gates on manage_users; the nav entry is
// hidden by AppShell.applyCapabilities() for everyone else).
//
// Layout: a table list on the left; the selected table's rows fill the right.
// Clicking a row splits the right pane vertically — rows on top, a full
// field-by-field detail card on the bottom.

const PAGE_SIZE = 50;

class AdminDbBrowser extends PanicElement {
  async connect() {
    this.tables = [];
    this.table = null;      // selected table name
    this.columns = [];      // [{ name, type, key }]
    this.rows = [];
    this.total = 0;
    this.page = 1;
    this.selectedIndex = -1; // index into this.rows of the open detail row
    this.loadingRows = false;
    this.sidebarWidth = 260;  // px, drag-resizable
    this.splitPercent = 55;   // % height of the rows pane once a row is open

    publish('page.context', {
      title: 'Database Browser',
      blurb: 'Read-only view of this tenant’s database — pick a table, then click a row to inspect every field.',
    });

    this.setLoading('Loading tables…');
    try {
      const data = await api('/db-browser');
      this.tables = data.tables || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  async selectTable(name) {
    if (this.loadingRows) return;
    this.table = name;
    this.page = 1;
    this.selectedIndex = -1;
    await this.loadRows();
  }

  async loadRows() {
    if (!this.table) return;
    this.loadingRows = true;
    this.render();
    try {
      const data = await api(`/db-browser/${encodeURIComponent(this.table)}?page=${this.page}&limit=${PAGE_SIZE}`);
      this.columns = data.columns || [];
      this.rows = data.rows || [];
      this.total = data.total || 0;
      this.page = data.page || 1;
    } catch (error) {
      publish('toast.show', { message: error.message, tone: 'error' });
      this.columns = [];
      this.rows = [];
      this.total = 0;
    } finally {
      this.loadingRows = false;
      this.render();
    }
  }

  async changePage(delta) {
    const maxPage = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const next = Math.min(maxPage, Math.max(1, this.page + delta));
    if (next === this.page) return;
    this.page = next;
    this.selectedIndex = -1;
    await this.loadRows();
  }

  selectRow(index) {
    this.selectedIndex = this.selectedIndex === index ? -1 : index;
    this.render();
  }

  // ─── Value formatting ────────────────────────────────────────────────────────

  cellText(value) {
    if (value === null || value === undefined) return '';
    const str = String(value);
    return str.length > 120 ? `${str.slice(0, 120)}…` : str;
  }

  // ─── Render ──────────────────────────────────────────────────────────────────

  render() {
    this.innerHTML = `
      <div class="dbx">
        ${this.renderTableList()}
        <section class="dbx-main">
          ${this.table ? this.renderRowsAndDetail() : '<div class="dbx-empty"><i class="fa-solid fa-database" aria-hidden="true"></i><p>Select a table on the left to browse its records.</p></div>'}
        </section>
      </div>
    `;
    this.bind();
  }

  renderTableList() {
    return `
      <aside class="dbx-tables" style="width:${this.sidebarWidth}px">
        <div class="dbx-tables-head">
          <i class="fa-solid fa-table-list" aria-hidden="true"></i>
          <span>Tables</span>
          <span class="dbx-count">${this.tables.length}</span>
        </div>
        <ul class="dbx-table-items">
          ${this.tables.map((t) => `
            <li>
              <button type="button" class="dbx-table-item ${t.name === this.table ? 'active' : ''}" data-table="${esc(t.name)}">
                <span class="dbx-table-name">${esc(t.name)}</span>
                <span class="dbx-table-rows">${esc(Number(t.approx_rows || 0).toLocaleString())}</span>
              </button>
            </li>
          `).join('') || '<li class="dbx-none">No tables found.</li>'}
        </ul>
        <div class="dbx-resize-v" data-resize="sidebar" aria-hidden="true"></div>
      </aside>
    `;
  }

  renderRowsAndDetail() {
    const hasDetail = this.selectedIndex >= 0 && this.rows[this.selectedIndex];
    return `
      <div class="dbx-split ${hasDetail ? 'has-detail' : ''}">
        <div class="dbx-rows-pane" style="${hasDetail ? `height:${this.splitPercent}%` : ''}">
          ${this.renderToolbar()}
          <div class="dbx-rows-scroll">
            ${this.loadingRows ? '<div class="dbx-loading"><span class="spinner"></span> Loading rows…</div>' : this.renderRowsTable()}
          </div>
        </div>
        ${hasDetail ? '<div class="dbx-resize-h" data-resize="split" aria-hidden="true"></div>' : ''}
        ${hasDetail ? this.renderDetail(this.rows[this.selectedIndex]) : ''}
      </div>
    `;
  }

  renderToolbar() {
    const maxPage = Math.max(1, Math.ceil(this.total / PAGE_SIZE));
    const from = this.total === 0 ? 0 : (this.page - 1) * PAGE_SIZE + 1;
    const to = Math.min(this.total, this.page * PAGE_SIZE);
    return `
      <div class="dbx-toolbar">
        <div class="dbx-toolbar-title"><i class="fa-solid fa-table" aria-hidden="true"></i> <strong>${esc(this.table)}</strong></div>
        <div class="dbx-pager">
          <span class="dbx-range">${from}–${to} of ${Number(this.total).toLocaleString()}</span>
          <button type="button" class="small secondary" data-page="-1" ${this.page <= 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
          <span class="dbx-page-num">${this.page} / ${maxPage}</span>
          <button type="button" class="small secondary" data-page="1" ${this.page >= maxPage ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
        </div>
      </div>
    `;
  }

  renderRowsTable() {
    if (!this.rows.length) return '<div class="empty-state">No records in this table.</div>';
    return `
      <table class="data-table dbx-data">
        <thead>
          <tr>${this.columns.map((c) => `<th title="${esc(c.type)}${c.key ? ` · ${esc(c.key)}` : ''}">${esc(c.name)}${c.key === 'PRI' ? ' <i class="fa-solid fa-key dbx-pk" aria-hidden="true"></i>' : ''}</th>`).join('')}</tr>
        </thead>
        <tbody>
          ${this.rows.map((row, index) => `
            <tr class="dbx-row ${index === this.selectedIndex ? 'selected' : ''}" data-row="${index}" tabindex="0">
              ${this.columns.map((c) => {
                const value = row[c.name];
                const isNull = value === null || value === undefined;
                return `<td class="${isNull ? 'dbx-null' : ''}" title="${esc(this.cellText(value))}">${isNull ? '<span class="dbx-nulltag">NULL</span>' : esc(this.cellText(value))}</td>`;
              }).join('')}
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }

  renderDetail(row) {
    const pk = this.columns.find((c) => c.key === 'PRI');
    const pkLabel = pk && row[pk.name] != null ? `${pk.name} = ${this.cellText(row[pk.name])}` : `Row ${this.selectedIndex + 1}`;
    return `
      <div class="dbx-detail-pane">
        <div class="dbx-detail-head">
          <div class="dbx-detail-title"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Record detail <span class="muted">· ${esc(pkLabel)}</span></div>
          <button type="button" class="small secondary" data-close-detail aria-label="Close detail"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="dbx-detail-scroll">
          <fieldset class="dbx-fieldset">
            <legend>${esc(this.table)}</legend>
            <div class="dbx-fields">
              ${this.columns.map((c) => {
                const value = row[c.name];
                const isNull = value === null || value === undefined;
                return `
                  <div class="dbx-field ${isNull ? 'is-null' : ''}">
                    <span class="dbx-field-label">
                      ${esc(c.name)}
                      ${c.key === 'PRI' ? '<i class="fa-solid fa-key dbx-pk" title="Primary key" aria-hidden="true"></i>' : ''}
                      <span class="dbx-field-type">${esc(c.type)}</span>
                    </span>
                    <span class="dbx-field-value">${isNull ? '<span class="dbx-nulltag">NULL</span>' : esc(String(value))}</span>
                  </div>
                `;
              }).join('')}
            </div>
          </fieldset>
        </div>
      </div>
    `;
  }

  bind() {
    $$('[data-table]', this).forEach((btn) => btn.addEventListener('click', () => this.selectTable(btn.dataset.table)));
    $$('[data-page]', this).forEach((btn) => btn.addEventListener('click', () => this.changePage(Number(btn.dataset.page))));
    $('[data-close-detail]', this)?.addEventListener('click', () => this.selectRow(this.selectedIndex));
    $$('[data-row]', this).forEach((tr) => {
      const open = () => this.selectRow(Number(tr.dataset.row));
      tr.addEventListener('click', open);
      tr.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
    this.bindResize();
  }

  // ─── Drag-to-resize (sidebar width, rows/detail split) ──────────────────────
  // Mirrors a desktop file-browser: dragging mutates the live DOM directly for
  // a smooth 1:1 feel, then commits the final size into component state so it
  // survives the next full re-render. Skipped on narrow viewports, where the
  // layout collapses to a fixed stack (see the 860px media query).

  bindResize() {
    if (window.innerWidth <= 860) return;

    const sidebarHandle = $('[data-resize="sidebar"]', this);
    if (sidebarHandle) {
      sidebarHandle.addEventListener('mousedown', (event) => {
        event.preventDefault();
        const aside = $('.dbx-tables', this);
        const startX = event.clientX;
        const startWidth = aside.getBoundingClientRect().width;
        sidebarHandle.classList.add('resizing');
        document.body.style.cursor = 'col-resize';

        const onMove = (moveEvent) => {
          const width = Math.min(440, Math.max(180, startWidth + (moveEvent.clientX - startX)));
          aside.style.width = `${width}px`;
          this.sidebarWidth = width;
        };
        const onUp = () => {
          sidebarHandle.classList.remove('resizing');
          document.body.style.cursor = '';
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    }

    const splitHandle = $('[data-resize="split"]', this);
    if (splitHandle) {
      splitHandle.addEventListener('mousedown', (event) => {
        event.preventDefault();
        const container = $('.dbx-split', this);
        const rowsPane = $('.dbx-rows-pane', this);
        const containerHeight = container.getBoundingClientRect().height;
        const startY = event.clientY;
        const startHeight = rowsPane.getBoundingClientRect().height;
        splitHandle.classList.add('resizing');
        document.body.style.cursor = 'row-resize';

        const onMove = (moveEvent) => {
          const height = startHeight + (moveEvent.clientY - startY);
          const percent = Math.min(80, Math.max(20, (height / containerHeight) * 100));
          rowsPane.style.height = `${percent}%`;
          this.splitPercent = percent;
        };
        const onUp = () => {
          splitHandle.classList.remove('resizing');
          document.body.style.cursor = '';
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    }
  }
}

customElements.define('pb-admin-db-browser', AdminDbBrowser);
