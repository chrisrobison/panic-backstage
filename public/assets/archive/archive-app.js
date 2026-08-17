import { archiveStore } from './archive-store.js';
import { MONTHS_LONG, PanicElement, csvCell, dateLabel, downloadFile, esc, number, publish, subscribe } from './archive-shared.js';
import './archive-filters.js';
import './archive-summary.js';
import './archive-timeline.js';
import './archive-performers.js';
import './archive-explorer.js';
import './archive-detail.js';

class MabArchiveApp extends PanicElement {
  async connect() {
    this.store = archiveStore;
    this.result = { nights: [], records: [] };
    this.installSubscriptions();
    window.addEventListener('popstate', () => this.restoreFromUrl(), { signal: this.abort.signal });
    try {
      await this.store.load();
      this.state = this.stateFromUrl();
      this.renderShell();
      this.applyState({ syncUrl: false });
      publish('archive.loaded', { records: this.store.records.length, nights: this.store.showNights.length });
    } catch (error) {
      this.renderError(error);
    }
  }

  installSubscriptions() {
    subscribe('archive.filter.changed', (changes) => {
      const { history: historyMode, ...criteria } = changes;
      if ((criteria.minYear !== undefined || criteria.maxYear !== undefined) && criteria.month === undefined) criteria.month = null;
      this.state = { ...this.state, ...criteria };
      this.applyState({ historyMode });
    }, this.abort.signal);
    subscribe('archive.view.changed', (changes) => {
      this.state = { ...this.state, ...changes };
      this.applyState();
    }, this.abort.signal);
    subscribe('archive.reset', () => {
      this.state = { ...this.store.defaultCriteria(), aggregation: 'year', tableMode: 'nights', selectedType: '', selectedId: '' };
      this.applyState();
    }, this.abort.signal);
    subscribe('archive.night.selected', ({ date }) => this.select('night', date), this.abort.signal);
    subscribe('archive.performer.selected', ({ name }) => this.select('performer', name), this.abort.signal);
    subscribe('archive.record.selected', ({ id }) => this.select('record', id), this.abort.signal);
    subscribe('archive.selection.cleared', () => this.select('', ''), this.abort.signal);
    subscribe('archive.export', (options) => this.exportFiltered(options), this.abort.signal);
    subscribe('archive.export-night', ({ date }) => this.exportNight(date), this.abort.signal);
    subscribe('archive.copy-link', () => this.copyLink(), this.abort.signal);
  }

  stateFromUrl() {
    const params = new URLSearchParams(location.search);
    const defaults = this.store.defaultCriteria();
    const year = Number(params.get('year'));
    const minYear = Number(params.get('from')) || year || defaults.minYear;
    const maxYear = Number(params.get('to')) || year || defaults.maxYear;
    const requestedDate = params.get('date');
    const selectedDate = this.store.getShowNight(requestedDate) ? requestedDate : '';
    const performers = params.getAll('artist').filter((name) => this.store.performers.has(name));
    const selectedPerformer = params.get('performer');
    const recordId = params.get('record');
    const requestedMonth = Number(params.get('month'));
    const month = Number.isInteger(requestedMonth) && requestedMonth >= 1 && requestedMonth <= 12 ? requestedMonth - 1 : null;
    const complexity = ['all', 'one', '2plus', '3plus', 'explicit', 'multi-record'].includes(params.get('complexity')) ? params.get('complexity') : 'all';
    const recordType = ['all', 'explicit', 'single', 'multi-date'].includes(params.get('type')) ? params.get('type') : 'all';
    const requestedWeekday = params.get('weekday');
    const weekday = ['', '0', '1', '2', '3', '4', '5', '6'].includes(requestedWeekday ?? '') ? (requestedWeekday ?? '') : '';
    return {
      ...defaults,
      minYear: Math.max(this.store.minYear, Math.min(minYear, this.store.maxYear)),
      maxYear: Math.max(this.store.minYear, Math.min(maxYear, this.store.maxYear)),
      month,
      search: params.get('q') || '',
      performers,
      complexity,
      recordType,
      weekday,
      aggregation: ['year', 'month', 'decade'].includes(params.get('aggregate')) ? params.get('aggregate') : 'year',
      tableMode: params.get('view') === 'records' ? 'records' : 'nights',
      selectedType: selectedDate ? 'night' : this.store.performers.has(selectedPerformer) ? 'performer' : recordId ? 'record' : '',
      selectedId: selectedDate || (this.store.performers.has(selectedPerformer) ? selectedPerformer : '') || recordId || '',
    };
  }

  restoreFromUrl() {
    if (!this.store.records.length) return;
    this.state = this.stateFromUrl();
    this.applyState({ syncUrl: false });
  }

  renderShell() {
    this.innerHTML = `<header class="archive-header">
      <div class="archive-brand" aria-label="Mabuhay Gardens archive"><span class="brand-star">M</span><span><strong>MABUHAY</strong><small>GARDENS · SF</small></span></div>
      <button type="button" class="mobile-filter-toggle" data-mobile-filters aria-label="Open filters"><i class="fa-solid fa-sliders"></i></button>
      <div class="archive-title"><p class="eyebrow">San Francisco performance history</p><h1>Mabuhay Gardens Show Archive</h1><p data-archive-subtitle></p></div>
      <div class="header-actions">
        <span class="date-badge" data-date-badge><i class="fa-regular fa-calendar"></i></span>
        <button type="button" class="button secondary" data-reset><i class="fa-solid fa-rotate-left"></i><span>Reset view</span></button>
        <button type="button" class="button secondary" data-copy><i class="fa-regular fa-bookmark"></i><span>Copy view</span></button>
        <button type="button" class="button primary" data-export><i class="fa-solid fa-download"></i><span>Export JSON</span></button>
      </div>
    </header>
    <div class="archive-layout">
      <aside class="filters-sidebar" aria-label="Archive filters"><archive-filters></archive-filters></aside>
      <button type="button" class="filters-backdrop" data-mobile-filters-close aria-label="Close filters"></button>
      <main class="archive-main" id="archive-main">
        <archive-summary></archive-summary>
        <div class="chart-grid"><archive-timeline></archive-timeline><archive-heatmap></archive-heatmap></div>
        <archive-performers></archive-performers>
        <archive-explorer></archive-explorer>
        <footer class="archive-footer"><span>Mabuhay Gardens archive</span><span>Raw records are grouped by date for exploration; grouping does not assert a formal bill.</span></footer>
      </main>
      <archive-detail hidden></archive-detail>
    </div>
    <div class="archive-announcer" aria-live="polite" aria-atomic="true" data-announcer></div>`;

    this.querySelector('[data-reset]').addEventListener('click', () => publish('archive.reset', {}));
    this.querySelector('[data-copy]').addEventListener('click', () => this.copyLink());
    this.querySelector('[data-export]').addEventListener('click', () => this.exportFiltered({ format: 'json', mode: this.state.tableMode }));
    const toggleFilters = (open) => this.classList.toggle('filters-open', open);
    this.querySelector('[data-mobile-filters]').addEventListener('click', () => toggleFilters(true));
    this.querySelector('[data-mobile-filters-close]').addEventListener('click', () => toggleFilters(false));
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      toggleFilters(false);
      if (this.state.selectedType) publish('archive.selection.cleared', {});
    }, { signal: this.abort.signal });
  }

  applyState({ syncUrl = true, historyMode = 'push' } = {}) {
    if (this.state.minYear > this.state.maxYear) [this.state.minYear, this.state.maxYear] = [this.state.maxYear, this.state.minYear];
    this.result = this.store.filter(this.state);
    if (syncUrl) this.syncUrl(historyMode);
    this.updateHeader();
    this.querySelector('archive-filters')?.update(this.store, this.state);
    this.querySelector('archive-summary')?.update(this.store, this.result);
    this.querySelector('archive-timeline')?.update(this.store, this.result, this.state);
    this.querySelector('archive-heatmap')?.update(this.store, this.result);
    this.querySelector('archive-performers')?.update(this.store, this.result);
    this.querySelector('archive-explorer')?.update(this.store, this.result, this.state);
    this.querySelector('archive-detail')?.update(this.store, this.state);
    this.classList.toggle('detail-open', Boolean(this.state.selectedType));
    this.announce(`${number(this.result.nights.length)} documented nights in the current view.`);
  }

  updateHeader() {
    const full = this.store.summarize(this.store.showNights, this.store.records);
    const subtitle = this.querySelector('[data-archive-subtitle]');
    subtitle.textContent = `Explore ${number(full.records)} documented concert records spanning ${this.store.minYear}–${this.store.maxYear}`;
    const badge = this.querySelector('[data-date-badge]');
    badge.innerHTML = `<i class="fa-regular fa-calendar"></i><span>${this.state.month !== null ? `${MONTHS_LONG[this.state.month]} ` : ''}${this.state.minYear === this.state.maxYear ? this.state.minYear : `${this.state.minYear}–${this.state.maxYear}`}</span>`;
  }

  select(type, id) {
    this.state.selectedType = type;
    this.state.selectedId = id;
    this.applyState();
    if (type && matchMedia('(max-width: 1120px)').matches) requestAnimationFrame(() => this.querySelector('.detail-drawer button')?.focus());
  }

  syncUrl(mode = 'push') {
    const params = new URLSearchParams();
    if (this.state.minYear === this.state.maxYear) params.set('year', this.state.minYear);
    else {
      if (this.state.minYear !== this.store.minYear) params.set('from', this.state.minYear);
      if (this.state.maxYear !== this.store.maxYear) params.set('to', this.state.maxYear);
    }
    if (this.state.month !== null) params.set('month', this.state.month + 1);
    if (this.state.search) params.set('q', this.state.search);
    this.state.performers.forEach((name) => params.append('artist', name));
    if (this.state.complexity !== 'all') params.set('complexity', this.state.complexity);
    if (this.state.recordType !== 'all') params.set('type', this.state.recordType);
    if (this.state.weekday !== '') params.set('weekday', this.state.weekday);
    if (this.state.aggregation !== 'year') params.set('aggregate', this.state.aggregation);
    if (this.state.tableMode !== 'nights') params.set('view', this.state.tableMode);
    if (this.state.selectedType === 'night') params.set('date', this.state.selectedId);
    if (this.state.selectedType === 'performer') params.set('performer', this.state.selectedId);
    if (this.state.selectedType === 'record') params.set('record', this.state.selectedId);
    const url = `${location.pathname}${params.size ? `?${params}` : ''}${location.hash}`;
    if (url === `${location.pathname}${location.search}${location.hash}`) return;
    history[mode === 'replace' ? 'replaceState' : 'pushState']({}, '', url);
  }

  normalizedNight(night) {
    return {
      date: night.date,
      weekday: dateLabel(night.date, { weekday: 'long' }),
      documented_performers: night.performers,
      performer_count: night.performerCount,
      raw_record_count: night.recordCount,
      explicit_bills: night.explicitBills.map((record) => ({ name: record.name, performers: record.performers, url: record.url })),
      records: night.records.map((record) => record.original),
      interpretation_note: 'Records sharing a date document same-night activity but do not necessarily establish one formally advertised bill.',
    };
  }

  exportFiltered({ format = 'json', mode = 'nights' } = {}) {
    const date = new Date().toISOString().slice(0, 10);
    if (mode === 'records') {
      if (format === 'csv') {
        const header = ['date', 'record_name', 'performers', 'venue', 'concert_archives_url', 'source_files'];
        const rows = this.result.records.map((record) => [record.date, record.name, record.performers, record.venue.name, record.url, record.sourceFiles].map(csvCell).join(','));
        downloadFile(`mabuhay-raw-records-${date}.csv`, [header.join(','), ...rows].join('\n'), 'text/csv;charset=utf-8');
      } else {
        downloadFile(`mabuhay-raw-records-${date}.json`, JSON.stringify(this.result.records.map((record) => record.original), null, 2), 'application/json');
      }
    } else if (format === 'csv') {
      const header = ['date', 'weekday', 'documented_performers', 'performer_count', 'raw_record_count', 'explicit_bill_count', 'era'];
      const rows = this.result.nights.map((night) => [night.date, dateLabel(night.date, { weekday: 'long' }), night.performers, night.performerCount, night.recordCount, night.explicitBills.length, night.era].map(csvCell).join(','));
      downloadFile(`mabuhay-show-nights-${date}.csv`, [header.join(','), ...rows].join('\n'), 'text/csv;charset=utf-8');
    } else {
      downloadFile(`mabuhay-show-nights-${date}.json`, JSON.stringify(this.result.nights.map((night) => this.normalizedNight(night)), null, 2), 'application/json');
    }
    this.announce(`Exported ${mode === 'records' ? this.result.records.length : this.result.nights.length} ${mode}.`);
  }

  exportNight(date) {
    const night = this.store.getShowNight(date);
    if (!night) return;
    downloadFile(`mabuhay-${date}.json`, JSON.stringify(this.normalizedNight(night), null, 2), 'application/json');
    this.announce(`Exported ${date}.`);
  }

  async copyLink() {
    try {
      await navigator.clipboard.writeText(location.href);
      this.announce('Archive link copied to the clipboard.');
    } catch {
      window.prompt('Copy this archive link:', location.href);
    }
  }

  announce(message) {
    const node = this.querySelector('[data-announcer]');
    if (!node) return;
    node.textContent = '';
    requestAnimationFrame(() => { node.textContent = message; });
  }

  renderError(error) {
    this.innerHTML = `<main class="archive-error" role="alert">
      <span class="error-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
      <p class="eyebrow">Archive unavailable</p>
      <h1>Unable to load mab-shows.json</h1>
      <p>The browser could not fetch or parse the archive data. Confirm that <code>public/data/mab-shows.json</code> exists and that this page is served over HTTP or HTTPS.</p>
      <details><summary>Developer details</summary><pre>${esc(error?.stack || error?.message || error)}</pre></details>
      <button type="button" class="button primary" data-retry-load><i class="fa-solid fa-rotate-right"></i> Try again</button>
    </main>`;
    this.querySelector('[data-retry-load]')?.addEventListener('click', () => location.reload());
  }
}

customElements.define('mab-archive-app', MabArchiveApp);
