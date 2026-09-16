<?php
declare(strict_types=1);

/**
 * Staff Handbook & Compliance — seed a database with the shared docs/staff/**
 * template (documents + published versions) plus the certification types and
 * unambiguous document -> role assignments that go with it.
 *
 * This is the reusable core behind:
 *   - scripts/sync-staff-docs.php + scripts/seed-staff-doc-defaults.php,
 *     the CLI workflow for the single-tenant/on-prem install (unchanged
 *     behavior — those scripts just call into this file now instead of
 *     duplicating the cert-type/assignment list inline).
 *   - Panic\Tenant\TenantProvisioner::provision(), which calls
 *     seed_staff_docs() for every newly-provisioned SaaS tenant so a fresh
 *     venue starts with a generic, ready-to-customize handbook/SOP library
 *     instead of nothing. See src/StaffDocs.php's docblock for the file-
 *     vs. db content-source split this seeds into: everything here is
 *     source='file' (StaffDocs::syncFromDisk()'s default), leaving a tenant
 *     free to customize any of it afterward via the draft/publish-draft API
 *     without ever touching this shared template.
 *
 * Idempotent and additive-only: safe to call against a database that
 * already has some staff_documents rows (a re-provision, or a tenant that's
 * since customized things) — StaffDocs::syncFromDisk() skips any row whose
 * source has become 'db' (a tenant's own customization), and the cert-type/
 * assignment upserts below skip anything that already exists.
 */

namespace Panic;

/**
 * Sync + publish every docs/staff/** file, then seed the cert-type/
 * assignment defaults. This is the one call TenantProvisioner makes.
 */
function seed_staff_docs(\PDO $pdo, string $root): void
{
    $db = new Database($pdo);

    foreach (StaffDocs::syncFromDisk($db, $root) as $r) {
        // No-op here by design — scripts/sync-staff-docs.php prints this
        // list itself when run standalone; provisioning just needs it done.
    }

    $slugs = $db->all('SELECT slug FROM staff_documents ORDER BY slug');
    foreach ($slugs as $row) {
        StaffDocs::publishFromFile($db, $root, $row['slug'], null);
    }

    seed_staff_doc_defaults($db);
}

/**
 * Certification types (RBS, Guard Card, Food Handler Card, and two general
 * employer-training types; see docs/staff/knowledge-audit.md for which are
 * settled fact vs. still need a validity-period/renewal answer from a given
 * venue's management) and document -> role assignments, but ONLY where the
 * mapping is unambiguous given the existing staff_members.default_role enum
 * (manager, security, bartender, barback, door, sound, lighting, stagehand,
 * runner, cleaner, other). Documents with no matching role in that enum
 * (booking, event coordinator, cafe, kitchen) are deliberately left
 * unassigned — a venue admin assigns those by hand via the Staff Doc
 * Assignments UI once a role/person is decided.
 *
 * Split out from seed_staff_docs() so scripts/seed-staff-doc-defaults.php can
 * still run it standalone (documents must already be registered by slug —
 * i.e. sync-staff-docs.php has run first).
 *
 * @return list<string> human-readable log lines, for CLI callers to print
 */
function seed_staff_doc_defaults(Database $db): array
{
    $log = [];

    $certTypes = [
        [
            'slug' => 'rbs',
            'name' => 'Responsible Beverage Service (RBS)',
            'description' => 'California ABC-mandated certification for anyone who serves, sells, or checks ID for alcohol at an on-sale licensed premises. Required statewide since July 2022. If this venue is outside California, replace with the local equivalent. VERIFY: exact venue enforcement/renewal tracking process.',
            'expiration_required' => 1,
            'default_validity_months' => 36,
        ],
        [
            'slug' => 'guard-card',
            'name' => 'BSIS Security Guard Card',
            'description' => 'California Bureau of Security and Investigative Services registration required for security guard work. If this venue is outside California, replace with the local equivalent. LEGAL/REGULATORY REVIEW REQUIRED: confirm which of this venue\'s security roles are legally "guard" work requiring this vs. ordinary event staff.',
            'expiration_required' => 1,
            'default_validity_months' => 24,
        ],
        [
            'slug' => 'food-handler',
            'name' => 'Food Handler Card',
            'description' => 'Food handler certification for staff handling food, where required by local jurisdiction. Applies once cafe/kitchen operations are active. VERIFY: this venue\'s specific county/jurisdiction requirements.',
            'expiration_required' => 1,
            'default_validity_months' => 36,
        ],
        [
            'slug' => 'harassment-prevention',
            'name' => 'Sexual Harassment Prevention Training',
            'description' => 'California SB 1343 requires employers with 5+ employees to provide this training to all employees every 2 years; other jurisdictions have similar requirements. VERIFY: current vendor/course used, and whether supervisors get the extended (2-hour) version.',
            'expiration_required' => 1,
            'default_validity_months' => 24,
        ],
        [
            'slug' => 'workplace-safety',
            'name' => 'Workplace Safety Training',
            'description' => 'General workplace safety/injury-and-illness-prevention-plan orientation. TODO — Management decision required: confirm whether a formal IIPP-linked (or local equivalent) training program exists and what it covers.',
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
            $log[] = "  [skip] certification type already exists: {$t['slug']}";
            continue;
        }
        $db->insert(
            'INSERT INTO staff_certification_types (slug, name, description, expiration_required, default_validity_months, active)
             VALUES (?, ?, ?, ?, ?, 1)',
            [$t['slug'], $t['name'], $t['description'], $t['expiration_required'], $t['default_validity_months']]
        );
        $log[] = "  [created] certification type: {$t['slug']}";
    }

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
        // Deliberately no entry for sop-booking, sop-event-coordinator,
        // sop-cafe, sop-kitchen — no matching staff_members.default_role
        // value exists yet.
    ];

    foreach ($assignments as $slug => $roleKeys) {
        $doc = $db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc) {
            $log[] = "  [WARN] document not registered, skipping assignments: {$slug}";
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
            $log[] = "  [assigned] {$slug} -> {$roleKey}";
        }
    }

    return $log;
}
