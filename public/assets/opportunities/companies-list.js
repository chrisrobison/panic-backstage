// <pb-opportunities-companies-list> — Opportunities > Companies (Phase 4:
// "make companies a useful account-level CRM view"). No dedicated mockup
// exists for the list view (only Discover and company-detail do — see
// docs/opportunity-ui/opportunity-3.png for the latter); built from the
// spec's explicit filter/sort/column list, following the exact same
// data-table + openModal() create-form pattern conferences-list.js already
// established.
import { esc, api, emptyState, openModal, formData, publish, PanicElement, $, $$ } from '../core.js';
import { relationshipStatusBadge, relativeTime, debounce } from './shared.js';

const SORTS = [
  ['name', 'Name'],
  ['pipeline_value', 'Pipeline Value'],
  ['open_opportunities', 'Open Opportunities'],
  ['last_activity', 'Last Activity'],
  ['conferences', 'Conferences'],
  ['research', 'Research Freshness'],
];

class OpportunitiesCompaniesList extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Opportunities › Companies', blurb: 'Prospect companies that could purchase a private event.' });
    this.filters = { q: '', industry: '', relationship_status: '', researched: '', sort: 'name' };
    this.companies = [];
    await this.load();
  }

  async load() {
    this.setLoading('Loading companies');
    try {
      const query = new URLSearchParams();
      if (this.filters.q) query.set('q', this.filters.q);
      if (this.filters.industry) query.set('industry', this.filters.industry);
      if (this.filters.relationship_status) query.set('relationship_status', this.filters.relationship_status);
      if (this.filters.researched !== '') query.set('researched', this.filters.researched);
      query.set('sort', this.filters.sort);

      const data = await api(`/opportunity-companies?${query.toString()}`);
      this.companies = data.companies || [];
      this.relationshipStatuses = data.relationship_statuses || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    this.innerHTML = `
      <div class="page-head">
        <div><h1>Companies</h1><p class="subtle">Organizations that could purchase a private event.</p></div>
        <button type="button" class="button primary" data-add>+ Add Company</button>
      </div>
      <div class="panel">
        <div class="section-head padded opp-conf-filters">
          <div class="inline-actions">
            <input type="search" placeholder="Search name, domain, HQ city…" data-q value="${esc(this.filters.q)}">
            <input type="text" placeholder="Industry" data-industry value="${esc(this.filters.industry)}" style="max-width:140px">
            <label class="select-inline"><span>Status</span>
              <select data-status>
                <option value="">Any</option>
                ${(this.relationshipStatuses || []).map((s) => `<option value="${esc(s)}" ${this.filters.relationship_status === s ? 'selected' : ''}>${esc(s.replace(/_/g, ' '))}</option>`).join('')}
              </select>
            </label>
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
            <th>Company</th><th>Industry</th><th>HQ</th><th>Status</th>
            <th>Open Opps.</th><th>Pipeline Value</th><th>Conferences</th><th>Last Activity</th>
          </tr></thead>
          <tbody>${this.rowsHtml()}</tbody>
        </table></div>
      </div>`;

    this.bind();
  }

  rowsHtml() {
    if (!this.companies.length) {
      return `<tr><td colspan="8">${emptyState('No companies match these filters.')}</td></tr>`;
    }
    return this.companies.map((c) => `<tr data-company-id="${esc(c.id)}" tabindex="0" role="button" aria-label="Open ${esc(c.name)}">
      <td><strong>${esc(c.name)}</strong>${c.domain ? `<br><small class="muted">${esc(c.domain)}</small>` : ''}</td>
      <td>${c.industry ? esc(c.industry) : '<span class="muted">—</span>'}</td>
      <td>${c.hq_city ? esc([c.hq_city, c.hq_state].filter(Boolean).join(', ')) : '<span class="muted">—</span>'}</td>
      <td>${relationshipStatusBadge(c.relationship_status)}</td>
      <td>${esc(c.open_opportunity_count ?? 0)}</td>
      <td>${c.pipeline_value ? `$${Number(c.pipeline_value).toLocaleString()}` : '<span class="muted">—</span>'}</td>
      <td>${esc(c.conference_count ?? 0)}</td>
      <td>${c.last_activity_at ? esc(relativeTime(c.last_activity_at)) : '<span class="muted">—</span>'}</td>
    </tr>`).join('');
  }

  bind() {
    $('[data-q]', this)?.addEventListener('input', debounce((e) => { this.filters.q = e.target.value; this.load(); }));
    $('[data-industry]', this)?.addEventListener('input', debounce((e) => { this.filters.industry = e.target.value; this.load(); }));
    $('[data-status]', this)?.addEventListener('change', (e) => { this.filters.relationship_status = e.target.value; this.load(); });
    $('[data-researched]', this)?.addEventListener('change', (e) => { this.filters.researched = e.target.value; this.load(); });
    $('[data-sort]', this)?.addEventListener('change', (e) => { this.filters.sort = e.target.value; this.load(); });
    $('[data-add]', this)?.addEventListener('click', () => this.openCreateModal());

    $$('[data-company-id]', this).forEach((row) => {
      const go = () => { location.hash = `#opportunities-company-${row.dataset.companyId}`; };
      row.addEventListener('click', go);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') go(); });
    });
  }

  openCreateModal() {
    const { dialog, close } = openModal({
      title: 'Add Company',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="new-company">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required></label>
        <label>Domain <input type="text" name="domain" placeholder="nvidia.com"></label>
        <label>Website <input type="url" name="website_url"></label>
        <label>Industry <input type="text" name="industry"></label>
        <label>Employee range <input type="text" name="employee_range" placeholder="1001-5000"></label>
        <label>HQ City <input type="text" name="hq_city"></label>
        <label>HQ State <input type="text" name="hq_state"></label>
        <label class="wide">Description <textarea name="description" rows="3"></textarea></label>
        <div class="wide"><button type="submit" class="primary">Add Company</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="new-company"]', dialog);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(form);
      if (!body.name?.trim()) return;
      try {
        const res = await api('/opportunity-companies', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${body.name} added.` });
        close();
        location.hash = `#opportunities-company-${res.company.id}`;
      } catch (err) {
        publish('toast.show', { message: err.message || 'Could not create company.', tone: 'error' });
      }
    });
  }
}
customElements.define('pb-opportunities-companies-list', OpportunitiesCompaniesList);
