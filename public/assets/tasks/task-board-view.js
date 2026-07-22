// <pb-task-board-view> — the "Board" tab: a Kanban of every task in the
// document (subtasks included, flattened — a parent task's own row still
// shows up as a card, with a breadcrumb to its parent for context) grouped
// into Not Started / In Progress / Done columns. Drag a card to a new
// column to change its status — same HTML5 drag-and-drop approach
// nav-manager.js uses for reordering nav items (dragstart stores the
// dragged id, dragover previews the drop target, drop commits it).
import { esc, api, publish, PanicElement, $, $$ } from '../core.js';
import { TASK_STATUSES, statusLabel, priorityFlag, avatar, fmtDate, isOverdue } from './task-shared.js';

class TaskBoardView extends PanicElement {
  connect() {
    if (this._data) this.render();
  }

  set data(value) { this._data = value; if (this.abort) this.render(); }
  get data() { return this._data; }

  render() {
    const { documentId, tasks, users } = this.data;
    this.documentId = documentId;
    this.users = users;
    const byId = new Map(tasks.map((t) => [t.id, t]));

    this.innerHTML = `<div class="tk-board">
      ${TASK_STATUSES.map((status) => {
        const cards = tasks.filter((t) => t.status === status);
        return `<div class="tk-board-col" data-col="${status}">
          <div class="tk-board-col-head"><span>${esc(statusLabel(status))}</span><span class="pill">${cards.length}</span></div>
          <div class="tk-board-col-body" data-drop="${status}">
            ${cards.map((task) => this.renderCard(task, byId)).join('') || '<div class="tk-board-empty muted small">No tasks</div>'}
          </div>
        </div>`;
      }).join('')}
    </div>`;

    this.bindBoard();
  }

  renderCard(task, byId) {
    const assignee = this.users.find((u) => String(u.id) === String(task.assignee_user_id));
    const parent = task.parent_task_id ? byId.get(task.parent_task_id) : null;
    const overdue = isOverdue(task);
    return `<div class="tk-card" draggable="true" data-card="${task.id}">
      ${parent ? `<div class="tk-card-parent muted small"><i class="fa-solid fa-folder" aria-hidden="true"></i> ${esc(parent.title)}</div>` : ''}
      <a href="#" class="tk-card-title" data-open="${task.id}">${esc(task.title)}</a>
      <div class="tk-card-foot">
        ${priorityFlag(task.priority)}
        ${task.due_date ? `<span class="tk-card-due${overdue ? ' tk-date-overdue' : ''}"><i class="fa-regular fa-calendar" aria-hidden="true"></i> ${esc(fmtDate(task.due_date))}</span>` : ''}
        <span class="tk-card-avatar">${avatar(assignee)}</span>
      </div>
    </div>`;
  }

  bindBoard() {
    $$('[data-open]', this).forEach((a) => a.addEventListener('click', (e) => {
      e.preventDefault();
      this.dispatchEvent(new CustomEvent('task-open', { bubbles: true, detail: { taskId: Number(a.dataset.open) } }));
    }));

    let draggedId = null;
    $$('.tk-card', this).forEach((card) => {
      card.addEventListener('dragstart', (e) => {
        draggedId = Number(card.dataset.card);
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(() => card.classList.add('dragging'), 0);
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        $$('.tk-board-col-body', this).forEach((col) => col.classList.remove('drag-over'));
        draggedId = null;
      });
    });
    $$('[data-drop]', this).forEach((col) => {
      col.addEventListener('dragover', (e) => {
        if (draggedId == null) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.classList.add('drag-over');
      });
      col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
      col.addEventListener('drop', (e) => {
        e.preventDefault();
        col.classList.remove('drag-over');
        if (draggedId == null) return;
        const newStatus = col.dataset.drop;
        const task = this.data.tasks.find((t) => t.id === draggedId);
        if (task && task.status !== newStatus) this.patchTask(draggedId, { status: newStatus });
        draggedId = null;
      });
    });
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
customElements.define('pb-task-board-view', TaskBoardView);
