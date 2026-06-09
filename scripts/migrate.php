<?php
/**
 * migrate.php — dead-simple forward-only migration runner.
 *
 * Applies every *.sql file in database/migrations/ that has not yet been
 * recorded in the schema_migrations ledger, in ascending filename order, then
 * records each applied filename. Re-running is a no-op once everything is
 * applied.
 *
 * Usage:
 *   php scripts/migrate.php            Apply all pending migrations.
 *   php scripts/migrate.php status     Show applied / pending without applying.
 *
 * Notes:
 *   * The baseline (database/schema.sql) already contains every table, so a
 *     fresh database starts with zero pending migrations. New schema changes
 *     go in database/migrations/NNN_description.sql and are applied here.
 *   * MySQL auto-commits DDL, so a migration that fails halfway cannot be rolled
 *     back. Write migrations to be safe to re-run (IF [NOT] EXISTS, guarded
 *     ALTERs) so a fixed-and-rerun leaves the database consistent.
 *
 * Connection uses the same .env credentials as the app (Panic\Database).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$command = $argv[1] ?? 'migrate';
if (!in_array($command, ['migrate', 'status'], true)) {
    fwrite(STDERR, "Unknown command '$command'. Use: migrate (default) | status\n");
    exit(2);
}

try {
    $pdo = (new Panic\Database())->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Check the DB_* credentials in .env.\n");
    exit(1);
}

ensureLedger($pdo);

$applied = array_fill_keys(
    array_map(
        static fn(array $r): string => (string) $r['filename'],
        $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC)
    ),
    true
);

$dir = $root . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    if (!isset($applied[$name])) {
        $pending[] = $path;
    }
}

if ($command === 'status') {
    printf("Applied migrations: %d\n", count($applied));
    foreach (array_keys($applied) as $name) {
        echo "  [x] $name\n";
    }
    printf("Pending migrations: %d\n", count($pending));
    foreach ($pending as $path) {
        echo "  [ ] " . basename($path) . "\n";
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to migrate — database is up to date (" . count($applied) . " applied).\n";
    exit(0);
}

echo "Applying " . count($pending) . " migration(s)...\n";
$record = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');

foreach ($pending as $path) {
    $name = basename($path);
    echo "→ $name ... ";
    $sql = file_get_contents($path);
    if ($sql === false) {
        echo "FAILED\n";
        fwrite(STDERR, "Could not read $path\n");
        exit(1);
    }

    $statements = splitSqlStatements($sql);
    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $record->execute([$name]);
        echo "ok (" . count($statements) . " statement(s))\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "Migration $name failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "It was NOT recorded. Fix the migration (make it safe to re-run) and run again.\n");
        exit(1);
    }
}

echo "Done. " . (count($applied) + count($pending)) . " migration(s) now applied.\n";
exit(0);

// ── helpers ─────────────────────────────────────────────────────────────────

/** Create the migration ledger if it does not already exist. */
function ensureLedger(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `filename` VARCHAR(255) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Split a SQL file into individual statements.
 *
 * Quote-, backtick-, and comment-aware so a ';' inside a string literal,
 * identifier, or comment does not split a statement. Preserves MySQL
 * executable comments (the "slash-star-bang" form) as part of the statement;
 * strips line (--, #) and ordinary block comments.
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    // none | sq (') | dq (") | bt (`) | line (-- or #) | block (/* */)
    $state = 'none';

    while ($i < $len) {
        $ch = $sql[$i];
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
                // drop the comment body; end at newline
                if ($ch === "\n") { $buf .= $ch; $state = 'none'; }
                break;
            case 'block':
                // drop ordinary block comment body; end at */
                if ($ch === '*' && $next === '/') { $i += 2; $state = 'none'; continue 2; }
                break;
            default: // none
                // Comment starts
                if ($ch === '-' && $next === '-') { $state = 'line'; $i += 2; continue 2; }
                if ($ch === '#') { $state = 'line'; $i++; continue 2; }
                if ($ch === '/' && $next === '*') {
                    // Preserve executable comments /*! ... */ verbatim.
                    if (($i + 2 < $len) && $sql[$i + 2] === '!') {
                        $buf .= $ch;
                        $state = 'none';
                        break;
                    }
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
