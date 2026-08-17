import { PanicElement, dateLabel, esc, number, plural, publish, sourceName, weekday } from './archive-shared.js';

class ArchiveDetail extends PanicElement {
  connect() {
    this.addEventListener('click', (event) => this.onClick(event), { signal: this.abort.signal });
  }

  update(store, state) {
    this.store = store;
    this.state = state;
    this.render();
  }

  render() {
    if (!this.store) return;
    if (!this.state.selectedType) {
      this.innerHTML = '';
      this.hidden = true;
      return;
    }
    this.hidden = false;
    let content = '';
    if (this.state.selectedType === 'night') content = this.nightHtml(this.store.getShowNight(this.state.selectedId));
    if (this.state.selectedType === 'performer') content = this.performerHtml(this.store.getPerformer(this.state.selectedId));
    if (this.state.selectedType === 'record') content = this.recordHtml(this.store.records.find((record) => record.id === this.state.selectedId));
    this.innerHTML = `<div class="detail-backdrop" data-detail-close aria-hidden="true"></div><aside class="detail-drawer" aria-label="Archive details" aria-live="polite">
      <header class="detail-header"><span>${this.typeLabel()}</span><button type="button" data-detail-close aria-label="Close details"><i class="fa-solid fa-xmark"></i></button></header>
      <div class="detail-content">${content || '<p class="empty-state">That archive item could not be found.</p>'}</div>
    </aside>`;
  }

  typeLabel() {
    return this.state.selectedType === 'night' ? 'Show Night' : this.state.selectedType === 'performer' ? 'Performer' : 'Raw Record';
  }

  nightHtml(night) {
    if (!night) return '';
    const allSources = [...new Set(night.records.flatMap((record) => record.sourceFiles))];
    return `<section class="detail-hero">
      <p class="eyebrow">${weekday(night.date)}</p>
      <h2>${dateLabel(night.date, { month: 'long', day: 'numeric', year: 'numeric' })}</h2>
      <div class="detail-stats"><span><strong>${number(night.performerCount)}</strong> performers</span><span><strong>${number(night.recordCount)}</strong> source records</span></div>
    </section>

    <section class="detail-section">
      <h3>Documented performers</h3>
      <div class="artist-chips">${night.performers.map((name) => `<button type="button" data-performer-name="${esc(name)}">${esc(name)}</button>`).join('')}</div>
    </section>

    <section class="detail-section">
      <div class="section-label"><h3>Explicit bills</h3><span>${night.explicitBills.length}</span></div>
      ${night.explicitBills.length ? night.explicitBills.map((record) => this.recordCard(record, true)).join('') : '<p class="detail-muted">No record on this date explicitly lists multiple artists together.</p>'}
    </section>

    ${night.recordCount > 1 ? `<section class="detail-section">
      <div class="section-label"><h3>Additional same-night records</h3><span>${night.recordCount}</span></div>
      <div class="ambiguity-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p>These separate records share a date. The source does not necessarily establish one formally advertised bill.</p></div>
      ${night.records.map((record) => this.recordCard(record, false)).join('')}
    </section>` : ''}

    <section class="detail-section">
      <h3>Source trail</h3>
      <div class="source-list">${allSources.map((path) => `<span class="source-pill"><i class="fa-regular fa-file-code" aria-hidden="true"></i>${esc(sourceName(path))}</span>`).join('')}</div>
      <div class="detail-links">${night.records.filter((record) => record.url).map((record, index) => `<a href="${esc(record.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open original record${night.records.length > 1 ? ` ${index + 1}` : ''}</a>`).join('')}</div>
    </section>

    <section class="detail-actions">
      <button type="button" class="button primary" data-copy-link><i class="fa-solid fa-link"></i> Copy night link</button>
      <button type="button" class="button secondary" data-export-night="${night.date}"><i class="fa-solid fa-download"></i> Export night JSON</button>
    </section>`;
  }

  recordCard(record, emphasize) {
    return `<article class="record-card ${emphasize ? 'explicit-card' : ''}">
      <div><span class="status-tag ${record.explicitBill ? 'explicit' : ''}">${record.explicitBill ? 'Explicit bill' : 'Single-artist record'}</span><strong>${esc(record.name)}</strong><small>${esc(record.performers.join(' · '))}</small></div>
      ${record.url ? `<a href="${esc(record.url)}" target="_blank" rel="noopener" aria-label="Open ${esc(record.name)}"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}
    </article>`;
  }

  performerHtml(stats) {
    if (!stats) return '';
    const dates = stats.dates;
    const related = stats.sameNightArtists.slice(0, 12);
    const minYear = stats.yearsActiveAtMab[0];
    const maxYear = stats.yearsActiveAtMab.at(-1);
    return `<section class="detail-hero performer-hero">
      <p class="eyebrow">Performer history</p>
      <h2>${esc(stats.name)}</h2>
      <div class="detail-stats"><span><strong>${number(stats.appearances)}</strong> raw appearances</span><span><strong>${number(stats.uniqueNights)}</strong> Mab nights</span><span><strong>${number(stats.yearsActiveAtMab.length)}</strong> years</span></div>
    </section>

    <section class="detail-section">
      <h3>First to last appearance</h3>
      <div class="performer-span"><span><strong>${minYear}</strong><small>${dateLabel(stats.firstAppearance, { month: 'short', day: 'numeric' })}</small></span><i></i><em>${number(stats.spanYears)} yr</em><span><strong>${maxYear}</strong><small>${dateLabel(stats.lastAppearance, { month: 'short', day: 'numeric' })}</small></span></div>
      <div class="appearance-timeline" aria-label="Appearance timeline from ${minYear} to ${maxYear}">
        ${stats.yearsActiveAtMab.map((year) => `<span style="--position:${maxYear === minYear ? 50 : (year - minYear) / (maxYear - minYear) * 100}%" title="${year}"></span>`).join('')}
      </div>
    </section>

    <section class="detail-section">
      <div class="section-label"><h3>Related performers</h3><span>${stats.sameNightArtists.length}</span></div>
      <p class="detail-muted">Relationships count shared dates; explicit co-billing is shown separately.</p>
      <div class="related-list">${related.map((item) => `<button type="button" data-performer-name="${esc(item.name)}">
        <span><strong>${esc(item.name)}</strong><small>${plural(item.sameNightCount, 'shared night')}</small></span>
        <span>${item.explicitBillCount ? `<b>${item.explicitBillCount} explicit</b>` : '<em>same-night only</em>'}<i class="fa-solid fa-chevron-right"></i></span>
      </button>`).join('') || '<p class="detail-muted">No related performers documented.</p>'}</div>
    </section>

    <section class="detail-section">
      <div class="section-label"><h3>Explicit bills</h3><span>${stats.explicitBills.length}</span></div>
      ${stats.explicitBills.slice(0, 10).map((record) => this.recordCard(record, true)).join('') || '<p class="detail-muted">No explicit multi-artist source records.</p>'}
    </section>

    <section class="detail-section">
      <div class="section-label"><h3>Complete night history</h3><span>${dates.length}</span></div>
      <div class="date-list">${dates.map((date) => `<button type="button" data-night-date="${date}"><time>${date}</time><span>${weekday(date, 'short')}</span><i class="fa-solid fa-chevron-right"></i></button>`).join('')}</div>
    </section>

    <section class="detail-actions"><button type="button" class="button primary" data-copy-link><i class="fa-solid fa-link"></i> Copy performer link</button></section>`;
  }

  recordHtml(record) {
    if (!record) return '';
    return `<section class="detail-hero"><p class="eyebrow">${weekday(record.date)}</p><h2>${esc(record.name)}</h2><p class="detail-date"><i class="fa-regular fa-calendar"></i> ${dateLabel(record.date)}</p></section>
      <section class="detail-section"><h3>Record classification</h3><span class="status-tag ${record.explicitBill ? 'explicit' : ''}">${record.explicitBill ? 'Explicit multi-artist bill' : 'Single-artist record'}</span></section>
      <section class="detail-section"><h3>Performers</h3><div class="artist-chips">${record.performers.map((name) => `<button type="button" data-performer-name="${esc(name)}">${esc(name)}</button>`).join('')}</div></section>
      <section class="detail-section"><h3>Source files</h3><div class="source-list">${record.sourceFiles.map((path) => `<span class="source-pill"><i class="fa-regular fa-file-code"></i>${esc(sourceName(path))}</span>`).join('')}</div></section>
      <section class="detail-actions">
        ${record.url ? `<a class="button primary" href="${esc(record.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open original record</a>` : ''}
        <button type="button" class="button secondary" data-night-date="${record.date}"><i class="fa-regular fa-moon"></i> View show night</button>
        <button type="button" class="button secondary" data-copy-link><i class="fa-solid fa-link"></i> Copy record link</button>
      </section>`;
  }

  onClick(event) {
    if (event.target.closest('[data-detail-close]')) return publish('archive.selection.cleared', {});
    const performer = event.target.closest('[data-performer-name]');
    if (performer) return publish('archive.performer.selected', { name: performer.dataset.performerName });
    const night = event.target.closest('[data-night-date]');
    if (night) return publish('archive.night.selected', { date: night.dataset.nightDate });
    if (event.target.closest('[data-copy-link]')) return publish('archive.copy-link', {});
    const exportNight = event.target.closest('[data-export-night]');
    if (exportNight) publish('archive.export-night', { date: exportNight.dataset.exportNight });
  }
}

customElements.define('archive-detail', ArchiveDetail);
