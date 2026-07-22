// <pb-task-calendar-view> — the "Calendar" tab: a month grid with tasks
// plotted on their due_date. Reuses the event calendar's generic grid CSS
// (.calendar-grid / .weekday / .calendar-day / .day-num / .cal-today —
// see event-views.js's EventCalendar) rather than inventing a parallel grid
// system; only the task-chip styling (.tk-cal-task) is new.
import { esc, isoDate, addDays, PanicElement, $, $$ } from '../core.js';

class TaskCalendarView extends PanicElement {
  connect() {
    this.month = new Date();
    if (this._data) this.render();
  }

  set data(value) { this._data = value; if (this.abort) this.render(); }
  get data() { return this._data; }

  render() {
    const { tasks } = this.data;
    const byDate = new Map();
    tasks.forEach((t) => {
      if (!t.due_date) return;
      if (!byDate.has(t.due_date)) byDate.set(t.due_date, []);
      byDate.get(t.due_date).push(t);
    });

    const first = new Date(this.month.getFullYear(), this.month.getMonth(), 1);
    const start = addDays(first, -first.getDay());
    const todayIso = isoDate(new Date());
    const monthLabel = this.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    const cells = [];
    for (let i = 0; i < 42; i++) {
      const d = addDays(start, i);
      const iso = isoDate(d);
      const inMonth = d.getMonth() === this.month.getMonth();
      const dayTasks = byDate.get(iso) || [];
      cells.push(`<div class="calendar-day${iso === todayIso ? ' cal-today' : ''}${!inMonth ? ' other-month' : ''}">
        <span class="day-num">${d.getDate()}</span>
        ${dayTasks.slice(0, 4).map((t) => `<div class="tk-cal-task tk-cal-task-${esc(t.status)}"><a href="#" data-open="${t.id}">${esc(t.title)}</a></div>`).join('')}
        ${dayTasks.length > 4 ? `<div class="tk-cal-more muted small">+${dayTasks.length - 4} more</div>` : ''}
      </div>`);
    }

    this.innerHTML = `<div class="tk-calendar">
      <div class="tk-cal-toolbar">
        <div class="calendar-controls">
          <button type="button" class="secondary small" data-prev>&lt;</button>
          <button type="button" class="secondary small" data-next>&gt;</button>
          <button type="button" class="secondary small" data-today>Today</button>
        </div>
        <h2>${esc(monthLabel)}</h2>
      </div>
      <div class="calendar-grid">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => `<div class="weekday">${d}</div>`).join('')}</div>
      <div class="calendar-grid">${cells.join('')}</div>
    </div>`;

    $('[data-prev]', this)?.addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1); this.render(); });
    $('[data-next]', this)?.addEventListener('click', () => { this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1); this.render(); });
    $('[data-today]', this)?.addEventListener('click', () => { this.month = new Date(); this.render(); });
    $$('[data-open]', this).forEach((a) => a.addEventListener('click', (e) => {
      e.preventDefault();
      this.dispatchEvent(new CustomEvent('task-open', { bubbles: true, detail: { taskId: Number(a.dataset.open) } }));
    }));
  }
}
customElements.define('pb-task-calendar-view', TaskCalendarView);
