<?php
declare(strict_types=1);

/**
 * dump-sheet-import-links.php — export the not-yet-confirmed sheet→app links.
 *
 * Run by sync-mabevents.py BEFORE generate-import-sql.py. The generator reads
 * this file so that a brand-new sheet row which was ALREADY turned into a local
 * event on a previous sync (but whose App ID hasn't been confirmed back into the
 * sheet yet) is reused instead of being inserted a second time. This is what
 * makes the "blank App ID = create a new event" rule safe to retry: a failed
 * write-back can never spawn a duplicate.
 *
 * Also applies migration 023 if the table doesn't exist yet, so a fresh deploy
 * works with no manual step (mirrors dump-sheet-shadow.php).
 *
 * Output JSON: a list of { event_id, sheet_row, title, date } for every
 * sheet_import_links row with linked = 0.
 *
 * Usage: php scripts/dump-sheet-import-links.php [output.json]
 *        (default output: storage/tmp/sheet-import-links.json)
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$out = $argv[1] ?? ($root . '/storage/tmp/sheet-import-links.json');
@mkdir(dirname($out), 0775, true);

$db = new Database();

// Ensure the table exists (idempotent — mirrors migration 023).
$db->run(
    'CREATE TABLE IF NOT EXISTS sheet_import_links (
        event_id     INT          NOT NULL PRIMARY KEY,
        sheet_row    INT          NOT NULL,
        title_snap   VARCHAR(200) NOT NULL DEFAULT \'\',
        date_snap    DATE         NULL,
        linked       TINYINT(1)   NOT NULL DEFAULT 0,
        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP    NULL,
        KEY idx_unlinked (linked)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$rows = $db->all(
    'SELECT event_id, sheet_row, title_snap, date_snap
     FROM sheet_import_links
     WHERE linked = 0'
);

$list = [];
foreach ($rows as $r) {
    $list[] = [
        'event_id'  => (int) $r['event_id'],
        'sheet_row' => (int) $r['sheet_row'],
        'title'     => (string) ($r['title_snap'] ?? ''),
        'date'      => $r['date_snap'] !== null ? (string) $r['date_snap'] : null,
    ];
}

file_put_contents($out, json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
fwrite(STDERR, 'pending import links: ' . count($list) . " unconfirmed -> {$out}\n");
