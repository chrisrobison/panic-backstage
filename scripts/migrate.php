<?php

/**
 * Panic Backstage — database migration runner
 *
 * Single-tenant (legacy) usage — existing behaviour unchanged:
 *   php scripts/migrate.php              Apply pending single-tenant migrations
 *   php scripts/migrate.php status       Show status for single-tenant DB
 *
 * Multi-tenant SaaS usage — new subcommands:
 *   php scripts/migrate.php super [--dry-run]
 *       Apply database/migrations/super/ to the super-admin registry DB.
 *
 *   php scripts/migrate.php tenant <database> [--dry-run]
 *       Apply database/migrations/ (the same folder single-tenant uses) to a
 *       specific tenant database. Tenant DBs share schema.sql/migrations with
 *       the single-tenant DB since identical app code runs against both.
 *
 *   php scripts/migrate.php tenants [--dry-run]
 *       Apply database/migrations/ to every tenant found in the super registry.
 *
 *   php scripts/migrate.php status super
 *   php scripts/migrate.php status tenant <database>
 *   php scripts/migrate.php status tenants
 *       Show pending/applied counts for the given scope.
 *
 * Ledger table: schema_migrations (filename, applied_at) — created automatically.
 *
 * Bootstrap mode: if the target DB already has tables but no schema_migrations,
 * all current on-disk files are marked applied without being executed (assumes
 * the DB was created from schema.sql and is already current).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

// ── Argument parsing ─────────────────────────────────────────────────────────

$argv   = $_SERVER['argv'] ?? [];
$args   = array_slice($argv, 1);
$flags  = array_values(array_filter($args, static fn ($a) => str_starts_with($a, '--')));
$pos    = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $flags, true);

$command  = $pos[0] ?? 'migrate';   // default to legacy single-tenant run
$arg1     = $pos[1] ?? null;        // tenant db name or 'super'/'tenant'/'tenants'
$arg2     = $pos[2] ?? null;        // tenant db name when command is 'status tenant <db>'

// ── Dispatch ─────────────────────────────────────────────────────────────────

try {
    switch ($command) {
        // ── Multi-tenant commands ──────────────────────────────────────────
        case 'super':
            runSuper($root, $dryRun);
            break;

        case 'tenant':
            $db = $arg1 ?? '';
            if ($db === '') { fwrite(STDERR, "Usage: migrate.php tenant <database>\n"); exit(1); }
            runTenant($root, $db, $dryRun);
            break;

        case 'tenants':
            runAllTenants($root, $dryRun);
            break;

        case 'status':
            $scope = $arg1 ?? 'single';
            runStatus($root, $scope, $arg2);
            break;

        // ── Legacy single-tenant commands (existing behaviour) ────────────
        case 'migrate':
        default:
            runSingle($root, $dryRun);
            break;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}

// ═════════════════════════════════════════════════════════════════════════════
// Command implementations
// ═════════════════════════════════════════════════════════════════════════════

/** Apply pending migrations to the super-admin registry DB. */
function runSuper(string $root, bool $dryRun): void
{
    $superName = (string)(getenv('SUPER_DB_NAME') ?: 'panic_backstage_super');
    $pdo = Panic\Database\Connection::provisioner($superName);
    $dir = $root . '/database/migrations/super';
    echo "── Super DB: {$superName}\n";
    applyMigrations($pdo, $dir, $dryRun);
}

/** Apply pending tenant migrations to a single tenant DB. */
function runTenant(string $root, string $dbName, bool $dryRun): void
{
    $pdo = Panic\Database\Connection::provisioner($dbName);
    $dir = $root . '/database/migrations';
    echo "── Tenant DB: {$dbName}\n";
    applyMigrations($pdo, $dir, $dryRun);
}

/** Apply pending tenant migrations to every tenant registered in the super DB. */
function runAllTenants(string $root, bool $dryRun): void
{
    $tenants = Panic\Database\Connection::super()
        ->query('SELECT slug, database_name FROM tenants ORDER BY slug')
        ->fetchAll(PDO::FETCH_ASSOC);

    if (!$tenants) {
        echo "No tenants in super registry.\n";
        return;
    }
    foreach ($tenants as $t) {
        echo "\n";
        runTenant($root, (string)$t['database_name'], $dryRun);
    }
}

/** Legacy single-tenant migration: uses DB_* env vars via Panic\Database. */
function runSingle(string $root, bool $dryRun): void
{
    try {
        $pdo = (new Panic\Database())->pdo();
    } catch (Throwable $e) {
        fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Check the DB_* credentials in .env.\n");
        exit(1);
    }

    $dir = $root . '/database/migrations';
    applyMigrations($pdo, $dir, $dryRun, legacyInsert: true);
}

/** Show migration status for the requested scope. */
function runStatus(string $root, string $scope, ?string $dbArg): void
{
    switch ($scope) {
        case 'super':
            $superName = (string)(getenv('SUPER_DB_NAME') ?: 'panic_backstage_super');
            printStatus($root . '/database/migrations/super',
                        Panic\Database\Connection::super(), $superName);
            break;

        case 'tenant':
            if (!$dbArg) { fwrite(STDERR, "Usage: migrate.php status tenant <database>\n"); exit(1); }
            printStatus($root . '/database/migrations',
                        Panic\Database\Connection::tenant($dbArg), $dbArg);
            break;

        case 'tenants':
            $tenants = Panic\Database\Connection::super()
                ->query('SELECT slug, database_name FROM tenants ORDER BY slug')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tenants as $t) {
                printStatus($root . '/database/migrations',
                            Panic\Database\Connection::tenant((string)$t['database_name']),
                            (string)$t['database_name']);
            }
            break;

        default: // legacy: 'status' with no extra arg
            try {
                $pdo = (new Panic\Database())->pdo();
            } catch (Throwable $e) {
                fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n"); exit(1);
            }
            printStatus($root . '/database/migrations', $pdo, '(single-tenant)');
            break;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Core migration engine
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Ensure the ledger exists, detect bootstrap mode, then apply pending files.
 *
 * @param bool $legacyInsert  When true, insert only (filename) — matches the
 *                            original single-tenant ledger schema which has no
 *                            extra columns.
 */
function applyMigrations(PDO $pdo, string $dir, bool $dryRun, bool $legacyInsert = false): void
{
    $hasLedger = (bool)$pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchAll();

    if (!$hasLedger && !$dryRun) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `schema_migrations` (
               `filename`   VARCHAR(255) NOT NULL,
               `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`filename`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  Created schema_migrations ledger.\n";
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    if (empty($files)) {
        echo "  No migration files found in {$dir}\n";
        return;
    }

    // No "bootstrap: mark applied without executing" shortcut here. That used
    // to fire for any DB with tables but no ledger — which is also exactly
    // the state right after a fresh `mysql < schema.sql` — on the assumption
    // schema.sql is always fully current with zero pending migrations. That
    // assumption silently breaks the moment a migration is added after a
    // squash without schema.sql being regenerated (true of 050-052 as of
    // this fix: schema.sql is only current through 049), because it marks
    // real, unrun migrations "applied" — a fresh install or newly
    // provisioned tenant then quietly ends up missing db_history,
    // token_version, rate_limits, etc. Every migration in this folder is
    // required to be idempotent (see migrations/README.md: guarded CREATE
    // TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS), so just running all
    // of them — including ones already reflected in schema.sql, which
    // no-op — is both safe and correct in every case the shortcut covered.
    $appliedRows = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied     = array_flip($appliedRows);

    $ran = 0;
    foreach ($files as $file) {
        $filename = basename($file);
        if (isset($applied[$filename])) {
            continue;
        }
        $sql = (string)file_get_contents($file);
        if (trim($sql) === '') {
            echo "  [skip-empty] {$filename}\n";
            continue;
        }

        echo '  [apply] ' . $filename . ($dryRun ? ' (dry-run)' : '') . "\n";

        if (!$dryRun) {
            $statements = splitSqlStatements($sql);
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")->execute([$filename]);
        }
        $ran++;
    }

    echo $ran === 0 ? "  Already up to date.\n" : "  Applied {$ran} migration(s).\n";
}

function printStatus(string $dir, PDO $pdo, string $label): void
{
    echo "\n── {$label}\n";

    $hasLedger = (bool)$pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchAll();
    if (!$hasLedger) {
        echo "  schema_migrations table does not exist.\n";
        return;
    }

    $applied = array_flip($pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $files   = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);
        echo '  ' . (isset($applied[$name]) ? '[x]' : '[ ]') . " {$name}\n";
    }

    $pendingCount = count(array_diff(array_map('basename', $files), array_keys($applied)));
    echo '  ' . count($applied) . ' applied, ' . $pendingCount . " pending.\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// SQL statement splitter (preserved from original migrate.php)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Split a SQL file into individual statements.
 * Quote-, backtick-, and comment-aware.
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    $state = 'none';

    while ($i < $len) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        switch ($state) {
            case 'sq':
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') { $buf .= $next; $i += 2; continue 2; }
                if ($ch === "'") { $state = 'none'; }
                break;
            case 'dq':
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') { $buf .= $next; $i += 2; continue 2; }
                if ($ch === '"') { $state = 'none'; }
                break;
            case 'bt':
                $buf .= $ch;
                if ($ch === '`') { $state = 'none'; }
                break;
            case 'line':
                if ($ch === "\n") { $buf .= $ch; $state = 'none'; }
                break;
            case 'block':
                if ($ch === '*' && $next === '/') { $i += 2; $state = 'none'; continue 2; }
                break;
            default:
                if ($ch === '-' && $next === '-') { $state = 'line'; $i += 2; continue 2; }
                if ($ch === '#') { $state = 'line'; $i++; continue 2; }
                if ($ch === '/' && $next === '*') {
                    if ($i + 2 < $len && $sql[$i + 2] === '!') { $buf .= $ch; break; }
                    $state = 'block'; $i += 2; continue 2;
                }
                if ($ch === "'") { $state = 'sq'; $buf .= $ch; break; }
                if ($ch === '"') { $state = 'dq'; $buf .= $ch; break; }
                if ($ch === '`') { $state = 'bt'; $buf .= $ch; break; }
                if ($ch === ';') {
                    $trimmed = trim($buf);
                    if ($trimmed !== '') { $statements[] = $trimmed; }
                    $buf = '';
                    $i++;
                    continue 2;
                }
                $buf .= $ch;
        }
        $i++;
    }

    $trimmed = trim($buf);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}
