<?php
declare(strict_types=1);

namespace Panic;

use PDO;

final class Database
{
    private PDO $pdo;

    /**
     * @param PDO|null $pdo  Pre-built connection (multi-tenant path).
     *                       When null, connects using DB_* environment variables
     *                       (single-tenant path — existing behavior unchanged).
     */
    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
            return;
        }
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'panic_backstage';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Pin the session to UTC so NOW()/CURRENT_TIMESTAMP and every
            // stored DATETIME/TIMESTAMP mean the same instant regardless of
            // this MySQL server's configured system timezone. The app's
            // display timezone (America/Los_Angeles, see bootstrap.php) is
            // applied only when formatting for humans — see
            // db_timestamp_to_epoch() in Support.php for the PHP-side half
            // of this contract.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        // Default actor attribution for the audit-trigger history table (see
        // migrations/xxx_add_audit_history.sql). Web requests overwrite this
        // via setActor() once Kernel knows the authenticated user; anything
        // that never calls setActor() (a cron job, a one-off script, `php -a`)
        // is still attributed to the CLI script that ran it instead of showing
        // up as an anonymous write — this is exactly the gap that made the
        // 2026-07-06 MabEvents sync incident hard to trace.
        if (PHP_SAPI === 'cli') {
            $script = $_SERVER['argv'][0] ?? $_SERVER['SCRIPT_NAME'] ?? 'unknown';
            $this->setActor('cli:' . basename($script));
        }
    }

    /**
     * Tag subsequent writes on this connection with who/what made them, read
     * by the AFTER INSERT/UPDATE/DELETE triggers into db_history.actor.
     * Safe to call repeatedly (e.g. once auth resolves mid-request).
     */
    public function setActor(string $actor): void
    {
        $stmt = $this->pdo->prepare('SET @app_actor = ?');
        $stmt->execute([$actor]);
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
