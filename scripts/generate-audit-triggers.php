<?php
declare(strict_types=1);

/**
 * generate-audit-triggers.php
 *
 * Generates (and by default applies) AFTER INSERT/UPDATE/DELETE triggers for
 * every table in panic_backstage so that every write — no matter whether it
 * came from the web app, a cron script, or a one-off `mysql` session — lands
 * a row in `db_history` with the old/new values and ready-to-run undo SQL.
 *
 * Why: the 2026-07-06 MabEvents sync incident was hard to trace precisely
 * because the writes that corrupted events 128/423768/etc. went through raw
 * SQL from a cron script, entirely bypassing event_activity_log (which only
 * app-mediated event updates go through). A DB-level trigger catches
 * everything regardless of what wrote it.
 *
 * Usage:
 *   php scripts/generate-audit-triggers.php            # generate + apply
 *   php scripts/generate-audit-triggers.php --dry-run   # print SQL only
 *
 * Safe to re-run after schema migrations: DROPs and recreates every trigger
 * this script owns (named trg_<table>_ai / _au / _ad) each time, so column
 * lists stay in sync with the current schema. Never touches db_history's own
 * data or any other trigger naming pattern.
 *
 * Excludes:
 *   - db_history itself (obviously; it has no triggers on itself)
 *   - Nothing else. Every other table, including existing log/audit-style
 *     tables (event_activity_log, contract_audit_log, event_sheet_shadow,
 *     etc.), gets covered too — auditing the audit tables is a little
 *     redundant but harmless, and leaves no blind spots.
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Env;

Env::load($root . '/.env');

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$host   = getenv('DB_HOST')     ?: '127.0.0.1';
$port   = getenv('DB_PORT')     ?: '3306';
$dbName = getenv('DB_NAME')     ?: 'panic_backstage';

// Trigger creation needs privileges beyond the app's scoped DB_USER grant
// (TRIGGER + often SUPER for the DEFINER clause). Reads the admin credential
// from ~/.my.cnf's [client] section (same one the `mysql` CLI uses) instead
// of hardcoding a password here. (PDO_MYSQL on mysqlnd, unlike the `mysql`
// CLI, can't read my.cnf itself — no MYSQL_ATTR_READ_DEFAULT_FILE support —
// so this parses it directly.)
$myCnf = @parse_ini_file(getenv('HOME') . '/.my.cnf', true, INI_SCANNER_RAW) ?: [];
$adminUser = $myCnf['client']['user'] ?? 'root';
$adminPass = $myCnf['client']['password'] ?? '';

$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
    $adminUser,
    $adminPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

const HISTORY_TABLE = 'db_history';

function q(string $ident): string
{
    return '`' . str_replace('`', '``', $ident) . '`';
}

// ── Ensure db_history exists ────────────────────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS " . q(HISTORY_TABLE) . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_name  VARCHAR(64) NOT NULL,
  pk_column   VARCHAR(64) NOT NULL,
  pk_value    VARCHAR(255) NOT NULL,
  action      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
  actor       VARCHAR(128) NULL,
  old_row     JSON NULL,
  new_row     JSON NULL,
  undo_sql    MEDIUMTEXT NOT NULL,
  created_at  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_table_pk (table_name, pk_value),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Discover tables + single-column primary keys ────────────────────────────
$tables = $pdo->query("
    SELECT t.TABLE_NAME AS name,
           (SELECT k.COLUMN_NAME
              FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.TABLE_SCHEMA = t.TABLE_SCHEMA
               AND k.TABLE_NAME = t.TABLE_NAME
               AND k.CONSTRAINT_NAME = 'PRIMARY'
             LIMIT 1) AS pk
      FROM information_schema.TABLES t
     WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
     ORDER BY t.TABLE_NAME
")->fetchAll();

$statements = [];
$skipped = [];

foreach ($tables as $t) {
    $table = $t['name'];
    $pk    = $t['pk'];

    if ($table === HISTORY_TABLE) {
        continue;
    }
    if ($pk === null) {
        $skipped[] = $table;
        continue;
    }

    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . "
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_COLUMN);

    $jsonNew = implode(', ', array_map(fn ($c) => $pdo->quote($c) . ", NEW." . q($c), $cols));
    $jsonOld = implode(', ', array_map(fn ($c) => $pdo->quote($c) . ", OLD." . q($c), $cols));

    $setNewFromOld = implode(", ", array_map(fn ($c) => q($c) . "=', QUOTE(OLD." . q($c) . "), '", $cols));
    $insertCols    = implode(',', array_map(fn ($c) => q($c), $cols));
    $insertVals    = implode(", ',', ", array_map(fn ($c) => "QUOTE(OLD." . q($c) . ")", $cols));

    for ($kind = 0; $kind < 3; $kind++) {
        [$suffix, $event] = [['ai', 'INSERT'], ['au', 'UPDATE'], ['ad', 'DELETE']][$kind];
        $trigName = "trg_" . $table . "_" . $suffix;
        $statements[] = "DROP TRIGGER IF EXISTS " . q($trigName) . ";";

        if ($event === 'INSERT') {
            $sql = "CREATE TRIGGER " . q($trigName) . " AFTER INSERT ON " . q($table) . "
FOR EACH ROW
INSERT INTO " . q(HISTORY_TABLE) . " (table_name, pk_column, pk_value, action, actor, old_row, new_row, undo_sql)
VALUES (
  " . $pdo->quote($table) . ", " . $pdo->quote($pk) . ", NEW." . q($pk) . ",
  'INSERT', @app_actor,
  NULL,
  JSON_OBJECT($jsonNew),
  CONCAT('DELETE FROM " . q($table) . " WHERE " . q($pk) . "=', QUOTE(NEW." . q($pk) . "))
);";
        } elseif ($event === 'UPDATE') {
            $sql = "CREATE TRIGGER " . q($trigName) . " AFTER UPDATE ON " . q($table) . "
FOR EACH ROW
INSERT INTO " . q(HISTORY_TABLE) . " (table_name, pk_column, pk_value, action, actor, old_row, new_row, undo_sql)
VALUES (
  " . $pdo->quote($table) . ", " . $pdo->quote($pk) . ", NEW." . q($pk) . ",
  'UPDATE', @app_actor,
  JSON_OBJECT($jsonOld),
  JSON_OBJECT($jsonNew),
  CONCAT('UPDATE " . q($table) . " SET $setNewFromOld', ' WHERE " . q($pk) . "=', QUOTE(OLD." . q($pk) . "))
);";
        } else {
            $sql = "CREATE TRIGGER " . q($trigName) . " AFTER DELETE ON " . q($table) . "
FOR EACH ROW
INSERT INTO " . q(HISTORY_TABLE) . " (table_name, pk_column, pk_value, action, actor, old_row, new_row, undo_sql)
VALUES (
  " . $pdo->quote($table) . ", " . $pdo->quote($pk) . ", OLD." . q($pk) . ",
  'DELETE', @app_actor,
  JSON_OBJECT($jsonOld),
  NULL,
  CONCAT('INSERT INTO " . q($table) . " ($insertCols) VALUES (', $insertVals, ')')
);";
        }
        $statements[] = $sql;
    }
}

if ($skipped) {
    fwrite(STDERR, "Skipped (no single-column primary key): " . implode(', ', $skipped) . "\n");
}

$fullSql = implode("\n\n", $statements) . "\n";

if ($dryRun) {
    echo $fullSql;
    exit(0);
}

$pdo->exec('SET SESSION sql_mode = REPLACE(@@sql_mode, "STRICT_TRANS_TABLES", "")');
foreach ($statements as $stmt) {
    $pdo->exec($stmt);
}

$triggerCount = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
")->fetchColumn();

echo "Applied. {$triggerCount} triggers now installed across " . (count($tables) - 1 - count($skipped)) . " tables.\n";
if ($skipped) {
    echo "Skipped (no usable primary key): " . implode(', ', $skipped) . "\n";
}
