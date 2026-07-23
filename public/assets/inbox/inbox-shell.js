// <pb-inbox-app> — Booking Inbox (incoming-ui.png): the top-level shell.
// Three columns: pb-inbox-list (queue) + pb-inbox-workspace (conversation/
// tabs/action bar) + pb-inbox-detail-panel (contact/event/AI info). Same
// "shell owns data + API calls, children render + bubble events" shape as
// tasks-shell.js. Mounted by app.js's router for each of the five saved-
// view routes seeded in nav_items (migration 077_add_booking_inbox_tasks_link_and_nav.sql).
import { esc, api, publish, subscribe, PanicElement, $ } from '../core.js';
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
    this.classification = null;
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
    el.data = { leads: this.leads, view: this.view, selectedLeadId: this.selectedLeadId, q: this.q };
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
      const res = await api(`/leads/${this.selectedLeadId}`);
      this.selectedLead = res.lead || null;
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
      this.selectedLead = null;
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
    el.data = { lead: this.selectedLead };
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
    const routingExplanation = null; // surfaced from lead_audit_log's most recent "routed" entry in a future pass
    el.data = { lead, classification: this.classification, routingExplanation, duplicates: [] };
  }
}
customElements.define('pb-inbox-app', InboxApp);
