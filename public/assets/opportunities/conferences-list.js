// <pb-opportunities-conferences-list> — Opportunities > Conferences
// (Phase 3: "make conferences a useful research and prospecting surface").
// No dedicated mockup exists for the list view (only the dashboard and
// conference-detail mockups do); built from the spec's explicit filter/sort/
// column list, following the same data-table + openModal() create-form
// pattern already established by discover-page.js and leads.js.
import { esc, api, emptyState, openModal, formData, publish, subscribe, PanicElement, $, $$ } from '../core.js';
import { shortMonthDay, dateRangeLabel, scoreTone, debounce } from './shared.js';

const SORTS = [
  ['date', 'Date'],
  ['score', 'Opportunity Score'],
  ['attendance', 'Attendance'],
  ['proximity', 'Proximity'],
  ['target_companies', 'Target Companies'],
];

class OpportunitiesConferencesList extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Opportunities › Conferences', blurb: 'Conference/trade-show source-of-demand records.' });
    this.filters = { q: '', tab: 'upcoming', city: '', researched: '', min_score: '', sort: 'date' };
    this.conferences = [];
    this.reloadDebounced = debounce(() => this.load(), 300);
    // Phase 8: this list previously only ever fetched once — another
    // user adding a conference, linking a company, or completing a task
    // now shows up without a manual reload.
    subscribe('data.invalidated', (msg) => {
      if (msg.entity === 'opportunity_conference' || msg.entity === 'global') this.reloadDebounced();
    }, this.abort.signal);
    await this.load();
  }

  async load() {
    this.setLoading('Loading conferences');
    try {
      const query = new URLSearchParams();
      if (this.filters.q) query.set('q', this.filters.q);
      if (this.filters.city) query.set('city', this.filters.city);
      if (this.filters.researched !== '') query.set('researched', this.filters.researched);
      if (this.filters.min_score) query.set('min_score', this.filters.min_score);
      query.set('sort', this.filters.sort);
      if (this.filters.tab === 'upcoming') query.set('upcoming', '1');
      if (this.filters.tab === 'past') query.set('past', '1');

      const data = await api(`/opportunity-conferences?${query.toString()}`);
      this.conferences = data.conferences || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    this.innerHTML = `
      <div class="page-head">
        <div><h1>Conferences</h1><p class="subtle">Trade shows and conventions that create demand for private events.</p></div>
        <button type="button" class="button primary" data-add>+ Add Conference</button>
      </div>
      <div class="panel">
        <div class="section-head padded opp-conf-filters">
          <nav class="tabs" data-tabs>
            ${['upcoming', 'past', 'all'].map((t) => `<button type="button" data-tab="${t}" class="${this.filters.tab === t ? 'active' : ''}">${esc(t[0].toUpperCase() + t.slice(1))}</button>`).join('')}
          </nav>
          <div class="inline-actions">
            <input type="search" placeholder="Search name, city, state…" data-q value="${esc(this.filters.q)}">
            <input type="text" placeholder="City" data-city value="${esc(this.filters.city)}" style="max-width:140px">
            <label class="select-inline"><span>Researched</span>
              <select data-researched>
                <option value="">Any</option>
                <option value="1" ${this.filters.researched === '1' ? 'selected' : ''}>Researched</option>
                <option value="0" ${this.filters.researched === '0' ? 'selected' : ''}>Unresearched</option>
              </select>
            </label>
            <label class="select-inline"><span>Sort</span>
              <select data-sort>${SORTS.map(([v, l]) => `<option value="${v}" ${this.filters.sort === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
            </label>
          </div>
        </div>
        <div class="table-scroll"><table class="data-table">
          <thead><tr>
            <th>Conference</th><th>Dates</th><th>City</th><th>Distance</th>
            <th>Attendance</th><th>Target Cos.</th><th>Signals</th><th>Tasks</th><th>Score</th><th>Researched</th>
          </tr></thead>
          <tbody>${this.rowsHtml()}</tbody>
        </table></div>
      </div>`;

    this.bind();
  }

  rowsHtml() {
    if (!this.conferences.length) {
      return `<tr><td colspan="10">${emptyState('No conferences match these filters.')}</td></tr>`;
    }
    return this.conferences.map((c) => `<tr data-conf-id="${esc(c.id)}" tabindex="0" role="button" aria-label="Open ${esc(c.name)}">
      <td><strong>${esc(c.name)}</strong></td>
      <td>${esc(dateRangeLabel(c.starts_at, c.ends_at))}</td>
      <td>${c.city ? esc([c.city, c.state].filter(Boolean).join(', ')) : '<span class="muted">—</span>'}</td>
      <td>${c.distance_from_venue_miles != null ? `${Number(c.distance_from_venue_miles).toFixed(1)} mi` : '<span class="muted">Unknown</span>'}</td>
      <td>${c.estimated_attendance != null ? Number(c.estimated_attendance).toLocaleString() : '<span class="muted">—</span>'}</td>
      <td>${esc(c.target_company_count ?? 0)}</td>
      <td>${esc(c.side_event_signal_count ?? 0)}</td>
      <td>${this.taskCellHtml(c)}</td>
      <td>${c.opportunity_score != null ? `<span class="${scoreTone(c.opportunity_score)}">${esc(c.opportunity_score)}</span>` : '<span class="muted">—</span>'}</td>
      <td>${c.last_researched_at ? esc(shortMonthDay(c.last_researched_at.slice(0, 10))) : '<span class="badge">Unresearched</span>'}</td>
    </tr>`).join('');
  }

  /** Task count + overdue badge (Phase 8 — Conferences::index()'s task_count/overdue_task_count aggregates). */
  taskCellHtml(row) {
    const count = row.task_count ?? 0;
    if (!count) return '<span class="muted">—</span>';
    const overdue = row.overdue_task_count ?? 0;
    return `${esc(count)}${overdue ? ` <span class="pill pill-danger">${esc(overdue)} overdue</span>` : ''}`;
  }

  bind() {
    $$('[data-tab]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.filters.tab = btn.dataset.tab;
      this.load();
    }));
    $('[data-q]', this)?.addEventListener('input', debounce((e) => { this.filters.q = e.target.value; this.load(); }));
    $('[data-city]', this)?.addEventListener('input', debounce((e) => { this.filters.city = e.target.value; this.load(); }));
    $('[data-researched]', this)?.addEventListener('change', (e) => { this.filters.researched = e.target.value; this.load(); });
    $('[data-sort]', this)?.addEventListener('change', (e) => { this.filters.sort = e.target.value; this.load(); });
    $('[data-add]', this)?.addEventListener('click', () => this.openCreateModal());

    $$('[data-conf-id]', this).forEach((row) => {
      const go = () => { location.hash = `#opportunities-conference-${row.dataset.confId}`; };
      row.addEventListener('click', go);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') go(); });
    });
  }

  openCreateModal() {
    const { dialog, close } = openModal({
      title: 'Add Conference',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="new-conference">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required></label>
        <label>Starts <input type="date" name="starts_at"></label>
        <label>Ends <input type="date" name="ends_at"></label>
        <label>City <input type="text" name="city"></label>
        <label>State <input type="text" name="state"></label>
        <label>Venue name <input type="text" name="venue_name"></label>
        <label>Website <input type="url" name="website_url"></label>
        <label>Est. attendance <input type="number" min="0" name="estimated_attendance"></label>
        <label>Est. exhibitors <input type="number" min="0" name="estimated_exhibitors"></label>
        <label>Est. sponsors <input type="number" min="0" name="estimated_sponsors"></label>
        <label class="wide">Description <textarea name="description" rows="3"></textarea></label>
        <div class="wide"><button type="submit" class="primary">Add Conference</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="new-conference"]', dialog);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(form);
      if (!body.name?.trim()) return;
      try {
        const res = await api('/opportunity-conferences', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${body.name} added.` });
        close();
        location.hash = `#opportunities-conference-${res.conference.id}`;
      } catch (err) {
        publish('toast.show', { message: err.message || 'Could not create conference.', tone: 'error' });
      }
    });
  }
}
customElements.define('pb-opportunities-conferences-list', OpportunitiesConferencesList);
