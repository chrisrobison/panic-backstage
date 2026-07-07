# Migrations

Incremental schema changes that apply **on top of** the baseline in
[`../schema.sql`](../schema.sql).

This folder is shared by **both** deployment modes: the single-tenant `DB_*`
database and every multi-tenant SaaS tenant database run the exact same PHP
endpoint classes, so they need the exact same schema. A file added here rolls
out to the legacy DB via `php scripts/migrate.php` and to every tenant via
`php scripts/migrate.php tenants` (and automatically to any tenant provisioned
afterward). Don't duplicate a migration into a separate tenant-only file —
that split existed before and silently drifted (see git history around
2026-07-02), which is exactly the failure mode this shared folder avoids.

(The super-admin registry — `tenants`, `tenant_domains`, `super_admin_users` —
is a genuinely different schema with its own baseline and migrations folder:
[`../schema-super.sql`](../schema-super.sql) / [`super/`](super/).)

## How it works

- `../schema.sql` is the canonical, full schema for a **fresh** database. Every
  migration written before it was last regenerated has been squashed into it,
  so a brand-new database starts with **zero** pending migrations.
- New schema changes go here as `NNN_short_description.sql`, numbered in
  ascending order. **Next number: `050`** (migrations 001–049 were squashed
  into `../schema.sql` on 2026-07-06).
- Apply pending migrations with the runner:

  ```bash
  php scripts/migrate.php              # single-tenant DB: apply everything not yet recorded
  php scripts/migrate.php status       # single-tenant: list applied / pending, apply nothing
  php scripts/migrate.php tenant <db>  # one tenant DB
  php scripts/migrate.php tenants      # every tenant in the super registry
  ```

  Applied filenames are recorded in each database's own `schema_migrations`
  table, so the runner is idempotent — re-running only applies what is new,
  and single-tenant/tenant scopes don't interfere with each other's ledgers.

## Writing a migration

- One logical change per file.
- MySQL **auto-commits DDL**, so a migration that fails halfway cannot be rolled
  back. Make every statement safe to re-run:
  - `CREATE TABLE IF NOT EXISTS ...`
  - `DROP TABLE IF EXISTS ...`
  - guard `ALTER TABLE ... ADD COLUMN` so a re-run after a partial failure is
    harmless.
- Statements are split on `;` (quote-, backtick-, and comment-aware), so
  ordinary multi-statement files work.

## Folding migrations back into the baseline

When the migration list grows long, regenerate `../schema.sql` from a
migrated database and clear this folder again. See **Database schema &
migrations** in the top-level `README.md` for the exact dump command.
