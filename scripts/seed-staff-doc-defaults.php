<?php
declare(strict_types=1);

/**
 * Staff Handbook & Compliance — CLI wrapper around
 * Panic\seed_staff_doc_defaults() (database/seed_staff_docs.php): seeds
 * certification types and the unambiguous document -> role assignments.
 * See that file for the full explanation; this script just runs it against
 * the single-tenant/on-prem DB_* database and prints the results.
 *
 * Idempotent: safe to re-run. Requires scripts/sync-staff-docs.php to have
 * run first (documents must already be registered by slug).
 *
 * Usage: php scripts/seed-staff-doc-defaults.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../database/seed_staff_docs.php';

use Panic\Database;
use Panic\Env;
use function Panic\seed_staff_doc_defaults;

$root = dirname(__DIR__);
Env::load($root . '/.env');
$db = new Database();

foreach (seed_staff_doc_defaults($db) as $line) {
    echo $line, "\n";
}

echo "\nDone.\n";
