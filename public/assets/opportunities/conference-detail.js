// <pb-opportunities-conference-detail> — Opportunities > Conferences >
// {conference} (opportunity-2.png, Dreamforce 2026 mockup). Owns all the
// conference's data and API calls; every panel below is rendered inline
// (not split into child elements — this is one cohesive detail view, same
// "single-file detail page" shape as contacts.js/leads.js, not the
// shell-plus-children shape tasks/inbox use for a multi-pane workspace).
//
// Reuses, unmodified: Notes (`/opportunity-conferences/{id}/notes`),
// Signals (`/opportunity-conferences/{id}/signals`, used here for Side
// Event Signals), ConferenceCompanies (`/opportunity-conferences/{id}/companies`),
// and the Tasks app (`/task-documents/{id}/tasks`, reached via the
// lazily-provisioned link at `/opportunity-conferences/{id}/tasks` — see
// src/Opportunities/TaskLink.php). Nothing here talks to a parallel task or
// note system.
import { esc, api, emptyState, openModal, formData, publish, subscribe, getAppCapabilities, PanicElement, $, $$ } from '../core.js';
import { scoreTone, shortMonthDay, dateRangeLabel, noteTypeLabel, debounce, overdueTaskCount } from './shared.js';

const ROLE_LABELS = {
  organizer: 'Organizer', headline_sponsor: 'Headline Sponsor', sponsor: 'Sponsor',
  exhibitor: 'Exhibitor', speaker: 'Speaker', partner: 'Partner', vendor: 'Vendor',
  delegation: 'Delegation', attendee: 'Attendee', unknown: 'Unknown',
};
const PHASE_LABELS = { pre: 'Pre-Conference', main: 'Main Conference', post: 'Post-Conference' };

class OpportunitiesConferenceDetail extends PanicElement {
  async connect() {
    this.canManage = !!getAppCapabilities().manage_opportunities;
    this.data = null;
    this.notes = [];
    this.signals = [];
    this.taskDocumentId = null;
    this.tasks = [];
    this.reloadDebounced = debounce(() => this.load(), 300);
    // Phase 8: previously fetched once — another user editing this
    // conference, linking a company, adding a Key Fact, or completing a
    // task now refreshes the page automatically.
    subscribe('data.invalidated', (msg) => {
      if ((msg.entity === 'opportunity_conference' && (msg.id == null || msg.id === this.id)) || msg.entity === 'global') this.reloadDebounced();
    }, this.abort.signal);
    await this.load();
  }

  async load() {
    this.setLoading('Loading conference');
    try {
      const [detail, notesRes, signalsRes, taskLink] = await Promise.all([
        api(`/opportunity-conferences/${this.id}`),
        api(`/opportunity-conferences/${this.id}/notes`),
        api(`/opportunity-conferences/${this.id}/signals`),
        api(`/opportunity-conferences/${this.id}/tasks`),
      ]);
      this.data = detail;
      this.notes = notesRes.notes || [];
      this.signals = signalsRes.signals || [];
      this.taskDocumentId = taskLink.task_document_id || null;
      this.tasks = this.taskDocumentId ? (await api(`/task-documents/${this.taskDocumentId}/tasks`)).tasks || [] : [];
      publish('page.context', { title: this.data.conference.name, blurb: 'Conference intelligence and related prospects.' });
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const c = this.data.conference;
    this.innerHTML = `
      <div class="page-head">
        <div>
          <a class="button secondary small" href="#opportunities-conferences">&larr; Back to Conferences</a>
          <h1>${esc(c.name)}</h1>
          <p class="subtle">Conference intelligence and related prospects.</p>
        </div>
        ${this.canManage ? '<button type="button" class="button secondary" data-edit-conference>Edit</button>' : ''}
      </div>
      ${this.headerCardsHtml(c)}
      <div data-ai-research-slot></div>
      <section class="dashboard-grid">
        <article class="panel padded">
          <h2>Overview</h2>
          <p>${c.description ? esc(c.description) : '<span class="muted">No description yet.</span>'}</p>
          ${c.website_url ? `<a href="${esc(c.website_url)}" target="_blank" rel="noopener">${esc(c.website_url)}</a>` : ''}
        </article>
        ${this.keyFactsHtml()}
      </section>
      ${this.peakWindowsHtml()}
      <section class="dashboard-grid">
        ${this.targetCompaniesHtml()}
        ${this.sideEventSignalsHtml()}
      </section>
      <section class="dashboard-grid">
        ${this.venueAvailabilityHtml(c)}
        ${this.outreachAnglesHtml()}
      </section>
      <section class="dashboard-grid">
        ${this.notesHtml()}
        ${this.tasksHtml()}
      </section>`;

    this.bind();
    this.mountAiResearch();
  }

  // See ai-research-panel.js's docblock — created via document.createElement
  // (never inline in the innerHTML template above) so scopeType/scopeId are
  // real JS properties already set before its connectedCallback fires.
  mountAiResearch() {
    const slot = $('[data-ai-research-slot]', this);
    if (!slot) return;
    const panel = document.createElement('pb-opportunities-ai-research');
    panel.scopeType = 'conference';
    panel.scopeId = this.id;
    panel.scopeName = this.data.conference.name;
    panel.addEventListener('research-imported', () => this.load());
    slot.replaceWith(panel);
  }

  // ── Header ───────────────────────────────────────────────────────────────

  headerCardsHtml(c) {
    const cards = [
      ['fa-calendar-days', 'Conference Dates', dateRangeLabel(c.starts_at, c.ends_at)],
      ['fa-location-dot', 'Venue', c.venue_name || '<span class="muted">Unknown</span>'],
      ['fa-users', 'Expected Attendees', c.estimated_attendance != null ? `${Number(c.estimated_attendance).toLocaleString()}+` : '<span class="muted">Unknown</span>'],
      ['fa-person-walking', 'Distance', c.distance_from_venue_miles != null ? `${Number(c.distance_from_venue_miles).toFixed(1)} mi` : '<span class="muted">Unknown</span>'],
    ];
    const score = c.opportunity_score != null
      ? `<article class="kpi-card"><span class="kpi-icon"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></span><div><span class="kpi-label">Opportunity Score</span><strong class="kpi-value ${scoreTone(c.opportunity_score)}">${esc(c.opportunity_score)}</strong></div></article>`
      : '';
    return `<section class="opp-kpis">
      ${cards.map(([icon, label, value]) => `<article class="kpi-card"><span class="kpi-icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span><div><span class="kpi-label">${esc(label)}</span><strong class="kpi-value">${value}</strong></div></article>`).join('')}
      ${score}
    </section>`;
  }

  // ── Key Facts ────────────────────────────────────────────────────────────

  keyFactsHtml() {
    const facts = this.data.facts || [];
    const items = facts.length
      ? facts.map((f) => `<li class="opp-fact-item">
          <i class="fa-solid fa-check" aria-hidden="true"></i>
          <span>${esc(f.fact)}</span>
          ${f.source_url ? `<a href="${esc(f.source_url)}" target="_blank" rel="noopener" class="opp-fact-source" title="Source"><i class="fa-solid fa-link" aria-hidden="true"></i></a>` : ''}
          ${this.canManage ? `<button type="button" class="opp-fact-remove" data-remove-fact="${esc(f.id)}" title="Remove fact"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>` : ''}
        </li>`).join('')
      : `<li class="muted">No key facts recorded yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Key Facts</h2></div>
      <ul class="opp-fact-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form" data-form="add-fact">
        <input type="text" name="fact" placeholder="e.g. 2,500+ sponsors and exhibitors" required>
        <input type="url" name="source_url" placeholder="Source URL (optional)">
        <button type="submit" class="small">Add</button>
      </form>` : ''}
    </article>`;
  }

  // ── Peak Side-Event Windows ──────────────────────────────────────────────

  peakWindowsHtml() {
    const pw = this.data.peak_windows || { windows: [], best_dates: [] };
    if (!pw.windows.length) return '';
    const groups = { pre: [], main: [], post: [] };
    pw.windows.forEach((w) => groups[w.phase]?.push(w));
    const band = (phase) => groups[phase].length
      ? `<div class="opp-window-band opp-window-${phase}">
          <div class="opp-window-band-label">${esc(PHASE_LABELS[phase])}</div>
          <div class="opp-window-dates">${groups[phase].map((w) => `<span class="opp-window-date opp-activity-${esc(w.activity)}">${esc(shortMonthDay(w.date))}</span>`).join('')}</div>
        </div>`
      : '';
    return `<article class="panel padded">
      <div class="section-head"><h2>Peak Side-Event Windows</h2></div>
      <div class="opp-window-bands">${band('pre')}${band('main')}${band('post')}</div>
      ${pw.best_dates.length ? `<p class="muted small">Best times for side events: ${pw.best_dates.map((d) => esc(shortMonthDay(d))).join(' &middot; ')}</p>` : ''}
    </article>`;
  }

  // ── Target Companies & Sponsors ──────────────────────────────────────────

  targetCompaniesHtml() {
    const companies = this.data.companies || [];
    const rows = companies.length
      ? companies.map((c) => `<tr>
          <td><a href="#opportunities-company-${esc(c.company_id)}">${esc(c.company_name)}</a></td>
          <td>${esc(ROLE_LABELS[c.role] || c.role)}</td>
          <td>${c.sponsor_tier ? esc(c.sponsor_tier) : '<span class="muted">—</span>'}</td>
          <td>${c.participation_notes ? esc(c.participation_notes) : '<span class="muted">—</span>'}</td>
          <td class="row-actions">
            ${this.canManage ? `<button type="button" class="small secondary" data-create-opp="${esc(c.company_id)}" data-company-name="${esc(c.company_name)}">Create Opportunity</button>
            <button type="button" class="small danger" data-remove-company="${esc(c.id)}">Remove</button>` : ''}
          </td>
        </tr>`).join('')
      : `<tr><td colspan="5">${emptyState('No target companies linked yet.')}</td></tr>`;

    return `<article class="panel">
      <div class="section-head padded"><h2>Target Companies &amp; Sponsors <span class="pill">${esc(companies.length)}</span></h2>
        ${this.canManage ? '<button type="button" class="button small" data-add-company>+ Add Company</button>' : ''}
      </div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Company</th><th>Role</th><th>Sponsor Tier</th><th>Notes</th><th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
    </article>`;
  }

  // ── Side Event Signals ───────────────────────────────────────────────────

  sideEventSignalsHtml() {
    const items = this.signals.length
      ? this.signals.slice(0, 10).map((s) => `<li class="opp-signal-item">
          <strong>${esc(s.description)}</strong>
          <small class="muted">${s.observed_at ? esc(shortMonthDay(s.observed_at.slice(0, 10))) : ''} ${s.source_url ? `&middot; <a href="${esc(s.source_url)}" target="_blank" rel="noopener">source</a>` : ''}</small>
        </li>`).join('')
      : `<li class="muted">No side-event signals recorded yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Side Event Signals <span class="pill">${esc(this.signals.length)}</span></h2></div>
      <ul class="opp-signal-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form" data-form="add-signal">
        <input type="text" name="description" placeholder="e.g. Salesforce Welcome Party, Mon 9/14, 7pm" required>
        <input type="url" name="source_url" placeholder="Source URL (optional)">
        <button type="submit" class="small">Add</button>
      </form>` : ''}
    </article>`;
  }

  // ── Venue Proximity & Availability ───────────────────────────────────────

  venueAvailabilityHtml(c) {
    const nights = this.data.empty_night_dates || [];
    return `<article class="panel padded">
      <div class="section-head"><h2>Venue Proximity &amp; Availability</h2></div>
      ${c.venue_coordinates_known
        ? `<p>${c.distance_from_venue_miles != null ? `<strong>${esc(Number(c.distance_from_venue_miles).toFixed(1))} miles</strong> from the venue.` : 'This conference has no coordinates yet, so distance is unknown.'}</p>`
        : `<p class="muted">The venue's own coordinates haven't been entered yet (Admin &gt; Venue), so distance can't be calculated.</p>`}
      ${nights.length
        ? `<p><strong>Suggested available nights:</strong> ${nights.map((d) => `<span class="pill">${esc(shortMonthDay(d))}</span>`).join(' ')}</p>`
        : '<p class="muted">No open venue nights during this conference right now.</p>'}
    </article>`;
  }

  // ── Recommended Outreach Angles ──────────────────────────────────────────

  outreachAnglesHtml() {
    const angles = this.data.outreach_angles || [];
    return `<article class="panel padded">
      <div class="section-head"><h2>Recommended Outreach Angles</h2></div>
      <ul class="opp-suggestion-list">${angles.map((a) => `<li class="opp-suggestion"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i>${esc(a)}</li>`).join('')}</ul>
    </article>`;
  }

  // ── Conference Notes ─────────────────────────────────────────────────────

  notesHtml() {
    const items = this.notes.length
      ? this.notes.map((n) => `<li class="opp-note-item">
          <div class="opp-note-head"><strong>${esc(n.created_by_name || 'Unknown')}</strong><span class="badge">${esc(noteTypeLabel(n.note_type))}</span></div>
          <p>${esc(n.body)}</p>
          <small class="muted">${esc(shortMonthDay((n.created_at || '').slice(0, 10)))}</small>
        </li>`).join('')
      : `<li class="muted">No notes yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Conference Notes</h2></div>
      <ul class="opp-note-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form opp-inline-form-wide" data-form="add-note">
        <textarea name="body" rows="2" placeholder="Add a note about this conference…" required></textarea>
        <button type="submit" class="small">Add Note</button>
      </form>` : ''}
    </article>`;
  }

  // ── Open Tasks ───────────────────────────────────────────────────────────

  tasksHtml() {
    if (!this.taskDocumentId) {
      return `<article class="panel padded">
        <div class="section-head"><h2>Open Tasks</h2></div>
        <p class="muted">No tasks yet for this conference.</p>
        ${this.canManage ? '<button type="button" class="button small secondary" data-start-tasks>+ Add first task</button>' : ''}
      </article>`;
    }
    const items = this.tasks.length
      ? this.tasks.map((t) => `<li class="opp-task-item ${t.status === 'done' ? 'opp-task-done' : ''}">
          <label><input type="checkbox" data-toggle-task="${esc(t.id)}" ${t.status === 'done' ? 'checked' : ''}> ${esc(t.title)}</label>
          ${t.due_date ? `<small class="muted">${esc(shortMonthDay(t.due_date))}</small>` : ''}
        </li>`).join('')
      : `<li class="muted">No tasks yet.</li>`;
    const overdue = overdueTaskCount(this.tasks);
    return `<article class="panel padded">
      <div class="section-head"><h2>Open Tasks <span class="pill">${esc(this.tasks.filter((t) => t.status !== 'done').length)}</span>${overdue ? ` <span class="pill pill-danger">${esc(overdue)} overdue</span>` : ''}</h2>
        <a class="button secondary small" href="#tasks-${esc(this.taskDocumentId)}">Open in Tasks</a>
      </div>
      <ul class="opp-task-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form" data-form="add-task">
        <input type="text" name="title" placeholder="Add a task…" required>
        <button type="submit" class="small">Add</button>
      </form>` : ''}
    </article>`;
  }

  // ── Wiring ───────────────────────────────────────────────────────────────

  bind() {
    $('[data-edit-conference]', this)?.addEventListener('click', () => this.openEditModal());
    $('[data-add-company]', this)?.addEventListener('click', () => this.openAddCompanyModal());
    $('[data-start-tasks]', this)?.addEventListener('click', () => this.ensureTasks());

    $$('[data-remove-fact]', this).forEach((btn) => btn.addEventListener('click', () => this.removeFact(Number(btn.dataset.removeFact))));
    $$('[data-remove-company]', this).forEach((btn) => btn.addEventListener('click', () => this.removeCompany(Number(btn.dataset.removeCompany))));
    $$('[data-create-opp]', this).forEach((btn) => btn.addEventListener('click', () => this.openCreateOpportunityModal(Number(btn.dataset.createOpp), btn.dataset.companyName)));
    $$('[data-toggle-task]', this).forEach((box) => box.addEventListener('change', () => this.toggleTask(Number(box.dataset.toggleTask), box.checked)));

    const factForm = $('[data-form="add-fact"]', this);
    factForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(factForm);
      if (!body.fact?.trim()) return;
      try {
        await api(`/opportunity-conferences/${this.id}/facts`, { method: 'POST', body: JSON.stringify(body) });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const signalForm = $('[data-form="add-signal"]', this);
    signalForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(signalForm);
      if (!body.description?.trim()) return;
      try {
        await api(`/opportunity-conferences/${this.id}/signals`, { method: 'POST', body: JSON.stringify({ ...body, signal_type: 'side_event_history', observed_at: body.observed_at || null }) });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const noteForm = $('[data-form="add-note"]', this);
    noteForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(noteForm);
      if (!body.body?.trim()) return;
      try {
        await api(`/opportunity-conferences/${this.id}/notes`, { method: 'POST', body: JSON.stringify(body) });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const taskForm = $('[data-form="add-task"]', this);
    taskForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(taskForm);
      if (!body.title?.trim()) return;
      try {
        await api(`/task-documents/${this.taskDocumentId}/tasks`, { method: 'POST', body: JSON.stringify(body) });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  async removeFact(factId) {
    try {
      await api(`/opportunity-conferences/${this.id}/facts/${factId}`, { method: 'DELETE' });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async removeCompany(linkId) {
    if (!confirm('Remove this company from the conference?')) return;
    try {
      await api(`/opportunity-conferences/${this.id}/companies/${linkId}`, { method: 'DELETE' });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async ensureTasks() {
    try {
      const res = await api(`/opportunity-conferences/${this.id}/tasks`, { method: 'POST' });
      this.taskDocumentId = res.task_document_id;
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async toggleTask(taskId, done) {
    try {
      await api(`/task-documents/${this.taskDocumentId}/tasks/${taskId}`, { method: 'PATCH', body: JSON.stringify({ status: done ? 'done' : 'not_started' }) });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  // ── Modals ───────────────────────────────────────────────────────────────

  openEditModal() {
    const c = this.data.conference;
    const { dialog, close } = openModal({
      title: 'Edit Conference',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="edit-conference">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required value="${esc(c.name)}"></label>
        <label>Starts <input type="date" name="starts_at" value="${esc(c.starts_at || '')}"></label>
        <label>Ends <input type="date" name="ends_at" value="${esc(c.ends_at || '')}"></label>
        <label>City <input type="text" name="city" value="${esc(c.city || '')}"></label>
        <label>State <input type="text" name="state" value="${esc(c.state || '')}"></label>
        <label>Venue name <input type="text" name="venue_name" value="${esc(c.venue_name || '')}"></label>
        <label>Venue address <input type="text" name="venue_address" value="${esc(c.venue_address || '')}"></label>
        <label>Website <input type="url" name="website_url" value="${esc(c.website_url || '')}"></label>
        <label>Latitude <input type="number" step="any" name="latitude" value="${esc(c.latitude != null ? c.latitude : '')}"></label>
        <label>Longitude <input type="number" step="any" name="longitude" value="${esc(c.longitude != null ? c.longitude : '')}"></label>
        <label>Est. attendance <input type="number" min="0" name="estimated_attendance" value="${esc(c.estimated_attendance != null ? c.estimated_attendance : '')}"></label>
        <label>Est. exhibitors <input type="number" min="0" name="estimated_exhibitors" value="${esc(c.estimated_exhibitors != null ? c.estimated_exhibitors : '')}"></label>
        <label>Est. sponsors <input type="number" min="0" name="estimated_sponsors" value="${esc(c.estimated_sponsors != null ? c.estimated_sponsors : '')}"></label>
        <label>Opportunity score (0-100) <input type="number" min="0" max="100" name="opportunity_score" value="${esc(c.opportunity_score != null ? c.opportunity_score : '')}"></label>
        <label class="wide">Description <textarea name="description" rows="3">${esc(c.description || '')}</textarea></label>
        <div class="wide"><button type="submit" class="primary">Save</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="edit-conference"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await api(`/opportunity-conferences/${this.id}`, { method: 'PATCH', body: JSON.stringify(formData(form)) });
        publish('toast.show', { message: 'Conference updated.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openAddCompanyModal() {
    const { dialog, close } = openModal({
      title: 'Add Company',
      bodyHtml: `<form class="grid-form padded" data-form="add-company">
        <div class="wide opp-company-mode">
          <label><input type="radio" name="mode" value="existing" checked> Existing company</label>
          <label><input type="radio" name="mode" value="new"> New company</label>
        </div>
        <div class="wide" data-existing-picker>
          <label>Search companies <input type="text" data-company-search placeholder="Start typing a company name…" autocomplete="off"></label>
          <div class="opp-company-results" data-company-results></div>
          <input type="hidden" name="company_id">
          <p class="muted small" data-selected-company></p>
        </div>
        <label class="wide" data-new-name hidden>New company name <input type="text" name="new_company_name"></label>
        <label>Role <select name="role">${Object.entries(ROLE_LABELS).map(([v, l]) => `<option value="${v}" ${v === 'sponsor' ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
        <label>Sponsor tier <input type="text" name="sponsor_tier" placeholder="Gold, Silver, Platinum…"></label>
        <label class="wide">Notes <input type="text" name="participation_notes"></label>
        <div class="wide"><button type="submit" class="primary">Add Company</button></div>
      </form>`,
      focus: '[data-company-search]',
    });
    const form = $('[data-form="add-company"]', dialog);
    const search = $('[data-company-search]', dialog);
    const results = $('[data-company-results]', dialog);
    const hiddenId = form.company_id;
    const selectedLabel = $('[data-selected-company]', dialog);

    $$('input[name="mode"]', dialog).forEach((radio) => radio.addEventListener('change', () => {
      const isNew = form.mode.value === 'new';
      $('[data-existing-picker]', dialog).hidden = isNew;
      $('[data-new-name]', dialog).hidden = !isNew;
    }));

    search.addEventListener('input', debounce(async () => {
      const q = search.value.trim();
      hiddenId.value = '';
      selectedLabel.textContent = '';
      if (q.length < 2) { results.innerHTML = ''; return; }
      try {
        const data = await api(`/opportunity-companies?q=${encodeURIComponent(q)}`);
        results.innerHTML = (data.companies || []).slice(0, 8).map((c) =>
          `<button type="button" class="opp-company-result" data-pick-company="${esc(c.id)}" data-pick-name="${esc(c.name)}">${esc(c.name)}${c.domain ? ` <small class="muted">${esc(c.domain)}</small>` : ''}</button>`
        ).join('') || '<p class="muted small">No matches.</p>';
      } catch { /* ignore search errors */ }
    }, 250));

    results.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-pick-company]');
      if (!btn) return;
      hiddenId.value = btn.dataset.pickCompany;
      selectedLabel.textContent = `Selected: ${btn.dataset.pickName}`;
      results.innerHTML = '';
      search.value = '';
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        let companyId = hiddenId.value;
        if (form.mode.value === 'new') {
          const name = form.new_company_name.value.trim();
          if (!name) { publish('toast.show', { message: 'Enter a company name.', tone: 'error' }); return; }
          const created = await api('/opportunity-companies', { method: 'POST', body: JSON.stringify({ name }) });
          companyId = created.company.id;
        }
        if (!companyId) { publish('toast.show', { message: 'Search for and select a company, or switch to "New company".', tone: 'error' }); return; }
        await api(`/opportunity-conferences/${this.id}/companies`, {
          method: 'POST',
          body: JSON.stringify({ company_id: companyId, role: form.role.value, sponsor_tier: form.sponsor_tier.value, participation_notes: form.participation_notes.value }),
        });
        publish('toast.show', { message: 'Company added.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openCreateOpportunityModal(companyId, companyName) {
    const c = this.data.conference;
    const { dialog, close } = openModal({
      title: `Create Opportunity — ${companyName}`,
      bodyHtml: `<form class="grid-form padded" data-form="create-opportunity">
        <label class="wide">Opportunity name <span class="req">*</span><input type="text" name="name" required value="${esc(companyName)} ${esc(c.name)} Opportunity"></label>
        <label>Estimated value <input type="number" min="0" step="0.01" name="estimated_value"></label>
        <label>Probability % <input type="number" min="0" max="100" name="probability"></label>
        <label>Guest count min <input type="number" min="0" name="guest_count_min"></label>
        <label>Guest count max <input type="number" min="0" name="guest_count_max"></label>
        <div class="wide"><button type="submit" class="primary">Create Opportunity</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="create-opportunity"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      body.company_id = companyId;
      body.conference_id = this.id;
      try {
        const res = await api('/opportunities', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${res.opportunity.name} created.` });
        close();
        location.hash = `#opportunities-${res.opportunity.id}`;
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }
}
customElements.define('pb-opportunities-conference-detail', OpportunitiesConferenceDetail);
