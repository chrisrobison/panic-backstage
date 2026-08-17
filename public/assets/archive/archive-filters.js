import { PanicElement, debounce, esc, number, publish } from './archive-shared.js';

class ArchiveFilters extends PanicElement {
  connect() {
    this.performerQuery = '';
    this.addEventListener('input', (event) => this.onInput(event), { signal: this.abort.signal });
    this.addEventListener('change', (event) => this.onChange(event), { signal: this.abort.signal });
    this.addEventListener('click', (event) => this.onClick(event), { signal: this.abort.signal });
    this.debouncedSearch = debounce((value) => publish('archive.filter.changed', { search: value, history: 'replace' }), 240);
  }

  update(store, state) {
    this.store = store;
    this.state = state;
    this.render();
  }

  render() {
    if (!this.store || !this.state) return;
    const selected = new Set(this.state.performers || []);
    const ranked = [...this.store.performers.values()]
      .filter((item) => !this.performerQuery || item.name.toLocaleLowerCase().includes(this.performerQuery.toLocaleLowerCase()))
      .sort((a, b) => b.appearances - a.appearances || a.name.localeCompare(b.name));
    const visible = [...ranked.filter((item) => selected.has(item.name)), ...ranked.filter((item) => !selected.has(item.name))].slice(0, 10);
    const decades = [];
    for (let decade = Math.floor(this.store.minYear / 10) * 10; decade <= this.store.maxYear; decade += 10) {
      const active = this.store.decades.includes(decade);
      decades.push(`<button type="button" class="filter-chip ${this.state.minYear === decade && this.state.maxYear === Math.min(decade + 9, this.store.maxYear) ? 'active' : ''}" data-decade="${decade}" ${active ? '' : 'disabled'}>${decade}s</button>`);
    }
    this.innerHTML = `
      <div class="filter-heading">
        <h2><i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters</h2>
        <button type="button" class="text-button" data-filter-reset>Reset</button>
      </div>
      <label class="field-label" for="archive-search">Search</label>
      <div class="search-field"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="archive-search" data-global-search type="search" value="${esc(this.state.search)}" placeholder="Search performers or shows" autocomplete="off"></div>

      <fieldset class="filter-group">
        <legend>Date range</legend>
        ${this.state.month !== null ? `<div class="selected-filters"><button type="button" data-clear-month>${esc(new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2000, this.state.month, 1)))} focus <i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>` : ''}
        <div class="range-values"><output>${this.state.minYear}</output><span>through</span><output>${this.state.maxYear}</output></div>
        <label class="sr-only" for="archive-year-start">Start year</label>
        <input id="archive-year-start" data-year-min type="range" min="${this.store.minYear}" max="${this.store.maxYear}" value="${this.state.minYear}">
        <label class="sr-only" for="archive-year-end">End year</label>
        <input id="archive-year-end" data-year-max type="range" min="${this.store.minYear}" max="${this.store.maxYear}" value="${this.state.maxYear}">
        <div class="decade-chips">${decades.join('')}</div>
      </fieldset>

      <fieldset class="filter-group">
        <legend>Performers</legend>
        ${selected.size ? `<div class="selected-filters">${[...selected].map((name) => `<button type="button" data-remove-performer="${esc(name)}">${esc(name)} <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>`).join('')}</div>` : ''}
        <div class="mini-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-performer-search type="search" value="${esc(this.performerQuery)}" placeholder="Find an artist" aria-label="Find a performer"></div>
        <div class="performer-options" aria-label="Performer filters">
          ${visible.map((item) => `<label><input type="checkbox" data-performer="${esc(item.name)}" ${selected.has(item.name) ? 'checked' : ''}><span>${esc(item.name)}</span><small>${number(item.appearances)}</small></label>`).join('')}
          ${!visible.length ? '<p class="empty-mini">No matching performers</p>' : ''}
        </div>
      </fieldset>

      <fieldset class="filter-group">
        <legend>Night complexity</legend>
        <select data-filter-key="complexity" aria-label="Night complexity">
          ${this.options([
            ['all', 'All nights'], ['one', 'One documented performer'], ['2plus', '2+ documented performers'], ['3plus', '3+ documented performers'], ['explicit', 'Explicit multi-act bills'], ['multi-record', 'Same-date multiple records'],
          ], this.state.complexity)}
        </select>
      </fieldset>

      <fieldset class="filter-group">
        <legend>Record type</legend>
        <select data-filter-key="recordType" aria-label="Record type">
          ${this.options([['all', 'All records'], ['explicit', 'Explicit multi-artist records'], ['single', 'Single-artist records'], ['multi-date', 'Dates containing multiple records']], this.state.recordType)}
        </select>
      </fieldset>

      <fieldset class="filter-group">
        <legend>Weekday</legend>
        <select data-filter-key="weekday" aria-label="Weekday">
          ${this.options([['', 'All weekdays'], ['1', 'Monday'], ['2', 'Tuesday'], ['3', 'Wednesday'], ['4', 'Thursday'], ['5', 'Friday'], ['6', 'Saturday'], ['0', 'Sunday']], String(this.state.weekday))}
        </select>
      </fieldset>

      <aside class="meaning-card">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <p><strong>How to read the archive</strong>An explicit bill names multiple artists in one record. Same-night records share a date, but may not describe one formally advertised lineup.</p>
      </aside>`;
  }

  options(items, current) {
    return items.map(([value, label]) => `<option value="${value}" ${value === current ? 'selected' : ''}>${label}</option>`).join('');
  }

  onInput(event) {
    if (event.target.matches('[data-global-search]')) this.debouncedSearch(event.target.value);
    if (event.target.matches('[data-performer-search]')) {
      this.performerQuery = event.target.value;
      const position = event.target.selectionStart;
      this.render();
      const input = this.querySelector('[data-performer-search]');
      input?.focus();
      input?.setSelectionRange(position, position);
    }
    if (event.target.matches('[data-year-min], [data-year-max]')) {
      const minInput = this.querySelector('[data-year-min]');
      const maxInput = this.querySelector('[data-year-max]');
      let minYear = Number(minInput.value);
      let maxYear = Number(maxInput.value);
      if (event.target.matches('[data-year-min]') && minYear > maxYear) maxYear = minYear;
      if (event.target.matches('[data-year-max]') && maxYear < minYear) minYear = maxYear;
      publish('archive.filter.changed', { minYear, maxYear, history: 'replace' });
    }
  }

  onChange(event) {
    if (event.target.matches('[data-performer]')) {
      const performers = new Set(this.state.performers || []);
      event.target.checked ? performers.add(event.target.dataset.performer) : performers.delete(event.target.dataset.performer);
      publish('archive.filter.changed', { performers: [...performers] });
    }
    if (event.target.matches('[data-filter-key]')) publish('archive.filter.changed', { [event.target.dataset.filterKey]: event.target.value });
  }

  onClick(event) {
    const decade = event.target.closest('[data-decade]');
    if (decade) {
      const start = Number(decade.dataset.decade);
      publish('archive.filter.changed', { minYear: start, maxYear: Math.min(start + 9, this.store.maxYear) });
    }
    const remove = event.target.closest('[data-remove-performer]');
    if (remove) publish('archive.filter.changed', { performers: this.state.performers.filter((name) => name !== remove.dataset.removePerformer) });
    if (event.target.closest('[data-clear-month]')) publish('archive.filter.changed', { month: null });
    if (event.target.closest('[data-filter-reset]')) publish('archive.reset', {});
  }
}

customElements.define('archive-filters', ArchiveFilters);
