// <pb-process-node> — renders a single node box on the canvas.
//
// Deliberately "dumb": it has no pointer-event listeners of its own. The
// canvas (process-canvas.js) owns all drag/select/connect interaction via
// delegated listeners keyed off `data-node-id`/`data-port` attributes, which
// keeps one interaction model (and one place to reason about undo batching)
// instead of every node re-implementing drag math. This element's only job
// is to turn `{ node, tone, icon, label, ports, selected, findings, badges }`
// into markup — set its `.data` property and call `.render()`.
import { esc } from '../core.js';

export class ProcessNodeElement extends HTMLElement {
  set data(value) {
    this._data = value;
    this.render();
  }

  get data() { return this._data; }

  render() {
    const d = this._data;
    if (!d) { this.innerHTML = ''; return; }
    const { node, tone, icon, label, ports, selected, findings, badges, disabled } = d;
    const errorCount = findings?.errors?.length || 0;
    const warningCount = findings?.warnings?.length || 0;

    this.dataset.nodeId = node.id;
    this.dataset.tone = tone;
    this.className = 'proc-node' + (selected ? ' selected' : '') + (disabled ? ' disabled' : '') + (errorCount ? ' has-error' : warningCount ? ' has-warning' : '');
    this.style.left = `${node.position.x}px`;
    this.style.top = `${node.position.y}px`;
    this.setAttribute('tabindex', '0');
    this.setAttribute('role', 'group');
    this.setAttribute('aria-label', `${label} node: ${node.name || '(unnamed)'}${errorCount ? `, ${errorCount} error${errorCount > 1 ? 's' : ''}` : ''}${warningCount ? `, ${warningCount} warning${warningCount > 1 ? 's' : ''}` : ''}`);

    const markerTitle = errorCount
      ? [...findings.errors].map((f) => f.message).join(' • ')
      : warningCount ? [...findings.warnings].map((f) => f.message).join(' • ') : '';
    const marker = (errorCount || warningCount)
      ? `<span class="proc-node-marker ${errorCount ? 'error' : 'warning'}" title="${esc(markerTitle)}"><i class="fa-solid ${errorCount ? 'fa-circle-exclamation' : 'fa-triangle-exclamation'}" aria-hidden="true"></i></span>`
      : '';

    const badgeRow = (badges && Object.values(badges).some((n) => n > 0))
      ? `<div class="proc-node-badges">${Object.entries(badges).filter(([, n]) => n > 0).map(([key, n]) => `<button type="button" class="proc-badge proc-badge-${esc(key)}" data-badge-filter="${esc(key)}" data-node-id="${esc(node.id)}">${n} ${esc(key)}</button>`).join('')}</div>`
      : '';

    const outputs = ports?.outputs || [];
    const outputPorts = outputs.map((p, i) => `
      <button type="button" class="proc-port proc-port-out" data-port-role="out" data-node-id="${esc(node.id)}" data-port="${esc(p.id)}"
        style="top:${outputs.length > 1 ? `${(i + 1) * (100 / (outputs.length + 1))}%` : '50%'}"
        title="${esc(p.label || p.id)}" aria-label="Connect from ${esc(p.label || p.id)} output"></button>
      ${outputs.length > 1 ? `<span class="proc-port-label proc-port-label-out" style="top:${(i + 1) * (100 / (outputs.length + 1))}%">${esc(p.label || p.id)}${p.isDefault ? ' <em>(default)</em>' : ''}</span>` : ''}
    `).join('');

    const inputPort = ports?.inputs !== 0
      ? `<button type="button" class="proc-port proc-port-in" data-port-role="in" data-node-id="${esc(node.id)}" data-port="in" title="Connect target" aria-label="Connection target"></button>`
      : '';

    this.innerHTML = `
      ${inputPort}
      <div class="proc-node-body">
        <div class="proc-node-head">
          <i class="${esc(icon)}" aria-hidden="true"></i>
          <span class="proc-node-type">${esc(label)}</span>
          ${marker}
        </div>
        <div class="proc-node-name">${esc(node.name || '(unnamed)')}</div>
        ${badgeRow}
      </div>
      ${outputPorts}
    `;
  }
}
customElements.define('pb-process-node', ProcessNodeElement);
