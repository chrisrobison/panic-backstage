# Panic Backstage — Multi-Tenant SaaS Implementation Plan

## Overview

Convert Panic Backstage from a single-tenant application into a multi-tenant SaaS product where each tenant (`XXX`) is accessed via `XXX.panicbackstage.com` and isolated to its own MySQL database (`panic_backstage_XXX`).

**Key constraint**: Every single line of existing single-tenant behavior must be preserved unchanged. Multi-tenant mode is a dead code path unless `SUPER_DB_NAME` is present in `.env`.

**Reference implementation**: `~/mic/app` (Panic Mic) — identical architecture adapted to this codebase.

---

## Architecture

```
                              ┌─────────────────────┐
  XXX.panicbackstage.com ───▶ │  public/api/index.php│
                              │                     │
                              │  SUPER_DB_NAME set? │
                              │    YES              │
                              │     ├── /api/super/* ──▶ SuperController
                              │     └── tenant route ──▶ TenantContext::resolve()
                              │                              │
                              │                         super DB lookup
                              │                         (tenant_domains JOIN tenants)
                              │                              │
                              │                         Connection::tenant($dbName)
                              │                              │
                              │                         Kernel::boot($root, $db)
                              │                              │
                              │    NO ──▶ Kernel::boot($root)  (existing path)
                              └─────────────────────┘
```

---

## Mode Detection

Multi-tenant mode is activated **only** when `SUPER_DB_NAME` is present and non-empty in `.env`.

| `SUPER_DB_NAME` | Mode | Behavior |
|---|---|---|
| not set (default) | Single-tenant | 100% existing behavior, zero code-path changes |
| set | Multi-tenant SaaS | Host-based tenant resolution, super DB registry |

---

## New Files

| File | Purpose |
|---|---|
| `src/Database/Connection.php` | Static PDO factory (super / tenant / provisioner) |
| `src/Tenant/TenantContext.php` | HTTP_HOST → tenant row + DB connection |
| `src/Tenant/TenantProvisioner.php` | CREATE DATABASE + apply tenant migrations |
| `src/Http/SuperController.php` | Super admin API + minimal HTML UI |
| `database/migrations/super/001_super_schema.sql` | tenants, tenant_domains, super_admin_users |
| `database/migrations/tenant/001_initial_schema.sql` | Full tenant schema (from schema.sql) |
| `scripts/migrate.php` | CLI migration runner with schema_migrations ledger |
| `SAAS-PLAN.md` | This document |

## Modified Files

| File | Change |
|---|---|
| `src/Database.php` | +4 lines: `?PDO $pdo = null` constructor param |
| `src/Kernel.php` | +2 lines: `?Database $db = null` in `boot()` |
| `public/api/index.php` | +15 lines: SaaS mode pre-flight at top |
| `.env.example` | +20 lines: multi-tenant config vars block |

## Untouched Files

Everything else: all endpoint classes, `Auth.php`, `BaseEndpoint.php`, `Mailer.php`,
`public/router.php`, all `.htaccess` files, all HTML files.

---

## Phase 1 — Database Infrastructure

### 1a. `src/Database.php` — PDO injection

Add `?PDO $pdo = null` to constructor. If a PDO is passed, skip env-var connection:

```php
public function __construct(?PDO $pdo = null)
{
    if ($pdo !== null) {
        $this->pdo = $pdo;
        return;
    }
    // ... existing host/port/name/user/password connection code ...
}
```

Backward-compat: `new Database()` still works identically.

### 1b. `src/Kernel.php` — injected Database in boot()

```php
public static function boot(string $root, ?Database $db = null): self
{
    Env::load($root . '/.env');
    return new self($root, $db ?? new Database(), new Auth());
}
```

Backward-compat: `Kernel::boot($root)` still works identically.

### 1c. `src/Database/Connection.php` — static PDO factory

Static connection factory mirroring Panic Mic's `PanicMic\Database\Connection`:

| Method | Env prefix | Purpose |
|---|---|---|
| `super()` | `SUPER_DB_*` | Super registry DB (cached singleton) |
| `tenant(string $dbName)` | `TENANT_DB_*` | Per-tenant DB (cached per name) |
| `provisioner(string $dbName)` | `PROVISION_DB_*` | DDL operations (CREATE TABLE etc.) |
| `provisionerServer()` | `PROVISION_DB_*` | No-database conn for `CREATE DATABASE` |

`PROVISION_DB_*` falls back to `SUPER_DB_*` when `PROVISION_DB_USER` is unset (dev installs).
`$dbName` is validated against `/^[A-Za-z0-9_]+$/` before use.

### 1d. `src/Tenant/TenantContext.php` — host-to-tenant resolver

```
TenantContext::resolve(): ?self
```

1. If `SUPER_DB_NAME` env var not set → return `null` (single-tenant passthrough)
2. Extract + normalize `HTTP_HOST` (honoring `TRUST_PROXY` → `HTTP_X_FORWARDED_HOST`)
3. Validate against `ALLOWED_HOSTS` (supports `*.panicbackstage.com` wildcard prefix)
4. Query super DB:
   ```sql
   SELECT t.*, d.domain
   FROM tenant_domains d
   JOIN tenants t ON t.id = d.tenant_id
   WHERE d.domain = ? AND t.status = 'active'
   LIMIT 1
   ```
5. On miss → `respondNotConfigured()` — branded HTML for browser, JSON 404 for API
6. On hit → `new TenantContext($tenantRow, Connection::tenant($tenant['database_name']))`

Bypass paths that skip lookup (called before resolve in index.php):
- `/api/super/**`, `/super/**`
- `/health`

Public properties: `array $tenant`, `PDO $db`

---

## Phase 2 — Tenant Provisioner

### `src/Tenant/TenantProvisioner.php`

```
TenantProvisioner::provision(array $tenant): void
```

1. Validate `$dbName` against `/^[A-Za-z0-9_]+$/`
2. `Connection::provisionerServer()->exec("CREATE DATABASE IF NOT EXISTS \`{$dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")`
3. Iterate `database/migrations/tenant/*.sql` in sorted order — apply each via `Connection::provisioner($dbName)->exec($sql)`
4. Create `storage/uploads/{slug}/` directory if missing

---

## Phase 3 — Super Admin Layer

### `src/Http/SuperController.php`

All routes except login require `$_SESSION['backstage_super_admin']`. Auth uses
`password_hash()` / `password_verify()` against `super_admin_users.password_hash` — no JWT.

| Route | Method | Auth | Action |
|---|---|---|---|
| `/super/tenants` | GET | super | Minimal HTML admin UI |
| `/api/super/login` | POST | none | Email+password → session |
| `/api/super/logout` | POST | super | Unset session |
| `/api/super/tenants` | GET | super | List all tenants + domains |
| `/api/super/tenants` | POST | super | Create + auto-provision |
| `/api/super/tenants/{id}` | GET | super | Show single tenant |
| `/api/super/tenants/{id}` | PATCH | super | Update name/slug/status |
| `/api/super/tenants/{id}/provision` | POST | super | Re-run provisioner |
| `/api/super/tenants/{id}/domains` | POST | super | Add domain alias |
| `/api/super/tenants/{id}/domains/{domId}` | DELETE | super | Remove alias |

`dispatch(string $path, string $method): never` — always exits (never returns).

---

## Phase 4 — Database Schemas

### `database/migrations/super/001_super_schema.sql`

```sql
CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  database_name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('provisioning','active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenant_domains (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  domain VARCHAR(253) NOT NULL UNIQUE,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_td_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_td_domain (domain)
);

CREATE TABLE IF NOT EXISTS super_admin_users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### `database/migrations/tenant/001_initial_schema.sql`

Current `database/schema.sql` converted to `CREATE TABLE IF NOT EXISTS` (no `DROP TABLE` guards).
Applied by `TenantProvisioner` to every fresh tenant database.
Future schema changes → `002_...`, `003_...` etc.

---

## Phase 5 — Migration Runner

### `scripts/migrate.php`

CLI migration runner with `schema_migrations` ledger table:

```
php scripts/migrate.php super [--dry-run]
php scripts/migrate.php tenant <database> [--dry-run]
php scripts/migrate.php tenants [--dry-run]   # all tenants in super registry
php scripts/migrate.php status super|tenant|tenants
```

**Bootstrap mode**: On first run against a DB that has tables but no `schema_migrations` table,
marks all current migration files as applied without executing them (avoids double-applying).

---

## Phase 6 — Entry Point Wiring

### `public/api/index.php`

```php
<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$root = dirname(__DIR__, 2);
Panic\Env::load($root . '/.env');

// ── Multi-tenant SaaS mode ────────────────────────────────────────────────────
// Activated only when SUPER_DB_NAME is present in .env.
// Without it, falls through to the existing single-tenant path unchanged.
if (($superDb = getenv('SUPER_DB_NAME')) !== false && $superDb !== '') {
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Super-admin routes: bypass tenant resolution entirely
    if (str_starts_with($path, '/api/super') || str_starts_with($path, '/super')) {
        Panic\Http\SuperController::dispatch($path, $method);
        // dispatch() always exits
    }

    // Tenant-scoped routes: resolve from HTTP_HOST → tenant DB
    $ctx = Panic\Tenant\TenantContext::resolve();   // exits with 400/404 on failure
    Panic\Kernel::boot($root, new Panic\Database($ctx->db))->handle()->send();
    exit;
}

// ── Single-tenant mode (existing behavior, zero changes) ──────────────────────
Panic\Kernel::boot($root)->handle()->send();
```

---

## Phase 7 — Configuration

### `.env.example` additions

```env
# ── Multi-tenant SaaS mode ─────────────────────────────────────────────────────
# Set SUPER_DB_NAME to enable multi-tenant routing. Leave blank for single-tenant.
SUPER_DB_NAME=panic_backstage_super
SUPER_DB_HOST=127.0.0.1
SUPER_DB_PORT=3306
SUPER_DB_USER=root
SUPER_DB_PASSWORD=

# Runtime app user — SELECT/INSERT/UPDATE/DELETE on panic_backstage_* databases
TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=3306
TENANT_DB_USER=panic_backstage_app
TENANT_DB_PASSWORD=

# Provisioning user — CREATE DATABASE + CREATE TABLE on panic_backstage_*
# Falls back to SUPER_DB_* if PROVISION_DB_USER is blank (dev installs).
PROVISION_DB_HOST=127.0.0.1
PROVISION_DB_PORT=3306
PROVISION_DB_USER=
PROVISION_DB_PASSWORD=

# Comma-separated. Supports *.domain.com wildcard prefix.
ALLOWED_HOSTS=localhost,127.0.0.1,panicbackstage.com,*.panicbackstage.com
TRUST_PROXY=false

# Optional: restrict /super and /api/super to this hostname only.
SUPER_HOST=admin.panicbackstage.com
```

---

## Backward-Compatibility Matrix

| Install scenario | `SUPER_DB_NAME` | Behavior |
|---|---|---|
| Existing `panicbooking.com/backstage` | not set | 100% identical to today |
| SaaS `mabuhay.panicbackstage.com` | set | Resolves tenant → `panic_backstage_mabuhay` |
| SaaS `admin.panicbackstage.com/super` | set | SuperController, no tenant lookup |
| SaaS — unknown hostname | set | Branded "nothing here" HTML or JSON 404 |
| SaaS — hostname in ALLOWED_HOSTS but no tenant row | set | JSON 404 or branded HTML |

---

## New Tenant Workflow

```
1.  Create super DB:    mysql -e "CREATE DATABASE panic_backstage_super"
2.  Run migrations:     php scripts/migrate.php super
3.  Create super-admin: php scripts/provision-tenant.php --create-super-admin
                        (or INSERT directly: INSERT INTO super_admin_users ...)
4.  Log in:            POST /api/super/login  { email, password }
5.  Create tenant:     POST /api/super/tenants
                        { slug: "mabuhay", name: "Mabuhay Gardens", database_name: "panic_backstage_mabuhay" }
    → TenantProvisioner auto-creates the DB + applies all tenant migrations
6.  Add domain:        POST /api/super/tenants/{id}/domains
                        { domain: "mabuhay.panicbackstage.com", is_primary: true }
7.  Done — mabuhay.panicbackstage.com is live, fully isolated
```

---

## Apache VirtualHost (Wildcard)

```apache
<VirtualHost *:443>
    ServerName panicbackstage.com
    ServerAlias *.panicbackstage.com admin.panicbackstage.com
    DocumentRoot /home/cdr/domains/panicbackstage.com/app/public
    # ... existing SSL / PHP-FPM config unchanged ...
</VirtualHost>
```

The wildcard `ServerAlias` routes all subdomains to the same `DocumentRoot`.
The existing `public/.htaccess` already routes all `/api/*` requests to `api/index.php`.
No `.htaccess` changes required.

---

## Database Naming Convention

| Tenant slug | Database name |
|---|---|
| `mabuhay` | `panic_backstage_mabuhay` |
| `broadway` | `panic_backstage_broadway` |
| `ace-hotel` | `panic_backstage_ace_hotel` (slug sanitized) |

The `database_name` column in `tenants` stores the exact DB name to use.
The `TenantProvisioner` validates the name against `/^[A-Za-z0-9_]+$/` before use.

---

## Security Notes

- Super admin auth uses PHP sessions (`$_SESSION['backstage_super_admin']`) + `password_verify()`
- Tenant DB names are validated with a strict regex before any SQL interpolation
- `ALLOWED_HOSTS` prevents requests from arbitrary hostnames reaching tenant resolution
- Tenant DBs use a separate runtime user (`TENANT_DB_*`) with no DDL privileges
- Provisioning runs as a separate elevated user (`PROVISION_DB_*`) only during setup
