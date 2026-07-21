// <pb-process-palette> — the left-hand node palette. Click-to-add is the
// primary (and fully keyboard/screen-reader accessible) interaction; nodes
// are also HTML5-draggable onto the canvas for anyone who prefers that.
// Adding a node is "publish intent" — this component never touches a graph
// document itself, it dispatches an `add-node` event and the designer
// (which owns the store + knows where the visible canvas center is) does
// the actual store.addNode() call.
import { $, $$, esc } from '../core.js';
import { categorized } from './node-registry.js';

export class ProcessPaletteElement extends HTMLElement {
  // Property setters (readOnly, below) can run before connectedCallback —
  // the designer builds this element and assigns its properties before
  // inserting it into the DOM (see process-designer.js buildShell()), same
  // as any other component mounted via `Object.assign(el, props)` in this
  // app. Default state has to exist from construction, not just from
  // connectedCallback, or a pre-connection render() call crashes on
  // `this.query` being undefined.
  query = '';

  connectedCallback() {
    this.render();
  }

  set readOnly(value) { this._readOnly = !!value; this.render(); }

  render() {
    if (this._readOnly) { this.innerHTML = '<p class="muted small padded">This version is published — create a new draft to add nodes.</p>'; return; }
    const q = this.query.trim().toLowerCase();
    const groups = categorized().map((cat) => {
      const types = cat.types.filter((t) => !q || t.label.toLowerCase().includes(q) || t.id.includes(q));
      return { ...cat, types };
    }).filter((cat) => cat.types.length);

    this.innerHTML = `
      <label class="proc-palette-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" placeholder="Search node types…" value="${esc(this.query)}" aria-label="Search node types"></label>
      <div class="proc-palette-groups">
        ${groups.map((cat) => `
          <details class="proc-palette-group" open>
            <summary>${esc(cat.label)}</summary>
            <div class="proc-palette-items">
              ${cat.types.map((t) => `
                <button type="button" class="proc-palette-item" draggable="true" data-node-type="${esc(t.id)}" data-tone="${esc(t.tone)}" title="${esc(t.description || '')}">
                  <i class="${esc(t.icon)}" aria-hidden="true"></i><span>${esc(t.label)}</span>
                </button>`).join('')}
            </div>
          </details>`).join('') || '<p class="muted small padded">No node types match.</p>'}
      </div>`;

    $('input[type="search"]', this)?.addEventListener('input', (e) => { this.query = e.target.value; this.render(); this.querySelector('input')?.focus(); });
    $$('[data-node-type]', this).forEach((btn) => {
      btn.addEventListener('click', () => this.dispatchEvent(new CustomEvent('add-node', { bubbles: true, detail: { type: btn.dataset.nodeType } })));
      btn.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/proc-node-type', btn.dataset.nodeType));
    });
  }
}
customElements.define('pb-process-palette', ProcessPaletteElement);
