import { esc, titleCase, publish, api, formData, badge, option, select, can, table, PanicElement, addToggle, $, $$ } from './core.js';


// ── Admin page ───────────────────────────────────────────────────────────────
// Three tabs: Users (login accounts), Staff (employee roster), Templates
// (run-sheet / checklist event templates). Admin-only — sidebar entry is
// hidden by AppShell.applyCapabilities() when the user lacks admin caps.

const ADMIN_TABS = [
  { key: 'users',     title: 'Users',     icon: 'fa-user-gear' },
  { key: 'duplicates', title: 'Duplicates', icon: 'fa-clone' },
  { key: 'staff',     title: 'Staff',     icon: 'fa-people-group' },
  { key: 'templates', title: 'Templates', icon: 'fa-layer-group' },
  { key: 'contracts', title: 'Contracts', icon: 'fa-file-signature' },
  { key: 'payments',  title: 'Payments',  icon: 'fa-credit-card' },
  { key: 'wizard',    title: 'Wizard',    icon: 'fa-wand-magic-sparkles' },
];


class AdminPage extends PanicElement {
  connect() {
    this.tab = ADMIN_TABS.find((t) => t.key === this.initialTab) ? this.initialTab : 'users';
    this.render();
  }

  render() {
    this.innerHTML = `
      <section class="page-head">
        <div><h1>Admin</h1><p class="subtle">Manage login accounts, the staff roster, event templates, and contract sections.</p></div>
      </section>
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
    const tag = { users: 'pb-admin-users', duplicates: 'pb-user-duplicates', staff: 'pb-admin-staff', templates: 'pb-admin-templates', contracts: 'pb-admin-contracts', payments: 'pb-payment-settings', wizard: 'pb-admin-wizard-defaults' }[this.tab];
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
        <div class="section-head padded"><h2>Login Accounts</h2><span class="muted">${users.length} total</span></div>
        <table class="data-table admin-table">
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Auth</th><th>Events</th><th></th></tr></thead>
          <tbody>
            ${users.map((u) => `<tr>
              <td>${esc(u.name)}</td>
              <td>${esc(u.email)}</td>
              <td><span class="badge">${esc(titleCase(u.role))}</span></td>
              <td>${Number(u.has_password) ? '<span class="muted">Password</span>' : '<span class="muted">—</span>'}${Number(u.passkey_count) ? ` &middot; ${esc(u.passkey_count)} passkey${Number(u.passkey_count) === 1 ? '' : 's'}` : ''}</td>
              <td>${esc(u.owned_event_count || 0)} owned &middot; ${esc(u.collaborator_event_count || 0)} collab</td>
              <td class="row-actions">
                <button class="small secondary" data-edit="${esc(u.id)}">Edit</button>
                <button class="small danger" data-delete="${esc(u.id)}" data-name="${esc(u.name)}">Delete</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">No users yet.</div></td></tr>'}
          </tbody>
        </table>
      </article>
      <article class="panel">
        <div class="section-head padded"><h2>Create User</h2></div>
        <form data-form="create" class="grid-form padded">
          <label>Name <input name="name" required placeholder="Full name"></label>
          <label>Email <input type="email" name="email" required placeholder="user@example.com"></label>
          <label>Role ${select('role', roles, 'viewer')}</label>
          <label>Password <input type="password" name="password" placeholder="Optional — they can also use email link"></label>
          <button>Create user</button>
        </form>
      </article>
    `;
    $('[data-form="create"]', this).addEventListener('submit', (event) => this.create(event));
    $$('[data-edit]', this).forEach((b) => b.addEventListener('click', () => this.openEdit(Number(b.dataset.edit))));
    $$('[data-delete]', this).forEach((b) => b.addEventListener('click', () => this.delete(Number(b.dataset.delete), b.dataset.name)));
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

  async create(event) {
    event.preventDefault();
    const body = formData(event.target);
    try {
      await api('/users', { method: 'POST', body: JSON.stringify(body) });
      publish('toast.show', { message: `User ${body.name} created.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  openEdit(id) {
    const user = (this.data.users || []).find((u) => Number(u.id) === id);
    if (!user) return;
    const roles = this.data.roles || [];
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Edit user</h2><button class="small secondary" data-close>Close</button></div>
      <form class="grid-form padded" data-form="edit">
        <label>Name <input name="name" required value="${esc(user.name)}"></label>
        <label>Email <input type="email" name="email" required value="${esc(user.email)}"></label>
        <label>Role ${select('role', roles, user.role)}</label>
        <label>Reset password <input type="password" name="password" placeholder="Leave blank to keep current"></label>
        <p class="muted">${Number(user.has_password) ? 'Password is set.' : 'No password set — user can sign in via passkey or email link.'} ${Number(user.passkey_count)} passkey${Number(user.passkey_count) === 1 ? '' : 's'} registered.</p>
        <button>Save</button>
      </form>
      <div class="section-head padded"><h2>Email addresses</h2></div>
      <div class="padded" data-emails-mount></div>
    </div>`;
    document.body.appendChild(dialog);
    const emailsEl = document.createElement('pb-user-emails');
    emailsEl.user = user;
    $('[data-emails-mount]', dialog).appendChild(emailsEl);
    const close = () => dialog.remove();
    $('[data-close]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
    $('[data-form="edit"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = formData(event.target);
      try {
        await api(`/users/${user.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        publish('toast.show', { message: 'User updated.' });
        close();
        this.connect();
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }

  async delete(id, name) {
    if (!confirm(`Delete user ${name}? This cannot be undone.`)) return;
    try {
      await api(`/users/${id}`, { method: 'DELETE' });
      publish('toast.show', { message: `${name} deleted.` });
      this.connect();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
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
      const res = await api('/wizard-defaults', { method: 'PUT', body: { defaults: newDefaults } });
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

customElements.define('pb-admin-page', AdminPage);
customElements.define('pb-admin-users', AdminUsers);
customElements.define('pb-admin-staff', AdminStaff);
customElements.define('pb-admin-templates', AdminTemplates);
customElements.define('pb-admin-wizard-defaults', AdminWizardDefaults);
