// public/assets/opportunities/ai-research-panel.js — <pb-opportunities-ai-research>,
// the shared "AI Research" widget (Phase 7) embedded into Discover, Conference
// detail, and Company detail. Not routed to directly — each host page
// creates one via document.createElement (never inline innerHTML, so its
// scope props are set before connectedCallback fires — see Discover/
// Conference/Company detail's bind() for the mount pattern, mirroring
// AppShell.mount()'s own `Object.assign(element, props)` convention) and
// sets three properties before inserting it: `scopeType` ('discover' |
// 'conference' | 'company'), `scopeId` (int, null for 'discover'), and
// `scopeName` (display string, used only in modal titles/prompts).
//
// Talks to POST/GET /api/opportunity-research/jobs[...] (src/Opportunities/
// Research/Jobs.php) directly — never a parallel research system — and
// dispatches a bubbling `research-imported` CustomEvent after a successful
// import so the host page can just do
// `$('pb-opportunities-ai-research', this)?.addEventListener('research-imported', () => this.load())`
// rather than this component reaching back into the host's own state
// ("child calls the API itself, parent just reacts to an event" — the same
// convention the tasks/ directory's shell+children split already uses).
//
// Every job result is Claude-generated, i.e. untrusted external input —
// every value rendered here goes through esc(); nothing is ever innerHTML'd
// raw, and nothing here writes CRM data itself — only the human-triggered
// "Import Selected" action does, via the job's own /import endpoint.
import { esc, api, openModal, formData, publish, subscribe, getAppCapabilities, PanicElement, $, $$ } from '../core.js';
import { researchModeLabel, researchStatusBadge, researchStatusIsActive, relativeTime } from './shared.js';

// Phase 8 "create tasks from a research result" — reuses the same lazily-
// provisioned TaskLink.php route every other Opportunities page already
// uses (src/Opportunities/TaskLink.php); only applies to a conference/
// company scope (a bare 'discover' scope has no owning record to attach a
// task to yet).
const TASK_OWNER_ROUTE = { conference: 'opportunity-conferences', company: 'opportunity-companies' };

// scope: which host pages a mode applies to. 'conference_or_company' shows
// on both, using whichever scope id the host actually has.
const MODE_META = {
  discover_conferences:      { scope: 'discover' },
  research_conference:       { scope: 'conference' },
  find_target_companies:     { scope: 'conference' },
  research_side_events:      { scope: 'conference' },
  generate_outreach_angles:  { scope: 'conference_or_company' },
  research_company:          { scope: 'company' },
};

const POLL_MS = 5000;

class OpportunitiesAiResearchPanel extends PanicElement {
  async connect() {
    this.canResearch = !!getAppCapabilities().research_opportunities;
    this.canImport = !!getAppCapabilities().manage_opportunities;
    this.jobs = [];
    this._pollTimer = null;
    // Phase 8: opportunity_research_jobs is now a mapped
    // RealtimeInvalidationMapper entity, so another user's (or the
    // background worker's) job-status change refreshes this panel
    // immediately — the 5s poll below stays as a fallback for whichever
    // sessions aren't subscribed to the realtime stream at all.
    subscribe('data.invalidated', async (msg) => {
      if (msg.entity !== 'opportunity_research_job') return;
      await this.loadJobs();
      this.render();
      this.maybeStartPolling();
    }, this.abort.signal);
    await this.loadJobs();
    this.render();
    this.maybeStartPolling();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    if (this._pollTimer) {
      clearInterval(this._pollTimer);
      this._pollTimer = null;
    }
  }

  applicableModes() {
    return Object.entries(MODE_META)
      .filter(([, m]) => m.scope === this.scopeType
        || (m.scope === 'conference_or_company' && (this.scopeType === 'conference' || this.scopeType === 'company')))
      .map(([mode]) => mode);
  }

  async loadJobs() {
    const params = new URLSearchParams({ limit: '8' });
    if (this.scopeType === 'conference') params.set('conference_id', String(this.scopeId));
    else if (this.scopeType === 'company') params.set('company_id', String(this.scopeId));
    else params.set('job_type', 'discover_conferences');
    try {
      const res = await api(`/opportunity-research/jobs?${params.toString()}`);
      this.jobs = res.jobs || [];
    } catch {
      this.jobs = [];
    }
  }

  maybeStartPolling() {
    if (this._pollTimer) return;
    if (!this.jobs.some((j) => researchStatusIsActive(j.status))) return;
    this._pollTimer = setInterval(async () => {
      await this.loadJobs();
      this.render();
      if (!this.jobs.some((j) => researchStatusIsActive(j.status))) {
        clearInterval(this._pollTimer);
        this._pollTimer = null;
      }
    }, POLL_MS);
  }

  render() {
    // Nothing to research and no history to show — stay invisible rather
    // than an empty panel taking up rail/page space for a plain viewer.
    if (!this.canResearch && !this.jobs.length) {
      this.innerHTML = '';
      return;
    }
    const modes = this.applicableModes();
    this.innerHTML = `<article class="panel padded opp-ai-research">
      <div class="section-head"><h2><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> AI Research</h2></div>
      ${this.canResearch ? `<div class="opp-ai-research-actions">
        ${modes.map((m) => `<button type="button" class="button secondary small" data-start-mode="${esc(m)}">${esc(researchModeLabel(m))}</button>`).join('')}
      </div>` : ''}
      ${this.jobsHtml()}
    </article>`;
    this.bind();
  }

  jobsHtml() {
    if (!this.jobs.length) {
      return this.canResearch ? '<p class="muted small">No research run yet for this record.</p>' : '';
    }
    return `<ul class="opp-ai-research-jobs">
      ${this.jobs.map((j) => `<li>
        <div class="opp-ai-research-job-row">
          <span>${esc(researchModeLabel(j.job_type))}</span>
          ${researchStatusBadge(j.status)}
        </div>
        <div class="opp-ai-research-job-meta">
          <small class="muted">${esc(relativeTime(j.created_at))}</small>
          ${j.status === 'completed' ? `<button type="button" class="button small" data-review-job="${esc(j.id)}">Review results</button>` : ''}
          ${j.status === 'completed' && this.canImport && TASK_OWNER_ROUTE[this.scopeType]
            ? `<button type="button" class="button small secondary" data-create-task-job="${esc(j.id)}">Create Task</button>` : ''}
          ${j.status === 'failed' ? `<span class="error-text small">${esc(j.error || 'Research failed.')}</span>` : ''}
        </div>
      </li>`).join('')}
    </ul>`;
  }

  bind() {
    $$('[data-start-mode]', this).forEach((btn) => btn.addEventListener('click', () => this.startMode(btn.dataset.startMode)));
    $$('[data-review-job]', this).forEach((btn) => btn.addEventListener('click', () => this.openReviewModal(Number(btn.dataset.reviewJob))));
    $$('[data-create-task-job]', this).forEach((btn) => btn.addEventListener('click', () => this.openCreateTaskModal(Number(btn.dataset.createTaskJob))));
  }

  /** Phase 8 "create a task from a research result" — same lazily-provisioned TaskLink route every other page uses, scoped to this panel's own conference/company. */
  openCreateTaskModal(jobId) {
    const job = this.jobs.find((j) => j.id === jobId);
    const route = TASK_OWNER_ROUTE[this.scopeType];
    if (!route) return;
    const { dialog, close } = openModal({
      title: 'Create Task',
      bodyHtml: `<form class="grid-form padded" data-form="research-task-form">
        <label class="wide">Task <span class="req">*</span>
          <input type="text" name="title" required value="${esc(`Follow up: ${job ? researchModeLabel(job.job_type) : 'research'}${this.scopeName ? ` for ${this.scopeName}` : ''}`)}"></label>
        <div class="wide"><button type="submit" class="primary">Create Task</button></div>
      </form>`,
      focus: '[name="title"]',
    });
    const form = $('[data-form="research-task-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      try {
        const ensured = await api(`/${route}/${this.scopeId}/tasks`, { method: 'POST' });
        await api(`/task-documents/${ensured.task_document_id}/tasks`, { method: 'POST', body: JSON.stringify({ title: body.title }) });
        publish('toast.show', { message: 'Task created.' });
        close();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  // ── Starting a job ───────────────────────────────────────────────────────

  async startMode(mode) {
    if (mode === 'discover_conferences') {
      this.openDiscoverForm();
      return;
    }
    const body = { job_type: mode };
    if (this.scopeType === 'conference') body.conference_id = this.scopeId;
    if (this.scopeType === 'company') body.company_id = this.scopeId;
    await this.enqueue(body);
  }

  openDiscoverForm() {
    const { dialog, close } = openModal({
      title: 'Find Upcoming Conferences',
      bodyHtml: `<form class="grid-form padded" data-form="discover-conferences">
        <label class="wide">Location <span class="req">*</span>
          <input type="text" name="location" placeholder="e.g. San Francisco, CA" required></label>
        <label>From <input type="date" name="date_from"></label>
        <label>To <input type="date" name="date_to"></label>
        <label class="wide">Additional context (optional)
          <textarea name="venue_context" rows="2" placeholder="Anything about the venue worth mentioning — capacity, vibe, typical private-event use…"></textarea></label>
        <div class="wide"><button type="submit" class="primary">Start Research</button></div>
      </form>`,
      focus: '[name="location"]',
    });
    const form = $('[data-form="discover-conferences"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = formData(form);
      if (!f.location?.trim()) return;
      close();
      await this.enqueue({
        job_type: 'discover_conferences',
        input: { location: f.location, date_from: f.date_from || null, date_to: f.date_to || null, venue_context: f.venue_context || null },
      });
    });
  }

  async enqueue(body) {
    try {
      await api('/opportunity-research/jobs', { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: 'Research started — this can take a few minutes.' });
      await this.loadJobs();
      this.render();
      this.maybeStartPolling();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Review / import ──────────────────────────────────────────────────────

  async openReviewModal(jobId) {
    let job;
    try {
      job = (await api(`/opportunity-research/jobs/${jobId}`)).job;
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      return;
    }
    const result = job.result_json || {};
    const { dialog, close } = openModal({
      title: `Research Complete — ${researchModeLabel(job.job_type)}`,
      wide: true,
      bodyHtml: `<div class="padded opp-ai-review">
        ${this.reviewSectionsHtml(job.job_type, result)}
        ${this.canImport ? `<div class="opp-ai-review-actions"><button type="button" class="button primary" data-import>Import Selected</button></div>` : ''}
      </div>`,
    });
    if (this.canImport) {
      $('[data-import]', dialog).addEventListener('click', async () => {
        const selections = this.collectSelections(dialog, job.job_type);
        try {
          const res = await api(`/opportunity-research/jobs/${jobId}/import`, { method: 'POST', body: JSON.stringify(selections) });
          const counts = Object.entries(res.summary || {}).filter(([, n]) => n > 0).map(([k, n]) => `${n} ${k.replace(/_/g, ' ')}`).join(', ');
          publish('toast.show', { message: counts ? `Imported: ${counts}.` : 'Nothing new to import.' });
          close();
          await this.loadJobs();
          this.render();
          this.dispatchEvent(new CustomEvent('research-imported', { bubbles: true }));
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }
  }

  /** One checklist `<ul>` per importable list in the result, keyed by the same names Importer.php expects back. */
  reviewSectionsHtml(jobType, result) {
    switch (jobType) {
      case 'discover_conferences':
        return this.checklist('conferences', result.conferences, (c) =>
          `<strong>${esc(c.name)}</strong> ${c.starts_on ? `<small class="muted">${esc(c.starts_on)}${c.ends_on && c.ends_on !== c.starts_on ? ` – ${esc(c.ends_on)}` : ''}</small>` : ''}
           <p class="small">${esc(c.why_relevant || '')}</p>
           ${this.sourceLinks(c.source_urls)}`);
      case 'research_conference':
        return this.checklist('facts', result.facts, (f) => `<strong>${esc(f.label)}:</strong> ${esc(f.value)} ${this.sourceLinks(f.source_url ? [f.source_url] : [])}`, 'Key Facts')
          + this.checklist('side_event_patterns', result.side_event_patterns, (p) => `${esc(p.description)} ${this.sourceLinks(p.source_url ? [p.source_url] : [])}`, 'Side-Event Patterns');
      case 'find_target_companies':
        return this.checklist('companies', result.companies, (c) =>
          `<strong>${esc(c.name)}</strong> <span class="badge">${esc(c.role.replace(/_/g, ' '))}</span> <span class="badge">${esc(c.confidence)}</span>
           <p class="small">${esc(c.why_relevant || '')}</p>${this.sourceLinks(c.source_url ? [c.source_url] : [])}`);
      case 'research_company':
        return this.companyFieldsHtml(result.company)
          + this.checklist('conference_presence', result.conference_presence, (p) => `${esc(p.conference_name)} ${p.role ? `<span class="badge">${esc(p.role)}</span>` : ''} ${this.sourceLinks(p.source_url ? [p.source_url] : [])}`, 'Conference Presence')
          + this.checklist('buyer_roles', result.buyer_roles, (b) => `<strong>${b.name ? esc(b.name) : esc(b.title)}</strong>${b.name ? ` — ${esc(b.title)}` : ''} ${b.note ? `<p class="small">${esc(b.note)}</p>` : ''} ${this.sourceLinks(b.source_url ? [b.source_url] : [])}`, 'Buyer Roles')
          + this.checklist('hospitality_signals', result.hospitality_signals, (h) => `${esc(h.description)} ${this.sourceLinks(h.source_url ? [h.source_url] : [])}`, 'Hospitality Signals');
      case 'research_side_events':
        return this.checklist('side_events', result.side_events, (s) =>
          `<strong>${esc(s.host_company)}</strong> — ${esc(s.event_name)} <span class="badge">${esc(s.type.replace(/_/g, ' '))}</span> ${s.date ? `<small class="muted">${esc(s.date)}</small>` : ''}
           ${s.note ? `<p class="small">${esc(s.note)}</p>` : ''}${this.sourceLinks(s.source_url ? [s.source_url] : [])}`);
      case 'generate_outreach_angles':
        return this.checklist('angles', result.angles, (a) =>
          `<strong>${esc(a.title)}</strong><p class="small">${esc(a.description || '')}</p>${a.rationale ? `<p class="small muted"><em>${esc(a.rationale)}</em></p>` : ''}`);
      default:
        return '<p class="muted">Unrecognized result shape.</p>';
    }
  }

  companyFieldsHtml(company) {
    if (!company) return '';
    const fields = ['industry', 'employee_range', 'hq_city', 'hq_state', 'description', 'linkedin_url', 'website_url']
      .filter((f) => company[f]);
    if (!fields.length) return '';
    return `<div class="opp-ai-review-section">
      <h3>Company Details</h3>
      <ul class="opp-ai-review-list">
        ${fields.map((f) => `<li><label><input type="checkbox" data-select="company_fields" value="${esc(f)}" checked>
          <strong>${esc(f.replace(/_/g, ' '))}:</strong> ${esc(String(company[f]))}</label></li>`).join('')}
      </ul>
      ${this.sourceLinks(company.source_urls)}
    </div>`;
  }

  checklist(key, items, formatter, title) {
    if (!items || !items.length) return '';
    return `<div class="opp-ai-review-section">
      ${title ? `<h3>${esc(title)}</h3>` : ''}
      <ul class="opp-ai-review-list">
        ${items.map((item, i) => `<li><label class="${item._imported ? 'opp-ai-imported' : ''}">
          <input type="checkbox" data-select="${esc(key)}" value="${i}" ${item._imported ? 'checked disabled' : 'checked'}>
          ${item._imported ? '<span class="badge success">Imported</span> ' : ''}
          ${formatter(item)}
        </label></li>`).join('')}
      </ul>
    </div>`;
  }

  sourceLinks(urls) {
    const list = (urls || []).filter(Boolean);
    if (!list.length) return '';
    return `<p class="small muted">Source${list.length > 1 ? 's' : ''}: ${list.map((u) => `<a href="${esc(u)}" target="_blank" rel="noopener">link</a>`).join(', ')}</p>`;
  }

  /** Reads every checked, non-disabled checkbox in the modal into `{key: [values]}` — company_fields collects field-name strings, everything else collects integer indices. */
  collectSelections(dialog, jobType) {
    const selections = {};
    $$('[data-select]', dialog).forEach((box) => {
      if (!box.checked || box.disabled) return;
      const key = box.dataset.select;
      selections[key] = selections[key] || [];
      selections[key].push(key === 'company_fields' ? box.value : Number(box.value));
    });
    return selections;
  }
}
customElements.define('pb-opportunities-ai-research', OpportunitiesAiResearchPanel);
