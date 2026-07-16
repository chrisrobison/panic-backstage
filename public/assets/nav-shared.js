// Single source of truth for turning a flat nav_items list (from
// GET /api/nav-items) into the sidebar's nested markup. Used by BOTH the
// real app shell (app.js) and the Navigation Manager's "Live Preview" pane
// (nav-manager.js) — one render function, so the preview can never drift
// from what actually ships in the real sidebar.
import { esc } from './core.js';

/** Nest a flat nav_items array into a tree, sorted by sort_order (ties by id). */
export function buildNavTree(items) {
  const byId = new Map();
  (items || []).forEach((item) => byId.set(item.id, { ...item, children: [] }));
  const roots = [];
  byId.forEach((item) => {
    const parent = item.parent_id != null ? byId.get(item.parent_id) : null;
    if (parent) parent.children.push(item);
    else roots.push(item);
  });
  const bySort = (a, b) => (a.sort_order - b.sort_order) || (a.id - b.id);
  byId.forEach((item) => item.children.sort(bySort));
  roots.sort(bySort);
  return roots;
}

/**
 * Drop invisible items and items the current user's capabilities don't
 * allow, then drop any parent left with zero children AND no link of its
 * own — a pure grouping node (e.g. "Admin") whose entire contents just got
 * filtered out. Generalizes what used to be a one-off "hide the Admin group"
 * check in app.js so it applies to any group, automatically.
 */
export function filterNavTree(tree, capabilities = {}) {
  const allowed = (item) => {
    if (!item.visible) return false;
    if (item.capability && !capabilities[item.capability]) return false;
    return true;
  };
  const walk = (nodes) => nodes
    .filter(allowed)
    .map((item) => ({ ...item, children: walk(item.children || []) }))
    .filter((item) => item.link || item.children.length > 0);
  return walk(tree || []);
}

/**
 * Render a nav tree into the exact markup shape app.css already styles:
 * <a data-nav="..."> for leaf items, .nav-group > .nav-parent + .nav-children
 * for a grouping parent. Active-state (.active on the current route's link
 * and its owning group) is applied afterward by the caller, same as today —
 * this function is purely structural.
 */
export function renderNavHtml(tree) {
  return (tree || []).map(renderItem).join('');
}

// Slugified label used as the group's data-group/data-group-toggle key.
// Deliberately derived from the label (not the row id) so a group's
// collapsed/open state — persisted in localStorage by setupNavGroups() —
// survives this migration to data-driven nav for existing users (the old
// hardcoded markup used the same slug-like strings: "events", "settings",
// "admin", "messages"). Two groups that happen to share a label share
// persisted open/closed state too — a negligible edge case, not worth a
// uniqueness scheme for v1.
function slug(label) {
  return String(label || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '') || 'group';
}

function renderItem(item) {
  const hasChildren = item.children && item.children.length > 0;
  const icon = esc(item.icon || 'fa-solid fa-circle');
  const label = esc(item.label || '');
  // The unread-messages badge is the one dynamic-per-item affordance kept
  // outside the generic data model (v1 has no general badge system) — it
  // mirrors today's hardcoded markup: Inbox itself, and its parent group.
  const hasBadge = item.link === 'inbox' || (hasChildren && item.children.some((c) => c.link === 'inbox'));
  const badge = hasBadge ? '<span class="nav-badge" data-inbox-badge hidden></span>' : '';

  if (!hasChildren) {
    const link = item.link || '';
    const external = /^https?:\/\//i.test(link);
    const href = external ? esc(link) : `#${esc(link)}`;
    const navAttr = external ? '' : ` data-nav="${esc(link)}"`;
    const target = external || item.open_in_new_window ? ' target="_blank" rel="noopener"' : '';
    return `<a${navAttr} href="${href}" title="${label}"${target}><i class="${icon}" aria-hidden="true"></i>${label}${badge}</a>`;
  }

  const groupKey = slug(item.label);
  const childrenHtml = item.children.map(renderItem).join('');
  return `<div class="nav-group" data-group="${groupKey}">
      <button class="nav-parent" type="button" data-group-toggle="${groupKey}" aria-expanded="false" title="${label}"><i class="${icon}" aria-hidden="true"></i><span class="nav-parent-label">${label}</span>${badge}<i class="nav-chevron fa-solid fa-chevron-right" aria-hidden="true"></i></button>
      <div class="nav-children">${childrenHtml}</div>
    </div>`;
}
