<?php
declare(strict_types=1);

/**
 * Staff Handbook & Compliance — maintenance: re-render every document's
 * current published version's HTML/TOC from its already-frozen Markdown,
 * using today's Panic\Markdown renderer. Use this after a renderer bugfix
 * (e.g. a change to how cross-document links or headings render) — it
 * never touches source_markdown, content_hash, or the version number, so
 * it can never invalidate an existing acknowledgment (which is tied to the
 * text a person agreed to, not to how that text happens to be rendered).
 *
 * This is NOT how you publish a content edit — that's
 * scripts/sync-staff-docs.php, which reads the Markdown file on disk and
 * requires a version bump if the text changed.
 *
 * Usage: php scripts/rerender-staff-docs.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\StaffDocs;

$root = dirname(__DIR__);
Env::load($root . '/.env');
$db = new Database();

$updated = StaffDocs::rerenderCurrentVersions($db);
foreach ($updated as $slug) {
    echo "  [rerendered] {$slug}\n";
}
echo "\nDone (" . count($updated) . " document(s)).\n";
