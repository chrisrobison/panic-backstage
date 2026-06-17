<?php

declare(strict_types=1);

namespace Panic\Database;

use PDO;

/**
 * Static PDO connection factory for multi-tenant mode.
 *
 * Three credential tiers:
 *   SUPER_DB_*      — the super-admin registry database (tenants table)
 *   TENANT_DB_*     — per-tenant runtime connections (SELECT/INSERT/UPDATE/DELETE)
 *   PROVISION_DB_*  — elevated DDL credentials (CREATE DATABASE, CREATE TABLE)
 *                     Falls back to SUPER_DB_* when PROVISION_DB_USER is unset.
 *
 * All connections are cached for the lifetime of the request.
 */
final class Connection
{
    private static ?PDO $super = null;

    /** @var array<string,PDO> */
    private static array $tenants = [];

    /** @var array<string,PDO> */
    private static array $provisioners = [];

    // ─── Public API ──────────────────────────────────────────────────────────

    /** Singleton connection to the super-admin registry database. */
    public static function super(): PDO
    {
        if (self::$super === null) {
            $name = (string)(getenv('SUPER_DB_NAME') ?: 'panic_backstage_super');
            self::$super = self::make($name, 'SUPER_DB');
        }
        return self::$super;
    }

    /**
     * Per-tenant runtime connection (SELECT/INSERT/UPDATE/DELETE).
     * Cached by database name; multiple calls with the same name reuse
     * the same PDO instance.
     */
    public static function tenant(string $database): PDO
    {
        self::assertSafeDbName($database);
        return self::$tenants[$database] ??= self::make($database, 'TENANT_DB');
    }

    /**
     * Elevated provisioning connection (DDL — CREATE TABLE, ALTER TABLE).
     * Uses PROVISION_DB_* credentials; falls back to SUPER_DB_* on dev installs
     * where PROVISION_DB_USER is not configured.
     */
    public static function provisioner(string $database): PDO
    {
        self::assertSafeDbName($database);
        $prefix = self::provisionPrefix();
        return self::$provisioners[$database] ??= self::make($database, $prefix);
    }

    /**
     * Provisioning connection without a selected database — needed to issue
     * CREATE DATABASE when the target schema does not yet exist.
     */
    public static function provisionerServer(): PDO
    {
        $prefix = self::provisionPrefix();
        $host     = (string)(getenv("{$prefix}_HOST") ?: '127.0.0.1');
        $port     = (string)(getenv("{$prefix}_PORT") ?: '3306');
        $user     = (string)(getenv("{$prefix}_USER") ?: 'root');
        $password = (string)(getenv("{$prefix}_PASSWORD") ?: '');
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        return new PDO($dsn, $user, $password, self::pdoOptions());
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private static function provisionPrefix(): string
    {
        $user = (string)(getenv('PROVISION_DB_USER') ?: '');
        return $user !== '' ? 'PROVISION_DB' : 'SUPER_DB';
    }

    private static function make(string $database, string $prefix): PDO
    {
        $host     = (string)(getenv("{$prefix}_HOST") ?: '127.0.0.1');
        $port     = (string)(getenv("{$prefix}_PORT") ?: '3306');
        $user     = (string)(getenv("{$prefix}_USER") ?: 'root');
        $password = (string)(getenv("{$prefix}_PASSWORD") ?: '');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return new PDO($dsn, $user, $password, self::pdoOptions());
    }

    /** @return array<int,mixed> */
    private static function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    private static function assertSafeDbName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException("Invalid database name: {$name}");
        }
    }
}
