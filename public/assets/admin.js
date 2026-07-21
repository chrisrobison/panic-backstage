import { esc, titleCase, publish, api, formData, badge, option, select, can, table, PanicElement, addToggle, roomTone, $, $$ } from './core.js';


// ── Admin page ───────────────────────────────────────────────────────────────
// Login accounts, venue/rooms, staff roster, event templates, contracts,
// payments, wizard defaults, the DB browser/history tools, and the nav
// manager — see docs/ops-manual.html's Admin chapter for the full writeup
// of each tab. Admin-only — sidebar entry is hidden by
// AppShell.applyCapabilities() when the user lacks admin caps.
//
// 'duplicates' (pb-user-duplicates) is intentionally left out of this list —
// the one-time duplicate-account cleanup it was built for is done and it's
// not a tab anyone needs day to day. The component itself is untouched, so
// it's a one-line change to bring back if it's ever needed again.

const ADMIN_TABS = [
  { key: 'users',     title: 'Users',     icon: 'fa-user-gear' },
  { key: 'staff',     title: 'Staff',     icon: 'fa-people-group' },
  { key: 'templates', title: 'Templates', icon: 'fa-layer-group' },
  { key: 'contracts', title: 'Contracts', icon: 'fa-file-signature' },
  { key: 'payments',  title: 'Payments',  icon: 'fa-credit-card' },
  { key: 'wizard',    title: 'Wizard',    icon: 'fa-wand-magic-sparkles' },
  { key: 'venue',     title: 'Venue',     icon: 'fa-building' },
  { key: 'settings',  title: 'App Settings', icon: 'fa-sliders' },
  { key: 'db',        title: 'DB Browser', icon: 'fa-database' },
  { key: 'db-history', title: 'DB History', icon: 'fa-clock-rotate-left' },
  { key: 'navigation', title: 'Navigation', icon: 'fa-bars' },
];


class AdminPage extends PanicElement {
  connect() {
    this.tab = ADMIN_TABS.find((t) => t.key === this.initialTab) ? this.initialTab : 'users';
    publish('page.context', { title: 'Admin', blurb: 'Manage login accounts, the staff roster, event templates, and contract sections.' });
    this.render();
  }

  render() {
    this.innerHTML = `
      <nav class="workspace-tabs tabs admin-tabs">
        ${ADMIN_TABS.map((t) => `<a data-admin-tab="${esc(t.key)}" href="#admin-${esc(t.key)}" class="${t.key === this.tab ? 'active' : ''}"><i class="fa-solid ${esc(t.icon)}" aria-hidden="true"></i> ${esc(t.title)}</a>`).join('')}
      </nav>
      <div class="admin-outlet"></div>
    `;
    $$('[data-admin-tab]', this).forEach((link) => link.addEventListener('click', (event) => {
      event.preventDefault();
      this.tab = link.dataset.adminTab;
      this.render();
    }));
    const outlet = $('.admin-outlet', this);
    const tag = { users: 'pb-admin-users', duplicates: 'pb-user-duplicates', staff: 'pb-admin-staff', templates: 'pb-admin-templates', contracts: 'pb-admin-contracts', payments: 'pb-payment-settings', wizard: 'pb-admin-wizard-defaults', venue: 'pb-admin-venue', settings: 'pb-admin-app-settings', db: 'pb-admin-db-browser', 'db-history': 'pb-admin-db-history', navigation: 'pb-admin-navigation' }[this.tab];
    outlet.replaceChildren(document.createElement(tag));
  }
}


class AdminUsers extends PanicElement {
  async connect() {
    this.setLoading('Loading users');
    try {
      this.data = await api('/users');
      this.renderList();
    } catch (error) {
      this.showError(error);
    }
  }

  renderList() {
    const allUsers = this.data.users || [];
    const roles = this.data.roles || [];
    const pending = allUsers.filter((u) => u.access_status === 'requested');
    const users = allUsers.filter((u) => u.access_status !== 'requested');
    const roleOptions = (selected) => roles.map((r) => `<option value="${esc(r)}" ${r === selected ? 'selected' : ''}>${esc(titleCase(r))}</option>`).join('');

    const pendingPanel = pending.length ? `
      <article class="panel">
        <div class="section-head padded"><h2>Pending access requests</h2><span class="muted">${pending.length} awaiting review</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Situation</th><th>Requested</th><th>Approve as</th><th></th></tr></thead>
          <tbody>
            ${pending.map((u) => `<tr>
              <td>${esc(u.name)} <span class="badge">Requested</span></td>
              <td>${esc(u.email)}</td>
              <td>${esc(u.phone || '—')}</td>
              <td class="muted">${u.request_notes ? esc(u.request_notes) : '—'}</td>
              <td class="muted">${esc(u.created_at ? new Date(u.created_at).toLocaleDateString() : '')}</td>
              <td><select data-approve-role="${esc(u.id)}">${roleOptions('viewer')}</select></td>
              <td class="row-actions">
                <button class="small" data-approve="${esc(u.id)}" data-name="${esc(u.name)}">Approve &amp; send link</button>
                <button class="small danger" data-dismiss="${esc(u.id)}" data-name="${esc(u.name)}">Dismiss</button>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </article>` : '';

    this.innerHTML = `
      ${pendingPanel}
      <article class="panel">
        <div class="section-head padded">
          <h2>Login Accounts</h2>
          <div class="section-head-actions">
            <span class="muted">${users.length} total</span>
            ${addToggle('Add user', true)}
          </div>
        </div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Auth</th><th>Events</th></tr></thead>
          <tbody>
            ${users.map((u) => `<tr class="clickable-row" data-user-id="${esc(u.id)}">
              <td>${esc(u.name)}</td>
              <td>${esc(u.email)}</td>
              <td><span class="badge">${esc(titleCase(u.role))}</span></td>
              <td>${Number(u.has_password) ? '<span class="muted">Password</span>' : '<span class="muted">—</span>'}${Number(u.passkey_count) ? ` &middot; ${esc(u.passkey_count)} passkey${Number(u.passkey_count) === 1 ? '' : 's'}` : ''}</td>
              <td>${esc(u.owned_event_count || 0)} owned &middot; ${esc(u.collaborator_event_count || 0)} collab</td>
            </tr>`).join('') || '<tr><td colspan="5"><div class="empty-state">No users yet — use the + above to add your first login.</div></td></tr>'}
          </tbody>
        </table>
      </article>
    `;
    $('[data-add]', this)?.addEventListener('click', () => this.openUserModal(null));
    $$('tr[data-user-id]', this).forEach((row) => row.addEventListener('click', (e) => {
      if (e.target.closest('a, button')) return;
      this.openUserModal((this.data.users || []).find((u) => Number(u.id) === Number(row.dataset.userId)));
    }));
    $$('[data-approve]', this).forEach((b) => b.addEventListener('click', () => {
      const role = $(`[data-approve-role="${b.dataset.approve}"]`, this)?.value || 'viewer';
      this.approve(Number(b.dataset.approve), b.dataset.name, role);
    }));
    $$('[data-dismiss]', this).forEach((b) => b.addEventListener('click', () => this.dismiss(Number(b.dataset.dismiss), b.dataset.name)));
  }

  async approve(id, name, role) {
    try {
      await api(`/users/${id}/approve`, { method: 'POST', body: JSON.stringify({ role }) });
      publish('toast.show', { message: `${name} approved — a login link was emailed.`, tone: 'success' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async invite(id, name) {
    try {
      await api(`/users/${id}/invite`, { method: 'POST' });
      publish('toast.show', { message: `Invite emailed to ${name}.`, tone: 'success' });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async dismiss(id, name) {
    if (!confirm(`Dismiss the access request from ${name}? This deletes the request.`)) return;
    try {
      await api(`/users/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `Request from ${name} dismissed.`, tone: 'info' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  // Add (user === null) and edit share one modal, mirroring AdminStaff's
  // openStaffModal(). Invite/Delete — previously separate per-row buttons —
  // live inside the edit modal instead of the table now.
  openUserModal(user = null) {
    const isEdit = Boolean(user && user.id);
    const u = user || {};
    const roles = this.data.roles || [];
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>${isEdit ? 'Edit user' : 'Add user'}</h2><button class="small secondary" data-close type="button">Close</button></div>
      <form class="grid-form padded" data-form="user">
        <label>Name <input name="name" required value="${esc(u.name || '')}" placeholder="Full name"></label>
        <label>Email <input type="email" name="email" required value="${esc(u.email || '')}" placeholder="user@example.com"></label>
        <label>Role ${select('role', roles, u.role || 'viewer')}</label>
        <label>${isEdit ? 'Reset password' : 'Password'} <input type="password" name="password" placeholder="${isEdit ? 'Leave blank to keep current' : 'Optional — they can also use email link'}"></label>
        ${isEdit ? `<p class="muted wide">${Number(u.has_password) ? 'Password is set.' : 'No password set — user can sign in via passkey or email link.'} ${Number(u.passkey_count)} passkey${Number(u.passkey_count) === 1 ? '' : 's'} registered.</p>` : ''}
        <div class="wide form-actions">
          <button type="submit">${isEdit ? 'Save' : 'Add user'}</button>
          ${isEdit ? '<button type="button" class="secondary" data-invite title="Email a fresh sign-in link">Send invite</button>' : ''}
          ${isEdit ? '<button type="button" class="danger" data-delete>Delete</button>' : ''}
        </div>
      </form>
      ${isEdit ? '<div class="section-head padded"><h2>Email addresses</h2></div><div class="padded" data-emails-mount></div>' : ''}
    </div>`;
    document.body.appendChild(dialog);
    if (isEdit) {
      const emailsEl = document.createElement('pb-user-emails');
      emailsEl.user = u;
      $('[data-emails-mount]', dialog).appendChild(emailsEl);
    }
    const close = () => { dialog.remove(); document.removeEventListener('keydown', onEsc); };
    function onEsc(event) { if (event.key === 'Escape') close(); }
    document.addEventListener('keydown', onEsc);
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('input[name="name"]', dialog)?.focus();

    $('[data-form="user"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      try {
        if (isEdit) {
          await api(`/users/${u.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'User updated.' });
        } else {
          await api('/users', { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: `User ${body.name} created.` });
        }
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });

    if (isEdit) {
      $('[data-invite]', dialog).addEventListener('click', () => this.invite(u.id, u.name));
      $('[data-delete]', dialog).addEventListener('click', async () => {
        if (!confirm(`Delete user ${u.name}? This cannot be undone.`)) return;
        try {
          await api(`/users/${u.id}`, { method: 'DELETE' });
          publish('toast.show', { message: `${u.name} deleted.` });
          close();
          this.connect();
        } catch (err) {
          publish('toast.show', { message: err.message, tone: 'error' });
        }
      });
    }
  }
}


class AdminStaff extends PanicElement {
  async connect() {
    this.setLoading('Loading staff roster');
    try {
      this.data = await api('/staff-members');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const staff = this.data.staff || [];
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded">
          <h2>Staff Roster</h2>
          <div class="section-head-actions">
            <span class="muted">${staff.filter((s) => Number(s.active)).length} active &middot; ${staff.length} total</span>
            ${addToggle('Add staff member', true)}
          </div>
        </div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Default role</th><th>Type</th><th>Contact</th><th>Rate</th><th>Hired</th><th>Login</th><th>Status</th><th></th></tr></thead>
          <tbody>
            ${staff.map((s) => `<tr class="${Number(s.active) ? '' : 'muted-row'}">
              <td><strong>${esc(s.name)}</strong>${s.pronoun ? ` <small class="muted">(${esc(s.pronoun)})</small>` : ''}${s.position ? `<br><small>${esc(s.position)}</small>` : ''}${s.notes ? `<br><small class="muted">${esc(s.notes)}</small>` : ''}</td>
              <td><span class="badge">${esc(titleCase(s.default_role))}</span></td>
              <td><span class="badge ${s.employment_type === 'contractor' ? 'status-amber' : 'status-blue'}">${esc(titleCase(s.employment_type || 'employee'))}</span></td>
              <td>${s.email ? esc(s.email) : ''}${s.email && s.phone ? '<br>' : ''}${s.phone ? esc(s.phone) : ''}</td>
              <td>${s.hourly_rate ? `$${esc(Number(s.hourly_rate).toFixed(2))}/hr` : '—'}</td>
              <td>${s.hire_date ? esc(String(s.hire_date).slice(0, 10)) : '<span class="muted">—</span>'}</td>
              <td>${s.user_name ? esc(s.user_name) : '<span class="muted">—</span>'}</td>
              <td>${Number(s.active) ? '<span class="badge status-confirmed">Active</span>' : '<span class="badge status-canceled">Inactive</span>'}</td>
              <td class="row-actions">
                <button class="small secondary" data-edit="${esc(s.id)}">Edit</button>
                <button class="small danger" data-delete="${esc(s.id)}" data-name="${esc(s.name)}">Delete</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="9"><div class="empty-state">No staff yet — use the + above to add your first crew member.</div></td></tr>'}
          </tbody>
        </table>
      </article>
    `;
    $('[data-add]', this)?.addEventListener('click', () => this.openStaffModal(null));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openStaffModal((this.data.staff || []).find((row) => Number(row.id) === Number(b.dataset.edit)))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
  }

  // Add (staff === null) and edit share one modal. New crew members default to
  // Active; submitting POSTs or PATCHes then reloads the roster.
  openStaffModal(staff = null) {
    const isEdit = Boolean(staff && staff.id);
    const s = staff || {};
    const roles = this.data.roles || [];
    const users = this.data.users || [];
    const active = isEdit ? Number(s.active) : 1;
    const userOpts = `<option value="">— No login linked —</option>${users.map((u) => `<option value="${esc(u.id)}" ${Number(s.user_id) === Number(u.id) ? 'selected' : ''}>${esc(u.name)} (${esc(u.email)})</option>`).join('')}`;
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>${isEdit ? 'Edit staff member' : 'Add staff member'}</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="staff">
        <label>Name <input name="name" required value="${esc(s.name || '')}" placeholder="Full name"></label>
        <label>Pronoun <input name="pronoun" value="${esc(s.pronoun || '')}" placeholder="they/them, she/her, …"></label>
        <label>Default role ${select('default_role', roles, s.default_role || 'security')}</label>
        <label>Type <select name="employment_type"><option value="employee" ${(s.employment_type || 'employee') === 'employee' ? 'selected' : ''}>Employee (W-2)</option><option value="contractor" ${s.employment_type === 'contractor' ? 'selected' : ''}>Contractor (1099)</option></select></label>
        <label>Position <input name="position" value="${esc(s.position || '')}" placeholder="Lead bartender, Head of Security, …"></label>
        <label>Email <input type="email" name="email" value="${esc(s.email || '')}" placeholder="Optional"></label>
        <label>Phone <input name="phone" value="${esc(s.phone || '')}" placeholder="Optional"></label>
        <label>Hourly rate <input type="number" step="0.01" name="hourly_rate" value="${esc(s.hourly_rate || '')}" placeholder="Optional"></label>
        <label>Hire date <input type="date" name="hire_date" value="${esc(s.hire_date ? String(s.hire_date).slice(0, 10) : '')}"></label>
        <label>Link to login <select name="user_id">${userOpts}</select></label>
        <label class="wide">Notes <input name="notes" value="${esc(s.notes || '')}" placeholder="Allergies, certifications, availability"></label>
        <label class="check-label"><input type="checkbox" name="active" value="1" ${active ? 'checked' : ''}> Active</label>
        <button>${isEdit ? 'Save' : 'Add staff member'}</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => { dialog.remove(); document.removeEventListener('keydown', onEsc); };
    function onEsc(event) { if (event.key === 'Escape') close(); }
    document.addEventListener('keydown', onEsc);
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    $('input[name="name"]', dialog)?.focus();
    $('[data-form="staff"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      body.active = event.target.active.checked ? 1 : 0;
      try {
        if (isEdit) {
          await api(`/staff-members/${s.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Staff member updated.' });
        } else {
          await api('/staff-members', { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: `${body.name} added.` });
        }
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Remove ${name} from the roster? Past shifts are kept as "TBD" assignments.`)) return;
    try {
      await api(`/staff-members/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `${name} removed.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}


class AdminTemplates extends PanicElement {
  async connect() {
    this.setLoading('Loading templates');
    try {
      this.data = await api('/templates');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const templates = this.data.templates || [];
    const types = this.data.types || ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];
    const venues = this.data.venues || [];
    const venueOpts = venues.map((v) => `<option value="${esc(v.id)}">${esc(v.name)}</option>`).join('');
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded"><h2>Event Templates</h2><span class="muted">${templates.length} total</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Type</th><th>Venue</th><th>Default title</th><th>Tasks / Schedule / Staffing</th><th></th></tr></thead>
          <tbody>
            ${templates.map((t) => {
              const checklist = (() => { try { return JSON.parse(t.checklist_json || '[]'); } catch { return []; } })();
              const schedule  = (() => { try { return JSON.parse(t.schedule_json  || '[]'); } catch { return []; } })();
              const staffing  = (() => { try { return JSON.parse(t.staffing_json  || '[]'); } catch { return []; } })();
              const staffTotal = staffing.reduce((sum, r) => sum + (parseInt(r.count, 10) || 1), 0);
              return `<tr>
                <td><strong>${esc(t.name)}</strong></td>
                <td>${esc(titleCase(t.event_type))}</td>
                <td>${esc(t.venue_name)}</td>
                <td>${esc(t.default_title || '—')}</td>
                <td>${checklist.length} task${checklist.length === 1 ? '' : 's'} &middot; ${schedule.length} schedule item${schedule.length === 1 ? '' : 's'} &middot; ${staffTotal} staff position${staffTotal === 1 ? '' : 's'}</td>
                <td class="row-actions">
                  <button class="small secondary" data-edit="${esc(t.id)}">Edit</button>
                  <button class="small danger" data-delete="${esc(t.id)}" data-name="${esc(t.name)}">Delete</button>
                </td>
              </tr>`;
            }).join('') || '<tr><td colspan="6"><div class="empty-state">No templates yet — create one below to start programming nights.</div></td></tr>'}
          </tbody>
        </table>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Create Template</h2></div>
        <form data-form="create" class="grid-form padded">
          <label>Name <input name="name" required placeholder="e.g. Three-Band Local Show"></label>
          <label>Type ${select('event_type', types, 'live_music')}</label>
          <label>Venue <select name="venue_id" required>${venueOpts}</select></label>
          <label>Default title <input name="default_title" placeholder="Used when creating events"></label>
          <label>Default ticket price <input type="number" step="0.01" name="default_ticket_price" value="0"></label>
          <label>Default age <input name="default_age_restriction" placeholder="21+ / All Ages"></label>
          <label class="wide">Public description <textarea name="default_description_public" rows="2"></textarea></label>
          <label class="wide">Checklist <small class="muted">One task per line. Pre-populates the Tasks list of new events.</small><textarea name="_checklist" rows="5" placeholder="Confirm headliner\nApprove flyer\nPublish event page"></textarea></label>
          <label class="wide">Schedule <small class="muted">One per line as <code>HH:MM | type | title</code>. Types: load_in, soundcheck, doors, set, changeover, curfew, staff_call, other.</small><textarea name="_schedule" rows="5" placeholder="17:00 | load_in | Load-in\n18:00 | soundcheck | Soundcheck\n20:00 | doors | Doors\n20:30 | set | Opener"></textarea></label>
          <label class="wide">Staffing
            <small class="muted">One role per line as <code>role | count | notes</code>. Roles: manager, bartender, barback, door, security, sound, lighting, stagehand, runner, cleaner, other. These create TBD shifts when an event is made from this template.</small>
            <div class="staffing-suggest-row" style="display:flex;gap:0.5rem;margin-bottom:0.4rem;align-items:center">
              <input type="number" name="_cap_hint" placeholder="Capacity" min="1" max="9999" style="width:110px">
              <button type="button" class="small secondary" data-suggest-staffing>Suggest from capacity</button>
            </div>
            <textarea name="_staffing" rows="5" placeholder="manager | 1&#10;bartender | 2&#10;security | 2 | Front door&#10;door | 1&#10;sound | 1"></textarea>
          </label>
          <button>Create template</button>
        </form>
      </article>
    `;
    $('[data-form="create"]', this).addEventListener('submit', (event) => this.create(event));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openEdit(Number(b.dataset.edit))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
    this.bindSuggestButtons(this);
  }

  // Staffing tiers for the "Suggest from capacity" helper (mirrored in event-panels.js).
  static STAFFING_TIERS = [
    { max:  50, roles: [['manager',1],['bartender',1],['door',1],['sound',1]] },
    { max: 100, roles: [['manager',1],['bartender',2],['door',1],['security',1],['sound',1]] },
    { max: 150, roles: [['manager',1],['bartender',2],['barback',1],['door',2],['security',1],['sound',1],['lighting',1],['stagehand',1]] },
    { max: 200, roles: [['manager',1],['bartender',3],['barback',1],['door',2],['security',2],['sound',1],['lighting',1],['stagehand',1]] },
    { max: 250, roles: [['manager',1],['bartender',3],['barback',2],['door',2],['security',3],['sound',1],['lighting',1],['stagehand',1],['runner',1]] },
    { max: 300, roles: [['manager',1],['bartender',4],['barback',2],['door',2],['security',4],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
    { max: 350, roles: [['manager',1],['bartender',5],['barback',2],['door',3],['security',5],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
    { max: 400, roles: [['manager',1],['bartender',5],['barback',3],['door',3],['security',6],['sound',1],['lighting',1],['stagehand',2],['runner',1]] },
  ];

  staffingTierFor(capacity) {
    const cap = Math.max(1, parseInt(capacity, 10) || 0);
    const tiers = AdminTemplates.STAFFING_TIERS;
    for (const tier of tiers) { if (cap <= tier.max) return tier.roles; }
    return tiers[tiers.length - 1].roles;
  }

  bindSuggestButtons(root) {
    $$('[data-suggest-staffing]', root).forEach((btn) => {
      btn.addEventListener('click', () => {
        const form   = btn.closest('form, article, .modal-card');
        const capEl  = $('[name="_cap_hint"]', form);
        const txtEl  = $('[name="_staffing"]', form);
        if (!capEl || !txtEl) return;
        const cap = parseInt(capEl.value, 10);
        if (!cap || cap <= 0) { capEl.focus(); return; }
        const roles = this.staffingTierFor(cap);
        txtEl.value = roles.map(([role, count]) => `${role} | ${count}`).join('\n');
        txtEl.focus();
      });
    });
  }

  parseChecklist(value) {
    return String(value || '').split('\n').map((l) => l.trim()).filter(Boolean).map((title) => ({ title }));
  }

  parseSchedule(value) {
    const validTypes = new Set(['load_in','soundcheck','doors','set','changeover','curfew','staff_call','other']);
    return String(value || '').split('\n').map((l) => l.trim()).filter(Boolean).map((line) => {
      const parts = line.split('|').map((p) => p.trim());
      const time = parts[0] || null;
      const type = validTypes.has((parts[1] || '').toLowerCase()) ? parts[1].toLowerCase() : 'other';
      const title = parts.slice(2).join(' | ') || (parts[1] || 'Schedule item');
      return { start_time: time, item_type: type, title };
    });
  }

  serializeChecklist(json) {
    try {
      const arr = JSON.parse(json || '[]');
      return (arr || []).map((row) => typeof row === 'string' ? row : row.title).filter(Boolean).join('\n');
    } catch { return ''; }
  }

  serializeSchedule(json) {
    try {
      const arr = JSON.parse(json || '[]');
      return (arr || []).map((row) => `${row.start_time || ''} | ${row.item_type || 'other'} | ${row.title || ''}`).join('\n');
    } catch { return ''; }
  }

  parseStaffing(value) {
    const validRoles = new Set(['manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other']);
    return String(value || '').split('\n').map((l) => l.trim()).filter(Boolean).map((line) => {
      const parts = line.split('|').map((p) => p.trim());
      const role  = validRoles.has(parts[0]) ? parts[0] : 'other';
      const count = Math.max(1, parseInt(parts[1], 10) || 1);
      const notes = parts[2] || null;
      return notes ? { role, count, notes } : { role, count };
    });
  }

  serializeStaffing(json) {
    try {
      const arr = JSON.parse(json || '[]');
      return (arr || []).map((row) => [row.role, row.count ?? 1, row.notes].filter((v) => v != null && v !== '').join(' | ')).join('\n');
    } catch { return ''; }
  }

  buildBody(form) {
    const body = formData(form);
    body.checklist_json = JSON.stringify(this.parseChecklist(body._checklist));
    body.schedule_json  = JSON.stringify(this.parseSchedule(body._schedule));
    body.staffing_json  = JSON.stringify(this.parseStaffing(body._staffing));
    delete body._checklist;
    delete body._schedule;
    delete body._staffing;
    delete body._cap_hint;
    return body;
  }

  async create(event) {
    event.preventDefault();
    try {
      await api('/templates', { method: 'POST', body: JSON.stringify(this.buildBody(event.target)) });
      publish('toast.show', { message: 'Template created.' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openEdit(id) {
    const t = (this.data.templates || []).find((row) => Number(row.id) === id);
    if (!t) return;
    const types = this.data.types || [];
    const venues = this.data.venues || [];
    const venueOpts = venues.map((v) => `<option value="${esc(v.id)}" ${Number(v.id) === Number(t.venue_id) ? 'selected' : ''}>${esc(v.name)}</option>`).join('');
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide">
      <div class="section-head padded"><h2>Edit template</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="edit">
        <label>Name <input name="name" required value="${esc(t.name)}"></label>
        <label>Type ${select('event_type', types, t.event_type)}</label>
        <label>Venue <select name="venue_id" required>${venueOpts}</select></label>
        <label>Default title <input name="default_title" value="${esc(t.default_title || '')}"></label>
        <label>Default ticket price <input type="number" step="0.01" name="default_ticket_price" value="${esc(t.default_ticket_price || 0)}"></label>
        <label>Default age <input name="default_age_restriction" value="${esc(t.default_age_restriction || '')}"></label>
        <label class="wide">Public description <textarea name="default_description_public" rows="2">${esc(t.default_description_public || '')}</textarea></label>
        <label class="wide">Checklist <small class="muted">One task per line.</small><textarea name="_checklist" rows="7">${esc(this.serializeChecklist(t.checklist_json))}</textarea></label>
        <label class="wide">Schedule <small class="muted">HH:MM | type | title  (types: load_in, soundcheck, doors, set, changeover, curfew, staff_call, other)</small><textarea name="_schedule" rows="7">${esc(this.serializeSchedule(t.schedule_json))}</textarea></label>
        <label class="wide">Staffing
          <small class="muted">role | count | notes  (roles: manager, bartender, barback, door, security, sound, lighting, stagehand, runner, cleaner, other)</small>
          <div class="staffing-suggest-row" style="display:flex;gap:0.5rem;margin-bottom:0.4rem;align-items:center">
            <input type="number" name="_cap_hint" placeholder="Capacity" min="1" max="9999" style="width:110px">
            <button type="button" class="small secondary" data-suggest-staffing>Suggest from capacity</button>
          </div>
          <textarea name="_staffing" rows="7">${esc(this.serializeStaffing(t.staffing_json))}</textarea>
        </label>
        <button>Save template</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    this.bindSuggestButtons(dialog);
    $('[data-form="edit"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api(`/templates/${t.id}`, { method: 'PATCH', body: JSON.stringify(this.buildBody(event.target)) });
        publish('toast.show', { message: 'Template saved.' });
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Delete the ${name} template? Existing events created from it are not affected.`)) return;
    try {
      await api(`/templates/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: 'Template deleted.' });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}

// ── Wizard Defaults Editor ────────────────────────────────────────────────────
// Admin UI for configuring the sane defaults that pre-fill the Event Creation
// Wizard for every new event.  Displayed on the "Wizard" tab of the Admin page.

const DEAL_TYPES = [
  { value: 'talent_buy',    label: 'Talent Buy' },
  { value: 'promoter_deal', label: 'Promoter Deal' },
  { value: 'rental',        label: 'Venue Rental' },
  { value: 'private_event', label: 'Private Event' },
  { value: 'residency',     label: 'Residency' },
  { value: 'free_event',    label: 'Free / Internal' },
];

const EVENT_TYPES = [
  'live_music', 'karaoke', 'open_mic', 'promoter_night',
  'dj_night', 'comedy', 'private_event', 'special_event',
];

class AdminWizardDefaults extends PanicElement {
  async connect() {
    this.saving    = false;
    this.defaults  = {};
    this.venues    = [];
    this.setLoading('Loading wizard defaults…');
    try {
      const [wdRes, tplRes] = await Promise.all([
        api('/wizard-defaults'),
        api('/templates'),
      ]);
      this.defaults = wdRes.defaults || {};
      this.venues   = tplRes.venues  || [];
      this.render();
    } catch (err) {
      this.innerHTML = `<p class="error-text">Failed to load: ${esc(err.message)}</p>`;
    }
  }

  /** Render a labelled form field. `type` may be text|number|time|select|bool. */
  _field({ id, label, type, options, hint }) {
    const val = esc(this.defaults[id] ?? '');
    const baseAttrs = `name="${esc(id)}" id="wd-${esc(id)}"`;

    let input;
    if (type === 'select') {
      const opts = [
        `<option value="">— no default —</option>`,
        ...options.map((o) =>
          `<option value="${esc(o.value)}" ${this.defaults[id] === o.value ? 'selected' : ''}>${esc(o.label)}</option>`),
      ].join('');
      input = `<select ${baseAttrs} class="wd-input">${opts}</select>`;
    } else if (type === 'bool') {
      input = `<select ${baseAttrs} class="wd-input">
        <option value="">— no default —</option>
        <option value="1" ${this.defaults[id] === '1' ? 'selected' : ''}>Yes</option>
        <option value="0" ${this.defaults[id] === '0' ? 'selected' : ''}>No</option>
      </select>`;
    } else {
      const typeAttr = type === 'number' ? 'type="number"' : type === 'time' ? 'type="time"' : 'type="text"';
      const extra    = type === 'number' ? 'min="0" step="1"' : '';
      input = `<input ${baseAttrs} ${typeAttr} ${extra} value="${val}" class="wd-input" placeholder="— no default —">`;
    }

    return `
      <div class="wd-field">
        <label class="wd-label" for="wd-${esc(id)}">${esc(label)}</label>
        ${input}
        ${hint ? `<span class="wd-hint">${esc(hint)}</span>` : ''}
      </div>`;
  }

  render() {
    const venueOpts = this.venues.map((v) => ({ value: String(v.id), label: v.name }));
    const typeOpts  = EVENT_TYPES.map((t) => ({ value: t, label: titleCase(t) }));

    this.innerHTML = `
      <div class="wd-editor">
        <div class="wd-header">
          <div>
            <h2>Wizard defaults</h2>
            <p class="subtle">These values pre-fill every new event in the creation wizard.
              Leave a field set to "— no default —" to start it blank.
              Staff can always override any default during the wizard.</p>
          </div>
          <button class="btn-primary wd-save" ${this.saving ? 'disabled' : ''}>
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            ${this.saving ? 'Saving…' : 'Save defaults'}
          </button>
        </div>

        <form class="wd-form" novalidate>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> Event basics</legend>
            <div class="wd-grid">
              ${this._field({ id: 'venue_id',       label: 'Venue',          type: 'select', options: venueOpts })}
              ${this._field({ id: 'event_type',     label: 'Event type',     type: 'select', options: typeOpts  })}
              ${this._field({ id: 'age_restriction',label: 'Age restriction',type: 'select', options: [
                { value: 'All Ages', label: 'All Ages' },
                { value: '18+',      label: '18+' },
                { value: '21+',      label: '21+' },
              ]})}
              ${this._field({ id: 'capacity',       label: 'Capacity',       type: 'number', hint: 'Max attendees' })}
              ${this._field({ id: 'doors_time',     label: 'Doors open',     type: 'time'   })}
              ${this._field({ id: 'show_time',      label: 'Show time',      type: 'time'   })}
              ${this._field({ id: 'end_time',       label: 'End / curfew',   type: 'time'   })}
            </div>
          </fieldset>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-handshake" aria-hidden="true"></i> Deal structure</legend>
            <div class="wd-grid">
              ${this._field({ id: 'deal_type',      label: 'Default deal type', type: 'select', options: DEAL_TYPES })}
            </div>
          </fieldset>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i> Deal terms</legend>
            <div class="wd-grid">
              ${this._field({ id: 'deposit_amount',     label: 'Deposit required ($)',  type: 'number' })}
              ${this._field({ id: 'bar_minimum',        label: 'Bar minimum ($)',        type: 'number' })}
              ${this._field({ id: 'merch_venue_percent',label: 'Venue merch cut (%)',    type: 'number', hint: '0–100' })}
            </div>
          </fieldset>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Production &amp; security</legend>
            <div class="wd-grid">
              ${this._field({ id: 'sound_tech_included',    label: 'Sound tech included',    type: 'bool' })}
              ${this._field({ id: 'lighting_tech_included', label: 'Lighting tech included',  type: 'bool' })}
              ${this._field({ id: 'security_count',         label: '# security guards',       type: 'number' })}
              ${this._field({ id: 'security_rate',          label: 'Security rate ($/hr)',     type: 'number' })}
              ${this._field({ id: 'security_paid_by',       label: 'Security paid by',         type: 'select', options: [
                { value: 'venue',     label: 'Venue'    },
                { value: 'artist',    label: 'Artist'   },
                { value: 'promoter',  label: 'Promoter' },
              ]})}
            </div>
          </fieldset>

        </form>
      </div>
    `;

    $('[data-action="reset"]', this)?.addEventListener('click', () => this.connect());
    $('.wd-save', this).addEventListener('click', (e) => { e.preventDefault(); this.save(); });
  }

  async save() {
    if (this.saving) return;
    this.saving = true;
    // Collect current values from form inputs
    const newDefaults = {};
    $$('.wd-input', this).forEach((el) => {
      const val = el.value.trim();
      if (val !== '') newDefaults[el.name] = val;
    });
    try {
      const res = await api('/wizard-defaults', { method: 'PUT', body: JSON.stringify({ defaults: newDefaults }) });
      this.defaults = res.defaults || newDefaults;
      publish('toast.show', { message: 'Wizard defaults saved.' });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Save failed.', tone: 'error' });
    } finally {
      this.saving = false;
      // Re-render to reflect authoritative saved state
      this.render();
    }
  }
}

// ── Venue Details Editor ──────────────────────────────────────────────────────
// Admin UI for viewing and editing the venue's own profile: name, address,
// city, state, and timezone. These fields appear on contracts and emails so
// it's important to fill them in early — the onboarding checklist links here.

const TIMEZONES = [
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Phoenix',
  'America/Los_Angeles',
  'America/Anchorage',
  'Pacific/Honolulu',
  'America/Puerto_Rico',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'Australia/Sydney',
  'Pacific/Auckland',
];

class AdminVenue extends PanicElement {
  async connect() {
    this.saving = false;
    this.setLoading('Loading venue details…');
    try {
      const data = await api('/venues');
      // Use the first venue (single-venue installs); multi-venue would show a picker.
      this.venue = (data.venues || [])[0] || null;
      // Management view needs archived rooms too, so load them separately.
      this.rooms = this.venue
        ? (await api(`/venues/${this.venue.id}/resources`)).resources || []
        : [];
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const v = this.venue || {};
    const tzOptions = TIMEZONES.map((tz) =>
      `<option value="${esc(tz)}" ${(v.timezone || 'America/Los_Angeles') === tz ? 'selected' : ''}>${esc(tz)}</option>`
    ).join('');

    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded">
          <h2>Venue Details</h2>
          <span class="muted">Appears on contracts, emails and public event pages.</span>
        </div>
        ${this.venue ? `
        <form class="grid-form padded" data-form="venue">
          <label>Venue name <input name="name" required value="${esc(v.name || '')}" placeholder="The Fillmore"></label>
          <label>Address <input name="address" value="${esc(v.address || '')}" placeholder="1805 Geary Blvd"></label>
          <label>City <input name="city" value="${esc(v.city || '')}" placeholder="San Francisco"></label>
          <label>State / Region <input name="state" value="${esc(v.state || '')}" placeholder="CA"></label>
          <label>Timezone
            <select name="timezone">${tzOptions}</select>
          </label>
          <label>Phone <input name="phone" value="${esc(v.phone || '')}" placeholder="(415) 555-0100"></label>
          <label>Website <input type="url" name="website_url" value="${esc(v.website_url || '')}" placeholder="https://thefillmore.com"></label>
          <div class="form-actions">
            <button class="btn-primary" ${this.saving ? 'disabled' : ''}>${this.saving ? 'Saving…' : 'Save venue details'}</button>
          </div>
        </form>
        ` : '<p class="padded muted">No venue found. Please contact support.</p>'}
      </article>
      ${this.venue ? this.roomsPanel() : ''}
    `;

    $('[data-form="venue"]', this)?.addEventListener('submit', (event) => this.save(event));
    $('[data-add-room]', this)?.addEventListener('click', () => this.openRoomModal(null));
    $$('[data-edit-room]', this).forEach((b) => b.addEventListener('click', () =>
      this.openRoomModal((this.rooms || []).find((r) => Number(r.id) === Number(b.dataset.editRoom)))));
    $$('[data-archive-room]', this).forEach((b) => b.addEventListener('click', () =>
      this.archiveRoom(Number(b.dataset.archiveRoom), b.dataset.name)));
    $$('[data-restore-room]', this).forEach((b) => b.addEventListener('click', () =>
      this.restoreRoom(Number(b.dataset.restoreRoom))));
  }

  roomsPanel() {
    const rooms = this.rooms || [];
    const active = rooms.filter((r) => Number(r.active));
    return `
      <article class="panel">
        <div class="section-head padded">
          <h2>Rooms</h2>
          <div class="section-head-actions">
            <span class="muted">${active.length} active${rooms.length !== active.length ? ` &middot; ${rooms.length - active.length} archived` : ''}</span>
            ${addToggle('Add room', true).replace('data-add', 'data-add-room')}
          </div>
        </div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Capacity</th><th>Zone</th><th>Order</th><th>Status</th><th></th></tr></thead>
          <tbody>
            ${rooms.map((r) => `<tr class="${Number(r.active) ? '' : 'muted-row'}">
              <td><strong>${esc(r.name)}</strong>${r.description ? `<br><small class="muted">${esc(r.description)}</small>` : ''}</td>
              <td>${r.capacity != null && r.capacity !== '' ? esc(r.capacity) : '<span class="muted">—</span>'}</td>
              <td><span class="badge ${esc(roomTone(r.zone))}">${esc(titleCase(r.zone || 'primary'))}</span></td>
              <td>${esc(r.sort_order)}</td>
              <td>${Number(r.active) ? '<span class="badge status-confirmed">Active</span>' : '<span class="badge status-canceled">Archived</span>'}</td>
              <td class="row-actions">
                <button class="small secondary" data-edit-room="${esc(r.id)}">Edit</button>
                ${Number(r.active)
                  ? `<button class="small danger" data-archive-room="${esc(r.id)}" data-name="${esc(r.name)}">Archive</button>`
                  : `<button class="small secondary" data-restore-room="${esc(r.id)}">Restore</button>`}
              </td>
            </tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">No rooms yet — use the + above to add a bookable space.</div></td></tr>'}
          </tbody>
        </table>
      </article>
    `;
  }

  // Add (room === null) and edit share one modal; submit POSTs or PATCHes then reloads.
  openRoomModal(room = null) {
    const isEdit = Boolean(room && room.id);
    const r = room || {};
    const zones = ['primary', 'up', 'down', 'both'];
    const zoneOpts = zones.map((z) =>
      `<option value="${esc(z)}" ${(r.zone || 'primary') === z ? 'selected' : ''}>${esc(titleCase(z))}</option>`
    ).join('');
    const active = isEdit ? Number(r.active) : 1;
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>${isEdit ? 'Edit room' : 'Add room'}</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="room">
        <label>Name <input name="name" required value="${esc(r.name || '')}" placeholder="Main Hall, Green Room, Patio…"></label>
        <label>Capacity <input type="number" min="0" name="capacity" value="${esc(r.capacity != null ? r.capacity : '')}" placeholder="Max occupancy"></label>
        <label>Zone <select name="zone">${zoneOpts}</select></label>
        <label>Sort order <input type="number" name="sort_order" value="${esc(r.sort_order != null ? r.sort_order : '')}" placeholder="0"></label>
        <label class="wide">Description <input name="description" value="${esc(r.description || '')}" placeholder="Notes shown alongside this room"></label>
        <label class="check-label"><input type="checkbox" name="active" value="1" ${active ? 'checked' : ''}> Active</label>
        <button>${isEdit ? 'Save' : 'Add room'}</button>
      </form>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => { dialog.remove(); document.removeEventListener('keydown', onEsc); };
    function onEsc(event) { if (event.key === 'Escape') close(); }
    document.addEventListener('keydown', onEsc);
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    $('input[name="name"]', dialog)?.focus();
    $('[data-form="room"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      body.active = event.target.active.checked ? 1 : 0;
      try {
        if (isEdit) {
          await api(`/venues/${this.venue.id}/resources/${r.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Room updated.' });
        } else {
          await api(`/venues/${this.venue.id}/resources`, { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: `${body.name} added.` });
        }
        close();
        await this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async archiveRoom(id, name) {
    if (!confirm(`Archive ${name}? It drops off the calendar but past events keep their room.`)) return;
    try {
      await api(`/venues/${this.venue.id}/resources/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `${name} archived.` });
      await this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async restoreRoom(id) {
    try {
      await api(`/venues/${this.venue.id}/resources/${id}`, { method: 'PATCH', body: JSON.stringify({ active: 1 }) });
      publish('toast.show', { message: 'Room restored.' });
      await this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async save(event) {
    event.preventDefault();
    if (this.saving) return;
    this.saving = true;
    this.render();

    const body = formData(event.target);
    try {
      const res = await api(`/venues/${this.venue.id}`, { method: 'PATCH', body: JSON.stringify(body) });
      this.venue = res.venue || this.venue;
      publish('toast.show', { message: 'Venue details saved.', tone: 'success' });
      // Notify the rest of the app in case the venue name changed.
      publish('venue.updated', { venue: this.venue });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Save failed.', tone: 'error' });
    } finally {
      this.saving = false;
      this.render();
    }
  }
}

// ── App Settings ───────────────────────────────────────────────────────────
// The app shell's own brand identity (name/logo shown top-left, browser tab
// title) plus the small set of venue contact/social fields that are safe to
// edit from a web form. Two stores behind one form/Save button — see
// src/AppSettings.php for exactly what goes where and why:
//   - Brand fields  → app_settings DB singleton (brand_name, logo_url).
//   - Everything else on this page → an allow-listed slice of .env, rewritten
//     in place (never a raw file editor — no secret ever reaches this page).
// Deliberately does NOT duplicate Admin > Venue (venues.name/city/state/
// website_url) — that stays the one place to edit the venue's own profile.
class AdminAppSettings extends PanicElement {
  async connect() {
    this.saving = false;
    this.setLoading('Loading app settings…');
    try {
      const res = await api('/app-settings');
      this.settings = res.settings || {};
      this.env = res.env || {};
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  _field({ id, label, value, hint, placeholder, type = 'text' }) {
    return `
      <div class="wd-field">
        <label class="wd-label" for="as-${esc(id)}">${esc(label)}</label>
        <input name="${esc(id)}" id="as-${esc(id)}" type="${esc(type)}" class="as-input" value="${esc(value ?? '')}" placeholder="${esc(placeholder || '')}">
        ${hint ? `<span class="wd-hint">${esc(hint)}</span>` : ''}
      </div>`;
  }

  render() {
    const s = this.settings || {};
    const e = this.env || {};

    this.innerHTML = `
      <div class="wd-editor">
        <div class="wd-header">
          <div>
            <h2>App Settings</h2>
            <p class="subtle">How this app identifies itself to your team, and who gets contacted about it.
              Login credentials, payment tokens, and other secrets live outside this page — server access only.</p>
          </div>
          <button class="btn-primary as-save" ${this.saving ? 'disabled' : ''}>
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            ${this.saving ? 'Saving…' : 'Save settings'}
          </button>
        </div>

        <form class="wd-form" novalidate>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-signature" aria-hidden="true"></i> Brand</legend>
            <div class="wd-grid">
              ${this._field({ id: 'brand_name', label: 'App name', value: s.brand_name, placeholder: 'Mabuhay Backstage', hint: 'Shown top-left in the sidebar/topbar and in the browser tab. Leave blank to use the default.' })}
              ${this._field({ id: 'logo_url', label: 'Logo URL', value: s.logo_url, placeholder: '(default icon)', hint: 'Leave blank to keep the default icon. Square image works best.' })}
            </div>
          </fieldset>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-address-card" aria-hidden="true"></i> Admin contact</legend>
            <div class="wd-grid">
              ${this._field({ id: 'manager_name', label: 'Name', value: e.manager_name, placeholder: 'Jane Smith', hint: 'Notified alongside venue_admins when an event reaches Intake Complete.' })}
              ${this._field({ id: 'manager_email', label: 'Email', type: 'email', value: e.manager_email, placeholder: 'jane@myvenue.com' })}
              ${this._field({ id: 'manager_phone', label: 'Phone', type: 'tel', value: e.manager_phone, placeholder: '(415) 555-0100' })}
            </div>
          </fieldset>

          <fieldset class="wd-section">
            <legend><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Venue contact &amp; social</legend>
            <div class="wd-grid">
              ${this._field({ id: 'venue_email', label: 'Public contact email', type: 'email', value: e.venue_email, placeholder: 'hello@myvenue.com', hint: 'From address for Promote email blasts.' })}
              ${this._field({ id: 'press_email', label: 'Press contact email', type: 'email', value: e.press_email, placeholder: 'press@myvenue.com' })}
              ${this._field({ id: 'hashtags', label: 'Hashtags', value: e.hashtags, placeholder: 'LiveMusic,MyVenue', hint: 'Comma-separated, no #, used in social-media copy.' })}
              ${this._field({ id: 'tiktok_handle', label: 'TikTok handle', value: e.tiktok_handle, placeholder: '(without @)' })}
            </div>
          </fieldset>

        </form>
      </div>
    `;

    $('.as-save', this).addEventListener('click', (event) => { event.preventDefault(); this.save(); });
  }

  async save() {
    if (this.saving) return;

    // Collect the typed values BEFORE re-rendering, and immediately adopt
    // them as this.settings/this.env too — render() always rebuilds the
    // form from those two fields, so if they weren't updated first, the
    // "Saving…" render (or a failed save's final render) would flash the
    // user's just-typed values back to whatever was last loaded.
    const settings = {};
    const env = {};
    const envKeys = new Set(['manager_name', 'manager_email', 'manager_phone', 'venue_email', 'press_email', 'hashtags', 'tiktok_handle']);
    $$('.as-input', this).forEach((el) => {
      if (envKeys.has(el.name)) env[el.name] = el.value.trim();
      else settings[el.name] = el.value.trim();
    });
    this.settings = settings;
    this.env = env;

    this.saving = true;
    this.render();

    try {
      const res = await api('/app-settings', { method: 'PUT', body: JSON.stringify({ settings, env }) });
      this.settings = res.settings || settings;
      this.env = res.env || env;
      publish('toast.show', { message: 'App settings saved.', tone: 'success' });
      publish('app-settings.updated', { settings: this.settings });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Save failed.', tone: 'error' });
    } finally {
      this.saving = false;
      this.render();
    }
  }
}

customElements.define('pb-admin-page', AdminPage);
customElements.define('pb-admin-users', AdminUsers);
customElements.define('pb-admin-staff', AdminStaff);
customElements.define('pb-admin-templates', AdminTemplates);
customElements.define('pb-admin-wizard-defaults', AdminWizardDefaults);
customElements.define('pb-admin-venue', AdminVenue);
customElements.define('pb-admin-app-settings', AdminAppSettings);
