// <pb-process-history> — published versions, per-version validation
// results, and the process_audit_log timeline (draft saves, publishes,
// restores). The version list lets an operator jump back to view (or
// restore-as-draft) any prior published graph — publish immutability means
// that's always safe to do.
import { $$, esc } from '../core.js';

export class ProcessHistoryElement extends HTMLElement {
  connectedCallback() { this.render(); }

  /** { versions, audit } */
  set data(value) { this._data = value || { versions: [], audit: [] }; this.render(); }

  render() {
    const d = this._data || { versions: [], audit: [] };
    this.innerHTML = `
      <div class="proc-history-grid">
        <section class="panel">
          <div class="section-head padded"><h2>Versions</h2></div>
          <div class="table-scroll"><table class="data-table">
            <thead><tr><th>Version</th><th>Status</th><th>Note</th><th>Published</th><th></th></tr></thead>
            <tbody>${d.versions.map((v) => `
              <tr>
                <td data-label="Version">v${v.version_number}</td>
                <td data-label="Status"><span class="badge status-${v.status === 'published' ? 'confirmed' : 'draft'}">${esc(v.status)}</span></td>
                <td data-label="Note">${esc(v.note || '—')}</td>
                <td data-label="Published">${esc(v.published_at || '—')}</td>
                <td><button type="button" class="small secondary" data-view-version="${v.id}">View</button></td>
              </tr>`).join('') || '<tr><td colspan="5" class="empty-state">No versions yet.</td></tr>'}
            </tbody>
          </table></div>
        </section>
        <section class="panel">
          <div class="section-head padded"><h2>Activity</h2></div>
          <ul class="proc-timeline padded">
            ${d.audit.length ? d.audit.map((a) => `
              <li><span class="timeline-dot"></span><div>
                <strong>${esc(this.actionLabel(a.action))}</strong>${a.version_number ? ` — v${a.version_number}` : ''}
                <span class="muted small"> · ${esc(a.actor_name || 'system')} · ${esc(a.created_at)}</span>
                ${a.note ? `<div class="muted small">${esc(a.note)}</div>` : ''}
              </div></li>`).join('') : '<li class="muted">No activity recorded yet.</li>'}
          </ul>
        </section>
      </div>`;

    $$('[data-view-version]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('view-version', { bubbles: true, detail: { versionId: Number(btn.dataset.viewVersion) } }));
    }));
  }

  actionLabel(action) {
    return {
      definition_created: 'Process created',
      draft_created: 'Draft created',
      draft_saved: 'Draft saved',
      published: 'Published',
    }[action] || action;
  }
}
customElements.define('pb-process-history', ProcessHistoryElement);
