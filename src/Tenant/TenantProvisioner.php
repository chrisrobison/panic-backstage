<?php

declare(strict_types=1);

namespace Panic\Tenant;

use Panic\Database\Connection;

/**
 * Idempotent tenant database provisioner.
 *
 * Creates the tenant database if it does not exist, loads the canonical
 * database/schema.sql baseline (the same schema single-tenant installs use —
 * every endpoint class runs unchanged against a tenant DB, so the two must
 * stay structurally identical), applies any database/migrations/*.sql files
 * not yet folded into that baseline, and ensures the per-tenant client
 * directory exists under clients/<slug>/.
 *
 * Previously this looped over a separate database/migrations/tenant/*.sql
 * set that was hand-maintained alongside the single-tenant migrations. That
 * duplication silently drifted (four single-tenant migrations — leads.band_name,
 * venue contact fields, asset-generation metadata, multi-day events — were
 * missing from the tenant lineage), so newly-provisioned tenants would have
 * been missing columns the app code already relies on. schema.sql is now the
 * one shared baseline for both paths.
 *
 * All DDL runs through Connection::provisioner() / provisionerServer() so it
 * uses the elevated PROVISION_DB_* credentials rather than the runtime app
 * user (which intentionally lacks CREATE TABLE privileges).
 *
 * On dev installs where PROVISION_DB_USER is not set, those methods fall back
 * to SUPER_DB_* credentials automatically.
 */
final class TenantProvisioner
{
    /**
     * Provision a tenant: create the database, load the baseline schema,
     * apply any not-yet-folded migrations, seed demo data for a brand-new
     * tenant, and ensure dirs.
     *
     * @param array<string,mixed> $tenant  Row from the super `tenants` table.
     *                                     Must include `slug` and `database_name`.
     *                                     `name`, `admin_name`, `admin_email` are
     *                                     used to personalize the seeded demo
     *                                     data on first provision.
     * @return array{admin_email: string, admin_password: string}|null
     *         Seed info when this call actually seeded demo data (a genuinely
     *         new, empty tenant), or null when it didn't — e.g. a
     *         "Re-provision" run against a tenant that already has data,
     *         where seeding would create duplicate venues/events.
     */
    public static function provision(array $tenant): ?array
    {
        $dbName = (string)($tenant['database_name'] ?? '');
        $slug   = (string)($tenant['slug']          ?? '');

        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new \RuntimeException("Invalid tenant database name: {$dbName}");
        }
        if ($slug === '') {
            throw new \RuntimeException('Tenant slug is required for provisioning');
        }

        // Step 1: Create the database (provisionerServer has no dbname selected).
        Connection::provisionerServer()->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` "
            . "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $db   = Connection::provisioner($dbName);
        $root = dirname(__DIR__, 2);

        // Step 2: Load the canonical baseline. schema.sql itself is written for
        // fresh installs (DROP TABLE IF EXISTS per table), which would wipe a
        // tenant's live data on re-provision — so it's loaded here in an
        // IF-NOT-EXISTS form instead: every DROP is stripped and every CREATE
        // TABLE is softened to CREATE TABLE IF NOT EXISTS. On a brand-new
        // database this creates everything; against an already-provisioned
        // tenant (the "Re-provision" action) it's a safe no-op on existing
        // tables, leaving the migration step below to add anything new.
        $schema = file_get_contents($root . '/database/schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('database/schema.sql not found');
        }
        $db->exec(self::makeIdempotent($schema));

        // Step 3: Ensure the ledger exists, then apply + record only migrations
        // not already recorded — i.e. ones written since the baseline was last
        // squashed (normally none right after a squash). This is what keeps a
        // tenant current without replaying its whole migration history, and
        // what makes re-provisioning safe to run against a live tenant.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `schema_migrations` (
               `filename`   VARCHAR(255) NOT NULL,
               `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`filename`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $applied = array_flip(
            $db->query('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN)
        );

        $files = glob($root . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $filename = basename($file);
            if (isset($applied[$filename])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }
            $db->exec($sql);
            $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')
                ->execute([$filename]);
        }

        // Step 4: Ensure the per-tenant client directory tree exists.
        self::ensureClientDirectory($slug);

        // Step 5: Seed demo data — but only for a genuinely fresh tenant.
        // Re-provisioning an already-active tenant must never insert a second
        // copy of the demo venue/events on top of real data.
        $venueCount = (int)$db->query('SELECT COUNT(*) FROM venues')->fetchColumn();
        if ($venueCount > 0) {
            return null;
        }

        require_once $root . '/database/seed_demo_data.php';

        $adminEmail = trim((string)($tenant['admin_email'] ?? ''));
        if ($adminEmail === '') {
            // No admin email on the tenant row (older tenants, or a request
            // that didn't supply one) — still seed so the tenant isn't
            // completely empty, using generic placeholders.
            return \Panic\seed_demo_data($db, $root, [
                'venue_name' => (string)($tenant['name'] ?? $slug),
                'venue_slug' => $slug,
            ]);
        }

        return \Panic\seed_demo_data($db, $root, [
            'venue_name'     => (string)($tenant['name'] ?? $slug),
            'venue_slug'     => $slug,
            'admin_name'     => (string)($tenant['admin_name'] ?? 'Admin'),
            'admin_email'    => $adminEmail,
            'admin_password' => bin2hex(random_bytes(8)),
        ]);
    }

    /**
     * Soften a schema.sql-style dump so it can be re-applied to an existing
     * database without dropping anything: strips `DROP TABLE IF EXISTS ...;`
     * statements and turns `CREATE TABLE `x`` into `CREATE TABLE IF NOT EXISTS `x``.
     */
    private static function makeIdempotent(string $schema): string
    {
        $schema = preg_replace('/^DROP TABLE IF EXISTS `[^`]+`;\n/m', '', $schema) ?? $schema;
        return preg_replace('/^CREATE TABLE `/m', 'CREATE TABLE IF NOT EXISTS `', $schema) ?? $schema;
    }

    /**
     * Create the per-tenant client data directory tree under clients/<slug>/.
     *
     * Sub-directories created:
     *   assets/    – uploaded event images, PDFs, etc.
     *   logs/      – application and integration logs
     *   mail/      – copies of every sent email (.eml files)
     *   contracts/ – server-side contract PDF snapshots
     *
     * This replaces the old storage/uploads/<slug>/ single-directory approach.
     * The clients/ tree lives outside public/ so files are never directly
     * web-accessible; all HTTP access goes through the /files/ gateway.
     */
    private static function ensureClientDirectory(string $slug): void
    {
        // Validate slug to avoid directory traversal.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            throw new \RuntimeException("Invalid tenant slug for directory creation: {$slug}");
        }
        $base = dirname(__DIR__, 2) . '/clients/' . $slug;
        foreach (['assets', 'logs', 'mail', 'contracts'] as $sub) {
            $dir = $base . '/' . $sub;
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create client directory: {$dir}");
            }
        }
    }
}
