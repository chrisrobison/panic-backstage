<?php
declare(strict_types=1);

/**
 * dump-sheet-shadow.php — export the event_sheet_shadow baseline as JSON.
 *
 * Run by sync-mabevents.py BEFORE generate-import-sql.py. The generator reads
 * this file to decide, per field, whether the sheet changed since the last sync
 * (sheet value != shadow) and therefore should win, or is unchanged (preserve
 * the app's value).
 *
 * Also applies migration 013 if the shadow table doesn't exist yet, so a fresh
 * deploy works with no manual step. On the very first run the table is empty,
 * the generator finds no baseline for existing events, and treats them as
 * "seed" rows (writes the shadow, does NOT overwrite app data).
 *
 * Usage: php scripts/dump-sheet-shadow.php [output.json]
 *        (default output: storage/tmp/sheet-shadow.json)
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$out = $argv[1] ?? ($root . '/storage/tmp/sheet-shadow.json');
@mkdir(dirname($out), 0775, true);

$db = new Database();

// Ensure the shadow table exists (idempotent — mirrors migration 013).
$db->run(
    'CREATE TABLE IF NOT EXISTS event_sheet_shadow (
        event_id  INT          NOT NULL PRIMARY KEY,
        raw_json  LONGTEXT     NOT NULL,
        synced_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$rows = $db->all('SELECT event_id, raw_json FROM event_sheet_shadow');

$map = [];
foreach ($rows as $r) {
    $decoded = json_decode((string) $r['raw_json'], true);
    if (is_array($decoded)) {
        $map[(string) $r['event_id']] = $decoded;
    }
}

file_put_contents($out, json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
fwrite(STDERR, 'shadow baseline: ' . count($map) . " events -> {$out}\n");
