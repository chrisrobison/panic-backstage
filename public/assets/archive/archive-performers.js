import { PanicElement, dateLabel, esc, number, plural, publish } from './archive-shared.js';

class ArchivePerformers extends PanicElement {
  connect() {
    this.metric = 'appearances';
    this.addEventListener('change', (event) => {
      if (event.target.matches('[data-rank-metric]')) {
        this.metric = event.target.value;
        this.render();
      }
    }, { signal: this.abort.signal });
    this.addEventListener('click', (event) => {
      const performer = event.target.closest('[data-performer-name]');
      const night = event.target.closest('[data-night-date]');
      const weekday = event.target.closest('[data-weekday]');
      if (performer) publish('archive.performer.selected', { name: performer.dataset.performerName });
      if (night) publish('archive.night.selected', { date: night.dataset.nightDate });
      if (weekday) publish('archive.filter.changed', { weekday: weekday.dataset.weekday });
    }, { signal: this.abort.signal });
  }

  update(store, result) {
    this.store = store;
    this.result = result;
    this.render();
  }

  render() {
    if (!this.store || !this.result) return;
    const ranking = this.store.performerRanking(this.result.nights, this.metric).slice(0, 10);
    const maxRank = Math.max(1, ...ranking.map((item) => item.value));
    const busy = [...this.result.nights].sort((a, b) => this.busyScore(b) - this.busyScore(a) || b.date.localeCompare(a.date)).slice(0, 6);
    const spanning = this.store.performerRanking(this.result.nights, 'span').filter((item) => item.value > 0).slice(0, 5);
    const weekdays = this.store.weekdayStats(this.result.nights);
    const maxWeekday = Math.max(1, ...weekdays.map((item) => item.count));
    const distribution = this.distribution();

    this.innerHTML = `<div class="insight-grid">
      <section class="archive-panel performer-leaderboard">
        <div class="panel-heading">
          <div><p class="eyebrow">Artist index</p><h2>Most Frequent Performers</h2></div>
          <label class="compact-select"><span class="sr-only">Rank artists by</span><select data-rank-metric>
            ${[['appearances', 'Raw appearances'], ['nights', 'Unique nights'], ['years', 'Years represented'], ['span', 'First-to-last span']].map(([value, label]) => `<option value="${value}" ${this.metric === value ? 'selected' : ''}>${label}</option>`).join('')}
          </select></label>
        </div>
        <div class="leader-list">${ranking.map((item, index) => `<button type="button" data-performer-name="${esc(item.name)}">
          <span class="leader-rank">${String(index + 1).padStart(2, '0')}</span><span class="leader-name">${esc(item.name)}</span>
          <span class="leader-track"><i style="width:${Math.max(3, item.value / maxRank * 100)}%"></i></span><strong>${number(item.value)}${this.metric === 'span' ? ' yr' : ''}</strong>
        </button>`).join('') || '<p class="empty-state">No performers match this view.</p>'}</div>
      </section>

      <section class="archive-panel busy-panel">
        <div class="panel-heading"><div><p class="eyebrow">Dense documentation</p><h2>Busy Nights</h2></div></div>
        <p class="chart-note">Ranked from documented performers, source records, and explicit bills—not a claim about a formal lineup.</p>
        <div class="busy-list">${busy.map((night) => `<button type="button" data-night-date="${night.date}">
          <time datetime="${night.date}"><strong>${dateLabel(night.date, { month: 'short', day: 'numeric' })}</strong><span>${night.year}</span></time>
          <span><strong>${esc(night.performers.slice(0, 3).join(' · '))}${night.performers.length > 3 ? ` +${night.performers.length - 3}` : ''}</strong><small>${plural(night.performerCount, 'performer')} · ${plural(night.recordCount, 'record')}</small></span>
          <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </button>`).join('') || '<p class="empty-state">No documented nights match this view.</p>'}</div>
      </section>
    </div>

    <div class="insight-grid lower">
      <section class="archive-panel then-now-panel">
        <div class="panel-heading"><div><p class="eyebrow">Continuity</p><h2>Then &amp; Now</h2></div></div>
        <p class="chart-note">Performers with the longest span between first and last documented Mab appearances in this view.</p>
        <div class="span-list">${spanning.map((item) => `<button type="button" data-performer-name="${esc(item.name)}">
          <span class="span-title"><strong>${esc(item.name)}</strong><em>${number(item.value)}-year span</em></span>
          <span class="span-axis"><b>${item.filteredNights[0].year}</b><i></i><b>${item.filteredNights.at(-1).year}</b></span>
        </button>`).join('') || '<p class="empty-state">No performers span multiple years in this view.</p>'}</div>
      </section>

      <section class="archive-panel distribution-panel">
        <div class="panel-heading"><div><p class="eyebrow">The long tail</p><h2>Scene Regulars vs Visitors</h2></div></div>
        <p class="chart-note">Artists grouped by raw appearance count within the current view.</p>
        <div class="distribution-chart">${distribution.map((bucket) => `<div>
          <span class="distribution-value"><strong>${bucket.count}</strong><small>${bucket.percent}%</small></span>
          <span class="distribution-track"><i style="height:${Math.max(3, bucket.percent * 2.4)}px"></i></span><b>${bucket.label}</b>
        </div>`).join('')}</div>
      </section>

      <section class="archive-panel weekday-panel">
        <div class="panel-heading"><div><p class="eyebrow">Weekly rhythm</p><h2>Nights by Weekday</h2></div></div>
        <p class="chart-note">Counts unique documented dates, not raw records.</p>
        <div class="weekday-chart">${weekdays.map((item) => `<button type="button" data-weekday="${item.day}" title="Filter to ${item.label}">
          <span>${item.label.slice(0, 3)}</span><i style="height:${Math.max(2, item.count / maxWeekday * 88)}px"></i><strong>${number(item.count)}</strong>
        </button>`).join('')}</div>
      </section>
    </div>`;
  }

  busyScore(night) {
    return night.performerCount * 5 + night.recordCount * 3 + night.explicitBills.length * 2 + (night.recordCount > 1 && night.explicitBills.length ? 3 : 0);
  }

  distribution() {
    const ranked = this.store.performerRanking(this.result.nights, 'appearances');
    const buckets = [
      { label: '1', test: (n) => n === 1 },
      { label: '2', test: (n) => n === 2 },
      { label: '3–4', test: (n) => n >= 3 && n <= 4 },
      { label: '5–9', test: (n) => n >= 5 && n <= 9 },
      { label: '10–19', test: (n) => n >= 10 && n <= 19 },
      { label: '20+', test: (n) => n >= 20 },
    ];
    return buckets.map((bucket) => {
      const count = ranked.filter((item) => bucket.test(item.value)).length;
      return { ...bucket, count, percent: ranked.length ? Math.round(count / ranked.length * 100) : 0 };
    });
  }
}

customElements.define('archive-performers', ArchivePerformers);
