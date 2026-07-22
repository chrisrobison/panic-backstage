// <pb-task-timeline-view> — the "Timeline" tab: a lightweight Gantt built
// with percentage-positioned bars over a horizontally scrollable date range
// (no charting library — same "plain CSS, no build step" constraint as the
// rest of this app). Bars span start_date→due_date; tasks with only one of
// the two render a short bar anchored on whichever date is set.
import { esc, addDays, PanicElement, $$ } from '../core.js';
import { buildHierarchy, flattenVisible, parseDateOnly } from './task-shared.js';

class TaskTimelineView extends PanicElement {
  connect() {
    if (this._data) this.render();
  }

  set data(value) { this._data = value; if (this.abort) this.render(); }
  get data() { return this._data; }

  render() {
    const { tasks } = this.data;
    if (!tasks.length) {
      this.innerHTML = '<div class="empty-state padded">No tasks yet — add some on the Tasks tab to see them here.</div>';
      return;
    }

    const rows = flattenVisible(buildHierarchy(tasks), new Set());
    const dated = tasks.filter((t) => t.start_date || t.due_date);
    const today = new Date(); today.setHours(0, 0, 0, 0);

    let rangeStart = dated.length
      ? new Date(Math.min(...dated.map((t) => (parseDateOnly(t.start_date || t.due_date)).getTime())))
      : addDays(today, -3);
    let rangeEnd = dated.length
      ? new Date(Math.max(...dated.map((t) => (parseDateOnly(t.due_date || t.start_date)).getTime())))
      : addDays(today, 27);
    rangeStart = addDays(rangeStart, -2);
    rangeEnd = addDays(rangeEnd, 2);
    if (rangeEnd - rangeStart < 14 * 86400000) rangeEnd = addDays(rangeStart, 14);
    const totalMs = rangeEnd - rangeStart;

    const weekMarks = [];
    for (let d = new Date(rangeStart); d <= rangeEnd; d = addDays(d, 7)) {
      weekMarks.push({ date: new Date(d), pct: ((d - rangeStart) / totalMs) * 100 });
    }
    const todayPct = ((today - rangeStart) / totalMs) * 100;
    const todayLine = todayPct >= 0 && todayPct <= 100 ? `<div class="tk-gantt-today" style="left:${todayPct}%" title="Today"></div>` : '';

    const rowsHtml = rows.map(({ task, depth }) => {
      const start = parseDateOnly(task.start_date) || parseDateOnly(task.due_date);
      const end = parseDateOnly(task.due_date) || parseDateOnly(task.start_date);
      let bar = '';
      if (start && end) {
        const left = Math.max(0, ((start - rangeStart) / totalMs) * 100);
        const width = Math.max(1.2, ((end - start) / totalMs) * 100);
        bar = `<button type="button" class="tk-gantt-bar tk-gantt-${esc(task.status)}" style="left:${left}%;width:${width}%" data-open="${task.id}" title="${esc(task.title)}"></button>`;
      }
      return `<div class="tk-gantt-row">
        <div class="tk-gantt-label" style="padding-left:${depth * 16}px"><a href="#" data-open="${task.id}">${esc(task.title)}</a></div>
        <div class="tk-gantt-track">${bar}</div>
      </div>`;
    }).join('');

    this.innerHTML = `<div class="tk-timeline">
      <div class="tk-gantt-header">
        <div class="tk-gantt-label-spacer"></div>
        <div class="tk-gantt-track tk-gantt-track-header">
          ${weekMarks.map((w) => `<div class="tk-gantt-tick" style="left:${w.pct}%">${esc(w.date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))}</div>`).join('')}
          ${todayLine}
        </div>
      </div>
      <div class="tk-gantt-body">${rowsHtml || '<div class="empty-state padded">No tasks yet.</div>'}</div>
    </div>`;

    $$('[data-open]', this).forEach((el) => el.addEventListener('click', (e) => {
      e.preventDefault();
      this.dispatchEvent(new CustomEvent('task-open', { bubbles: true, detail: { taskId: Number(el.dataset.open) } }));
    }));
  }
}
customElements.define('pb-task-timeline-view', TaskTimelineView);
