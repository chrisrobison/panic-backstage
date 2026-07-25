<?php
/**
 * Tests for Panic\Capabilities — the pure capability-checking logic
 * extracted out of BaseEndpoint (see src/Capabilities.php's docblock and
 * docs/AI-ASSISTANT-PLAN.md's "Capability-logic extraction" section).
 *
 * These pin down hasGlobal()/globalCapabilities()/capabilitiesForEventRole()
 * for a handful of representative roles against the exact values the
 * pre-refactor inline BaseEndpoint logic produced, so a future edit to
 * either copy can't silently drift from the other. eventAccess()/hasEvent()
 * need a live events/event_collaborators table, so they're covered by the
 * existing endpoint-level tests instead of duplicated here hermetically.
 *
 * Pure — no DB, no bootstrap beyond the autoloader. Picked up automatically
 * by tests/run-php-tests.sh.
 *
 * Run with: php tests/capabilities_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Capabilities;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Capabilities extraction ===\n\n";

// ── hasGlobal(): representative roles ───────────────────────────────────────
ok(Capabilities::hasGlobal('venue_admin', 'manage_users') === true, "venue_admin has manage_users");
ok(Capabilities::hasGlobal('venue_admin', 'view_all_events') === true, "venue_admin has view_all_events");
ok(Capabilities::hasGlobal('promoter', 'manage_users') === false, "promoter lacks manage_users");
ok(Capabilities::hasGlobal('staff', 'view_leads') === true, "staff has view_leads");
ok(Capabilities::hasGlobal('staff', 'manage_users') === false, "staff lacks manage_users");
ok(Capabilities::hasGlobal('global_viewer', 'view_all_events') === true, "global_viewer has view_all_events");
ok(Capabilities::hasGlobal('global_viewer', 'manage_users') === false, "global_viewer lacks manage_users");
ok(Capabilities::hasGlobal('viewer', 'view_all_events') === false, "viewer (no global caps) lacks view_all_events");
ok(Capabilities::hasGlobal('made_up_role', 'view_all_events') === false, "unknown role has no global capabilities");

// ── globalCapabilities(): full map matches hasGlobal() per key, for several roles ──
foreach (['venue_admin', 'event_owner', 'promoter', 'staff', 'global_viewer', 'viewer'] as $role) {
    $map = Capabilities::globalCapabilities($role);
    $consistent = true;
    foreach ($map as $capability => $granted) {
        if ($granted !== Capabilities::hasGlobal($role, $capability)) {
            $consistent = false;
            break;
        }
    }
    ok($consistent, "globalCapabilities('$role') map matches hasGlobal() for every key");
}
// Every role's map has the exact same set of keys (the union of all roles' capabilities).
$expectedKeys = array_keys(Capabilities::globalCapabilities('venue_admin'));
foreach (['promoter', 'viewer'] as $role) {
    ok(array_keys(Capabilities::globalCapabilities($role)) === $expectedKeys,
        "globalCapabilities('$role') has the same key set as venue_admin's map");
}

// ── capabilitiesForEventRole(): representative roles ────────────────────────
$admin = Capabilities::capabilitiesForEventRole('venue_admin');
ok($admin['edit_event'] === true && $admin['delete_event'] === true && $admin['manage_ledger'] === true,
    "venue_admin event role gets edit/delete/manage_ledger");

$band = Capabilities::capabilitiesForEventRole('band');
ok($band['read_event'] === true && $band['upload_assets'] === true && $band['edit_event'] === false,
    "band event role can read + upload but not edit");

$viewer = Capabilities::capabilitiesForEventRole('viewer');
ok($viewer['read_event'] === true && array_sum($viewer) === 1,
    "viewer event role has exactly read_event and nothing else");

$unknown = Capabilities::capabilitiesForEventRole('not_a_role');
ok(array_sum($unknown) === 0, "unknown event role grants nothing (all-false map, not an error)");

// publish_event implies view_public_page even when not separately listed.
$promoter = Capabilities::capabilitiesForEventRole('promoter');
ok($promoter['view_public_page'] === true,
    "promoter event role has view_public_page (explicitly listed)");
// event_owner has publish_event but not explicitly view_public_page in the
// source list — capabilitiesForEventRole() backfills it from publish_event.
$owner = Capabilities::capabilitiesForEventRole('event_owner');
ok($owner['publish_event'] === true && $owner['view_public_page'] === true,
    "event_owner: publish_event implies view_public_page (backfilled)");

// ── emptyEventCapabilities(): every key present, every value false ─────────
$empty = Capabilities::emptyEventCapabilities();
ok(array_sum($empty) === 0 && count($empty) === count(Capabilities::EVENT_CAPABILITY_KEYS),
    "emptyEventCapabilities() has every key, all false");

echo "\nCapabilities: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
