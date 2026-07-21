// <pb-process-step-form> — renders, and lets someone act on, "the current
// actionable step" of a process instance. This is the "form view" half of
// the process-graph engine's founding idea: the same graph document (node
// type, config.instructions, config.formFields, config.outcomes/branches)
// that draws the visual canvas also drives this plain-form rendering, with
// no separate, hand-maintained definition of what the step asks for.
//
// One component, three call sites today: the Live Cases expanded detail,
// the Automation > Tasks inbox, and an embedded card on the Event workspace
// page (see event-workspace.js) — proving the graph really is reusable
// wherever a real record needs to show/act on its current automation step,
// not just inside the Automation section.
//
// Like every other component in this feature, it never calls the API
// itself — it only publishes intent as bubbling CustomEvents and leaves the
// host (whichever page embeds it) to make the actual request and re-supply
// `.detail` afterward:
//   `task-action` { instanceId, taskId, outcome, note, formValues }
//   `wait-action` { instanceId, waitId, note }
import { $, $$, esc } from '../core.js';

export class ProcessStepFormElement extends HTMLElement {
  /** { instance, tasks, waits, graph } — the exact shape
   *  GET /api/processes/{id}/instances/{iid} returns. */
  set detail(value) { this._detail = value; this.render(); }
  get detail() { return this._detail; }

  render() {
    const d = this._detail;
    if (!d?.instance) { this.innerHTML = ''; return; }
    const openTask = (d.tasks || []).find((t) => t.status === 'open');
    const openWait = !openTask && (d.waits || []).find((w) => w.status === 'waiting');
    const nodeId = openTask?.node_id || openWait?.node_id || d.instance.current_node_id;
    const node = (d.graph?.nodes || []).find((n) => n.id === nodeId);

    if (openTask) { this.renderTask(d.instance.id, openTask, node); return; }
    if (openWait) { this.renderWait(d.instance.id, openWait, node); return; }
    this.renderStatus(d.instance);
  }

  renderTask(instanceId, task, node) {
    const cfg = node?.config || {};
    const outcomes = cfg.outcomes?.length ? cfg.outcomes : (cfg.branches?.length ? cfg.branches : [{ id: 'complete', label: 'Complete' }]);
    const fields = cfg.formFields || [];
    this.innerHTML = `
      <form class="proc-step-form" data-step-form>
        <h4>${esc(task.title)}</h4>
        ${cfg.instructions ? `<p class="muted small">${esc(cfg.instructions)}</p>` : ''}
        ${task.assignee_role || task.due_at ? `<p class="muted small">${task.assignee_role ? `Assigned: ${esc(task.assignee_role)}` : ''}${task.assignee_role && task.due_at ? ' · ' : ''}${task.due_at ? `Due ${esc(task.due_at)}` : ''}</p>` : ''}
        ${fields.length ? `<div class="grid-form">${fields.map((f) => this.renderField(f)).join('')}</div>` : ''}
        <label class="wide">Note (optional)<textarea name="__note" rows="2"></textarea></label>
        <div class="proc-instance-actions">
          ${outcomes.map((o) => `<button type="submit" class="small" data-outcome="${esc(o.id)}">${esc(o.label || o.id)}</button>`).join('')}
        </div>
      </form>`;
    $('[data-step-form]', this).addEventListener('submit', (e) => this.onSubmitTask(e, instanceId, task));
  }

  renderField(f) {
    const name = `field__${f.id}`;
    const req = f.required ? 'required' : '';
    const label = `${esc(f.label || f.id)}${f.required ? ' *' : ''}`;
    if (f.type === 'textarea') return `<label class="wide">${label}<textarea name="${esc(name)}" ${req}></textarea></label>`;
    if (f.type === 'checkbox') return `<label class="wide toggle-row"><input type="checkbox" name="${esc(name)}"> ${label}</label>`;
    if (f.type === 'select') {
      const opts = String(f.options || '').split(',').map((o) => o.trim()).filter(Boolean);
      return `<label>${label}<select name="${esc(name)}" ${req}><option value="">—</option>${opts.map((o) => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}</select></label>`;
    }
    const type = ['number', 'date'].includes(f.type) ? f.type : 'text';
    return `<label>${label}<input type="${type}" name="${esc(name)}" ${req}></label>`;
  }

  onSubmitTask(e, instanceId, task) {
    e.preventDefault();
    const form = e.target;
    const outcome = e.submitter?.dataset.outcome;
    if (!outcome) return;
    const formValues = {};
    $$('[name^="field__"]', form).forEach((el) => {
      const id = el.name.replace('field__', '');
      formValues[id] = el.type === 'checkbox' ? el.checked : el.value;
    });
    const note = $('[name="__note"]', form)?.value || '';
    this.dispatchEvent(new CustomEvent('task-action', { bubbles: true, detail: { instanceId, taskId: task.id, outcome, note, formValues } }));
  }

  renderWait(instanceId, wait, node) {
    this.innerHTML = `<div class="proc-step-wait">
      <h4>Waiting: ${esc(wait.awaited_event || node?.name || 'timer')}</h4>
      ${wait.timeout_at ? `<p class="muted small">Times out ${esc(wait.timeout_at)}</p>` : ''}
      <label class="wide">Note (optional)<textarea data-wait-note rows="2"></textarea></label>
      <div class="proc-instance-actions"><button type="button" class="small" data-resume>Resume now</button></div>
    </div>`;
    $('[data-resume]', this).addEventListener('click', () => {
      const note = $('[data-wait-note]', this)?.value || '';
      this.dispatchEvent(new CustomEvent('wait-action', { bubbles: true, detail: { instanceId, waitId: wait.id, note } }));
    });
  }

  renderStatus(instance) {
    const label = { completed: 'completed', canceled: 'canceled', failed: 'failed', paused: 'paused' }[instance.status];
    this.innerHTML = `<p class="muted small">${label ? `This case is ${label}.` : `Currently at "${esc(instance.current_node_id || '—')}" — an automatic step, nothing needs your input right now.`}</p>`;
  }
}
customElements.define('pb-process-step-form', ProcessStepFormElement);
