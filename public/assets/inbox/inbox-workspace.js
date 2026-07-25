// <pb-inbox-workspace> — the main panel: header (name/number/status/owner/
// source/score/claim button), tabs (Conversation/Details/Event Info/Files/
// Notes/Tasks/History), and the bottom action bar. Owns the per-lead API
// calls (claim/status/reassign/etc.) and bubbles `inbox-lead-changed` so the
// shell reloads the list row + detail panel without this component needing
// to know how those are rendered.
import { esc, api, publish, PanicElement, $, $$ } from '../core.js';
import './inbox-conversation.js';
import { statusLabel, ALL_STATUSES, REASON_REQUIRED_STATUSES, relativeTime, scoreTone, initials, avatarColor } from './inbox-shared.js';

const TABS = [['conversation', 'Conversation'], ['details', 'Details'], ['event-info', 'Event Info'], ['files', 'Files'], ['notes', 'Notes'], ['tasks', 'Tasks'], ['history', 'History']];

class InboxWorkspace extends PanicElement {
  set data(value) {
    const changed = !this._data || this._data.lead?.id !== value?.lead?.id;
    this._data = value || {};
    this.activeTab = changed ? 'conversation' : (this.activeTab || 'conversation');
    this.render();
  }
  get data() { return this._data || {}; }

  connect() { this.render(); }

  render() {
    const { lead } = this.data;
    if (!lead) {
      this.innerHTML = `<div class="ib-empty-main"><i class="fa-regular fa-comments" aria-hidden="true"></i><p>Select an inquiry to get started.</p></div>`;
      return;
    }

    const name = lead.contact_org || lead.contact_name || 'Unknown';
    const canClaim = !lead.claimed_by_user_id && lead.status !== 'onboarded';
    const tone = scoreTone(lead.inquiry_score);

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
              <select data-status-select>
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

          <nav class="ib-tabs" data-tabs>
            ${TABS.map(([id, label]) => `<a href="#" class="${this.activeTab === id ? 'active' : ''}" data-tab="${id}">${esc(label)}</a>`).join('')}
          </nav>
        </div>

        <div class="ib-tab-body" data-tab-body></div>

        <div class="ib-action-bar">
          <button type="button" class="button primary-green" data-action="onboard"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Onboard Lead</button>
          <button type="button" class="button secondary" data-action="availability"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Send Availability</button>
          <button type="button" class="button secondary" data-action="proposal"><i class="fa-regular fa-file-lines" aria-hidden="true"></i> Send Proposal</button>
          <button type="button" class="button secondary" data-action="tour"><i class="fa-solid fa-people-group" aria-hidden="true"></i> Schedule Tour</button>
          <button type="button" class="button secondary" data-action="task"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Add Task</button>
          <button type="button" class="button secondary" data-action="reassign"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Reassign</button>
          <button type="button" class="button secondary" data-action="decline"><i class="fa-regular fa-circle-xmark" aria-hidden="true"></i> Decline</button>
          <button type="button" class="button secondary" data-action="archive"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
          <button type="button" class="button secondary" data-action="more"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i> More Actions</button>
        </div>
      </div>`;

    this.bind();
    this.mountTab();
  }

  bind() {
    $('[data-claim]', this)?.addEventListener('click', () => this.claim());
    $('[data-release-claim]', this)?.addEventListener('click', () => this.releaseClaim());
    $('[data-status-select]', this)?.addEventListener('change', (e) => this.changeStatus(e.target.value));
    $$('[data-tab]', this).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); this.activeTab = a.dataset.tab; this.render(); }));
    $('[data-action="decline"]', this)?.addEventListener('click', () => this.changeStatus('declined'));
    $('[data-action="archive"]', this)?.addEventListener('click', () => this.changeStatus('archived'));
    $('[data-action="onboard"]', this)?.addEventListener('click', () => this.openOnboard());
    $('[data-action="reassign"]', this)?.addEventListener('click', () => this.openReassign());
    this.addEventListener('inbox-message-sent', () => this.notifyChanged(), { signal: this.abort.signal });
  }

  mountTab() {
    const wrap = $('[data-tab-body]', this);
    if (!wrap) return;
    const { lead } = this.data;

    if (this.activeTab === 'conversation') {
      const el = document.createElement('pb-inbox-conversation');
      el.data = { leadId: lead.id, lead };
      wrap.replaceChildren(el);
      return;
    }
    if (this.activeTab === 'history') {
      wrap.innerHTML = '<div class="padded" data-history-body>Loading…</div>';
      this.loadHistory(lead.id);
      return;
    }
    if (this.activeTab === 'details' || this.activeTab === 'event-info') {
      wrap.innerHTML = `<div class="padded grid-form">
        ${row('Contact', lead.contact_name)}${row('Email', lead.contact_email)}${row('Phone', lead.contact_phone)}
        ${row('Organization', lead.contact_org)}${row('Event Type', lead.event_type)}${row('Category', lead.event_category)}
        ${row('Genre', lead.music_genre)}${row('Desired Date', lead.desired_date)}${row('Alt Date', lead.desired_date_alt)}
        ${row('Attendance', lead.projected_attendance)}${row('Budget', lead.budget)}${row('Age restriction', lead.age_restriction)}
        ${row('Alcohol plan', lead.alcohol_plan)}${row('Notes', lead.notes)}
      </div>`;
      return;
    }
    if (this.activeTab === 'notes') {
      wrap.innerHTML = '<div class="empty-state padded">Internal notes are shown inline in the Conversation tab (marked "Internal Note").</div>';
      return;
    }
    if (this.activeTab === 'tasks') {
      wrap.innerHTML = '<div class="empty-state padded">Tasks linked to this inquiry appear here (see the Tasks app).</div>';
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
        ? `<div class="padded">${rows.map((a) => `<div class="ib-detail-row"><span class="k">${esc(a.filename)}</span><span class="v">${esc(new Date(a.created_at.replace(' ', 'T') + 'Z').toLocaleDateString())}</span></div>`).join('')}</div>`
        : '<div class="empty-state padded">No files yet.</div>';
    } catch (err) {
      wrap.innerHTML = `<div class="empty-state padded">${esc(err.message)}</div>`;
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

  async changeStatus(status) {
    const { lead } = this.data;
    let reason = null;
    if (REASON_REQUIRED_STATUSES.includes(status)) {
      reason = window.prompt(`A reason is required to mark this inquiry as "${statusLabel(status)}":`);
      if (reason === null || reason.trim() === '') return;
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

  openReassign() {
    const { lead } = this.data;
    const userId = window.prompt('Reassign to user id:');
    if (!userId) return;
    const reason = window.prompt('Reason for reassignment (required):');
    if (!reason) return;
    api(`/leads/${lead.id}/reassign`, { method: 'POST', body: JSON.stringify({ user_id: Number(userId), reason }) })
      .then(() => { publish('toast.show', { message: 'Reassigned.' }); this.notifyChanged(); })
      .catch((err) => publish('toast.show', { message: err.message, tone: 'error' }));
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
