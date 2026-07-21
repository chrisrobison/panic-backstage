// <pb-process-tasks-list> — Automation > Tasks. A real cross-process inbox
// (src/Processes/Tasks.php, backed by the process_tasks rows the runtime
// creates for human.* nodes) rather than a placeholder: every open task
// from every process definition. This is the one Phase 4 "operational
// view" pulled forward, because once real task rows exist a cross-process
// list of them is just a join — see the doc comment on Tasks.php.
//
// "Open" loads the task's full instance detail and hands it to the shared
// <pb-process-step-form> in a modal — the exact same rendering Live Cases
// uses (real outcome buttons/form fields from the node's own config, not a
// freeform guess), so completing a task looks and behaves identically
// whether you got here from this cross-process inbox or from a specific
// case's Live Cases row.
import { $, $$, api, esc, openModal, publish, PanicElement } from '../core.js';
import './process-step-form.js';

export class ProcessTasksListElement extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Tasks', blurb: 'Automation > Tasks' });
    this.filters = { status: 'open', assignee: '', q: '' };
    this.setLoading('Loading tasks…');
    await this.load();
  }

  async load() {
    const params = new URLSearchParams();
    if (this.filters.status) params.set('status', this.filters.status);
    if (this.filters.assignee) params.set('assignee', this.filters.assignee);
    if (this.filters.q) params.set('q', this.filters.q);
    const data = await api(`/process-tasks?${params.toString()}`);
    this.tasks = data.tasks || [];
    this.render();
  }

  render() {
    const rows = this.tasks.map((t) => `
      <tr class="${t.overdue ? 'row-overdue' : ''}">
        <td data-label="Task"><strong>${esc(t.title)}</strong>${t.overdue ? ' <span class="badge status-canceled">overdue</span>' : ''}</td>
        <td data-label="Case"><a href="#automation-process-${t.process_definition_id}">${esc(t.instance_name)}</a> <span class="muted small">(${esc(t.process_name)})</span></td>
        <td data-label="Assignee">${esc(t.assignee_name || t.assignee_role || 'Unassigned')}</td>
        <td data-label="Due">${t.due_at ? esc(t.due_at) : '—'}</td>
        <td data-label="Status"><span class="badge status-${t.status === 'completed' ? 'advanced' : t.status === 'canceled' ? 'empty' : 'needs_assets'}">${esc(t.status)}${t.outcome ? ` (${esc(t.outcome)})` : ''}</span></td>
        <td>${t.status === 'open' ? `<button type="button" class="small" data-open-task data-def="${t.process_definition_id}" data-inst="${t.process_instance_id}" data-task="${t.id}">Open</button>` : ''}</td>
      </tr>`).join('');

    this.innerHTML = `
      <div class="page-head"><div><h1>Tasks</h1><p class="subtle">Your inbox of human-work items created by running processes.</p></div></div>
      <div class="panel padded">
        <div class="proc-drawer-filters" style="margin-bottom:14px;">
          <select data-filter="status" aria-label="Filter by status">
            <option value="open" ${this.filters.status === 'open' ? 'selected' : ''}>Open</option>
            <option value="completed" ${this.filters.status === 'completed' ? 'selected' : ''}>Completed</option>
            <option value="canceled" ${this.filters.status === 'canceled' ? 'selected' : ''}>Canceled</option>
            <option value="all" ${this.filters.status === 'all' ? 'selected' : ''}>All</option>
          </select>
          <label class="toggle-row small"><input type="checkbox" data-filter="mine" ${this.filters.assignee === 'me' ? 'checked' : ''}> Assigned to me</label>
          <input type="search" placeholder="Search…" value="${esc(this.filters.q)}" data-filter="q" aria-label="Search tasks">
        </div>
        ${this.tasks.length ? `<div class="table-scroll"><table class="data-table">
          <thead><tr><th>Task</th><th>Case</th><th>Assignee</th><th>Due</th><th>Status</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>` : '<div class="empty-state padded">No tasks match these filters.</div>'}
      </div>`;

    $('[data-filter="status"]', this)?.addEventListener('change', (e) => { this.filters.status = e.target.value; this.load(); });
    $('[data-filter="mine"]', this)?.addEventListener('change', (e) => { this.filters.assignee = e.target.checked ? 'me' : ''; this.load(); });
    $('[data-filter="q"]', this)?.addEventListener('input', (e) => { this.filters.q = e.target.value; this.load(); });
    $$('[data-open-task]', this).forEach((btn) => btn.addEventListener('click', () => this.openTaskModal(btn.dataset.def, btn.dataset.inst)));
  }

  async openTaskModal(processDefId, instanceId) {
    const { dialog, close } = openModal({ title: 'Act on this step', bodyHtml: `<div class="padded" data-body><pb-process-step-form></pb-process-step-form></div>` });
    const body = $('[data-body]', dialog);
    const stepForm = $('pb-process-step-form', dialog);

    try {
      stepForm.detail = await api(`/processes/${processDefId}/instances/${instanceId}`);
    } catch (err) {
      body.innerHTML = `<p class="error-text">${esc(err.message)}</p>`;
      return;
    }

    stepForm.addEventListener('task-action', async (e) => {
      try {
        await api(`/process-tasks/${e.detail.taskId}/complete`, { method: 'POST', body: JSON.stringify({ outcome: e.detail.outcome, note: e.detail.note, formValues: e.detail.formValues }) });
        publish('toast.show', { message: `Marked "${e.detail.outcome}".` });
        close();
        await this.load();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
    stepForm.addEventListener('wait-action', async (e) => {
      try {
        await api(`/processes/${processDefId}/instances/${instanceId}/waits/${e.detail.waitId}/resume`, { method: 'POST', body: JSON.stringify({ note: e.detail.note }) });
        publish('toast.show', { message: 'Wait resumed.' });
        close();
        await this.load();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}
customElements.define('pb-process-tasks-list', ProcessTasksListElement);
