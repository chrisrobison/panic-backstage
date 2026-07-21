// Admin > Users ("Login Accounts", public/assets/admin.js AdminUsers +
// src/Users.php). The Create User form used to sit inline at the bottom of
// the tab with Edit/Invite/Delete as per-row buttons; it now opens as a modal
// dialog (the default going forward — see project memory: ui-conventions),
// and Edit/Invite/Delete moved into that same dialog instead of the table.
//
// Destructive against a throwaway user only, cleaned up via a direct API call
// in `finally` (same convention as 97-nav-manager.test.mjs) so cleanup still
// happens even if a UI assertion above it fails.
import { test, assert } from './harness.mjs';

const NAME = 'PB UI TEST user (safe to delete)';
const EMAIL = `pb-ui-test-${Date.now()}@example.invalid`;

async function apiFetch(page, path, opts = {}) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
  const res = await fetch(page.base + '/api' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON (e.g. 204) */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

// Scoped to our own modal's form — harmless if another modal convention
// (e.g. a leftover Contracts/Nav dialog) is open in this shared page session.
const ADD_MODAL = '.modal-backdrop:has([data-form="user"])';

test('Admin > Users "+" opens an Add User modal, no per-row buttons in the table', async (page) => {
  await page.goto('#admin-users');
  await page.until(`document.querySelector('.admin-table')`);
  if (!(await page.exists('[data-add]'))) return page.skip('no manage_users capability for this user');

  assert.notOk(await page.exists(ADD_MODAL), 'no Add User modal open before clicking +');
  assert.equal(await page.count('.admin-table tbody tr[data-user-id] .row-actions'), 0, 'no per-row action buttons remain in the Login Accounts table');

  await page.click('[data-add]');
  assert.ok(await page.exists(ADD_MODAL), 'modal opens with the Add User form');
  assert.includes(await page.text(`${ADD_MODAL} h2`), 'Add user', 'dialog title is "Add user"');
  for (const field of ['name', 'email', 'role', 'password']) {
    assert.ok(await page.exists(`${ADD_MODAL} [name="${field}"]`), `${field} field present`);
  }
  await page.click(`${ADD_MODAL} [data-close]`);
  assert.notOk(await page.exists(ADD_MODAL), 'modal closes via the Close button');
});

test('Creating a user via the modal, then editing it, exposes Save/Send invite/Delete in one dialog', async (page) => {
  await page.goto('#admin-users');
  await page.until(`document.querySelector('.admin-table')`);
  if (!(await page.exists('[data-add]'))) return page.skip('no manage_users capability for this user');

  let userId = null;
  try {
    // --- create a throwaway user via the real "+" UI ---
    await page.click('[data-add]');
    await page.until(`document.querySelector('${ADD_MODAL}')`);
    await page.setValue(`${ADD_MODAL} [name="name"]`, NAME);
    await page.setValue(`${ADD_MODAL} [name="email"]`, EMAIL);
    await page.click(`${ADD_MODAL} button[type="submit"]`);
    await page.until(`document.querySelector('.admin-table') && document.querySelector('.admin-table').textContent.includes(${JSON.stringify(NAME)})`);
    assert.notOk(await page.exists(ADD_MODAL), 'modal closes after a successful create');

    const rows = await apiFetch(page, '/users');
    const created = (rows.users || []).find((u) => u.email === EMAIL);
    assert.ok(created, 'new user is persisted server-side');
    userId = created.id;

    // --- clicking the row (not a button — there are none) opens Edit ---
    await page.click(`tr[data-user-id="${userId}"]`);
    const EDIT_MODAL = `.modal-backdrop:has([data-form="user"])`;
    await page.until(`document.querySelector('${EDIT_MODAL}')`);
    assert.includes(await page.text(`${EDIT_MODAL} h2`), 'Edit user', 'dialog title is "Edit user"');
    assert.equal(await page.eval(`document.querySelector('${EDIT_MODAL} [name="name"]').value`), NAME, 'name field pre-filled');
    assert.ok(await page.exists(`${EDIT_MODAL} [data-invite]`), 'Send invite button present in the edit modal');
    assert.ok(await page.exists(`${EDIT_MODAL} [data-delete]`), 'Delete button present in the edit modal');
    assert.ok(await page.exists(`${EDIT_MODAL} [data-emails-mount] pb-user-emails`), 'email-addresses section mounts inside the edit modal');

    // --- delete via the in-modal Delete button (confirm() stubbed to true) ---
    await page.eval(`window.confirm = () => true`);
    await page.click(`${EDIT_MODAL} [data-delete]`);
    await page.until(`!document.querySelector('${EDIT_MODAL}')`);
    userId = null; // deleted through the UI — nothing left for the finally block to clean up
  } finally {
    if (userId) await apiFetch(page, `/users/${userId}`, { method: 'DELETE' }).catch(() => {});
  }
});
