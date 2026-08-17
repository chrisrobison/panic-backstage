import { PanicElement, debounce, esc, number, publish, sourceName, weekday } from './archive-shared.js';

class ArchiveExplorer extends PanicElement {
  connect() {
    this.sort = { key: 'date', direction: 'asc' };
    this.page = 1;
    this.pageSize = 25;
    this.query = '';
    this.debouncedQuery = debounce((value) => {
      this.query = value;
      this.page = 1;
      this.render();
    }, 180);
    this.addEventListener('input', (event) => {
      if (event.target.matches('[data-table-search]')) this.debouncedQuery(event.target.value);
    }, { signal: this.abort.signal });
    this.addEventListener('change', (event) => this.onChange(event), { signal: this.abort.signal });
    this.addEventListener('click', (event) => this.onClick(event), { signal: this.abort.signal });
    this.addEventListener('keydown', (event) => {
      if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-row-date], [data-row-record]')) {
        event.preventDefault();
        event.target.click();
      }
    }, { signal: this.abort.signal });
  }

  update(store, result, state) {
    this.store = store;
    this.result = result;
    this.state = state;
    this.render();
  }

  render() {
    if (!this.result) return;
    const mode = this.state.tableMode;
    const items = this.filteredAndSorted(mode === 'nights' ? this.result.nights : this.result.records, mode);
    const pages = Math.max(1, Math.ceil(items.length / this.pageSize));
    this.page = Math.min(this.page, pages);
    const start = (this.page - 1) * this.pageSize;
    const visible = items.slice(start, start + this.pageSize);
    this.innerHTML = `<section class="archive-panel explorer-panel" id="archive-explorer">
      <div class="explorer-heading">
        <div><p class="eyebrow">Browse the source</p><h2>Concert Explorer <span>${number(items.length)} results</span></h2></div>
        <div class="segmented" aria-label="Explorer mode">
          <button type="button" data-table-mode="nights" class="${mode === 'nights' ? 'active' : ''}">Show Nights</button>
          <button type="button" data-table-mode="records" class="${mode === 'records' ? 'active' : ''}">Raw Records</button>
        </div>
      </div>
      <div class="explorer-tools">
        <div class="search-field compact"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-table-search type="search" value="${esc(this.query)}" placeholder="Search this table" aria-label="Search explorer table"></div>
        <div class="tool-spacer"></div>
        <button type="button" class="button secondary small" data-export-table="csv"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV</button>
        <button type="button" class="button secondary small" data-export-table="json"><i class="fa-solid fa-code" aria-hidden="true"></i> JSON</button>
      </div>
      <div class="table-scroll">
        ${mode === 'nights' ? this.nightsTable(visible) : this.recordsTable(visible)}
      </div>
      <footer class="table-footer">
        <p>Showing ${items.length ? start + 1 : 0}–${Math.min(start + this.pageSize, items.length)} of ${number(items.length)}</p>
        <div class="pager" aria-label="Pagination">
          <button type="button" data-page="first" aria-label="First page" ${this.page === 1 ? 'disabled' : ''}><i class="fa-solid fa-angles-left"></i></button>
          <button type="button" data-page="previous" aria-label="Previous page" ${this.page === 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left"></i></button>
          <span>Page <strong>${this.page}</strong> of ${pages}</span>
          <button type="button" data-page="next" aria-label="Next page" ${this.page === pages ? 'disabled' : ''}><i class="fa-solid fa-chevron-right"></i></button>
          <button type="button" data-page="last" aria-label="Last page" ${this.page === pages ? 'disabled' : ''}><i class="fa-solid fa-angles-right"></i></button>
        </div>
        <label class="rows-select">Rows <select data-page-size>${[10, 25, 50, 100].map((size) => `<option ${size === this.pageSize ? 'selected' : ''}>${size}</option>`).join('')}</select></label>
      </footer>
    </section>`;
  }

  nightsTable(nights) {
    const columns = [
      ['date', 'Date'], ['weekday', 'Day'], ['performers', 'Documented performers'], ['performerCount', 'Performers'], ['recordCount', 'Records'], ['explicitBills', 'Explicit bill?'], ['era', 'Era'],
    ];
    return `<table class="archive-table"><thead><tr>${columns.map(([key, label]) => this.heading(key, label)).join('')}</tr></thead>
      <tbody>${nights.map((night) => `<tr tabindex="0" data-row-date="${night.date}" class="${this.state.selectedType === 'night' && this.state.selectedId === night.date ? 'selected' : ''}">
        <td data-label="Date"><time datetime="${night.date}">${night.date}</time></td>
        <td data-label="Day">${weekday(night.date, 'short')}</td>
        <td data-label="Performers"><strong>${esc(night.performers.slice(0, 5).join(', '))}${night.performers.length > 5 ? ` <em>+${night.performers.length - 5}</em>` : ''}</strong></td>
        <td data-label="Performers">${number(night.performerCount)}</td>
        <td data-label="Records">${number(night.recordCount)}</td>
        <td data-label="Explicit bill">${night.explicitBills.length ? '<span class="status-tag explicit">Yes</span>' : '<span class="dash">—</span>'}</td>
        <td data-label="Era"><span class="era-tag ${night.era.startsWith('Reopened') ? 'reopened' : ''}">${esc(night.era)}</span></td>
      </tr>`).join('') || `<tr><td colspan="7"><p class="empty-state">No documented nights match these filters.</p></td></tr>`}</tbody></table>`;
  }

  recordsTable(records) {
    const columns = [['date', 'Date'], ['name', 'Record name'], ['performers', 'Performers'], ['sourceFiles', 'Sources'], ['url', 'Concert Archives'], ['source', 'Source file']];
    return `<table class="archive-table raw-table"><thead><tr>${columns.map(([key, label]) => this.heading(key, label)).join('')}</tr></thead>
      <tbody>${records.map((record) => `<tr tabindex="0" data-row-record="${record.id}" data-row-date="${record.date}" class="${this.state.selectedType === 'record' && this.state.selectedId === record.id ? 'selected' : ''}">
        <td data-label="Date"><time datetime="${record.date}">${record.date}</time></td>
        <td data-label="Record"><strong>${esc(record.name)}</strong>${record.explicitBill ? '<span class="status-tag explicit">Explicit bill</span>' : ''}</td>
        <td data-label="Performers">${esc(record.performers.join(', ') || 'Not listed')}</td>
        <td data-label="Sources">${number(record.sourceFiles.length)}</td>
        <td data-label="Concert Archives">${record.url ? `<a href="${esc(record.url)}" target="_blank" rel="noopener" data-external>Open record <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>` : '—'}</td>
        <td data-label="Source file"><span class="source-pill"><i class="fa-regular fa-file-code" aria-hidden="true"></i>${esc(sourceName(record.sourceFiles[0]))}</span></td>
      </tr>`).join('') || `<tr><td colspan="6"><p class="empty-state">No raw records match these filters.</p></td></tr>`}</tbody></table>`;
  }

  heading(key, label) {
    const active = this.sort.key === key;
    const direction = active ? this.sort.direction : 'none';
    return `<th aria-sort="${active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-sort="${key}">${esc(label)} <i class="fa-solid ${active ? (direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort'}" aria-hidden="true"></i></button></th>`;
  }

  filteredAndSorted(items, mode) {
    const query = this.query.trim().toLocaleLowerCase();
    const filtered = query ? items.filter((item) => {
      const values = mode === 'nights' ? [item.date, item.era, ...item.performers] : [item.date, item.name, ...item.performers, ...item.sourceFiles];
      return values.join(' ').toLocaleLowerCase().includes(query);
    }) : [...items];
    const { key, direction } = this.sort;
    const value = (item) => {
      if (key === 'weekday') return item.weekday;
      if (key === 'performers') return item.performers.join(', ');
      if (key === 'explicitBills') return item.explicitBills?.length || 0;
      if (key === 'sourceFiles') return item.sourceFiles?.length || 0;
      if (key === 'source') return sourceName(item.sourceFiles?.[0]);
      return item[key] ?? '';
    };
    return filtered.sort((a, b) => {
      const av = value(a); const bv = value(b);
      const comparison = typeof av === 'number' ? av - bv : String(av).localeCompare(String(bv));
      return direction === 'asc' ? comparison : -comparison;
    });
  }

  onChange(event) {
    if (event.target.matches('[data-page-size]')) {
      this.pageSize = Number(event.target.value);
      this.page = 1;
      this.render();
    }
  }

  onClick(event) {
    if (event.target.closest('[data-external]')) return;
    const mode = event.target.closest('[data-table-mode]');
    if (mode) {
      this.page = 1;
      this.sort = { key: 'date', direction: 'asc' };
      publish('archive.view.changed', { tableMode: mode.dataset.tableMode });
      return;
    }
    const sort = event.target.closest('[data-sort]');
    if (sort) {
      const key = sort.dataset.sort;
      this.sort = { key, direction: this.sort.key === key && this.sort.direction === 'asc' ? 'desc' : 'asc' };
      this.page = 1;
      this.render();
      return;
    }
    const page = event.target.closest('[data-page]');
    if (page) {
      const pages = Math.max(1, Math.ceil(this.filteredAndSorted(this.state.tableMode === 'nights' ? this.result.nights : this.result.records, this.state.tableMode).length / this.pageSize));
      if (page.dataset.page === 'first') this.page = 1;
      if (page.dataset.page === 'previous') this.page = Math.max(1, this.page - 1);
      if (page.dataset.page === 'next') this.page = Math.min(pages, this.page + 1);
      if (page.dataset.page === 'last') this.page = pages;
      this.render();
      return;
    }
    const exportButton = event.target.closest('[data-export-table]');
    if (exportButton) {
      publish('archive.export', { format: exportButton.dataset.exportTable, mode: this.state.tableMode });
      return;
    }
    const row = event.target.closest('[data-row-date]');
    if (row) {
      if (this.state.tableMode === 'records' && row.dataset.rowRecord) publish('archive.record.selected', { id: row.dataset.rowRecord });
      else publish('archive.night.selected', { date: row.dataset.rowDate });
    }
  }
}

customElements.define('archive-explorer', ArchiveExplorer);
