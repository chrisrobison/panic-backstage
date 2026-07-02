# Super-registry migrations

Incremental schema changes that apply **on top of** the baseline in
[`../../schema-super.sql`](../../schema-super.sql) — the super-admin registry
database (`tenants`, `tenant_domains`, `super_admin_users`). This is a
separate schema from the per-tenant app database; see
[`../README.md`](../README.md) for that one.

- New schema changes go here as `NNN_short_description.sql`, numbered in
  ascending order. **Next number: `002`** (migration `001_super_schema.sql`
  was squashed into `../../schema-super.sql` on 2026-07-02).
- Apply pending migrations with:

  ```bash
  php scripts/migrate.php super              # apply everything not yet recorded
  php scripts/migrate.php status super       # list applied / pending, apply nothing
  ```
