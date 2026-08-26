<?php
/**
 * Tests for Panic\Opportunities\FollowUp — Phase 8's pure, DB-free
 * follow-up-intelligence flag classifier (docs/OPPORTUNITIES-IMPLEMENTATION.md
 * §4.8). No DB, no bootstrap beyond the autoloader.
 *
 * Run with: php tests/opportunity_followup_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Opportunities\FollowUp;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Opportunities\\FollowUp ===\n\n";

$now = '2026-06-15 12:00:00';

// ── won/lost opportunities never get flagged ────────────────────────────────
foreach (['won', 'lost'] as $stage) {
    $flags = FollowUp::additionalFlags(
        ['stage' => $stage, 'next_action_at' => null, 'target_date' => '2026-06-16', 'conference_starts_at' => '2026-06-16'],
        ['now' => $now, 'last_activity_at' => '2026-01-01 00:00:00']
    );
    ok($flags === [], "a $stage opportunity gets no follow-up flags at all");
}

// ── no_activity ──────────────────────────────────────────────────────────────
$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified'],
    ['now' => $now, 'last_activity_at' => '2026-06-01 00:00:00'] // 14 days ago == threshold
);
ok(in_array('no_activity', $flags, true), 'no_activity fires at exactly NO_ACTIVITY_DAYS (14) days of silence');

$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified'],
    ['now' => $now, 'last_activity_at' => '2026-06-10 00:00:00'] // 5 days ago
);
ok(!in_array('no_activity', $flags, true), 'no_activity does not fire with recent activity');

// ── proposal_stalled ─────────────────────────────────────────────────────────
$flags = FollowUp::additionalFlags(
    ['stage' => 'proposal_sent', 'next_action_at' => null],
    ['now' => $now, 'last_activity_at' => '2026-06-03 00:00:00'] // 12 days ago, >= PROPOSAL_STALLED_DAYS(10)
);
ok(in_array('proposal_stalled', $flags, true), 'proposal_stalled fires when stage=proposal_sent, no follow-up, and stale activity');

$flags = FollowUp::additionalFlags(
    ['stage' => 'proposal_sent', 'next_action_at' => '2026-07-01 00:00:00'], // scheduled, in the future
    ['now' => $now, 'last_activity_at' => '2026-06-03 00:00:00']
);
ok(!in_array('proposal_stalled', $flags, true), 'proposal_stalled does not fire once a future follow-up is scheduled');

$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'next_action_at' => null],
    ['now' => $now, 'last_activity_at' => '2026-06-01 00:00:00']
);
ok(!in_array('proposal_stalled', $flags, true), 'proposal_stalled never fires outside the proposal_sent stage');

// ── conference_approaching ───────────────────────────────────────────────────
$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'conference_starts_at' => '2026-06-25'], // 10 days out
    ['now' => $now, 'last_activity_at' => $now]
);
ok(in_array('conference_approaching', $flags, true), 'conference_approaching fires within CONFERENCE_APPROACHING_DAYS (14)');

$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'conference_starts_at' => '2026-09-01'],
    ['now' => $now, 'last_activity_at' => $now]
);
ok(!in_array('conference_approaching', $flags, true), 'conference_approaching does not fire for a far-future conference');

$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'conference_starts_at' => '2026-05-01'], // already past
    ['now' => $now, 'last_activity_at' => $now]
);
ok(!in_array('conference_approaching', $flags, true), 'conference_approaching does not fire for a conference already in the past');

// ── target_date_approaching ──────────────────────────────────────────────────
$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'target_date' => '2026-06-20'], // 5 days out
    ['now' => $now, 'last_activity_at' => $now]
);
ok(in_array('target_date_approaching', $flags, true), 'target_date_approaching fires within TARGET_DATE_APPROACHING_DAYS (14)');

$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'target_date' => '2027-01-01'],
    ['now' => $now, 'last_activity_at' => $now]
);
ok(!in_array('target_date_approaching', $flags, true), 'target_date_approaching does not fire for a far-future target date');

// ── falls back to created_at/updated_at when no last_activity_at is given ───
$flags = FollowUp::additionalFlags(
    ['stage' => 'qualified', 'updated_at' => '2026-05-01 00:00:00'],
    ['now' => $now]
);
ok(in_array('no_activity', $flags, true), 'falls back to the opportunity row\'s own updated_at when no last_activity_at context is supplied');

// ── LABELS covers every code additionalFlags() can emit ─────────────────────
foreach (['no_activity', 'proposal_stalled', 'conference_approaching', 'target_date_approaching'] as $code) {
    ok(isset(FollowUp::LABELS[$code]), "FollowUp::LABELS has a human label for '$code'");
}

echo "\nFollowUp: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
