<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME') ?: 'panic_backstage';

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
