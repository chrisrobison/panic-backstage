// Shared constants/helpers for the standalone Tasks app (tasks-ui.png) — the
// document sidebar shell (tasks-shell.js) and its four view components
// (task-list-view.js / task-board-view.js / task-timeline-view.js /
// task-calendar-view.js) plus the detail panel (task-detail-panel.js) all
// import from here so status/priority vocabulary and rendering stay
// consistent across every view, the same role core.js plays for the rest of
// the app.
import { esc, titleCase } from '../core.js';

// Curated icon choices for a task document's sidebar glyph — same idea as
// nav-manager.js's ICONS list (a short, pre-vetted set so a document's icon
// stays visually consistent with the rest of the app instead of every user
// picking an arbitrary FontAwesome class), rendered as an actual clickable
// icon grid in the "New task document" modal rather than a text dropdown.
// The free-text `icon` field stays available alongside it for anything not
// in this list.
export const DOCUMENT_ICONS = [
  ['fa-solid fa-list-check', 'Checklist'],
  ['fa-solid fa-clipboard-list', 'Clipboard'],
  ['fa-solid fa-table-list', 'Table'],
  ['fa-solid fa-diagram-project', 'Project'],
  ['fa-solid fa-briefcase', 'Briefcase'],
  ['fa-solid fa-rocket', 'Rocket'],
  ['fa-solid fa-bullhorn', 'Marketing'],
  ['fa-solid fa-chart-line', 'Chart'],
  ['fa-solid fa-calendar-days', 'Calendar'],
  ['fa-solid fa-users', 'Team'],
  ['fa-solid fa-people-group', 'People'],
  ['fa-solid fa-building', 'Venue'],
  ['fa-solid fa-file-signature', 'Contract'],
  ['fa-solid fa-envelope', 'Email'],
  ['fa-solid fa-headset', 'Support'],
  ['fa-solid fa-code', 'Engineering'],
  ['fa-solid fa-paintbrush', 'Design'],
  ['fa-solid fa-camera', 'Photo'],
  ['fa-solid fa-video', 'Video'],
  ['fa-solid fa-music', 'Music'],
  ['fa-solid fa-utensils', 'Catering'],
  ['fa-solid fa-plane', 'Travel'],
  ['fa-solid fa-truck', 'Logistics'],
  ['fa-solid fa-box', 'Inventory'],
  ['fa-solid fa-dollar-sign', 'Finance'],
  ['fa-solid fa-shield-halved', 'Security'],
  ['fa-solid fa-lightbulb', 'Ideas'],
  ['fa-solid fa-star', 'Star'],
  ['fa-solid fa-flag', 'Flag'],
  ['fa-solid fa-bolt', 'Urgent'],
];

export const TASK_STATUSES = ['not_started', 'in_progress', 'done'];
const TASK_STATUS_LABELS = { not_started: 'Not Started', in_progress: 'In Progress', done: 'Done' };
// Reuses this app's existing .badge.status-* hue vocabulary (see app.css)
// rather than inventing new colors: gray/amber/green already read as
// "not started / in progress / done" everywhere else in the app.
const TASK_STATUS_BADGE_CLASS = { not_started: 'status-empty', in_progress: 'status-needs_assets', done: 'status-confirmed' };

export function statusLabel(status) {
  return TASK_STATUS_LABELS[status] || titleCase(status);
}

export function statusBadge(status) {
  return `<span class="badge ${esc(TASK_STATUS_BADGE_CLASS[status] || 'status-empty')}">${esc(statusLabel(status))}</span>`;
}

export const TASK_PRIORITIES = ['low', 'medium', 'high', 'urgent'];
const PRIORITY_LABELS = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };

export function priorityLabel(priority) {
  return PRIORITY_LABELS[priority] || titleCase(priority);
}

export function priorityFlag(priority) {
  return `<span class="tk-priority tk-priority-${esc(priority || 'medium')}"><i class="fa-solid fa-flag" aria-hidden="true"></i> ${esc(priorityLabel(priority))}</span>`;
}

export const DOC_STATUSES = ['on_track', 'at_risk', 'off_track', 'complete'];
const DOC_STATUS_LABELS = { on_track: 'On Track', at_risk: 'At Risk', off_track: 'Off Track', complete: 'Complete' };
const DOC_STATUS_BADGE_CLASS = { on_track: 'status-confirmed', at_risk: 'status-needs_assets', off_track: 'status-canceled', complete: 'status-advanced' };

export function docStatusLabel(status) {
  return DOC_STATUS_LABELS[status] || titleCase(status);
}

export function docStatusBadge(status) {
  return `<span class="badge ${esc(DOC_STATUS_BADGE_CLASS[status] || 'status-empty')}">${esc(docStatusLabel(status))}</span>`;
}

// ── Avatars — same hash-a-color-from-id approach as listmaster.js's
// AVATAR_COLORS/initials()/avatarColor(), generalized from contacts
// ({first_name,last_name}) to app users ({id,name}). ─────────────────────
const AVATAR_COLORS = ['#2563eb', '#7c3aed', '#0f8f46', '#d99100', '#dc2626', '#0891b2', '#c026d3', '#4f46e5'];

function hashSeed(value) {
  let h = 0;
  const s = String(value || '');
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return h;
}

export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  const first = parts[0][0] || '';
  const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
  return (first + last).toUpperCase();
}

export function avatarColor(seed) {
  return AVATAR_COLORS[hashSeed(seed) % AVATAR_COLORS.length];
}

export function avatar(user, big) {
  if (!user) return `<span class="tk-avatar tk-avatar-unassigned${big ? ' tk-avatar-lg' : ''}" title="Unassigned"><i class="fa-regular fa-user" aria-hidden="true"></i></span>`;
  return `<span class="tk-avatar${big ? ' tk-avatar-lg' : ''}" style="background:${esc(avatarColor(user.id ?? user.name))}" title="${esc(user.name)}">${esc(initials(user.name))}</span>`;
}

// ── Dates ─────────────────────────────────────────────────────────────────
// Tasks store plain DATE columns (no time component); parse as UTC midnight
// so the displayed day never shifts a day back/forward from the local
// timezone offset (the same footgun addressed by db_timestamp_to_epoch() on
// the PHP side for full timestamps).
export function parseDateOnly(value) {
  if (!value) return null;
  const d = new Date(`${value}T00:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function fmtDate(value) {
  const d = parseDateOnly(value);
  return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '—';
}

export function isOverdue(task) {
  if (!task.due_date || task.status === 'done') return false;
  const due = parseDateOnly(task.due_date);
  const today = new Date(); today.setHours(0, 0, 0, 0);
  return !!due && due < today;
}

// ── Hierarchy ─────────────────────────────────────────────────────────────
// Tasks arrive as a flat list (see src/Tasks/Items.php::index() — same
// "flat rows in, tree built client-side" approach used elsewhere in this
// app); this builds the parent/child tree and computes WBS numbering
// (1, 1.1, 1.1.4, …) from tree position, matching tasks-ui.png's "#"
// column. Sibling order is sort_order then id.
export function buildHierarchy(tasks) {
  const byParent = new Map();
  tasks.forEach((t) => {
    const key = t.parent_task_id || null;
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key).push(t);
  });
  for (const list of byParent.values()) list.sort((a, b) => (a.sort_order - b.sort_order) || (a.id - b.id));

  function walk(parentId, prefix, depth) {
    const kids = byParent.get(parentId) || [];
    return kids.map((task, i) => {
      const wbs = prefix ? `${prefix}.${i + 1}` : `${i + 1}`;
      return { task, wbs, depth, children: walk(task.id, wbs, depth + 1) };
    });
  }
  return walk(null, '', 0);
}

/** Depth-first flatten, skipping the children of any node whose task id is in `collapsed`. */
export function flattenVisible(nodes, collapsed) {
  const out = [];
  for (const node of nodes) {
    out.push(node);
    if (node.children.length && !collapsed.has(node.task.id)) {
      out.push(...flattenVisible(node.children, collapsed));
    }
  }
  return out;
}

export function progressOf(tasks) {
  const total = tasks.length;
  const done = tasks.filter((t) => t.status === 'done').length;
  return { total, done, pct: total ? Math.round((done / total) * 100) : 0 };
}
