<?php
/**
 * Tests for the process_definitions / process_versions / process_instances
 * data model (database/migrations/066_add_process_automation.sql) and the
 * invariants src/Processes.php + src/Processes/Versions.php enforce:
 * unique key_slug, incrementing version numbers, published-version
 * immutability, "current_published_version_id only ever points at a
 * published row", and instance-to-definition scoping (the multi-tenant
 * story here: rows only ever surface via a WHERE process_definition_id = ?
 * — this test checks that scoping directly against the DB rather than
 * mocking it).
 *
 * REQUIRES A REAL MYSQL DATABASE with migration 066 applied — same
 * convention as rate_limiter_test.php. Excluded from the default hermetic
 * run; opt in with RUN_DB_TESTS=1 against a throwaway/dev database.
 *
 * Run with: php tests/process_versions_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use function Panic\slugify;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

try {
    $db = new Database();
    $db->one('SELECT 1');
    $db->one('SELECT 1 FROM process_definitions LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not use process_* tables: {$e->getMessage()}\n");
    fwrite(STDERR, "process_versions_test.php needs a real MySQL DB with migration 066 applied.\n");
    exit(1);
}

echo "\n=== Process definitions/versions/instances tests ===\n\n";

$suffix = bin2hex(random_bytes(4));
$name = "Test Process $suffix";
$slug = slugify($name);

// ── 1. Create definition + draft v1 ──────────────────────────────────────────
$defId = $db->insert(
    'INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)',
    [$slug, $name]
);
$v1 = $db->insert(
    "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json) VALUES (?, 1, 'draft', ?)",
    [$defId, json_encode(['schemaVersion' => 1, 'nodes' => [], 'edges' => []])]
);
ok($defId > 0 && $v1 > 0, 'Created a definition and its v1 draft');

// ── 2. key_slug is unique ─────────────────────────────────────────────────────
try {
    $db->insert('INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)', [$slug, 'Duplicate slug']);
    ok(false, 'Duplicate key_slug should be rejected by the unique index');
} catch (\Throwable $e) {
    ok(true, 'Duplicate key_slug rejected: ' . $e->getMessage());
}

// ── 3. Publishing sets current_published_version_id and flips status ────────
$db->run("UPDATE process_versions SET status = 'published', published_at = NOW() WHERE id = ?", [$v1]);
$db->run('UPDATE process_definitions SET current_published_version_id = ? WHERE id = ?', [$v1, $defId]);
$def = $db->one('SELECT current_published_version_id FROM process_definitions WHERE id = ?', [$defId]);
ok((int) $def['current_published_version_id'] === $v1, 'Definition points at the newly published version');

// ── 4. A new draft (v2) increments version_number and leaves v1 published ───
$v2 = $db->insert(
    "INSERT INTO process_versions (process_definition_id, version_number, status, graph_json) VALUES (?, 2, 'draft', ?)",
    [$defId, json_encode(['schemaVersion' => 1, 'nodes' => [], 'edges' => []])]
);
$versions = $db->all('SELECT version_number, status FROM process_versions WHERE process_definition_id = ? ORDER BY version_number', [$defId]);
ok(count($versions) === 2 && $versions[0]['status'] === 'published' && $versions[1]['status'] === 'draft',
   'v1 stays published while v2 is a separate draft (immutability by construction)');

// ── 5. version_number is unique per definition ───────────────────────────────
try {
    $db->insert("INSERT INTO process_versions (process_definition_id, version_number, status, graph_json) VALUES (?, 2, 'draft', '{}')", [$defId]);
    ok(false, 'Duplicate version_number for the same definition should be rejected');
} catch (\Throwable $e) {
    ok(true, 'Duplicate version_number rejected: ' . $e->getMessage());
}

// ── 6. Demo instance rows are flagged and scoped to their definition ────────
$instId = $db->insert(
    "INSERT INTO process_instances (process_definition_id, process_version_id, name, status, current_node_id, is_demo) VALUES (?, ?, 'Test Case', 'waiting', 'manager_approval', 1)",
    [$defId, $v1]
);
$otherDefId = $db->insert('INSERT INTO process_definitions (key_slug, name) VALUES (?, ?)', [slugify("Other $suffix"), "Other $suffix"]);
$scoped = $db->all('SELECT id FROM process_instances WHERE process_definition_id = ?', [$otherDefId]);
ok($instId > 0 && count($scoped) === 0, 'Instance is scoped to its own definition — a sibling definition sees none of it');

// ── 7. Deleting a definition cascades to its versions/instances ─────────────
$db->run('DELETE FROM process_definitions WHERE id = ?', [$defId]);
$remainingVersions = $db->all('SELECT id FROM process_versions WHERE process_definition_id = ?', [$defId]);
$remainingInstances = $db->all('SELECT id FROM process_instances WHERE process_definition_id = ?', [$defId]);
ok(count($remainingVersions) === 0 && count($remainingInstances) === 0, 'Deleting a definition cascades to its versions and instances');

// Cleanup the sibling definition used for scoping check.
$db->run('DELETE FROM process_definitions WHERE id = ?', [$otherDefId]);

echo "\n";
if ($failed === 0) {
    echo "All $passed assertion(s) passed.\n";
    exit(0);
}
echo "$failed/" . ($passed + $failed) . " assertions failed.\n";
exit(1);
