import { esc, titleCase, publish, api, PanicElement, $, $$ } from './core.js';


// ── User email aliases + duplicate merge ──────────────────────────────────────
// Two admin-only web components for the Admin ▸ Users screen:
//   <pb-user-emails user-id="…">   — manage one user's primary + alt emails
//   <pb-user-duplicates>            — review suggested duplicate pairs + merge
//
// Both follow the PanicElement / api() / esc() conventions used by admin.js.
// alt_emails JSON shape (from the /users payload):
//   [ { email, verified_at: <ISO8601|null>, added_at: <ISO8601> }, … ]
// Only entries with verified_at != null may authenticate.


// Normalize the alt_emails value off a user row. The API may serialize it as a
// JSON string or already-decoded array depending on the column hydration, so we
// tolerate both and always return an array of {email, verified_at, added_at}.
function parseAltEmails(value) {
  if (Array.isArray(value)) return value;
  if (typeof value === 'string' && value.trim()) {
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed : [];
    } catch { return []; }
  }
  return [];
}


function verifiedBadge(entry) {
  return entry.verified_at
    ? '<span class="badge status-confirmed">Verified</span>'
    : '<span class="badge status-hold">Unverified</span>';
}


// ── Per-user email manager ────────────────────────────────────────────────────
// Lists the primary email and every alias with a verified/unverified badge,
// an add-alias form, and per-row resend / remove / make-primary actions.
// Hosted inside the Edit-user modal; takes the user object via `.user` or reads
// the `user-id` attribute and fetches a fresh copy.
class UserEmails extends PanicElement {
  connect() {
    if (this.user) {
      this.render();
    } else {
      this.load();
    }
  }

  get userId() {
    return Number(this.user?.id ?? this.getAttribute('user-id'));
  }

  async load() {
    this.setLoading('Loading emails');
    try {
      const data = await api('/users');
      this.user = (data.users || []).find((u) => Number(u.id) === this.userId) || null;
      if (!this.user) { this.innerHTML = '<p class="muted">User not found.</p>'; return; }
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const user = this.user || {};
    const aliases = parseAltEmails(user.alt_emails);
    this.innerHTML = `
      <div class="user-emails">
        <ul class="email-list">
          <li class="email-row">
            <span class="email-addr">${esc(user.email)}</span>
            <span class="badge">Primary</span>
            <span class="badge status-confirmed">Verified</span>
            <span class="email-actions"></span>
          </li>
          ${aliases.map((entry) => `<li class="email-row" data-alias="${esc(entry.email)}">
            <span class="email-addr">${esc(entry.email)}</span>
            ${verifiedBadge(entry)}
            <span class="email-actions">
              ${entry.verified_at
                ? `<button type="button" class="small secondary" data-primary="${esc(entry.email)}">Make primary</button>`
                : `<button type="button" class="small secondary" data-resend="${esc(entry.email)}">Resend link</button>`}
              <button type="button" class="small danger" data-remove="${esc(entry.email)}">Remove</button>
            </span>
          </li>`).join('')}
        </ul>
        <form data-form="add-alias" class="inline-add">
          <input type="email" name="email" required placeholder="add another email…" aria-label="New email address">
          <button class="small">Add email</button>
        </form>
        <p class="muted small">Adding an email sends a verification link to that address. Only verified emails can sign in.</p>
      </div>
    `;
    $('[data-form="add-alias"]', this).addEventListener('submit', (event) => this.addAlias(event));
    $$('[data-resend]', this).forEach((b) => b.addEventListener('click', () => this.resend(b.dataset.resend)));
    $$('[data-remove]', this).forEach((b) => b.addEventListener('click', () => this.remove(b.dataset.remove)));
    $$('[data-primary]', this).forEach((b) => b.addEventListener('click', () => this.makePrimary(b.dataset.primary)));
  }

  // Re-fetch this user (so badges/primary reflect server state) and re-render.
  async refresh() {
    try {
      const data = await api('/users');
      const fresh = (data.users || []).find((u) => Number(u.id) === this.userId);
      if (fresh) this.user = fresh;
    } catch { /* keep current view; toast already surfaced the error */ }
    this.render();
    publish('user-emails.changed', { userId: this.userId });
  }

  async addAlias(event) {
    event.preventDefault();
    const input = event.target.email;
    const email = String(input.value || '').trim();
    if (!email) return;
    try {
      await api(`/users/${this.userId}/emails`, { method: 'POST', body: JSON.stringify({ email }) });
      publish('toast.show', { message: `Verification link sent to ${email}.`, tone: 'success' });
      await this.refresh();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async resend(email) {
    try {
      await api(`/users/${this.userId}/emails/resend`, { method: 'POST', body: JSON.stringify({ email }) });
      publish('toast.show', { message: `Verification link re-sent to ${email}.`, tone: 'success' });
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async remove(email) {
    if (!confirm(`Remove ${email} from this account? They will no longer be able to sign in with it.`)) return;
    try {
      await api(`/users/${this.userId}/emails`, { method: 'DELETE', body: JSON.stringify({ email }) });
      publish('toast.show', { message: `${email} removed.`, tone: 'info' });
      await this.refresh();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async makePrimary(email) {
    if (!confirm(`Make ${email} the primary login email? The current primary becomes a verified alias.`)) return;
    try {
      await api(`/users/${this.userId}/emails/primary`, { method: 'POST', body: JSON.stringify({ email }) });
      publish('toast.show', { message: `${email} is now the primary email.`, tone: 'success' });
      await this.refresh();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}


// ── Duplicate review + merge ──────────────────────────────────────────────────
// Lists server-suggested duplicate pairs with their match signals and a
// same-role indicator, then merges one into the other after an explicit,
// clearly-irreversible confirm. Handles the 409 role-mismatch case by offering
// an override confirm that re-sends with confirm:true.
class UserDuplicates extends PanicElement {
  async connect() {
    this.setLoading('Scanning for duplicate accounts');
    try {
      this.pairs = await api('/users/duplicates');
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const pairs = Array.isArray(this.pairs) ? this.pairs : [];
    this.innerHTML = `
      <article class="panel">
        <div class="section-head padded">
          <h2>Possible duplicate accounts</h2>
          <span class="muted">${pairs.length} suggestion${pairs.length === 1 ? '' : 's'}</span>
        </div>
        ${pairs.length ? `<div class="dup-list padded">
          ${pairs.map((pair, index) => this.renderPair(pair, index)).join('')}
        </div>` : '<div class="empty-state">No likely duplicates found.</div>'}
      </article>
    `;
    $$('[data-merge]', this).forEach((b) => b.addEventListener('click', () => this.merge(Number(b.dataset.merge))));
  }

  renderPair(pair, index) {
    const a = pair.user_a || {};
    const b = pair.user_b || {};
    const signals = (pair.signals || []).map((s) => `<span class="badge">${esc(titleCase(s))}</span>`).join(' ');
    const roleNote = pair.same_role
      ? '<span class="badge status-confirmed">Same role</span>'
      : '<span class="badge status-hold">Different roles</span>';
    const card = (u) => `<div class="dup-user">
      <strong>${esc(u.name || '—')}</strong>
      <div class="muted small">${esc(u.email || '')}</div>
      <span class="badge">${esc(titleCase(u.role || ''))}</span>
    </div>`;
    return `<div class="dup-pair" data-pair="${index}">
      <div class="dup-pair-users">
        ${card(a)}
        <span class="dup-vs" aria-hidden="true"><i class="fa-solid fa-code-compare"></i></span>
        ${card(b)}
      </div>
      <div class="dup-pair-meta">${signals} ${roleNote}</div>
      <div class="row-actions">
        <button class="small" data-merge="${index}">Merge…</button>
      </div>
    </div>`;
  }

  // Open a confirm dialog letting the admin pick which record survives, then
  // POST the merge. Re-used for the override path after a 409.
  merge(index) {
    const pair = (this.pairs || [])[index];
    if (!pair) return;
    const a = pair.user_a || {};
    const b = pair.user_b || {};
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>Merge accounts</h2><button class="small secondary" data-close>Close</button></div>
      <div class="padded">
        <p class="error-text"><strong>This is irreversible.</strong> All events, contracts and other records owned by the loser are reassigned to the survivor, the loser's emails are folded in as verified aliases, and the loser account is deleted.</p>
        <p>Choose which account to <strong>keep</strong> (survivor):</p>
        <label class="check-label"><input type="radio" name="survivor" value="${esc(a.id)}" checked> Keep <strong>${esc(a.name || a.email)}</strong> — ${esc(a.email || '')} <span class="badge">${esc(titleCase(a.role || ''))}</span></label>
        <label class="check-label"><input type="radio" name="survivor" value="${esc(b.id)}"> Keep <strong>${esc(b.name || b.email)}</strong> — ${esc(b.email || '')} <span class="badge">${esc(titleCase(b.role || ''))}</span></label>
        ${pair.same_role ? '' : '<p class="muted small">These accounts have different roles. You will be asked to confirm the override.</p>'}
        <div class="row-actions" style="margin-top:1rem">
          <button class="small danger" data-confirm-merge>Merge and delete loser</button>
          <button class="small secondary" data-cancel>Cancel</button>
        </div>
      </div>
    </div>`;
    document.body.appendChild(dialog);
    const close = () => { dialog.remove(); document.removeEventListener('keydown', onEsc); };
    function onEsc(event) { if (event.key === 'Escape') close(); }
    document.addEventListener('keydown', onEsc);
    $('[data-close]', dialog).addEventListener('click', close);
    $('[data-cancel]', dialog).addEventListener('click', close);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    $('[data-confirm-merge]', dialog).addEventListener('click', async () => {
      const survivorId = Number($('input[name="survivor"]:checked', dialog)?.value);
      const loserId = survivorId === Number(a.id) ? Number(b.id) : Number(a.id);
      await this.performMerge(survivorId, loserId, close);
    });
  }

  // Issue the merge call. On 409 (role mismatch) prompt for an explicit
  // override; the contract already requires confirm:true, so the override here
  // is the admin re-affirming the cross-role merge.
  async performMerge(survivorId, loserId, close, overrideRoles = false) {
    try {
      const result = await api('/users/merge', {
        method: 'POST',
        body: JSON.stringify({ survivor_id: survivorId, loser_id: loserId, confirm: true, override_role: overrideRoles }),
      });
      const moved = Object.entries(result?.moved || {}).map(([table, n]) => `${n} ${table}`).join(', ');
      publish('toast.show', { message: `Merged. ${moved ? `Reassigned ${moved}.` : 'No records to move.'}`, tone: 'success' });
      close?.();
      this.connect();
      publish('user-emails.changed', { userId: survivorId });
    } catch (err) {
      if (/role/i.test(err.message) || /409/.test(err.message)) {
        if (confirm('These accounts have different roles. Merge anyway? The survivor keeps its own role.')) {
          return this.performMerge(survivorId, loserId, close, true);
        }
        return;
      }
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }
}


customElements.define('pb-user-emails', UserEmails);
customElements.define('pb-user-duplicates', UserDuplicates);
