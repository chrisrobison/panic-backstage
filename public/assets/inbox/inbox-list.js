// <pb-inbox-list> — the scrollable, filterable inquiry queue (middle
// column). Receives `.data = { leads, view, selectedLeadId, q }` from the
// shell (inbox-shell.js) and only ever reads it — search/view changes and
// row clicks are all bubbled up as CustomEvents, same "child reacts to
// parent state, parent owns the API calls" shape as task-list-view.js.
import { esc, $, $$, PanicElement } from '../core.js';
import { initials, avatarColor, relativeTime, statusLabel, categoryClass, SAVED_VIEWS, viewLabel, slaCountdown } from './inbox-shared.js';

class InboxList extends PanicElement {
  set data(value) {
    this._data = value || { leads: [], view: 'all', selectedLeadId: null, q: '' };
    this.render();
  }

  get data() {
    return this._data || { leads: [], view: 'all', selectedLeadId: null, q: '' };
  }

  connect() {
    this.render();
  }

  render() {
    const { leads, view, selectedLeadId, q } = this.data;

    this.innerHTML = `
      <div class="ib-list-head">
        <div class="ib-list-head-row">
          <h2>${leads.length} Inquir${leads.length === 1 ? 'y' : 'ies'}</h2>
        </div>
        <div class="ib-list-search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" placeholder="Search inquiries..." value="${esc(q || '')}" data-search aria-label="Search inquiries">
        </div>
        <select class="ib-list-view-select" data-view-select aria-label="Saved view">
          ${SAVED_VIEWS.map(([id, label]) => `<option value="${id}" ${id === view ? 'selected' : ''}>${esc(label)}</option>`).join('')}
        </select>
      </div>
      <div class="ib-list-scroll" data-scroll>
        ${leads.length ? leads.map((lead) => this.rowHtml(lead, selectedLeadId)).join('') : `
          <div class="empty-state padded">No inquiries in "${esc(viewLabel(view))}".</div>
        `}
      </div>`;

    $('[data-search]', this)?.addEventListener('input', (e) => {
      clearTimeout(this._searchDebounce);
      this._searchDebounce = setTimeout(() => {
        this.dispatchEvent(new CustomEvent('inbox-search', { bubbles: true, detail: { q: e.target.value } }));
      }, 250);
    });
    $('[data-view-select]', this)?.addEventListener('change', (e) => {
      this.dispatchEvent(new CustomEvent('inbox-view-change', { bubbles: true, detail: { view: e.target.value } }));
    });
    $$('.ib-list-item', this).forEach((row) => {
      row.addEventListener('click', () => {
        this.dispatchEvent(new CustomEvent('inbox-open-lead', { bubbles: true, detail: { leadId: Number(row.dataset.leadId) } }));
      });
    });
  }

  rowHtml(lead, selectedLeadId) {
    const name = lead.contact_org || lead.contact_name || 'Unknown';
    const active = Number(lead.id) === Number(selectedLeadId) ? ' active' : '';
    const category = lead.event_category || lead.event_type;
    const sla = lead.status === 'assigned' ? slaCountdown(lead.sla_claim_due_at, 'Claim expires')
      : lead.status === 'claimed' ? slaCountdown(lead.claim_expires_at, 'Response due') : null;

    return `
      <div class="ib-list-item${active}" data-lead-id="${esc(String(lead.id))}">
        <span class="ib-avatar" style="background:${avatarColor(name)}">${esc(initials(name))}</span>
        <div class="ib-list-item-body">
          <div class="ib-list-item-top">
            <span class="ib-list-item-name">${esc(name)}</span>
            <span class="ib-list-item-time">${esc(relativeTime(lead.created_at))}</span>
          </div>
          <div class="ib-list-item-meta">${esc(lead.event_name || statusLabel(lead.status))}${lead.projected_attendance ? ` • ${esc(String(lead.projected_attendance))} guests` : ''}</div>
          <div class="ib-list-item-row2">
            ${category ? `<span class="ib-cat-badge ${categoryClass(category)}">${esc(category)}</span>` : ''}
            ${sla && sla.overdue ? '<span class="ib-sla-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> overdue</span>' : ''}
            <span class="ib-list-item-icons">
              <i class="fa-regular fa-envelope" title="Email" aria-hidden="true"></i>
              <i class="fa-regular fa-comment" title="Has notes" aria-hidden="true"></i>
            </span>
          </div>
        </div>
      </div>`;
  }
}
customElements.define('pb-inbox-list', InboxList);
