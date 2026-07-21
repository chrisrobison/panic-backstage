// <pb-process-toolbar> — process name/status/version, the Design/Live
// Cases/History tabs, and the edit/view actions (Validate, Test, Save
// Draft, Publish, Undo/Redo, zoom, fit). Purely presentational: every
// button dispatches one bubbling `toolbar-action` CustomEvent and the
// designer (process-designer.js), which alone knows how to talk to the
// store and the API, decides what to do with it.
import { $, $$, esc } from '../core.js';

export class ProcessToolbarElement extends HTMLElement {
  connectedCallback() {
    this.tab = 'design';
    this.render();
  }

  set data(value) {
    this._data = value; // { process, version, dirty, validation, canUndo, canRedo, readOnly }
    this.render();
  }

  setTab(tab) { this.tab = tab; this.render(); }

  render() {
    const d = this._data || {};
    const process = d.process || {};
    const version = d.version || {};
    const errorCount = d.validation?.errors?.length || 0;
    const warningCount = d.validation?.warnings?.length || 0;
    const statusBadge = version.status === 'published'
      ? `<span class="badge status-confirmed"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Published v${version.version_number ?? '—'}</span>`
      : `<span class="badge status-draft"><i class="fa-solid fa-pen" aria-hidden="true"></i> Draft v${version.version_number ?? '—'}</span>`;

    this.innerHTML = `
      <div class="proc-toolbar-row">
        <div class="proc-toolbar-title">
          <a href="#automation-processes" class="proc-toolbar-back" title="Back to Processes"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
          <div>
            <h1 class="proc-toolbar-name">${esc(process.name || 'Untitled Process')}</h1>
            <div class="proc-toolbar-sub">${statusBadge} ${d.dirty ? '<span class="pill pill-muted">Unsaved changes</span>' : ''}</div>
          </div>
        </div>
        <nav class="tabs proc-toolbar-tabs" role="tablist">
          ${['design', 'live', 'history'].map((t) => `<button type="button" role="tab" aria-selected="${this.tab === t}" class="${this.tab === t ? 'active' : ''}" data-tab="${t}">${{ design: 'Design', live: 'Live Cases', history: 'History' }[t]}</button>`).join('')}
        </nav>
        <div class="proc-toolbar-actions">
          ${errorCount || warningCount ? `<button type="button" class="small secondary proc-validation-pill" data-action="show-validation">
            ${errorCount ? `<span class="proc-count error">${errorCount} error${errorCount > 1 ? 's' : ''}</span>` : ''}
            ${warningCount ? `<span class="proc-count warning">${warningCount} warning${warningCount > 1 ? 's' : ''}</span>` : ''}
          </button>` : ''}
          <button type="button" class="small secondary" data-action="validate"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Validate</button>
          <button type="button" class="small secondary" data-action="test"><i class="fa-solid fa-play" aria-hidden="true"></i> Test</button>
          ${version.status === 'published'
            ? `<button type="button" class="small secondary" data-action="new-draft"><i class="fa-solid fa-code-branch" aria-hidden="true"></i> Edit as Draft</button>`
            : `<button type="button" class="small secondary" data-action="save-draft" ${d.dirty ? '' : 'disabled'}><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Draft</button>
               <button type="button" data-action="publish"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> Publish</button>`}
          <div class="proc-toolbar-menu">
            <button type="button" class="small secondary" data-action="menu-toggle" aria-haspopup="true" aria-expanded="false" title="More"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
            <div class="proc-toolbar-menu-list" hidden>
              <button type="button" data-action="export"><i class="fa-solid fa-download" aria-hidden="true"></i> Export graph JSON</button>
              <button type="button" data-action="import"><i class="fa-solid fa-upload" aria-hidden="true"></i> Import graph JSON</button>
              <button type="button" data-action="rename"><i class="fa-solid fa-tag" aria-hidden="true"></i> Rename process</button>
            </div>
          </div>
        </div>
      </div>
      <div class="proc-toolbar-row proc-toolbar-canvas-controls">
        <div class="inline-actions">
          <button type="button" class="small secondary" data-action="undo" ${d.canUndo ? '' : 'disabled'} title="Undo"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
          <button type="button" class="small secondary" data-action="redo" ${d.canRedo ? '' : 'disabled'} title="Redo"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>
        </div>
        <div class="inline-actions">
          <button type="button" class="small secondary" data-action="zoom-out" title="Zoom out"><i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i></button>
          <span class="proc-zoom-readout">${Math.round((d.zoom ?? 1) * 100)}%</span>
          <button type="button" class="small secondary" data-action="zoom-in" title="Zoom in"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i></button>
          <button type="button" class="small secondary" data-action="fit" title="Fit to screen"><i class="fa-solid fa-expand" aria-hidden="true"></i></button>
        </div>
      </div>`;

    $$('[data-tab]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.tab = btn.dataset.tab;
      this.render();
      this._fire('tab', { tab: btn.dataset.tab });
    }));
    $$('[data-action]', this).forEach((btn) => btn.addEventListener('click', () => {
      const action = btn.dataset.action;
      if (action === 'menu-toggle') {
        const list = $('.proc-toolbar-menu-list', this);
        const open = list.hidden;
        list.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        return;
      }
      $('.proc-toolbar-menu-list', this)?.setAttribute('hidden', '');
      this._fire('action', { action });
    }));
  }

  _fire(kind, detail) {
    this.dispatchEvent(new CustomEvent('toolbar-action', { bubbles: true, detail: { kind, ...detail } }));
  }
}
customElements.define('pb-process-toolbar', ProcessToolbarElement);
