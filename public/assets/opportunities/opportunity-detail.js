// <pb-opportunities-detail> — Opportunities > Pipeline > {opportunity}
// (docs/opportunity-ui/opportunity-4.png, "NVIDIA Partner Reception"
// mockup). Same "single-file detail page" shape as company-detail.js/
// conference-detail.js, reusing its `.opp-detail-layout` main+rail grid.
//
// The mockup's four top tabs (Overview / Notes / Activity / Linked Records)
// all show up on ONE page in the mockup screenshot itself (Overview already
// contains qualification, venue fit, notes, and activity together) — this
// implements them as a real tab switch over the MAIN column only; the right
// sidebar (Next Actions, Risk Flags, Buying Signals, Quick Quote) is always
// visible regardless of tab, matching the mockup's persistent right rail.
//
// Reuses, unmodified: Notes/Signals (`/opportunities/{id}/notes|signals`),
// the lazily-provisioned Tasks link (`/opportunities/{id}/tasks`, same
// TaskLink.php pattern as conferences/companies), and Qualification/
// DecisionMakers (new this phase — src/Opportunities/Qualification.php,
// src/Opportunities/DecisionMakers.php).
import { esc, api, emptyState, openModal, formData, publish, subscribe, getAppCapabilities, PanicElement, $, $$ } from '../core.js';
import {
  stageBadge, shortMonthDay, relativeTime, noteTypeLabel, NOTE_TYPES,
  activityActionLabel, decisionMakerRoleBadge, QUALIFICATION_ITEMS, debounce,
} from './shared.js';

const TABS = [
  ['overview', 'Overview'],
  ['notes', 'Notes'],
  ['activity', 'Activity'],
  ['linked', 'Linked Records'],
];

class OpportunitiesDetail extends PanicElement {
  async connect() {
    this.canManage = !!getAppCapabilities().manage_opportunities;
    this.activeTab = 'overview';
    this.noteFilter = 'all';
    this.reloadDebounced = debounce(() => this.load(), 300);
    subscribe('data.invalidated', (msg) => {
      if (msg.entity === 'opportunity' && (!msg.id || Number(msg.id) === this.id)) this.reloadDebounced();
    }, this.abort.signal);
    await this.load();
  }

  async load() {
    this.setLoading('Loading opportunity');
    try {
      const detail = await api(`/opportunities/${this.id}`);
      this.data = detail;
      const o = detail.opportunity;

      const [notesRes, signalsRes, activityRes, qualRes, dmRes, taskLink, contactsRes] = await Promise.all([
        api(`/opportunities/${this.id}/notes`),
        api(`/opportunities/${this.id}/signals`),
        api(`/opportunities/${this.id}/activities`),
        api(`/opportunities/${this.id}/qualification`),
        api(`/opportunities/${this.id}/decision-makers`),
        api(`/opportunities/${this.id}/tasks`),
        api(`/opportunity-companies/${o.company_id}/contacts`),
      ]);
      this.notes = notesRes.notes || [];
      this.signals = signalsRes.signals || [];
      this.activity = activityRes.activities || [];
      this.qualification = qualRes.qualification;
      this.decisionMakers = dmRes.decision_makers || [];
      this.taskDocumentId = taskLink.task_document_id || null;
      this.tasks = this.taskDocumentId ? (await api(`/task-documents/${this.taskDocumentId}/tasks`)).tasks || [] : [];
      this.companyContacts = contactsRes.contacts || [];

      publish('page.context', { title: o.name, blurb: `${o.company_name}${o.conference_name ? ' — ' + o.conference_name : ''}` });
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const o = this.data.opportunity;
    this.innerHTML = `
      <div class="page-head">
        <div>
          <a class="button secondary small" href="#opportunities-pipeline">&larr; Back to Opportunities</a>
          <h1>${esc(o.name)}</h1>
          <p class="subtle">Opportunity details and qualification</p>
        </div>
        <div class="row-actions">
          ${o.won_event_id
            ? `<a class="button primary" href="#event-${esc(o.won_event_id)}"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> View Event</a>`
            : (this.canManage && o.stage !== 'lost' ? '<button type="button" class="button primary" data-convert><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Convert to Event</button>' : '')}
          ${this.canManage ? '<button type="button" class="button secondary" data-create-proposal>Create Proposal</button>' : ''}
          ${this.canManage ? '<button type="button" class="button secondary" data-log-activity>Log Activity</button>' : ''}
          ${this.canManage ? '<button type="button" class="button secondary" data-edit>Edit</button>' : ''}
          ${this.canManage ? '<button type="button" class="button danger" data-delete>Delete</button>' : ''}
        </div>
      </div>
      ${this.headerFactsHtml(o)}
      <div class="opp-tabs">
        ${TABS.map(([key, label]) => `<button type="button" class="opp-tab${this.activeTab === key ? ' active' : ''}" data-tab="${key}">${esc(label)}</button>`).join('')}
      </div>
      <div class="opp-detail-layout">
        <div class="opp-detail-main">
          ${this.mainContentHtml(o)}
        </div>
        <div class="opp-detail-rail">
          ${this.nextActionsHtml(o)}
          ${this.riskFlagsHtml()}
          ${this.buyingSignalsHtml()}
          ${this.quickQuoteHtml(o)}
        </div>
      </div>`;

    this.bind();
  }

  // ── Header ───────────────────────────────────────────────────────────────

  headerFactsHtml(o) {
    const facts = [
      ['fa-flag', 'Stage', stageBadge(o.stage)],
      ['fa-percent', 'Probability', o.probability != null ? `${esc(o.probability)}%` : '<span class="muted">—</span>'],
      ['fa-sack-dollar', 'Est. Revenue', o.estimated_value != null ? `$${Number(o.estimated_value).toLocaleString()}` : '<span class="muted">—</span>'],
      ['fa-calendar-day', 'Target Date', o.target_date ? esc(shortMonthDay(o.target_date)) : '<span class="muted">—</span>'],
      ['fa-building', 'Company', `<a href="#opportunities-company-${esc(o.company_id)}">${esc(o.company_name)}</a>`],
      ['fa-calendar-days', 'Conference', o.conference_id ? `<a href="#opportunities-conference-${esc(o.conference_id)}">${esc(o.conference_name)}</a>` : '<span class="muted">—</span>'],
      ['fa-user-group', 'Guest Count', (o.guest_count_min || o.guest_count_max) ? `${esc(o.guest_count_min ?? '?')}–${esc(o.guest_count_max ?? '?')}` : '<span class="muted">—</span>'],
      ['fa-user', 'Owner', o.owner_name ? esc(o.owner_name) : '<span class="muted">Unassigned</span>'],
    ];
    return `<section class="opp-header-facts">
      ${facts.map(([icon, label, value]) => `<div class="opp-header-fact"><span class="muted"><i class="fa-solid ${icon}" aria-hidden="true"></i> ${esc(label)}</span><strong>${value}</strong></div>`).join('')}
    </section>`;
  }

  // ── Main column (tab-switched) ──────────────────────────────────────────

  mainContentHtml(o) {
    if (this.activeTab === 'notes') return this.notesTabHtml();
    if (this.activeTab === 'activity') return this.activityTabHtml();
    if (this.activeTab === 'linked') return this.linkedRecordsHtml(o);
    return `${this.qualificationHtml()}
      ${this.decisionMakersHtml()}
      ${this.venueFitHtml(o)}
      ${this.activityHtml(6)}`;
  }

  qualificationHtml() {
    const q = this.qualification || {};
    return `<article class="panel padded">
      <div class="section-head"><h2>Qualification Checklist <span class="pill">${esc(q.completed_count ?? 0)} / ${esc(q.total_count ?? QUALIFICATION_ITEMS.length)}</span></h2></div>
      <ul class="opp-qual-list">
        ${QUALIFICATION_ITEMS.map(([key, label]) => `<li class="opp-qual-item">
          <label>
            <input type="checkbox" data-qual="${key}" ${q[key] ? 'checked' : ''} ${this.canManage ? '' : 'disabled'}>
            <i class="fa-solid ${q[key] ? 'fa-circle-check opp-qual-done' : 'fa-circle'}" aria-hidden="true"></i>
            ${esc(label)}
          </label>
        </li>`).join('')}
      </ul>
    </article>`;
  }

  decisionMakersHtml() {
    const rows = this.decisionMakers.length
      ? this.decisionMakers.map((dm) => `<li class="opp-dm-item">
          <span>${esc(dm.name)}${dm.title ? ` <small class="muted">${esc(dm.title)}</small>` : ''}</span>
          ${decisionMakerRoleBadge(dm.role)}
          ${this.canManage ? `<button type="button" class="small danger" data-remove-dm="${esc(dm.id)}" aria-label="Remove ${esc(dm.name)}">&times;</button>` : ''}
        </li>`).join('')
      : `<li class="muted">No decision makers linked yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Decision Makers <span class="pill">${esc(this.decisionMakers.length)}</span></h2>
        ${this.canManage ? '<button type="button" class="button small secondary" data-add-dm>+ Add</button>' : ''}
      </div>
      <ul class="opp-dm-list">${rows}</ul>
    </article>`;
  }

  venueFitHtml(o) {
    const resources = this.data.resources || [];
    const budgetFit = this.data.budget_fit || { status: 'unknown', label: 'Unknown' };
    return `<article class="panel padded">
      <div class="section-head"><h2>Proposed Event Format &amp; Venue Fit</h2></div>
      <p class="opp-fact-row-label">Recommended Space</p>
      <div class="opp-room-grid">
        ${resources.length ? resources.map((r) => `<button type="button" class="opp-room-card${r.recommended ? ' opp-room-recommended' : ''}${o.recommended_resource_id === r.id ? ' opp-room-selected' : ''}" data-pick-room="${esc(r.id)}" ${this.canManage ? '' : 'disabled'}>
            <strong>${esc(r.name)}</strong>
            <span class="muted">${r.capacity ? `${esc(r.capacity)} guests` : 'Capacity unset'}</span>
            ${r.recommended ? '<span class="badge success">Recommended</span>' : ''}
          </button>`).join('') : emptyState('No venue spaces configured yet — add rooms under Admin > Venue.')}
      </div>
      <div class="opp-budget-fit opp-budget-fit-${esc(budgetFit.status)}"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <strong>Budget Fit:</strong> ${esc(budgetFit.label)}</div>
      ${this.canManage ? `<form class="grid-form padded" data-form="venue-fit-form">
        <label>Budget range min <input type="number" min="0" step="0.01" name="budget_range_min" value="${o.budget_range_min ?? ''}"></label>
        <label>Budget range max <input type="number" min="0" step="0.01" name="budget_range_max" value="${o.budget_range_max ?? ''}"></label>
        <label class="wide">AV requirements <textarea name="av_requirements" rows="2">${esc(o.av_requirements || '')}</textarea></label>
        <label class="wide">Food &amp; beverage notes <textarea name="catering_notes" rows="2">${esc(o.catering_notes || '')}</textarea></label>
        <div class="wide"><button type="submit" class="secondary small">Save Venue Fit Details</button></div>
      </form>` : ''}
    </article>`;
  }

  activityHtml(limit) {
    const items = (limit ? this.activity.slice(0, limit) : this.activity);
    const rows = items.length
      ? items.map((a) => `<li class="opp-activity-item">
          <strong>${esc(activityActionLabel(a.action, this.parseDetails(a.details_json)))}</strong>
          <small class="muted">${a.created_by_name ? esc(a.created_by_name) + ' &middot; ' : ''}${esc(relativeTime(a.created_at))}</small>
        </li>`).join('')
      : `<li class="muted">No activity yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Activity Feed</h2>${limit && this.activity.length > limit ? `<button type="button" class="button secondary small" data-view-all-activity>View all</button>` : ''}</div>
      <ul class="opp-note-list">${rows}</ul>
    </article>`;
  }

  activityTabHtml() {
    return this.activityHtml(null);
  }

  parseDetails(json) {
    if (!json) return {};
    try { return typeof json === 'string' ? JSON.parse(json) : json; } catch { return {}; }
  }

  // ── Notes tab ────────────────────────────────────────────────────────────

  notesTabHtml() {
    const counts = {
      all: this.notes.length,
      pinned: this.notes.filter((n) => n.is_pinned).length,
      meeting: this.notes.filter((n) => n.note_type === 'meeting').length,
      call: this.notes.filter((n) => n.note_type === 'call').length,
      research: this.notes.filter((n) => n.note_type === 'research').length,
      internal: this.notes.filter((n) => n.note_type === 'internal').length,
    };
    const filtered = this.notes.filter((n) => {
      if (this.noteFilter === 'all') return true;
      if (this.noteFilter === 'pinned') return n.is_pinned;
      return n.note_type === this.noteFilter;
    });
    const rows = filtered.length
      ? filtered.map((n) => `<li class="opp-note-item">
          <div class="opp-note-head">
            <strong>${esc(n.created_by_name || (n.is_ai_generated ? 'AI Assistant' : 'Unknown'))}</strong>
            <span class="badge">${esc(noteTypeLabel(n.note_type))}</span>
            ${n.is_ai_generated ? '<span class="badge info">AI</span>' : ''}
            ${n.is_pinned ? '<i class="fa-solid fa-thumbtack" title="Pinned" aria-hidden="true"></i>' : ''}
            ${this.canManage ? `<button type="button" class="opp-note-pin-toggle" data-toggle-pin="${esc(n.id)}" title="${n.is_pinned ? 'Unpin' : 'Pin'}"><i class="fa-solid fa-thumbtack" aria-hidden="true"></i></button>` : ''}
          </div>
          <p>${esc(n.body)}</p>
          <small class="muted">${esc(relativeTime(n.created_at))}</small>
        </li>`).join('')
      : `<li class="muted">No notes in this filter.</li>`;
    return `<article class="panel padded">
      <div class="section-head opp-note-tabs">
        <button type="button" class="opp-tab-sm${this.noteFilter === 'all' ? ' active' : ''}" data-note-filter="all">All Notes ${counts.all}</button>
        <button type="button" class="opp-tab-sm${this.noteFilter === 'pinned' ? ' active' : ''}" data-note-filter="pinned">Pinned ${counts.pinned}</button>
        <button type="button" class="opp-tab-sm${this.noteFilter === 'meeting' ? ' active' : ''}" data-note-filter="meeting">Meetings ${counts.meeting}</button>
        <button type="button" class="opp-tab-sm${this.noteFilter === 'call' ? ' active' : ''}" data-note-filter="call">Calls ${counts.call}</button>
        <button type="button" class="opp-tab-sm${this.noteFilter === 'research' ? ' active' : ''}" data-note-filter="research">Research ${counts.research}</button>
        <button type="button" class="opp-tab-sm${this.noteFilter === 'internal' ? ' active' : ''}" data-note-filter="internal">Internal ${counts.internal}</button>
      </div>
      <ul class="opp-note-list">${rows}</ul>
      ${this.canManage ? `<form class="opp-inline-form opp-inline-form-wide" data-form="add-note">
        <textarea name="body" rows="3" placeholder="Add a note… use @ to mention, # to tag" required></textarea>
        <div class="inline-actions">
          <select name="note_type">${NOTE_TYPES.map((t) => `<option value="${t}">${esc(noteTypeLabel(t))}</option>`).join('')}</select>
          <label class="opp-inline-check"><input type="checkbox" name="is_pinned"> Pin</label>
          <button type="submit" class="small">Add Note</button>
        </div>
      </form>` : ''}
    </article>`;
  }

  // ── Linked Records tab ───────────────────────────────────────────────────

  linkedRecordsHtml(o) {
    const items = this.tasks.filter((t) => t.status !== 'done');
    return `<article class="panel padded">
      <div class="section-head"><h2>Linked Records</h2></div>
      <div class="opp-linked-grid">
        <div class="opp-linked-item"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
          <div><span class="muted">Conference</span>${o.conference_id ? `<strong><a href="#opportunities-conference-${esc(o.conference_id)}">${esc(o.conference_name)}</a></strong>` : '<strong class="muted">None</strong>'}</div>
        </div>
        <div class="opp-linked-item"><i class="fa-solid fa-building" aria-hidden="true"></i>
          <div><span class="muted">Company</span><strong><a href="#opportunities-company-${esc(o.company_id)}">${esc(o.company_name)}</a></strong></div>
        </div>
        <div class="opp-linked-item"><i class="fa-solid fa-address-card" aria-hidden="true"></i>
          <div><span class="muted">Primary Contact</span>
            <strong>${o.primary_contact_name ? esc(o.primary_contact_name) + (o.primary_contact_title ? ` <small class="muted">${esc(o.primary_contact_title)}</small>` : '') : '<span class="muted">None set</span>'}</strong>
          </div>
          ${this.canManage ? '<button type="button" class="small secondary" data-set-primary-contact>Change</button>' : ''}
        </div>
      </div>
    </article>
    ${this.tasksHtml(items)}`;
  }

  tasksHtml(openTasks) {
    if (!this.taskDocumentId) {
      return `<article class="panel padded">
        <div class="section-head"><h2>Related Tasks</h2></div>
        <p class="muted">No tasks yet for this opportunity.</p>
        ${this.canManage ? '<button type="button" class="button small secondary" data-start-tasks>+ Add first task</button>' : ''}
      </article>`;
    }
    const items = this.tasks.length
      ? this.tasks.map((t) => `<li class="opp-task-item ${t.status === 'done' ? 'opp-task-done' : ''}">
          <label><input type="checkbox" data-toggle-task="${esc(t.id)}" ${t.status === 'done' ? 'checked' : ''}> ${esc(t.title)}</label>
          ${t.due_date ? `<small class="muted">${esc(shortMonthDay(t.due_date))}</small>` : ''}
        </li>`).join('')
      : `<li class="muted">No tasks yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Related Tasks <span class="pill">${esc(openTasks.length)}</span></h2>
        <a class="button secondary small" href="#tasks-${esc(this.taskDocumentId)}">Open in Tasks</a>
      </div>
      <ul class="opp-task-list">${items}</ul>
      ${this.canManage ? `<form class="opp-inline-form" data-form="add-task">
        <input type="text" name="title" placeholder="Add a task…" required>
        <button type="submit" class="small">Add</button>
      </form>` : ''}
    </article>`;
  }

  // ── Right sidebar ────────────────────────────────────────────────────────

  nextActionsHtml(o) {
    return `<article class="panel padded">
      <div class="section-head"><h2>Next Actions</h2></div>
      ${o.next_action
        ? `<p class="opp-next-action"><strong>${esc(o.next_action)}</strong>${o.next_action_at ? `<br><small class="muted">Due ${esc(shortMonthDay(String(o.next_action_at).slice(0, 10)))}</small>` : ''}</p>`
        : '<p class="muted">No next action set.</p>'}
      ${this.canManage ? `<form class="opp-inline-form opp-inline-form-wide" data-form="next-action-form">
        <input type="text" name="next_action" placeholder="e.g. Follow up with finance owner" value="${esc(o.next_action || '')}">
        <input type="datetime-local" name="next_action_at" value="${o.next_action_at ? esc(String(o.next_action_at).slice(0, 16)) : ''}">
        <button type="submit" class="small">Save</button>
      </form>` : ''}
    </article>`;
  }

  riskFlagsHtml() {
    const flags = this.data.risk_flags || [];
    return `<article class="panel padded">
      <div class="section-head"><h2>Risk Flags ${flags.length ? `<span class="pill pill-danger">${esc(flags.length)}</span>` : ''}</h2></div>
      ${flags.length
        ? `<ul class="opp-risk-list">${flags.map((f) => `<li class="opp-risk-item"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> ${esc(f.label)}</li>`).join('')}</ul>`
        : '<p class="muted">No risk flags right now.</p>'}
    </article>`;
  }

  buyingSignalsHtml() {
    const items = this.signals.length
      ? this.signals.slice(0, 8).map((s) => `<li class="opp-signal-item">
          <strong>${esc(s.description)}</strong>
          <small class="muted">${esc((s.signal_type || '').replace(/_/g, ' '))} ${s.source_url ? `&middot; <a href="${esc(s.source_url)}" target="_blank" rel="noopener">source</a>` : ''}</small>
        </li>`).join('')
      : `<li class="muted">No buying signals recorded yet.</li>`;
    return `<article class="panel padded">
      <div class="section-head"><h2>Buying Signals <span class="pill">${esc(this.signals.length)}</span></h2></div>
      <ul class="opp-signal-list">${items}</ul>
    </article>`;
  }

  quickQuoteHtml(o) {
    return `<article class="panel padded opp-quick-quote">
      <div class="section-head"><h2>Quick Quote</h2></div>
      <div class="opp-fact-grid">
        <div class="opp-fact-row"><span class="muted">Estimated Total</span><span>${o.estimated_value != null ? `$${Number(o.estimated_value).toLocaleString()}` : '—'}</span></div>
        <div class="opp-fact-row"><span class="muted">Guest Count</span><span>${(o.guest_count_min || o.guest_count_max) ? `${o.guest_count_min ?? '?'}–${o.guest_count_max ?? '?'}` : '—'}</span></div>
        <div class="opp-fact-row"><span class="muted">Package</span><span>${o.quote_package ? esc(o.quote_package) : '—'}</span></div>
        <div class="opp-fact-row"><span class="muted">Duration</span><span>${o.quote_duration_hours ? `${esc(o.quote_duration_hours)} hours` : '—'}</span></div>
      </div>
      ${this.canManage ? '<button type="button" class="button primary" data-update-quote>Update Quote</button>' : ''}
    </article>`;
  }

  // ── Wiring ───────────────────────────────────────────────────────────────

  bind() {
    $$('[data-tab]', this).forEach((btn) => btn.addEventListener('click', () => { this.activeTab = btn.dataset.tab; this.render(); }));
    $$('[data-note-filter]', this).forEach((btn) => btn.addEventListener('click', () => { this.noteFilter = btn.dataset.noteFilter; this.render(); }));

    $('[data-convert]', this)?.addEventListener('click', () => this.openConvertModal());
    $('[data-create-proposal]', this)?.addEventListener('click', () => this.openCreateProposalModal());
    $('[data-log-activity]', this)?.addEventListener('click', () => this.openLogActivityModal());
    $('[data-edit]', this)?.addEventListener('click', () => this.openEditModal());
    $('[data-delete]', this)?.addEventListener('click', () => this.deleteOpportunity());
    $('[data-add-dm]', this)?.addEventListener('click', () => this.openAddDecisionMakerModal());
    $('[data-set-primary-contact]', this)?.addEventListener('click', () => this.openSetPrimaryContactModal());
    $('[data-start-tasks]', this)?.addEventListener('click', () => this.ensureTasks());
    $('[data-update-quote]', this)?.addEventListener('click', () => this.openUpdateQuoteModal());
    $('[data-view-all-activity]', this)?.addEventListener('click', () => { this.activeTab = 'activity'; this.render(); });

    $$('[data-remove-dm]', this).forEach((btn) => btn.addEventListener('click', () => this.removeDecisionMaker(Number(btn.dataset.removeDm))));
    $$('[data-pick-room]', this).forEach((btn) => btn.addEventListener('click', () => this.pickRoom(Number(btn.dataset.pickRoom))));
    $$('[data-qual]', this).forEach((box) => box.addEventListener('change', () => this.toggleQualification(box.dataset.qual, box.checked)));
    $$('[data-toggle-task]', this).forEach((box) => box.addEventListener('change', () => this.toggleTask(Number(box.dataset.toggleTask), box.checked)));
    $$('[data-toggle-pin]', this).forEach((btn) => btn.addEventListener('click', () => this.toggleNotePin(Number(btn.dataset.togglePin))));

    const venueFitForm = $('[data-form="venue-fit-form"]', this);
    venueFitForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(venueFitForm);
      try {
        await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Venue fit details saved.' });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const nextActionForm = $('[data-form="next-action-form"]', this);
    nextActionForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(nextActionForm);
      try {
        await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Next action saved.' });
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });

    const noteForm = $('[data-form="add-note"]', this);
    noteForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(noteForm);
      if (!body.body?.trim()) return;
      body.is_pinned = !!noteForm.is_pinned?.checked;
      try {
        await api(`/opportunities/${this.id}/notes`, { method: 'POST', body: JSON.stringify(body) });
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

  async pickRoom(resourceId) {
    try {
      await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify({ recommended_resource_id: resourceId }) });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async toggleQualification(item, checked) {
    try {
      await api(`/opportunities/${this.id}/qualification`, { method: 'PATCH', body: JSON.stringify({ [item]: checked }) });
      const qualRes = await api(`/opportunities/${this.id}/qualification`);
      this.qualification = qualRes.qualification;
      this.render();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async removeDecisionMaker(linkId) {
    if (!confirm('Remove this decision maker?')) return;
    try {
      await api(`/opportunities/${this.id}/decision-makers/${linkId}`, { method: 'DELETE' });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async ensureTasks() {
    try {
      const res = await api(`/opportunities/${this.id}/tasks`, { method: 'POST' });
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

  async toggleNotePin(noteId) {
    const note = this.notes.find((n) => n.id === noteId);
    if (!note) return;
    try {
      await api(`/opportunities/${this.id}/notes/${noteId}`, { method: 'PATCH', body: JSON.stringify({ is_pinned: !note.is_pinned }) });
      await this.load();
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  async deleteOpportunity() {
    if (!confirm(`Delete "${this.data.opportunity.name}"? This cannot be undone.`)) return;
    try {
      await api(`/opportunities/${this.id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Opportunity deleted.' });
      location.hash = '#opportunities-pipeline';
    } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
  }

  // ── Modals ───────────────────────────────────────────────────────────────

  openConvertModal() {
    const o = this.data.opportunity;
    const { dialog, close } = openModal({
      title: 'Convert to Event',
      bodyHtml: `<form class="grid-form padded" data-form="convert-form">
        <p class="wide muted">This creates exactly one Backstage event, prefilled from this opportunity. Review the details below before confirming.</p>
        <label class="wide">Event title <input type="text" name="title" value="${esc(o.name)}"></label>
        <label>Date <input type="date" name="date" value="${esc(o.target_date || '')}"></label>
        <label>Estimated guests <input type="number" min="0" name="estimated_guests" value="${o.guest_count_max ?? o.guest_count_min ?? ''}"></label>
        <div class="wide"><button type="submit" class="primary">Confirm &amp; Create Event</button></div>
      </form>`,
      focus: '[name="title"]',
    });
    const form = $('[data-form="convert-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      try {
        const res = await api(`/opportunities/${this.id}/convert`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: res.already_converted ? 'Already converted — opening existing event.' : 'Event created.' });
        close();
        location.hash = res.event_url;
      } catch (err) { publish('toast.show', { message: err.message || 'Could not convert to event.', tone: 'error' }); }
    });
  }

  openCreateProposalModal() {
    const { dialog, close } = openModal({
      title: 'Create Proposal',
      bodyHtml: `<form class="grid-form padded" data-form="proposal-form">
        <p class="wide muted">Moves this opportunity to Proposal Sent and logs it on the activity feed.</p>
        <label class="wide">Note <textarea name="note" rows="3" placeholder="e.g. Sent package + pricing to Jason"></textarea></label>
        <div class="wide"><button type="submit" class="primary">Mark Proposal Sent</button></div>
      </form>`,
      focus: '[name="note"]',
    });
    const form = $('[data-form="proposal-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const note = form.note.value.trim() || 'Proposal sent.';
      try {
        if (this.data.opportunity.stage !== 'proposal_sent') {
          await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify({ stage: 'proposal_sent' }) });
        }
        await api(`/opportunities/${this.id}/activities`, { method: 'POST', body: JSON.stringify({ activity_type: 'proposal', note }) });
        publish('toast.show', { message: 'Proposal noted.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openLogActivityModal() {
    const { dialog, close } = openModal({
      title: 'Log Activity',
      bodyHtml: `<form class="grid-form padded" data-form="log-activity-form">
        <label>Type
          <select name="activity_type">
            <option value="call">Call</option>
            <option value="meeting">Meeting</option>
            <option value="note">Note</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label class="wide">Details <span class="req">*</span><textarea name="note" rows="3" required></textarea></label>
        <div class="wide"><button type="submit" class="primary">Log Activity</button></div>
      </form>`,
      focus: '[name="note"]',
    });
    const form = $('[data-form="log-activity-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      if (!body.note?.trim()) return;
      try {
        await api(`/opportunities/${this.id}/activities`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Activity logged.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openEditModal() {
    const o = this.data.opportunity;
    const users = this.data.users || [];
    const { dialog, close } = openModal({
      title: 'Edit Opportunity',
      wide: true,
      bodyHtml: `<form class="grid-form padded" data-form="edit-opportunity">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required value="${esc(o.name)}"></label>
        <label>Estimated value <input type="number" min="0" step="0.01" name="estimated_value" value="${o.estimated_value ?? ''}"></label>
        <label>Probability % <input type="number" min="0" max="100" name="probability" value="${o.probability ?? ''}"></label>
        <label>Target date <input type="date" name="target_date" value="${o.target_date || ''}"></label>
        <label>Target date end <input type="date" name="target_date_end" value="${o.target_date_end || ''}"></label>
        <label>Guest count min <input type="number" min="0" name="guest_count_min" value="${o.guest_count_min ?? ''}"></label>
        <label>Guest count max <input type="number" min="0" name="guest_count_max" value="${o.guest_count_max ?? ''}"></label>
        <label>Event type <input type="text" name="event_type" value="${esc(o.event_type || '')}"></label>
        <label>Owner
          <select name="owner_user_id"><option value="">Unassigned</option>${users.map((u) => `<option value="${esc(u.id)}" ${String(o.owner_user_id) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
        </label>
        <label class="wide">Event concept <textarea name="event_concept" rows="3">${esc(o.event_concept || '')}</textarea></label>
        ${o.stage !== 'lost' ? '' : `<label class="wide">Lost reason <input type="text" name="lost_reason" value="${esc(o.lost_reason || '')}"></label>`}
        <div class="wide"><button type="submit" class="primary">Save</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    const form = $('[data-form="edit-opportunity"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      try {
        await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Opportunity updated.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openAddDecisionMakerModal() {
    const available = this.companyContacts.filter((c) => !this.decisionMakers.some((dm) => dm.contact_id === c.id));
    if (!available.length) {
      publish('toast.show', { message: 'No more company contacts to add — add one from the Company page first.', tone: 'error' });
      return;
    }
    const { dialog, close } = openModal({
      title: 'Add Decision Maker',
      bodyHtml: `<form class="grid-form padded" data-form="add-dm-form">
        <label class="wide">Contact
          <select name="contact_id">${available.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}${c.title ? ` — ${esc(c.title)}` : ''}</option>`).join('')}</select>
        </label>
        <label class="wide">Role
          <select name="role">
            <option value="champion">Champion</option>
            <option value="influencer">Influencer</option>
            <option value="decision_maker">Decision Maker</option>
            <option value="finance">Finance</option>
            <option value="blocker">Blocker</option>
            <option value="other">Other</option>
          </select>
        </label>
        <div class="wide"><button type="submit" class="primary">Add</button></div>
      </form>`,
    });
    const form = $('[data-form="add-dm-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      try {
        await api(`/opportunities/${this.id}/decision-makers`, { method: 'POST', body: JSON.stringify(body) });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openSetPrimaryContactModal() {
    const { dialog, close } = openModal({
      title: 'Set Primary Contact',
      bodyHtml: `<form class="grid-form padded" data-form="primary-contact-form">
        <label class="wide">Contact
          <select name="primary_contact_id">
            <option value="">None</option>
            ${this.companyContacts.map((c) => `<option value="${esc(c.id)}" ${this.data.opportunity.primary_contact_id === c.id ? 'selected' : ''}>${esc(c.name)}${c.title ? ` — ${esc(c.title)}` : ''}</option>`).join('')}
          </select>
        </label>
        <div class="wide"><button type="submit" class="primary">Save</button></div>
      </form>`,
    });
    const form = $('[data-form="primary-contact-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      body.primary_contact_id = body.primary_contact_id || null;
      try {
        await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }

  openUpdateQuoteModal() {
    const o = this.data.opportunity;
    const { dialog, close } = openModal({
      title: 'Update Quote',
      bodyHtml: `<form class="grid-form padded" data-form="quote-form">
        <label>Estimated total <input type="number" min="0" step="0.01" name="estimated_value" value="${o.estimated_value ?? ''}"></label>
        <label>Package <input type="text" name="quote_package" placeholder="e.g. Reception Package" value="${esc(o.quote_package || '')}"></label>
        <label>Duration (hours) <input type="number" min="0" step="0.5" name="quote_duration_hours" value="${o.quote_duration_hours ?? ''}"></label>
        <label>Guest count min <input type="number" min="0" name="guest_count_min" value="${o.guest_count_min ?? ''}"></label>
        <label>Guest count max <input type="number" min="0" name="guest_count_max" value="${o.guest_count_max ?? ''}"></label>
        <div class="wide"><button type="submit" class="primary">Save Quote</button></div>
      </form>`,
      focus: '[name="estimated_value"]',
    });
    const form = $('[data-form="quote-form"]', dialog);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = formData(form);
      try {
        await api(`/opportunities/${this.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Quote updated.' });
        close();
        await this.load();
      } catch (err) { publish('toast.show', { message: err.message, tone: 'error' }); }
    });
  }
}
customElements.define('pb-opportunities-detail', OpportunitiesDetail);
