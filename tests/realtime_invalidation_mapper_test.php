<?php
/**
 * Tests for Panic\RealtimeInvalidationMapper — the db_history row -> safe
 * realtime invalidation translator behind src/Realtime.php (see
 * docs/realtime-data.md).
 *
 * Pure — no DB, no bootstrap beyond the autoloader. Picked up automatically
 * by tests/run-php-tests.sh.
 *
 * Run with: php tests/realtime_invalidation_mapper_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\RealtimeInvalidationMapper;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

function row(string $table, ?string $pkValue = null, ?array $oldRow = null, ?array $newRow = null): array {
    return [
        'table_name' => $table,
        'pk_value'   => $pkValue,
        'old_row'    => $oldRow !== null ? json_encode($oldRow) : null,
        'new_row'    => $newRow !== null ? json_encode($newRow) : null,
    ];
}

echo "\n=== RealtimeInvalidationMapper ===\n\n";

// ── Direct tables: entity id comes straight from pk_value ──────────────────
$r = RealtimeInvalidationMapper::map(row('events', '123', null, ['id' => 123, 'title' => 'x']));
ok($r === ['entity' => 'event', 'id' => 123], "events row maps to event:123");

$r = RealtimeInvalidationMapper::map(row('leads', '412', null, ['id' => 412]));
ok($r === ['entity' => 'lead', 'id' => 412], "leads row maps to lead:412");

// ── Child tables: entity id comes from a foreign key in new_row/old_row ────
$r = RealtimeInvalidationMapper::map(row('event_tasks', '9', null, ['id' => 9, 'event_id' => 555, 'title' => 'Load in']));
ok($r === ['entity' => 'event', 'id' => 555], "event_tasks INSERT maps to the parent event (event_id from new_row)");

$r = RealtimeInvalidationMapper::map(row('event_blockers', '9', ['id' => 9, 'event_id' => 555], null));
ok($r === ['entity' => 'event', 'id' => 555], "event_blockers DELETE maps to the parent event (event_id from old_row, new_row null)");

$r = RealtimeInvalidationMapper::map(row('lead_messages', '77', ['id' => 77, 'lead_id' => 412, 'direction' => 'inbound'], ['id' => 77, 'lead_id' => 412, 'is_read' => 1]));
ok($r === ['entity' => 'lead', 'id' => 412], "lead_messages UPDATE maps to the parent lead");

$r = RealtimeInvalidationMapper::map(row('contracts', '3', null, ['id' => 3, 'event_id' => 700]));
ok($r === ['entity' => 'event', 'id' => 700], "contracts row (nullable event_id) maps to the parent event when set");

$r = RealtimeInvalidationMapper::map(row('contracts', '3', null, ['id' => 3, 'event_id' => null]));
ok($r === ['entity' => 'global'], "contracts row with null event_id falls back to global (never crashes)");

// ── Unmapped, non-ignored table: safe 'global' fallback, not silence ───────
$r = RealtimeInvalidationMapper::map(row('some_future_table', '1', null, ['id' => 1]));
ok($r === ['entity' => 'global'], "unmapped table falls back to a content-free 'global' invalidation");

// ── SECURITY: never returns old_row/new_row values, even embedded ──────────
foreach ([
    RealtimeInvalidationMapper::map(row('events', '123', null, ['id' => 123, 'title' => 'Secret Title'])),
    RealtimeInvalidationMapper::map(row('event_tasks', '9', null, ['id' => 9, 'event_id' => 555, 'title' => 'Secret Task'])),
    RealtimeInvalidationMapper::map(row('lead_messages', '77', ['id' => 77, 'lead_id' => 412, 'body_text' => 'Secret body'], null)),
] as $i => $result) {
    $encoded = json_encode($result);
    ok(
        !str_contains($encoded, 'Secret') && array_keys($result) !== ['old_row'] && array_keys($result) !== ['new_row'],
        "map() result #$i never contains row field values, only entity/id"
    );
    ok(array_diff(array_keys($result), ['entity', 'id']) === [], "map() result #$i has only entity/id keys");
}

// ── IGNORE list: high-frequency auth/rate-limit plumbing emits nothing ─────
foreach (['rate_limits', 'refresh_tokens', 'magic_link_tokens', 'email_verification_tokens', 'webauthn_challenges', 'portal_tokens'] as $table) {
    $r = RealtimeInvalidationMapper::map(row($table, '1', null, ['id' => 1]));
    ok($r === null, "$table is on the IGNORE list (map() returns null, not even 'global')");
}

// ── Non-numeric / missing pk_value degrades to 'global' rather than erroring ──
$r = RealtimeInvalidationMapper::map(row('events', null, null, ['title' => 'x']));
ok($r === ['entity' => 'global'], "events row with no pk_value falls back to global instead of throwing");

// ── Phase 8: Opportunities conferences/companies/contacts/notes/research jobs ──
$r = RealtimeInvalidationMapper::map(row('opportunity_conferences', '42', null, ['id' => 42]));
ok($r === ['entity' => 'opportunity_conference', 'id' => 42], "opportunity_conferences row maps to opportunity_conference:42");

$r = RealtimeInvalidationMapper::map(row('opportunity_companies', '7', null, ['id' => 7]));
ok($r === ['entity' => 'opportunity_company', 'id' => 7], "opportunity_companies row maps to opportunity_company:7");

$r = RealtimeInvalidationMapper::map(row('opportunity_contacts', '3', null, ['id' => 3]));
ok($r === ['entity' => 'opportunity_contact', 'id' => 3], "opportunity_contacts row maps to opportunity_contact:3");

$r = RealtimeInvalidationMapper::map(row('opportunity_notes', '9', null, ['id' => 9]));
ok($r === ['entity' => 'opportunity_note', 'id' => 9], "opportunity_notes row maps to opportunity_note:9");

$r = RealtimeInvalidationMapper::map(row('opportunity_research_jobs', '5', null, ['id' => 5]));
ok($r === ['entity' => 'opportunity_research_job', 'id' => 5], "opportunity_research_jobs row maps to opportunity_research_job:5");

$r = RealtimeInvalidationMapper::map(row('opportunity_conference_facts', '1', null, ['id' => 1, 'conference_id' => 42]));
ok($r === ['entity' => 'opportunity_conference', 'id' => 42], "opportunity_conference_facts maps to its parent conference");

$r = RealtimeInvalidationMapper::map(row('opportunity_conference_companies', '1', null, ['id' => 1, 'conference_id' => 42, 'company_id' => 7]));
ok($r === ['entity' => 'opportunity_conference', 'id' => 42], "opportunity_conference_companies maps to its parent conference (not the company)");

$r = RealtimeInvalidationMapper::map(row('opportunity_note_links', '1', null, ['id' => 1, 'note_id' => 9, 'linked_type' => 'company', 'linked_id' => 7]));
ok($r === ['entity' => 'opportunity_note', 'id' => 9], "opportunity_note_links maps to its parent note");

$r = RealtimeInvalidationMapper::map(row('opportunity_note_versions', '1', ['id' => 1, 'note_id' => 9], null));
ok($r === ['entity' => 'opportunity_note', 'id' => 9], "opportunity_note_versions DELETE maps to its parent note (note_id from old_row)");

echo "\nRealtimeInvalidationMapper: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
