// ── Event workspace panels ───────────────────────────────────────────────────
// The editable section panels inside the event workspace: Tasks, Lineup, Run
// Sheet, Staffing, Open Items, Guest List, Assets, Invites, Settlement — plus
// the shared record-list helpers they all build on.

// Capacity-based staffing tiers (mirrored from Staffing.php STAFFING_TIERS).
// Used by the "Auto-fill from capacity" button in the StaffingManager panel.
// Tiers cover every 50 guests from 50 → 400; anything above 400 uses the 400 tier.
const STAFFING_TIERS = [
  { max:  50, roles: [['manager',1],['bartender',1],['door',1],['sound',1]] },
  { max: 100, roles: [['manager',1],['bartender',2],['door',1],['security',1],['sound',1]] },
  { max: 150, roles: [['manager',1],['bartender',2],['barback',1],['door',2],['security',1],['sound',1],['lighting',1],['stagehand',1]] },
  { max: 200, roles: [['manager',1],['bartender',3],['barback',1],['door',2],['security',2],['sound',1],['lighting',1],['stagehand',1]] },
  { max: 250, roles: [['manager',1],['bartender',3],['barback',2],['door',2],['security',3],['sound',1],['lighting',1],['stagehand',1],['runner',1]] },
  { max: 300, roles: [['manager',1],['bartender',4],['barback',2],['door',2],['security',4],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
  { max: 350, roles: [['manager',1],['bartender',5],['barback',2],['door',3],['security',5],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
  { max: 400, roles: [['manager',1],['bartender',5],['barback',3],['door',3],['security',6],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
];

function staffingTierFor(capacity) {
  const cap = Math.max(1, parseInt(capacity, 10) || 0);
  for (const tier of STAFFING_TIERS) { if (cap <= tier.max) return tier.roles; }
  return STAFFING_TIERS[STAFFING_TIERS.length - 1].roles;
}
import { setTokens, esc, titleCase, statuses, appUrl, assetUrl, getAppUser, publish, subscribe, api, apiUrl, getToken, formData, broadcastEventData, refreshSection, eventDate, shortDate, isoDate, addDays, timeLabel, money, statusTone, statusLabel, badge, option, select, userSelect, ownerSelect, emptyState, helpLink, can, table, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';

// ---- Editable record lists: read-only review tables with hover-to-edit ----
// These power the Tasks / Lineup / Run Sheet / Staffing / Guest / Open Items
// panels. Existing items render as plain text rows; an edit pencil fades in on
// row hover and swaps the row for its inline edit form. A "+" in the panel
// header reveals the (otherwise hidden) add form. After any save the parent
// component re-renders via refreshSection(), collapsing everything back to the
// clean review view.

// A small pill for status/category values; blank input renders as an em dash.
function chip(value, tone) {
  if (value === '' || value == null) return '';
  return `<span class="chip${tone ? ` chip-${esc(tone)}` : ''}">${esc(titleCase(value))}</span>`;
}


// Format a YYYY-MM-DD value as a short, localized date (blank stays blank).
function dateLabel(value) {
  if (!value) return '';
  const date = new Date(`${value}T12:00:00`);
  return Number.isNaN(date.getTime()) ? esc(value) : esc(shortDate(date));
}


const editAffordance = '<button type="button" class="record-edit" data-edit aria-label="Edit" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>';


// Render a read-only review table whose rows reveal an inline edit form.
//   items     – array of records
//   cols      – [{ label, grid?, cell:(item)=>html }] (cell returns safe HTML)
//   formFor   – (item)=>'<form class="row-form record-form" …>' edit form markup
//   editable  – when false, rows are plain text with no pencil/form
//   empty     – empty-state message (optional)
//   opts.labeled  – grouped lists: skip the column header, keep per-cell labels
//   opts.rowClass – (item)=>extra class string for the record wrapper
function recordList(items, cols, formFor, editable, empty, opts = {}) {
  if (!items.length) return empty ? emptyState(empty) : '';
  const labeled = Boolean(opts.labeled);
  const tpl = cols.map((c) => c.grid || 'minmax(110px, 1fr)').join(' ') + (editable ? ' 44px' : '');
  const head = labeled ? '' : `<div class="record-head" style="grid-template-columns:${tpl}">${cols.map((c) => `<span>${esc(c.label)}</span>`).join('')}${editable ? '<span aria-hidden="true"></span>' : ''}</div>`;
  const rows = items.map((item) => {
    const cells = cols.map((c) => {
      const value = c.cell(item);
      const empty = value === '' || value == null;
      return `<div class="record-cell"><span class="record-label">${esc(c.label)}</span><span class="record-value">${empty ? '<span class="record-empty">—</span>' : value}</span></div>`;
    }).join('');
    const rowClass = opts.rowClass ? opts.rowClass(item) : '';
    const view = `<div class="record-view" style="grid-template-columns:${tpl}">${cells}${editable ? editAffordance : ''}</div>`;
    return `<div class="record${rowClass ? ` ${rowClass}` : ''}" data-record>${view}${editable ? formFor(item) : ''}</div>`;
  }).join('');
  return `<div class="record-table${labeled ? ' record-table--labeled' : ''}">${head}${rows}</div>`;
}


// Wire up read<->edit toggling and the "+ add" reveal inside a list component.
function bindRecords(root) {
  $$('[data-edit]', root).forEach((btn) => btn.addEventListener('click', () => {
    const rec = btn.closest('[data-record]');
    if (!rec) return;
    rec.classList.add('editing');
    $$('input, select, textarea', rec).find((el) => !el.disabled && el.type !== 'hidden')?.focus();
  }));
  $$('[data-cancel]', root).forEach((btn) => btn.addEventListener('click', () => {
    btn.closest('[data-record]')?.classList.remove('editing');
  }));
  bindAddToggle(root);
}

class TaskList extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const tasks = data.tasks || [];
    const editable = can(data, 'manage_tasks');
    const users = data.users || [];
    const userName = (id) => { const u = users.find((x) => String(x.id) === String(id)); return u ? esc(u.name) : ''; };
    const cols = [
      { label: 'Task', grid: 'minmax(150px, 2fr)', cell: (t) => esc(t.title) },
      { label: 'Status', grid: 'minmax(110px, 1fr)', cell: (t) => chip(t.status) },
      { label: 'Assigned', grid: 'minmax(110px, 1fr)', cell: (t) => userName(t.assigned_user_id) },
      { label: 'Due', grid: 'minmax(90px, 0.8fr)', cell: (t) => dateLabel(t.due_date) },
      { label: 'Priority', grid: 'minmax(90px, 0.8fr)', cell: (t) => chip(t.priority) },
      { label: 'Details', grid: 'minmax(140px, 2fr)', cell: (t) => esc(t.description || '') },
    ];
    const editForm = (task) => `<form data-api="/events/${data.event.id}/tasks/${task.id}" data-method="PATCH" class="row-form record-form"><label>Task<input name="title" value="${esc(task.title)}"></label><label>Status${select('status', ['todo','in_progress','blocked','done','canceled'], task.status)}</label><label>Assigned${userSelect(users, task.assigned_user_id)}</label><label>Due<input type="date" name="due_date" value="${esc(task.due_date || '')}"></label><label>Priority${select('priority', ['low','normal','high','urgent'], task.priority)}</label><label>Details<input name="description" value="${esc(task.description || '')}"></label><button>Save</button><button type="button" class="secondary" data-complete="${esc(task.id)}">Done</button><button type="button" class="small danger" data-delete="${esc(task.id)}">Delete</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/tasks" data-method="POST" class="row-form" data-add-form hidden><label>Task<input name="title" required placeholder="Confirm door count"></label><label>Assigned${userSelect(users)}</label><label>Due<input type="date" name="due_date"></label><label>Priority${select('priority', ['low','normal','high','urgent'], 'normal')}</label><input type="hidden" name="status" value="todo"><label>Details<input name="description" placeholder="Details"></label><button>Add task</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    const templates = data.taskTemplates || [];
    const templateDropdown = editable && templates.length > 0
      ? `<details class="print-menu"><summary class="button secondary">Add Tasks &#9662;</summary><div class="print-menu-items">${templates.map((t) => `<button type="button" data-tmpl-id="${esc(String(t.id))}">${esc(t.name)}</button>`).join('')}</div></details>`
      : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Tasks ${helpLink('tasks', 'Tasks')}</h2><div class="section-head-actions">${templateDropdown}${addToggle('Add task', editable)}</div></div><div class="record-body">${addForm}${recordList(tasks, cols, editForm, editable, 'No tasks for this event.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Task saved.' });
    }));
    $$('[data-complete]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'done';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      await refreshSection(this);
      publish('toast.show', { message: 'Task completed.' });
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      if (!confirm('Delete this task? This cannot be undone.')) return;
      try {
        await api(`/events/${this.eventData.event.id}/tasks/${button.dataset.delete}`, { method: 'DELETE' });
        await refreshSection(this);
        publish('toast.show', { message: 'Task deleted.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));
    $$('[data-tmpl-id]', this).forEach((btn) => btn.addEventListener('click', async () => {
      const tmplId = btn.dataset.tmplId;
      btn.closest('details')?.removeAttribute('open');
      const result = await api(`/events/${this.eventData.event.id}/tasks/from-template/${tmplId}`, { method: 'POST' });
      await refreshSection(this);
      const added = result?.added ?? 0;
      publish('toast.show', { message: added === 1 ? '1 task added.' : `${added} tasks added.` });
    }));
  }
}


class LineupEditor extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const lineup = data.lineup || [];
    const editable = can(data, 'manage_lineup');
    const cols = [
      { label: '#', grid: '46px', cell: (i) => esc(i.billing_order ?? '') },
      { label: 'Artist', grid: 'minmax(140px, 2fr)', cell: (i) => esc(i.display_name) },
      { label: 'Set', grid: 'minmax(80px, 1fr)', cell: (i) => i.set_time ? esc(timeLabel(i.set_time)) : '' },
      { label: 'Length', grid: 'minmax(70px, 0.8fr)', cell: (i) => i.set_length_minutes ? `${esc(i.set_length_minutes)} min` : '' },
      { label: 'Status', grid: 'minmax(100px, 1fr)', cell: (i) => chip(i.status) },
      { label: 'Payout', grid: 'minmax(100px, 1fr)', cell: (i) => esc(i.payout_terms || '') },
      { label: 'Notes', grid: 'minmax(120px, 2fr)', cell: (i) => esc(i.notes || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/lineup/${item.id}" data-method="PATCH" class="row-form record-form"><label>#<input name="billing_order" type="number" value="${esc(item.billing_order)}"></label><label>Artist<input name="display_name" value="${esc(item.display_name)}"></label><label>Set<input name="set_time" type="time" value="${esc(item.set_time || '')}"></label><label>Length<input name="set_length_minutes" type="number" value="${esc(item.set_length_minutes || '')}"></label><label>Status${select('status', ['invited','tentative','confirmed','canceled'], item.status)}</label><label>Payout<input name="payout_terms" value="${esc(item.payout_terms || '')}"></label><label>Notes<input name="notes" value="${esc(item.notes || '')}"></label><button>Save</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/lineup" data-method="POST" class="row-form" data-add-form hidden><label>Artist<input name="band_name" placeholder="Band/artist"></label><label>Display name<input name="display_name" placeholder="Display name"></label><label>#<input name="billing_order" type="number" placeholder="Order"></label><label>Set<input name="set_time" type="time"></label><label>Length<input name="set_length_minutes" type="number" placeholder="Minutes"></label><label>Status${select('status', ['invited','tentative','confirmed','canceled'], 'tentative')}</label><label>Payout<input name="payout_terms" placeholder="Payout"></label><button>Add lineup</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Lineup ${helpLink('lineup', 'Lineup &amp; Bands')}</h2><div class="section-head-actions">${addToggle('Add lineup', editable)}</div></div><div class="record-body">${addForm}${recordList(lineup, cols, editForm, editable, 'No lineup yet.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Lineup saved.' });
    }));
  }
}


class RunSheet extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const schedule = data.schedule || [];
    const editable = can(data, 'manage_schedule');
    const types = ['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other'];
    const cols = [
      { label: 'Item', grid: 'minmax(140px, 2fr)', cell: (i) => esc(i.title) },
      { label: 'Type', grid: 'minmax(110px, 1fr)', cell: (i) => chip(i.item_type) },
      { label: 'Start', grid: 'minmax(80px, 1fr)', cell: (i) => i.start_time ? esc(timeLabel(i.start_time)) : '' },
      { label: 'End', grid: 'minmax(80px, 1fr)', cell: (i) => i.end_time ? esc(timeLabel(i.end_time)) : '' },
      { label: 'Notes', grid: 'minmax(120px, 2fr)', cell: (i) => esc(i.notes || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/schedule/${item.id}" data-method="PATCH" class="row-form record-form"><label>Item<input name="title" value="${esc(item.title)}"></label><label>Type${select('item_type', types, item.item_type)}</label><label>Start<input type="time" name="start_time" value="${esc(item.start_time || '')}"></label><label>End<input type="time" name="end_time" value="${esc(item.end_time || '')}"></label><label>Notes<input name="notes" value="${esc(item.notes || '')}"></label><button>Save</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/schedule" data-method="POST" class="row-form" data-add-form hidden><label>Item<input name="title" required placeholder="Schedule item"></label><label>Type${select('item_type', types, 'other')}</label><label>Start<input type="time" name="start_time"></label><label>End<input type="time" name="end_time"></label><label>Notes<input name="notes" placeholder="Notes"></label><button>Add item</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Run Sheet ${helpLink('schedule', 'Schedule &amp; Run Sheet')}</h2><div class="section-head-actions">${addToggle('Add run sheet item', editable)}</div></div><div class="record-body">${addForm}${recordList(schedule, cols, editForm, editable, 'No run sheet items yet.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Run sheet saved.' });
    }));
  }
}


class StaffingManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const shifts = data.staffing || [];
    const roster = data.staffRoster || [];
    const roles  = data.staffRoles || ['manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other'];
    const statuses = data.staffingStatuses || ['scheduled','confirmed','declined','no_show','completed','canceled'];
    const editable = can(data, 'manage_staffing');

    const rosterOptions = (selectedId) => `<option value="">— TBD —</option>${roster.map((s) => `<option value="${esc(s.id)}" data-default-role="${esc(s.default_role)}" data-default-rate="${esc(s.hourly_rate || '')}" ${Number(s.id) === Number(selectedId || 0) ? 'selected' : ''}>${esc(s.name)} (${esc(titleCase(s.default_role))})</option>`).join('')}`;

    // Group shifts by role for a tidy night-of-show layout.
    const grouped = shifts.reduce((map, shift) => {
      const key = shift.role || 'other';
      (map[key] = map[key] || []).push(shift);
      return map;
    }, {});
    const roleOrder = ['manager','sound','lighting','security','door','bartender','barback','stagehand','runner','cleaner','other'];

    const capacity = data.event?.capacity ? parseInt(data.event.capacity, 10) : 0;
    const totalShifts = shifts.length;
    const confirmed = shifts.filter((s) => s.status === 'confirmed').length;
    const tbd = shifts.filter((s) => !s.staff_member_id).length;

    const cols = [
      { label: 'Staff', grid: 'minmax(130px, 1.4fr)', cell: (s) => s.staff_name ? esc(s.staff_name) : '<span class="muted">TBD</span>' },
      { label: 'Role', grid: 'minmax(100px, 1fr)', cell: (s) => chip(s.role) },
      { label: 'Call', grid: 'minmax(70px, 0.8fr)', cell: (s) => s.call_time ? esc(timeLabel(s.call_time)) : '' },
      { label: 'End', grid: 'minmax(70px, 0.8fr)', cell: (s) => s.end_time ? esc(timeLabel(s.end_time)) : '' },
      { label: 'Rate', grid: 'minmax(80px, 0.8fr)', cell: (s) => s.hourly_rate ? `${esc(money(s.hourly_rate))}/hr` : '' },
      { label: 'Status', grid: 'minmax(100px, 1fr)', cell: (s) => chip(s.status) },
      { label: 'Contact', grid: 'minmax(120px, 1.4fr)', cell: (s) => [s.staff_phone, s.staff_email].filter(Boolean).map(esc).join(' &middot; ') },
      { label: 'Notes', grid: 'minmax(120px, 1.4fr)', cell: (s) => esc(s.notes || '') },
    ];

    const editForm = (shift) => `<form data-shift="${esc(shift.id)}" class="row-form record-form staffing-row"><label>Staff <select name="staff_member_id">${rosterOptions(shift.staff_member_id)}</select></label><label>Role ${select('role', roles, shift.role)}</label><label>Call <input type="time" name="call_time" value="${esc(shift.call_time || '')}"></label><label>End <input type="time" name="end_time" value="${esc(shift.end_time || '')}"></label><label>Rate <input type="number" step="0.01" name="hourly_rate" value="${esc(shift.hourly_rate || '')}" placeholder="$/hr"></label><label>Status ${select('status', statuses, shift.status)}</label><label>Notes <input name="notes" value="${esc(shift.notes || '')}"></label><button>Save</button><button type="button" class="small danger" data-delete="${esc(shift.id)}">Remove</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;

    const groupSections = roleOrder
      .filter((role) => grouped[role])
      .map((role) => `<div class="staffing-section"><h3 class="guest-section-head">${esc(titleCase(role))} <span class="muted">${grouped[role].length} shift${grouped[role].length === 1 ? '' : 's'}</span></h3>${recordList(grouped[role], cols, editForm, editable, '', { labeled: true })}</div>`)
      .join('');

    const rosterHint = roster.length
      ? ''
      : (editable
        ? '<p class="muted padded">No active staff in the roster yet. Open <a href="#admin-staff">Admin &rarr; Staff</a> to add bartenders, security, sound, etc.</p>'
        : '');

    const addForm = editable ? `<form data-form="add" data-add-form hidden class="row-form staffing-add">
      <label>Staff <select name="staff_member_id">${rosterOptions(null)}</select></label>
      <label>Role ${select('role', roles, 'security')}</label>
      <label>Call <input type="time" name="call_time"></label>
      <label>End <input type="time" name="end_time"></label>
      <label>Rate <input type="number" step="0.01" name="hourly_rate" placeholder="$/hr"></label>
      <label>Status ${select('status', statuses, 'scheduled')}</label>
      <label>Notes <input name="notes" placeholder="Door area, late call, etc."></label>
      <button>Add shift</button>
      <button type="button" class="secondary small" data-cancel-add>Cancel</button>
    </form>` : '';

    // Auto-fill button: only show when user can edit and event has a capacity set.
    const autoFillBtn = editable && capacity > 0
      ? `<button type="button" class="small secondary" data-auto-staff title="Clear all shifts and rebuild from capacity-based staffing tiers">Auto-fill (${capacity} cap)</button>`
      : '';

    // Export payroll CSV button: shown to anyone with manage_staffing access.
    const exportBtn = editable
      ? `<button type="button" class="small secondary" data-export-payroll title="Download payroll CSV for this event">Export CSV</button>`
      : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Staffing ${helpLink('staffing', 'Staffing')}</h2>
        <div class="section-head-actions">
          <div class="staffing-totals muted">${totalShifts} shift${totalShifts === 1 ? '' : 's'} &middot; ${confirmed} confirmed${tbd ? ` &middot; ${tbd} TBD` : ''}</div>
          ${exportBtn}
          ${autoFillBtn}
          ${addToggle('Add shift', editable)}
        </div>
      </div>
      <div class="record-body staffing-body">
        ${rosterHint}
        ${addForm}
        ${shifts.length ? groupSections : emptyState('No shifts assigned yet. Add bartenders, security, sound, door staff, etc.')}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    const eventId = this.eventData.event.id;
    const buildBody = (form) => {
      const body = formData(form);
      if (body.staff_member_id === '') body.staff_member_id = null;
      if (body.hourly_rate === '')     body.hourly_rate = null;
      return body;
    };

    // When picking a staff member, prefill role + rate if those fields are empty.
    $$('select[name="staff_member_id"]', this).forEach((select) => select.addEventListener('change', () => {
      const opt = select.selectedOptions[0];
      if (!opt || !opt.value) return;
      const form = select.closest('form');
      if (!form) return;
      const defRole = opt.dataset.defaultRole;
      const defRate = opt.dataset.defaultRate;
      if (defRole && form.elements.role && !form.elements.role.dataset.touched) {
        form.elements.role.value = defRole;
      }
      if (defRate && form.elements.hourly_rate && !form.elements.hourly_rate.value) {
        form.elements.hourly_rate.value = defRate;
      }
    }));
    $$('select[name="role"], input[name="hourly_rate"]', this).forEach((el) => el.addEventListener('input', () => { el.dataset.touched = '1'; }));

    $$('form[data-shift]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${eventId}/staffing/${form.dataset.shift}`, { method: 'PATCH', body: JSON.stringify(buildBody(form)) });
        await refreshSection(this);
        publish('toast.show', { message: 'Shift saved.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      if (!confirm('Remove this shift?')) return;
      try {
        await api(`/events/${eventId}/staffing/${button.dataset.delete}`, { method: 'DELETE' });
        await refreshSection(this);
        publish('toast.show', { message: 'Shift removed.' });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    }));

    $('[data-form="add"]', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${eventId}/staffing`, { method: 'POST', body: JSON.stringify(buildBody(event.target)) });
        publish('toast.show', { message: 'Shift added.' });
        await refreshSection(this);
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });

    // Export payroll CSV
    $('[data-export-payroll]', this)?.addEventListener('click', async () => {
      const btn = $('[data-export-payroll]', this);
      try {
        if (btn) btn.disabled = true;
        const url = apiUrl(`/events/${eventId}/staffing/export`);
        const resp = await fetch(url, { headers: { Authorization: `Bearer ${getToken()}` } });
        if (!resp.ok) throw new Error(`Export failed (${resp.status})`);
        const blob = await resp.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `payroll-event-${eventId}-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (err) {
        publish('toast.show', { message: err.message || 'CSV export failed.', tone: 'error' });
      } finally {
        if (btn) btn.disabled = false;
      }
    });

    // Auto-fill from capacity tiers
    $('[data-auto-staff]', this)?.addEventListener('click', async () => {
      const cap  = parseInt(this.eventData?.event?.capacity, 10) || 0;
      if (!cap) {
        publish('toast.show', { message: 'Set a capacity on the event first.', tone: 'error' });
        return;
      }
      const tier  = staffingTierFor(cap);
      const total = tier.reduce((sum, [, count]) => sum + count, 0);
      const preview = tier.map(([role, count]) => `${count} ${titleCase(role)}`).join(', ');
      if (!confirm(`Reset staffing for ${cap} people?\n\nThis will clear all current shifts and create:\n${preview}\n\n${total} positions total. Continue?`)) return;
      try {
        await api(`/events/${eventId}/staffing/from-capacity`, {
          method: 'POST',
          body: JSON.stringify({ capacity: cap }),
        });
        await refreshSection(this);
        publish('toast.show', { message: `Staffing auto-filled for ${cap} people (${total} positions).` });
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}


class OpenItems extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const items = data.blockers || [];
    const editable = can(data, 'manage_open_items');
    const cols = [
      { label: 'Item', grid: 'minmax(150px, 2fr)', cell: (i) => esc(i.title) },
      { label: 'Status', grid: 'minmax(110px, 1fr)', cell: (i) => chip(i.status) },
      { label: 'Due', grid: 'minmax(90px, 0.8fr)', cell: (i) => dateLabel(i.due_date) },
      { label: 'Details', grid: 'minmax(150px, 2fr)', cell: (i) => esc(i.description || '') },
    ];
    const editForm = (item) => `<form data-api="/events/${data.event.id}/open-items/${item.id}" data-method="PATCH" class="row-form record-form"><label>Item<input name="title" value="${esc(item.title)}"></label><label>Status${select('status', ['open','waiting','resolved','canceled'], item.status)}</label><label>Due<input type="date" name="due_date" value="${esc(item.due_date || '')}"></label><label>Details<input name="description" value="${esc(item.description || '')}"></label><input type="hidden" name="owner_user_id" value="${esc(item.owner_user_id || '')}"><button>Save</button><button type="button" class="secondary" data-resolve="${esc(item.id)}">Mark Complete</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;
    const addForm = editable ? `<form data-api="/events/${data.event.id}/open-items" data-method="POST" class="row-form" data-add-form hidden><label>Item<input name="title" required placeholder="Waiting on ticket link"></label><label>Details<input name="description" placeholder="Details"></label><input type="hidden" name="status" value="open"><label>Due<input type="date" name="due_date"></label><button>Add open item</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : '';
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Open Items ${helpLink('open-items', 'Open Items')}</h2><div class="section-head-actions">${addToggle('Add open item', editable)}</div></div><div class="record-body">${addForm}${recordList(items, cols, editForm, editable, 'No open items for this event.')}</div></section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Open item saved.' });
    }));
    $$('[data-resolve]', this).forEach((button) => button.addEventListener('click', async () => {
      const form = button.closest('form');
      const body = formData(form);
      body.status = 'resolved';
      await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
      await refreshSection(this);
      publish('toast.show', { message: 'Open item completed.' });
    }));
  }
}


class GuestListManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const guests = data.guests || [];
    const editable = can(data, 'manage_guest_list');
    const internal = data.event?.ticketing_mode === 'internal';
    const listTypes = ['comp', 'guest', 'will_call', 'vip', 'press', 'industry'];

    // Per-guest comp affordance: view/resend issued comps, or issue them.
    const compCell = (g) => {
      if (!internal) return '';
      const tickets = g.comp_tickets || [];
      if (tickets.length) {
        const links = tickets.map((t) => `<a class="comp-view" href="${esc(t.url || '#')}" target="_blank" rel="noopener" title="${esc(t.code)} — ${esc(t.status)}">${t.status === 'redeemed' ? '✓ ' : ''}QR</a>`).join('');
        return `<div class="comp-cell"><span class="comp-badge">${tickets.length} comp${tickets.length > 1 ? 's' : ''}</span>${links}${editable ? `<button type="button" class="small secondary" data-resend-comp="${esc(g.id)}">Resend</button>` : ''}</div>`;
      }
      if (!editable) return '';
      return g.email
        ? `<button type="button" class="small" data-issue-comp="${esc(g.id)}">Issue comp</button>`
        : '<span class="muted small">needs email</span>';
    };

    const grouped = guests.reduce((map, guest) => {
      const key = guest.list_type || 'guest';
      (map[key] = map[key] || []).push(guest);
      return map;
    }, {});
    const sectionOrder = ['vip', 'press', 'industry', 'comp', 'guest', 'will_call'];

    const totalEntries = guests.length;
    const totalSeats = guests.reduce((sum, g) => sum + Number(g.party_size || 1), 0);
    const checkedIn = guests.filter((g) => Number(g.checked_in)).length;
    const checkedSeats = guests
      .filter((g) => Number(g.checked_in))
      .reduce((sum, g) => sum + Number(g.party_size || 1), 0);

    // The check-in toggle stays live in the read-only row — it's the primary
    // door-night action — while the pencil reveals the full edit form.
    const cols = [
      { label: 'In', grid: '64px', cell: (g) => `<label class="guest-check"><input type="checkbox" data-checkin="${esc(g.id)}" ${Number(g.checked_in) ? 'checked' : ''}${editable ? '' : ' disabled'}><span>${Number(g.checked_in) ? 'In' : 'Out'}</span></label>` },
      { label: 'Name', grid: 'minmax(140px, 1.8fr)', cell: (g) => `${esc(g.name)}${g.email ? `<br><span class="muted small">${esc(g.email)}</span>` : ''}` },
      { label: 'Party', grid: '64px', cell: (g) => esc(g.party_size || 1) },
      { label: 'Type', grid: 'minmax(86px, 0.9fr)', cell: (g) => chip(g.list_type) },
      { label: 'Guest of', grid: 'minmax(100px, 1fr)', cell: (g) => esc(g.guest_of || '') },
      ...(internal ? [{ label: 'Comp', grid: 'minmax(130px, 1.3fr)', cell: compCell }] : []),
      { label: 'Notes', grid: 'minmax(110px, 1.2fr)', cell: (g) => esc(g.notes || '') },
    ];

    const editForm = (guest) => `<form data-api="/events/${data.event.id}/guest-list/${guest.id}" data-method="PATCH" class="row-form record-form guest-row"><label>Name<input name="name" value="${esc(guest.name)}"></label><label>Email<input name="email" type="email" placeholder="For comp delivery" value="${esc(guest.email || '')}"></label><label>Party<input name="party_size" type="number" min="1" value="${esc(guest.party_size || 1)}"></label><label>Type${select('list_type', listTypes, guest.list_type)}</label><label>Guest of<input name="guest_of" placeholder="Guest of" value="${esc(guest.guest_of || '')}"></label><label>Notes<input name="notes" placeholder="Notes" value="${esc(guest.notes || '')}"></label><button>Save</button><button type="button" class="small danger" data-delete="${esc(guest.id)}">Delete</button><button type="button" class="secondary small" data-cancel>Cancel</button></form>`;

    const sections = sectionOrder
      .filter((key) => grouped[key])
      .map((key) => {
        const subtotalEntries = grouped[key].length;
        const subtotalSeats = grouped[key].reduce((sum, g) => sum + Number(g.party_size || 1), 0);
        return `<div class="guest-section"><h3 class="guest-section-head">${esc(titleCase(key))} <span class="muted">${subtotalEntries} entries &middot; ${subtotalSeats} seats</span></h3>${recordList(grouped[key], cols, editForm, editable, '', { labeled: true, rowClass: (g) => Number(g.checked_in) ? 'checked-in' : '' })}</div>`;
      }).join('');

    const addForm = editable ? `<form data-api="/events/${data.event.id}/guest-list" data-method="POST" data-add-form hidden class="row-form guest-add">
      <label>Name<input name="name" required placeholder="Guest name"></label>
      <label>Email<input name="email" type="email" placeholder="For comp delivery"></label>
      <label>Party<input name="party_size" type="number" min="1" value="1"></label>
      <label>Type${select('list_type', listTypes, 'guest')}</label>
      <label>Guest of<input name="guest_of" placeholder="Guest of (band/promoter)"></label>
      <label>Notes<input name="notes" placeholder="Notes"></label>
      <button>Add guest</button>
      <button type="button" class="secondary small" data-cancel-add>Cancel</button>
    </form>` : '';

    this.innerHTML = `<section class="panel">
      <div class="section-head padded">
        <h2>Door / Guest List ${helpLink('guest-list', 'Guest List')}</h2>
        <div class="section-head-actions">
          <div class="guest-totals muted">${totalEntries} entries &middot; ${totalSeats} seats &middot; ${checkedIn} checked in (${checkedSeats} seats)</div>
          ${addToggle('Add guest', editable)}
        </div>
      </div>
      <div class="record-body guest-list-body">
        ${addForm}
        ${guests.length ? sections : emptyState('No guest list entries yet.')}
      </div>
    </section>`;
    if (!editable) return;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('form[data-api]', this).forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault();
      await api(form.dataset.api, { method: form.dataset.method, body: JSON.stringify(formData(form)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Guest list saved.' });
    }));
    $$('[data-checkin]', this).forEach((checkbox) => checkbox.addEventListener('change', async () => {
      const id = checkbox.dataset.checkin;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ checked_in: checkbox.checked ? 1 : 0 }),
      });
      await refreshSection(this);
      publish('toast.show', { message: checkbox.checked ? 'Checked in.' : 'Check-in cleared.' });
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      const id = button.dataset.delete;
      if (!confirm('Remove this guest from the list?')) return;
      await api(`/events/${this.eventData.event.id}/guest-list/${id}`, { method: 'DELETE' });
      await refreshSection(this);
      publish('toast.show', { message: 'Guest removed.' });
    }));
    const eventId = this.eventData.event.id;
    $$('[data-issue-comp]', this).forEach((button) => button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const res = await api(`/events/${eventId}/ticketing/comp`, { method: 'POST', body: JSON.stringify({ guest_list_id: button.dataset.issueComp }) });
        publish('toast.show', { message: `Issued ${res.issued} comp ticket(s)${res.emailed ? `, emailed ${res.emailed}` : ''}.` });
        await refreshSection(this);
      } catch (error) {
        publish('toast.show', { tone: 'error', message: error.message || 'Could not issue comp.' });
        button.disabled = false;
      }
    }));
    $$('[data-resend-comp]', this).forEach((button) => button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const res = await api(`/events/${eventId}/ticketing/comp`, { method: 'POST', body: JSON.stringify({ guest_list_id: button.dataset.resendComp, resend: 1 }) });
        publish('toast.show', { message: `Resent ${res.emailed} ticket(s).` });
      } catch (error) {
        publish('toast.show', { tone: 'error', message: error.message || 'Could not resend.' });
      } finally {
        button.disabled = false;
      }
    }));
  }
}


// Full-page image viewer. Shows the image contained at the largest size that
// preserves its aspect ratio; tap/click anywhere or press Escape to dismiss.
function openImageLightbox(src, alt = '') {
  if (!src) return;
  const dialog = document.createElement('div');
  dialog.className = 'lightbox-backdrop';
  dialog.innerHTML = `<button class="lightbox-close" type="button" aria-label="Close">&times;</button><img class="lightbox-img" src="${esc(src)}" alt="${esc(alt)}">`;
  document.body.appendChild(dialog);
  document.body.classList.add('lightbox-open');
  const close = () => {
    dialog.remove();
    document.body.classList.remove('lightbox-open');
    document.removeEventListener('keydown', onEsc);
  };
  function onEsc(e) { if (e.key === 'Escape') close(); }
  dialog.addEventListener('click', close);
  document.addEventListener('keydown', onEsc);
}


class AssetManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const assets = data.assets || [];
    const canManage = can(data, 'manage_assets');
    const canUpload = can(data, 'upload_assets');
    this.innerHTML = `<section class="panel"><div class="section-head padded"><h2>Assets ${helpLink('assets', 'Assets &amp; Flyers')}</h2><div class="section-head-actions">${canUpload ? '<button class="secondary small" data-generate-flyer>✨ Generate flyer</button>' : ''}${addToggle('Upload asset', canUpload)}</div></div>${canUpload ? `<form id="asset-form" class="row-form" data-add-form hidden><label>Title<input name="title" placeholder="Asset title"></label><label>Type${select('asset_type', ['flyer','poster','band_photo','logo','social_square','social_story','press_photo','other'], 'flyer')}</label><label>File<input type="file" name="asset" accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,.pdf" required></label><label>Notes<input name="notes" placeholder="Notes"></label><button>Upload asset</button><button type="button" class="secondary small" data-cancel-add>Cancel</button></form>` : ''}<div class="asset-grid">${assets.map((asset) => `<article class="asset-card">${/\.(png|jpg|jpeg|gif|webp|svg)$/i.test(asset.filename) ? `<img class="asset-image" src="${esc(assetUrl(asset.file_path))}" alt="${esc(asset.title)}" tabindex="0" role="button" aria-label="View ${esc(asset.title)} full size">` : '<span class="asset-thumb">PDF</span>'}<strong>${esc(asset.title)}</strong><span>${esc(titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span><div class="inline-actions"><a class="button small secondary" href="${esc(assetUrl(asset.file_path))}" download>Download</a>${canManage ? `<button class="small" data-approve="${esc(asset.id)}">Approve</button><button class="small secondary" data-reject="${esc(asset.id)}">Reject</button><button class="small danger" data-delete="${esc(asset.id)}">Delete</button>` : ''}</div></article>`).join('') || emptyState('No assets uploaded yet.')}</div></section>`;
    this.bind();
  }

  bind() {
    bindRecords(this);
    $$('img.asset-image', this).forEach((img) => {
      const open = () => openImageLightbox(img.src, img.alt);
      img.addEventListener('click', open);
      img.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
    $('#asset-form', this)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/events/${this.eventData.event.id}/assets`, { method: 'POST', body: new FormData(event.target) });
        publish('toast.show', { message: 'Asset uploaded.' });
        await refreshSection(this);
      } catch (err) {
        publish('toast.show', { message: err.message || 'Upload failed.', tone: 'error' });
      }
    });
    $$('[data-approve],[data-reject]', this).forEach((button) => button.addEventListener('click', async () => {
      const status = button.dataset.approve ? 'approved' : 'rejected';
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.approve || button.dataset.reject}`, { method: 'PATCH', body: JSON.stringify({ approval_status: status }) });
        await refreshSection(this);
        publish('toast.show', { message: `Asset ${status}.` });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Action failed.', tone: 'error' });
      }
    }));
    $$('[data-delete]', this).forEach((button) => button.addEventListener('click', async () => {
      try {
        await api(`/events/${this.eventData.event.id}/assets/${button.dataset.delete}`, { method: 'DELETE' });
        await refreshSection(this);
        publish('toast.show', { message: 'Asset deleted.' });
      } catch (err) {
        publish('toast.show', { message: err.message || 'Delete failed.', tone: 'error' });
      }
    }));
    $('[data-generate-flyer]', this)?.addEventListener('click', async (event) => {
      const btn = event.currentTarget;
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Generating…';
      try {
        await api(`/events/${this.eventData.event.id}/assets/generate-flyer`, { method: 'POST' });
        publish('toast.show', { message: 'Flyer generated! Review it in the assets list below.' });
        await refreshSection(this);
      } catch (err) {
        publish('toast.show', { message: err.message || 'Flyer generation failed.', tone: 'error' });
      } finally {
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  }
}


class InviteManager extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const eventId = data.event.id;
    const roles = ['event_owner','promoter','band','artist','designer','staff','viewer'];
    const invites = data.invites || [];

    const rowsHtml = invites.length ? invites.map((invite) => {
      const url = appUrl(`invite.html?token=${invite.token}`);
      const accepted = Boolean(invite.used_at);
      const meta = accepted ? 'Accepted' : `Expires ${esc(invite.expires_at)}`;
      const emailBtn = accepted
        ? ''
        : `<button class="secondary small" data-email="${esc(invite.id)}">Email invite</button>`;
      return `<article class="invite-row">
        <span><strong>${esc(invite.email)}</strong><br><small>${esc(titleCase(invite.role))} - ${meta}</small></span>
        <input readonly value="${esc(url)}">
        <button class="secondary small" data-copy="${esc(url)}">Copy link</button>
        ${emailBtn}
      </article>`;
    }).join('') : emptyState('No invites have been created for this event.');

    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Invites ${helpLink('invites', 'Invites &amp; Collaborators')}</h2><div class="section-head-actions">${addToggle('Create invite', true)}</div></div>
      <div class="invite-list">${rowsHtml}</div>
      <form class="row-form invite-add" data-add-form hidden>
        <label>Email <input type="email" name="email" required placeholder="promoter@example.com"></label>
        <label>Role ${select('role', roles, 'viewer')}</label>
        <label class="check-label"><input type="checkbox" name="send_email" value="1" checked> Send invitation email</label>
        <button>Create invite</button>
        <button type="button" class="secondary small" data-cancel-add>Cancel</button>
      </form>
    </section>`;

    bindAddToggle(this);

    $('form', this).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      // formData() omits unchecked checkboxes entirely, so coerce to a clean boolean.
      body.send_email = event.target.send_email.checked;
      try {
        const result = await api(`/events/${eventId}/invites`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', {
          message: result.emailed
            ? `Invite emailed to ${body.email}.`
            : `Invite link created: ${appUrl(result.url)}`,
        });
        await refreshSection(this);
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });

    $$('[data-copy]', this).forEach((button) => button.addEventListener('click', async () => {
      if (navigator.clipboard) {
        await navigator.clipboard.writeText(button.dataset.copy);
      } else {
        button.previousElementSibling?.select();
        document.execCommand('copy');
      }
      publish('toast.show', { message: 'Invite link copied.' });
    }));

    $$('[data-email]', this).forEach((button) => button.addEventListener('click', async () => {
      const inviteId = button.dataset.email;
      button.disabled = true;
      const original = button.textContent;
      button.textContent = 'Sending...';
      try {
        await api(`/events/${eventId}/invites/${inviteId}`, { method: 'POST', body: '{}' });
        publish('toast.show', { message: 'Invite email sent.' });
        button.textContent = 'Sent';
        setTimeout(() => { button.textContent = original; button.disabled = false; }, 2000);
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
        button.textContent = original;
        button.disabled = false;
      }
    }));
  }
}


class SettlementForm extends HTMLElement {
  set data(data) {
    this.eventData = data;
    const settlement = data.settlement || {};
    const event = data.event || {};
    const fields = ['gross_ticket_sales','tickets_sold','bar_sales','expenses','band_payouts','promoter_payout','venue_net'];
    const docUrl = event.settlement_doc_url || '';
    const docLink = docUrl && /^https?:/i.test(docUrl)
      ? `<a class="button small secondary" href="${esc(docUrl)}" target="_blank" rel="noopener noreferrer">Open settlement doc &nearr;</a>`
      : '';
    this.innerHTML = `<section class="panel">
      <div class="section-head padded"><h2>Settlement ${helpLink('settlement', 'Settlement')}</h2><div class="inline-actions">${docLink}<button class="secondary small" type="button" data-calc>Calculate venue net</button></div></div>
      <form class="row-form" data-form="doc"><label class="wide">Settlement document <input name="settlement_doc_url" value="${esc(docUrl)}" placeholder="URL or note pointing to the night-of settlement sheet"></label><button class="small">Save link</button></form>
      <form class="row-form" data-form="settlement">${fields.map((field) => `<label>${esc(titleCase(field))}<input name="${esc(field)}" type="number" step="0.01" value="${esc(settlement[field] || 0)}"></label>`).join('')}<label class="wide">Notes <textarea name="notes">${esc(settlement.notes || '')}</textarea></label><button>Save settlement</button></form>
    </section>`;
    const form = $('form[data-form="settlement"]', this);
    const calculate = () => {
      const values = formData(form);
      const venueNet = Number(values.gross_ticket_sales || 0) + Number(values.bar_sales || 0) - Number(values.expenses || 0) - Number(values.band_payouts || 0) - Number(values.promoter_payout || 0);
      form.elements.venue_net.value = venueNet.toFixed(2);
    };
    $('[data-calc]', this).addEventListener('click', calculate);
    ['gross_ticket_sales','bar_sales','expenses','band_payouts','promoter_payout'].forEach((name) => form.elements[name].addEventListener('input', calculate));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventData.event.id}/settlement`, { method: 'POST', body: JSON.stringify(formData(e.target)) });
      await refreshSection(this);
      publish('toast.show', { message: 'Settlement saved.' });
    });
    $('form[data-form="doc"]', this).addEventListener('submit', async (e) => {
      e.preventDefault();
      await api(`/events/${this.eventData.event.id}`, { method: 'PATCH', body: JSON.stringify({ settlement_doc_url: formData(e.target).settlement_doc_url }) });
      await refreshSection(this);
      publish('toast.show', { message: 'Settlement doc link saved.' });
    });
  }
}

customElements.define('pb-task-list', TaskList);
customElements.define('pb-lineup-editor', LineupEditor);
customElements.define('pb-run-sheet', RunSheet);
customElements.define('pb-staffing-manager', StaffingManager);
customElements.define('pb-open-items', OpenItems);
customElements.define('pb-guest-list-manager', GuestListManager);
customElements.define('pb-asset-manager', AssetManager);
customElements.define('pb-invite-manager', InviteManager);
customElements.define('pb-settlement-form', SettlementForm);
