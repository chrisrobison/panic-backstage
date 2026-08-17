import { MONTHS, MONTHS_LONG, PanicElement, esc, number, publish } from './archive-shared.js';

class ArchiveTimeline extends PanicElement {
  connect() {
    this.addEventListener('click', (event) => this.onClick(event), { signal: this.abort.signal });
    this.addEventListener('change', (event) => {
      if (event.target.matches('[data-aggregation]')) publish('archive.view.changed', { aggregation: event.target.value });
    }, { signal: this.abort.signal });
  }

  update(store, result, state) {
    this.store = store;
    this.result = result;
    this.state = state;
    this.render();
  }

  render() {
    if (!this.store || !this.result) return;
    const values = this.store.aggregate(this.result.nights, this.state.aggregation);
    const max = Math.max(1, ...values.map((item) => item.records));
    this.innerHTML = `<section class="archive-panel timeline-panel">
      <div class="panel-heading">
        <div><p class="eyebrow">Archive chronology</p><h2>Documented Performances Over Time</h2></div>
        <label class="compact-select"><span class="sr-only">Aggregate chart by</span><select data-aggregation>
          ${[['year', 'Year'], ['month', 'Month'], ['decade', 'Decade']].map(([value, label]) => `<option value="${value}" ${this.state.aggregation === value ? 'selected' : ''}>${label}</option>`).join('')}
        </select></label>
      </div>
      <p class="chart-note">Bars count raw archive records. Empty periods remain visible so gaps are not mistaken for continuous documentation.</p>
      <div class="timeline-scroll" tabindex="0" aria-label="Scrollable performance timeline">
        <div class="bar-chart ${this.state.aggregation}" style="--items:${values.length}">
          ${values.map((item, index) => this.bar(item, max, index, values.length)).join('')}
        </div>
      </div>
    </section>`;
  }

  bar(item, max, index, total) {
    const height = item.records ? Math.max(4, item.records / max * 100) : 1;
    const showLabel = total <= 20 || index % Math.max(1, Math.ceil(total / 14)) === 0 || index === total - 1;
    const title = `${item.label}: ${number(item.records)} raw records, ${number(item.nights)} documented nights, ${number(item.performerCount)} unique performers`;
    return `<button type="button" class="chart-bar ${item.records ? '' : 'zero'}" style="--bar-height:${height}%" data-period="${esc(item.key)}" data-unit="${this.state.aggregation}" title="${esc(title)}" aria-label="${esc(title)}">
      <span class="bar-value">${item.records ? number(item.records) : '0'}</span><span class="bar-fill"></span><span class="bar-label">${showLabel ? esc(this.shortLabel(item)) : ''}</span>
    </button>`;
  }

  shortLabel(item) {
    if (this.state.aggregation === 'month') return item.key.endsWith('-01') ? item.key.slice(0, 4) : MONTHS[Number(item.key.slice(5)) - 1];
    return item.label;
  }

  onClick(event) {
    const button = event.target.closest('[data-period]');
    if (!button) return;
    const { unit, period } = button.dataset;
    if (unit === 'year') publish('archive.filter.changed', { minYear: Number(period), maxYear: Number(period) });
    if (unit === 'decade') publish('archive.filter.changed', { minYear: Number(period), maxYear: Math.min(Number(period) + 9, this.store.maxYear) });
    if (unit === 'month') {
      const [year, month] = period.split('-').map(Number);
      publish('archive.filter.changed', { minYear: year, maxYear: year, month: month - 1 });
    }
  }
}

class ArchiveHeatmap extends PanicElement {
  connect() {
    this.addEventListener('click', (event) => {
      const cell = event.target.closest('[data-heat-year]');
      if (cell) publish('archive.filter.changed', { minYear: Number(cell.dataset.heatYear), maxYear: Number(cell.dataset.heatYear), month: Number(cell.dataset.heatMonth) });
    }, { signal: this.abort.signal });
  }

  update(store, result) {
    this.store = store;
    this.result = result;
    this.render();
  }

  render() {
    if (!this.store || !this.result) return;
    const { years, cells } = this.store.monthMatrix(this.result.nights);
    const max = Math.max(1, ...[...cells.values()].map((cell) => cell.records));
    this.innerHTML = `<section class="archive-panel heatmap-panel">
      <div class="panel-heading"><div><p class="eyebrow">Seasonality</p><h2>Activity by Month</h2></div></div>
      <p class="chart-note">Select a cell to focus the archive on that month.</p>
      <div class="heatmap-scroll" tabindex="0" aria-label="Year and month activity heatmap">
        <div class="heatmap" style="--years:${years.length}">
          <span></span>${years.map((year) => `<strong>${year}</strong>`).join('')}
          ${MONTHS.map((month, monthIndex) => `<span class="heat-label">${month}</span>${years.map((year) => this.cell(year, monthIndex, cells.get(`${year}-${monthIndex}`), max)).join('')}`).join('')}
        </div>
      </div>
      <div class="heat-legend"><span>Less activity</span><i></i><span>More activity</span></div>
    </section>`;
  }

  cell(year, month, cell, max) {
    const records = cell?.records || 0;
    const intensity = records / max;
    const title = `${MONTHS_LONG[month]} ${year}: ${records} concert records, ${cell?.nights || 0} documented dates, ${cell?.performers.size || 0} performers`;
    return `<button type="button" class="heat-cell ${records ? '' : 'empty'}" style="--intensity:${intensity}" data-heat-year="${year}" data-heat-month="${month}" title="${esc(title)}" aria-label="${esc(title)}"></button>`;
  }
}

customElements.define('archive-timeline', ArchiveTimeline);
customElements.define('archive-heatmap', ArchiveHeatmap);
