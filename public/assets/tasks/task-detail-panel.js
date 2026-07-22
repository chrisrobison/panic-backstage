// <pb-task-detail-panel> — the slide-over task detail (tasks-ui.png's right
// panel): title, assignee/status/due/priority, description, checklist,
// tags, dependencies, and a merged comments+activity feed. Mounted fresh by
// tasks-shell.js's renderDetail() each time the selected task changes.
//
// Two kinds of edits here, deliberately handled differently:
//   - Fields other views also display (title/status/assignee/due/priority)
//     call the API then dispatch a bubbling `task-changed` so the shell
//     reloads everything (list/board/timeline/calendar + sidebar counts all
//     need to see the change).
//   - Panel-only fields (description/checklist/tags/dependencies) patch the
//     API in the background and re-render just their own section — no
//     reason to remount the whole detail panel (and refetch the activity
//     feed) for every checklist-item toggle.
import { esc, api, publish, PanicElement, $, $$ } from '../core.js';
import { statusBadge, statusLabel, priorityLabel, TASK_STATUSES, TASK_PRIORITIES, buildHierarchy, flattenVisible } from './task-shared.js';

function uid() {
  return 'c' + Math.random().toString(36).slice(2, 9);
}

function fmtWhen(raw) {
  if (!raw) return '';
  const d = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return `${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })} at ${d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
}

class TaskDetailPanel extends PanicElement {
  connect() {
    if (this._data) this.load();
  }

  set data(value) { this._data = value; if (this.abort) this.load(); }
  get data() { return this._data; }

  async load() {
    const { documentId, task } = this.data;
    this.documentId = documentId;
    this.task = task;
    this.tasks = this.data.tasks;
    this.users = this.data.users;
    this.activity = [];
    this.render();
    try {
      const res = await api(`/task-documents/${documentId}/tasks/${task.id}/activity`);
      this.activity = res.items || [];
    } catch {
      this.activity = [];
    }
    this.renderActivity();
  }

  render() {
    const { task, tasks, users } = this;
    const flat = flattenVisible(buildHierarchy(tasks), new Set());
    const wbs = flat.find((n) => n.task.id === task.id)?.wbs || '';
    const parent = task.parent_task_id ? tasks.find((t) => t.id === task.parent_task_id) : null;
    const checklist = task.checklist || [];
    const checkedCount = checklist.filter((c) => c.done).length;
    const depIds = task.depends_on || [];
    const deps = depIds.map((id) => tasks.find((t) => t.id === id)).filter(Boolean);
    const depOptions = tasks.filter((t) => t.id !== task.id && !depIds.includes(t.id));

    this.innerHTML = `
      <div class="tk-detail-head">
        <button type="button" class="tk-detail-close" data-close title="Close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <div class="tk-detail-breadcrumb muted small">${parent ? `${esc(parent.title)} / ` : ''}${esc(wbs)}</div>
        <input type="text" class="tk-detail-title-input" data-field="title" value="${esc(task.title)}" aria-label="Task title">
      </div>
      <div class="tk-detail-body">
        <div class="tk-detail-meta-grid">
          <label>Assignee<select data-field="assignee_user_id"><option value="">Unassigned</option>${users.map((u) => `<option value="${u.id}" ${String(u.id) === String(task.assignee_user_id || '') ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
          <label>Status<select data-field="status">${TASK_STATUSES.map((s) => `<option value="${s}" ${s === task.status ? 'selected' : ''}>${esc(statusLabel(s))}</option>`).join('')}</select></label>
          <label>Due date<input type="date" data-field="due_date" value="${esc(task.due_date || '')}"></label>
          <label>Priority<select data-field="priority">${TASK_PRIORITIES.map((p) => `<option value="${p}" ${p === task.priority ? 'selected' : ''}>${esc(priorityLabel(p))}</option>`).join('')}</select></label>
        </div>

        <div class="tk-detail-section">
          <div class="tk-detail-section-label">Description</div>
          <textarea class="tk-detail-desc" data-field-local="description" placeholder="Add a description…">${esc(task.description || '')}</textarea>
        </div>

        <div class="tk-detail-section">
          <div class="tk-detail-section-label">Checklist <span class="pill">${checkedCount}/${checklist.length}</span></div>
          <ul class="tk-checklist" data-checklist>${this.checklistHtml(checklist)}</ul>
          <form class="tk-checklist-add" data-add-check-form><input type="text" placeholder="+ Add item" data-add-check-input></form>
        </div>

        <div class="tk-detail-section">
          <div class="tk-detail-section-label">Tags</div>
          <div class="tk-tags-row" data-tags>${this.tagsHtml(task.tags || [])}</div>
        </div>

        <div class="tk-detail-section">
          <div class="tk-detail-section-label">Dependencies</div>
          <ul class="tk-dep-list" data-deps>${this.depsHtml(deps)}</ul>
          ${depOptions.length ? `<select class="tk-add-dep-select" data-add-dep><option value="">+ Add dependency…</option>${depOptions.map((t) => `<option value="${t.id}">${esc(t.title)}</option>`).join('')}</select>` : ''}
        </div>

        <div class="tk-detail-section">
          <div class="tk-detail-section-label">Activity</div>
          <form class="tk-comment-form" data-comment-form>
            <textarea placeholder="Write a comment…" data-comment-input></textarea>
            <button type="submit" class="small">Comment</button>
          </form>
          <ul class="tk-activity-list" data-activity-list></ul>
        </div>
      </div>`;

    this.bind();
    this.renderActivity();
  }

  checklistHtml(checklist) {
    return checklist.map((item) => `<li class="tk-checklist-item">
      <label><input type="checkbox" data-check="${esc(item.id)}" ${item.done ? 'checked' : ''}> <span class="${item.done ? 'tk-checklist-done' : ''}">${esc(item.label)}</span></label>
      <button type="button" class="tk-checklist-remove" data-remove-check="${esc(item.id)}" aria-label="Remove item"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </li>`).join('');
  }

  tagsHtml(tags) {
    return tags.map((tag) => `<span class="tk-tag-pill">${esc(tag)}<i class="fa-solid fa-xmark" data-remove-tag="${esc(tag)}"></i></span>`).join('')
      + '<button type="button" class="tk-add-tag-btn" data-add-tag>+ Add</button>';
  }

  depsHtml(deps) {
    if (!deps.length) return '<li class="muted small">No dependencies.</li>';
    return deps.map((d) => `<li class="tk-dep-row"><span>${esc(d.title)}</span> ${statusBadge(d.status)} <button type="button" data-remove-dep="${d.id}" aria-label="Remove dependency"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></li>`).join('');
  }

  renderActivity() {
    const list = $('[data-activity-list]', this);
    if (!list) return;
    if (!this.activity.length) {
      list.innerHTML = '<li class="muted small">No activity yet.</li>';
      return;
    }
    list.innerHTML = this.activity.map((item) => {
      const who = esc(item.user_name || 'Someone');
      const when = esc(fmtWhen(item.created_at));
      if (item.type === 'comment') {
        return `<li class="tk-activity-item"><div class="tk-activity-icon"><i class="fa-regular fa-comment" aria-hidden="true"></i></div><div><div class="tk-activity-message"><strong>${who}</strong> ${esc(item.body)}</div><div class="tk-activity-time">${when}</div></div></li>`;
      }
      let msg = `<strong>${who}</strong> ${esc(item.action)}`;
      if (item.action === 'changed status' && item.details) {
        msg += ` from ${esc(statusLabel(item.details.from))} to ${esc(statusLabel(item.details.to))}`;
      } else if (item.details?.changes?.length) {
        msg += ': ' + item.details.changes.map((c) => `${esc(c.field)} → “${esc(c.to ?? '')}”`).join(', ');
      }
      return `<li class="tk-activity-item"><div class="tk-activity-icon"><i class="fa-solid fa-rotate" aria-hidden="true"></i></div><div><div class="tk-activity-message">${msg}</div><div class="tk-activity-time">${when}</div></div></li>`;
    }).join('');
  }

  bind() {
    $('[data-close]', this)?.addEventListener('click', () => this.dispatchEvent(new CustomEvent('task-close-detail', { bubbles: true })));

    $$('[data-field]', this).forEach((el) => {
      const evt = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'change';
      el.addEventListener(evt, () => this.patchAndSync({ [el.dataset.field]: el.value }));
    });

    $('[data-field-local="description"]', this)?.addEventListener('change', (e) => {
      this.task.description = e.target.value;
      this.patchSilent({ description: e.target.value });
    });

    $('[data-add-check-form]', this)?.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = $('[data-add-check-input]', this);
      const label = input.value.trim();
      if (!label) return;
      input.value = '';
      const checklist = [...(this.task.checklist || []), { id: uid(), label, done: false }];
      this.applyChecklist(checklist);
    });
    this.bindChecklistRows();
    this.bindTagControls();
    this.bindDepControls();

    $('[data-comment-form]', this)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = $('[data-comment-input]', this);
      const body = input.value.trim();
      if (!body) return;
      try {
        await api(`/task-documents/${this.documentId}/tasks/${this.task.id}/comments`, { method: 'POST', body: JSON.stringify({ body }) });
        input.value = '';
        const res = await api(`/task-documents/${this.documentId}/tasks/${this.task.id}/activity`);
        this.activity = res.items || [];
        this.renderActivity();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  bindChecklistRows() {
    $$('[data-check]', this).forEach((cb) => cb.addEventListener('change', () => {
      const checklist = (this.task.checklist || []).map((item) => (item.id === cb.dataset.check ? { ...item, done: cb.checked } : item));
      this.applyChecklist(checklist);
    }));
    $$('[data-remove-check]', this).forEach((btn) => btn.addEventListener('click', () => {
      const checklist = (this.task.checklist || []).filter((item) => item.id !== btn.dataset.removeCheck);
      this.applyChecklist(checklist);
    }));
  }

  bindTagControls() {
    $('[data-add-tag]', this)?.addEventListener('click', () => {
      const name = (window.prompt('Tag name:') || '').trim();
      if (!name) return;
      const tags = Array.from(new Set([...(this.task.tags || []), name]));
      this.applyTags(tags);
    });
    $$('[data-remove-tag]', this).forEach((el) => el.addEventListener('click', () => {
      const tags = (this.task.tags || []).filter((t) => t !== el.dataset.removeTag);
      this.applyTags(tags);
    }));
  }

  bindDepControls() {
    $('[data-add-dep]', this)?.addEventListener('change', (e) => {
      const id = Number(e.target.value);
      if (!id) return;
      const deps = Array.from(new Set([...(this.task.depends_on || []), id]));
      this.applyDeps(deps);
    });
    $$('[data-remove-dep]', this).forEach((btn) => btn.addEventListener('click', () => {
      const deps = (this.task.depends_on || []).filter((id) => id !== Number(btn.dataset.removeDep));
      this.applyDeps(deps);
    }));
  }

  applyChecklist(checklist) {
    this.task.checklist = checklist;
    $('[data-checklist]', this).innerHTML = this.checklistHtml(checklist);
    $('.tk-detail-section-label .pill', this).textContent = `${checklist.filter((c) => c.done).length}/${checklist.length}`;
    this.bindChecklistRows();
    this.patchSilent({ checklist_json: checklist });
  }

  applyTags(tags) {
    this.task.tags = tags;
    $('[data-tags]', this).innerHTML = this.tagsHtml(tags);
    this.bindTagControls();
    this.patchSilent({ tags_json: tags });
  }

  applyDeps(depIds) {
    this.task.depends_on = depIds;
    this.render(); // dependency list options depend on the full remaining set — simplest to re-render this section+the select
    this.patchSilent({ depends_on_json: depIds });
  }

  /** Panel-only field: save in the background, no full reload. */
  async patchSilent(body) {
    try {
      await api(`/task-documents/${this.documentId}/tasks/${this.task.id}`, { method: 'PATCH', body: JSON.stringify(body) });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  /** Field other views also render: save, then let the shell reload everything. */
  async patchAndSync(body) {
    try {
      await api(`/task-documents/${this.documentId}/tasks/${this.task.id}`, { method: 'PATCH', body: JSON.stringify(body) });
      this.dispatchEvent(new CustomEvent('task-changed', { bubbles: true }));
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}
customElements.define('pb-task-detail-panel', TaskDetailPanel);
