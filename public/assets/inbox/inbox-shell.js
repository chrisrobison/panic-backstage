// <pb-inbox-app> — Booking Inbox (incoming-ui.png): the top-level shell.
// Three columns: pb-inbox-list (queue) + pb-inbox-workspace (conversation/
// tabs/action bar) + pb-inbox-detail-panel (contact/event/AI info). Same
// "shell owns data + API calls, children render + bubble events" shape as
// tasks-shell.js. Mounted by app.js's router for each of the five saved-
// view routes seeded in nav_items (migration 077_add_booking_inbox_tasks_link_and_nav.sql).
import { esc, api, getAppCapabilities, openModal, publish, subscribe, PanicElement, $, $$ } from '../core.js';
import './inbox-list.js';
import './inbox-workspace.js';
import './inbox-detail-panel.js';
import { openOnboardDialog } from './inbox-onboard-dialog.js';

const POLL_INTERVAL_MS = 8000;

class InboxApp extends PanicElement {
  connect() {
    this._app = document.getElementById('app');
    this._app?.classList.add('workspace-outbox');
    publish('page.context', { title: 'Booking Inbox', blurb: 'Claim, respond to, and onboard inbound event inquiries.' });

    this.view = this.view || 'all';
    this.q = '';
    this.leads = [];
    this.selectedLeadId = null;
    this.selectedLead = null;
    this.selectedContext = {};
    this.classification = null;
    this.canReviewQuarantine = !!getAppCapabilities().manage_booking_inbox;
    this._since = new Date().toISOString().slice(0, 19).replace('T', ' ');

    this.renderShell();
    this.bootstrap();
    this._pollTimer = setInterval(() => this.pollChanges(), POLL_INTERVAL_MS);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this._app?.classList.remove('workspace-outbox');
    clearInterval(this._pollTimer);
  }

  async bootstrap() {
    await this.loadList();
    // On a narrow (mobile) viewport the spec wants the queue as the first
    // screen — auto-opening the first lead would immediately navigate away
    // from it before the visitor ever sees the list. Desktop/tablet keeps
    // the existing "select the first one so the workspace isn't empty"
    // convenience.
    const isMobileWidth = window.matchMedia('(max-width: 860px)').matches;
    if (this.leads.length && !this.selectedLeadId && !isMobileWidth) {
      await this.openLead(this.leads[0].id);
    } else {
      this.renderWorkspace();
    }
  }

  async loadList() {
    try {
      const qs = new URLSearchParams({ view: this.view });
      if (this.q) qs.set('q', this.q);
      const res = await api(`/inbox/list?${qs.toString()}`);
      this.leads = res.leads || [];
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      this.leads = [];
    }
    this.renderList();
  }

  async pollChanges() {
    try {
      const res = await api(`/inbox/changes?since=${encodeURIComponent(this._since)}`);
      this._since = res.server_time || this._since;
      if ((res.leads || []).length || (res.messages || []).length) {
        await this.loadList();
        if (this.selectedLeadId && (res.leads || []).some((l) => l.id === this.selectedLeadId)) {
          await this.refreshSelectedLead();
        }
      }
    } catch { /* best-effort — polling failures are silent */ }
  }

  renderShell() {
    this.innerHTML = `
      <div class="ib-body" data-body>
        <aside class="ib-list" data-list-mount></aside>
        <main class="ib-main" data-main-mount>
          <button type="button" class="ib-back-to-list" data-back><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to inquiries</button>
          <div data-workspace-mount></div>
        </main>
        <aside class="ib-detail" data-detail-mount></aside>
      </div>`;

    $('[data-back]', this)?.addEventListener('click', () => {
      $('[data-body]', this)?.classList.add('ib-body-list-active');
    });

    this.addEventListener('inbox-search', (e) => { this.q = e.detail.q; this.loadList(); }, { signal: this.abort.signal });
    this.addEventListener('inbox-view-change', (e) => { this.view = e.detail.view; this.loadList(); }, { signal: this.abort.signal });
    this.addEventListener('inbox-open-lead', (e) => this.openLead(e.detail.leadId), { signal: this.abort.signal });
    this.addEventListener('inbox-lead-changed', () => { this.loadList(); this.refreshSelectedLead(); }, { signal: this.abort.signal });
    this.addEventListener('inbox-open-onboard', (e) => openOnboardDialog(e.detail.lead, () => { this.loadList(); this.refreshSelectedLead(); }), { signal: this.abort.signal });
    this.addEventListener('inbox-open-quarantine', () => this.openQuarantine(), { signal: this.abort.signal });

    // Mobile: list is the first screen until a row is opened.
    $('[data-body]', this)?.classList.add('ib-body-list-active');
  }

  renderList() {
    const mount = $('[data-list-mount]', this);
    if (!mount) return;
    let el = $('pb-inbox-list', mount);
    if (!el) {
      el = document.createElement('pb-inbox-list');
      mount.replaceChildren(el);
    }
    el.data = {
      leads: this.leads,
      view: this.view,
      selectedLeadId: this.selectedLeadId,
      q: this.q,
      canReviewQuarantine: this.canReviewQuarantine,
    };
  }

  async openLead(leadId) {
    this.selectedLeadId = leadId;
    $('[data-body]', this)?.classList.remove('ib-body-list-active');
    this.renderList();
    await this.refreshSelectedLead();
  }

  async refreshSelectedLead() {
    if (!this.selectedLeadId) { this.selectedLead = null; this.renderWorkspace(); return; }
    try {
      const res = await api(`/leads/${this.selectedLeadId}/detail`);
      this.selectedLead = res.lead || null;
      this.selectedContext = res || {};
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      this.selectedLead = null;
      this.selectedContext = {};
    }
    try {
      const cls = await api(`/leads/${this.selectedLeadId}/classification`);
      this.classification = cls.classification || null;
    } catch { this.classification = null; }

    this.renderWorkspace();
    this.renderDetail();
  }

  renderWorkspace() {
    const mount = $('[data-workspace-mount]', this);
    if (!mount) return;
    let el = $('pb-inbox-workspace', mount);
    if (!el) {
      el = document.createElement('pb-inbox-workspace');
      mount.replaceChildren(el);
    }
    el.data = {
      lead: this.selectedLead,
      users: this.selectedContext.users || [],
      replyTemplates: this.selectedContext.reply_templates || [],
      pendingApproval: this.selectedContext.pending_approval || null,
      capabilities: this.selectedContext.capabilities || {},
    };
  }

  renderDetail() {
    const mount = $('[data-detail-mount]', this);
    if (!mount) return;
    let el = $('pb-inbox-detail-panel', mount);
    if (!el) {
      el = document.createElement('pb-inbox-detail-panel');
      mount.replaceChildren(el);
    }
    const lead = this.selectedLead;
    const routing = this.selectedContext.routing || null;
    const routingExplanation = routing?.reason || null;
    el.data = {
      lead,
      classification: this.classification,
      routingExplanation,
      duplicates: this.selectedContext.duplicates || [],
      canManage: !!this.selectedContext.capabilities?.manage,
    };
  }

  async openQuarantine() {
    let items;
    try {
      const res = await api('/inbox/quarantine');
      items = res.items || [];
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      return;
    }

    const { dialog, close } = openModal({
      title: 'Quarantined Mail',
      wide: true,
      bodyHtml: items.length ? `<div class="table-wrap"><table>
        <thead><tr><th>Received</th><th>Sender</th><th>Subject</th><th>Reason</th><th></th></tr></thead>
        <tbody>${items.map((item) => `<tr data-quarantine-id="${item.id}">
          <td>${esc(new Date((item.received_at || item.created_at).replace(' ', 'T') + 'Z').toLocaleDateString())}</td>
          <td>${esc(item.from_name || item.from_email || 'Unknown')}</td>
          <td>${esc(item.subject || '(no subject)')}</td>
          <td>${esc(item.disposition_reason || 'Automated intake filter')}</td>
          <td><button type="button" class="small" data-promote="${item.id}">Promote</button></td>
        </tr>`).join('')}</tbody>
      </table></div>` : '<div class="empty-state padded">No quarantined mail.</div>',
    });

    $$('[data-promote]', dialog).forEach((button) => button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const res = await api(`/inbox/quarantine/${button.dataset.promote}`, {
          method: 'POST',
          body: JSON.stringify({ action: 'promote' }),
        });
        close();
        await this.loadList();
        await this.openLead(res.lead_id);
        publish('toast.show', { message: 'Message promoted to an inquiry.' });
      } catch (err) {
        button.disabled = false;
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));
  }
}
customElements.define('pb-inbox-app', InboxApp);
