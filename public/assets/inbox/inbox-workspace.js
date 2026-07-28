// <pb-inbox-workspace> — the main panel: header (name/number/status/owner/
// source/score/claim button), tabs (Conversation/Details/Event Info/Files/
// Notes/Tasks/History), and the bottom action bar. Owns the per-lead API
// calls (claim/status/reassign/etc.) and bubbles `inbox-lead-changed` so the
// shell reloads the list row + detail panel without this component needing
// to know how those are rendered.
import { esc, api, publish, openModal, PanicElement, $, $$ } from '../core.js';
import './inbox-conversation.js';
import { statusLabel, ALL_STATUSES, REASON_REQUIRED_STATUSES, relativeTime, scoreTone, initials, avatarColor, computeActionBar } from './inbox-shared.js';

// Maps an action-bar data-action id to the workspace method that handles it
// — one delegated listener on .ib-action-bar (see bind()) instead of a
// separate addEventListener per button, whether the button is a visible
// primary/secondary control or tucked inside the overflow "More" menu.
const ACTION_HANDLERS = {
  onboard: (self) => self.openOnboard(),
  availability: (self) => self.prefillTemplate('availability'),
  proposal: (self) => self.prefillTemplate('proposal'),
  tour: (self) => self.openTour(),
  task: (self) => self.openTask(),
  assign: (self) => self.openAssign(),
  reassign: (self) => self.openReassign(),
  decline: (self) => self.changeStatus('declined'),
  archive: (self) => self.changeStatus('archived'),
  more: (self) => self.openMoreActions(),
};

const TABS = [['conversation', 'Conversation'], ['details', 'Details'], ['event-info', 'Event Info'], ['files', 'Files'], ['notes', 'Notes'], ['tasks', 'Tasks'], ['history', 'History']];

class InboxWorkspace extends PanicElement {
  set data(value) {
    const changed = !this._data || this._data.lead?.id !== value?.lead?.id;
    this._data = value || {};
    this.activeTab = changed ? 'conversation' : (this.activeTab || 'conversation');
    if (changed) this._overflowOpen = false;
    this.render();
  }
  get data() { return this._data || {}; }

  connect() {
    this.render();
    // Close the action-bar overflow menu on an outside click/Escape — a
    // single listener for the component's lifetime rather than one added
    // per render().
    document.addEventListener('click', (e) => {
      if (this._overflowOpen && !e.target.closest('.ib-overflow')) {
        this._overflowOpen = false;
        this.render();
      }
    }, { signal: this.abort.signal });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this._overflowOpen) {
        this._overflowOpen = false;
        this.render();
      }
    }, { signal: this.abort.signal });
  }

  render() {
    const { lead, capabilities = {}, pendingApproval } = this.data;
    if (!lead) {
      this.innerHTML = `<div class="ib-empty-main"><i class="fa-regular fa-comments" aria-hidden="true"></i><p>Select an inquiry to get started.</p></div>`;
      return;
    }

    const name = lead.contact_org || lead.contact_name || 'Unknown';
    const canClaim = capabilities.claim && !lead.claimed_by_user_id && lead.status !== 'onboarded';
    const tone = scoreTone(lead.inquiry_score);
    const canManage = capabilities.manage !== false;

    this.innerHTML = `
      <div class="ib-workspace">
        <div class="ib-workspace-head">
          <div class="ib-workspace-title-row">
            <h1>${esc(name)}</h1>
            <span class="muted">Inquiry ${esc(lead.inquiry_number || ('#' + lead.id))}</span>
          </div>
          <div class="ib-workspace-subline">
            <span><i class="fa-regular fa-clock" aria-hidden="true"></i>${esc(relativeTime(lead.created_at))}</span>
            ${lead.event_type ? `<span><i class="fa-solid fa-tag" aria-hidden="true"></i>${esc(lead.event_type)}</span>` : ''}
            ${lead.projected_attendance ? `<span><i class="fa-solid fa-users" aria-hidden="true"></i>${esc(String(lead.projected_attendance))} guests</span>` : ''}
            ${lead.desired_date ? `<span><i class="fa-regular fa-calendar" aria-hidden="true"></i>${esc(lead.desired_date)}</span>` : ''}
          </div>

          <div class="ib-status-bar">
            <div class="ib-status-field">
              <label>Status</label>
              <select data-status-select ${canManage ? '' : 'disabled'}>
                ${ALL_STATUSES.map((s) => `<option value="${s}" ${s === lead.status ? 'selected' : ''}>${esc(statusLabel(s))}</option>`).join('')}
              </select>
            </div>
            <div class="ib-status-field">
              <label>Owner</label>
              <span class="ib-owner-chip">
                <span class="ib-avatar" style="width:22px;height:22px;font-size:10px;background:${avatarColor(lead.assigned_to_name)}">${esc(initials(lead.assigned_to_name))}</span>
                ${esc(lead.owner_name || lead.assigned_to_name || 'Unassigned')}
              </span>
            </div>
            <div class="ib-status-field">
              <label>Source</label>
              <span>${esc(lead.source || '—')}</span>
            </div>
            <div class="ib-status-field">
              <label>Inquiry Score</label>
              <span class="ib-score-ring ${tone}"><i class="fa-solid fa-circle" aria-hidden="true"></i> ${lead.inquiry_score ?? '—'}</span>
            </div>
            <div class="ib-claim-btn">
              ${canClaim
                ? `<button type="button" class="button" data-claim><i class="fa-solid fa-lock" aria-hidden="true"></i> Claim Inquiry</button>`
                : lead.claimed_by_user_id ? `<button type="button" class="button secondary" data-release-claim>Release Claim</button>` : ''}
            </div>
          </div>
          ${pendingApproval ? `<div class="ib-stale-warning">
            <span><i class="fa-solid fa-user-shield" aria-hidden="true"></i> ${esc(pendingApproval.requested_by_name || 'A booker')} requested ${esc(statusLabel(pendingApproval.requested_status))}: ${esc(pendingApproval.reason || 'No reason supplied')}</span>
            <span><button type="button" class="small" data-approval="approve">Approve</button> <button type="button" class="small secondary" data-approval="deny">Deny</button></span>
          </div>` : ''}

          <nav class="ib-tabs" data-tabs>
            ${TABS.map(([id, label]) => `<a href="#" class="${this.activeTab === id ? 'active' : ''}" data-tab="${id}">${esc(label)}</a>`).join('')}
          </nav>
        </div>

        <div class="ib-tab-body" data-tab-body></div>

        ${this.renderActionBar(lead, capabilities, canManage)}
      </div>`;

    this.bind();
    this.mountTab();
  }

  /**
   * Renders the bottom action bar as three tiers (see
   * inbox-shared.js::computeActionBar): primary/secondary buttons visible
   * directly in the bar, and an overflow "More" menu for the rest. The
   * overflow menu is always rendered in the DOM (so data-action wiring and
   * keyboard access work the same as any other button) — just hidden until
   * toggled open, per this._overflowOpen.
   */
  renderActionBar(lead, capabilities, canManage) {
    if (!canManage) return '';
    const { primary, secondary, overflow } = computeActionBar(lead, capabilities);
    if (!primary.length && !secondary.length && !overflow.length) return '';

    const button = (action, tone) => `<button type="button" class="button ${tone === 'primary' ? (action.tone || '') : 'secondary'}" data-action="${action.id}">
      <i class="${action.icon}" aria-hidden="true"></i> ${esc(action.label)}</button>`;

    return `<div class="ib-action-bar">
      ${primary.map((a) => button(a, 'primary')).join('')}
      ${secondary.map((a) => button(a, 'secondary')).join('')}
      ${overflow.length ? `<div class="ib-overflow" data-overflow>
        <button type="button" class="button secondary" data-action="more-menu" aria-haspopup="true" aria-expanded="${this._overflowOpen ? 'true' : 'false'}">
          <i class="fa-solid fa-ellipsis" aria-hidden="true"></i> More</button>
        <div class="ib-overflow-menu" data-overflow-menu ${this._overflowOpen ? '' : 'hidden'}>
          ${overflow.map((a) => `<button type="button" data-action="${a.id}"><i class="${a.icon}" aria-hidden="true"></i> ${esc(a.label)}</button>`).join('')}
        </div>
      </div>` : ''}
    </div>`;
  }

  bind() {
    $('[data-claim]', this)?.addEventListener('click', () => this.claim());
    $('[data-release-claim]', this)?.addEventListener('click', () => this.releaseClaim());
    $('[data-status-select]', this)?.addEventListener('change', (e) => this.changeStatus(e.target.value));
    $$('[data-tab]', this).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); this.activeTab = a.dataset.tab; this.render(); }));
    $$('[data-approval]', this).forEach((button) => button.addEventListener('click', () => this.decideApproval(button.dataset.approval)));
    this.addEventListener('inbox-message-sent', () => this.notifyChanged(), { signal: this.abort.signal });

    // Single delegated listener for every action-bar button (primary,
    // secondary, and overflow) instead of one addEventListener per action.
    $('.ib-action-bar', this)?.addEventListener('click', (e) => {
      if (e.target.closest('[data-action="more-menu"]')) {
        this._overflowOpen = !this._overflowOpen;
        this.render();
        return;
      }
      const target = e.target.closest('[data-action]');
      if (!target) return;
      const fromOverflow = !!target.closest('[data-overflow-menu]');
      const handler = ACTION_HANDLERS[target.dataset.action];
      if (handler) handler(this);
      // Picking an overflow item always closes the menu, whether the
      // action opens a modal (assign/reassign/task/other status) or
      // applies directly (decline/archive).
      if (fromOverflow) {
        this._overflowOpen = false;
        this.render();
      }
    });
  }

  mountTab() {
    const wrap = $('[data-tab-body]', this);
    if (!wrap) return;
    const { lead } = this.data;

    if (this.activeTab === 'conversation') {
      const el = document.createElement('pb-inbox-conversation');
      el.data = { leadId: lead.id, lead, replyTemplates: this.data.replyTemplates || [], venueName: this.data.venueName || '' };
      wrap.replaceChildren(el);
      return;
    }
    if (this.activeTab === 'history') {
      wrap.innerHTML = '<div class="padded" data-history-body>Loading…</div>';
      this.loadHistory(lead.id);
      return;
    }
    if (this.activeTab === 'details') {
      wrap.innerHTML = `<div class="padded grid-form">
        ${row('Contact', lead.contact_name)}${row('Email', lead.contact_email)}${row('Phone', lead.contact_phone)}
        ${row('Organization', lead.contact_org)}${row('Source', lead.source)}
        ${row('Point person', lead.point_person_name)}${row('Risk level', lead.risk_level)}
      </div>`;
      return;
    }
    if (this.activeTab === 'event-info') {
      wrap.innerHTML = `<div class="padded grid-form">
        ${row('Event Name', lead.event_name)}${row('Event Type', lead.event_type)}${row('Category', lead.event_category)}
        ${row('Genre', lead.music_genre)}${row('Band(s)', lead.band_name)}${row('Rooms Requested', lead.rooms_requested)}
        ${row('Desired Date', lead.desired_date)}${row('Alt Date', lead.desired_date_alt)}
        ${row('Attendance', lead.projected_attendance)}${row('Budget', lead.budget)}${row('Age restriction', lead.age_restriction)}
        ${row('Private event', lead.is_private ? 'Yes' : 'No')}${row('Alcohol plan', lead.alcohol_plan)}
        ${row('Notes', lead.notes)}
      </div>`;
      return;
    }
    if (this.activeTab === 'notes') {
      wrap.innerHTML = '<div class="empty-state padded">Internal notes are shown inline in the Conversation tab (marked "Internal Note").</div>';
      return;
    }
    if (this.activeTab === 'tasks') {
      wrap.innerHTML = '<div class="padded" data-tasks-body>Loading…</div>';
      this.loadTasks(lead.id);
      return;
    }
    if (this.activeTab === 'files') {
      this.loadAttachments(lead.id, wrap);
      return;
    }
    wrap.innerHTML = '';
  }

  async loadHistory(leadId) {
    const wrap = $('[data-tab-body]', this);
    try {
      const res = await api(`/leads/${leadId}/audit`);
      if (!wrap) return;
      const rows = res.audit || [];
      wrap.innerHTML = rows.length ? `<div class="padded">${rows.map((r) => `
        <div class="ib-detail-row"><span class="k">${esc(new Date(r.created_at.replace(' ', 'T') + 'Z').toLocaleString())}</span>
        <span class="v">${esc(r.action)}${r.user_name ? ' — ' + esc(r.user_name) : ''}</span></div>`).join('')}</div>`
        : '<div class="empty-state padded">No history yet.</div>';
    } catch (err) {
      if (wrap) wrap.innerHTML = `<div class="empty-state padded">${esc(err.message)}</div>`;
    }
  }

  async loadAttachments(leadId, wrap) {
    try {
      const res = await api(`/leads/${leadId}/attachments`);
      const rows = res.attachments || [];
      wrap.innerHTML = rows.length
        ? `<div class="padded">${rows.map((a) => `<div class="ib-detail-row"><a class="k" href="${esc(a.storage_path)}" target="_blank" rel="noopener">${esc(a.filename)}</a><span class="v">${esc(new Date(a.created_at.replace(' ', 'T') + 'Z').toLocaleDateString())}</span></div>`).join('')}</div>`
        : '<div class="empty-state padded">No files yet.</div>';
    } catch (err) {
      wrap.innerHTML = `<div class="empty-state padded">${esc(err.message)}</div>`;
    }
  }

  async loadTasks(leadId) {
    const wrap = $('[data-tasks-body]', this);
    try {
      const res = await api(`/leads/${leadId}/tasks`);
      if (!wrap) return;
      const rows = res.tasks || [];
      wrap.innerHTML = rows.length
        ? rows.map((task) => `<div class="ib-detail-row"><span class="k">${esc(task.title)}</span><span class="v">${esc(task.assignee_name || 'Unassigned')} · ${esc(task.due_date || task.status.replace(/_/g, ' '))}</span></div>`).join('')
        : '<div class="empty-state">No linked tasks yet.</div>';
    } catch (err) {
      if (wrap) wrap.innerHTML = `<div class="empty-state">${esc(err.message)}</div>`;
    }
  }

  async claim() {
    const { lead } = this.data;
    try {
      await api(`/leads/${lead.id}/claim`, { method: 'POST' });
      publish('toast.show', { message: 'Inquiry claimed.' });
      this.notifyChanged();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async releaseClaim() {
    const { lead } = this.data;
    try {
      await api(`/leads/${lead.id}/release-claim`, { method: 'POST', body: JSON.stringify({}) });
      publish('toast.show', { message: 'Claim released.' });
      this.notifyChanged();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async changeStatus(status, suppliedReason = null) {
    const { lead } = this.data;
    let reason = suppliedReason;
    if (REASON_REQUIRED_STATUSES.includes(status) && !String(reason || '').trim()) {
      const select = $('[data-status-select]', this);
      if (select) select.value = lead.status;
      this.openStatusReason(status);
      return;
    }
    try {
      const res = await api(`/leads/${lead.id}/status`, { method: 'POST', body: JSON.stringify({ status, reason }) });
      if (res.pendingApproval) {
        publish('toast.show', { message: 'This is a high-value inquiry — a manager approval request was created.' });
      } else {
        publish('toast.show', { message: `Status updated to ${statusLabel(status)}.` });
      }
      this.notifyChanged();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openStatusReason(status) {
    const { dialog, close } = openModal({
      title: statusLabel(status),
      bodyHtml: `<form class="grid-form padded" data-status-reason-form>
        <label class="wide">Reason<textarea name="reason" required></textarea></label>
        <div class="wide"><button type="submit">Confirm</button></div>
      </form>`,
    });
    $('[data-status-reason-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const reason = String(new FormData(event.currentTarget).get('reason') || '').trim();
      if (!reason) return;
      close();
      await this.changeStatus(status, reason);
    });
  }

  openReassign() {
    const { lead, users = [] } = this.data;
    const { dialog, close } = openModal({
      title: 'Reassign Inquiry',
      bodyHtml: `<form class="grid-form padded" data-reassign-form>
        <label class="wide">Assign to<select name="user_id" required><option value="">Select a person</option>${users.map((user) => `<option value="${user.id}">${esc(user.name)} · ${esc(user.role)}</option>`).join('')}</select></label>
        <label class="wide">Reason<textarea name="reason" required></textarea></label>
        <div class="wide"><button type="submit">Reassign</button></div>
      </form>`,
    });
    $('[data-reassign-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      try {
        await api(`/leads/${lead.id}/reassign`, { method: 'POST', body: JSON.stringify({ user_id: Number(values.user_id), reason: values.reason }) });
        close();
        publish('toast.show', { message: 'Inquiry reassigned.' });
        this.notifyChanged();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  openAssign() {
    const { lead, users = [] } = this.data;
    const { dialog, close } = openModal({
      title: 'Assign Inquiry',
      bodyHtml: `<form class="grid-form padded" data-assign-form>
        <label class="wide">Assign to<select name="user_id" required><option value="">Select a person</option>${users.map((user) => `<option value="${user.id}">${esc(user.name)} · ${esc(user.role)}</option>`).join('')}</select></label>
        <label class="wide">Note (optional)<textarea name="reason"></textarea></label>
        <div class="wide"><button type="submit">Assign</button></div>
      </form>`,
    });
    $('[data-assign-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      try {
        await api(`/leads/${lead.id}/assign`, { method: 'POST', body: JSON.stringify({ user_id: Number(values.user_id), reason: values.reason || undefined }) });
        close();
        publish('toast.show', { message: 'Inquiry assigned.' });
        this.notifyChanged();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  prefillTemplate(kind, replacements = {}) {
    const template = (this.data.replyTemplates || []).find((item) => item.kind === kind);
    if (!template) {
      publish('toast.show', { message: `No ${kind.replace(/_/g, ' ')} template is configured.`, tone: 'error' });
      return;
    }
    this.activeTab = 'conversation';
    this.render();
    $('pb-inbox-conversation', this)?.prefillTemplate(template, replacements);
  }

  openTour() {
    const { dialog, close } = openModal({
      title: 'Schedule Venue Tour',
      bodyHtml: `<form class="grid-form padded" data-tour-form>
        <label>Date<input type="date" name="date" required></label>
        <label>Time<input type="time" name="time" required></label>
        <div class="wide"><button type="submit">Prepare Confirmation</button></div>
      </form>`,
    });
    $('[data-tour-form]', dialog)?.addEventListener('submit', (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      const when = new Date(`${values.date}T${values.time}`);
      const label = Number.isNaN(when.getTime()) ? `${values.date} at ${values.time}` : when.toLocaleString([], { dateStyle: 'long', timeStyle: 'short' });
      close();
      this.activeTab = 'conversation';
      this.render();
      $('pb-inbox-conversation', this)?.prefill({
        subject: 'Re: venue tour',
        body: `We have scheduled your venue tour for ${label}. Please reply to confirm that this still works for you.`,
        workflowAction: 'tour',
      });
    });
  }

  openTask() {
    const users = this.data.users || [];
    const { dialog, close } = openModal({
      title: 'Add Inquiry Task',
      bodyHtml: `<form class="grid-form padded" data-task-form>
        <label class="wide">Task<input type="text" name="title" required></label>
        <label>Due date<input type="date" name="due_date"></label>
        <label>Priority<select name="priority"><option>medium</option><option>high</option><option>urgent</option><option>low</option></select></label>
        <label class="wide">Assignee<select name="assignee_user_id"><option value="">Me</option>${users.map((user) => `<option value="${user.id}">${esc(user.name)}</option>`).join('')}</select></label>
        <label class="wide">Details<textarea name="description"></textarea></label>
        <div class="wide"><button type="submit">Add Task</button></div>
      </form>`,
    });
    $('[data-task-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      try {
        await api(`/leads/${this.data.lead.id}/tasks`, { method: 'POST', body: JSON.stringify(values) });
        close();
        publish('toast.show', { message: 'Task linked to this inquiry.' });
        this.activeTab = 'tasks';
        this.render();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  openMoreActions() {
    const { dialog, close } = openModal({
      title: 'More Inquiry Actions',
      bodyHtml: `<form class="grid-form padded" data-more-form>
        <label class="wide">Action<select name="status"><option value="awaiting_customer">Awaiting customer</option><option value="on_hold">Put on hold</option><option value="lost">Mark lost</option><option value="spam">Mark spam</option><option value="duplicate">Mark duplicate</option></select></label>
        <label class="wide">Reason<textarea name="reason"></textarea></label>
        <div class="wide"><button type="submit">Apply Action</button></div>
      </form>`,
    });
    $('[data-more-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      if (REASON_REQUIRED_STATUSES.includes(values.status) && !String(values.reason || '').trim()) {
        publish('toast.show', { message: 'A reason is required for this action.', tone: 'error' });
        return;
      }
      close();
      await this.changeStatus(values.status, values.reason || null);
    });
  }

  async decideApproval(decision) {
    const approval = this.data.pendingApproval;
    if (!approval) return;
    try {
      await api(`/inbox/approvals/${approval.id}`, { method: 'POST', body: JSON.stringify({ decision }) });
      publish('toast.show', { message: decision === 'approve' ? 'Request approved.' : 'Request denied.' });
      this.notifyChanged();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openOnboard() {
    this.dispatchEvent(new CustomEvent('inbox-open-onboard', { bubbles: true, detail: { lead: this.data.lead } }));
  }

  notifyChanged() {
    this.dispatchEvent(new CustomEvent('inbox-lead-changed', { bubbles: true, detail: { leadId: this.data.lead.id } }));
  }
}

function row(label, value) {
  if (value === null || value === undefined || value === '') return '';
  return `<label class="wide"><span class="muted">${esc(label)}</span><div>${esc(String(value))}</div></label>`;
}

customElements.define('pb-inbox-workspace', InboxWorkspace);
