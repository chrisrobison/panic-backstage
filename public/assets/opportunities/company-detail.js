// <pb-opportunities-company-detail> — Opportunities > Companies > {company}
// (docs/opportunity-ui/opportunity-3.png, "NVIDIA" mockup). Same "single-file
// detail page" shape as conference-detail.js/contacts.js/leads.js — every
// panel rendered inline, not split into child elements.
//
// Reuses, unmodified: Notes (`/opportunity-companies/{id}/notes`), Signals
// (`/opportunity-companies/{id}/signals`, used here for Buying Signals),
// ConferenceCompanies' reverse listing (`/opportunity-companies/{id}/conferences`,
// folded into the company GET response as `conferences`), and the Tasks app
// (`/task-documents/{id}/tasks`, reached via the lazily-provisioned link at
// `/opportunity-companies/{id}/tasks` — see src/Opportunities/TaskLink.php).
// Buyer contacts (`/opportunity-companies/{id}/contacts`) are new this phase
// — see src/Opportunities/Contacts.php.
import { esc, api, emptyState, openModal, formData, publish, subscribe, getAppCapabilities, PanicElement, $, $$ } from '../core.js';
import {
  relationshipStatusBadge, relationshipStatusLabel, contactStatusBadge,
  venueFitTagLabel, activityActionLabel, initials, avatarColor, relativeTime,
  shortMonthDay, noteTypeLabel, debounce, overdueTaskCount,
} from './shared.js';

const SIGNAL_TYPE_LABELS = {
  proximity: 'Proximity', availability: 'Availability', sponsorship: 'Sponsorship',
  exhibitor: 'Exhibitor', hospitality_history: 'Hospitality History', side_event_history: 'Side Event History',
  hiring: 'Hiring', company_size: 'Company Size', speaking: 'Speaking', budget: 'Budget', other: 'Other',
};

class OpportunitiesCompanyDetail extends PanicElement {
  async connect() {
    this.canManage = !!getAppCapabilities().manage_opportunities;
    this.data = null;
    this.contacts = [];
    this.notes = [];
    this.signals = [];
    this.activity = [];
    this.taskDocumentId = null;
    this.tasks = [];
    this.reloadDebounced = debounce(() => this.load(), 300);
    // Phase 8: previously fetched once — another user editing this
    // company, adding a buyer contact, or completing a task now refreshes
    // the page automatically. Also reload on 'opportunity' (this company's
    // own opportunities feed the Activity panel and KPIs).
    subscribe('data.invalidated', (msg) => {
      if ((msg.entity === 'opportunity_company' && (msg.id == null || msg.id === this.id))
        || msg.entity === 'opportunity' || msg.entity === 'global') this.reloadDebounced();
    }, this.abort.signal);
    await this.load();
  }

  async load() {
    this.setLoading('Loading company');
    try {
      const [detail, contactsRes, notesRes, signalsRes, activityRes, taskLink] = await Promise.all([
        api(`/opportunity-companies/${this.id}`),
        api(`/opportunity-companies/${this.id}/contacts`),
        api(`/opportunity-companies/${this.id}/notes`),
        api(`/opportunity-companies/${this.id}/signals`),
        api(`/opportunity-companies/${this.id}/activity`),
        api(`/opportunity-companies/${this.id}/tasks`),
      ]);
      this.data = detail;
      this.contacts = contactsRes.contacts || [];
      this.notes = notesRes.notes || [];
      this.signals = signalsRes.signals || [];
      this.activity = activityRes.activity || [];
      this.taskDocumentId = taskLink.task_document_id || null;
      this.tasks = this.taskDocumentId ? (await api(`/task-documents/${this.taskDocumentId}/tasks`)).tasks || [] : [];
      publish('page.context', { title: this.data.company.name, blurb: 'Conference activity, contacts, opportunities, and notes.' });
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const c = this.data.company;
    this.innerHTML = `
      <div class="page-head">
        <div>
          <a class="button secondary small" href="#opportunities-companies">&larr; Back to Companies</a>
          <h1>${esc(c.name)} ${relationshipStatusBadge(c.relationship_status)}</h1>
          <p class="subtle">${[c.industry, [c.hq_city, c.hq_state].filter(Boolean).join(', ')].filter(Boolean).map(esc).join(' · ') || 'Conference activity, contacts, opportunities, and notes.'}</p>
        </div>
        <div class="row-actions">
          ${this.canManage ? '<button type="button" class="button secondary" data-edit-company>Edit</button>' : ''}
          ${this.canManage ? '<button type="button" class="button danger" data-delete-company>Delete</button>' : ''}
        </div>
      </div>
      ${this.kpiCardsHtml()}
      <div class="opp-detail-layout">
        <div class="opp-detail-main">
          ${this.activeOpportunitiesHtml()}
          ${this.conferencePresenceHtml()}
          ${this.keyContactsHtml()}
          ${this.activityHtml()}
          ${this.notesHtml()}
          ${this.tasksHtml()}
        </div>
        <div class="opp-detail-rail">
          <div data-ai-research-slot></div>
          ${this.companyIntelligenceHtml(c)}
          ${this.buyingSignalsHtml()}
          ${this.venueFitHtml()}
          ${this.pitchIdeasHtml()}
        </div>
      </div>`;

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
    panel.scopeType = 'company';
    panel.scopeId = this.id;
    panel.scopeName = this.data.company.name;
    panel.addEventListener('research-imported', () => this.load());
    slot.replaceWith(panel);
  }

  // ── KPI cards ────────────────────────────────────────────────────────────

  kpiCardsHtml() {
    const k = this.data.kpis || {};
    const cards = [
      ['fa-briefcase', 'Open Opportunities', esc(k.open_opportunity_count ?? 0)],
      ['fa-calendar-days', 'Conferences Attending', esc(k.conference_count ?? 0)],
      ['fa-sack-dollar', 'Estimated Pipeline Value', k.pipeline_value ? `$${Number(k.pipeline_value).toLocaleString()}` : '<span class="muted">—</span>'],
      ['fa-clock-rotate-left', 'Last Activity', k.last_activity_at ? esc(relativeTime(k.last_activity_at)) : '<span class="muted">—</span>'],
    ];
    return `<section class="opp-kpis">
      ${cards.map(([icon, label, value]) => `<article class="kpi-card"><span class="kpi-icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span><div><span class="kpi-label">${esc(label)}</span><strong class="kpi-value">${value}</strong></div></article>`).join('')}
    </section>`;
  }

  // ── Active Opportunities ─────────────────────────────────────────────────

  activeOpportunitiesHtml() {
    const opps = this.data.opportunities || [];
    const rows = opps.length
      ? opps.map((o) => `<tr>
          <td><a href="#opportunities-${esc(o.id)}">${esc(o.name)}</a></td>
          <td>${o.conference_name ? esc(o.conference_name) : '<span class="muted">—</span>'}</td>
          <td><span class="badge">${esc(o.stage.replace(/_/g, ' '))}</span></td>
          <td>${o.event_type ? esc(o.event_type) : '<span class="muted">—</span>'}</td>
          <td>${o.estimated_value != null ? `$${Number(o.estimated_value).toLocaleString()}` : '<span class="muted">—</span>'}</td>
          <td>${o.owner_name ? esc(o.owner_name) : '<span class="muted">—</span>'}</td>
        </tr>`).join('')
      : `<tr><td colspan="6">${emptyState('No opportunities for this company yet.')}</td></tr>`;
    return `<article class="panel">
      <div class="section-head padded"><h2>Active Opportunities <span class="pill">${esc(opps.length)}</span></h2>
        ${this.canManage ? '<button type="button" class="button small" data-add-opportunity>+ New Opportunity</button>' : ''}
      </div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Opportunity</th><th>Conference</th><th>Stage</th><th>Event Type</th><th>Est. Value</th><th>Owner</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
    </article>`;
  }

  // ── Conference Presence ──────────────────────────────────────────────────

  conferencePresenceHtml() {
    const links = this.data.conferences || [];
    const rows = links.length
      ? links.map((l) => `<tr>
          <td><a href="#opportunities-conference-${esc(l.conference_id)}">${esc(l.conference_name)}</a></td>
          <td>${esc(l.role.replace(/_/g, ' '))}</td>
          <td>${l.conference_starts_at ? esc(shortMonthDay(l.conference_starts_at.slice(0, 10))) : '<span class="muted">—</span>'}</td>
          <td>${l.why_relevant ? esc(l.why_relevant) : '<span class="muted">—</span>'}</td>
        </tr>`).join('')
      : `<tr><td colspan="4">${emptyState('No conference participation recorded yet.')}</td></tr>`;
    return `<article class="panel">
      <div class="section-head padded"><h2>Conference Presence <span class="pill">${esc(links.length)}</span></h2></div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Conference</th><th>Role</th><th>Dates</th><th>Why Relevant</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
    </article>`;
  }

  // ── Key Contacts ─────────────────────────────────────────────────────────

  keyContactsHtml() {
    const rows = this.contacts.length
      ? this.contacts.map((ct) => `<tr>
          <td class="opp-contact-name-cell">
            <span class="opp-avatar" style="background:${avatarColor(ct.name)}">${esc(initials(ct.name))}</span>
            <span>${esc(ct.name)} ${ct.is_likely_buyer ? '<span class="badge success" title="Likely buyer role">Likely Buyer</span>' : ''}</span>
          </td>
          <td>${[ct.title, ct.department].filter(Boolean).map(esc).join(' · ') || '<span class="muted">—</span>'}</td>
          <td>${contactStatusBadge(ct.status)}</td>
          <td>${ct.last_touch_at ? esc(relativeTime(ct.last_touch_at)) : '<span class="muted">—</span>'}</td>
          <td class="row-actions">
            ${this.canManage ? `<button type="button" class="small secondary" data-edit-contact="${esc(ct.id)}">Edit</button>
            <button type="button" class="small danger" data-remove-contact="${esc(ct.id)}">Remove</button>` : ''}
          </td>
        </tr>`).join('')
      : `<tr><td colspan="5">${emptyState('No buyer contacts recorded yet.')}</td></tr>`;
    return `<article class="panel">
      <div class="section-head padded"><h2>Key Contacts <span class="pill">${esc(this.contacts.length)}</span></h2>
        ${this.canManage ? '<button type="button" class="button small" data-add-contact>+ Add Contact</button>' : ''}
      </div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Contact</th><th>Title / Department</th><th>Status</th><th>Last Touch</th><th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
    </article>`;
  }

  // ── Activity & Outreach ──────────────────────────────────────────────────

  activityHtml() {
    const items = this.activity.length
      ? this.activity.slice(0, 12).map((a) => `<li class="opp-activity-item">
          <strong>${esc(activityActionLabel(a.action, a.details))}</strong>
          <small class="muted">${a.opportunity_name ? esc(a.opportunity_name) + ' &middot; ' : ''}${a.created_by_name ? esc(a.created_by_name) + ' &middot; ' : ''}${esc(relativeTime(a.created_at))}</small>
        </li>`).join('')
      : `<li class="muted">No activity recorded yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Activity &amp; Outreach</h2></div>
      <ul class="opp-note-list">${items}</ul>
      <p class="muted small">Real stage changes, notes, and signals across this company's opportunities — see Notes and Open Tasks below for the rest.</p>
    </article>`;
  }

  // ── Notes ────────────────────────────────────────────────────────────────

  notesHtml() {
    const pinned = this.notes.filter((n) => n.is_pinned);
    const items = this.notes.length
      ? this.notes.map((n) => `<li class="opp-note-item">
          <div class="opp-note-head"><strong>${esc(n.created_by_name || 'Unknown')}</strong>
            <span class="badge">${esc(noteTypeLabel(n.note_type))}</span>
            ${n.is_pinned ? '<i class="fa-solid fa-thumbtack" title="Pinned" aria-hidden="true"></i>' : ''}
          </div>
          <p>${esc(n.body)}</p>
          <small class="muted">${esc(shortMonthDay((n.created_at || '').slice(0, 10)))}</small>
        </li>`).join('')
      : `<li class="muted">No notes yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Notes ${pinned.length ? `<span class="pill">${esc(pinned.length)} pinned</span>` : ''}</h2></div>
      <ul class="opp-note-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form opp-inline-form-wide" data-form="add-note">
        <textarea name="body" rows="2" placeholder="Add a note about this company…" required></textarea>
        <label class="opp-inline-check"><input type="checkbox" name="is_pinned"> Pin this note</label>
        <button type="submit" class="small">Add Note</button>
      </form>` : ''}
    </article>`;
  }

  // ── Open Tasks ───────────────────────────────────────────────────────────

  tasksHtml() {
    if (!this.taskDocumentId) {
      return `<article class="panel padded">
        <div class="section-head"><h2>Open Tasks</h2></div>
        <p class="muted">No tasks yet for this company.</p>
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

  // ── Right rail: Company Intelligence / Buying Signals / Venue Fit / Pitch Ideas ──

  companyIntelligenceHtml(c) {
    const rows = [
      ['Industry', c.industry],
      ['Employees', c.employee_range],
      ['HQ', [c.hq_city, c.hq_state].filter(Boolean).join(', ')],
      ['Local Office', c.local_office ? 'Yes' : null],
      ['Website', c.website_url ? `<a href="${esc(c.website_url)}" target="_blank" rel="noopener">${esc(c.website_url)}</a>` : null],
      ['LinkedIn', c.linkedin_url ? `<a href="${esc(c.linkedin_url)}" target="_blank" rel="noopener">Profile</a>` : null],
      ['Relationship', relationshipStatusLabel(c.relationship_status)],
    ];
    const items = rows.filter(([, v]) => v).map(([label, v]) => `<div class="opp-fact-row"><span class="muted">${esc(label)}</span><span>${v.startsWith?.('<a') ? v : esc(v)}</span></div>`).join('');
    return `<article class="panel padded">
      <div class="section-head"><h2>Company Intelligence</h2></div>
      <div class="opp-fact-grid">${items || '<p class="muted">Nothing recorded yet.</p>'}</div>
      ${c.description ? `<p class="opp-company-description">${esc(c.description)}</p>` : ''}
    </article>`;
  }

  buyingSignalsHtml() {
    const items = this.signals.length
      ? this.signals.slice(0, 8).map((s) => `<li class="opp-signal-item">
          <strong>${esc(s.description)}</strong>
          <small class="muted">${SIGNAL_TYPE_LABELS[s.signal_type] || s.signal_type} ${s.source_url ? `&middot; <a href="${esc(s.source_url)}" target="_blank" rel="noopener">source</a>` : ''}</small>
        </li>`).join('')
      : `<li class="muted">No buying signals recorded yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Buying Signals <span class="pill">${esc(this.signals.length)}</span></h2></div>
      <ul class="opp-signal-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form opp-inline-form-wide" data-form="add-signal">
        <input type="text" name="description" placeholder="e.g. Hiring a Field Marketing Manager" required>
        <select name="signal_type">${Object.entries(SIGNAL_TYPE_LABELS).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}</select>
        <input type="url" name="source_url" placeholder="Source URL (optional)">
        <button type="submit" class="small">Add</button>
      </form>` : ''}
    </article>`;
  }

  venueFitHtml() {
    const tags = this.data.venue_fit_tags || [];
    return `<article class="panel padded">
      <div class="section-head"><h2>Venue Fit</h2></div>
      ${tags.length
        ? `<div class="opp-tag-cloud">${tags.map((t) => `<span class="pill">${esc(venueFitTagLabel(t))}</span>`).join('')}</div>`
        : '<p class="muted">Not enough data yet — add conference roles or company details.</p>'}
    </article>`;
  }

  pitchIdeasHtml() {
    const ideas = this.data.pitch_ideas || [];
    return `<article class="panel padded">
      <div class="section-head"><h2>Pitch Ideas</h2></div>
      <ul class="opp-suggestion-list">${ideas.map((i) => `<li class="opp-suggestion"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i>${esc(i)}</li>`).join('')}</ul>
    </article>`;
  }

  // ── Wiring ───────────────────────────────────────────────────────────────

  bind() {
    $('[data-edit-company]', this)?.addEventListener('click', () => this.openEditModal());
    $('[data-delete-company]', this)?.addEventListener('click', () => this.deleteCompany());
    $('[data-add-contact]', this)?.addEventListener('click', () => this.openContactModal());
    $('[data-add-opportunity]', this)?.addEventListener('click', () => this.openCreateOpportunityModal());
    $('[data-start-tasks]', this)?.addEventListener('click', () => this.ensureTasks());

    $$('[data-edit-contact]', this).forEach((btn) => btn.addEventListener('click', () => this.openContactModal(Number(btn.dataset.editContact))));
    $$('[data-remove-contact]', this).forEach((btn) => btn.addEventListener('click', () => this.removeContact(Number(btn.dataset.removeContact))));
    $$('[data-toggle-task]', this).forEach((box) => box.addEventListener('change', () => this.toggleTask(Number(box.dataset.toggleTask), box.checked)));

    const noteForm = $('[data-form="add-note"]', this);
    noteForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(noteForm);
      if (!body.body?.trim()) return;
      body.is_pinned = !!noteForm.is_pinned?.checked;
      try {
        await api(`/opportunity-companies/${this.id}/notes`, { method: 'POST', body: JSON.stringify(body) });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const signalForm = $('[data-form="add-signal"]', this);
    signalForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(signalForm);
      if (!body.description?.trim()) return;
      try {
        await api(`/opportunity-companies/${this.id}/signals`, { method: 'POST', body: JSON.stringify(body) });
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

  async deleteCompany() {
    if (!confirm(`Delete ${this.data.company.name}? This cannot be undone.`)) return;
    try {
      await api(`/opportunity-companies/${this.id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Company deleted.' });
      location.hash = '#opportunities-companies';
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async removeContact(contactId) {
    if (!confirm('Remove this contact?')) return;
    try {
      await api(`/opportunity-companies/${this.id}/contacts/${contactId}`, { method: 'DELETE' });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async ensureTasks() {
    try {
      const res = await api(`/opportunity-companies/${this.id}/tasks`, { method: 'POST' });
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
    const c = this.data.company;
    const { dialog, close } = openModal({
      title: 'Edit Company',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="edit-company">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required value="${esc(c.name)}"></label>
        <label>Domain <input type="text" name="domain" value="${esc(c.domain || '')}"></label>
        <label>Website <input type="url" name="website_url" value="${esc(c.website_url || '')}"></label>
        <label>Logo URL <input type="url" name="logo_url" value="${esc(c.logo_url || '')}"></label>
        <label>Industry <input type="text" name="industry" value="${esc(c.industry || '')}"></label>
        <label>Employee range <input type="text" name="employee_range" value="${esc(c.employee_range || '')}"></label>
        <label>HQ City <input type="text" name="hq_city" value="${esc(c.hq_city || '')}"></label>
        <label>HQ State <input type="text" name="hq_state" value="${esc(c.hq_state || '')}"></label>
        <label>LinkedIn <input type="url" name="linkedin_url" value="${esc(c.linkedin_url || '')}"></label>
        <label>Relationship status
          <select name="relationship_status">
            ${['prospect', 'active', 'past_client', 'do_not_contact', 'unknown'].map((s) => `<option value="${s}" ${c.relationship_status === s ? 'selected' : ''}>${esc(relationshipStatusLabel(s))}</option>`).join('')}
          </select>
        </label>
        <label><input type="checkbox" name="local_office" ${c.local_office ? 'checked' : ''}> Has a local office near this venue</label>
        <label class="wide">Description <textarea name="description" rows="3">${esc(c.description || '')}</textarea></label>
        <div class="wide"><button type="submit" class="primary">Save</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="edit-company"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      body.local_office = !!form.local_office.checked;
      try {
        await api(`/opportunity-companies/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Company updated.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openContactModal(contactId) {
    const existing = contactId ? this.contacts.find((c) => c.id === contactId) : null;
    const { dialog, close } = openModal({
      title: existing ? 'Edit Contact' : 'Add Contact',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="contact-form">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required value="${esc(existing?.name || '')}"></label>
        <label>Title <input type="text" name="title" placeholder="Field Marketing Director" value="${esc(existing?.title || '')}"></label>
        <label>Department <input type="text" name="department" value="${esc(existing?.department || '')}"></label>
        <label>Email <input type="email" name="email" value="${esc(existing?.email || '')}"></label>
        <label>Phone <input type="text" name="phone" value="${esc(existing?.phone || '')}"></label>
        <label>LinkedIn <input type="url" name="linkedin_url" value="${esc(existing?.linkedin_url || '')}"></label>
        <label>Status
          <select name="status">
            ${['active', 'cold', 'left_company', 'unknown'].map((s) => `<option value="${s}" ${(existing?.status || 'unknown') === s ? 'selected' : ''}>${esc(s.replace(/_/g, ' '))}</option>`).join('')}
          </select>
        </label>
        <label class="wide">Source URL <input type="url" name="source_url" value="${esc(existing?.source_url || '')}"></label>
        <div class="wide"><button type="submit" class="primary">${existing ? 'Save' : 'Add Contact'}</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="contact-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      if (!body.name?.trim()) return;
      try {
        const path = existing ? `/opportunity-companies/${this.id}/contacts/${existing.id}` : `/opportunity-companies/${this.id}/contacts`;
        await api(path, { method: existing ? 'PATCH' : 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: existing ? 'Contact updated.' : 'Contact added.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openCreateOpportunityModal() {
    const c = this.data.company;
    const { dialog, close } = openModal({
      title: `Create Opportunity — ${c.name}`,
      bodyHtml: `<form class="grid-form padded" data-form="create-opportunity">
        <label class="wide">Opportunity name <span class="req">*</span><input type="text" name="name" required value="${esc(c.name)} Opportunity"></label>
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
      body.company_id = this.id;
      try {
        const res = await api('/opportunities', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: `${res.opportunity.name} created.` });
        close();
        location.hash = `#opportunities-${res.opportunity.id}`;
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }
}
customElements.define('pb-opportunities-company-detail', OpportunitiesCompanyDetail);
