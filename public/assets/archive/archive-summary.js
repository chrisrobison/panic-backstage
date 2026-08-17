import { PanicElement, esc, number, plural } from './archive-shared.js';

class ArchiveSummary extends PanicElement {
  update(store, result) {
    this.store = store;
    this.result = result;
    this.render();
  }

  render() {
    if (!this.store || !this.result) return;
    const stats = this.store.summarize(this.result.nights, this.result.records);
    const cards = [
      ['fa-ticket', 'Concert records', stats.records, 'Raw source objects', 'teal'],
      ['fa-moon', 'Documented nights', stats.nights, 'Unique calendar dates', 'red'],
      ['fa-users', 'Unique performers', stats.performers, 'In the current view', 'teal'],
      ['fa-calendar', 'Years represented', stats.years, stats.busiestYear ? `Busiest: ${stats.busiestYear.year}` : 'No active years', 'teal'],
      ['fa-layer-group', 'Explicit bills', stats.explicit, 'Multi-artist source records', 'red'],
    ];
    this.innerHTML = `<section class="summary-grid" aria-label="Archive summary">
      ${cards.map(([icon, label, value, detail, tone]) => `<article class="summary-card ${tone}">
        <span class="summary-icon" aria-hidden="true"><i class="fa-solid ${icon}"></i></span>
        <div><p>${esc(label)}</p><strong>${number(value)}</strong><small>${esc(detail)}</small></div>
      </article>`).join('')}
    </section>
    <p class="result-context" aria-live="polite">Current view: ${plural(stats.records, 'raw record')} across ${plural(stats.nights, 'documented night')}.</p>`;
  }
}

customElements.define('archive-summary', ArchiveSummary);
