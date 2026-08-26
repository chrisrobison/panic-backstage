// <pb-opportunities-pipeline> — Opportunities > Pipeline (Phase 5:
// docs/opportunity-ui/opportunity-5.png). Kanban board over the existing
// GET /api/opportunities list endpoint (Phase 1), which now also returns
// per-card `note_count`/`task_count`/`tasks_due_soon`/`warnings` aggregates
// (Opportunities::attachPipelineAggregates()) so this page never fetches
// per-card. Reuses the existing `.pipeline-board`/`.pipe-col`/`.pipe-card`
// kanban CSS the Events pipeline (event-views.js) already established —
// only a thin `.opp-*` layer of card-content styling is new (see app.css).
//
// Stage changes have TWO paths, per the spec's "implement stage-change
// controls first, then progressively enhance with HTML5 drag/drop": every
// card's <select> is the accessible, always-present control; native HTML5
// drag-and-drop onto a column is a progressive enhancement on top of the
// same PATCH call. No third-party drag/drop package.
import { esc, api, emptyState, openModal, formData, publish, subscribe, getAppCapabilities, PanicElement, $, $$ } from '../core.js';
import { STAGES, stageLabel, warningLabel, shortMonthDay, debounce } from './shared.js';

const COLUMNS = [
  { key: 'new_signal',    label: 'New Signals',    stages: ['new_signal'] },
  { key: 'researching',   label: 'Researching',    stages: ['researching'] },
  { key: 'contacted',     label: 'Contacted',      stages: ['contacted'] },
  { key: 'qualified',     label: 'Qualified',      stages: ['qualified'] },
  { key: 'proposal_sent', label: 'Proposal Sent',  stages: ['proposal_sent'] },
  { key: 'verbal_yes',    label: 'Verbal Yes',     stages: ['verbal_yes'] },
  { key: 'won',           label: 'Won',            stages: ['won'] },
  { key: 'lost_nurture',  label: 'Lost / Nurture', stages: ['lost', 'nurture'] },
];

const DATE_RANGES = [
  ['', 'Any Date'],
  ['30', 'Next 30 Days'],
  ['60', 'Next 60 Days'],
  ['90', 'Next 90 Days'],
  ['180', 'Next 180 Days'],
  ['quarter', 'This Quarter'],
];

const VALUE_RANGES = [
  ['', 'Any Value'],
  ['0-25000', 'Under $25k'],
  ['25000-50000', '$25k – $50k'],
  ['50000-100000', '$50k – $100k'],
  ['100000-', '$100k+'],
];

class OpportunitiesPipelineBoard extends PanicElement {
  async connect() {
    this.canManage = !!getAppCapabilities().manage_opportunities;
    publish('page.context', { title: 'Opportunity Pipeline', blurb: 'Track prospects from signal to booked event.' });
    this.filters = { q: '', conference_id: '', owner_id: '', event_type: '', date_range: '', value_range: '', stale_only: false };
    this.opportunities = [];
    this.conferences = [];
    this.users = [];
    this.reloadDebounced = debounce(() => this.load(), 300);
    subscribe('data.invalidated', (msg) => {
      if (msg.entity === 'opportunity') this.reloadDebounced();
    }, this.abort.signal);
    await this.load();
  }

  async load() {
    this.setLoading('Loading pipeline');
    try {
      const [oppRes, confRes] = await Promise.all([
        api('/opportunities'),
        api('/opportunity-conferences?upcoming=1'),
      ]);
      this.opportunities = oppRes.opportunities || [];
      this.users = oppRes.users || [];
      this.conferences = confRes.conferences || [];
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  // ── Client-side filtering (conference/owner already available as real
  // query params on the backend, but re-filtering a single already-fetched
  // ≤200-row page in place keeps every other filter change instant with no
  // extra round trip — same tradeoff PipelineBoard (event-views.js) makes
  // for its own date-range filter). ──────────────────────────────────────
  visibleOpportunities() {
    const f = this.filters;
    const q = f.q.trim().toLowerCase();
    const [valMin, valMax] = f.value_range ? f.value_range.split('-').map((v) => (v === '' ? null : Number(v))) : [null, null];
    let dateCeiling = null;
    let quarterOnly = false;
    if (f.date_range === 'quarter') {
      quarterOnly = true;
    } else if (f.date_range) {
      dateCeiling = new Date();
      dateCeiling.setDate(dateCeiling.getDate() + Number(f.date_range));
    }
    return this.opportunities.filter((o) => {
      if (q && !`${o.name} ${o.company_name} ${o.conference_name || ''}`.toLowerCase().includes(q)) return false;
      if (f.conference_id && String(o.conference_id) !== f.conference_id) return false;
      if (f.owner_id && String(o.owner_user_id) !== f.owner_id) return false;
      if (f.event_type && o.event_type !== f.event_type) return false;
      if (f.stale_only && !(o.warnings || []).includes('stale')) return false;
      if (valMin != null && (o.estimated_value == null || Number(o.estimated_value) < valMin)) return false;
      if (valMax != null && (o.estimated_value == null || Number(o.estimated_value) > valMax)) return false;
      if (quarterOnly) {
        if (!o.target_date) return false;
        const d = new Date(`${o.target_date}T12:00:00`);
        const now = new Date();
        if (Math.floor(d.getMonth() / 3) !== Math.floor(now.getMonth() / 3) || d.getFullYear() !== now.getFullYear()) return false;
      } else if (dateCeiling) {
        if (!o.target_date) return false;
        if (new Date(`${o.target_date}T12:00:00`) > dateCeiling) return false;
      }
      return true;
    });
  }

  summary(visible) {
    const open = visible.filter((o) => !['won', 'lost'].includes(o.stage));
    const totalValue = open.reduce((sum, o) => sum + Number(o.estimated_value || 0), 0);
    const weighted = open.reduce((sum, o) => sum + Number(o.estimated_value || 0) * (Number(o.probability || 0) / 100), 0);
    const now = new Date();
    const quarterValue = open
      .filter((o) => o.target_date && Math.floor(new Date(`${o.target_date}T12:00:00`).getMonth() / 3) === Math.floor(now.getMonth() / 3))
      .reduce((sum, o) => sum + Number(o.estimated_value || 0) * (Number(o.probability || 0) / 100), 0);
    const stale = open.filter((o) => (o.warnings || []).includes('stale')).length;
    const tasksDue = visible.reduce((sum, o) => sum + Number(o.tasks_due_soon || 0), 0);
    return {
      total_value: totalValue, weighted_value: weighted, open_count: open.length,
      quarter_forecast: quarterValue, stale_count: stale, tasks_due: tasksDue,
    };
  }

  render() {
    const visible = this.visibleOpportunities();
    const s = this.summary(visible);
    const eventTypes = Array.from(new Set(this.opportunities.map((o) => o.event_type).filter(Boolean))).sort();

    this.innerHTML = `
      <div class="page-head">
        <div><h1>Opportunity Pipeline</h1><p class="subtle">Track prospects from signal to booked event.</p></div>
        ${this.canManage ? '<button type="button" class="button primary" data-add>+ New Opportunity</button>' : ''}
      </div>
      <section class="opp-kpis">
        <article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span><div><span class="kpi-label">Total Pipeline Value</span><strong class="kpi-value">$${Math.round(s.total_value).toLocaleString()}</strong></div></article>
        <article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><div><span class="kpi-label">Weighted Pipeline</span><strong class="kpi-value">$${Math.round(s.weighted_value).toLocaleString()}</strong></div></article>
        <article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-user-group" aria-hidden="true"></i></span><div><span class="kpi-label">Open Opportunities</span><strong class="kpi-value">${esc(s.open_count)}</strong></div></article>
        <article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></span><div><span class="kpi-label">Quarter Forecast</span><strong class="kpi-value">$${Math.round(s.quarter_forecast).toLocaleString()}</strong></div></article>
        <article class="kpi-card kpi-warn"><span class="kpi-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><div><span class="kpi-label">Stale Opportunities</span><strong class="kpi-value">${esc(s.stale_count)}</strong></div></article>
        <article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><div><span class="kpi-label">Tasks Due</span><strong class="kpi-value">${esc(s.tasks_due)}</strong><span class="kpi-sub">Next 7 days</span></div></article>
      </section>
      <div class="panel padded opp-pipe-filters">
        <input type="search" placeholder="Search company, opportunity, conference…" data-q value="${esc(this.filters.q)}">
        <label class="select-inline"><span>Conference</span><select data-conference><option value="">All Conferences</option>${this.conferences.map((c) => `<option value="${esc(c.id)}" ${this.filters.conference_id === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
        <label class="select-inline"><span>Owner</span><select data-owner><option value="">All Owners</option>${this.users.map((u) => `<option value="${esc(u.id)}" ${this.filters.owner_id === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
        <label class="select-inline"><span>Event Type</span><select data-event-type><option value="">All Types</option>${eventTypes.map((t) => `<option value="${esc(t)}" ${this.filters.event_type === t ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
        <label class="select-inline"><span>Date Range</span><select data-date-range>${DATE_RANGES.map(([v, l]) => `<option value="${v}" ${this.filters.date_range === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
        <label class="select-inline"><span>Est. Value</span><select data-value-range>${VALUE_RANGES.map(([v, l]) => `<option value="${v}" ${this.filters.value_range === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
        <label class="opp-inline-check"><input type="checkbox" data-stale ${this.filters.stale_only ? 'checked' : ''}> Stale only</label>
        <button type="button" class="button secondary small" data-clear>Clear</button>
      </div>
      <section class="pipeline-board opp-pipeline-board">
        ${COLUMNS.map((col) => this.columnHtml(col, visible)).join('')}
      </section>`;

    this.bind();
  }

  columnHtml(col, visible) {
    const items = visible.filter((o) => col.stages.includes(o.stage));
    const total = items.reduce((sum, o) => sum + Number(o.estimated_value || 0), 0);
    return `<article class="pipe-col opp-pipe-col" data-column="${esc(col.key)}" data-stages="${esc(col.stages.join(','))}">
      <h3>${esc(col.label)} <span class="pipe-count">${items.length}</span></h3>
      <p class="opp-pipe-col-total">${total ? `$${Math.round(total).toLocaleString()}` : ''}</p>
      ${items.map((o) => this.cardHtml(o, col)).join('') || '<p class="muted small">No opportunities.</p>'}
      ${this.canManage ? `<button type="button" class="button secondary small opp-pipe-add" data-add-to-stage="${esc(col.stages[0])}">+ Add Opportunity</button>` : ''}
    </article>`;
  }

  cardHtml(o, col) {
    const warnings = o.warnings || [];
    return `<article class="pipe-card opp-pipe-card" data-opp-id="${esc(o.id)}" ${this.canManage ? 'draggable="true"' : ''} tabindex="0">
      <div class="opp-pipe-card-head">
        <strong>${esc(o.company_name)}</strong>
        ${o.probability != null ? `<span class="opp-pipe-prob">${esc(o.probability)}%</span>` : ''}
      </div>
      <span class="opp-pipe-name">${esc(o.name)}</span>
      ${o.conference_name ? `<span class="opp-pipe-conf"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> ${esc(o.conference_name)}</span>` : ''}
      ${o.event_type ? `<span class="pill">${esc(o.event_type)}</span>` : ''}
      <span class="opp-pipe-value">${o.estimated_value != null ? `$${Number(o.estimated_value).toLocaleString()}` : '<span class="muted">No estimate</span>'}</span>
      <div class="opp-pipe-meta">
        <span>${esc(o.owner_name || 'Unassigned')}</span>
        ${o.next_action_at ? `<span>${esc(shortMonthDay(String(o.next_action_at).slice(0, 10)))}</span>` : ''}
      </div>
      <div class="opp-pipe-icons">
        <span title="Notes"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i> ${esc(o.note_count || 0)}</span>
        <span title="Open tasks"><i class="fa-solid fa-list-check" aria-hidden="true"></i> ${esc(o.task_count || 0)}</span>
      </div>
      ${warnings.length ? `<div class="opp-pipe-warnings">${warnings.map((w) => `<span class="opp-pipe-warning opp-pipe-warning-${esc(w)}"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ${esc(warningLabel(w))}</span>`).join('')}</div>` : ''}
      <div class="opp-pipe-actions">
        <a class="button secondary small" href="#opportunities-${esc(o.id)}">Open</a>
        ${this.canManage ? `<select class="opp-pipe-stage-select" data-stage-select="${esc(o.id)}" aria-label="Move stage for ${esc(o.name)}">
          ${STAGES.map((st) => `<option value="${st}" ${st === o.stage ? 'selected' : ''}>${esc(stageLabel(st))}</option>`).join('')}
        </select>` : ''}
      </div>
    </article>`;
  }

  bind() {
    $('[data-q]', this)?.addEventListener('input', debounce((e) => { this.filters.q = e.target.value; this.render(); }, 250));
    $('[data-conference]', this)?.addEventListener('change', (e) => { this.filters.conference_id = e.target.value; this.render(); });
    $('[data-owner]', this)?.addEventListener('change', (e) => { this.filters.owner_id = e.target.value; this.render(); });
    $('[data-event-type]', this)?.addEventListener('change', (e) => { this.filters.event_type = e.target.value; this.render(); });
    $('[data-date-range]', this)?.addEventListener('change', (e) => { this.filters.date_range = e.target.value; this.render(); });
    $('[data-value-range]', this)?.addEventListener('change', (e) => { this.filters.value_range = e.target.value; this.render(); });
    $('[data-stale]', this)?.addEventListener('change', (e) => { this.filters.stale_only = e.target.checked; this.render(); });
    $('[data-clear]', this)?.addEventListener('click', () => {
      this.filters = { q: '', conference_id: '', owner_id: '', event_type: '', date_range: '', value_range: '', stale_only: false };
      this.render();
    });
    $('[data-add]', this)?.addEventListener('click', () => this.openCreateModal());
    $$('[data-add-to-stage]', this).forEach((btn) => btn.addEventListener('click', () => this.openCreateModal(btn.dataset.addToStage)));

    $$('[data-stage-select]', this).forEach((select) => {
      select.addEventListener('click', (e) => e.stopPropagation());
      select.addEventListener('change', async () => {
        const id = select.dataset.stageSelect;
        await this.moveStage(Number(id), select.value);
      });
    });

    // HTML5 drag/drop — progressive enhancement over the <select> above.
    if (this.canManage) {
      $$('[data-opp-id]', this).forEach((card) => {
        card.addEventListener('dragstart', (e) => {
          e.dataTransfer.setData('text/plain', card.dataset.oppId);
          e.dataTransfer.effectAllowed = 'move';
        });
      });
      $$('[data-column]', this).forEach((col) => {
        col.addEventListener('dragover', (e) => { e.preventDefault(); col.classList.add('opp-pipe-col-over'); });
        col.addEventListener('dragleave', () => col.classList.remove('opp-pipe-col-over'));
        col.addEventListener('drop', async (e) => {
          e.preventDefault();
          col.classList.remove('opp-pipe-col-over');
          const id = e.dataTransfer.getData('text/plain');
          const targetStage = col.dataset.stages.split(',')[0];
          if (id) await this.moveStage(Number(id), targetStage);
        });
      });
    }

    $$('.opp-pipe-card', this).forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('select, a, button')) return;
        location.hash = `#opportunities-${card.dataset.oppId}`;
      });
      card.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('select, a, button')) {
          location.hash = `#opportunities-${card.dataset.oppId}`;
        }
      });
    });
  }

  async moveStage(id, stage) {
    try {
      await api(`/opportunities/${id}`, { method: 'PATCH', body: JSON.stringify({ stage }) });
      publish('toast.show', { message: 'Stage updated.' });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not move opportunity.', tone: 'error' });
      await this.load();
    }
  }

  openCreateModal(initialStage) {
    const { dialog, close } = openModal({
      title: 'New Opportunity',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="new-opportunity">
        <label class="wide">Opportunity name <span class="req">*</span><input type="text" name="name" required></label>
        <label class="wide">Company <span class="req">*</span>
          <input type="text" data-company-search placeholder="Search companies…" autocomplete="off">
          <input type="hidden" name="company_id" required>
        </label>
        <div class="opp-company-results" data-company-results hidden></div>
        <label>Conference <select name="conference_id"><option value="">None</option>${this.conferences.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select></label>
        <label>Estimated value <input type="number" min="0" step="0.01" name="estimated_value"></label>
        <label>Probability % <input type="number" min="0" max="100" name="probability"></label>
        <label>Event type <input type="text" name="event_type" placeholder="e.g. private_reception"></label>
        <label>Guest count min <input type="number" min="0" name="guest_count_min"></label>
        <label>Guest count max <input type="number" min="0" name="guest_count_max"></label>
        <div class="wide"><button type="submit" class="primary">Create Opportunity</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="new-opportunity"]', dialog);
    const searchInput = $('[data-company-search]', form);
    const resultsBox = $('[data-company-results]', form);
    searchInput.addEventListener('input', debounce(async () => {
      const q = searchInput.value.trim();
      if (!q) { resultsBox.hidden = true; resultsBox.innerHTML = ''; return; }
      try {
        const res = await api(`/opportunity-companies?q=${encodeURIComponent(q)}`);
        const companies = (res.companies || []).slice(0, 8);
        resultsBox.hidden = companies.length === 0;
        resultsBox.innerHTML = companies.map((c) => `<button type="button" class="opp-company-result" data-pick="${esc(c.id)}" data-name="${esc(c.name)}">${esc(c.name)}</button>`).join('');
        $$('[data-pick]', resultsBox).forEach((btn) => btn.addEventListener('click', () => {
          form.company_id.value = btn.dataset.pick;
          searchInput.value = btn.dataset.name;
          resultsBox.hidden = true;
        }));
      } catch { /* ignore transient search errors */ }
    }, 250));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      if (!body.name?.trim() || !body.company_id) {
        publish('toast.show', { message: 'Choose a company from the search results.', tone: 'error' });
        return;
      }
      if (initialStage) body.stage = initialStage;
      try {
        const res = await api('/opportunities', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${res.opportunity.name} created.` });
        close();
        location.hash = `#opportunities-${res.opportunity.id}`;
      } catch (err) {
        publish('toast.show', { message: err.message || 'Could not create opportunity.', tone: 'error' });
      }
    });
  }
}
customElements.define('pb-opportunities-pipeline', OpportunitiesPipelineBoard);
