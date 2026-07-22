// <pb-task-list-view> — the "Tasks" tab (tasks-ui.png's main hierarchical
// table): WBS-numbered rows with inline status/priority/assignee/due
// editing, expand/collapse for subtasks, and inline "add task"/"add
// subtask" rows. Talks to the API directly (PATCH on every inline edit,
// POST on add) and announces changes upward via bubbling `task-changed` —
// the shell (tasks-shell.js) owns reloading; this view only re-renders
// itself locally when toggling collapse state doesn't need a reload
// (delegated to the shell via `task-collapse-toggle` so collapse state
// survives a tab switch).
import { esc, api, publish, PanicElement, $, $$ } from '../core.js';
import { TASK_STATUSES, TASK_PRIORITIES, priorityLabel, isOverdue, buildHierarchy, flattenVisible } from './task-shared.js';

class TaskListView extends PanicElement {
  connect() {
    this.addingUnder = null; // null | 'root' | <parentTaskId>
    if (this._data) this.render();
  }

  set data(value) { this._data = value; if (this.abort) this.render(); }
  get data() { return this._data; }

  render() {
    const { documentId, tasks, users, collapsed } = this.data;
    this.documentId = documentId;
    this.users = users;

    if (!tasks.length && this.addingUnder === null) {
      this.innerHTML = `<div class="tk-list-empty">
        <p class="muted">No tasks yet.</p>
        <button type="button" class="button" data-add-root>+ Add Task</button>
      </div>`;
      $('[data-add-root]', this)?.addEventListener('click', () => { this.addingUnder = 'root'; this.render(); });
      return;
    }

    const tree = buildHierarchy(tasks);
    const rows = flattenVisible(tree, collapsed);

    const rowHtml = rows.map((node) => this.renderRow(node, collapsed)).join('');
    const addRootRow = this.addingUnder === 'root' ? this.renderAddRow(null, 0) : '';

    this.innerHTML = `
      <div class="table-scroll tk-list-scroll">
        <table class="data-table tk-task-table">
          <thead><tr><th class="tk-col-wbs">#</th><th>Task Name</th><th>Assignee</th><th>Status</th><th>Due</th><th>Priority</th><th></th></tr></thead>
          <tbody>${rowHtml}${addRootRow}</tbody>
        </table>
      </div>
      ${this.addingUnder === 'root' ? '' : '<button type="button" class="tk-add-task-btn" data-add-root><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Task</button>'}`;

    this.bindRows();
  }

  renderRow(node, collapsed) {
    const { task, wbs, depth, children } = node;
    const hasChildren = children.length > 0;
    const isCollapsed = collapsed.has(task.id);
    const overdue = isOverdue(task);
    const toggleIcon = hasChildren
      ? `<button type="button" class="tk-row-toggle" data-toggle="${task.id}"><i class="fa-solid fa-chevron-${isCollapsed ? 'right' : 'down'}" aria-hidden="true"></i></button>`
      : '<span class="tk-row-toggle-spacer"></span>';
    const rowIcon = hasChildren
      ? '<i class="fa-solid fa-folder tk-row-icon tk-row-icon-folder" aria-hidden="true"></i>'
      : `<button type="button" class="tk-row-status-icon" data-quick-done="${task.id}" title="${task.status === 'done' ? 'Mark not started' : 'Mark done'}"><i class="fa-${task.status === 'done' ? 'solid fa-circle-check tk-icon-done' : 'regular fa-circle'}" aria-hidden="true"></i></button>`;
    const childCount = hasChildren ? `<span class="pill">${children.length}</span>` : '';
    const nameStyle = `style="padding-left:${depth * 22}px"`;

    const assigneeCell = `<select class="tk-inline-select" data-field="assignee_user_id" data-task="${task.id}">
        <option value="">Unassigned</option>
        ${this.users.map((u) => `<option value="${u.id}" ${String(u.id) === String(task.assignee_user_id || '') ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}
      </select>`;
    const statusCell = `<select class="tk-inline-select" data-field="status" data-task="${task.id}">
        ${TASK_STATUSES.map((s) => `<option value="${s}" ${s === task.status ? 'selected' : ''}>${esc(s === 'not_started' ? 'Not Started' : s === 'in_progress' ? 'In Progress' : 'Done')}</option>`).join('')}
      </select>`;
    const dueCell = `<input type="date" class="tk-inline-date${overdue ? ' tk-date-overdue' : ''}" data-field="due_date" data-task="${task.id}" value="${esc(task.due_date || '')}">`;
    const priorityCell = `<select class="tk-inline-select tk-priority-select tk-priority-${esc(task.priority)}" data-field="priority" data-task="${task.id}">
        ${TASK_PRIORITIES.map((p) => `<option value="${p}" ${p === task.priority ? 'selected' : ''}>${esc(priorityLabel(p))}</option>`).join('')}
      </select>`;

    const row = `<tr data-row="${task.id}">
      <td class="tk-col-wbs muted">${esc(wbs)}</td>
      <td class="tk-col-name">
        <div class="tk-row-name" ${nameStyle}>
          ${toggleIcon}${rowIcon}
          <a href="#" class="tk-row-title" data-open="${task.id}">${esc(task.title)}</a>
          ${childCount}
        </div>
      </td>
      <td>${assigneeCell}</td>
      <td>${statusCell}</td>
      <td>${dueCell}</td>
      <td>${priorityCell}</td>
      <td class="tk-col-actions"><button type="button" class="tk-row-add-child" data-add-child="${task.id}" title="Add subtask" aria-label="Add subtask"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></td>
    </tr>`;

    const addChildRow = this.addingUnder === task.id ? this.renderAddRow(task.id, depth + 1) : '';
    return row + addChildRow;
  }

  renderAddRow(parentId, depth) {
    return `<tr class="tk-add-row" data-add-row="${parentId ?? 'root'}">
      <td></td>
      <td colspan="6">
        <div class="tk-row-name" style="padding-left:${depth * 22}px">
          <i class="fa-regular fa-circle tk-row-icon" aria-hidden="true"></i>
          <input type="text" class="tk-add-input" data-add-input placeholder="Task name…" autofocus>
        </div>
      </td>
    </tr>`;
  }

  bindRows() {
    $$('[data-toggle]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('task-collapse-toggle', { bubbles: true, detail: { taskId: Number(btn.dataset.toggle) } }));
    }));
    $$('[data-open]', this).forEach((a) => a.addEventListener('click', (e) => {
      e.preventDefault();
      this.dispatchEvent(new CustomEvent('task-open', { bubbles: true, detail: { taskId: Number(a.dataset.open) } }));
    }));
    $$('[data-quick-done]', this).forEach((btn) => btn.addEventListener('click', () => {
      const id = Number(btn.dataset.quickDone);
      const task = this.data.tasks.find((t) => t.id === id);
      this.patchTask(id, { status: task.status === 'done' ? 'not_started' : 'done' });
    }));
    $$('[data-field]', this).forEach((el) => el.addEventListener('change', () => {
      this.patchTask(Number(el.dataset.task), { [el.dataset.field]: el.value });
    }));
    $$('[data-add-root]', this).forEach((btn) => btn.addEventListener('click', () => { this.addingUnder = 'root'; this.render(); }));
    $$('[data-add-child]', this).forEach((btn) => btn.addEventListener('click', () => { this.addingUnder = Number(btn.dataset.addChild); this.render(); }));

    const addInput = $('[data-add-input]', this);
    if (addInput) {
      addInput.focus();
      const rowEl = addInput.closest('[data-add-row]');
      const parentRaw = rowEl?.dataset.addRow;
      const parentId = parentRaw === 'root' ? null : Number(parentRaw);
      addInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); this.submitAdd(parentId, addInput.value); }
        if (e.key === 'Escape') { this.addingUnder = null; this.render(); }
      });
      addInput.addEventListener('blur', () => { this.addingUnder = null; this.render(); });
    }
  }

  async submitAdd(parentId, title) {
    const trimmed = (title || '').trim();
    if (!trimmed) { this.addingUnder = null; this.render(); return; }
    try {
      await api(`/task-documents/${this.documentId}/tasks`, {
        method: 'POST',
        body: JSON.stringify({ title: trimmed, parent_task_id: parentId || '' }),
      });
      this.addingUnder = null;
      this.dispatchEvent(new CustomEvent('task-changed', { bubbles: true }));
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async patchTask(taskId, body) {
    try {
      await api(`/task-documents/${this.documentId}/tasks/${taskId}`, { method: 'PATCH', body: JSON.stringify(body) });
      this.dispatchEvent(new CustomEvent('task-changed', { bubbles: true }));
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}
customElements.define('pb-task-list-view', TaskListView);
