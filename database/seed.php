<?php
declare(strict_types=1);

/**
 * Panic Backstage — local dev seed script.
 *
 * DESTROYS existing data: TRUNCATEs users/events/venues/staff/bands/contracts/
 * etc. (see the table list below) and replaces them with generic demo data.
 * Safe ONLY against a throwaway database that has never held anything real.
 *
 * ⚠️ INCIDENT 2026-07-23: this script was run with .env pointed at the live
 * production `panic_backstage` database — this checkout IS the production
 * site (see README "no staging" gotcha) — and it silently wiped every real
 * user, passkey, event, and lead down to one generic demo admin account.
 * Recovered from a git-tracked mysqldump snapshot (see
 * /home/cdr/db-backups/panic-snapshots); no data was lost, but it could have
 * been. The guards below exist so this can never happen silently again:
 *
 *   1. Hard-blocks a short list of known-production database names outright.
 *      No flag bypasses this list — point .env at a genuinely disposable
 *      database if you need to run this script. Add any other production
 *      DB name here if you reuse this script for a different deployment.
 *   2. Independent of naming: refuses to run against ANY database that
 *      already has rows in `users` or `events` unless the operator
 *      explicitly confirms — by re-typing the database name at an
 *      interactive prompt, or setting CONFIRM_SEED_DB=<dbname> for
 *      scripted/non-interactive use.
 *
 * seed_demo_data() (database/seed_demo_data.php) itself refuses to insert
 * into a non-empty `users` table too, as a second, independent backstop —
 * see the top of that file.
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME') ?: 'panic_backstage';

// ── Guard 1: known-production names are never in scope for this script,
// full stop — no flag bypasses this. Point .env at a different, disposable
// database if you need to run a local seed.
$blockedNames = ['panic_backstage'];
if (in_array($dbName, $blockedNames, true)) {
    fwrite(STDERR, "Refusing to run: '{$dbName}' is a known production database (see \$blockedNames in " . basename(__FILE__) . ").\n");
    fwrite(STDERR, "This script TRUNCATEs users/events/venues/staff/bands/contracts/etc. It must never target production.\n");
    fwrite(STDERR, "Point DB_NAME in .env at a throwaway database if you need to seed local demo data.\n");
    exit(1);
}

try {
    $rootPdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // Create the target database if this is a true fresh install (matches
    // the "mysql -u <user> -p <dbname> < schema.sql" usage documented at the
    // top of schema.sql), then select it — the connection above has no
    // dbname, so without this every statement in schema.sql fails with
    // "No database selected".
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $rootPdo->exec("USE `$dbName`");
    $rootPdo->exec(file_get_contents($root . '/database/schema.sql'));
} catch (PDOException $error) {
    fwrite(STDERR, "Could not connect to MySQL with the configured credentials. Update .env and run again.\n");
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ── Guard 2: independent of naming — this script's TRUNCATE step is only
// ever safe against an empty database. If either table already has rows,
// something real is almost certainly in here. Abort unless the operator
// explicitly confirms.
$existingUsers  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$existingEvents = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

if ($existingUsers > 0 || $existingEvents > 0) {
    fwrite(STDERR, "\n!!!! DANGER: '{$dbName}' already has data ({$existingUsers} users, {$existingEvents} events). !!!!\n");
    fwrite(STDERR, "This script TRUNCATEs users, events, venues, staff, bands, contracts, and more,\n");
    fwrite(STDERR, "then replaces them with generic demo data. That data will be gone.\n\n");

    $confirmEnv = getenv('CONFIRM_SEED_DB') ?: '';
    if ($confirmEnv === $dbName) {
        fwrite(STDERR, "CONFIRM_SEED_DB matches '{$dbName}' — proceeding.\n");
    } elseif (PHP_SAPI === 'cli' && function_exists('stream_isatty') && stream_isatty(STDIN)) {
        fwrite(STDERR, "Type the database name (\"{$dbName}\") to confirm you want to destroy its data: ");
        $typed = trim((string) fgets(STDIN));
        if ($typed !== $dbName) {
            fwrite(STDERR, "Confirmation did not match. Aborting — nothing was touched.\n");
            exit(1);
        }
    } else {
        fwrite(STDERR, "Non-interactive and CONFIRM_SEED_DB is not set to '{$dbName}'. Aborting — nothing was touched.\n");
        fwrite(STDERR, "Re-run with CONFIRM_SEED_DB={$dbName} to proceed anyway.\n");
        exit(1);
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['contract_versions','contract_sections','contract_template_modules','contracts','contract_templates','contract_modules','event_activity_log','event_invites','event_settlements','event_schedule_items','event_staffing','event_assets','event_blockers','event_tasks','event_lineup','bands','event_collaborators','events','event_templates','staff_members','venues','users'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// Generic local-dev demo data. Real venues get their own name + admin email
// from the tenant creation request instead — see
// Panic\Tenant\TenantProvisioner::provision(), which calls the same
// seed_demo_data() with those values.
require $root . '/database/seed_demo_data.php';
$seeded = Panic\seed_demo_data($pdo, $root, [
    'venue_name'  => 'Demo Venue',
    'venue_slug'  => 'demo-venue',
    'admin_name'  => 'Admin',
    'admin_email' => 'admin@venue.local',
    'admin_password' => 'changeme',
]);

echo "Seed complete. Login: {$seeded['admin_email']} / {$seeded['admin_password']}\n";
if (!empty($seeded['primary_event_id'])) {
    // Machine-parseable line (also handy to eyeball): a real, richly-populated
    // event id for anything that needs one — e.g. `UI_EVENT_ID=... node
    // tests/ui/run.mjs`. See tests/ui/README.md.
    echo "UI_EVENT_ID={$seeded['primary_event_id']}\n";
}
