<?php

declare(strict_types=1);

namespace Panic\Tenant;

use Panic\Database\Connection;

/**
 * Idempotent tenant database provisioner.
 *
 * Creates the tenant database if it does not exist, applies every
 * database/migrations/tenant/*.sql file in sorted order, and ensures the
 * per-tenant upload directory exists under storage/uploads/.
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
     * Provision a tenant: create the database, apply migrations, ensure dirs.
     *
     * @param array<string,mixed> $tenant  Row from the super `tenants` table.
     *                                     Must include `slug` and `database_name`.
     */
    public static function provision(array $tenant): void
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

        // Step 2: Apply every tenant migration in sorted order.
        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations/tenant';
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $db = Connection::provisioner($dbName);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }
            $db->exec($sql);
        }

        // Step 3: Ensure the per-tenant client directory tree exists.
        self::ensureClientDirectory($slug);
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
