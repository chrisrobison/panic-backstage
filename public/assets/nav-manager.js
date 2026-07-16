// Admin > Navigation — the "Navigation Manager" that edits the nav_items
// table the app shell's sidebar renders from (see nav-shared.js). Three
// panes: a draggable/nestable item list, an edit form for the selected item,
// and a live preview built from the exact same renderNavHtml() the real
// sidebar uses, so the preview can't drift from what actually ships.
import { api, esc, $, $$, openModal, formData, titleCase, publish, PanicElement } from './core.js';
import { buildNavTree, filterNavTree, renderNavHtml } from './nav-shared.js';

// Curated FontAwesome classes already used elsewhere in the app, so picking
// from this list keeps new nav items visually consistent with the rest of
// the sidebar. "Custom…" still allows any class string.
const ICONS = [
  ['fa-solid fa-gauge-high', 'Gauge (Dashboard)'],
  ['fa-solid fa-chart-line', 'Chart line (Reports)'],
  ['fa-solid fa-filter', 'Filter (Leads)'],
  ['fa-solid fa-address-book', 'Address book (Contacts)'],
  ['fa-solid fa-bullhorn', 'Bullhorn (Promote)'],
  ['fa-solid fa-envelope', 'Envelope (Messages)'],
  ['fa-solid fa-inbox', 'Inbox'],
  ['fa-solid fa-box-archive', 'Archive'],
  ['fa-solid fa-paper-plane', 'Paper plane (Outbox)'],
  ['fa-solid fa-envelope-open-text', 'Open envelope (Campaigns)'],
  ['fa-solid fa-rectangle-list', 'List (Lists)'],
  ['fa-solid fa-table-list', 'Table list (ListMaster)'],
  ['fa-solid fa-ticket', 'Ticket (Events)'],
  ['fa-solid fa-list', 'List'],
  ['fa-solid fa-calendar-check', 'Calendar check (Upcoming)'],
  ['fa-solid fa-calendar-days', 'Calendar (Calendar)'],
  ['fa-solid fa-table-columns', 'Columns (Pipeline)'],
  ['fa-solid fa-images', 'Images (Assets)'],
  ['fa-solid fa-gear', 'Gear (Settings)'],
  ['fa-solid fa-sliders', 'Sliders (Preferences)'],
  ['fa-solid fa-user', 'User (Account)'],
  ['fa-solid fa-user-gear', 'User gear (Users)'],
  ['fa-solid fa-user-shield', 'User shield (Admin)'],
  ['fa-solid fa-people-group', 'People group (Staff)'],
  ['fa-solid fa-layer-group', 'Layer group (Templates)'],
  ['fa-solid fa-file-signature', 'File signature (Contracts)'],
  ['fa-solid fa-credit-card', 'Credit card (Payments)'],
  ['fa-solid fa-building', 'Building (Venue)'],
  ['fa-solid fa-database', 'Database (DB Browser)'],
  ['fa-solid fa-clock-rotate-left', 'Clock rotate (History)'],
  ['fa-solid fa-circle-question', 'Circle question (Help)'],
  ['fa-solid fa-book', 'Book'],
  ['fa-solid fa-shield-halved', 'Shield'],
  ['fa-solid fa-bell', 'Bell'],
  ['fa-solid fa-star', 'Star'],
  ['fa-solid fa-flag', 'Flag'],
  ['fa-solid fa-clipboard-list', 'Clipboard list'],
];

class AdminNavigation extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Navigation Manager', blurb: 'Manage the main app navigation items.' });
    this.setLoading('Loading navigation…');
    this.selectedId = null;
    this.draft = null; // pending, unsaved edits to the selected item — preview-only
    await this.load();
  }

  async load() {
    const data = await api('/nav-items');
    this.items = data.items || [];
    this.capabilityKeys = data.capabilities || [];
    if (this.selectedId && !this.items.some((i) => i.id === this.selectedId)) {
      this.selectedId = null;
    }
    this.draft = null;
    this.render();
  }

  topLevel() {
    return this.items.filter((i) => i.parent_id === null).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
  }

  childrenOf(id) {
    return this.items.filter((i) => i.parent_id === id).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
  }

  previewTree() {
    let items = this.items;
    if (this.draft) {
      items = items.map((it) => (it.id === this.draft.id ? { ...it, ...this.draft } : it));
    }
    // The preview shows structure, not per-role visibility — treat every
    // known capability as granted so a capability-gated item still shows up
    // for the admin editing it (there's no "preview as role X" yet).
    const allCaps = Object.fromEntries(this.capabilityKeys.map((k) => [k, true]));
    return filterNavTree(buildNavTree(items), allCaps);
  }

  render() {
    const selected = this.selectedId ? this.items.find((i) => i.id === this.selectedId) : null;
    this.innerHTML = `
      <div class="nav-manager-body">
        <section class="panel nav-manager-list">
          <div class="section-head padded">
            <h2>Navigation Items <span class="pill">${this.items.length} items</span></h2>
            <button type="button" class="small" data-add-root><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Item</button>
          </div>
          <div class="nav-manager-tree" data-tree>${this.renderTree()}</div>
          <p class="muted small padded nav-manager-hint"><i class="fa-solid fa-arrows-up-down" aria-hidden="true"></i> Drag items to reorder. Drop onto the middle of a top-level item to nest as a child.</p>
        </section>
        <section class="panel nav-manager-edit">
          ${selected ? this.renderEditForm(selected) : this.renderEmptyEdit()}
        </section>
        <section class="panel nav-manager-preview">
          <div class="section-head padded"><h2>Live Preview</h2></div>
          <div class="nav-preview-frame">
            <div class="sidebar">
              <div class="brand"><span class="brand-mark" aria-hidden="true"></span><span>Backstage</span></div>
              <nav class="side-nav">${renderNavHtml(this.previewTree())}</nav>
            </div>
          </div>
          <p class="muted small padded">This is a preview of how the navigation will appear in the app.</p>
        </section>
      </div>`;
    $$('.nav-manager-preview .nav-group', this).forEach((g) => g.classList.add('open'));
    this.bindTree();
    this.bindEditForm();
    $('[data-add-root]', this)?.addEventListener('click', () => this.openCreateModal(null));
  }

  // ── Left pane: item tree ──────────────────────────────────────────────
  renderTree() {
    const top = this.topLevel();
    if (!top.length) return '<div class="empty-state padded">No navigation items yet.</div>';
    return top.map((item) => this.renderTreeGroup(item)).join('');
  }

  renderTreeGroup(item) {
    const children = this.childrenOf(item.id);
    if (!children.length) return this.renderRow(item, false);
    return `<div class="nav-row-group" data-group-id="${item.id}">
      ${this.renderRow(item, false, true)}
      <div class="nav-row-children">
        ${children.map((c) => this.renderRow(c, true)).join('')}
        <button type="button" class="nav-row-add-child" data-add-child="${item.id}"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Child Item</button>
      </div>
    </div>`;
  }

  renderRow(item, isChild, hasChevron = false) {
    const selected = item.id === this.selectedId ? ' selected' : '';
    const childClass = isChild ? ' nav-row-child' : '';
    const chevron = hasChevron ? '<i class="nav-row-chevron fa-solid fa-chevron-down" aria-hidden="true"></i>' : '';
    const homeBadge = item.is_home ? '<span class="pill pill-home">Home</span>' : '';
    const hiddenBadge = !item.visible ? '<span class="pill pill-muted">Hidden</span>' : '';
    return `<div class="nav-row${selected}${childClass}" draggable="true" data-row-id="${item.id}" data-parent-id="${item.parent_id ?? ''}">
      ${chevron}
      <span class="nav-row-drag" title="Drag to reorder"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></span>
      <i class="nav-row-icon ${esc(item.icon || 'fa-solid fa-circle')}" aria-hidden="true"></i>
      <span class="nav-row-label">${esc(item.label)}</span>
      ${homeBadge}${hiddenBadge}
      <button type="button" class="nav-row-delete" data-delete-id="${item.id}" title="Delete ${esc(item.label)}" aria-label="Delete ${esc(item.label)}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
    </div>`;
  }

  bindTree() {
    let draggedId = null;
    $$('.nav-row[draggable="true"]', this).forEach((row) => {
      row.addEventListener('click', (event) => {
        if (event.target.closest('.nav-row-delete')) return;
        this.selectedId = Number(row.dataset.rowId);
        this.draft = null;
        this.render();
      });
      row.addEventListener('dragstart', (event) => {
        draggedId = Number(row.dataset.rowId);
        event.dataTransfer.effectAllowed = 'move';
        setTimeout(() => row.classList.add('dragging'), 0);
      });
      row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        $$('.nav-row', this).forEach((r) => r.classList.remove('drag-over-before', 'drag-over-after', 'drag-over-nest'));
        draggedId = null;
      });
      row.addEventListener('dragover', (event) => {
        if (draggedId == null) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const targetId = Number(row.dataset.rowId);
        if (targetId === draggedId) return;
        const rect = row.getBoundingClientRect();
        const offset = (event.clientY - rect.top) / rect.height;
        const isTopLevel = row.dataset.parentId === '';
        let zone;
        if (offset < 0.25) zone = 'before';
        else if (offset > 0.75) zone = 'after';
        else zone = isTopLevel ? 'nest' : (offset < 0.5 ? 'before' : 'after');
        row.classList.remove('drag-over-before', 'drag-over-after', 'drag-over-nest');
        row.classList.add(`drag-over-${zone}`);
        row.dataset.dropZone = zone;
      });
      row.addEventListener('dragleave', () => {
        row.classList.remove('drag-over-before', 'drag-over-after', 'drag-over-nest');
      });
      row.addEventListener('drop', (event) => {
        event.preventDefault();
        const targetId = Number(row.dataset.rowId);
        const zone = row.dataset.dropZone || 'after';
        row.classList.remove('drag-over-before', 'drag-over-after', 'drag-over-nest');
        if (draggedId != null && targetId !== draggedId) this.handleDrop(draggedId, targetId, zone);
        draggedId = null;
      });
    });
    $$('[data-delete-id]', this).forEach((btn) => btn.addEventListener('click', (event) => {
      event.stopPropagation();
      this.deleteItemConfirm(Number(btn.dataset.deleteId));
    }));
    $$('[data-add-child]', this).forEach((btn) => btn.addEventListener('click', (event) => {
      event.stopPropagation();
      this.openCreateModal(Number(btn.dataset.addChild));
    }));
  }

  handleDrop(draggedId, targetId, zone) {
    const dragged = this.items.find((i) => i.id === draggedId);
    const target = this.items.find((i) => i.id === targetId);
    if (!dragged || !target) return;

    let newParentId;
    if (zone === 'nest') {
      if (target.parent_id !== null) return; // can't nest under a child (2 levels max)
      if (this.items.some((i) => i.parent_id === dragged.id)) return; // dragged has its own children
      newParentId = target.id;
    } else {
      newParentId = target.parent_id;
      // A parent-with-children can't become someone's child by reordering
      // next to a child row either — guard the same rule here.
      if (newParentId !== null && this.items.some((i) => i.parent_id === dragged.id)) return;
    }

    const siblings = this.items
      .filter((i) => i.parent_id === newParentId && i.id !== dragged.id)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const targetIdx = siblings.findIndex((i) => i.id === target.id);
    let insertAt;
    if (zone === 'nest') insertAt = siblings.length;
    else if (zone === 'before') insertAt = Math.max(targetIdx, 0);
    else insertAt = targetIdx + 1;
    siblings.splice(insertAt, 0, dragged);

    const updates = siblings.map((it, idx) => ({ id: it.id, parent_id: newParentId, sort_order: (idx + 1) * 10 }));
    this.saveReorder(updates);
  }

  async saveReorder(updates) {
    try {
      await api('/nav-items/reorder', { method: 'POST', body: JSON.stringify({ items: updates }) });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async deleteItemConfirm(id) {
    const item = this.items.find((i) => i.id === id);
    if (!item) return;
    const hasChildren = this.items.some((i) => i.parent_id === id);
    const msg = hasChildren
      ? `Delete "${item.label}" and its child items? This can't be undone.`
      : `Delete "${item.label}"? This can't be undone.`;
    if (!window.confirm(msg)) return;
    try {
      await api(`/nav-items/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `Deleted "${item.label}".` });
      if (this.selectedId === id) this.selectedId = null;
      await this.load();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Middle pane: edit form ────────────────────────────────────────────
  renderEmptyEdit() {
    return `<div class="section-head padded"><h2>Edit Navigation Item</h2></div>
      <div class="empty-state padded">Select an item on the left to edit it, or add a new one.</div>`;
  }

  renderEditForm(item) {
    const hasChildren = this.items.some((i) => i.parent_id === item.id);
    const parent = item.parent_id ? this.items.find((i) => i.id === item.parent_id) : null;
    const breadcrumb = parent
      ? `${esc(parent.label)} <i class="fa-solid fa-angle-right" aria-hidden="true"></i> <strong>${esc(item.label)}</strong>`
      : `<strong>${esc(item.label)}</strong>`;
    const iconKnown = ICONS.some(([value]) => value === item.icon);
    const iconOptions = ICONS.map(([value, label]) => `<option value="${esc(value)}" ${item.icon === value ? 'selected' : ''}>${esc(label)}</option>`).join('')
      + `<option value="__custom__" ${!iconKnown ? 'selected' : ''}>Custom…</option>`;
    const parentOptions = `<option value="">— Top level —</option>` + this.topLevel()
      .filter((i) => i.id !== item.id)
      .map((i) => `<option value="${i.id}" ${item.parent_id === i.id ? 'selected' : ''}>${esc(i.label)}</option>`).join('');
    const capOptions = `<option value="">No restriction</option>` + this.capabilityKeys
      .map((c) => `<option value="${esc(c)}" ${item.capability === c ? 'selected' : ''}>${esc(titleCase(c))}</option>`).join('');
    const level = item.parent_id ? 2 : 1;

    return `
      <div class="section-head padded">
        <h2>Edit Navigation Item</h2>
        <button type="button" class="small danger" data-delete-current="${item.id}"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete Item</button>
      </div>
      <div class="nav-edit-breadcrumb padded muted">${breadcrumb}</div>
      <form class="grid-form padded" data-nav-edit-form data-item-id="${item.id}">
        <label class="wide">Label <span class="req">*</span><input type="text" name="label" value="${esc(item.label)}" required></label>
        <label>Icon <span class="req">*</span><select name="icon">${iconOptions}</select></label>
        <label${iconKnown ? ' hidden' : ''} data-icon-custom-wrap>Custom icon class<input type="text" name="icon_custom" value="${esc(!iconKnown ? (item.icon || '') : '')}" placeholder="fa-solid fa-star"></label>
        <label class="wide">Parent Item${hasChildren ? ' <span class="muted small">(has its own children — can\'t be nested)</span>' : ''}
          <select name="parent_id" ${hasChildren ? 'disabled' : ''}>${parentOptions}</select>
        </label>
        <p class="wide muted small nav-level-readout">Level <strong data-level-readout>${level === 1 ? 'Level 1 (Top level)' : 'Level 2 (Child)'}</strong> — this item will appear ${level === 1 ? 'in the main sidebar' : 'indented under its parent'} in the navigation.</p>
        <label class="wide">Route / Link<input type="text" name="link" value="${esc(item.link || '')}" placeholder="dashboard, admin-users, or https://example.com/…"></label>
        <p class="wide muted small">An internal route key (e.g. <code>dashboard</code>, <code>admin-users</code>) or an external <code>https://</code> URL. Leave blank for a pure grouping item with no page of its own.</p>
        <label class="wide toggle-row"><input type="checkbox" name="visible" ${item.visible ? 'checked' : ''}> Show in navigation</label>
        <p class="wide muted small">Turn off to hide from the sidebar without deleting it.</p>
        ${item.parent_id === null ? `<button type="button" class="small secondary wide" data-add-child="${item.id}"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Child Item</button>` : ''}
        <details class="wide nav-advanced">
          <summary>Advanced Options</summary>
          <div class="grid-form">
            <label>Open in
              <span class="radio-row">
                <label><input type="radio" name="open_in_new_window" value="0" ${!item.open_in_new_window ? 'checked' : ''}> Same window</label>
                <label><input type="radio" name="open_in_new_window" value="1" ${item.open_in_new_window ? 'checked' : ''}> New window</label>
              </span>
            </label>
            <label>Permission (optional)<select name="capability">${capOptions}</select></label>
            <label>Order<input type="number" name="sort_order" value="${item.sort_order}"></label>
            <label class="toggle-row"><input type="checkbox" name="is_home" ${item.is_home ? 'checked' : ''}> Default landing page</label>
          </div>
        </details>
        <div class="wide nav-edit-actions">
          <button type="submit">Save Item</button>
        </div>
      </form>`;
  }

  bindEditForm() {
    const form = $('[data-nav-edit-form]', this);
    if (!form) return;

    const iconSelect = $('select[name="icon"]', form);
    const iconCustomWrap = $('[data-icon-custom-wrap]', form);
    iconSelect?.addEventListener('change', () => {
      if (iconCustomWrap) iconCustomWrap.hidden = iconSelect.value !== '__custom__';
    });

    const parentSelect = $('select[name="parent_id"]', form);
    const levelReadout = $('[data-level-readout]', this);
    parentSelect?.addEventListener('change', () => {
      if (levelReadout) levelReadout.textContent = parentSelect.value ? 'Level 2 (Child)' : 'Level 1 (Top level)';
    });

    form.addEventListener('input', () => this.updateDraftFromForm(form));
    form.addEventListener('change', () => this.updateDraftFromForm(form));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.saveEditForm(form);
    });

    $('[data-delete-current]', this)?.addEventListener('click', (event) => {
      this.deleteItemConfirm(Number(event.currentTarget.dataset.deleteCurrent));
    });
    $$('[data-add-child]', this).forEach((btn) => btn.addEventListener('click', () => this.openCreateModal(Number(btn.dataset.addChild))));
  }

  readFormFields(form) {
    const fd = formData(form);
    const icon = fd.icon === '__custom__' ? ((fd.icon_custom || '').trim() || 'fa-solid fa-circle') : fd.icon;
    return {
      label: (fd.label || '').trim(),
      icon,
      link: (fd.link || '').trim(),
      parent_id: fd.parent_id ? Number(fd.parent_id) : null,
      capability: fd.capability || null,
      visible: form.querySelector('[name="visible"]')?.checked ? 1 : 0,
      open_in_new_window: fd.open_in_new_window === '1' ? 1 : 0,
      sort_order: Number(fd.sort_order) || 0,
      is_home: form.querySelector('[name="is_home"]')?.checked ? 1 : 0,
    };
  }

  updateDraftFromForm(form) {
    const id = Number(form.dataset.itemId);
    this.draft = { id, ...this.readFormFields(form) };
    const nav = $('.nav-manager-preview .side-nav', this);
    if (nav) {
      nav.innerHTML = renderNavHtml(this.previewTree());
      $$('.nav-manager-preview .nav-group', this).forEach((g) => g.classList.add('open'));
    }
  }

  async saveEditForm(form) {
    const id = Number(form.dataset.itemId);
    const fields = this.readFormFields(form);
    if (!fields.label) {
      publish('toast.show', { message: 'Label is required', tone: 'error' });
      return;
    }
    try {
      await api(`/nav-items/${id}`, { method: 'PATCH', body: JSON.stringify(fields) });
      publish('toast.show', { message: 'Navigation item saved.' });
      await this.load();
      this.selectedId = id;
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // ── Add item / add child modal ────────────────────────────────────────
  openCreateModal(parentId) {
    const { dialog, close } = openModal({
      title: parentId ? 'Add Child Item' : 'Add Navigation Item',
      bodyHtml: `<form class="grid-form padded" data-form="new-nav-item">
        <label class="wide">Label <span class="req">*</span><input type="text" name="label" required></label>
        <label class="wide">Route / Link<input type="text" name="link" placeholder="dashboard, admin-users, or https://…"></label>
        <div class="wide"><button type="submit">Add</button></div>
      </form>`,
      focus: '[name="label"]',
    });
    const form = $('[data-form="new-nav-item"]', dialog);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = formData(form);
      const label = (fd.label || '').trim();
      if (!label) return;
      const siblings = parentId ? this.childrenOf(parentId) : this.topLevel();
      const nextOrder = siblings.length ? Math.max(...siblings.map((s) => s.sort_order)) + 10 : 10;
      try {
        const res = await api('/nav-items', {
          method: 'POST',
          body: JSON.stringify({
            label,
            link: (fd.link || '').trim(),
            parent_id: parentId ?? '',
            sort_order: nextOrder,
          }),
        });
        publish('toast.show', { message: `"${label}" added.` });
        close();
        this.selectedId = res.id;
        await this.load();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}
customElements.define('pb-admin-navigation', AdminNavigation);
