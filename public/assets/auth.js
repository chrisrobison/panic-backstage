import { setTokens, esc, appUrl, apiUrl, getAppUser, setAppUser, publish, api, formData, option, select, can, PanicElement, $, $$ } from './core.js';


// ── WebAuthn / passkey helpers ────────────────────────────────────────────────
function b64uToBuffer(b64u) {
  const pad = b64u.length % 4 ? 4 - b64u.length % 4 : 0;
  return Uint8Array.from(atob(b64u.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat(pad)), (c) => c.charCodeAt(0)).buffer;
}


function bufToB64u(buf) {
  const bytes = new Uint8Array(buf instanceof ArrayBuffer ? buf : (buf.buffer ?? buf));
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}


/** Serialise a PublicKeyCredential into plain JSON for the server. */
function serializeCredential(cred) {
  const r = cred.response;
  const out = { id: cred.id, type: cred.type, response: { clientDataJSON: bufToB64u(r.clientDataJSON) } };
  if (r.attestationObject) out.response.attestationObject = bufToB64u(r.attestationObject);
  if (r.authenticatorData) out.response.authenticatorData = bufToB64u(r.authenticatorData);
  if (r.signature)         out.response.signature          = bufToB64u(r.signature);
  if (r.userHandle)        out.response.userHandle         = bufToB64u(r.userHandle);
  if (r.getTransports)     out.response.transports         = r.getTransports();
  return out;
}


/** Convert server-side registration options into the form navigator.credentials.create() expects. */
function prepareCreateOptions(opts) {
  return {
    ...opts,
    challenge: b64uToBuffer(opts.challenge),
    user: { ...opts.user, id: b64uToBuffer(opts.user.id) },
    excludeCredentials: (opts.excludeCredentials || []).map((c) => ({ ...c, id: b64uToBuffer(c.id) })),
  };
}


/** Convert server-side authentication options into the form navigator.credentials.get() expects. */
function prepareGetOptions(opts) {
  return {
    challenge: b64uToBuffer(opts.challenge),
    timeout: opts.timeout || 60000,
    rpId: opts.rpId,
    allowCredentials: (opts.allowCredentials || []).map((c) => ({ ...c, id: b64uToBuffer(c.id) })),
    userVerification: opts.userVerification || 'preferred',
  };
}


class LoginPage extends PanicElement {
  async connect() {
    // ── Magic-link landing ────────────────────────────────────────────────
    // Critical: do NOT call /auth/verify on page load. iMessage / SMS link
    // previewers and some corporate scanners execute the page's JavaScript,
    // and a verify call here would mark the token used_at and burn it
    // before the human ever clicks the bubble. Instead we render an
    // explicit "Continue" interstitial and verify only on a real click.
    const urlToken = new URLSearchParams(location.search).get('token');
    if (urlToken) {
      await this.renderTokenLanding(urlToken);
      return;
    }
    this.email = '';
    this.showEmailStep();
    this.startConditionalPasskey();
  }

  /** Step 0: explicit "Continue to your account" interstitial for magic-link URLs. */
  async renderTokenLanding(token) {
    this.innerHTML = `<main class="auth-card"><pb-loading-state label="Checking your link"></pb-loading-state></main>`;
    let status;
    try {
      status = await api('/auth/verify-status', { method: 'POST', body: JSON.stringify({ token }) });
    } catch {
      this.showEmailStep('We could not check that login link. Please request a new one.');
      return;
    }
    if (!status?.valid) {
      this.showEmailStep('That login link is invalid or has already been used. Request a new one below.');
      return;
    }

    const greeting = status.name ? esc(status.name) : esc(status.email);
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      <p class="auth-greeting">Hi, <strong>${greeting}</strong></p>
      <p class="muted">Click below to finish signing in. This link can only be used once.</p>
      <button class="primary block" data-action="continue" type="button">Continue to your account</button>
      <p class="auth-sub"><a href="#" data-action="request-new">Send a new login link</a></p>
      <p class="error-text" data-error></p>
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-action="continue"]', this).addEventListener('click', () => this.consumeToken(token));
    $('[data-action="request-new"]', this).addEventListener('click', (e) => {
      e.preventDefault();
      this.email = status.email || '';
      this.showEmailStep('Enter your email and we will send a fresh link.');
    });
  }

  async consumeToken(token) {
    const btn = $('[data-action="continue"]', this);
    if (btn) { btn.disabled = true; btn.textContent = 'Signing you in…'; }
    try {
      const data = await api('/auth/verify', { method: 'POST', body: JSON.stringify({ token }) });
      this.completeLogin(data);
    } catch (err) {
      const errEl = $('[data-error]', this);
      if (errEl) errEl.textContent = err.message || 'Could not sign in. Request a fresh link.';
      if (btn) { btn.disabled = false; btn.textContent = 'Continue to your account'; }
    }
  }

  /** Hand off to the app after any successful sign-in path. */
  completeLogin(data) {
    setTokens(data.access_token, data.refresh_token);
    publish('auth.changed', data);
    // The app shell calls /api/me on load and decides whether to show the
    // credential-setup modal from that — no client-side hint needed.
    location.href = appUrl();
  }

  // ── Email-first multi-step sign-in ────────────────────────────────────────

  /** Step 1: just an email field. We branch from here based on what the account has. */
  showEmailStep(notice = '') {
    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      ${notice ? `<div class="auth-notice ${notice.startsWith('✓') ? 'success' : 'error'}">${esc(notice)}</div>` : ''}

      <form class="stack" data-form="email-step">
        <label>Email <input type="email" name="email" required autocomplete="username webauthn" placeholder="you@example.com" autofocus value="${esc(this.email || '')}"></label>
        <button class="primary block" type="submit">Continue</button>
        <p class="error-text" data-email-error></p>
      </form>

      <div class="auth-or"><span>or</span></div>

      <button class="passkey-btn" data-action="passkey" type="button">
        <span class="passkey-icon">🔑</span>Sign in with passkey
      </button>
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-form="email-step"]', this).addEventListener('submit', (e) => this.onEmailContinue(e));
    $('[data-action="passkey"]', this).addEventListener('click', () => this.passkeyLogin());
  }

  /** Step 2: per-account methods. Always offers the magic-link fallback. */
  showMethodStep(email, methods) {
    this.email = email;
    const friendly = methods?.name ? esc(methods.name) : esc(email);

    const blocks = [];
    if (methods?.has_passkey) {
      blocks.push(`<button class="passkey-btn" data-action="passkey" type="button">
        <span class="passkey-icon">🔑</span>Sign in with passkey
      </button>`);
    }
    if (methods?.has_password) {
      if (blocks.length) blocks.push(`<div class="auth-or"><span>or password</span></div>`);
      blocks.push(`<form class="stack" data-form="password">
        <input type="hidden" name="email" value="${esc(email)}">
        <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Password" autofocus></label>
        <button type="submit">Sign in</button>
        <p class="error-text" data-pw-error></p>
      </form>`);
    }
    if (blocks.length) blocks.push(`<div class="auth-or"><span>or</span></div>`);

    // Magic-link is always offered. For accounts with no credentials yet
    // this is the primary path; otherwise it's the fallback.
    const isOnly = !methods?.has_password && !methods?.has_passkey;
    blocks.push(`<form class="stack" data-form="magic-link">
      <input type="hidden" name="email" value="${esc(email)}">
      <button type="submit" class="${isOnly ? 'primary block' : ''}">Email me a login link</button>
      ${isOnly ? '<p class="muted small">We will email <strong>' + esc(email) + '</strong> a one-time link that expires in 24 hours.</p>' : ''}
      <p class="error-text" data-ml-error></p>
    </form>`);

    this.innerHTML = `<main class="auth-card">
      <h1>Panic Backstage</h1>
      <p class="auth-greeting">Signing in as <strong>${friendly}</strong> <a href="#" data-action="back" class="small">change</a></p>
      ${blocks.join('\n')}
    </main>
    <pb-toast-stack></pb-toast-stack>`;

    $('[data-action="back"]', this).addEventListener('click', (e) => {
      e.preventDefault();
      this.showEmailStep();
    });
    $('[data-action="passkey"]', this)?.addEventListener('click', () => this.passkeyLogin(email));
    $('[data-form="password"]', this)?.addEventListener('submit', (e) => this.passwordLogin(e));
    $('[data-form="magic-link"]', this).addEventListener('submit', (e) => this.requestMagicLink(e));
  }

  async onEmailContinue(event) {
    event.preventDefault();
    const fd = formData(event.target);
    const email = String(fd.email || '').trim().toLowerCase();
    if (!email) return;
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Checking…';
    $('[data-email-error]', this).textContent = '';
    try {
      const methods = await api('/auth/lookup', { method: 'POST', body: JSON.stringify({ email }) });
      this.showMethodStep(email, methods);
    } catch (err) {
      $('[data-email-error]', this).textContent = err.message || 'Could not look that up. Try again.';
      btn.disabled = false;
      btn.textContent = 'Continue';
    }
  }

  // ── Passkey / password / magic-link handlers ──────────────────────────────

  /** Browser-native passkey autocomplete on the email field. Silent, non-blocking. */
  async startConditionalPasskey() {
    try {
      if (!window.PublicKeyCredential?.isConditionalMediationAvailable) return;
      const available = await PublicKeyCredential.isConditionalMediationAvailable();
      if (!available) return;
      const opts = await fetch(apiUrl('/auth/passkey-login-begin'), {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
      }).then((r) => r.json());
      if (!opts.challenge) return;
      const cred = await navigator.credentials.get({ publicKey: prepareGetOptions(opts), mediation: 'conditional' });
      if (cred) await this.finishPasskeyLogin(cred);
    } catch { /* cancelled or unsupported — silently ignore */ }
  }

  async passkeyLogin(email = '') {
    const btn = $('[data-action="passkey"]', this);
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="passkey-icon">🔑</span>Waiting for passkey…';
    }
    try {
      const opts = await fetch(apiUrl('/auth/passkey-login-begin'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(email ? { email } : {}),
      }).then((r) => r.json());
      const cred = await navigator.credentials.get({ publicKey: prepareGetOptions(opts) });
      await this.finishPasskeyLogin(cred);
    } catch (err) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="passkey-icon">🔑</span>Sign in with passkey'; }
      if (err?.name !== 'NotAllowedError') {
        const errEl = $('[data-pw-error]', this) || $('[data-email-error]', this);
        if (errEl) errEl.textContent = err.message || 'Passkey sign-in failed';
      }
    }
  }

  async finishPasskeyLogin(cred) {
    const body = serializeCredential(cred);
    const res  = await fetch(apiUrl('/auth/passkey-login-complete'), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Passkey login failed');
    this.completeLogin(data);
  }

  async passwordLogin(event) {
    event.preventDefault();
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Signing in…';
    $('[data-pw-error]', this).textContent = '';
    try {
      const data = await api('/auth/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      this.completeLogin(data);
    } catch (err) {
      $('[data-pw-error]', this).textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Sign in';
    }
  }

  async requestMagicLink(event) {
    event.preventDefault();
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    btn.textContent = 'Sending…';
    $('[data-ml-error]', this).textContent = '';
    try {
      await api('/auth/magic-link', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      event.target.innerHTML = `<div class="auth-notice success">✓ Login link sent — check your email. It expires in 24 hours and can be used once.</div>`;
    } catch (err) {
      $('[data-ml-error]', this).textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Email me a login link';
    }
  }
}


// ── Credential setup modal ──────────────────────────────────────────────────
//
// Shown after sign-in when a user has neither a passkey nor a password.
// Dismissible — re-shown on every sign-in until they set up at least one
// credential or check "Don't show this again". Reuses the existing
// `/auth/passkey-register-*` and `/auth/set-password` endpoints.

function openCredentialSetupModal(user, onChange) {
  if (document.querySelector('[data-credential-setup-modal]')) return; // already open
  const dialog = document.createElement('div');
  dialog.className = 'modal-backdrop';
  dialog.setAttribute('data-credential-setup-modal', '');
  dialog.innerHTML = renderCredentialSetupBody(user);
  document.body.appendChild(dialog);

  const close = () => dialog.remove();
  const refresh = async () => {
    try {
      const me = await api('/me');
      if (me?.user) {
        user = me.user;
        if (typeof onChange === 'function') onChange(user);
        // If they now have a credential, close the modal.
        if (user.has_password || user.has_passkey) {
          publish('toast.show', { message: 'Sign-in method saved.', tone: 'success' });
          close();
          return;
        }
        dialog.querySelector('.modal-card').outerHTML = renderCredentialSetupBody(user, true);
        bind();
      }
    } catch { /* ignore */ }
  };

  const bind = () => {
    $('[data-action="skip"]', dialog).addEventListener('click', async () => {
      const hide = $('[data-hide-future]', dialog)?.checked;
      if (hide) {
        try {
          await api('/auth/preferences', {
            method: 'POST',
            body: JSON.stringify({ hide_credential_setup_prompt: true }),
          });
          user.hide_credential_setup_prompt = true;
          if (typeof onChange === 'function') onChange(user);
        } catch (err) {
          publish('toast.show', { message: err.message || 'Could not save preference', tone: 'error' });
          return;
        }
      }
      close();
    });

    $('[data-action="add-passkey"]', dialog)?.addEventListener('click', async () => {
      const btn = $('[data-action="add-passkey"]', dialog);
      btn.disabled = true;
      btn.textContent = 'Waiting for device…';
      try {
        const opts = await api('/auth/passkey-register-begin', { method: 'POST', body: '{}' });
        const cred = await navigator.credentials.create({ publicKey: prepareCreateOptions(opts) });
        const body = serializeCredential(cred);
        const name = (cred.authenticatorAttachment === 'platform' ? 'This device' : 'Security key')
                   + ' — ' + new Date().toLocaleDateString();
        await api('/auth/passkey-register-complete', {
          method: 'POST',
          body: JSON.stringify({ name, response: body.response }),
        });
        await refresh();
      } catch (err) {
        if (err?.name !== 'NotAllowedError') {
          publish('toast.show', { message: err.message || 'Could not add passkey', tone: 'error' });
        }
        btn.disabled = false;
        btn.textContent = 'Add a passkey for this device';
      }
    });

    $('[data-form="password"]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = formData(event.target);
      if (fd.password !== fd.confirm_password) {
        $('[data-pw-error]', dialog).textContent = 'Passwords do not match';
        return;
      }
      const btn = $('button[type="submit"]', event.target);
      btn.disabled = true;
      $('[data-pw-error]', dialog).textContent = '';
      try {
        // current_password is empty — user has no password yet, the server
        // accepts that path.
        await api('/auth/set-password', { method: 'POST', body: JSON.stringify(fd) });
        await refresh();
      } catch (err) {
        $('[data-pw-error]', dialog).textContent = err.message || 'Could not set password';
        btn.disabled = false;
      }
    });
  };

  bind();
}


function renderCredentialSetupBody(user, isRefresh = false) {
  const passkeySupported = Boolean(window.PublicKeyCredential);
  const name = user?.name || user?.email || 'there';
  return `<div class="modal-card credential-setup-card">
    <div class="section-head padded">
      <h2>Make future sign-ins faster</h2>
    </div>
    <div class="padded">
      <p>Welcome, <strong>${esc(name)}</strong>. You're signed in.</p>
      <p class="muted">Email links work, but they can get eaten by message previews before you click them.
        Set up one (or both) of the options below so future sign-ins go straight through.</p>

      <div class="credential-setup-options">
        ${passkeySupported ? `
        <div class="credential-setup-option">
          <h3><span class="passkey-icon">🔑</span>Passkey</h3>
          <p class="muted small">Use Face ID, Touch ID, Windows Hello, or your password manager. Fastest option on a phone or laptop you trust.</p>
          <button class="primary block" data-action="add-passkey" type="button">Add a passkey for this device</button>
        </div>` : `
        <div class="credential-setup-option muted">
          <h3>Passkey</h3>
          <p class="small">Your browser does not support passkeys. Set a password instead.</p>
        </div>`}

        <div class="credential-setup-option">
          <h3><i class="fa-solid fa-lock" aria-hidden="true"></i> Password</h3>
          <p class="muted small">Classic email + password. Works everywhere.</p>
          <form class="stack" data-form="password">
            <label>New password <input type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters" minlength="8"></label>
            <label>Confirm <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Same password again"></label>
            <button type="submit">Set password</button>
            <p class="error-text" data-pw-error></p>
          </form>
        </div>
      </div>

      <div class="credential-setup-footer">
        <label class="checkbox-row">
          <input type="checkbox" data-hide-future>
          <span>Don't show this again — I'm fine using email links</span>
        </label>
        <button class="secondary" data-action="skip" type="button">Skip for now</button>
      </div>
    </div>
  </div>`;
}


class AccountSettings extends PanicElement {
  async connect() {
    this.setLoading('Loading account settings');
    try {
      const [data, me] = await Promise.all([
        api('/auth/passkeys', { method: 'POST', body: '{}' }),
        api('/me'),
      ]);
      this.passkeys           = data.passkeys || [];
      this.hasPassword        = Boolean(data.has_password);
      this.profile            = {
        name:  me?.user?.name  || '',
        email: me?.user?.email || '',
        phone: me?.user?.phone || '',
      };
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const passkeySupported = Boolean(window.PublicKeyCredential);
    this.innerHTML = `<section class="page-head">
      <div><h1>Account Settings</h1><p class="subtle">Manage your profile and login methods.</p></div>
    </section>
    <div class="panel padded" style="max-width: 560px">

      <div class="account-section">
        <h2>Profile</h2>
        <p class="muted">Your name, email, and phone. Email is also your sign-in address.</p>
        <form class="stack" data-form="profile" style="margin-top:14px">
          <label>Name <input type="text" name="name" required autocomplete="name" value="${esc(this.profile.name)}" placeholder="Full name"></label>
          <label>Email <input type="email" name="email" required autocomplete="email" value="${esc(this.profile.email)}" placeholder="you@example.com"></label>
          <label>Phone <input type="tel" name="phone" autocomplete="tel" value="${esc(this.profile.phone)}" placeholder="Optional"></label>
          <button type="submit">Save profile</button>
          <p class="error-text" data-profile-error></p>
        </form>
      </div>

      <div class="account-section">
        <h2>Passkeys (biometric login)</h2>
        ${this.passkeys.length
          ? `<div class="passkey-list">${this.passkeys.map((pk) => `
            <div class="passkey-item">
              <span class="passkey-icon" style="font-size:20px">🔑</span>
              <div class="passkey-item-info">
                <div class="passkey-item-name">${esc(pk.name)}</div>
                <div class="passkey-item-meta">Added ${esc(new Date(pk.created_at).toLocaleDateString())}${pk.last_used_at ? ' · Last used ' + esc(new Date(pk.last_used_at).toLocaleDateString()) : ''}</div>
              </div>
              <button class="button" style="background:var(--danger,#dc2626)" data-remove="${esc(pk.id)}">Remove</button>
            </div>`).join('')}</div>`
          : `<p class="muted" style="margin-bottom:12px">No passkeys registered yet.</p>`}
        ${passkeySupported
          ? `<button class="button" data-action="add-passkey">+ Add passkey for this device</button>`
          : `<p class="muted">Your browser does not support passkeys.</p>`}
      </div>

      <div class="account-section">
        <h2>${this.hasPassword ? 'Change password' : 'Set a password'}</h2>
        <p class="muted">${this.hasPassword ? 'Enter your current password before setting a new one.' : 'A password lets you sign in alongside passkeys and email links.'}</p>
        <form class="stack" data-form="password" style="margin-top:14px">
          ${this.hasPassword ? `<label>Current password <input type="password" name="current_password" required autocomplete="current-password" placeholder="Current password"></label>` : ''}
          <label>New password <input type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters" minlength="8"></label>
          <label>Confirm <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Same password again"></label>
          <button type="submit">${this.hasPassword ? 'Change password' : 'Set password'}</button>
          <p class="error-text" data-pw-error></p>
        </form>
      </div>

    </div>`;

    $('[data-form="profile"]', this)?.addEventListener('submit', (e) => this.saveProfile(e));
    $$('[data-remove]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        if (confirm('Remove this passkey? You will no longer be able to sign in with it.')) {
          this.removePasskey(Number(btn.dataset.remove));
        }
      });
    });
    $('[data-action="add-passkey"]', this)?.addEventListener('click', () => this.addPasskey());
    $('[data-form="password"]', this)?.addEventListener('submit', (e) => this.setPassword(e));
  }

  async saveProfile(event) {
    event.preventDefault();
    const fd = formData(event.target);
    const btn = $('button[type="submit"]', event.target);
    const errEl = $('[data-profile-error]', this);
    errEl.textContent = '';
    btn.disabled = true;
    try {
      const res = await api('/auth/profile', { method: 'POST', body: JSON.stringify(fd) });
      this.profile = { name: res.user.name, email: res.user.email, phone: res.user.phone || '' };
      // Keep the shared user + header pill in sync.
      const current = getAppUser() || {};
      setAppUser({ ...current, name: res.user.name, email: res.user.email, phone: res.user.phone });
      const pill = document.querySelector('pb-app-shell [data-user-pill]');
      if (pill) pill.textContent = res.user.name || res.user.email || 'Account';
      publish('toast.show', { message: 'Profile saved.', tone: 'success' });
      btn.disabled = false;
    } catch (err) {
      errEl.textContent = err.message || 'Could not save profile';
      btn.disabled = false;
    }
  }

  async removePasskey(id) {
    try {
      await api('/auth/remove-passkey', { method: 'POST', body: JSON.stringify({ id }) });
      publish('toast.show', { message: 'Passkey removed.', tone: 'info' });
      this.passkeys = this.passkeys.filter((pk) => pk.id !== id);
      this.render();
    } catch (err) {
      publish('toast.show', { message: err.message, tone: 'error' });
    }
  }

  async addPasskey() {
    const btn = $('[data-action="add-passkey"]', this);
    if (btn) { btn.disabled = true; btn.textContent = 'Waiting for device…'; }
    try {
      const opts = await api('/auth/passkey-register-begin', { method: 'POST', body: '{}' });
      const cred = await navigator.credentials.create({ publicKey: prepareCreateOptions(opts) });
      const body = serializeCredential(cred);
      const name = (cred.authenticatorAttachment === 'platform' ? 'This device' : 'Security key')
                 + ' — ' + new Date().toLocaleDateString();
      await api('/auth/passkey-register-complete', {
        method: 'POST',
        body: JSON.stringify({ name, response: body.response }),
      });
      publish('toast.show', { message: 'Passkey added — you can now sign in with biometrics.', tone: 'success' });
      this.connect();
    } catch (err) {
      if (err?.name !== 'NotAllowedError') {
        publish('toast.show', { message: err.message || 'Could not add passkey', tone: 'error' });
      }
      if (btn) { btn.disabled = false; btn.textContent = '+ Add passkey for this device'; }
    }
  }

  async setPassword(event) {
    event.preventDefault();
    const fd = formData(event.target);
    if (fd.password !== fd.confirm_password) {
      $('[data-pw-error]', this).textContent = 'Passwords do not match';
      return;
    }
    const btn = $('button[type="submit"]', event.target);
    btn.disabled = true;
    $('[data-pw-error]', this).textContent = '';
    try {
      await api('/auth/set-password', { method: 'POST', body: JSON.stringify(fd) });
      publish('toast.show', { message: 'Password saved.', tone: 'success' });
      this.hasPassword = true;
      this.render();
    } catch (err) {
      $('[data-pw-error]', this).textContent = err.message;
      btn.disabled = false;
    }
  }
}


// ── Preferences page ──────────────────────────────────────────────────────────
// UI preferences stored as columns on the user (see /api/auth/preferences):
// default landing page, sidebar-collapsed default, events default sort, and the
// credential-setup nudge (formerly on the Account page).
const LANDING_OPTIONS = [
  { value: '',          label: 'Dashboard (default)' },
  { value: 'calendar',  label: 'Calendar' },
  { value: 'pipeline',  label: 'Pipeline' },
  { value: 'events',    label: 'Events list' },
  { value: 'templates', label: 'Templates' },
];


class Preferences extends PanicElement {
  async connect() {
    this.setLoading('Loading preferences');
    try {
      let user = getAppUser();
      if (!user) user = (await api('/me'))?.user || {};
      this.prefs = {
        default_landing: user.default_landing || '',
        nav_collapsed:   Boolean(user.nav_collapsed),
        events_sort:     user.events_sort || '',
        hide_credential_setup_prompt: Boolean(user.hide_credential_setup_prompt),
      };
      this.render();
    } catch (err) {
      this.showError(err);
    }
  }

  render() {
    const landing = this.prefs.default_landing;
    const sort = this.prefs.events_sort;
    this.innerHTML = `<section class="page-head">
      <div><h1>Preferences</h1><p class="subtle">Tune how Backstage looks and behaves for your account.</p></div>
    </section>
    <div class="panel padded" style="max-width: 560px">

      <div class="account-section">
        <h2>Landing page</h2>
        <p class="muted">Which page opens when you sign in.</p>
        <label>Default page
          <select data-pref="default_landing">
            ${LANDING_OPTIONS.map((o) => `<option value="${esc(o.value)}" ${o.value === landing ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}
          </select>
        </label>
      </div>

      <div class="account-section">
        <h2>Events list</h2>
        <p class="muted">Default sort order for the Events list.</p>
        <label>Sort by date
          <select data-pref="events_sort">
            <option value="" ${sort === '' ? 'selected' : ''}>App default (oldest first)</option>
            <option value="asc" ${sort === 'asc' ? 'selected' : ''}>Oldest first</option>
            <option value="desc" ${sort === 'desc' ? 'selected' : ''}>Newest first</option>
          </select>
        </label>
      </div>

      <div class="account-section">
        <h2>Navigation</h2>
        <label class="checkbox-row">
          <input type="checkbox" data-pref="nav_collapsed" ${this.prefs.nav_collapsed ? 'checked' : ''}>
          <span>Start with the sidebar collapsed (icon-only rail)</span>
        </label>
      </div>

      <div class="account-section">
        <h2>Sign-in nudges</h2>
        <label class="checkbox-row">
          <input type="checkbox" data-pref="remind_credential" ${this.prefs.hide_credential_setup_prompt ? '' : 'checked'}>
          <span>Remind me to set up a passkey or password when I don't have one</span>
        </label>
        <p class="muted small">When on and your account has neither a passkey nor a password, a small modal appears after each sign-in to help you set one up.</p>
      </div>

    </div>`;

    $$('[data-pref]', this).forEach((el) => {
      const evt = el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(evt, () => this.onChange(el));
    });
  }

  async onChange(el) {
    const field = el.dataset.pref;
    let body;
    if (field === 'remind_credential') {
      body = { hide_credential_setup_prompt: !el.checked };
    } else if (field === 'nav_collapsed') {
      body = { nav_collapsed: el.checked };
    } else {
      body = { [field]: el.value };
    }
    try {
      await api('/auth/preferences', { method: 'POST', body: JSON.stringify(body) });
      // Update the local cache so other views reflect the change immediately.
      const current = getAppUser() || {};
      const merged = { ...current, ...body };
      if ('hide_credential_setup_prompt' in body) this.prefs.hide_credential_setup_prompt = body.hide_credential_setup_prompt;
      else if (field === 'nav_collapsed') this.prefs.nav_collapsed = el.checked;
      else this.prefs[field] = el.value;
      setAppUser(merged);
      if (field === 'nav_collapsed') {
        try { localStorage.setItem('pb.navCollapsed', el.checked ? '1' : '0'); } catch { /* storage blocked */ }
        const shell = document.querySelector('pb-app-shell');
        shell?.classList.toggle('nav-collapsed', el.checked);
        shell?.querySelector('[data-nav-toggle]')?.setAttribute('aria-expanded', String(!el.checked));
      }
      publish('toast.show', { message: 'Preference saved.', tone: 'info' });
    } catch (err) {
      publish('toast.show', { message: err.message || 'Could not save preference', tone: 'error' });
    }
  }
}
customElements.define('pb-login-page', LoginPage);
customElements.define('pb-account-settings', AccountSettings);
customElements.define('pb-preferences', Preferences);

export { openCredentialSetupModal };
