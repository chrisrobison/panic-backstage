<?php
declare(strict_types=1);

/**
 * Staff Handbook & Compliance — seed the unambiguous defaults:
 *   - certification types (RBS, Guard Card, Food Handler Card, and two
 *     general CA-employer training types; see docs/staff/knowledge-audit.md
 *     for which are settled fact vs. still need a validity-period/renewal
 *     answer from management)
 *   - document -> role assignments, but ONLY where the mapping is
 *     unambiguous given the existing staff_members.default_role enum
 *     (manager, security, bartender, barback, door, sound, lighting,
 *     stagehand, runner, cleaner, other). Documents with no matching role
 *     in that enum (booking, event coordinator, café, kitchen) are
 *     deliberately left unassigned — see docs/staff/knowledge-audit.md,
 *     "Management Decisions Needed" — an admin assigns those by hand via
 *     the Staff Doc Assignments UI once a role/person is decided.
 *
 * Idempotent: safe to re-run. Requires scripts/sync-staff-docs.php to have
 * run first (documents must already be registered by slug).
 *
 * Usage: php scripts/seed-staff-doc-defaults.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');
$db = new Database();

// --- Certification types -------------------------------------------------

$certTypes = [
    [
        'slug' => 'rbs',
        'name' => 'Responsible Beverage Service (RBS)',
        'description' => 'California ABC-mandated certification for anyone who serves, sells, or checks ID for alcohol at an on-sale licensed premises. Required statewide since July 2022. VERIFY: exact venue enforcement/renewal tracking process.',
        'expiration_required' => 1,
        'default_validity_months' => 36,
    ],
    [
        'slug' => 'guard-card',
        'name' => 'BSIS Security Guard Card',
        'description' => 'California Bureau of Security and Investigative Services registration required for security guard work. LEGAL/REGULATORY REVIEW REQUIRED: confirm which Mabuhay security roles are legally "guard" work requiring this vs. ordinary event staff.',
        'expiration_required' => 1,
        'default_validity_months' => 24,
    ],
    [
        'slug' => 'food-handler',
        'name' => 'Food Handler Card',
        'description' => 'California food handler certification for staff handling food. Applies once café/kitchen operations are active. VERIFY: San Francisco county-specific requirements.',
        'expiration_required' => 1,
        'default_validity_months' => 36,
    ],
    [
        'slug' => 'harassment-prevention',
        'name' => 'Sexual Harassment Prevention Training',
        'description' => 'California SB 1343 requires employers with 5+ employees to provide this training to all employees every 2 years. VERIFY: current vendor/course used, and whether supervisors get the extended (2-hour) version.',
        'expiration_required' => 1,
        'default_validity_months' => 24,
    ],
    [
        'slug' => 'workplace-safety',
        'name' => 'Workplace Safety Training',
        'description' => 'General workplace safety/IIPP orientation. TODO — Management decision required: confirm whether a formal IIPP-linked training program exists and what it covers.',
        'expiration_required' => 0,
        'default_validity_months' => null,
    ],
    [
        'slug' => 'emergency-evacuation',
        'name' => 'Emergency / Evacuation Training',
        'description' => 'Venue-specific emergency and evacuation procedures orientation. TODO — Management decision required: confirm cadence and who delivers it.',
        'expiration_required' => 0,
        'default_validity_months' => null,
    ],
];

foreach ($certTypes as $t) {
    $existing = $db->one('SELECT id FROM staff_certification_types WHERE slug = ?', [$t['slug']]);
    if ($existing) {
        echo "  [skip] certification type already exists: {$t['slug']}\n";
        continue;
    }
    $db->insert(
        'INSERT INTO staff_certification_types (slug, name, description, expiration_required, default_validity_months, active)
         VALUES (?, ?, ?, ?, ?, 1)',
        [$t['slug'], $t['name'], $t['description'], $t['expiration_required'], $t['default_validity_months']]
    );
    echo "  [created] certification type: {$t['slug']}\n";
}

// --- Document -> role assignments ----------------------------------------

$assignments = [
    'handbook' => ['all_staff'],
    'emergency' => ['all_staff'],
    'venue-safety' => ['all_staff'],
    'alcohol-service' => ['bartender', 'barback', 'door', 'manager'],
    'sop-opening' => ['manager'],
    'sop-closing' => ['manager'],
    'sop-house-manager' => ['manager'],
    'sop-bartender' => ['bartender'],
    'sop-barback' => ['barback'],
    'sop-door' => ['door'],
    'sop-security' => ['security'],
    'sop-sound-engineer' => ['sound'],
    'sop-stagehand' => ['stagehand'],
    'sop-cash-handling' => ['manager', 'bartender', 'door'],
    'sop-artist-settlement' => ['manager'],
    'sop-cleaning' => ['cleaner'],
    // Deliberately no entry for sop-booking, sop-event-coordinator, sop-cafe,
    // sop-kitchen — no matching staff_members.default_role value exists yet.
];

foreach ($assignments as $slug => $roleKeys) {
    $doc = $db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug]);
    if (!$doc) {
        echo "  [WARN] document not registered, skipping assignments: {$slug} (run scripts/sync-staff-docs.php first)\n";
        continue;
    }
    foreach ($roleKeys as $roleKey) {
        $existing = $db->one(
            'SELECT id FROM staff_document_assignments WHERE document_id = ? AND role_key = ?',
            [(int) $doc['id'], $roleKey]
        );
        if ($existing) {
            continue;
        }
        $db->insert(
            'INSERT INTO staff_document_assignments (document_id, role_key, required) VALUES (?, ?, 1)',
            [(int) $doc['id'], $roleKey]
        );
        echo "  [assigned] {$slug} -> {$roleKey}\n";
    }
}

echo "\nDone.\n";
