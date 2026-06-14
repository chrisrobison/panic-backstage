# Migrations

Incremental schema changes that apply **on top of** the baseline in
[`../schema.sql`](../schema.sql).

## How it works

- `../schema.sql` is the canonical, full schema for a **fresh** database. Every
  migration written before it was last regenerated has been squashed into it,
  so a brand-new database starts with **zero** pending migrations.
- New schema changes go here as `NNN_short_description.sql`, numbered in
  ascending order. **Next number: `015`** (migrations 001–014 were squashed
  into `../schema.sql` on 2026-06-14).
- Apply pending migrations with the runner:

  ```bash
  php scripts/migrate.php          # apply everything not yet recorded
  php scripts/migrate.php status   # list applied / pending, apply nothing
  ```

  Applied filenames are recorded in the `schema_migrations` table, so the
  runner is idempotent — re-running only applies what is new.

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
