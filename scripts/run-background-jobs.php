<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Drain ready durable jobs.
 *
 *   php scripts/run-background-jobs.php [--limit=25]
 *   php scripts/run-background-jobs.php tenant <database> [--limit=25]
 *   php scripts/run-background-jobs.php tenants [--limit=25]
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Database;
use Panic\Database\Connection;
use Panic\Env;
use Panic\Jobs\JobWorker;
use Panic\Tenant\TenantContext;

Env::load($root . '/.env');

$args = array_slice($_SERVER['argv'] ?? [], 1);
$limit = 25;
foreach ($args as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $match)) {
        $limit = max(1, min(500, (int) $match[1]));
    }
}
$positionals = array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));
$scope = $positionals[0] ?? 'single';
$workerBase = gethostname() . ':' . getmypid();

try {
    if ($scope === 'tenant') {
        $database = (string) ($positionals[1] ?? '');
        if ($database === '') {
            throw new RuntimeException('Usage: run-background-jobs.php tenant <database> [--limit=N]');
        }
        $tenant = tenantForDatabase($database);
        TenantContext::setCurrent(new TenantContext($tenant, Connection::tenant($database)));
        if (!empty($tenant['domain'])) {
            putenv('APP_URL=https://' . $tenant['domain']);
        }
        runJobs(new Database(Connection::tenant($database)), $root, $workerBase . ':' . $database, $limit);
    } elseif ($scope === 'tenants') {
        $tenants = Connection::super()->query(
            "SELECT t.*, d.domain
             FROM tenants t
             LEFT JOIN tenant_domains d ON d.tenant_id = t.id
             WHERE t.status = 'active'
             ORDER BY t.id, d.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($tenants as $tenant) {
            $database = (string) $tenant['database_name'];
            if (isset($seen[$database])) {
                continue;
            }
            $seen[$database] = true;
            TenantContext::setCurrent(new TenantContext($tenant, Connection::tenant($database)));
            if (!empty($tenant['domain'])) {
                putenv('APP_URL=https://' . $tenant['domain']);
            }
            runJobs(new Database(Connection::tenant($database)), $root, $workerBase . ':' . $database, $limit);
        }
        TenantContext::setCurrent(null);
    } elseif ($scope === 'single') {
        runJobs(new Database(), $root, $workerBase . ':single', $limit);
    } else {
        throw new RuntimeException('Scope must be single, tenant, or tenants.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Background worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return void */
function runJobs(Database $db, string $root, string $workerId, int $limit): void
{
    $counts = (new JobWorker($db, $root, $workerId))->run($limit);
    echo "{$workerId}: {$counts['completed']} completed, {$counts['failed']} failed\n";
}

/** @return array<string,mixed> */
function tenantForDatabase(string $database): array
{
    $stmt = Connection::super()->prepare(
        "SELECT t.*, d.domain
         FROM tenants t
         LEFT JOIN tenant_domains d ON d.tenant_id = t.id
         WHERE t.database_name = ?
         ORDER BY d.id
         LIMIT 1"
    );
    $stmt->execute([$database]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        throw new RuntimeException("No tenant registry row found for database {$database}.");
    }
    return $tenant;
}
