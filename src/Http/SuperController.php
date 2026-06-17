<?php

declare(strict_types=1);

namespace Panic\Http;

use Panic\Database\Connection;
use Panic\Tenant\TenantProvisioner;
use PDO;

/**
 * Super-admin controller for multi-tenant management.
 *
 * All routes under /super and /api/super are handled here.
 * dispatch() is called from public/api/index.php *before* tenant resolution,
 * so this controller uses Connection::super() directly and never touches a
 * tenant database.
 *
 * Authentication: PHP session key 'backstage_super_admin' set on login.
 * Auth uses password_hash() / password_verify() against super_admin_users.
 *
 * Routes:
 *   GET  /super/tenants                            — HTML admin UI
 *   POST /api/super/login                          — authenticate super admin
 *   POST /api/super/logout                         — end session
 *   GET  /api/super/tenants                        — list tenants + domains
 *   POST /api/super/tenants                        — create + provision tenant
 *   GET  /api/super/tenants/{id}                   — single tenant
 *   PATCH /api/super/tenants/{id}                  — update tenant
 *   POST /api/super/tenants/{id}/provision         — re-provision tenant DB
 *   POST /api/super/tenants/{id}/domains           — add domain alias
 *   DELETE /api/super/tenants/{id}/domains/{domId} — remove domain alias
 *   GET  /api/super/me                             — current super admin info
 */
final class SuperController
{
    // ─── Entry point ─────────────────────────────────────────────────────────

    /** @return never */
    public static function dispatch(string $path, string $method): never
    {
        // Start session if not already started (super admin uses PHP sessions).
        if (session_status() === PHP_SESSION_NONE) {
            session_name('backstage_super_sid');
            session_start();
        }

        // ── HTML UI ──────────────────────────────────────────────────────────
        if ($path === '/super' || $path === '/super/tenants') {
            if ($method !== 'GET') {
                self::jsonExit(['error' => 'Method not allowed'], 405);
            }
            self::htmlUi();
        }

        // All remaining routes are JSON API under /api/super/
        if (!str_starts_with($path, '/api/super')) {
            self::jsonExit(['error' => 'Not found'], 404);
        }

        // Strip /api/super prefix for easier matching below.
        $sub = substr($path, strlen('/api/super')) ?: '/';

        // ── Public (no auth required) ─────────────────────────────────────────
        if ($sub === '/login' && $method === 'POST') {
            self::login();
        }
        if ($sub === '/logout' && $method === 'POST') {
            unset($_SESSION['backstage_super_admin']);
            self::jsonExit(['ok' => true]);
        }

        // ── Auth gate ─────────────────────────────────────────────────────────
        self::requireAuth();

        // ── Authenticated routes ───────────────────────────────────────────────
        if ($sub === '/me' && $method === 'GET') {
            self::jsonExit(['user' => $_SESSION['backstage_super_admin']]);
        }

        if ($sub === '/tenants' && $method === 'GET') {
            self::jsonExit(['tenants' => self::listTenants()]);
        }

        if ($sub === '/tenants' && $method === 'POST') {
            self::createTenant();
        }

        // Routes with a tenant ID segment: /tenants/{id}[/...]
        if (preg_match('#^/tenants/(\d+)(/.*)?$#', $sub, $m)) {
            $tenantId = (int) $m[1];
            $rest     = $m[2] ?? '';

            if ($rest === '' && $method === 'GET') {
                self::showTenant($tenantId);
            }
            if ($rest === '' && $method === 'PATCH') {
                self::updateTenant($tenantId);
            }
            if ($rest === '/provision' && $method === 'POST') {
                self::provisionTenant($tenantId);
            }
            if ($rest === '/domains' && $method === 'POST') {
                self::addDomain($tenantId);
            }
            if (preg_match('#^/domains/(\d+)$#', $rest, $dm) && $method === 'DELETE') {
                self::deleteDomain($tenantId, (int) $dm[1]);
            }
        }

        self::jsonExit(['error' => 'Not found'], 404);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    /** @return never */
    private static function login(): never
    {
        $body     = self::jsonBody();
        $email    = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($email === '' || $password === '') {
            self::jsonExit(['error' => 'Email and password are required'], 422);
        }

        $stmt = Connection::super()->prepare(
            'SELECT id, email, password_hash, display_name FROM super_admin_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            self::jsonExit(['error' => 'Invalid credentials'], 401);
        }

        $_SESSION['backstage_super_admin'] = [
            'id'           => (int)$user['id'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
        ];

        self::jsonExit(['user' => $_SESSION['backstage_super_admin']]);
    }

    private static function requireAuth(): void
    {
        if (empty($_SESSION['backstage_super_admin'])) {
            self::jsonExit(['error' => 'Super admin authentication required'], 401);
        }
    }

    // ─── Tenant CRUD ──────────────────────────────────────────────────────────

    /** @return never */
    private static function createTenant(): never
    {
        $body = self::jsonBody();

        $slug   = trim((string)($body['slug']          ?? ''));
        $name   = trim((string)($body['name']          ?? ''));
        $dbName = trim((string)($body['database_name'] ?? ''));

        if ($slug === '' || $name === '') {
            self::jsonExit(['error' => '`slug` and `name` are required'], 422);
        }

        // Sanitize slug: lowercase, replace non-alphanum with underscore.
        $slug = preg_replace('/[^a-z0-9_-]/', '_', strtolower($slug)) ?? $slug;

        // Auto-derive database_name from slug if not supplied.
        if ($dbName === '') {
            $safeSlug = str_replace('-', '_', $slug);
            $dbName   = 'panic_backstage_' . $safeSlug;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            self::jsonExit(['error' => 'Invalid database_name — only letters, digits, underscores allowed'], 422);
        }

        $db = Connection::super();

        // Check uniqueness.
        $existing = $db->prepare('SELECT id FROM tenants WHERE slug = ? OR database_name = ? LIMIT 1');
        $existing->execute([$slug, $dbName]);
        if ($existing->fetch()) {
            self::jsonExit(['error' => 'A tenant with that slug or database name already exists'], 409);
        }

        // Insert.
        $stmt = $db->prepare(
            'INSERT INTO tenants (slug, name, database_name, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $dbName, 'provisioning']);
        $tenantId = (int)$db->lastInsertId();

        // Fetch the new row.
        $tenant = $db->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $tenant->execute([$tenantId]);
        $tenantRow = (array)$tenant->fetch();

        // Provision the database.
        try {
            TenantProvisioner::provision($tenantRow);
            $db->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
            $tenantRow['status'] = 'active';
        } catch (\Throwable $e) {
            $db->prepare("UPDATE tenants SET status = 'provisioning' WHERE id = ?")->execute([$tenantId]);
            self::jsonExit([
                'error'  => 'Tenant created but database provisioning failed: ' . $e->getMessage(),
                'tenant' => $tenantRow,
            ], 500);
        }

        self::jsonExit(['tenant' => $tenantRow], 201);
    }

    /** @return never */
    private static function showTenant(int $id): never
    {
        $tenant = self::fetchTenant($id);
        $tenant['domains'] = self::fetchDomains($id);
        self::jsonExit(['tenant' => $tenant]);
    }

    /** @return never */
    private static function updateTenant(int $id): never
    {
        $tenant = self::fetchTenant($id);
        $body   = self::jsonBody();

        $allowed = ['name', 'status'];
        $fields  = [];
        $values  = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $fields[] = "{$field} = ?";
                $values[] = $body[$field];
            }
        }

        if (empty($fields)) {
            self::jsonExit(['error' => 'Nothing to update — supply `name` and/or `status`'], 422);
        }

        // Validate status if provided.
        if (isset($body['status']) && !in_array($body['status'], ['active', 'suspended', 'provisioning'], true)) {
            self::jsonExit(['error' => 'Invalid status — must be active, suspended, or provisioning'], 422);
        }

        $values[] = $id;
        Connection::super()->prepare(
            'UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($values);

        $updated = self::fetchTenant($id);
        $updated['domains'] = self::fetchDomains($id);
        self::jsonExit(['tenant' => $updated]);
    }

    /** @return never */
    private static function provisionTenant(int $id): never
    {
        $tenant = self::fetchTenant($id);

        try {
            TenantProvisioner::provision($tenant);
            Connection::super()->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$id]);
        } catch (\Throwable $e) {
            self::jsonExit(['error' => 'Provisioning failed: ' . $e->getMessage()], 500);
        }

        $updated = self::fetchTenant($id);
        $updated['domains'] = self::fetchDomains($id);
        self::jsonExit(['tenant' => $updated]);
    }

    // ─── Domain management ────────────────────────────────────────────────────

    /** @return never */
    private static function addDomain(int $tenantId): never
    {
        self::fetchTenant($tenantId); // 404 guard

        $body      = self::jsonBody();
        $domain    = strtolower(trim((string)($body['domain'] ?? '')));
        $isPrimary = (bool)($body['is_primary'] ?? false);

        if ($domain === '') {
            self::jsonExit(['error' => '`domain` is required'], 422);
        }
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            self::jsonExit(['error' => 'Invalid domain format'], 422);
        }

        $db = Connection::super();

        // Check uniqueness across all tenants.
        $exists = $db->prepare('SELECT id FROM tenant_domains WHERE domain = ? LIMIT 1');
        $exists->execute([$domain]);
        if ($exists->fetch()) {
            self::jsonExit(['error' => "Domain {$domain} is already registered"], 409);
        }

        if ($isPrimary) {
            // Demote any existing primary for this tenant.
            $db->prepare('UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ?')->execute([$tenantId]);
        }

        $db->prepare(
            'INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, ?)'
        )->execute([$tenantId, $domain, $isPrimary ? 1 : 0]);

        $domainId  = (int)$db->lastInsertId();
        $newDomain = $db->prepare('SELECT * FROM tenant_domains WHERE id = ? LIMIT 1');
        $newDomain->execute([$domainId]);

        self::jsonExit(['domain' => $newDomain->fetch()], 201);
    }

    /** @return never */
    private static function deleteDomain(int $tenantId, int $domainId): never
    {
        self::fetchTenant($tenantId); // 404 guard

        $db = Connection::super();
        $row = $db->prepare('SELECT * FROM tenant_domains WHERE id = ? AND tenant_id = ? LIMIT 1');
        $row->execute([$domainId, $tenantId]);
        if (!$row->fetch()) {
            self::jsonExit(['error' => 'Domain not found'], 404);
        }

        $db->prepare('DELETE FROM tenant_domains WHERE id = ? AND tenant_id = ?')->execute([$domainId, $tenantId]);
        self::jsonExit(['ok' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private static function listTenants(): array
    {
        $tenants = Connection::super()
            ->query('SELECT * FROM tenants ORDER BY created_at DESC')
            ->fetchAll();

        foreach ($tenants as &$t) {
            $t['domains'] = self::fetchDomains((int)$t['id']);
        }
        unset($t);

        return $tenants ?: [];
    }

    /**
     * @return array<string,mixed>
     * @return never on 404
     */
    private static function fetchTenant(int $id): array
    {
        $stmt = Connection::super()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            self::jsonExit(['error' => 'Tenant not found'], 404);
        }
        return (array)$tenant;
    }

    /** @return array<int,array<string,mixed>> */
    private static function fetchDomains(int $tenantId): array
    {
        $stmt = Connection::super()->prepare(
            'SELECT * FROM tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, id ASC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Decode the request JSON body. Returns an empty array on failure (no body
     * or invalid JSON) so callers can safely access keys with null-coalescing.
     *
     * @return array<string,mixed>
     */
    private static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send a JSON response and exit.
     *
     * @param array<string,mixed> $data
     * @return never
     */
    private static function jsonExit(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ─── HTML admin UI ────────────────────────────────────────────────────────

    /** @return never */
    private static function htmlUi(): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panic Backstage — Super Admin</title>
<style>
  :root { color-scheme: light dark; }
  *, *::before, *::after { box-sizing: border-box; }
  body   { font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
           margin: 0; background: #0f1117; color: #e8e8f0; min-height: 100vh; }
  header { background: #1a1d3a; border-bottom: 1px solid #2e3250;
           padding: .75rem 1.5rem; display: flex; align-items: center; gap: 1rem; }
  header h1 { margin: 0; font-size: 1.1rem; font-weight: 700; letter-spacing: -.02em; }
  header span { margin-left: auto; font-size: .85rem; opacity: .6; }
  #login-screen, #admin-screen { max-width: 900px; margin: 3rem auto; padding: 0 1rem; }
  .card   { background: #1a1d3a; border: 1px solid #2e3250; border-radius: .75rem;
            padding: 1.5rem; margin-bottom: 1.5rem; }
  .card h2 { margin: 0 0 1rem; font-size: 1rem; font-weight: 600; }
  label   { display: block; font-size: .85rem; margin-bottom: .5rem; opacity: .8; }
  input, select { width: 100%; padding: .5rem .75rem; border-radius: .4rem;
          border: 1px solid #3a3f60; background: #0f1117; color: #e8e8f0;
          font-size: .95rem; margin-bottom: 1rem; }
  button  { padding: .55rem 1.25rem; border-radius: .4rem; border: none; cursor: pointer;
            font-size: .9rem; font-weight: 600; }
  .btn-primary  { background: #6c63ff; color: #fff; }
  .btn-danger   { background: #e05c5c; color: #fff; }
  .btn-sm       { padding: .35rem .75rem; font-size: .8rem; }
  table   { width: 100%; border-collapse: collapse; font-size: .88rem; }
  th, td  { text-align: left; padding: .5rem .75rem; border-bottom: 1px solid #2e3250; }
  th      { font-weight: 600; opacity: .7; }
  .badge  { display: inline-block; padding: .15em .55em; border-radius: 999px;
            font-size: .75rem; font-weight: 600; }
  .badge-active      { background: #1e4d2b; color: #4ade80; }
  .badge-suspended   { background: #4d1e1e; color: #f87171; }
  .badge-provisioning { background: #3b3000; color: #fbbf24; }
  .domains { font-size: .8rem; opacity: .7; }
  .err    { color: #f87171; font-size: .85rem; margin-top: .5rem; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  #status-msg { margin-top: .5rem; font-size: .85rem; }
  .hidden { display: none; }
</style>
</head>
<body>
<header>
  <h1>⚡ Panic Backstage — Super Admin</h1>
  <span id="user-info"></span>
  <button class="btn-sm" id="logout-btn" style="display:none" onclick="logout()">Log out</button>
</header>

<div id="login-screen">
  <div class="card" style="max-width:380px;margin:0 auto">
    <h2>Super Admin Login</h2>
    <label>Email<input type="email" id="login-email" autocomplete="username"></label>
    <label>Password<input type="password" id="login-pass" autocomplete="current-password"
           onkeydown="if(event.key==='Enter')doLogin()"></label>
    <button class="btn-primary" onclick="doLogin()">Log in</button>
    <div class="err" id="login-err"></div>
  </div>
</div>

<div id="admin-screen" class="hidden">
  <div class="card">
    <h2>Tenants</h2>
    <div id="tenant-list"><em>Loading…</em></div>
  </div>
  <div class="card">
    <h2>Create Tenant</h2>
    <div class="form-row">
      <label>Name (display)<input type="text" id="new-name" placeholder="Mabuhay Gardens"></label>
      <label>Slug (URL key)<input type="text" id="new-slug" placeholder="mabuhay"></label>
    </div>
    <label style="font-size:.8rem;opacity:.6">Database name (auto-derived if blank)
      <input type="text" id="new-dbname" placeholder="panic_backstage_mabuhay"></label>
    <button class="btn-primary" onclick="createTenant()">Create + Provision</button>
    <div id="status-msg"></div>
  </div>
</div>

<script>
const api = p => fetch('/api/super' + p, { credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' } });
const post = (p, body) => fetch('/api/super' + p, {
  method: 'POST', credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body)
});
const del = p => fetch('/api/super' + p, { method: 'DELETE', credentials: 'same-origin' });

async function doLogin() {
  document.getElementById('login-err').textContent = '';
  const r = await post('/login', {
    email: document.getElementById('login-email').value,
    password: document.getElementById('login-pass').value
  });
  const d = await r.json();
  if (!r.ok) { document.getElementById('login-err').textContent = d.error; return; }
  showAdmin(d.user);
}

function showAdmin(user) {
  document.getElementById('login-screen').classList.add('hidden');
  document.getElementById('admin-screen').classList.remove('hidden');
  document.getElementById('user-info').textContent = user.display_name + ' (' + user.email + ')';
  document.getElementById('logout-btn').style.display = '';
  loadTenants();
}

async function logout() {
  await post('/logout', {});
  location.reload();
}

async function loadTenants() {
  const r = await api('/tenants');
  const d = await r.json();
  const el = document.getElementById('tenant-list');
  if (!d.tenants || !d.tenants.length) { el.innerHTML = '<em>No tenants yet.</em>'; return; }
  el.innerHTML = `<table>
    <tr><th>ID</th><th>Slug</th><th>Name</th><th>Database</th><th>Status</th><th>Domains</th><th></th></tr>
    ${d.tenants.map(t => `<tr>
      <td>${t.id}</td>
      <td><code>${t.slug}</code></td>
      <td>${esc(t.name)}</td>
      <td><code>${t.database_name}</code></td>
      <td><span class="badge badge-${t.status}">${t.status}</span></td>
      <td class="domains">${(t.domains||[]).map(dm=>`${dm.domain}${dm.is_primary?'*':''}`).join('<br>')}</td>
      <td>
        <button class="btn-sm" onclick="reprovision(${t.id})">Re-provision</button>
        <button class="btn-sm btn-danger" onclick="addDomain(${t.id})">+ Domain</button>
      </td>
    </tr>`).join('')}
  </table>`;
}

async function createTenant() {
  const msg = document.getElementById('status-msg');
  msg.textContent = 'Creating…';
  const r = await post('/tenants', {
    name:          document.getElementById('new-name').value,
    slug:          document.getElementById('new-slug').value,
    database_name: document.getElementById('new-dbname').value || undefined
  });
  const d = await r.json();
  if (!r.ok) { msg.textContent = '✗ ' + d.error; return; }
  msg.textContent = '✓ Tenant "' + d.tenant.name + '" created and provisioned.';
  ['new-name','new-slug','new-dbname'].forEach(id => document.getElementById(id).value = '');
  loadTenants();
}

async function reprovision(id) {
  if (!confirm('Re-run provisioner for tenant ' + id + '?')) return;
  const r = await post('/tenants/' + id + '/provision', {});
  const d = await r.json();
  alert(r.ok ? '✓ Provisioned' : '✗ ' + d.error);
  loadTenants();
}

async function addDomain(tenantId) {
  const domain = prompt('New domain (e.g. mabuhay.panicbackstage.com):');
  if (!domain) return;
  const primary = confirm('Set as primary domain?');
  const r = await post('/tenants/' + tenantId + '/domains', { domain, is_primary: primary });
  const d = await r.json();
  alert(r.ok ? '✓ Domain added' : '✗ ' + d.error);
  loadTenants();
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Check if already logged in.
api('/me').then(r => r.ok ? r.json() : null).then(d => {
  if (d && d.user) showAdmin(d.user);
});
</script>
</body>
</html>
HTML;
        exit;
    }
}
